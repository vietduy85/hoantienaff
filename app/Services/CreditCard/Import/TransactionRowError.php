<?php

namespace App\Services\CreditCard\Import;

/**
 * Lỗi của một ô/dòng khi import. Không làm hỏng cả file — dùng để báo lại cho
 * user sửa đúng dòng.
 */
final class TransactionRowError
{
    public function __construct(
        public readonly int $rowNumber,
        public readonly string $column,
        public readonly string $message,
    ) {}

    public function toArray(): array
    {
        return [
            'row' => $this->rowNumber,
            'column' => $this->column,
            'message' => $this->message,
        ];
    }
}
