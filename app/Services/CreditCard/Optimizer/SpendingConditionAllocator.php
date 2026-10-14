<?php

namespace App\Services\CreditCard\Optimizer;

use App\Models\CreditCard\SpendQualificationCondition;

/**
 * SpendingConditionAllocator — CHỦ ĐỘNG đạt "điều kiện hoàn tiền đặc biệt".
 *
 * Nhiều thẻ chỉ hoàn tiền khi người dùng đạt một mức chi tiêu tối thiểu trên thẻ
 * đó (đo trên CHI TIÊU THỰC TẾ của kỳ, xem `SpendQualificationService`). Vì điều
 * kiện là GATE (không đạt ⇒ cả kỳ 0 đồng), bộ tối ưu không thể tìm ra phương án
 * tốt nếu chỉ gieo mỗi danh mục vào thẻ có giá trị đơn cao nhất — vì xét riêng lẻ
 * thẻ vẫn "0 đồng".
 *
 * Lớp này bù lại: nếu một danh mục người dùng nhập bị CHÍNH điều kiện loại trừ
 * khỏi pool, hoặc ngắn mức tối thiểu, nó CHUYỂN (move, không nhân) phần chi tiêu
 * đủ điều kiện sang thẻ đích — chọn khoản chi có giá trị hoàn tiền NƠI KHÁC thấp
 * nhất trước (chi phí cơ hội nhỏ nhất). Tổng chi mỗi danh mục KHÔNG đổi.
 *
 * Kết quả vẫn phải do engine xác nhận: `PortfolioOptimizerService` chỉ nhận phương
 * án đã kích hoạt NẾU tổng hoàn tiền thật cao hơn.
 */
class SpendingConditionAllocator
{
    /**
     * Phân bổ đã sửa để thẻ `$scaffold` ĐẠT điều kiện, hoặc `null` nếu không cần /
     * không đủ chi tiêu để đạt.
     *
     * @param  array<string, array<int, int>>  $allocation
     * @param  array<int, int>  $amounts
     * @param  array<string, array<int, array{value: float, tier_min: float|null, percent: float|null}>>  $singles
     * @return array<string, array<int, int>>|null
     */
    public function activate(CardScaffold $scaffold, array $allocation, array $amounts, array $singles): ?array
    {
        $qualification = $scaffold->qualification;

        if ($qualification === null) {
            return null;
        }

        $conditions = $qualification->conditions
            ->filter(fn (SpendQualificationCondition $condition): bool => (bool) $condition->is_enabled);

        if ($conditions->isEmpty()) {
            return null;
        }

        $key = $scaffold->key;
        $working = $allocation;
        $changed = false;

        foreach ($conditions as $condition) {
            if ($condition->isCategory()) {
                $categoryId = (int) $condition->category_id;
                $min = (float) $condition->min_spend;
                $available = (int) ($amounts[$categoryId] ?? 0);

                if ($available < $min) {
                    return null;
                }

                if (! $this->ensureOnTarget($working, $key, $categoryId, (int) ceil($min))) {
                    return null;
                }

                $changed = true;

                continue;
            }

            $excluded = $condition->excludedCategories
                ->pluck('category_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $min = (float) $condition->min_spend;
            $onTarget = 0;

            foreach ($working[$key] ?? [] as $categoryId => $amount) {
                if (! in_array((int) $categoryId, $excluded, true)) {
                    $onTarget += (int) $amount;
                }
            }

            $shortfall = (int) ceil(max(0.0, $min - $onTarget));

            if ($shortfall <= 0) {
                continue;
            }

            $sources = [];

            foreach ($amounts as $categoryId => $amount) {
                $categoryId = (int) $categoryId;
                $amount = (int) $amount;

                if ($amount <= 0 || in_array($categoryId, $excluded, true)) {
                    continue;
                }

                $already = (int) ($working[$key][$categoryId] ?? 0);
                $available = $amount - $already;

                if ($available <= 0) {
                    continue;
                }

                $sources[] = [
                    'category_id' => $categoryId,
                    'available' => $available,
                    'percent' => $this->bestPercentElsewhere($singles, $categoryId),
                ];
            }

            usort($sources, fn (array $a, array $b): int => [$a['percent'], -$a['available'], $a['category_id']]
                <=> [$b['percent'], -$b['available'], $b['category_id']]);

            foreach ($sources as $source) {
                if ($shortfall <= 0) {
                    break;
                }

                $move = min($source['available'], $shortfall);

                if ($move <= 0) {
                    continue;
                }

                $current = (int) ($working[$key][$source['category_id']] ?? 0);

                if (! $this->ensureOnTarget($working, $key, $source['category_id'], $current + $move)) {
                    return null;
                }

                $shortfall -= $move;
                $changed = true;
            }

            if ($shortfall > 0) {
                return null;
            }
        }

        return $changed ? $working : null;
    }

    /**
     * Bảo đảm thẻ `$key` giữ ĐÚNG `$targetAmount` của danh mục, kéo phần thiếu từ
     * các thẻ khác (không tạo thêm tiền). Trả `false` nếu tổng hiện có không đủ.
     *
     * @param  array<string, array<int, int>>  $allocation
     */
    private function ensureOnTarget(array &$allocation, string $key, int $categoryId, int $targetAmount): bool
    {
        $current = (int) ($allocation[$key][$categoryId] ?? 0);

        if ($current >= $targetAmount) {
            return true;
        }

        $need = $targetAmount - $current;

        foreach ($allocation as $otherKey => $cats) {
            if ($otherKey === $key) {
                continue;
            }

            $have = (int) ($cats[$categoryId] ?? 0);

            if ($have <= 0) {
                continue;
            }

            $take = min($have, $need);
            $allocation[$otherKey][$categoryId] = $have - $take;

            if ($allocation[$otherKey][$categoryId] <= 0) {
                unset($allocation[$otherKey][$categoryId]);
            }

            $allocation[$key][$categoryId] = ($allocation[$key][$categoryId] ?? 0) + $take;
            $need -= $take;

            if ($need <= 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Giá trị hoàn tiền đơn CAO NHẤT của danh mục trên mọi thẻ — ước lượng chi phí
     * cơ hội khi rút danh mục khỏi nơi đang đặt.
     *
     * @param  array<string, array<int, array{value: float, tier_min: float|null, percent: float|null}>>  $singles
     */
    private function bestPercentElsewhere(array $singles, int $categoryId): float
    {
        $best = 0.0;

        foreach ($singles as $rows) {
            $percent = $rows[$categoryId]['percent'] ?? null;

            if ($percent !== null) {
                $best = max($best, (float) $percent);
            }
        }

        return $best;
    }
}
