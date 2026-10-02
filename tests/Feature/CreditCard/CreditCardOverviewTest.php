<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardTransactionService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Trang Tổng quan (/thetindung) — 4 chỉ số + form nhập giao dịch tại chỗ.
 *
 * Bốn bất biến được khoá ở đây:
 *   1. Bốn chỉ số đến từ `CreditCardOverviewService` (aggregate SQL), và
 *      "cashback dự kiến" là SỐ ENGINE ĐÃ GHI vào `StatementPeriod.total_cashback`
 *      — không có công thức cashback nào ở trang hay ở service.
 *   2. "Tổng chi tiêu" = kỳ sao kê HIỆN TẠI (kỳ `open` chứa hôm nay), không phải
 *      30 ngày gần nhất, không phải cả lịch sử.
 *   3. Form chỉ gửi 4 field; ngày giao dịch mặc định là HÔM NAY do server đặt.
 *   4. Người khác không bao giờ thấy thẻ, giao dịch hay hạn mức của user này.
 */
class CreditCardOverviewTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    // =====================================================================
    // 4 chỉ số
    // =====================================================================

    #[Test]
    public function the_overview_renders_exactly_four_metric_tiles(): void
    {
        $this->makeUserCard($this->owner->id);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        foreach ([
            'stat-total-cards',
            'stat-total-limit',
            'stat-total-spend',
            'stat-expected-cashback',
        ] as $testId) {
            $this->assertStringContainsString('data-testid="'.$testId.'"', $html);
        }

        // Đúng 4 ô chỉ số — không dựng thêm ô thứ 5.
        $this->assertSame(4, substr_count($html, 'data-testid="stat-'));
    }

    #[Test]
    public function the_overview_counts_cards_and_sums_their_credit_limits(): void
    {
        $this->makeUserCard($this->owner->id, ['credit_limit' => 50000000]);
        $this->makeUserCard($this->owner->id, ['credit_limit' => 100000000]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        // 150.000.000 = 50tr + 100tr (tiền Việt Nam phân cách nghìn bằng dấu chấm).
        $this->assertStringContainsString('150.000.000', $html);
    }

    #[Test]
    public function the_overview_does_not_leak_another_users_cards_or_limits(): void
    {
        $this->makeUserCard($this->owner->id, ['credit_limit' => 50000000]);
        $this->makeUserCard($this->stranger->id, ['credit_limit' => 900000000]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('50.000.000', $html);
        $this->assertStringNotContainsString('900.000.000', $html);
    }

    #[Test]
    public function the_cards_api_carries_the_same_four_metrics(): void
    {
        $this->makeUserCard($this->owner->id, ['credit_limit' => 70000000]);
        $this->makeUserCard($this->stranger->id, ['credit_limit' => 800000000]);

        $summary = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json('meta.summary');

        // Trang Tổng quan làm mới 4 chỉ số bằng chính endpoint này ⇒ phải khớp.
        $this->assertSame(1, $summary['total_cards']);
        $this->assertSame('70000000.00', $summary['total_credit_limit']);
        $this->assertSame('0.00', $summary['total_spend']);
        $this->assertSame('0.00', $summary['expected_cashback']);

        // Chỉ 4 chỉ số — không kéo theo model kỳ sao kê vào payload JSON.
        $this->assertSame(
            ['total_cards', 'total_credit_limit', 'total_spend', 'expected_cashback'],
            array_keys($summary)
        );
    }

    // =====================================================================
    // Kỳ sao kê hiện tại
    // =====================================================================

    #[Test]
    public function spending_and_cashback_only_count_the_current_statement_period(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        $current = $this->makeCurrentPeriod($card);
        $previous = $this->makeStatementPeriod($card, [
            'period_start' => CarbonImmutable::now()->subMonths(2)->startOfMonth()->toDateString(),
            'period_end' => CarbonImmutable::now()->subMonth()->subDay()->toDateString(),
            'statement_date' => CarbonImmutable::now()->subMonth()->subDay()->toDateString(),
            'payment_due_date' => CarbonImmutable::now()->subMonth()->toDateString(),
            'status' => StatementPeriod::STATUS_FINALIZED,
            'total_cashback' => 999000,
        ]);

        // 1.000.000 trong kỳ cũ (đã đóng) + 250.000 trong kỳ hiện tại.
        $this->createTransaction($card, $category, '1000000', $previous->id, CarbonImmutable::now()->subMonths(2)->subDay()->subDays(3)->toDateString());
        $this->createTransaction($card, $category, '250000', $current->id, CarbonImmutable::now()->toDateString());

        $summary = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json('meta.summary');

        // Chỉ kỳ HIỆN TẠI: 250.000, không phải 1.250.000 cả lịch sử.
        $this->assertSame('250000.00', $summary['total_spend']);
    }

    #[Test]
    public function expected_cashback_is_the_number_the_engine_wrote_for_the_current_period(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $current = $this->makeCurrentPeriod($card);

        // Kỳ đã đóng có cashback riêng — không được cộng vào.
        $this->makeStatementPeriod($card, [
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'statement_date' => '2026-06-30',
            'payment_due_date' => '2026-07-10',
            'status' => StatementPeriod::STATUS_FINALIZED,
            'total_cashback' => 1234567,
        ]);

        $current->forceFill(['total_cashback' => 250000])->save();

        $summary = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json('meta.summary');

        $this->assertSame('250000.00', $summary['expected_cashback']);
    }

    #[Test]
    public function a_refund_reduces_the_total_spend(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $current = $this->makeCurrentPeriod($card);
        $category = $this->makeSystemCategory();

        $this->createTransaction($card, $category, '1000000', $current->id, CarbonImmutable::now()->toDateString());
        $this->createTransaction($card, $category, '-150000', $current->id, CarbonImmutable::now()->toDateString());

        $summary = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json('meta.summary');

        $this->assertSame('850000.00', $summary['total_spend']);
    }

    #[Test]
    public function current_period_totals_are_summed_across_all_of_the_users_cards(): void
    {
        $first = $this->makeUserCard($this->owner->id);
        $second = $this->makeUserCard($this->owner->id);

        $this->makeCurrentPeriod($first)->forceFill(['total_cashback' => 10000])->save();
        $this->makeCurrentPeriod($second)->forceFill(['total_cashback' => 20000])->save();

        $summary = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json('meta.summary');

        $this->assertSame('30000.00', $summary['expected_cashback']);
    }

    #[Test]
    public function opening_the_overview_never_creates_a_statement_period(): void
    {
        $this->makeUserCard($this->owner->id);

        $this->assertSame(0, StatementPeriod::query()->count());

        $this->actingAs($this->owner)->get('/thetindung')->assertOk();
        $this->actingAs($this->owner)->get('/thetindung')->assertOk();
        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk();

        $this->assertSame(0, StatementPeriod::query()->count());
    }

    #[Test]
    public function the_overview_shows_the_current_period_statement_and_due_dates(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->makeCurrentPeriod($card, [
            'period_start' => '2026-09-16',
            'period_end' => '2026-10-15',
            'statement_date' => '2026-10-15',
            'payment_due_date' => '2026-10-25',
            'total_cashback' => 250000,
        ]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('250.000', $html);
        $this->assertStringContainsString('15/10', $html);
        $this->assertStringContainsString('25/10/2026', $html);
    }

    // =====================================================================
    // Ràng buộc trang
    // =====================================================================

    #[Test]
    public function the_overview_has_the_add_transaction_cta_but_no_add_card_button(): void
    {
        $this->makeUserCard($this->owner->id, ['status' => UserCard::STATUS_ACTIVE]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="add-transaction-button"', $html);
        $this->assertStringContainsString('Nhập giao dịch', $html);

        // "+ Thêm thẻ" chỉ nằm ở Quản lý thẻ.
        $this->assertStringNotContainsString('data-testid="add-card-button"', $html);
        $this->assertStringNotContainsString('+ Thêm thẻ', $html);
    }

    #[Test]
    public function the_transaction_form_has_exactly_five_inputs(): void
    {
        $this->makeUserCard($this->owner->id, ['status' => UserCard::STATUS_ACTIVE]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="transaction-form"', $html);

        // Năm ô: NGÀY → SỐ TIỀN → THẺ → DANH MỤC → GHI CHÚ.
        foreach (['tx-transaction-date', 'tx-amount', 'tx-card', 'tx-category', 'tx-note'] as $field) {
            $this->assertStringContainsString('id="'.$field.'"', $html, 'Thiếu ô '.$field);
        }

        // Ngày đứng ĐẦU TIÊN: mọi thứ khác (kỳ sao kê, bậc, quota) đều bám ngày.
        $this->assertLessThan(
            strpos($html, 'id="tx-amount"'),
            strpos($html, 'id="tx-transaction-date"'),
            'Ô ngày phải đứng trước ô số tiền',
        );

        // Ô ngày là `type="date"` có min/max theo kỳ hiện tại của thẻ đang chọn.
        $this->assertMatchesRegularExpression('/id="tx-transaction-date"[^>]*type="date"/', $html);
        $this->assertMatchesRegularExpression('/id="tx-transaction-date"[^>]*:min="periodStart\(\)"/', $html);
        $this->assertMatchesRegularExpression('/id="tx-transaction-date"[^>]*:max="periodEnd\(\)"/', $html);

        // KHÔNG ô nào cho nhập cashback / chính sách / bậc / cap / chọn kỳ sao kê.
        foreach (['cashback', 'policy', 'tier', 'cap', 'statement-period'] as $forbidden) {
            $this->assertStringNotContainsString('id="tx-'.$forbidden.'"', $html);
        }
    }

    #[Test]
    public function the_transaction_date_defaults_to_today_inside_the_current_period(): void
    {
        $this->makeUserCard($this->owner->id, ['status' => UserCard::STATUS_ACTIVE]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();
        $state = $this->overviewState($html);

        // Hôm nay đưa xuống làm giá trị mặc định của ô ngày.
        $this->assertSame(CarbonImmutable::now()->toDateString(), $state['today']);

        // Ranh giới kỳ hiện tại đi kèm từng thẻ để chặn ngày ngoài kỳ ngay trên máy.
        $card = $state['cards'][0];
        $this->assertNotNull($card['period_start']);
        $this->assertNotNull($card['period_end']);
        // Hôm nay phải nằm GIỮA ranh giới kỳ hiện tại của thẻ.
        $this->assertLessThanOrEqual($card['period_end'], $state['today']);
        $this->assertGreaterThanOrEqual($card['period_start'], $state['today']);
    }

    #[Test]
    public function the_overview_no_longer_shows_the_spending_reminder(): void
    {
        $this->makeUserCard($this->owner->id, [
            'status' => UserCard::STATUS_ACTIVE,
            'spending_deadline_day' => 20,
        ]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        // Ngày nhắc là metadata phụ, còn kỳ sao kê do `statement_day` quyết định —
        // hiện nó dễ bị hiểu nhầm là hạn chức năng. Ô ngày + ranh giới kỳ đã thay thế.
        $this->assertStringNotContainsString('deadlineWarnings', $html);
        $this->assertStringNotContainsString('Nhắc chi tiêu', $html);
    }

    #[Test]
    public function the_transaction_form_is_mobile_first(): void
    {
        $this->makeUserCard($this->owner->id, ['status' => UserCard::STATUS_ACTIVE]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        // Chiều cao nút bấm ≥ 44px cho ngón tay; ô nhập cao h-12.
        $this->assertStringContainsString('data-testid="add-transaction-button"', $html);
        $this->assertMatchesRegularExpression('/data-testid="add-transaction-button"[^>]*h-1[24]/', $html);

        // Các ô nhập full-width và có `min-w-0` ở ô chỉ số để không tràn ngang.
        $this->assertMatchesRegularExpression('/id="tx-amount"[^>]*w-full/', $html);
        $this->assertStringContainsString('min-w-0', $html);
    }

    #[Test]
    public function the_overview_offers_a_usable_transaction_card(): void
    {
        $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ VCB chính',
            'status' => UserCard::STATUS_ACTIVE,
            'card_number_last4' => '9876',
        ]);
        $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ đã đóng',
            'status' => UserCard::STATUS_INACTIVE,
        ]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();
        $state = $this->overviewState($html);

        // Ô chọn thẻ của form CHỈ có thẻ còn nhận giao dịch.
        $this->assertCount(1, $state['cards']);
        $this->assertSame('Thẻ VCB chính', $state['cards'][0]['name']);
        $this->assertSame('9876', $state['cards'][0]['last4']);

        // Danh sách thẻ bên dưới vẫn hiện thẻ đã đóng để xem lịch sử.
        $this->assertStringContainsString('Thẻ đã đóng', $html);
    }

    #[Test]
    public function the_transaction_card_options_never_show_a_full_card_number(): void
    {
        $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ VCB chính',
            'status' => UserCard::STATUS_ACTIVE,
            'card_number_last4' => '9876',
        ]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();
        $card = $this->overviewState($html)['cards'][0];

        // Payload chỉ mang 4 số cuối — không có `card_number`/`card_number_masked`.
        // Thêm `period_start`/`period_end` để ô ngày giới hạn theo kỳ của thẻ.
        $this->assertSame(
            ['id', 'name', 'bank', 'last4', 'period_start', 'period_end'],
            array_keys($card),
        );
        $this->assertSame('9876', $card['last4']);
        $this->assertArrayNotHasKey('card_number', $card);
    }

    #[Test]
    public function an_empty_overview_invites_the_user_to_manage_cards(): void
    {
        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('Bạn chưa thêm thẻ tín dụng nào.', $html);

        // Không có thẻ thì không hiện nút nhập giao dịch chết; dẫn sang Quản lý thẻ.
        $this->assertStringNotContainsString('data-testid="add-transaction-button"', $html);
        $this->assertStringContainsString(route('credit-cards.manage'), $html);
    }

    #[Test]
    public function the_card_list_links_to_each_cards_history(): void
    {
        $card = $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ VCB chính',
            'status' => UserCard::STATUS_ACTIVE,
        ]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="card-history-link"', $html);
        $this->assertStringContainsString(route('credit-cards.transactions', ['userCard' => $card->id]), $html);
    }

    #[Test]
    public function the_card_list_hides_the_bank_name_but_keeps_the_last_four(): void
    {
        $bank = $this->makeBank(['name' => 'Ngân hàng ABC', 'slug' => 'ngan-hang-abc']);
        $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ VCB chính',
            'status' => UserCard::STATUS_ACTIVE,
            'bank_id' => $bank->id,
            'card_number_last4' => '9876',
        ]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        // Ô CHỌN thẻ vẫn giữ ngân hàng vì cần để phân biệt khi có nhiều thẻ —
        // nó đi vào trang qua payload Alpine.
        $this->assertSame('Ngân hàng ABC', $this->overviewState($html)['cards'][0]['bank']);

        // Danh sách thẻ không lặp tên ngân hàng (trùng giữa các thẻ, không giúp
        // gì trên điện thoại); chỉ giữ tên thẻ + 4 số cuối.
        $this->assertStringContainsString('•••• 9876', $html);
        $this->assertStringNotContainsString('Ngân hàng ABC', $html);
    }

    #[Test]
    public function the_overview_requires_authentication(): void
    {
        $this->get('/thetindung')->assertRedirect(route('login'));
    }

    // =====================================================================
    // Nhập giao dịch từ form Tổng quan
    // =====================================================================

    #[Test]
    public function a_transaction_cannot_be_created_without_sending_a_date(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        $this->actingAs($this->owner)->postJson(route('credit-cards.api.transactions.store'), [
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '1200000',
            'note' => 'Cà phê sáng',
        ])->assertStatus(422)->assertJsonValidationErrors('transaction_date');

        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function a_transaction_lands_in_the_current_statement_period(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        // Ngày hợp lệ bất kỳ trong kỳ hiện tại — không nhất thiết hôm nay.
        $date = $this->dateInsideCurrentPeriod($card, -3);

        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.transactions.store'), [
            'transaction_date' => $date->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '1200000',
            'note' => 'Cà phê sáng',
        ]);

        $response->assertCreated();

        $transaction = Transaction::findOrFail($response->json('data.id'));

        $this->assertSame($date->toDateString(), $transaction->transaction_date->toDateString());
        $this->assertSame(Transaction::SOURCE_MANUAL, $transaction->source);
        $this->assertSame('Cà phê sáng', $transaction->note);

        // Giao dịch tự gắn vào kỳ sao kê HIỆN TẠI của chính thẻ đó.
        $period = StatementPeriod::query()->where('user_card_id', $card->id)->firstOrFail();
        $this->assertSame($period->id, (int) $transaction->statement_period_id);
        $this->assertTrue($period->contains($date));
    }

    #[Test]
    public function a_transaction_outside_the_current_period_is_rejected(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 10]);
        $category = $this->makeSystemCategory();

        // Kỳ hiện tại của thẻ chốt hàng 10. Hai tháng trước nằm ở kỳ đã qua nên
        // bị chặn, dù "gần" về mặt lịch.
        $outside = CarbonImmutable::now()->subMonths(2)->startOfMonth();

        $this->actingAs($this->owner)->postJson(route('credit-cards.api.transactions.store'), [
            'transaction_date' => $outside->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '1200000',
        ])->assertStatus(422)->assertJsonValidationErrors('transaction_date');

        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function a_negative_amount_is_rejected_by_the_manual_form(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        // Số âm là hoàn tiền và thuộc luồng import, không phải form nhập tay.
        $this->actingAs($this->owner)->postJson(route('credit-cards.api.transactions.store'), [
            'transaction_date' => $this->dateInsideCurrentPeriod($card, 0)->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '-50000',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertSame(0, Transaction::query()->count());
    }

    /**
     * Ngày nằm trong kỳ sao kê hiện tại của thẻ, lệch `offset` ngày so với hôm nay.
     *
     * Tính bằng chính ranh giới mà `StatementPeriodService` dùng nên test không
     * phụ thuộc việc thẻ dùng `statement_day` nào.
     */
    private function dateInsideCurrentPeriod(UserCard $card, int $offset = 0): CarbonImmutable
    {
        [$start, $end] = app(StatementPeriodService::class)->currentBoundaries(
            $card,
            CarbonImmutable::now(),
        );

        $date = CarbonImmutable::now()->addDays($offset);

        // Kẹp vào trong khoảng hợp lệ nếu offset trượt ra ngoài.
        if ($date->lessThan($start)) {
            $date = $start;
        }

        if ($date->greaterThan($end)) {
            $date = $end;
        }

        return $date;
    }

    #[Test]
    public function a_new_transaction_moves_the_overview_numbers(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '5.000']]
        );

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.transactions.store'), [
                'transaction_date' => $this->dateInsideCurrentPeriod($card, 0)->toDateString(),
                'user_card_id' => $card->id,
                'category_id' => $category->id,
                'amount' => '1000000',
            ])
            ->assertCreated();

        $summary = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json('meta.summary');

        // Cashback do engine ghi, không phải test tự tính.
        $this->assertSame('1000000.00', $summary['total_spend']);
        $this->assertSame('50000.00', $summary['expected_cashback']);
    }

    #[Test]
    public function a_cashback_field_in_the_overview_form_payload_is_still_ignored(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '5.000']]
        );

        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.transactions.store'), [
            'transaction_date' => $this->dateInsideCurrentPeriod($card, 0)->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '1000000',
            'cashback_amount' => 999999999,
            'cashback_percent' => 99,
        ]);

        $response->assertCreated();
        $this->assertEquals(50000.0, $response->json('data.cashback_amount'));
    }

    #[Test]
    public function a_zero_amount_is_rejected(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.transactions.store'), [
                'user_card_id' => $card->id,
                'category_id' => $category->id,
                'amount' => '0',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function the_form_cannot_record_a_transaction_on_another_users_card(): void
    {
        $foreignCard = $this->makeUserCard($this->stranger->id);
        $category = $this->makeSystemCategory();

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.transactions.store'), [
                'user_card_id' => $foreignCard->id,
                'category_id' => $category->id,
                'amount' => '1000000',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_card_id');

        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function the_form_cannot_use_another_users_category(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $foreignCategory = $this->makeUserCategory($this->stranger->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.transactions.store'), [
                'user_card_id' => $card->id,
                'category_id' => $foreignCategory->id,
                'amount' => '1000000',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');

        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function the_overview_only_offers_the_users_own_categories(): void
    {
        $this->makeUserCard($this->owner->id, ['status' => UserCard::STATUS_ACTIVE]);
        $this->makeSystemCategory(['name' => 'Ăn uống hệ thống']);
        $this->makeUserCategory($this->owner->id, ['name' => 'Du lịch của tôi']);
        $this->makeUserCategory($this->stranger->id, ['name' => 'Sở thích của người khác']);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();
        $names = array_column($this->overviewState($html)['categories'], 'name');

        // Danh mục hệ thống + danh mục RIÊNG của user; không có của người khác.
        // `assertEqualsCanonicalizing` vì `sort()` so sánh theo byte nên tiếng Việt
        // có dấu không sort như mong đợi.
        $this->assertEqualsCanonicalizing(['Ăn uống hệ thống', 'Du lịch của tôi'], $names);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Bóc payload Alpine của trang Tổng quan ra mảng thật.
     *
     * `@js()` phát ra `JSON.parse('…')` với chuỗi đã escape HAI lớp (Laravel escape
     * `\` và `"` để an toàn trong string literal của JS), nên phải giải mã hai
     * lần: string literal → văn bản JSON → mảng. Assert thẳng lên `$html` sẽ
     * so sánh chuỗi đã escape (`Th\\u1ebb`) và luôn fail.
     *
     * @return array<string, mixed>
     */
    private function overviewState(string $html): array
    {
        $this->assertSame(
            1,
            preg_match("/x-data=\"creditCardOverview\(JSON\.parse\('(.*)'\)\)\"/", $html, $matches),
            'Không tìm thấy payload Alpine của trang Tổng quan.'
        );

        $json = json_decode('"'.$matches[1].'"', true);
        $this->assertIsString($json, 'Chuỗi JS của payload phải giải mã được.');

        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded, 'Payload Alpine phải decode được thành JSON hợp lệ.');

        return $decoded;
    }

    /**
     * Kỳ `open` chứa HÔM NAY — đúng định nghĩa "kỳ hiện tại" của module
     * (`StatementPeriod::contains()`).
     */
    private function makeCurrentPeriod(UserCard $card, array $attributes = []): StatementPeriod
    {
        $today = CarbonImmutable::now();

        return $this->makeStatementPeriod($card, array_merge([
            'period_start' => $today->subDays(10)->toDateString(),
            'period_end' => $today->addDays(10)->toDateString(),
            'statement_date' => $today->addDays(10)->toDateString(),
            'payment_due_date' => $today->addDays(20)->toDateString(),
            'status' => StatementPeriod::STATUS_OPEN,
        ], $attributes));
    }

    private function createTransaction(
        UserCard $card,
        $category,
        string $amount,
        int $periodId,
        string $date,
    ): Transaction {
        $transaction = app(CreditCardTransactionService::class)->create($card, [
            'transaction_date' => $date,
            'amount' => $amount,
            'category_id' => $category->id,
            'statement_period_id' => $periodId,
        ]);

        // `CreditCardTransactionService` tự resolve kỳ theo ngày giao dịch; test
        // này cố ý ép kỳ để kiểm tra đúng việc "chỉ tính kỳ hiện tại".
        $transaction->forceFill(['statement_period_id' => $periodId])->save();

        return $transaction->fresh();
    }
}
