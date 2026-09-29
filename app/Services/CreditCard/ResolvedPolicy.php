<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyVersion;

/**
 * ResolvedPolicy — kết quả resolve policy tại một mốc thời gian.
 *
 * Value object thuần: mang business rule ĐÃ ĐỌC, không truy vấn thêm. Đây là
 * đầu vào duy nhất mà `CashbackCalculator` cần.
 */
final class ResolvedPolicy
{
    public function __construct(
        public readonly PolicyVersion $policyVersion,
        public readonly ?Policy $root,
        public readonly float $minTotalSpend,
        public readonly ?float $maxCashbackTotalPerPeriod,
        public readonly string $roundingMode,
    ) {
    }

    public function isLocked(): bool
    {
        return (bool) $this->policyVersion->is_locked;
    }
}
