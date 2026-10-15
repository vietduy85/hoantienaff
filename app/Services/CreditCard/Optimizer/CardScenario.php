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
        /** Tổng chi tiêu ĐỦ ĐIỀU KIỆN (gộp, TRƯỚC trần) — dùng cho ngưỡng min-spend/bậc. */
        public readonly ?string $totalEligibleSpend = null,
        /**
         * Tóm tắt "điều kiện hoàn tiền đặc biệt" của kỳ MÔ PHỎNG dưới dạng
         * `['enabled' => bool, 'conditions' => list<...>]` (cùng shape
         * `SpendQualificationService::summaryForPeriod`), hoặc null khi thẻ không
         * có điều kiện / điều kiện bị tắt. Dùng để trình bày trạng thái TỪNG điều
         * kiện (đỏ/xanh) mà KHÔNG tính lại lệch evaluator.
         */
        public readonly ?array $qualificationSummary = null,
        public readonly int $instanceIndex = 1,
        public readonly ?string $productKey = null,
        /** Số giao dịch giả định đã dùng cho thẻ (mỗi danh mục ≥ 1 dòng). */
        public readonly int $simulatedTransactions = 0,
        /** Kết quả có phụ thuộc việc CHIA khoản chi thành nhiều giao dịch không. */
        public readonly bool $dependsOnSplitting = false,
        /** Kết quả có phụ thuộc giả định khoản chi được THANH TOÁN TRỰC TUYẾN không. */
        public readonly bool $dependsOnOnline = false,
    ) {}

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
