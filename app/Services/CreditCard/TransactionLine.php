<?php

namespace App\Services\CreditCard;

/**
 * TransactionLine — một dòng giao dịch ĐẦU VÀO cho CashbackCalculator.
 *
 * Là value object thuần: chỉ chứa dữ liệu đã quyết định, không có hành vi.
 * CashbackCalculator không bao giờ truy vấn DB, nên mọi thứ nó cần đều phải
 * được đưa vào đây từ service bên ngoài.
 */
final class TransactionLine
{
    public function __construct(
        public readonly int $id,
        public readonly string $transactionDate,
        public readonly ?int $categoryId,
        public readonly string $amount,
    ) {}

    public function amountAsFloat(): float
    {
        return (float) $this->amount;
    }
}
