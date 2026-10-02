<?php

namespace App\Http\Requests\CreditCard\Concerns;

use App\Models\CreditCard\UserCard;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Kiểm tra ngày giao dịch có thuộc kỳ sao kê HIỆN TẠI của thẻ hay không.
 *
 * Dùng chung cho thêm mới và sửa để hai luồng KHÔNG thể lệch nhau. Toàn bộ quy tắc
 * nằm ở `StatementPeriodService::isInCurrentPeriod()` — form request chỉ gọi, không
 * tự tính ranh giới kỳ (đó là việc của service, theo `statement_day`).
 */
trait ValidatesTransactionDateInCurrentPeriod
{
    /**
     * Closure rule cho `transaction_date`.
     *
     * Bỏ qua khi chưa xác định được thẻ hoặc ngày sai định dạng — các rule khác
     * đã báo lỗi rồi, thêm lỗi ở đây chỉ gây nhiễu.
     */
    protected function transactionDateWithinCurrentPeriod(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $date = $this->input('transaction_date');

            if (! is_string($date) || ! CarbonImmutable::hasFormat($date, 'Y-m-d')) {
                return;
            }

            $card = $this->cardForTransactionDateValidation();

            if ($card === null) {
                return;
            }

            $periods = app(StatementPeriodService::class);

            if ($periods->isInCurrentPeriod($card, CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay())) {
                return;
            }

            [$start, $end] = $periods->currentBoundaries($card);

            $fail(sprintf(
                'Ngày giao dịch phải nằm trong kỳ sao kê hiện tại (%s – %s).',
                $start->format('d/m/Y'),
                $end->format('d/m/Y'),
            ));
        };
    }

    /**
     * Thẻ mà `transaction_date` phải nằm trong kỳ hiện tại của.
     *
     * Thêm mới lấy từ `user_card_id` trong payload; sửa lấy từ chính giao dịch
     * (giao dịch không di chuyển giữa các thẻ).
     */
    abstract protected function cardForTransactionDateValidation(): ?UserCard;
}
