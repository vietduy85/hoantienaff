<?php

namespace App\Services\CreditCard\Import;

/**
 * Một dòng giao dịch đã đọc từ file, CHƯA validate nghiệp vụ.
 *
 * Giá trị giữ nguyên dạng chuỗi như đọc từ sheet; việc ép kiểu/kiểm tra hợp lệ
 * do `TransactionImportService` lo để tập trung luật vào một chỗ.
 */
final class TransactionRow
{
    public function __construct(
        /** Số thứ tự dòng trong file (1-based, khớp với số hiển thị trong Excel). */
        public readonly int $rowNumber,
        public readonly ?string $transactionDate,
        public readonly ?string $postedDate,
        public readonly ?string $amount,
        public readonly ?string $merchant,
        public readonly ?string $category,
        public readonly ?string $note,
    ) {
    }
}
