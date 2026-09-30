<?php

namespace App\Services\CreditCard;

/**
 * Kết quả tính cashback cho MỘT giao dịch.
 *
 * Tách rõ ba nhóm theo §16:
 *   - `is_eligible` / `ineligible_reason` : kết luận
 *   - `cashbackPercent` / `cashbackAmount`: giá trị
 *   - `meta`                             : cap nào chạm, quota trước/sau
 */
final class CashbackResult
{
    public function __construct(
        public readonly int $transactionId,
        public readonly bool $isEligible,
        public readonly string $cashbackAmount,
        public readonly ?string $cashbackPercent = null,
        public readonly ?int $ruleId = null,
        public readonly ?string $ineligibleReason = null,
        public readonly array $meta = [],
    ) {}

    public function cashbackAmountAsFloat(): float
    {
        return (float) $this->cashbackAmount;
    }
}
