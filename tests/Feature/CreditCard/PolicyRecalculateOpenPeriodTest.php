<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardOverviewService;
use App\Services\CreditCard\CreditCardTransactionService;
use App\Services\CreditCard\PolicyService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Lưu policy xong phải recalculate kỳ ĐANG MỞ (Fix 2).
 *
 * ---------------------------------------------------------------------------
 * HỢP ĐỒNG (đã chốt — đóng có chủ đích, không backfill)
 * ---------------------------------------------------------------------------
 *   1. Bốn đường đổi policy của THẺ đều recalc kỳ open trong cùng transaction:
 *      `createVersion` (versions.store), `cloneTemplate` (chọn mẫu),
 *      `updateCurrentVersionInPlace` (sửa tại chỗ), `createFromScratch` (lần
 *      đầu có cấu hình). Nhờ vậy `period.policy_id` re-attach version mới và
 *      snapshot/quota đọc cấu hình mới thay vì bản cũ.
 *   2. Kỳ FINALIZED/CLOSED KHÔNG BAO GIỜ bị đụng tới: `recalculateOpenPeriods`
 *      lọc `open()` + `calculatePeriod()` early-return — lịch sử để nguyên.
 *   3. `is_locked` không bao giờ tự set khi finalize period, nên versions.store
 *      và sửa tại chỗ vẫn chạy được; policy version cũ chỉ đổi status
 *      `superseded` (append-only).
 *
 * Mỗi test này FAIL trên code cũ (không có hook recalc): snapshot kỳ open giữ
 * số cũ, `period.policy_id` không re-attach, quota đọc version sai.
 */
class PolicyRecalculateOpenPeriodTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    /** Hôm nay: 07/10/2026 — kỳ derive [09-15 .. 10-14], version hiệu lực 07/10 ≤ 10/14. */
    private const TODAY = '2026-10-07 12:00:00';

    private User $owner;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();

        $this->atDate(self::TODAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // =====================================================================
    // C · versions.store + sửa tại chỗ ⇒ kỳ open đọc version mới
    // =====================================================================

    #[Test]
    public function creating_a_new_version_and_editing_it_in_place_recalculates_the_open_period(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Nhà hàng']);
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);
        $this->attachPolicyWithCap($card, $category, '4000000');

        $tx = $this->spend($card, $category, '20000000');

        // Giai đoạn 1: version A, trần 4 triệu ⇒ cashback 1.000.000 (5% của 20tr).
        $a = Policy::findOrFail($card->current_policy_id);
        $period = $this->openPeriodOf($card);

        $this->assertSame((int) $a->id, (int) $period->policy_id);
        $this->assertSame('1000000.00', (string) $period->total_cashback);
        $this->assertSame('1000000.00', (string) $tx->cashback_amount_snapshot);
        $this->assertSame('4000000.00', $this->quotaLimitOf($card));

        // Giai đoạn 2: tạo version B (effective 07/10) — kỳ open phải re-attach B.
        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.versions.store', $card->id), [
                'effective_from' => '2026-10-07',
            ])
            ->assertCreated();

        $b = Policy::findOrFail($response->json('data.id'));

        $period = $this->openPeriodOf($card);
        $this->assertSame((int) $b->id, (int) $period->policy_id, 'Kỳ open phải gắn lại version mới sau versions.store.');

        $a->refresh();
        $this->assertSame(Policy::STATUS_SUPERSEDED, $a->status);

        // Giai đoạn 3: sửa tại chỗ version B — hạ trần còn 400.000 ⇒ recalc ngay.
        $tier = $b->tiers()->orderBy('sort_order')->firstOrFail();
        $rule = $tier->tierCategoryRules()->whereNotNull('category_id')->firstOrFail();

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), [
                'policy' => [
                    'effective_from' => '2026-10-07',
                    'tiers' => [[
                        'id' => $tier->id,
                        'name' => $tier->name,
                        'sort_order' => (int) $tier->sort_order,
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'max_cashback_per_period' => 400000,
                        'rules' => [[
                            'id' => $rule->id,
                            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                            'category_id' => $category->id,
                            'cashback_percent' => 5,
                        ]],
                    ]],
                ],
            ])
            ->assertOk();

        $period = $this->openPeriodOf($card);
        $tx->refresh();

        $this->assertSame((int) $b->id, (int) $period->policy_id);
        $this->assertSame('400000.00', (string) $period->total_cashback, 'Kỳ open phải tính lại theo trần mới.');
        $this->assertSame('400000.00', (string) $tx->cashback_amount_snapshot);
        $this->assertSame('400000.00', $this->quotaLimitOf($card), 'Quota phải đọc version đang gắn với kỳ.');

        // Lịch sử không bị đụng: trần của version A giữ nguyên.
        $tierA = PolicyTier::query()->where('policy_id', $a->id)->firstOrFail();
        $this->assertSame('4000000.00', (string) $tierA->max_cashback_per_period);
    }

    // =====================================================================
    // D · Kỳ finalized là lịch sử — policy đổi cũng không recalc
    // =====================================================================

    #[Test]
    public function a_finalized_period_is_untouched_when_the_policy_changes(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Nhà hàng']);
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);
        $this->attachPolicyWithCap($card, $category, '4000000');

        // Kỳ cũ [08-15 .. 09-14] với 50.000 cashback, rồi FINALIZE.
        $txOld = $this->spend($card, $category, '1000000', CarbonImmutable::parse('2026-09-10'));
        $oldPeriod = StatementPeriod::findOrFail($txOld->statement_period_id);
        $this->assertSame('50000.00', (string) $oldPeriod->total_cashback);

        $oldPeriod->forceFill(['status' => StatementPeriod::STATUS_FINALIZED])->save();

        // Kỳ hiện tại open với 1.000.000 cashback.
        $txNew = $this->spend($card, $category, '20000000');
        $a = Policy::findOrFail($card->current_policy_id);
        $this->assertSame('1000000.00', (string) $this->openPeriodOf($card)->total_cashback);

        // Đổi policy: version B + hạ trần 400.000 — chỉ kỳ open được recalc.
        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.versions.store', $card->id), [
                'effective_from' => '2026-10-07',
            ])
            ->assertCreated();

        $b = Policy::findOrFail($response->json('data.id'));
        $tier = $b->tiers()->orderBy('sort_order')->firstOrFail();
        $rule = $tier->tierCategoryRules()->whereNotNull('category_id')->firstOrFail();

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), [
                'policy' => [
                    'effective_from' => '2026-10-07',
                    'tiers' => [[
                        'id' => $tier->id,
                        'name' => $tier->name,
                        'sort_order' => (int) $tier->sort_order,
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'max_cashback_per_period' => 400000,
                        'rules' => [[
                            'id' => $rule->id,
                            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                            'category_id' => $category->id,
                            'cashback_percent' => 5,
                        ]],
                    ]],
                ],
            ])
            ->assertOk();

        // Kỳ OPEN: đã recalc theo version B + trần mới.
        $open = $this->openPeriodOf($card);
        $txNew->refresh();

        $this->assertSame((int) $b->id, (int) $open->policy_id);
        $this->assertSame('400000.00', (string) $open->total_cashback);
        $this->assertSame('400000.00', (string) $txNew->cashback_amount_snapshot);

        // Kỳ FINALIZED: nguyên vẹn — policy, tổng, snapshot, status.
        $oldPeriod->refresh();
        $txOld->refresh();

        $this->assertSame(StatementPeriod::STATUS_FINALIZED, $oldPeriod->status);
        $this->assertSame((int) $a->id, (int) $oldPeriod->policy_id, 'Kỳ finalized phải giữ nguyên version cũ.');
        $this->assertSame('50000.00', (string) $oldPeriod->total_cashback);
        $this->assertSame('50000.00', (string) $txOld->cashback_amount_snapshot);

        // Finalize period KHÔNG khoá policy — versions.store vẫn chạy được.
        $a->refresh();
        $this->assertFalse((bool) $a->is_locked);
    }

    // =====================================================================
    // cloneTemplate · chọn mẫu ⇒ kỳ open re-attach + recalc
    // =====================================================================

    #[Test]
    public function cloning_a_template_into_the_card_recalculates_the_open_period(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Nhà hàng']);
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);
        $this->attachPolicyWithCap($card, $category, '4000000');

        $tx = $this->spend($card, $category, '20000000');

        $a = Policy::findOrFail($card->current_policy_id);
        $this->assertSame('1000000.00', (string) $this->openPeriodOf($card)->total_cashback);
        $this->assertSame('4000000.00', $this->quotaLimitOf($card));

        // Mẫu hệ thống một bậc, trần 300.000, cùng danh mục 5%.
        $template = app(PolicyService::class)->createSystemTemplate(
            'Mẫu trần 300k',
            null,
            CarbonImmutable::parse('2026-09-01'),
            [
                'tiers' => [[
                    'name' => 'Bậc 1',
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'max_cashback_per_period' => 300000,
                    'rules' => [[
                        'category_id' => $category->id,
                        'cashback_percent' => 5,
                    ]],
                ]],
            ],
            true,
        );

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), [
                'policy' => [
                    'template_id' => $template->id,
                    'effective_from' => '2026-10-07',
                ],
            ])
            ->assertOk();

        $card->refresh();
        $period = $this->openPeriodOf($card);
        $tx->refresh();

        $this->assertNotNull($card->current_policy_id);
        $this->assertNotSame((int) $a->id, (int) $card->current_policy_id, 'Chọn mẫu phải tạo version clone riêng của thẻ.');
        $this->assertSame((int) $card->current_policy_id, (int) $period->policy_id, 'Kỳ open phải re-attach version clone.');
        $this->assertSame('300000.00', (string) $period->total_cashback, 'Kỳ open phải tính lại theo cấu hình mẫu.');
        $this->assertSame('300000.00', (string) $tx->cashback_amount_snapshot);
        $this->assertSame('300000.00', $this->quotaLimitOf($card));
    }

    // =====================================================================
    // createFromScratch · lần đầu gắn policy ⇒ kỳ open có policy + recalc
    // =====================================================================

    #[Test]
    public function attaching_a_first_policy_recalculates_the_open_period(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Nhà hàng']);
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $tx = $this->spend($card, $category, '2000000');

        // Chưa có policy: kỳ đã tồn tại (do giao dịch) nhưng chưa gắn version.
        $period = $this->openPeriodOf($card);
        $this->assertNull($period->policy_id);
        $this->assertNull($card->current_policy_id);

        // Sửa thẻ + gửi cấu hình tay ⇒ createFromScratch + recalc kỳ open.
        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), [
                'policy' => [
                    'effective_from' => '2026-10-07',
                    'tiers' => [[
                        'name' => 'Bậc 1',
                        'sort_order' => 1,
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'max_cashback_per_period' => 400000,
                        'rules' => [[
                            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                            'category_id' => $category->id,
                            'cashback_percent' => 5,
                        ]],
                    ]],
                ],
            ])
            ->assertOk();

        $card->refresh();
        $period = $this->openPeriodOf($card);
        $tx->refresh();

        $this->assertNotNull($card->current_policy_id);
        $this->assertSame((int) $card->current_policy_id, (int) $period->policy_id, 'Kỳ open phải gắn policy vừa tạo.');
        $this->assertSame('100000.00', (string) $period->total_cashback, 'Kỳ open phải tính cashback theo policy mới.');
        $this->assertSame('100000.00', (string) $tx->cashback_amount_snapshot);
        $this->assertSame('400000.00', $this->quotaLimitOf($card));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function attachPolicyWithCap(UserCard $card, Category $category, string $cap): void
    {
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => $cap]],
            [['category_id' => $category->id, 'percent' => '5.000']],
        );
    }

    /**
     * Giao dịch đi qua service thật (kèm kỳ + snapshot). Không truyền ngày ⇒
     * dùng hôm nay với `setTestNow`; truyền ngày ⇒ ghi đúng ngày đó.
     */
    private function spend(UserCard $card, Category $category, string $amount, ?CarbonImmutable $date = null): Transaction
    {
        if ($date === null) {
            [$start] = app(StatementPeriodService::class)->currentBoundaries($card, CarbonImmutable::now());

            $date = CarbonImmutable::now()->subDay();

            if ($date->lessThan($start)) {
                $date = $start;
            }
        }

        return app(CreditCardTransactionService::class)->create($card, [
            'transaction_date' => $date->toDateString(),
            'amount' => $amount,
            'category_id' => $category->id,
        ]);
    }

    /** Kỳ đang mở (kỳ hiện tại) của thẻ. */
    private function openPeriodOf(UserCard $card): StatementPeriod
    {
        return StatementPeriod::query()
            ->where('user_card_id', $card->id)
            ->open()
            ->orderByDesc('period_start')
            ->firstOrFail();
    }

    /** `quota.limit` đọc qua đúng service Tổng quan — trần bậc đích của kỳ hiện tại. */
    private function quotaLimitOf(UserCard $card): ?string
    {
        $metrics = app(CreditCardOverviewService::class)
            ->forPage($this->owner->id)['cards'][$card->id] ?? null;

        $quota = $metrics['quota'] ?? null;

        return $quota['limit'] ?? null;
    }

    private function atDate(string $when): void
    {
        Carbon::setTestNow($when);
        CarbonImmutable::setTestNow($when);
    }
}
