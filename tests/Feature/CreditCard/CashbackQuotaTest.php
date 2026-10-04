<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\CategoryComboItem;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CategoryRuleService;
use App\Services\CreditCard\CreditCardOverviewService;
use App\Services\CreditCard\CreditCardTransactionService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Quota hoàn tiền theo BẬC MONG MUỐN (`UserCard.desired_spend`).
 *
 * ---------------------------------------------------------------------------
 * HỢP ĐỒNG ĐANG KIỂM TRA
 * ---------------------------------------------------------------------------
 *  1. Bậc đích tra bằng `desired_spend`, TUYỆT ĐỐI không bằng chi tiêu thực tế.
 *  2. Chỉ rule `is_quota_category = true` mới vào quota; fallback không bao giờ.
 *  3. HAI TẦNG CON SỐ, KHÔNG ĐƯỢC TRỘN:
 *       - `tier_cashback_*`     = trần CHUNG `PolicyTier.max_cashback_per_period`,
 *                                 "đã dùng" chỉ cộng cashback của rule quota.
 *       - `cashback_*` (rule)   = trần RIÊNG
 *                                 `max_cashback_per_category_per_period`.
 *       - `cashback_available_for_rule` = MIN của hai tầng trên, và KHÔNG phân
 *                                 bổ ngân sách chung tuần tự cho các danh mục.
 *  4. "Đã dùng" đọc `cashback_amount_snapshot` — tiền engine ĐÃ ghi. Service
 *     này không recalculate, không sửa snapshot, không gọi `CashbackCalculator`.
 *  5. `spend_remaining_estimate` WALK từng dải `spend_from`/`spend_to`, dùng tỷ
 *     lệ của từng dải — không chia một lần cho tỷ lệ hiện tại.
 *  6. Combo là rule riêng: max lấy từ rule combo của bậc đích, used cộng theo
 *     membership HIỆN TẠI.
 *
 * Bậc thật mà engine dùng để tính cashback là bậc theo CHI TIÊU THỰC TẾ, nên
 * snapshot của giao dịch có thể trỏ vào rule của bậc KHÁC với bậc quota. Vì vậy
 * "đã dùng" ở đây gán theo CATEGORY của rule quota trong bậc đích, không đọc cờ
 * của rule trong snapshot.
 */
class CashbackQuotaTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    protected function setUp(): void
    {
        // `setUpCreditCardTestCase()` tự gọi `parent::setUp()` — gọi thêm ở đây sẽ
        // mở hai transaction và `RefreshDatabase` báo "already an active
        // transaction".
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();
    }

    // =====================================================================
    // 1. Bậc đích lấy từ desired_spend
    // =====================================================================

    #[Test]
    public function desired_spend_picks_the_target_tier(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->cardWithTiersAndRules('6000000', ...$this->twoTierFixture($category));

        $quota = $this->quotaOf($card);

        // Mục tiêu 6 triệu nằm trong bậc 2 (từ 5 triệu).
        $this->assertSame('Bậc 2', $quota['tier_name']);
        $this->assertNotNull($quota['tier_id']);
    }

    #[Test]
    public function the_target_tier_supplies_the_global_cashback_max(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->cardWithTiersAndRules('6000000', ...$this->twoTierFixture($category));

        $quota = $this->quotaOf($card);

        // Trần CHUNG lấy từ `PolicyTier.max_cashback_per_period` CỦA BẬC ĐÍCH
        // (1.000.000), không phải của bậc thấp (100.000).
        $this->assertSame('1000000.00', $quota['tier_cashback_max']);
        $this->assertTrue($quota['has_tier_cashback_max']);
    }

    #[Test]
    public function actual_spend_below_the_target_tier_still_projects_the_target_tier(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->cardWithTiersAndRules('6000000', ...$this->twoTierFixture($category));

        // 2 triệu < ngưỡng bậc 2 ⇒ engine CHẠY Ở BẬC 1, nhưng quota vẫn phải theo
        // bậc 2 vì đó là bậc mục tiêu.
        $this->spend($card, $category, '2000000');

        $quota = $this->quotaOf($card);
        $rule = $this->ruleFor($quota, 'category_id', (int) $category->id);

        $this->assertSame('Bậc 2', $quota['tier_name']);
        $this->assertSame('1000000.00', $quota['tier_cashback_max']);
        // Trần riêng cũng phải lấy của BẬC ĐÍCH, không lấy "cap của actual tier".
        $this->assertSame('300000.00', $rule['cashback_max']);
    }

    #[Test]
    public function the_engine_can_snapshot_another_tier_than_the_quota_tier(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->cardWithTiersAndRules('6000000', ...$this->twoTierFixture($category));

        $transaction = $this->spend($card, $category, '2000000');

        $quota = $this->quotaOf($card);

        // Engine chạy bậc 1 (chi tiêu thực 2 triệu)…
        $this->assertSame($this->tierIdOf($card, 0), (int) $transaction->policy_tier_id);
        // …còn quota vẫn đọc bậc 2.
        $this->assertSame('Bậc 2', $quota['tier_name']);
        $this->assertNotSame(
            (int) $transaction->policy_tier_id,
            (int) $quota['tier_id'],
            'Quota phải theo bậc mục tiêu, không theo bậc engine đang dùng.',
        );
    }

    // =====================================================================
    // 2. Chỉ rule is_quota_category mới vào quota
    // =====================================================================

    #[Test]
    public function tier_cashback_used_counts_only_quota_rules(): void
    {
        $shopee = $this->makeSystemCategory();
        $card = $this->cardWithTiersAndRules('1000000', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '1000000.00',
        ]], [
            $this->categoryRule($shopee, percent: '5.000', capCategory: '300000.00', quota: true),
        ]);

        $transaction = $this->spend($card, $shopee, '2000000');

        $quota = $this->quotaOf($card);

        $this->assertSame('100000.00', (string) $transaction->cashback_amount_snapshot);
        $this->assertSame('100000.00', $quota['tier_cashback_used']);
    }

    #[Test]
    public function a_transaction_of_a_rule_without_the_quota_flag_does_not_raise_tier_used(): void
    {
        $quotaCategory = $this->makeSystemCategory();
        $plainCategory = $this->makeSystemCategory();
        $card = $this->cardWithTiersAndRules('1000000', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '1000000.00',
        ]], [
            $this->categoryRule($quotaCategory, percent: '5.000', capCategory: '300000.00', quota: true),
            $this->categoryRule($plainCategory, percent: '5.000', quota: false),
        ]);

        // Cashback thật vẫn có, nhưng danh mục này KHÔNG tick quota.
        $transaction = $this->spend($card, $plainCategory, '2000000');

        $quota = $this->quotaOf($card);

        $this->assertSame('100000.00', (string) $transaction->cashback_amount_snapshot);
        $this->assertSame(
            '0.00',
            $quota['tier_cashback_used'],
            'Giao dịch của rule không tick quota không được tính vào hạn mức chung.',
        );
        $this->assertSame('1000000.00', $quota['tier_cashback_remaining']);
        // Rule không tick KHÔNG xuất hiện trong kết quả quota.
        $this->assertCount(1, $quota['rules']);
    }

    #[Test]
    public function a_fallback_transaction_does_not_raise_tier_used(): void
    {
        $shopee = $this->makeSystemCategory();
        $card = $this->cardWithTiersAndRules('6000000', [
            ['name' => 'Bậc 1', 'min' => 0, 'max' => 5000000, 'cap_period' => '100000.00'],
            ['name' => 'Bậc 2', 'min' => 5000000, 'max' => null, 'cap_period' => '1000000.00'],
        ], [
            // Bậc 1 KHÔNG có rule cho Shopee ⇒ engine rơi vào fallback.
            ['only_tier' => 0, 'scope_type' => PolicyTierCategory::SCOPE_OTHER, 'cashback_percent' => '2.000'],
            $this->categoryRule($shopee, onlyTier: 1, percent: '5.000', capCategory: '300000.00', quota: true),
        ]);

        $transaction = $this->spend($card, $shopee, '2000000');

        $quota = $this->quotaOf($card);

        // Fallback CÓ sinh hoàn tiền thật…
        $this->assertSame('40000.00', (string) $transaction->cashback_amount_snapshot);
        $this->assertNotNull($transaction->policy_tier_category_id);
        // …nhưng không được tính vào hạn mức chung.
        $this->assertSame('0.00', $quota['tier_cashback_used']);
        $this->assertSame('1000000.00', $quota['tier_cashback_remaining']);
        // Rule quota của bậc đích vẫn xuất hiện, dù chưa dùng đồng nào.
        $this->assertSame('0.00', $this->ruleFor($quota, 'category_id', (int) $shopee->id)['cashback_used']);
    }

    // =====================================================================
    // 3. Tầng chung
    // =====================================================================

    #[Test]
    public function tier_cashback_remaining_is_the_global_max_minus_the_quota_used(): void
    {
        $shopee = $this->makeSystemCategory();
        $food = $this->makeSystemCategory();
        $utilities = $this->makeSystemCategory();
        $card = $this->cardWithTiersAndRules('1000000', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '1000000.00',
        ]], [
            $this->categoryRule($shopee, percent: '5.000', capCategory: '300000.00', quota: true),
            $this->categoryRule($food, percent: '5.000', capCategory: '500000.00', quota: true),
            $this->categoryRule($utilities, percent: '5.000', quota: false),
            ['scope_type' => PolicyTierCategory::SCOPE_OTHER, 'cashback_percent' => '1.000'],
        ]);

        // Quota: 100.000 + 200.000. Không quota: 150.000 + fallback 50.000.
        $this->spend($card, $shopee, '2000000');
        $this->spend($card, $food, '4000000');
        $this->spend($card, $utilities, '3000000');
        $orphan = $this->makeSystemCategory();
        $this->spend($card, $orphan, '5000000');

        $quota = $this->quotaOf($card);

        $this->assertSame('300000.00', $quota['tier_cashback_used']);
        $this->assertSame('700000.00', $quota['tier_cashback_remaining']);
        $this->assertFalse($quota['is_exhausted']);
    }

    #[Test]
    public function a_tier_without_a_period_cap_reports_no_tier_max(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->cardWithTiersAndRules('1000000', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => null,
        ]], [
            $this->categoryRule($category, percent: '5.000', capCategory: '300000.00', quota: true),
        ]);

        $quota = $this->quotaOf($card);

        $this->assertNull($quota['tier_cashback_max']);
        $this->assertFalse($quota['has_tier_cashback_max']);
        // Chưa có trần KHÔNG phải là hết quota.
        $this->assertFalse($quota['is_exhausted']);
    }

    // =====================================================================
    // 4. Tầng riêng của từng rule
    // =====================================================================

    #[Test]
    public function a_category_rule_reports_its_own_max_used_and_remaining(): void
    {
        $shopee = $this->makeSystemCategory();
        $card = $this->cardWithTiersAndRules('1000000', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '1000000.00',
        ]], [
            $this->categoryRule($shopee, percent: '5.000', capCategory: '300000.00', quota: true),
        ]);

        $transaction = $this->spend($card, $shopee, '2000000');

        $rule = $this->ruleFor($this->quotaOf($card), 'category_id', (int) $shopee->id);

        $this->assertSame('category', $rule['target_scope']);
        $this->assertSame('300000.00', $rule['cashback_max']);
        // "Đã dùng" = đúng tiền engine đã ghi, không tự tính lại.
        $this->assertSame((string) $transaction->cashback_amount_snapshot, $rule['cashback_used']);
        $this->assertSame('200000.00', $rule['cashback_remaining']);
    }

    #[Test]
    public function several_quota_rules_of_the_same_tier_stay_independent(): void
    {
        $shopee = $this->makeSystemCategory();
        $food = $this->makeSystemCategory();
        $card = $this->cardWithTiersAndRules('1000000', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '400000.00',
        ]], [
            $this->categoryRule($shopee, percent: '5.000', capCategory: '300000.00', quota: true),
            $this->categoryRule($food, percent: '5.000', capCategory: '500000.00', quota: true),
        ]);

        $this->spend($card, $shopee, '2000000');   // 100.000
        $this->spend($card, $food, '4000000');     // 200.000

        $quota = $this->quotaOf($card);
        $shopeeRule = $this->ruleFor($quota, 'category_id', (int) $shopee->id);
        $foodRule = $this->ruleFor($quota, 'category_id', (int) $food->id);

        $this->assertCount(2, $quota['rules']);
        $this->assertSame('300000.00', $shopeeRule['cashback_max']);
        $this->assertSame('100000.00', $shopeeRule['cashback_used']);
        $this->assertSame('200000.00', $shopeeRule['cashback_remaining']);

        $this->assertSame('500000.00', $foodRule['cashback_max']);
        $this->assertSame('200000.00', $foodRule['cashback_used']);
        $this->assertSame('300000.00', $foodRule['cashback_remaining']);

        $this->assertSame('300000.00', $quota['tier_cashback_used']);
        $this->assertSame('100000.00', $quota['tier_cashback_remaining']);

        // Ngân sách chung KHÔNG phân bổ tuần tự: mỗi danh mục thấy trọn phần còn
        // lại của hạn mức chung, nên cả hai đều 100.000 (một bộ chia dồn sẽ cho
        // danh mục thứ nhất 100.000 và danh mục sau 0).
        $this->assertSame('100000.00', $shopeeRule['cashback_available_for_rule']);
        $this->assertSame('100000.00', $foodRule['cashback_available_for_rule']);
    }

    // =====================================================================
    // 5. MIN của hai tầng
    // =====================================================================

    #[Test]
    public function cashback_available_is_the_smaller_of_the_rule_remaining_and_the_tier_remaining(): void
    {
        $shopee = $this->makeSystemCategory();
        // Trần chung 150.000 < trần riêng 300.000 ⇒ tầng chung mới là nút thắt.
        $card = $this->cardWithTiersAndRules('1000000', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '150000.00',
        ]], [
            $this->categoryRule($shopee, percent: '5.000', capCategory: '300000.00', quota: true),
        ]);

        $this->spend($card, $shopee, '2000000'); // 100.000

        $quota = $this->quotaOf($card);
        $rule = $this->ruleFor($quota, 'category_id', (int) $shopee->id);

        $this->assertSame('200000.00', $rule['cashback_remaining']);   // MIN bị trần chung ép
        $this->assertSame('50000.00', $rule['tier_cashback_remaining']);
        $this->assertSame('50000.00', $rule['cashback_available_for_rule']);
    }

    #[Test]
    public function cashback_available_follows_the_rule_remaining_when_the_tier_cap_is_wide(): void
    {
        $shopee = $this->makeSystemCategory();
        $card = $this->cardWithTiersAndRules('1000000', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '1000000.00',
        ]], [
            $this->categoryRule($shopee, percent: '5.000', capCategory: '300000.00', quota: true),
        ]);

        $this->spend($card, $shopee, '2000000');

        $rule = $this->ruleFor($this->quotaOf($card), 'category_id', (int) $shopee->id);

        $this->assertSame('200000.00', $rule['cashback_remaining']);
        $this->assertSame('900000.00', $rule['tier_cashback_remaining']);
        $this->assertSame('200000.00', $rule['cashback_available_for_rule']);
    }

    #[Test]
    public function an_exhausted_tier_cap_leaves_nothing_available_anywhere(): void
    {
        $shopee = $this->makeSystemCategory();
        $card = $this->cardWithTiersAndRules('1000000', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '1000000.00',
        ]], [
            // Trần riêng CỐ Ý rộng (2.000.000) để chứng minh nút thắt là tầng chung.
            $this->categoryRule($shopee, percent: '5.000', capCategory: '2000000.00', quota: true),
        ]);

        // 20.000.000 × 5% = 1.000.000 ⇒ engine kẹp đúng trần chung.
        $this->spend($card, $shopee, '20000000');

        $quota = $this->quotaOf($card);
        $rule = $this->ruleFor($quota, 'category_id', (int) $shopee->id);

        $this->assertSame('1000000.00', $quota['tier_cashback_used']);
        $this->assertSame('0.00', $quota['tier_cashback_remaining']);
        $this->assertTrue($quota['is_exhausted']);

        // Trần riêng vẫn còn dư — nên "hết" là do tầng chung, không phải tầng riêng.
        $this->assertSame('1000000.00', $rule['cashback_remaining']);
        $this->assertSame('0.00', $rule['cashback_available_for_rule']);
        $this->assertSame('0.00', $rule['spend_remaining_estimate']);
    }

    #[Test]
    public function an_exhausted_rule_cap_leaves_nothing_available_for_that_rule(): void
    {
        $shopee = $this->makeSystemCategory();
        $card = $this->cardWithTiersAndRules('1000000', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '1000000.00',
        ]], [
            $this->categoryRule($shopee, percent: '5.000', capCategory: '100000.00', quota: true),
        ]);

        // 4.000.000 × 5% = 200.000 nhưng engine kẹp theo trần riêng còn 100.000.
        $this->spend($card, $shopee, '4000000');

        $quota = $this->quotaOf($card);
        $rule = $this->ruleFor($quota, 'category_id', (int) $shopee->id);

        $this->assertSame('100000.00', $rule['cashback_used']);
        $this->assertSame('0.00', $rule['cashback_remaining']);
        $this->assertTrue($rule['is_exhausted']);
        $this->assertSame('0.00', $rule['cashback_available_for_rule']);
        $this->assertSame('0.00', $rule['spend_remaining_estimate']);

        // Tầng chung vẫn còn dư ⇒ nút thắt nằm ở tầng riêng.
        $this->assertSame('900000.00', $quota['tier_cashback_remaining']);
    }

    // =====================================================================
    // 6. Combo là rule riêng
    // =====================================================================

    #[Test]
    public function a_combo_rule_reports_the_combo_max_and_identity(): void
    {
        $first = $this->makeSystemCategory();
        $second = $this->makeSystemCategory();
        $combo = $this->makeCombo([$first, $second]);

        $card = $this->cardWithTiersAndRules('1000000', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '1000000.00',
        ]], [
            $this->comboRule($combo, percent: '5.000', capCategory: '400000.00', quota: true),
        ]);

        $rule = $this->ruleFor($this->quotaOf($card), 'combo_id', (int) $combo->id);

        $this->assertSame('combo', $rule['target_scope']);
        $this->assertSame((int) $combo->id, $rule['combo_id']);
        $this->assertSame($combo->name, $rule['combo_name']);
        $this->assertNull($rule['category_id']);
        $this->assertSame('400000.00', $rule['cashback_max']);
        $this->assertSame([(int) $first->id, (int) $second->id], $rule['scope_category_ids']);
    }

    #[Test]
    public function a_combo_rule_sums_the_cashback_of_its_current_members_only(): void
    {
        $first = $this->makeSystemCategory();
        $second = $this->makeSystemCategory();
        $outside = $this->makeSystemCategory();
        $combo = $this->makeCombo([$first, $second]);

        $card = $this->cardWithTiersAndRules('1000000', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '1000000.00',
        ]], [
            $this->comboRule($combo, percent: '5.000', capCategory: '400000.00', quota: true),
            // Danh mục NGOÀI combo có rule riêng, không tick quota.
            $this->categoryRule($outside, percent: '5.000', quota: false),
        ]);

        $this->spend($card, $first, '2000000');    // 100.000
        $this->spend($card, $second, '3000000');   // 150.000
        $this->spend($card, $outside, '1000000');  //  50.000 — ngoài combo

        $quota = $this->quotaOf($card);
        $rule = $this->ruleFor($quota, 'combo_id', (int) $combo->id);

        $this->assertSame('250000.00', $rule['cashback_used']);
        $this->assertSame('150000.00', $rule['cashback_remaining']);
        $this->assertSame('250000.00', $quota['tier_cashback_used']);
        // Giao dịch ngoài combo không lọt vào combo.
        $this->assertSame('5000000.00', $rule['scope_spend']);
    }

    #[Test]
    public function a_combo_picks_up_a_category_that_joined_it_later_in_the_period(): void
    {
        $member = $this->makeSystemCategory();
        $latecomer = $this->makeSystemCategory();
        $combo = $this->makeCombo([$member]);

        $card = $this->cardWithTiersAndRules('1000000', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '1000000.00',
        ]], [
            $this->comboRule($combo, percent: '5.000', capCategory: '400000.00', quota: true),
            $this->categoryRule($latecomer, percent: '5.000', quota: false),
        ]);

        $this->spend($card, $member, '2000000'); // 100.000 khi chưa có latecomer

        // Danh mục này ban đầu là rule riêng (không tick quota); sau đó vào combo.
        $this->spend($card, $latecomer, '1000000'); // 50.000
        CategoryComboItem::create(['combo_id' => $combo->id, 'category_id' => $latecomer->id, 'sort_order' => 1]);

        $rule = $this->ruleFor($this->quotaOf($card), 'combo_id', (int) $combo->id);

        // Membership HIỆN TẠI quyết định gom tiền, không sửa snapshot lịch sử.
        $this->assertContains((int) $latecomer->id, $rule['scope_category_ids']);
        $this->assertSame('150000.00', $rule['cashback_used']);
    }

    // =====================================================================
    // 6. Rule quota KHÔNG có trần riêng — chỉ bị trần CHUNG chặn
    // =====================================================================

    #[Test]
    public function a_quota_rule_without_its_own_cap_is_bounded_only_by_the_tier_cap(): void
    {
        $shopee = $this->makeSystemCategory();
        // `max_cashback_per_category_per_period = NULL` là MỘT QUY ƯỚC ĐÃ CHỐT:
        // rule này không có trần riêng, chỉ chịu ngân sách chung của bậc. Không
        // phải "chưa cấu hình xong", nên không được trả null cho những con số mà
        // user hành động được.
        $card = $this->cardWithTiersAndRules('1000000', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '1000000.00',
        ]], [
            $this->categoryRule($shopee, percent: '5.000', capCategory: null, quota: true),
        ]);

        $transaction = $this->spend($card, $shopee, '2000000');

        $quota = $this->quotaOf($card);
        $rule = $this->ruleFor($quota, 'category_id', (int) $shopee->id);

        // "Không có trần riêng" được nói thẳng bằng null + false, không phải bằng
        // một con số bịa ra (bịa `1000000` sẽ ám chỉ có trần danh mục 1 triệu).
        $this->assertNull($rule['cashback_max']);
        $this->assertFalse($rule['has_cashback_max']);
        $this->assertNull($rule['cashback_remaining']);

        // Nhưng SỐ TIỀN CÒN KIẾM ĐƯỢC thì phải có: bị chặn bởi trần chung 1.000.000
        // đã dùng 100.000 ⇒ còn 900.000.
        $this->assertSame('100000.00', $rule['cashback_used']);
        $this->assertSame('900000.00', $rule['cashback_available_for_rule']);
        $this->assertFalse($rule['is_exhausted']);

        // 900.000 hoàn tiền @5% ⇒ chi thêm 18.000.000 (đủ để đi hết dải 0–∞).
        $this->assertSame('18000000.00', $rule['spend_remaining_estimate']);
        $this->assertTrue($rule['spend_estimate_is_reachable']);

        $this->assertSame('100000.00', (string) $transaction->cashback_amount_snapshot);
    }

    #[Test]
    public function a_quota_rule_with_no_own_cap_and_no_tier_cap_has_nothing_left_to_earn(): void
    {
        $shopee = $this->makeSystemCategory();
        // Bậc KHÔNG đặt trần chung (`cap_period = null`) + rule KHÔNG đặt trần riêng
        // ⇒ không tồn tại trần nào. Engine cũng vậy: `max_cashback_per_period =
        // NULL` thì không chặn gì cả, nên coi như đã đạt trần là sai.
        $card = $this->cardWithTiersAndRules('0', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => null,
        ]], [
            $this->categoryRule($shopee, percent: '5.000', capCategory: null, quota: true),
        ]);

        $this->spend($card, $shopee, '2000000');

        $quota = $this->quotaOf($card);
        $rule = $this->ruleFor($quota, 'category_id', (int) $shopee->id);

        $this->assertNull($rule['cashback_max']);
        $this->assertNull($rule['tier_cashback_max']);
        $this->assertNull($rule['tier_cashback_remaining']);
        $this->assertSame('100000.00', $rule['cashback_used']);
        $this->assertNull($rule['cashback_available_for_rule']);
        $this->assertNull($rule['spend_remaining_estimate']);
        // "Không xác định" ≠ "đã hết".
        $this->assertFalse($rule['is_exhausted']);
    }

    #[Test]
    public function a_quota_rule_with_its_own_cap_still_respects_the_tier_cap(): void
    {
        $shopee = $this->makeSystemCategory();
        $lazada = $this->makeSystemCategory();
        // Trần riêng rộng (1.000.000) nhưng trần chung hẹp (100.000): số còn kiếm
        // phải là MIN ⇒ 90.000. Ngược lại (trần riêng hẹp hơn trần chung) thì
        // `cashback_available_follows_the_rule_remaining...` đã khoá.
        $card = $this->cardWithTiersAndRules('0', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '100000.00',
        ]], [
            $this->categoryRule($shopee, percent: '5.000', capCategory: '1000000.00', quota: true),
        ]);

        // 200.000 × 5% = 10.000 ⇒ chung còn 90.000, riêng còn 990.000.
        $this->spend($card, $shopee, '200000');

        $rule = $this->ruleFor($this->quotaOf($card), 'category_id', (int) $shopee->id);

        $this->assertSame('1000000.00', $rule['cashback_max']);
        $this->assertSame('990000.00', $rule['cashback_remaining']);
        $this->assertSame('90000.00', $rule['cashback_available_for_rule']);
    }

    // =====================================================================
    // 7. Suy ra số tiền chi thêm — walk dải rate
    // =====================================================================

    #[Test]
    public function different_rates_produce_different_spend_estimates(): void
    {
        $slow = $this->makeSystemCategory();
        $slowCard = $this->singleTierCard($slow, '5.000');

        $fast = $this->makeSystemCategory();
        $fastCard = $this->singleTierCard($fast, '10.000');

        // Cùng một ngân sách 1.000.000, chỉ khác tỷ lệ.
        $slowRule = $this->ruleFor($this->quotaOf($slowCard), 'category_id', (int) $slow->id);
        $fastRule = $this->ruleFor($this->quotaOf($fastCard), 'category_id', (int) $fast->id);

        $this->assertSame('1000000.00', $slowRule['cashback_available_for_rule']);
        $this->assertSame('1000000.00', $fastRule['cashback_available_for_rule']);

        // 1.000.000 / 5% = 20.000.000; 1.000.000 / 10% = 10.000.000.
        $this->assertSame('20000000.00', $slowRule['spend_remaining_estimate']);
        $this->assertSame('10000000.00', $fastRule['spend_remaining_estimate']);
    }

    #[Test]
    public function a_multi_band_rule_walks_into_the_next_band_at_its_own_rate(): void
    {
        $shopee = $this->makeSystemCategory();
        $card = $this->singleTierCard($shopee, '5.000', bands: [
            ['spend_from' => '0.00', 'spend_to' => '5000000.00', 'percent' => '5.000'],
            ['spend_from' => '5000000.00', 'spend_to' => null, 'percent' => '10.000'],
        ]);

        $rule = $this->ruleFor($this->quotaOf($card), 'category_id', (int) $shopee->id);

        $this->assertCount(2, $rule['bands']);

        // Dải 1 chỉ chứa 5.000.000 × 5% = 250.000 hoàn tiền. Phần dư 750.000 phải
        // đi tiếp vào dải 2 với TỶ LỆ 10% ⇒ 7.500.000. Tổng 5.000.000 + 7.500.000.
        // Chia một lần cho 5% (flat) sẽ ra 20.000.000 — sai.
        $this->assertSame('1000000.00', $rule['cashback_available_for_rule']);
        $this->assertSame('12500000.00', $rule['spend_remaining_estimate']);
    }

    #[Test]
    public function the_band_walk_starts_from_the_current_spend_of_the_scope(): void
    {
        $shopee = $this->makeSystemCategory();
        $card = $this->singleTierCard($shopee, '5.000', bands: [
            ['spend_from' => '0.00', 'spend_to' => '5000000.00', 'percent' => '5.000'],
            ['spend_from' => '5000000.00', 'spend_to' => null, 'percent' => '10.000'],
        ]);

        // 3.000.000 × 5% = 150.000 hoàn tiền, vẫn nằm trong dải 1.
        $this->spend($card, $shopee, '3000000');

        $rule = $this->ruleFor($this->quotaOf($card), 'category_id', (int) $shopee->id);

        $this->assertSame('3000000.00', $rule['scope_spend']);
        $this->assertSame('150000.00', $rule['cashback_used']);
        $this->assertSame('850000.00', $rule['cashback_available_for_rule']);

        // Phần dải 1 CÒN LẠI chỉ 2.000.000 chi × 5% = 100.000; 750.000 còn lại đi
        // dải 2 @10% = 7.500.000. Tổng 9.500.000 (không phải 12.500.000 như khi
        // chưa chi gì) — chứng minh phép walk tính từ VỊ TRÍ HIỆN TẠI.
        $this->assertSame('9500000.00', $rule['spend_remaining_estimate']);
    }

    #[Test]
    public function a_band_already_passed_is_not_counted_again(): void
    {
        $shopee = $this->makeSystemCategory();
        $card = $this->singleTierCard($shopee, '5.000', bands: [
            ['spend_from' => '0.00', 'spend_to' => '5000000.00', 'percent' => '5.000'],
            ['spend_from' => '5000000.00', 'spend_to' => null, 'percent' => '10.000'],
        ]);

        // Vượt hẳn dải 1. Engine chọn dải 2 và áp 10% cho CẢ kỳ (retroactive).
        $this->spend($card, $shopee, '6000000');

        $rule = $this->ruleFor($this->quotaOf($card), 'category_id', (int) $shopee->id);

        $this->assertSame('600000.00', $rule['cashback_used']);
        $this->assertSame('400000.00', $rule['cashback_available_for_rule']);
        // Dải 1 đã đóng kín nên không đóng góp; chỉ dải 2 @10% ⇒ 4.000.000.
        $this->assertSame('4000000.00', $rule['spend_remaining_estimate']);
    }

    // =====================================================================
    // 8. Chỉ đọc — không recalculate
    // =====================================================================

    #[Test]
    public function reading_the_quota_never_rewrites_a_cashback_snapshot(): void
    {
        $shopee = $this->makeSystemCategory();
        $card = $this->singleTierCard($shopee, '5.000');
        $transaction = $this->spend($card, $shopee, '2000000');

        $transaction->refresh();
        $before = [
            'cashback' => (string) $transaction->cashback_amount_snapshot,
            'percent' => (string) $transaction->cashback_percent_snapshot,
            'rule' => (int) $transaction->policy_tier_category_id,
            'tier' => (int) $transaction->policy_tier_id,
            'period_total' => (string) $this->currentPeriodOf($card)->total_cashback,
            'transactions' => Transaction::query()->count(),
            'rules' => PolicyTierCategory::query()->count(),
        ];

        // Đọc nhiều lần — payload có đầy đủ các trường như contract yêu cầu.
        for ($i = 0; $i < 3; $i++) {
            $quota = $this->quotaOf($card);
            $this->assertNotEmpty($quota['rules']);
        }

        $transaction->refresh();

        $this->assertSame($before['cashback'], (string) $transaction->cashback_amount_snapshot);
        $this->assertSame($before['percent'], (string) $transaction->cashback_percent_snapshot);
        $this->assertSame($before['rule'], (int) $transaction->policy_tier_category_id);
        $this->assertSame($before['tier'], (int) $transaction->policy_tier_id);
        $this->assertSame($before['period_total'], (string) $this->currentPeriodOf($card)->total_cashback);
        $this->assertSame($before['transactions'], Transaction::query()->count());
        $this->assertSame($before['rules'], PolicyTierCategory::query()->count());
    }

    // =====================================================================
    // 9. Tiền qua bcmath
    // =====================================================================

    #[Test]
    public function money_stays_exact_with_a_decimal_rate_and_a_large_amount(): void
    {
        $shopee = $this->makeSystemCategory();
        $card = $this->cardWithTiersAndRules('1000000', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '1000000.00',
        ]], [
            $this->categoryRule($shopee, percent: '7.530', capCategory: '5000000.00', quota: true),
        ]);

        // 12.345.678,99 × 7,53% = 929.629,627947. Engine CHUYỂN TIỀN về nguyên đồng
        // bằng `floor()` (CashbackCalculator::round()), nên snapshot là 929.629.
        // Quota đọc đúng chuỗi đó, không đi qua float và không tự làm tròn lại.
        $transaction = $this->spend($card, $shopee, '12345678.99');

        $this->assertSame('929629.00', (string) $transaction->cashback_amount_snapshot);

        $quota = $this->quotaOf($card);
        $rule = $this->ruleFor($quota, 'category_id', (int) $shopee->id);

        $this->assertSame('929629.00', $quota['tier_cashback_used']);
        $this->assertSame('929629.00', $rule['cashback_used']);
        $this->assertSame('4070371.00', $rule['cashback_remaining']);

        foreach ([$quota['tier_cashback_max'], $quota['tier_cashback_used'], $quota['tier_cashback_remaining']] as $money) {
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $money);
        }
    }

    #[Test]
    public function a_non_terminating_division_rounds_up_so_the_estimate_is_not_short(): void
    {
        $shopee = $this->makeSystemCategory();
        $card = $this->cardWithTiersAndRules('1000000', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '100.00',
        ]], [
            $this->categoryRule($shopee, percent: '3.000', capCategory: '100.00', quota: true),
        ]);

        $rule = $this->ruleFor($this->quotaOf($card), 'category_id', (int) $shopee->id);

        // 100 / 3% = 3333,3333… ⇒ LÀM TRÒN LÊN 3333,34. Làm tròn xuống sẽ báo
        // user chi thiếu.
        $this->assertSame('100.00', $rule['cashback_available_for_rule']);
        $this->assertSame('3333.34', $rule['spend_remaining_estimate']);
    }

    // =====================================================================
    // 10. Regression Phase 1
    // =====================================================================

    #[Test]
    public function quota_rules_in_two_tiers_of_one_policy_version_still_return_422(): void
    {
        $first = $this->makeSystemCategory();
        $second = $this->makeSystemCategory();
        $card = $this->cardWithTiersAndRules('1000000', [
            ['name' => 'Bậc 1', 'min' => 0, 'max' => 5000000, 'cap_period' => '100000.00'],
            ['name' => 'Bậc 2', 'min' => 5000000, 'max' => null, 'cap_period' => '1000000.00'],
        ], [
            $this->categoryRule($first, onlyTier: 0, percent: '5.000', capCategory: '300000.00'),
            $this->categoryRule($second, onlyTier: 1, percent: '7.000', capCategory: '400000.00'),
        ]);

        $rules = PolicyTierCategory::query()->orderBy('id')->get();

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.rules.update', $rules[0]->id), ['is_quota_category' => true])
            ->assertOk();

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.rules.update', $rules[1]->id), ['is_quota_category' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', CategoryRuleService::QUOTA_TIER_CONFLICT_MESSAGE);

        $this->assertTrue($rules[0]->refresh()->is_quota_category);
        $this->assertFalse($rules[1]->refresh()->is_quota_category);
    }

    // =====================================================================
    // Fixture
    // =====================================================================

    /**
     * Thẻ + policy + kỳ hiện tại đã gắn version, theo fixture của caller.
     *
     * @param  array<int, array<string, mixed>>  $tiers
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function cardWithTiersAndRules(?string $desiredSpend, array $tiers, array $rules): UserCard
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => $desiredSpend]);

        $policy = Policy::create([
            'user_card_id' => $card->id,
            'version_no' => 1,
            'status' => Policy::STATUS_ACTIVE,
            'name' => 'Policy quota',
            'effective_from' => '2000-01-01',
            'effective_to' => null,
            'min_total_spend' => 0,
            'rounding_mode' => 'round',
        ]);

        // Root trỏ về chính nó ⇒ UNIQUE (root_policy_id, version_no) có hiệu lực.
        $policy->forceFill(['root_policy_id' => $policy->id])->save();

        foreach (array_values($tiers) as $index => $tier) {
            $tierModel = PolicyTier::create([
                'policy_id' => $policy->id,
                'name' => $tier['name'] ?? 'Bậc '.($index + 1),
                'sort_order' => $index,
                'min_total_spend' => $tier['min'],
                'max_total_spend' => $tier['max'] ?? null,
                'max_cashback_per_period' => $tier['cap_period'] ?? null,
            ]);

            foreach (array_values($rules) as $sortOrder => $rule) {
                if (isset($rule['only_tier']) && $rule['only_tier'] !== $index) {
                    continue;
                }

                unset($rule['only_tier']);

                PolicyTierCategory::create(array_merge([
                    'tier_id' => $tierModel->id,
                    'sort_order' => $sortOrder,
                    'spend_from' => '0.00',
                    'spend_to' => null,
                    'cashback_percent' => '0.000',
                    'is_enabled' => true,
                    'counts_toward_tier_cap' => true,
                    'is_quota_category' => false,
                ], $rule));
            }
        }

        $card->forceFill(['current_policy_id' => $policy->id])->save();

        return $this->openCurrentPeriod($card->refresh());
    }

    /**
     * Một bậc duy nhất, một danh mục tick quota, trần chung = trần riêng =
     * 1.000.000 — dùng cho các test chỉ cần một con số sạch.
     *
     * @param  array<int, array<string, mixed>>  $bands
     */
    private function singleTierCard($category, string $percent, array $bands = []): UserCard
    {
        if ($bands === []) {
            $bands = [['spend_from' => '0.00', 'spend_to' => null, 'percent' => $percent]];
        }

        $rules = [];
        $sortOrder = 0;

        foreach ($bands as $band) {
            $rules[] = $this->categoryRule(
                $category,
                onlyTier: 0,
                percent: $band['percent'],
                capCategory: '1000000.00',
                quota: true,
                sortOrder: $sortOrder++,
                spendFrom: $band['spend_from'],
                spendTo: $band['spend_to'],
            );
        }

        return $this->cardWithTiersAndRules('1000000', [[
            'name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '1000000.00',
        ]], $rules);
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryRule(
        $category,
        ?int $onlyTier = null,
        string $percent = '5.000',
        ?string $capCategory = null,
        bool $quota = false,
        int $sortOrder = 0,
        string $spendFrom = '0.00',
        ?string $spendTo = null,
    ): array {
        return array_filter([
            'category_id' => $category->id,
            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
            'cashback_percent' => $percent,
            'max_cashback_per_category_per_period' => $capCategory,
            'is_quota_category' => $quota,
            'sort_order' => $sortOrder,
            'spend_from' => $spendFrom,
            'spend_to' => $spendTo,
            'only_tier' => $onlyTier,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function comboRule(CategoryCombo $combo, string $percent = '5.000', ?string $capCategory = null, bool $quota = false): array
    {
        return [
            'category_id' => null,
            'combo_id' => $combo->id,
            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
            'cashback_percent' => $percent,
            'max_cashback_per_category_per_period' => $capCategory,
            'is_quota_category' => $quota,
        ];
    }

    /**
     * @param  array<int, mixed>  $members
     */
    private function makeCombo(array $members): CategoryCombo
    {
        static $sequence = 0;
        $sequence++;

        $combo = CategoryCombo::create([
            'scope' => CategoryCombo::SCOPE_USER,
            'owner_user_id' => $this->owner->id,
            'name' => 'Combo '.$sequence,
            'slug' => 'combo-'.$sequence.'-'.uniqid(),
            'is_active' => true,
        ]);

        foreach ($members as $index => $member) {
            CategoryComboItem::create([
                'combo_id' => $combo->id,
                'category_id' => $member->id,
                'sort_order' => $index,
            ]);
        }

        return $combo;
    }

    /**
     * Hai bậc: bậc 1 (0–5tr, trần 100.000) và bậc 2 (từ 5tr, trần 1.000.000),
     * cùng một danh mục ở cả hai bậc với TRẦN RIÊNG khác nhau — đủ để chứng minh
     * quota lấy số của bậc đích chứ không phải bậc engine đang chạy.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function twoTierFixture($category): array
    {
        return [
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 5000000, 'cap_period' => '100000.00'],
                ['name' => 'Bậc 2', 'min' => 5000000, 'max' => null, 'cap_period' => '1000000.00'],
            ],
            [
                $this->categoryRule($category, onlyTier: 0, percent: '5.000', capCategory: '50000.00'),
                $this->categoryRule($category, onlyTier: 1, percent: '5.000', capCategory: '300000.00', quota: true),
            ],
        ];
    }

    /**
     * Mở kỳ hiện tại và gắn version policy qua chính pipeline của engine: ghi một
     * giao dịch rồi xoá, để trạng thái `policy_id` của kỳ giống hệt lúc chạy thật.
     */
    private function openCurrentPeriod(UserCard $card): UserCard
    {
        $throwaway = $this->makeSystemCategory();
        $this->spend($card, $throwaway, '1');

        $transaction = Transaction::query()->where('user_card_id', $card->id)->firstOrFail();
        app(CreditCardTransactionService::class)->delete($this->owner->id, $transaction->id);

        return $card->refresh();
    }

    /** Ghi một giao dịch ở kỳ hiện tại và trả về nó. */
    private function spend(UserCard $card, $category, string $amount): Transaction
    {
        $date = $this->dateInCurrentPeriod($card, CarbonImmutable::now());

        return app(CreditCardTransactionService::class)->create($card, [
            'transaction_date' => $date->toDateString(),
            'amount' => $amount,
            'category_id' => $category->id,
        ]);
    }

    /**
     * Ngày hợp lệ trong kỳ hiện tại của thẻ, lùi 1 ngày để không đụng biên.
     */
    private function dateInCurrentPeriod(UserCard $card, CarbonImmutable $today): CarbonImmutable
    {
        [$start, $end] = app(StatementPeriodService::class)->currentBoundaries($card, $today);

        $date = $today->subDay();

        if ($date->lessThan($start)) {
            $date = $start;
        }

        if ($date->greaterThan($end)) {
            $date = $end;
        }

        return $date;
    }

    private function currentPeriodOf(UserCard $card): StatementPeriod
    {
        return StatementPeriod::query()
            ->where('user_card_id', $card->id)
            ->open()
            ->orderByDesc('period_start')
            ->firstOrFail();
    }

    private function tierIdOf(UserCard $card, int $sortOrder): int
    {
        $policyId = $card->refresh()->current_policy_id;

        return (int) PolicyTier::query()
            ->where('policy_id', $policyId)
            ->where('sort_order', $sortOrder)
            ->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function quotaOf(UserCard $card): array
    {
        return app(CreditCardOverviewService::class)->forPage($this->owner->id)['cards'][$card->id]['quota'];
    }

    /**
     * Rule quota có `key` = `value` trong kết quả.
     *
     * @param  array<string, mixed>  $quota
     * @return array<string, mixed>
     */
    private function ruleFor(array $quota, string $key, int $value): array
    {
        foreach ($quota['rules'] as $rule) {
            if ($rule[$key] === $value) {
                return $rule;
            }
        }

        $this->fail("Không có rule quota nào có {$key} = {$value}.");
    }
}
