<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyVersion;

/**
 * ResolvedPolicy — kết quả resolve policy tại một mốc thời gian.
 *
 * Value object thuần: mang business rule ĐÃ ĐỌC, không truy vấn thêm. Trần hoàn
 * mỗi kỳ không còn nằm ở policy toàn cục — nó thuộc BẬC và chỉ biết được sau khi
 * resolve bậc theo tổng chi tiêu cuối kỳ (ở tầng `CashbackRecordService`), nên VO
 * này không mang theo cap nào cả.
 */
final class ResolvedPolicy
{
    public function __construct(
        public readonly PolicyVersion $policyVersion,
        public readonly ?Policy $root,
        public readonly float $minTotalSpend,
        public readonly string $roundingMode,
    ) {}

    public function isLocked(): bool
    {
        return (bool) $this->policyVersion->is_locked;
    }
}
