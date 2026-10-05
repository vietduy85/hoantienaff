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
 *   - clamp anchor về ngày hợp lệ trong tháng (29/30/31)
 *   - suy ra [period_start, period_end] từ một ngày bất kỳ
 *   - tìm hoặc tạo StatementPeriod
 *   - gắn / gỡ giao dịch khỏi kỳ (hỗ trợ chỉnh tay khi sao kê thực tế khác)
 *
 * TUYỆT ĐỐI KHÔNG dùng `spending_deadline_day` để quyết định kỳ.
 * `spending_deadline_day` chỉ là metadata nhắc nhở cho user — xem
 * `UserCard::spendingDeadlineWarning()`.
 *
 * ANCHOR — ngày MỞ kỳ (không phải ngày chốt)
 * --------------------------------------------------
 * Mỗi thẻ có một "anchor day" là ngày MỞ chu kỳ sao kê:
 *   1. `statement_period_start` — ngày user nhập ở form ("Ngày bắt đầu").
 *      Đây là nguồn chuẩn; `statement_period_end` do server tính từ nó.
 *   2. fallback `statement_day` khi thẻ chưa có `statement_period_start`.
 *
 * Với anchor A, kỳ MỞ tại tháng M là:
 *   period_start(M) = clamp(A, M)
 *   period_end(M)   = clamp(A, M+1) − 1 ngày
 *
 * Do `period_start(M+1) = clamp(A, M+1) = period_end(M) + 1` nên các kỳ luôn
 * liền nhau: không trùng, không hở — kể cả A = 29/30/31 và tháng không đủ ngày.
 *
 * Ví dụ A = 7 (chu kỳ 07 → 06):
 *   mở 07/08 → 06/09 | mở 07/09 → 06/10 | mở 07/10 → 06/11
 *
 * LƯU Ý LỊCH SỬ: trước đây `statement_day` được hiểu là ngày CUỐI kỳ
 * (`period = [clamp(S, M−1)+1, clamp(S, M)]`) và `StatementPeriodService` chỉ
 * đọc cột đó. Form thì luôn ghi `statement_period_start/end`, nên cột đó bị
 * bỏ không ⇒ mọi thẻ rơi về `statement_day` mặc định (1) và dùng chung một kỳ
 * `02/10 → 01/11`. Bản sửa này dùng anchor, khớp đúng công thức mà form đã áp.
 */
class StatementPeriodService
{
    public const MIN_DAY = 1;

    public const MAX_DAY = 31;

    /**
     * Số kỳ (kể cả kỳ hiện tại) mà dropdown Sao kê được phép liệt kê.
     *
     * Giới hạn cứng để dropdown không dài vô hạn theo thời gian: mỗi kỳ là một
     * chu kỳ sao kê, người dùng thực tế chỉ cần vài kỳ gần nhất để nhập bảng kê
     * chậm. Không có giới hạn này, mỗi lần mở trang sau vài năm sẽ phải dựng
     * hàng trăm `<option>`.
     */
    public const SELECTABLE_LIMIT = 12;

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
     * Ngày MỞ chu kỳ (day-of-month).
     *
     * Nguồn chuẩn là `statement_period_start` — cùng ngày user nhập ở form
     * "Kỳ sao kê → Ngày bắt đầu", và cùng ngày mà `UserCardService` dùng để
     * tính `statement_period_end`. `statement_day` chỉ là fallback cho thẻ
     * chưa cấu hình.
     */
    public function anchorDay(UserCard $userCard): int
    {
        $start = $userCard->statement_period_start;

        return (int) ($start !== null ? $start->day : $userCard->statement_day);
    }

