<?php

namespace App\Services\CreditCard\Optimizer;

/**
 * OptimizationAudit — NHẬT KÝ có cấu trúc các QUYẾT ĐỊNH thật của bộ tối ưu.
 *
 * Mỗi lần `PortfolioOptimizerService::run()` chạy một cấu hình sinh, nó ghi lại
 * từng bước quyết định (gieo danh mục vào thẻ nào, vì sao; loại thẻ/danh mục vì
 * lý do gì; bù ngưỡng tổng chi tối thiểu; để lại khoản chưa phân bổ) vào một đối
 * tượng audit. Nhật ký được gắn vào phương án tương ứng và hiển thị thu gọn.
 *
 * Mục đích: truy vết được thuật toán ĐÃ QUYẾT ĐỊNH GÌ theo đúng thứ tự chạy —
 * KHÔNG dựng lại suy đoán sau khi đã có kết quả.
 */
final class OptimizationAudit
{
    public const STEP_CANDIDATES = 'candidates';

    public const STEP_SEED = 'seed';

    public const STEP_REJECT_CATEGORY = 'reject_category';

    public const STEP_REALLOCATE = 'reallocate';

    public const STEP_TIER_FILL = 'tier_fill';

    public const STEP_ACTIVATION = 'activation';

    public const STEP_CONDITION_UNMET = 'condition_unmet';

    public const STEP_UNALLOCATED = 'unallocated';

    public const STEP_FINAL = 'final';

    /** @var list<array<string, mixed>> */
    private array $entries = [];

    /**
     * Ghi một bước. `order` được đánh tự động theo thứ tự gọi (đúng thứ tự chạy).
     *
     * @param  array<string, mixed>  $entry
     */
    public function record(array $entry): void
    {
        $entry['order'] = count($this->entries) + 1;
        $this->entries[] = $entry;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }
}
