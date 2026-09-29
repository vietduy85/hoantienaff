<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * §7, §8 — StatementPeriodService.
 *
 * Trọng tâm của file này là hai bảo đảm dễ vỡ nhất:
 *   1. `statement_day` 29/30/31 không tạo ra khoảng trống hoặc chồng lấn giữa
 *      các kỳ khi tháng không đủ ngày.
 *   2. `spending_deadline_day` KHÔNG BAO GIỜ quyết định kỳ sao kê.
 */
class StatementPeriodServiceTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private StatementPeriodService $service;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->service = app(StatementPeriodService::class);
    }

    // =====================================================================
    // Clamp statement_day
    // =====================================================================

    #[Test]
    #[DataProvider('clampCases')]
    public function it_clamps_the_statement_day_to_the_last_day_of_the_month(
        int $statementDay,
        int $year,
        int $month,
        int $expected
    ): void {
        $this->assertSame($expected, $this->service->clampDay($statementDay, $year, $month));
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    public static function clampCases(): array
    {
        return [
            'ngày thường giữ nguyên' => [15, 2026, 10, 15],
            'ngày 31 ở tháng 2 năm nhuận' => [31, 2024, 2, 29],
            'ngày 31 ở tháng 2 năm thường' => [31, 2026, 2, 28],
            'ngày 30 ở tháng 2' => [30, 2026, 2, 28],
            'ngày 29 ở tháng 2 thường' => [29, 2026, 2, 28],
            'ngày 30 ở tháng 4 (30 ngày)' => [30, 2026, 4, 30],
            'ngày 31 ở tháng 4 (30 ngày)' => [31, 2026, 4, 30],
            'ngày 0 bị đẩy lên 1' => [0, 2026, 10, 1],
            'ngày âm bị đẩy lên 1' => [-5, 2026, 10, 1],
            'ngày vượt 31 bị chặn' => [99, 2026, 10, 31],
        ];
    }

    // =====================================================================
    // Ranh giới kỳ: không trùng, không hở
    // =====================================================================

    #[Test]
    #[DataProvider('boundaryCases')]
    public function period_boundaries_are_contiguous_across_months(int $statementDay): void
    {
        $card = $this->makeUserCard($this->makeUser()->id, ['statement_day' => $statementDay]);

        $previous = null;

        // Duyệt 14 tháng liên tiếp bao gồm cả tháng 2 năm nhuận và năm thường.
        for ($step = 0; $step < 14; $step++) {
            $year = 2025 + intdiv($step, 12);
            $month = ($step % 12) + 1;

            [$start, $end] = $this->service->periodEndingAt($card, $year, $month);

            if ($previous !== null) {
                $this->assertSame(
                    $previous->addDay()->toDateString(),
                    $start->toDateString(),
                    "statement_day={$statementDay}: kỳ {$year}-{$month} phải bắt đầu ngay sau kỳ trước."
                );
            }

            $this->assertLessThanOrEqual(
                $end->toDateString(),
                $start->toDateString(),
                "statement_day={$statementDay}: kỳ {$year}-{$month} có start > end."
            );

            $previous = $end;
        }
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function boundaryCases(): array
    {
        return [
            'statement_day = 1' => [1],
            'statement_day = 15' => [15],
            'statement_day = 28' => [28],
            'statement_day = 29' => [29],
            'statement_day = 30' => [30],
            'statement_day = 31' => [31],
        ];
    }

    #[Test]
    public function every_single_day_belongs_to_exactly_one_period(): void
    {
        $card = $this->makeUserCard($this->makeUser()->id, ['statement_day' => 31]);

        // Dựng kỳ từ 2025-01 tới 2027-06 để bao trọn vùng 400 ngày sẽ quét.
        $periods = [];

        for ($step = 0; $step < 30; $step++) {
            $year = 2025 + intdiv($step, 12);
            $month = ($step % 12) + 1;

            [$start, $end] = $this->service->periodEndingAt($card, $year, $month);

            $periods[] = [$start, $end];
        }

        // Quét từng ngày trong 400 ngày liên tiếp và đếm số kỳ chứa nó.
        $day = CarbonImmutable::parse('2026-01-01');
        $checked = 0;

        while ($checked < 400) {
            $matches = 0;

            foreach ($periods as [$start, $end]) {
                if ($day->greaterThanOrEqualTo($start) && $day->lessThanOrEqualTo($end)) {
                    $matches++;
                }
            }

            $this->assertSame(
                1,
                $matches,
                'Ngày '.$day->toDateString().' thuộc '.$matches.' kỳ (phải đúng 1).'
            );

            $day = $day->addDay();
            $checked++;
        }
    }

    // =====================================================================
    // Resolve kỳ theo ngày
    // =====================================================================

    #[Test]
    public function it_assigns_a_date_to_the_period_ending_in_the_same_month(): void
    {
        // statement_day = 15 ⇒ kỳ [16/08 .. 15/09]
        $card = $this->makeUserCard($this->makeUser()->id, ['statement_day' => 15]);

        $period = $this->service->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10'));

        $this->assertSame('2026-08-16', $period->period_start->toDateString());
        $this->assertSame('2026-09-15', $period->period_end->toDateString());
    }

    #[Test]
    public function it_assigns_a_date_after_the_statement_day_to_the_next_period(): void
    {
        $card = $this->makeUserCard($this->makeUser()->id, ['statement_day' => 15]);

        $period = $this->service->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-16'));

        $this->assertSame('2026-09-16', $period->period_start->toDateString());
        $this->assertSame('2026-10-15', $period->period_end->toDateString());
    }

    #[Test]
    public function resolving_the_same_date_twice_reuses_the_same_period(): void
    {
        $card = $this->makeUserCard($this->makeUser()->id, ['statement_day' => 15]);

        $first = $this->service->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10'));
        $second = $this->service->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-12'));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $card->statementPeriods()->count());
    }

    #[Test]
    public function february_29_only_falls_into_the_leap_year_period(): void
    {
        $card = $this->makeUserCard($this->makeUser()->id, ['statement_day' => 31]);

        // Năm thường: 28/02 là ngày chốt kỳ (clamp 31 → 28)
        $normal = $this->service->resolvePeriodForDate($card, CarbonImmutable::parse('2026-02-28'));
        $this->assertSame('2026-02-28', $normal->period_end->toDateString());

        // Năm nhuận: 29/02 là ngày chốt kỳ
        $leap = $this->service->resolvePeriodForDate($card, CarbonImmutable::parse('2024-02-29'));
        $this->assertSame('2024-02-29', $leap->period_end->toDateString());
    }

    // =====================================================================
    // payment_due_date
    // =====================================================================

    #[Test]
    public function payment_due_date_lands_in_the_month_after_the_statement_date(): void
    {
        $card = $this->makeUserCard($this->makeUser()->id, [
            'statement_day' => 15,
            'payment_due_day' => 25,
        ]);

        $period = $this->service->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10'));

        $this->assertSame('2026-10-25', $period->payment_due_date->toDateString());
    }

    #[Test]
    public function payment_due_date_is_clamped_when_the_next_month_is_short(): void
    {
        // Kỳ chốt 31/01 ⇒ payment_due_day = 31 rơi vào tháng 2 (28 ngày)
        $card = $this->makeUserCard($this->makeUser()->id, [
            'statement_day' => 31,
            'payment_due_day' => 31,
        ]);

        $period = $this->service->resolvePeriodForDate($card, CarbonImmutable::parse('2026-01-31'));

        $this->assertSame('2026-01-31', $period->period_end->toDateString());
        $this->assertSame('2026-02-28', $period->payment_due_date->toDateString());
    }

    // =====================================================================
    // §8 — spending_deadline_day KHÔNG quyết định kỳ
    // =====================================================================

    #[Test]
    public function spending_deadline_day_never_changes_which_period_a_date_belongs_to(): void
    {
        $user = $this->makeUser();

        $withoutDeadline = $this->makeUserCard($user->id, [
            'statement_day' => 15,
            'spending_deadline_day' => null,
        ]);

        $withEarlyDeadline = $this->makeUserCard($user->id, [
            'statement_day' => 15,
            'spending_deadline_day' => 5,
        ]);

        $withLateDeadline = $this->makeUserCard($user->id, [
            'statement_day' => 15,
            'spending_deadline_day' => 28,
        ]);

        foreach (['2026-09-01', '2026-09-10', '2026-09-15', '2026-09-16', '2026-09-30'] as $date) {
            $expected = $this->service->resolvePeriodForDate($withoutDeadline, CarbonImmutable::parse($date));

            foreach ([$withEarlyDeadline, $withLateDeadline] as $card) {
                $actual = $this->service->resolvePeriodForDate($card, CarbonImmutable::parse($date));

                $this->assertSame(
                    $expected->period_start->toDateString(),
                    $actual->period_start->toDateString(),
                    "Ngày {$date} bị đổi kỳ vì spending_deadline_day."
                );
                $this->assertSame(
                    $expected->period_end->toDateString(),
                    $actual->period_end->toDateString(),
                    "Ngày {$date} bị đổi kỳ vì spending_deadline_day."
                );
            }
        }
    }

    #[Test]
    public function spending_deadline_is_only_a_reminder_and_returns_null_when_not_configured(): void
    {
        $user = $this->makeUser();

        $withoutDeadline = $this->makeUserCard($user->id, ['spending_deadline_day' => null]);
        $periodEnd = CarbonImmutable::parse('2026-09-15');

        $this->assertNull($withoutDeadline->spendingDeadlineFor($periodEnd));
        $this->assertNull(
            $withoutDeadline->spendingDeadlineWarning(CarbonImmutable::parse('2026-09-01'), $periodEnd)
        );

        $withDeadline = $this->makeUserCard($user->id, ['spending_deadline_day' => 20]);

        $this->assertSame('2026-09-20', $withDeadline->spendingDeadlineFor($periodEnd)->toDateString());
        $this->assertNotNull(
            $withDeadline->spendingDeadlineWarning(CarbonImmutable::parse('2026-09-10'), $periodEnd)
        );
    }

    #[Test]
    public function spending_deadline_warning_flips_to_past_tense_after_the_deadline(): void
    {
        $card = $this->makeUserCard($this->makeUser()->id, ['spending_deadline_day' => 20]);
        $periodEnd = CarbonImmutable::parse('2026-09-15');

        $this->assertStringContainsString(
            'còn',
            $card->spendingDeadlineWarning(CarbonImmutable::parse('2026-09-10'), $periodEnd)
        );

        $this->assertStringContainsString(
            'nên chi tiêu trước',
            $card->spendingDeadlineWarning(CarbonImmutable::parse('2026-09-25'), $periodEnd)
        );
    }

    // =====================================================================
    // Chỉnh tay kỳ
    // =====================================================================

    #[Test]
    public function a_transaction_can_be_moved_to_another_open_period(): void
    {
        $user = $this->makeUser();
        $card = $this->makeUserCard($user->id, ['statement_day' => 15]);

        $first = $this->service->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10'));
        $second = $this->service->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-20'));

        $transaction = $this->makeTransaction($card, $first, '2026-09-10');

        $this->service->assignTransactionToPeriod($card, $transaction, $second);

        $this->assertSame($second->id, $transaction->refresh()->statement_period_id);
    }

    #[Test]
    public function a_transaction_cannot_be_moved_into_a_finalized_period(): void
    {
        $card = $this->makeUserCard($this->makeUser()->id, ['statement_day' => 15]);

        $open = $this->service->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10'));
        $finalized = $this->service->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-20'));

        $finalized->forceFill([
            'status' => \App\Models\CreditCard\StatementPeriod::STATUS_FINALIZED,
            'finalized_at' => now(),
        ])->save();

        $transaction = $this->makeTransaction($card, $open, '2026-09-10');

        $this->expectException(\LogicException::class);

        $this->service->assignTransactionToPeriod($card, $transaction, $finalized);
    }

    #[Test]
    public function a_transaction_cannot_be_moved_into_another_cards_period(): void
    {
        $user = $this->makeUser();
        $cardA = $this->makeUserCard($user->id, ['statement_day' => 15]);
        $cardB = $this->makeUserCard($user->id, ['statement_day' => 20]);

        $periodA = $this->service->resolvePeriodForDate($cardA, CarbonImmutable::parse('2026-09-10'));
        $periodB = $this->service->resolvePeriodForDate($cardB, CarbonImmutable::parse('2026-09-25'));

        $transaction = $this->makeTransaction($cardA, $periodA, '2026-09-10');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->assignTransactionToPeriod($cardA, $transaction, $periodB);
    }

    /**
     * REGRESSION: không được gắn giao dịch của thẻ B vào kỳ của thẻ A.
     *
     * Trước khi sửa, hàm chỉ kiểm tra `period.user_card_id` nên một giao dịch
     * thuộc thẻ khác vẫn gắn được ⇒ làm sai tổng kỳ của thẻ A.
     */
    #[Test]
    public function a_transaction_from_another_card_cannot_be_moved_into_this_cards_period(): void
    {
        $user = $this->makeUser();
        $cardA = $this->makeUserCard($user->id, ['statement_day' => 15]);
        $cardB = $this->makeUserCard($user->id, ['statement_day' => 15]);

        $periodA = $this->service->resolvePeriodForDate($cardA, CarbonImmutable::parse('2026-09-10'));
        $periodB = $this->service->resolvePeriodForDate($cardB, CarbonImmutable::parse('2026-09-10'));

        $transactionOfB = $this->makeTransaction($cardB, $periodB, '2026-09-10');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->assignTransactionToPeriod($cardA, $transactionOfB, $periodA);
    }

    // =====================================================================
    // 8 - basis posted_date
    // =====================================================================

    #[Test]
    public function posted_date_basis_places_the_transaction_in_the_posted_period(): void
    {
        $card = $this->makeUserCard($this->makeUser()->id, [
            'statement_day' => 15,
            'statement_date_basis' => UserCard::BASIS_POSTED_DATE,
        ]);

        // Mua 14/09 (rơi vào kỳ 16/08-15/09) nhưng ngân hàng ghi nhận 20/09
        // (rơi vào kỳ 16/09-15/10) ⇒ kỳ theo posted_date là kỳ thứ hai.
        $period = $this->service->resolveForTransaction(
            $card,
            $this->makeTransaction($card, null, '2026-09-14', ['posted_date' => '2026-09-20'])
        );

        $this->assertSame('2026-09-16', $period->period_start->toDateString());
    }

    #[Test]
    public function posted_date_basis_falls_back_to_transaction_date_when_not_posted_yet(): void
    {
        $card = $this->makeUserCard($this->makeUser()->id, [
            'statement_day' => 15,
            'statement_date_basis' => UserCard::BASIS_POSTED_DATE,
        ]);

        // Chưa có posted_date ⇒ suy luận theo transaction_date.
        $period = $this->service->resolveForTransaction(
            $card,
            $this->makeTransaction($card, null, '2026-09-10')
        );

        $this->assertSame('2026-08-16', $period->period_start->toDateString());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeTransaction(
        UserCard $card,
        ?\App\Models\CreditCard\StatementPeriod $period,
        string $date,
        array $attributes = []
    ): \App\Models\CreditCard\Transaction {
        return \App\Models\CreditCard\Transaction::create(array_merge([
            'user_card_id' => $card->id,
            'statement_period_id' => $period?->id,
            'transaction_date' => $date,
            'amount' => 100000,
            'source' => 'manual',
        ], $attributes));
    }
}