    /**
     * Ngày mở chu kỳ tại tháng (year, month).
     */
    public function cycleDateFor(UserCard $userCard, int $year, int $month): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, 1)
            ->day($this->clampDay($this->anchorDay($userCard), $year, $month))
            ->startOfDay();
    }

    /**
     * Ranh giới của kỳ MỞ tại tháng (year, month).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} [start, end]
     */
    public function periodStartingAt(UserCard $userCard, int $year, int $month): array
    {
        $start = $this->cycleDateFor($userCard, $year, $month);
        $nextStart = $this->cycleDateFor($userCard, ...$this->nextMonth($year, $month));

        return [$start, $nextStart->subDay()];
    }

    /**
     * Kỳ chứa ngày `$date`, tạo mới nếu chưa có.
     *
     * Chỉ dùng anchor. `spending_deadline_day` không tham gia.
     */
    public function resolvePeriodForDate(UserCard $userCard, CarbonInterface $date): StatementPeriod
    {
        [$year, $month] = $this->startMonthFor($userCard, $date);

        return $this->findOrCreate($userCard, $year, $month);
    }

    /**
     * Tháng MỞ kỳ của kỳ chứa ngày `$date`.
     *
     * Ngày >= anchor ⇒ kỳ đó mở ngay trong tháng này. Ngày < anchor ⇒ kỳ đã mở
     * từ tháng trước. Chỉ dùng anchor; `spending_deadline_day` không tham gia.
     *
     * @return array{0: int, 1: int}
     */
    private function startMonthFor(UserCard $userCard, CarbonInterface $date): array
    {
        $anchor = $this->clampDay($this->anchorDay($userCard), $date->year, $date->month);

        return $date->day >= $anchor
            ? [$date->year, $date->month]
            : $this->previousMonth($date->year, $date->month);
    }

    /**
     * Tìm kỳ theo ranh giới, tạo mới nếu chưa có.
     *
     * `$year`/`$month` là tháng MỞ kỳ.
     */
    public function findOrCreate(UserCard $userCard, int $year, int $month): StatementPeriod
    {
        [$start, $end] = $this->periodStartingAt($userCard, $year, $month);

        return $this->findByBoundaries($userCard, $start, $end)
            ?? $this->create($userCard, $start, $end);
    }

    /**
     * Tìm kỳ theo ranh giới ĐÃ BIẾT, không tạo mới.
     *
     * Tách riêng khỏi `findOrCreate()` để các đường chỉ đọc không phải lặp lại
     * câu truy vấn, và để "tìm" với "tạo" là hai ý định khác nhau khi đọc code.
     */
    public function findByBoundaries(UserCard $userCard, CarbonInterface $start, CarbonInterface $end): ?StatementPeriod
    {
        return StatementPeriod::query()
            ->where('user_card_id', $userCard->id)
            ->whereDate('period_start', $start->toDateString())
            ->whereDate('period_end', $end->toDateString())
            ->first();
    }

    /**
     * Ranh giới kỳ MỞ tại tháng MỞ kỳ của `period_start`.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO THÁNG MỞ KỲ LẤY THẲNG TỪ `period_start`
     * ---------------------------------------------------------------------------
     * `cycleDateFor()` luôn trả về một ngày NẰM TRONG CHÍNH tháng truyền vào
     * (ngày 1 của tháng, rồi đặt `day = clamp(anchor)`), nên `period_start`
     * thuộc đúng tháng mở kỳ của nó. Không cần dò ngược để tìm tháng mở kỳ, và
     * cũng không có chuyện "tháng trước/tháng sau" phụ thuộc dữ liệu.
     *
     * Hàm này là cổng cho MỌI `period_start` do người dùng gửi lên: một ngày
     * bất kỳ trong tháng đó đều dẫn tới một kỳ hợp lệ, nên việc kiểm tra thật sự
     * nằm ở chỗ ngày gửi lên có trùng `period_start` mà service suy ra hay không
     * (`resolvePeriodStart()`).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} [start, end]
     */
    public function boundariesForPeriodStart(UserCard $userCard, CarbonInterface $periodStart): array
    {
        return $this->periodStartingAt($userCard, $periodStart->year, $periodStart->month);
    }

    /**
     * Kỳ ứng với `period_start` người dùng gửi lên — TẠO nếu chưa có.
     *
     * Đây là đường GHI: chỉ dùng khi thực sự lưu sao kê. `period_start` sai
     * (không phải ngày mở kỳ hợp lệ của thẻ) bị từ chối thay vì âm thầm ghi vào
     * một kỳ khác — nếu không, người dùng chọn nhầm tháng mà không hề hay biết.
     *
     * @throws \InvalidArgumentException khi `period_start` không khớp kỳ suy ra
     */
    public function resolvePeriodStart(UserCard $userCard, CarbonInterface $periodStart): StatementPeriod
    {
        [$start, $end] = $this->boundariesForPeriodStart($userCard, $periodStart);

        if ($start->toDateString() !== $periodStart->toDateString()) {
            throw new \InvalidArgumentException('Kỳ sao kê không hợp lệ với chu kỳ của thẻ này.');
        }

        return $this->findByBoundaries($userCard, $start, $end)
            ?? $this->create($userCard, $start, $end);
    }

    /**
     * Kỳ ĐÃ KẾT THÚC gần nhất — kỳ có `period_end` < hôm nay, mới nhất trở đi.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO LÙI MỘT KỲ TỪ KỲ HIỆN TẠI
     * ---------------------------------------------------------------------------
     * Kỳ hiện tại luôn `period_end >= hôm nay` (theo định nghĩa kỳ chứa ngày
     * hôm nay), nên kỳ kết thúc gần nhất LUÔN là kỳ liền trước nó. Nhờ vậy
     * hàm luôn trả về một kỳ — không bao giờ null — và không cần quét DB.
     *
     * Chỉ tính toán, không tạo bản ghi: dùng cho dropdown và cho Tổng quan.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} [start, end]
     */
    public function completedBoundaries(UserCard $userCard, ?CarbonInterface $today = null): array
    {
        [$start] = $this->currentBoundaries($userCard, $today);

        [$year, $month] = $this->previousMonth($start->year, $start->month);

        return $this->periodStartingAt($userCard, $year, $month);
    }

    /**
     * Danh sách kỳ để hiển thị, MỚI NHẤT TRƯỚC.
     *
     * Lùi từ kỳ hiện tại về tối đa `$limit` kỳ bằng chính công thức tháng MỞ kỳ,
     * nên không phụ thuộc bảng `statement_periods`: thẻ chưa có bản ghi kỳ nào
     * vẫn ra đủ danh sách, và mở trang KHÔNG sinh bản ghi.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> [ [start, end], ... ]
     */
    public function selectableBoundaries(
        UserCard $userCard,
        ?CarbonInterface $today = null,
        int $limit = self::SELECTABLE_LIMIT,
    ): array {
        [$start] = $this->currentBoundaries($userCard, $today);

        $limit = max(1, $limit);
        $list = [];

        [$year, $month] = [$start->year, $start->month];

        for ($i = 0; $i < $limit; $i++) {
            $list[] = $this->periodStartingAt($userCard, $year, $month);
            [$year, $month] = $this->previousMonth($year, $month);
        }

        return $list;
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
        [$year, $month] = $this->startMonthFor($userCard, $date);

        return $this->periodStartingAt($userCard, $year, $month);
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
     * ngờ. Ranh giới suy ra từ anchor nên thẻ chưa có bản ghi kỳ nào vẫn
     * trả lời được, và hàm này KHÔNG tạo kỳ.
     *
     * Hai ngày cùng kỳ ⇔ cùng tháng mở kỳ ⇔ `boundariesForDate()` trùng nhau.
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

        return $this->findByBoundaries($userCard, $start, $end);
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
     * Kỳ đang mở gần nhất (kỳ mà giao dịch mới sẽ rơi vào theo anchor).
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
