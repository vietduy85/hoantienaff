<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\CumulativeSpendUpdate;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Support\CreditCard\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CreditCardCumulativeSpendService — MỐC CHI TIÊU LŨY KẾ + tiến độ tới ngưỡng miễn
 * phí thường niên của một thẻ.
 *
 * ---------------------------------------------------------------------------
 * BÀI TOÁN
 * ---------------------------------------------------------------------------
 * Điều kiện miễn phí thường niên của ngân hàng thường tính theo TỔNG chi tiêu
 * trong NĂM, không phải theo kỳ sao kê. Module chỉ biết chắc chi tiêu trong KỲ
 * HIỆN TẠI (SUM giao dịch gắn `statement_period_id` của kỳ đó). Vì vậy user tự
 * khai MỐC LŨY KẾ KỲ TRƯỚC (`cumulative_spend_baseline`), và hệ thống cộng thêm
 * chi tiêu kỳ hiện tại để ra tiến độ.
 *
 * ---------------------------------------------------------------------------
 * GIỚI HẠN — KHÔNG KHẲNG ĐỊNH "ĐỦ ĐIỀU KIỆN MIỄN PHÍ"
 * ---------------------------------------------------------------------------
 * Con số ở đây là TIẾN ĐỘ theo dữ liệu user tự khai, KHÔNG phải phán quyết của
 * ngân hàng: chi tiêu "đủ điều kiện" còn tuỳ loại trừ MCC/giao dịch, kỳ đối chiếu
 * và quy định từng sản phẩm. Service chỉ trả `reached`/`remaining` như một chỉ
 * báo trung tính; tầng hiển thị phải nói rõ điều này, không tự kết luận thay ngân
 * hàng.
 *
 * ---------------------------------------------------------------------------
 * CHỈ ĐỌC KỲ, KHÔNG TẠO
 * ---------------------------------------------------------------------------
 * Chi tiêu kỳ hiện tại lấy qua `StatementPeriodService::findForDate()` — mở trang
 * KHÔNG được sinh bản ghi kỳ. Kỳ chưa có bản ghi ⇒ chi tiêu kỳ này = 0.
 *
 * ---------------------------------------------------------------------------
 * NGÀY MỐC DO HỆ THỐNG XÁC ĐỊNH, KHÔNG DO NGƯỜI DÙNG CHỌN
 * ---------------------------------------------------------------------------
 * Mốc lũy kế gắn với "hết kỳ trước". Ngày của mốc KHÔNG nhận từ input: nó lấy
 * từ ngày chốt sao kê (kỳ trước) của CHÍNH thẻ, suy bằng
 * `StatementPeriodService::completedBoundaries()` — cùng công thức với kỳ hiện
 * tại nên luôn khớp. Nhờ vậy mốc và ngày luôn đi cặp; khi kỳ chuyển sang kỳ mới,
 * mốc cũ KHÔNG bị cộng trùng cũng không bị mất (ngày đã lưu giữ nguyên ý nghĩa).
 *
 * Mọi phép tính tiền đi qua `Decimal` (bcmath), không qua `float`.
 */
class CreditCardCumulativeSpendService
{
    public function __construct(
        private readonly StatementPeriodService $periods,
    ) {
    }

