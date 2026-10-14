<?php

namespace App\Services\CreditCard\Optimizer;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\PolicyTierCategory;

/**
 * OnlinePaymentEligibilityResolver — cầu nối CONFIG ⇒ ID cho thuộc tính mô phỏng
 * "khoản chi được thanh toán trực tuyến" (xem `config/credit_card_online.php`).
 *
 * Lớp này KHÔNG tự quyết định số tiền hay tỷ lệ; nó chỉ trả lời:
 *   - danh mục gốc nào CÓ THỂ chuyển một phần sang danh mục online nào;
 *   - thẻ này có rule phủ danh mục online đó không (nếu không thì dòng online
 *     cũng không sinh hoàn tiền, khỏi thử).
 *
 * ID danh mục được resolve (và memo) MỘT lần cho mỗi instance ⇒ không query
 * trong vòng lặp đánh giá.
 */
class OnlinePaymentEligibilityResolver
{
    /** @var array<int, int>|null base category id => online category id */
    private ?array $baseToOnline = null;

    /** @var array<string, int> slug => category id */
    private array $slugToId = [];

    public function isEnabled(): bool
    {
        return (bool) config('credit_card_online.enabled', false);
    }

    /**
     * ID danh mục hệ thống thay thế cho một danh mục gốc (null nếu không cấu hình).
     */
    public function targetForBase(int $baseCategoryId): ?int
    {
        return $this->map()[$baseCategoryId] ?? null;
    }

    /**
     * Toàn bộ danh mục gốc (đã resolve ID) có thể chuyển sang danh mục online.
     *
     * @return list<int>
     */
    public function baseCategoryIds(): array
    {
        return array_map('intval', array_keys($this->map()));
    }

    /**
     * Tỷ lệ chia sang dòng ONLINE sẽ thử (0.0 = toàn bộ sang online).
     *
     * @return list<float>
     */
    public function baseFractions(): array
    {
        $raw = (array) config('credit_card_online.base_fractions', [0.0, 0.25, 0.5, 0.75]);
        $out = [];

        foreach ($raw as $value) {
            $value = (float) $value;

            if ($value >= 0.0 && $value < 1.0) {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Thẻ có rule phủ danh mục `$categoryId` không (rule danh mục cụ thể, combo
     * chứa danh mục, hoặc fallback "các danh mục còn lại").
     */
    public function coversCategory(CardScaffold $scaffold, int $categoryId): bool
    {
        foreach ($scaffold->allEnabledRules as $rule) {
            if ($this->ruleMatches($rule, $categoryId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, int>
     */
    private function map(): array
    {
        if ($this->baseToOnline !== null) {
            return $this->baseToOnline;
        }

        $this->baseToOnline = [];

        if (! $this->isEnabled()) {
            return $this->baseToOnline;
        }

        $spec = (array) config('credit_card_online.verified', []);

        if (config('credit_card_online.use_unverified', false)) {
            $spec += (array) config('credit_card_online.unverified', []);
        }

        if ($spec === []) {
            return $this->baseToOnline;
        }

        $slugs = [];

        foreach ($spec as $base => $target) {
            $slugs[] = (string) $base;
            $slugs[] = (string) $target;
        }

        $rows = Category::query()
            ->whereIn('slug', array_values(array_unique($slugs)))
            ->get(['id', 'slug']);

        foreach ($rows as $row) {
            $this->slugToId[(string) $row->slug] = (int) $row->id;
        }

        foreach ($spec as $base => $target) {
            $baseId = $this->slugToId[(string) $base] ?? null;
            $onlineId = $this->slugToId[(string) $target] ?? null;

            if ($baseId !== null && $onlineId !== null && $baseId !== $onlineId) {
                $this->baseToOnline[$baseId] = $onlineId;
            }
        }

        return $this->baseToOnline;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function ruleMatches(array $rule, int $categoryId): bool
    {
        if (($rule['scope_type'] ?? PolicyTierCategory::SCOPE_CATEGORY) === PolicyTierCategory::SCOPE_OTHER) {
            return true;
        }

        $members = $rule['combo_category_ids'] ?? [];

        if ($members !== []) {
            return in_array($categoryId, array_map('intval', $members), true);
        }

        return (int) ($rule['category_id'] ?? 0) === $categoryId;
    }
}
