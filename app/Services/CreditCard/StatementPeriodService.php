<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * StatementPeriodService — xác định và quản lý kỳ sao kê.
 *
 * Trách nhiệm DUY NHẤT:
 *   - clamp `statement_day` về ngày cuối tháng (29/30/31)
 *   - suy ra [period_start, period_end] từ một ngày bất kỳ
 *   - tìm hoặc tạo StatementPeriod
 *   - gắn / gỡ giao dịch khỏi kỳ (hỗ trợ chỉnh tay khi sao kê thực tế khác)
 *
 * TUYỆT ĐỐI KHÔNG dùng `spending_deadline_day` để quyết định kỳ.
 * `spending_deadline_day` chỉ là metadata nhắc nhở cho user — xem
 * `UserCard::spendingDeadlineWarning()`.
 *
 * Quy tắc ranh giới (statement_day = S):
 *   kỳ kết thúc tại tháng M  →  [ clamp(S, M-1) + 1 ngày , clamp(S, M) ]
 * Nhờ đó period_start(M) = period_end(M-1) + 1 ngày ⇒ không trùng, không hở,
 * kể cả khi S = 29/30/31 và tháng không đủ ngày.
 */
class StatementPeriodService
{
    public const MIN_DAY = 1;

    public const MAX_DAY = 31;

    /**
     * Clamp ngày trong tháng về ngày hợp lệ.
     *
     * statement_day = 31 ở tháng 2 (28 ngày) ⇒ 28/02.
     * KHÔNG roll-over sang tháng sau (31/02 không tồn tại).
     */
    public function clampDay(int $statementDay, int $year, int $month): int
    {
        $day = max(self::MIN_DAY, min($statementDay, self::MAX_DAY));
        $daysInMonth = CarbonImmutable::create($year, $month, 1)->daysInMonth;

        return min($day, $daysInMonth);
    }

