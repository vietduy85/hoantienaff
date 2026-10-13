<?php

namespace App\Services\CreditCard\Optimizer;

/**
 * CardScenario — kết quả đánh giá MỘT thẻ với một phân bổ chi tiêu (assignment).
 *
 * `scenarioReason` khác null nghĩa là phương án cho thẻ này BỊ LOẠI (thẻ không
 * thể tham gia) — ví dụ thẻ thuộc danh mục của user khác, thẻ chưa có policy,
 * hoặc thẻ của tôi không đủ điều kiện theo SpendQualification.
 */
final class CardScenario
{
    public function __construct(
        public readonly string $key,
        public readonly bool $isOwned,
        public readonly string $name,
        public readonly ?string $bankName,
        public readonly ?int $cardId,
        public readonly ?int $templateId,
        public readonly string $totalCashback,
        /** @var array<int, array<string, mixed>> categoryId => chi tiết */
        public readonly array $perCategory,
        public readonly ?string $scenarioReason,
        public readonly ?string $tierLabel,
        public readonly ?string $tierMinTotalSpend,
        public readonly bool $minTotalSpendMet,
        public readonly ?string $qualificationNotice,
        public readonly string $marketAssumption,
        public readonly int $instanceIndex = 1,
        public readonly ?string $productKey = null,
    ) {
    }

    /** Khoá nhóm bản sao của cùng một sản phẩm (mặc định = key). */
    public function groupKey(): string
    {
        return $this->productKey ?? $this->key;
    }

    public function cardsCounted(): bool
    {
        return $this->scenarioReason === null;
    }
}