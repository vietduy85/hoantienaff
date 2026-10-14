<?php

namespace App\Services\CreditCard\Optimizer;

/**
 * CakeFreedomRule — quy tắc MÔ PHỎNG riêng cho thẻ Cake Freedom trong optimizer.
 *
 * Mục đích: với thẻ Cake Freedom, KHÔNG cần dò tổ hợp chia nhỏ giao dịch phức tạp
 * để chạm trần. Optimizer chỉ cần phân bổ tối đa `trần danh mục / tỷ lệ` cho mỗi
 * danh mục đủ điều kiện, và mức hoàn mô phỏng mỗi danh mục = min(tỷ lệ × chi,
 * trần danh mục).
 *
 *   - Tỷ lệ: lấy từ rule thật trong policy (Cake Freedom = 20%).
 *   - Trần danh mục: `max_cashback_per_category_per_period` (Cake = 200.000đ).
 *   - Trần ngân sách/danh mục = trần danh mục / tỷ lệ (200.000 / 20% = 1.000.000đ).
 *
 * Đây là quy tắc LẬP PHƯƠNG ÁN của optimizer, không phải logic tính hoàn tiền thực
 * tế (việc đó vẫn thuộc `CashbackCalculator`). Không hardcode tỷ lệ/trần sản phẩm.
 */
final class CakeFreedomRule
{
    /** Template sản phẩm áp dụng quy tắc đơn giản (Cake Freedom). */
    public const TEMPLATE_SLUG = 'vpbank-cake-freedom-visa';

    public static function appliesTo(CardScaffold $scaffold): bool
    {
        return $scaffold->templateSlug === self::TEMPLATE_SLUG;
    }

    /**
     * Trần ngân sách mô phỏng của MỘT danh mục = max(trần danh mục / tỷ lệ) trong
     * tập rule. Trả null khi không suy được (thẻ/danh mục không có trần danh mục).
     *
     * @param  array<int, array<string, mixed>>  $rules
     */
    public static function categoryBudgetCap(array $rules): ?float
    {
        $cap = null;

        foreach ($rules as $rule) {
            $percent = (float) ($rule['cashback_percent'] ?? 0.0);
            $capCat = $rule['max_cashback_per_category_per_period'] ?? null;

            if ($percent <= 0.0 || $capCat === null || (float) $capCat <= 0.0) {
                continue;
            }

            $reach = (float) $capCat / ($percent / 100.0);
            $cap = $cap === null ? $reach : max($cap, $reach);
        }

        return $cap;
    }

    /**
     * Hoàn tiền MÔ PHỎNG của một danh mục = min(tỷ lệ × chi, trần danh mục).
     */
    public static function categoryCashback(float $percent, ?float $categoryCap, float $amount): float
    {
        if ($percent <= 0.0 || $amount <= 0.0) {
            return 0.0;
        }

        $cashback = $amount * ($percent / 100.0);

        if ($categoryCap !== null && $categoryCap > 0.0) {
            $cashback = min($cashback, $categoryCap);
        }

        return $cashback;
    }

    /**
     * Trần ngân sách/danh mục suy từ TOÀN BỘ rule đang bật của scaffold (dùng cho
     * bước cắt phân bổ hiển thị, không phụ thuộc bậc).
     */
    public static function budgetCapForCategory(CardScaffold $scaffold, int $categoryId): ?float
    {
        return self::categoryBudgetCap(self::matchingRules($scaffold->allEnabledRules, $categoryId));
    }

    /**
     * Cắt mỗi danh mục Cake về trần ngân sách mô phỏng; danh mục không có rule
     * (không suy được trần) giữ nguyên.
     *
     * @param  array<int, int>  $cats
     * @return array<int, int>
     */
    public static function clampAllocation(CardScaffold $scaffold, array $cats): array
    {
        if (! self::appliesTo($scaffold)) {
            return $cats;
        }

        foreach ($cats as $categoryId => $amount) {
            $cap = self::budgetCapForCategory($scaffold, (int) $categoryId);

            if ($cap !== null) {
                $cats[$categoryId] = (int) min((int) $amount, (int) round($cap));
            }
        }

        return $cats;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int, array<string, mixed>>
     */
    private static function matchingRules(array $rules, int $categoryId): array
    {
        $matched = [];

        foreach ($rules as $rule) {
            if (($rule['scope_type'] ?? 'category') === 'other') {
                $matched[] = $rule;

                continue;
            }

            $members = $rule['combo_category_ids'] ?? [];

            if ($members !== []) {
                if (in_array($categoryId, array_map('intval', $members), true)) {
                    $matched[] = $rule;
                }

                continue;
            }

            if ((int) ($rule['category_id'] ?? 0) === $categoryId) {
                $matched[] = $rule;
            }
        }

        return $matched;
    }
}