    /**
     * Ngày chốt kỳ của tháng (year, month).
     */
    public function statementDateFor(UserCard $userCard, int $year, int $month): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, 1)
            ->day($this->clampDay((int) $userCard->statement_day, $year, $month))
            ->startOfDay();
    }

    /**
     * Ranh giới kỳ kết thúc tại tháng (year, month).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} [start, end]
     */
    public function periodEndingAt(UserCard $userCard, int $year, int $month): array
    {
        $end = $this->statementDateFor($userCard, $year, $month);

        $previousEnd = $this->statementDateFor($userCard, ...$this->previousMonth($year, $month));

        return [$previousEnd->addDay(), $end];
    }

    /**
     * Tháng kết thúc của kỳ chứa ngày `$date`.
     *
     * Dùng duy nhất `statement_day`. `spending_deadline_day` không tham gia.
     */
    public function resolvePeriodForDate(UserCard $userCard, CarbonInterface $date): StatementPeriod
    {
        [$year, $month] = $this->endMonthFor($userCard, $date);

        return $this->findOrCreate($userCard, $year, $month);
    }

    /**
     * Tháng chốt kỳ chứa `$date`.
     *
     * Ngày nằm trong tháng chốt kỳ ⇒ kỳ đó kết thúc ngay trong tháng này.
     * Dùng duy nhất `statement_day`; `spending_deadline_day` không tham gia.
     *
     * @return array{0: int, 1: int}
     */
    private function endMonthFor(UserCard $userCard, CarbonInterface $date): array
    {
        $statementDayOfMonth = $this->clampDay((int) $userCard->statement_day, $date->year, $date->month);

        return $date->day <= $statementDayOfMonth
            ? [$date->year, $date->month]
            : $this->nextMonth($date->year, $date->month);
    }

    /**
     * Tìm kỳ theo ranh giới, tạo mới nếu chưa có.
     */
    public function findOrCreate(UserCard $userCard, int $year, int $month): StatementPeriod
    {
        [$start, $end] = $this->periodEndingAt($userCard, $year, $month);

        $period = StatementPeriod::query()
            ->where('user_card_id', $userCard->id)
            ->whereDate('period_start', $start->toDateString())
            ->whereDate('period_end', $end->toDateString())
            ->first();

        if ($period !== null) {
            return $period;
        }

        return $this->create($userCard, $start, $end);
    }

    /**
     * Tạo mới một kỳ. Dùng transaction DB vì có race khi 2 giao dịch đến cùng lúc.
     */
    public function create(UserCard $userCard, CarbonInterface $start, CarbonInterface $end): StatementPeriod
    {
        return DB::connection('creditcard')->transaction(function () use ($userCard, $start, $end): StatementPeriod {
            $paymentDueDate = $this->paymentDueDateFor($userCard, $end);

            return StatementPeriod::create([
                'user_card_id' => $userCard->id,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'statement_date' => $end->toDateString(),
                'payment_due_date' => $paymentDueDate?->toDateString(),
                'status' => StatementPeriod::STATUS_OPEN,
            ]);
        });
    }

    /**
     * Ranh giới [period_start, period_end] của kỳ chứa `$date`.
     *
     * Chỉ TÍNH TOÁN, không truy vấn và không tạo bản ghi. Dùng cho UI chỉ đọc
     * (ví dụ tính ngày nhắc chi tiêu) khi kỳ có thể chưa tồn tại trong DB.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function boundariesForDate(UserCard $userCard, CarbonInterface $date): array
    {
        [$year, $month] = $this->endMonthFor($userCard, $date);

        return $this->periodEndingAt($userCard, $year, $month);
    }

    /**
     * Ranh giới kỳ SAO KẾ HIỆN TẠI — chỉ tính toán, không tạo bản ghi.
     *
     * `currentPeriod()` (trên) trả về MODEL và TẠO kỳ nếu chưa có — dùng khi
     * thực sự cần ghi. Bản này dành cho mọi chỗ chỉ cần biết "kỳ này chạy từ
     * ngày nào đến ngày nào" mà không được phép sinh bản ghi.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function currentBoundaries(UserCard $userCard, ?CarbonInterface $today = null): array
    {
        $today = $today ? CarbonImmutable::instance($today) : CarbonImmutable::now();

        return $this->boundariesForDate($userCard, $today);
    }

    /**
     * `$date` có nằm trong kỳ sao kê HIỆN TẠI của thẻ không.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO SO SÁH THEO KỲ, KHÔNG SO SÁH THEO NGÀY
     * ---------------------------------------------------------------------------
     * Giao dịch nhập tay chỉ được ghi vào kỳ đang mở: nếu cho nhận ngày ngoài kỳ,
     * giao dịch rơi sang kỳ khác và "Tổng quan" (chỉ đọc kỳ hiện tại) nhảy số bất
     * ngờ. Ranh giới suy ra từ `statement_day` nên thẻ chưa có bản ghi kỳ nào vẫn
     * trả lời được, và hàm này KHÔNG tạo kỳ.
     *
     * Hai ngày cùng kỳ ⇔ cùng tháng chốt ⇔ `boundariesForDate()` trùng nhau.
     * So sánh chuỗi ngày chứ không so sánh object để không phụ thuộc timezone.
     */
    public function isInCurrentPeriod(UserCard $userCard, CarbonInterface $date, ?CarbonInterface $today = null): bool
    {
        [$start, $end] = $this->currentBoundaries($userCard, $today);
        [$dateStart, $dateEnd] = $this->boundariesForDate($userCard, $date);

        return $dateStart->toDateString() === $start->toDateString()
            && $dateEnd->toDateString() === $end->toDateString();
    }

    /**
     * Tìm kỳ chứa ngày mà KHÔNG TẠO mới.
     *
     * Dùng cho các trang chỉ ĐỌC (trang tổng quan): không được phép sinh bản ghi
     * khi user chỉ mở trang. Muốn tạo kỳ thì gọi `resolvePeriodForDate()`.
     */
    public function findForDate(UserCard $userCard, CarbonInterface $date): ?StatementPeriod
    {
        [$start, $end] = $this->boundariesForDate($userCard, $date);

        return StatementPeriod::query()
            ->where('user_card_id', $userCard->id)
            ->whereDate('period_start', $start->toDateString())
            ->whereDate('period_end', $end->toDateString())
            ->first();
    }

    /**
     * Ngày đến hạn thanh toán, tính từ ngày chốt kỳ sang tháng kế tiếp.
     */
    public function paymentDueDateFor(UserCard $userCard, CarbonInterface $statementDate): ?CarbonImmutable
    {
        $day = $userCard->payment_due_day;

        if ($day === null) {
            return null;
        }

        [$year, $month] = $this->nextMonth($statementDate->year, $statementDate->month);

        return CarbonImmutable::create($year, $month, 1)
            ->day($this->clampDay((int) $day, $year, $month))
            ->startOfDay();
    }

    /**
     * Kỳ chứa giao dịch theo `statement_date_basis` của thẻ.
     *
     * Nếu basis = posted_date mà giao dịch chưa có posted_date ⇒ rơi về
     * transaction_date. Đây là suy luận, không phải dữ liệu ngân hàng: user có
     * thể chỉnh lại bằng `assignTransactionToPeriod()` khi kỳ chưa finalize.
     */
    public function resolveForTransaction(UserCard $userCard, Transaction $transaction): StatementPeriod
    {
        return $this->resolvePeriodForDate($userCard, $transaction->basisDate((string) $userCard->statement_date_basis));
    }

    /**
     * Workflow chỉnh tay: gắn giao dịch vào một kỳ khác (khi sao kê thực tế của
     * ngân hàng khác suy luận tự động).
     *
     * Kỳ đã finalize là bản ghi lịch sử ⇒ không cho chỉnh.
     */
    public function assignTransactionToPeriod(UserCard $userCard, Transaction $transaction, StatementPeriod $period): void
    {
        if ((int) $period->user_card_id !== (int) $userCard->id) {
            throw new \InvalidArgumentException('Kỳ sao kê không thuộc thẻ này.');
        }

        // Chặn gắn giao dịch của thẻ khác: sẽ làm hỏng cả kỳ của thẻ này và
        // ghi cashback sai vào snapshot của thẻ khác.
        if ((int) $transaction->user_card_id !== (int) $userCard->id) {
            throw new \InvalidArgumentException('Giao dịch không thuộc thẻ này.');
        }

        if ($period->isFinalized()) {
            throw new \LogicException('Không thể chỉnh kỳ đã chốt.');
        }

        $transaction->forceFill(['statement_period_id' => $period->id])->save();
    }

    /**
     * Kỳ đang mở gần nhất (kỳ mà giao dịch mới sẽ rơi vào theo statement_day).
     */
    public function currentPeriod(UserCard $userCard, ?CarbonInterface $today = null): StatementPeriod
    {
        $today = $today ? CarbonImmutable::instance($today) : CarbonImmutable::now();

        return $this->resolvePeriodForDate($userCard, $today);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function previousMonth(int $year, int $month): array
    {
        return $month === 1 ? [$year - 1, 12] : [$year, $month - 1];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function nextMonth(int $year, int $month): array
    {
        return $month === 12 ? [$year + 1, 1] : [$year, $month + 1];
    }
}