    /**
     * Một hàng dữ liệu của tab "Phí thường niên và chi tiêu tích lũy".
     *
     * Trả số thô (chuỗi `decimal` 2 chữ số) + ngày ISO; tầng view/JS lo định dạng
     * tiền. KHÔNG trả tổng "đủ điều kiện miễn phí" — chỉ là tiến độ theo dữ liệu
     * user khai.
     *
     * @return array<string, mixed>
     */
    public function row(UserCard $card, CarbonImmutable $today): array
    {
        [$start, $end] = $this->periods->currentBoundaries($card, $today);
        [$previousStart, $previousEnd] = $this->periods->completedBoundaries($card, $today);

        $spend = $this->currentPeriodSpend($card, $today);

        // Cửa sổ phí thường niên KẾ TIẾP: ngày (ISO) + số ngày còn lại theo NGÀY
        // LỊCH ở múi giờ Việt Nam (`config('app.timezone')`). Chỉ tính theo ngày,
        // bỏ giờ/phút; ngày đã qua ⇒ số ngày là null (không bao giờ âm).
        $nextFeeDate = $card->next_annual_fee_date;
        $nextFeeIso = $nextFeeDate?->toDateString();
        $vnToday = $today->setTimezone(config('app.timezone'))->startOfDay();

        if ($nextFeeDate === null) {
            $feeStatus = 'unknown';
            $feeDays = null;
        } else {
            $feeDay = CarbonImmutable::parse($nextFeeIso, config('app.timezone'))->startOfDay();

            if ($feeDay->equalTo($vnToday)) {
                $feeStatus = 'today';
                $feeDays = 0;
            } elseif ($feeDay->lessThan($vnToday)) {
                $feeStatus = 'past';
                $feeDays = null;
            } else {
                $feeStatus = 'upcoming';
                $feeDays = (int) $vnToday->diffInDays($feeDay);
            }
        }

        $baseline = $card->cumulative_spend_baseline === null
            ? null
            : Decimal::money($card->cumulative_spend_baseline);

        $threshold = $card->annual_fee_waiver_spend === null
            ? null
            : Decimal::money($card->annual_fee_waiver_spend);

        $cumulative = $baseline === null ? null : Decimal::add($baseline, $spend);

        // Ngày đã lưu của mốc (nếu có). Đây là "sự thật" của mốc: khi kỳ chuyển
        // sang kỳ mới, ngày này KHÔNG đổi, nên mốc và ngày luôn khớp nhau.
        $storedDate = $card->cumulative_spend_baseline_date;

        // Nhãn hiển thị: ưu tiên ngày đã lưu; mốc chưa có ngày (dữ liệu cũ) thì
        // lấy ngày chốt kỳ trước hiện tại làm gợi ý. Thẻ chưa đủ cấu hình kỳ ⇒
        // không có ngày (không tự đặt ngày giả).
        $referenceDate = $storedDate
            ?? ($this->hasPeriodConfiguration($card) ? $previousEnd : null);

        $hasProgress = $baseline !== null && $threshold !== null && Decimal::isPositive($threshold);

        $reached = $hasProgress && Decimal::compare($cumulative, $threshold) >= 0;

        return [
            'id' => (int) $card->id,
            'name' => $card->name,
            'bank' => $card->bank?->name,
            'card_number_last4' => $card->card_number_last4,
            'annual_fee' => $card->annual_fee === null ? null : Decimal::money($card->annual_fee),
            'threshold' => $threshold,
            'next_annual_fee_date' => $nextFeeIso,
            // Số ngày còn lại tới ngày tính phí (theo ngày lịch VN): null khi đã
            // qua ngày hoặc chưa khai. `next_annual_fee_status`:
            //   unknown | today | past | upcoming.
            'next_annual_fee_days' => $feeDays,
            'next_annual_fee_status' => $feeStatus,
            'baseline' => $baseline,
            // Ngày mốc đã lưu (ISO) — chỉ để tra cứu/lịch sử. Nhãn hiển thị dùng
            // `baseline_date_label` bên dưới.
            'baseline_date' => $storedDate?->toDateString(),
            'baseline_date_label' => $referenceDate?->format('d/m/Y'),
            'baseline_date_source' => $storedDate !== null
                ? 'saved'
                : ($referenceDate !== null ? 'previous_period' : 'unknown'),
            'note' => $card->cumulative_spend_note,
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'start_label' => $start->format('d/m/Y'),
                'end_label' => $end->format('d/m/Y'),
            ],
            // Ngày chốt sao kê của KỲ TRƯỚC — nguồn của ngày mốc. Kèm bản định
            // dạng `d/m/Y` để view in thẳng.
            'previous_period' => [
                'start' => $previousStart->toDateString(),
                'end' => $previousEnd->toDateString(),
                'start_label' => $previousStart->format('d/m/Y'),
                'end_label' => $previousEnd->format('d/m/Y'),
            ],
            // Chi tiêu KỲ HIỆN TẠI — chỉ đọc, khoá `period_spend` để không trùng
            // tên với khái niệm "chi tiêu đủ điều kiện" của engine cashback.
            'period_spend' => $spend,
            'cumulative' => $cumulative,
            'progress_percent' => $hasProgress ? (float) Decimal::percent($cumulative, $threshold) : null,
            // Số tiền còn thiếu = ngưỡng − tổng lũy kế, TỐI THIỂU bằng 0 (vượt
            // ngưỡng ⇒ 0). Chỉ có khi đã hội đủ mốc + ngưỡng dương; thiếu dữ liệu
            // ⇒ null để tầng hiển thị nói "chưa đủ dữ liệu", KHÔNG giả định 0.
            'remaining' => $hasProgress ? Decimal::clampZero(Decimal::subtract($threshold, $cumulative)) : null,
            'reached' => $reached,
        ];
    }

    /**
     * Cập nhật mốc lũy kế kỳ trước của thẻ và GHI LẠI một dòng nhật ký.
     *
     * Trong MỘT transaction: chụp giá trị cũ → ghi giá trị mới → ghi nhật ký. Nếu
     * bước nào lỗi, mốc và nhật ký không thể lệch nhau.
     *
     * `user_id` do controller truyền từ `auth()->id()`, KHÔNG lấy từ request. Ngày
     * mốc cũng do server suy từ kỳ sao kê của thẻ, KHÔNG nhận từ input.
     *
     * @param  array<string, mixed>  $attributes  `cumulative_spend_baseline` (bắt
     *         buộc), `cumulative_spend_note` (tuỳ chọn)
     * @throws InvalidArgumentException khi mốc thiếu/không phải số/âm
     */
    public function update(UserCard $card, int $userId, array $attributes, ?CarbonImmutable $today = null): UserCard
    {
        $baseline = $this->normalizeBaseline($attributes['cumulative_spend_baseline'] ?? null);
        $note = $this->normalizeNote($attributes['cumulative_spend_note'] ?? null);
        $date = $this->baselineDateFor($card, $today ?? CarbonImmutable::now());

        return DB::connection('creditcard')->transaction(function () use ($card, $userId, $baseline, $date, $note): UserCard {
            $old = $card->cumulative_spend_baseline;

            $card->forceFill([
                'cumulative_spend_baseline' => $baseline,
                'cumulative_spend_baseline_date' => $date,
                'cumulative_spend_note' => $note,
            ])->save();

            CumulativeSpendUpdate::create([
                'user_card_id' => $card->id,
                'user_id' => $userId,
                'old_baseline' => $old === null ? null : Decimal::money($old),
                'new_baseline' => $baseline,
                'baseline_date' => $date,
                'note' => $note,
            ]);

            return $card->refresh()->loadMissing('bank');
        });
    }

    /**
     * Tổng chi tiêu của KỲ HIỆN TẠI, chỉ đọc.
     *
     * Kỳ lấy bằng `findForDate()` (KHÔNG tạo): kỳ chưa có bản ghi ⇒ 0.
     */
    private function currentPeriodSpend(UserCard $card, CarbonImmutable $today): string
    {
        $period = $this->periods->findForDate($card, $today);

        if ($period === null) {
            return '0.00';
        }

        $total = Transaction::query()
            ->where('user_card_id', $card->id)
            ->where('statement_period_id', $period->id)
            ->sum('amount');

        return Decimal::money($total);
    }

    private function normalizeBaseline(mixed $value): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw new InvalidArgumentException('Vui lòng nhập chi tiêu lũy kế tính đến hết kỳ trước.');
        }

        $amount = (float) $value;

        if ($amount < 0) {
            throw new InvalidArgumentException('Chi tiêu lũy kế tính đến hết kỳ trước không được âm.');
        }

        return number_format($amount, 2, '.', '');
    }

    /**
     * Ngày chốt sao kê của KỲ TRƯỚC, dùng làm ngày mốc — KHÔNG lấy từ input.
     *
     * Suy từ chu kỳ sao kê của thẻ (`completedBoundaries`) nên luôn khớp với
     * `period_spend` và không phụ thuộc ngày hiện tại. Thẻ chưa đủ cấu hình kỳ ⇒
     * null (không tự đặt ngày giả).
     */
    private function baselineDateFor(UserCard $card, CarbonImmutable $today): ?string
    {
        if (! $this->hasPeriodConfiguration($card)) {
            return null;
        }

        [, $previousEnd] = $this->periods->completedBoundaries($card, $today);

        return $previousEnd->toDateString();
    }

    /**
     * Thẻ có đủ cấu hình để suy ra kỳ sao kê không — cùng quy ước với
     * `CreditCardCardSortService::hasPeriodConfiguration()`: có
     * `statement_period_start` (nguồn chuẩn) HOẶC `statement_day` (fallback) là đủ.
     */
    private function hasPeriodConfiguration(UserCard $card): bool
    {
        return $card->statement_period_start !== null
            || $card->statement_day !== null;
    }

    private function normalizeNote(mixed $value): ?string
    {
        $note = trim((string) ($value ?? ''));

        return $note === '' ? null : $note;
    }
}
