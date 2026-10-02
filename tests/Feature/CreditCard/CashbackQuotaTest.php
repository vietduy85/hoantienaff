<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardOverviewService;
use App\Services\CreditCard\CreditCardTransactionService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Quota hoàn tiền của kỳ hiện tại.
 *
 * ---------------------------------------------------------------------------
 * HỢP ĐỒNG ĐANG KIỂM TRA
 * ---------------------------------------------------------------------------
 * 1. Quota = `PolicyTier.max_cashback_per_period` — LỚP CAP THỨ BA của engine
 *    (`CashbackCalculator`). Hai lớp còn lại là trần theo từng lát (mỗi giao
 *    dịch / mỗi danh mục trong kỳ); gộp chúng thành một "quota" sẽ bịa ra giới
 *    hạn mà engine không có.
 * 2. "Đã dùng" CHỈ cộng cashback của giao dịch áp rule có
 *    `counts_toward_tier_cap = true` — đúng cái engine dùng khi kẹp lớp cap 3.
 * 3. "Còn lại" = trần − đã dùng, KHÔNG âm.
 * 4. Bậc tra theo `UserCard.desired_spend` (mục tiêu), qua `TierResolverService`
 *    — không hardcode ngưỡng, không suy đoán tên bậc.
 * 5. Không có trần ⇒ KHÔNG có "còn lại" (`null`), không ép về 0 (0 sẽ bị hiểu là
 *    đã hết quota).
 */
class CashbackQuotaTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    protected function setUp(): void
    {
        // `setUpCreditCardTestCase()` tự gọi `parent::setUp()` — gọi thêm ở đây
        // sẽ mở hai transaction và `RefreshDatabase` báo "already an active
        // transaction".
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();
    }

    // =====================================================================
    // Trần lấy từ bậc
    // =====================================================================

    #[Test]
    public function a_tier_without_a_period_cap_has_no_quota(): void
    {
        $card = $this->cardWithQuota(null);

        $quota = $this->quotaOf($card);

        $this->assertFalse($quota['has_limit']);
        $this->assertNull($quota['limit']);
        $this->assertNull($quota['remaining']);
        // `is_exhausted` KHÔNG được bật: chưa có trần không phải là hết quota.
        $this->assertFalse($quota['is_exhausted']);
        $this->assertSame('0.00', $quota['used']);
    }

    #[Test]
    public function the_whole_period_is_left_when_nothing_was_spent_yet(): void
    {
        $card = $this->cardWithQuota('500000.00');

        $quota = $this->quotaOf($card);

        $this->assertTrue($quota['has_limit']);
        $this->assertSame('500000.00', $quota['limit']);
        $this->assertSame('0.00', $quota['used']);
        $this->assertSame('500000.00', $quota['remaining']);
        $this->assertFalse($quota['is_exhausted']);
    }

    #[Test]
    public function spending_lowers_the_remaining_quota_by_the_cashback_earned(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->cardWithQuota('500000.00', $category);

        // 2.000.000 × 5% = 100.000 hoàn tiền.
        $this->spend($card, $category, '2000000');

        $quota = $this->quotaOf($card);

        $this->assertSame('100000.00', $quota['used']);
        $this->assertSame('400000.00', $quota['remaining']);
        $this->assertFalse($quota['is_exhausted']);
    }

    #[Test]
    public function the_remaining_quota_never_goes_below_zero(): void
    {
        $category = $this->makeSystemCategory();
        // Trần cố ý nhỏ hơn cashback đã kiếm: engine vẫn ghi cashback thật, chỉ là
        // con số "còn lại" phải kẹp ở 0 chứ không âm (âm sẽ đọc như còn thêm được).
        $card = $this->cardWithQuota('50000.00', $category);

        $this->spend($card, $category, '2000000');

        $quota = $this->quotaOf($card);

        // "Đã dùng" phải bằng đúng số engine đã ghi, không phải số test tự tính —
        // engine kẹp theo lớp cap nên có thể nhỏ hơn cashback lý thuyết.
        $period = $this->currentPeriodOf($card);
        $this->assertSame(
            (string) $period->total_cashback,
            $quota['used'],
            'Phần đã dùng phải khớp tổng cashback engine ghi cho kỳ.',
        );
        $this->assertGreaterThan(0, (float) $quota['used']);

        // Kẹp ở 0 chứ không âm — âm sẽ đọc như còn thêm được.
        $this->assertSame('0.00', $quota['remaining']);
        $this->assertTrue($quota['is_exhausted']);
    }

    // =====================================================================
    // Cờ counts_toward_tier_cap
    // =====================================================================

    #[Test]
    public function cashback_from_a_rule_that_does_not_count_toward_the_cap_is_ignored(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->cardWithQuota('500000.00', $category, countsCap: false);

        $this->spend($card, $category, '2000000');

        $quota = $this->quotaOf($card);

        // Cashback thật vẫn có (100.000), nhưng lớp cap toàn kỳ KHÔNG ăn nó, nên
        // quota vẫn nguyên. Nếu ta cộng mọi giao dịch thì số này lệch engine.
        $this->assertSame('0.00', $quota['used']);
        $this->assertSame('500000.00', $quota['remaining']);

        $transaction = Transaction::query()->where('user_card_id', $card->id)->firstOrFail();
        $this->assertSame('100000.00', (string) $transaction->cashback_amount_snapshot);
    }

    #[Test]
    public function only_transactions_of_the_current_period_reduce_the_quota(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->cardWithQuota('500000.00', $category);

        $currentPeriod = $this->currentPeriodOf($card);

        // Giao dịch kỳ TRƯỚC: vẫn nằm trong lịch sử nhưng không giảm quota của
        // kỳ hiện tại. Ngày chọn đủ xa để chắc chắn rơi vào kỳ khác.
        $oldDate = CarbonImmutable::now()->subMonths(2)->startOfMonth()->addDays(2);
        $oldTransaction = app(CreditCardTransactionService::class)->create($card, [
            'transaction_date' => $oldDate->toDateString(),
            'amount' => '1000000',
            'category_id' => $category->id,
        ]);

        $oldPeriod = $oldTransaction->statementPeriod;

        $this->assertNotSame((int) $currentPeriod->id, (int) $oldPeriod->id);

        $oldPeriod->forceFill(['status' => StatementPeriod::STATUS_FINALIZED])->save();

        $quota = $this->quotaOf($card);

        $this->assertSame('0.00', $quota['used']);
        $this->assertSame('500000.00', $quota['remaining']);
    }

    #[Test]
    public function a_card_without_a_policy_has_no_quota(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => 1000000]);

        $quota = $this->quotaOf($card);

        $this->assertFalse($quota['has_limit']);
        $this->assertNull($quota['tier_id']);
        $this->assertNull($quota['tier_name']);
        $this->assertNull($quota['remaining']);
    }

    // =====================================================================
    // Bậc lấy từ desired_spend
    // =====================================================================

    #[Test]
    public function the_tier_is_picked_from_the_desired_spend_not_the_actual_spend(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => 6000000]);

        $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc thấp', 'min' => 0, 'max' => 5000000, 'cap_period' => '100000.00'],
                ['name' => 'Bậc cao', 'min' => 5000000, 'max' => null, 'cap_period' => '900000.00'],
            ],
            [['category_id' => $category->id, 'percent' => '5.000']]
        );

        // Chi tiêu thực tế = 0 (chưa giao dịch) nhưng mục tiêu là 6 triệu ⇒ bậc cao.
        $quota = $this->quotaOf($card);

        $this->assertSame('Bậc cao', $quota['tier_name']);
        $this->assertSame('900000.00', $quota['limit']);
    }

    #[Test]
    public function a_card_without_a_goal_falls_into_the_lowest_tier(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => null]);

        $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc thấp', 'min' => 0, 'max' => 5000000, 'cap_period' => '100000.00'],
                ['name' => 'Bậc cao', 'min' => 5000000, 'max' => null, 'cap_period' => '900000.00'],
            ],
            [['category_id' => $category->id, 'percent' => '5.000']]
        );

        // Chưa đặt mục tiêu ⇒ `desired_spend` NULL coi như 0 ⇒ bậc phủ 0.
        $this->assertSame('Bậc thấp', $this->quotaOf($card)['tier_name']);
    }

    // =====================================================================
    // Tiến độ mục tiêu (đi cùng quota trên cùng một dòng thẻ)
    // =====================================================================

    #[Test]
    public function progress_is_spent_over_the_desired_spend_and_can_exceed_one_hundred(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => 1000000]);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '500000.00']],
            [['category_id' => $category->id, 'percent' => '5.000']]
        );

        $this->spend($card, $category, '500000');

        $metrics = app(CreditCardOverviewService::class)->forPage($this->owner->id)['cards'][$card->id];

        $this->assertTrue($metrics['has_goal']);
        $this->assertSame('1000000.00', $metrics['desired_spend']);
        $this->assertSame('500000.00', $metrics['spent']);
        $this->assertSame('50.00', $metrics['progress_percent']);

        // Vượt mục tiêu: trả về số thật, KHÔNG kẹp 100. UI tự vẽ thanh đầy + nhãn.
        $this->spend($card, $category, '1000000');

        $metrics = app(CreditCardOverviewService::class)->forPage($this->owner->id)['cards'][$card->id];

        $this->assertSame('1500000.00', $metrics['spent']);
        $this->assertSame('150.00', $metrics['progress_percent']);
    }

    #[Test]
    public function a_card_without_a_goal_reports_no_progress(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => null]);

        $metrics = app(CreditCardOverviewService::class)->forPage($this->owner->id)['cards'][$card->id];

        $this->assertFalse($metrics['has_goal']);
        // Tiến độ vẫn trả 0.00 để payload có hình dạng cố định, nhưng UI dựa vào
        // `has_goal` để KHÔNG vẽ thanh 0% (0% giả sẽ bị hiểu là đã đo).
        $this->assertSame('0.00', $metrics['progress_percent']);
    }

    #[Test]
    public function the_overview_carries_the_quota_of_every_card(): void
    {
        $category = $this->makeSystemCategory();
        $first = $this->cardWithQuota('500000.00', $category);
        $second = $this->cardWithQuota('200000.00', $category);

        $cards = app(CreditCardOverviewService::class)->forPage($this->owner->id)['cards'];

        $this->assertSame('500000.00', $cards[$first->id]['quota']['limit']);
        $this->assertSame('200000.00', $cards[$second->id]['quota']['limit']);
        // Mỗi thẻ có hồ riêng — không gộp chung.
        $this->assertSame('500000.00', $cards[$first->id]['quota']['remaining']);
        $this->assertSame('200000.00', $cards[$second->id]['quota']['remaining']);
    }

    // =====================================================================
    // Helper
    // =====================================================================

    /**
     * Thẻ đã có policy một bậc, có trần kỳ tùy chọn và đã gắn vào kỳ hiện tại.
     *
     * Gắn policy vào kỳ qua chính pipeline (`create()` của
     * `CreditCardTransactionService`) để trạng thái `policy_id` của kỳ giống hệt
     * lúc chạy thật, thay vì ép tay.
     */
    private function cardWithQuota(
        ?string $capPeriod,
        $category = null,
        bool $countsCap = true,
    ): UserCard {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => 1000000]);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => $capPeriod]],
            [[
                'category_id' => ($category ?? $this->makeSystemCategory())->id,
                'percent' => '5.000',
                'counts_cap' => $countsCap,
            ]]
        );

        // Tạo kỳ hiện tại (và gắn version) bằng cách ghi một giao dịch rồi xoá
        // giao dịch đi — cách duy nhất đi qua đúng pipeline gắn policy của engine.
        $this->spend($card, $category ?? $this->makeSystemCategory(), '1');

        $transaction = Transaction::query()->where('user_card_id', $card->id)->firstOrFail();
        app(CreditCardTransactionService::class)->delete($this->owner->id, $transaction->id);

        return $card->refresh();
    }

    /** Ghi một giao dịch ở kỳ hiện tại và trả về nó. */
    private function spend(UserCard $card, $category, string $amount): Transaction
    {
        $today = CarbonImmutable::now();
        $date = $this->dateInCurrentPeriod($card, $today);

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

    /**
     * Kỳ sao kê hiện tại của thẻ (kỳ `open` chứa hôm nay).
     */
    private function currentPeriodOf(UserCard $card): StatementPeriod
    {
        return StatementPeriod::query()
            ->where('user_card_id', $card->id)
            ->open()
            ->orderByDesc('period_start')
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function quotaOf(UserCard $card): array
    {
        return app(CreditCardOverviewService::class)->forPage($this->owner->id)['cards'][$card->id]['quota'];
    }
}
