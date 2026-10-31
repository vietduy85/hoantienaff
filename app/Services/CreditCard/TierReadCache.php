<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\CategoryComboItem;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyTierCategoryTransactionCap;
use Closure;
use Illuminate\Support\Collection;

/**
 * Nạp sẵn TOÀN BỘ bậc / rule / cap / thành viên combo (bảng nhỏ: vài trăm dòng) trong
 * 4 câu truy vấn cho các phép tính CHỈ ĐỌC chạy qua rất nhiều policy: Đề xuất thẻ và
 * Tối ưu thẻ. Trước đây TierResolverService truy vấn theo từng policy/bậc => ~450-600
 * query cho mỗi lần Đề xuất.
 *
 * Chỉ có hiệu lực BÊN TRONG `using()`; ngoài phạm vi đó TierResolverService truy vấn DB
 * như cũ, nên đường ghi (sửa chính sách) không bao giờ đọc phải dữ liệu nạp sẵn đã cũ.
 * Thứ tự dữ liệu giữ đúng như các truy vấn gốc.
 */
final class TierReadCache
{
    /** @var array{tiers: array<int, Collection>, rules: array<int, Collection>, caps: array<int, Collection>, combos: array<int, array<int, int>>}|null */
    private static ?array $data = null;

    public static function using(Closure $callback): mixed
    {
        // Lồng nhau: dùng lại dữ liệu đã nạp của phạm vi ngoài.
        if (self::$data !== null) {
            return $callback();
        }

        $tiers = PolicyTier::query()->orderBy('id')->get();
        $rules = PolicyTierCategory::query()
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $caps = PolicyTierCategoryTransactionCap::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $combos = CategoryComboItem::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['combo_id', 'category_id']);

        self::$data = [
            'tiers' => $tiers->groupBy('policy_id')->map->values()->all(),
            'rules' => $rules->groupBy('tier_id')->map->values()->all(),
            'caps' => $caps->groupBy('policy_tier_id')->map->values()->all(),
            'combos' => $combos->groupBy('combo_id')
                ->map(fn (Collection $items): array => $items->map(fn ($item): int => (int) $item->category_id)->all())
                ->all(),
        ];

        try {
            return $callback();
        } finally {
            self::$data = null;
        }
    }

    public static function active(): bool
    {
        return self::$data !== null;
    }

    /** @return Collection<int, PolicyTier> theo id tăng dần */
    public static function tiersOf(int $policyId): Collection
    {
        return self::$data['tiers'][$policyId] ?? collect();
    }

    /** @return Collection<int, PolicyTierCategory> rule đang bật, theo (sort_order, id) */
    public static function rulesOf(int $tierId): Collection
    {
        return self::$data['rules'][$tierId] ?? collect();
    }

    /**
     * Rule đang bật của NHIỀU bậc, sắp chung theo (sort_order, id) — đúng như whereIn gốc.
     *
     * @param  iterable<int>  $tierIds
     * @return Collection<int, PolicyTierCategory>
     */
    public static function rulesOfTiers(iterable $tierIds): Collection
    {
        $all = collect();
        foreach ($tierIds as $tierId) {
            $all = $all->concat(self::rulesOf((int) $tierId));
        }

        return $all->sort(fn (PolicyTierCategory $a, PolicyTierCategory $b): int => [(int) $a->sort_order, (int) $a->id] <=> [(int) $b->sort_order, (int) $b->id])
            ->values();
    }

    /** @return Collection<int, PolicyTierCategoryTransactionCap> theo (sort_order, id) */
    public static function capsOf(int $tierId): Collection
    {
        return self::$data['caps'][$tierId] ?? collect();
    }

    /** @return array<int, int> category_id thành viên của combo */
    public static function comboMembers(int $comboId): array
    {
        return self::$data['combos'][$comboId] ?? [];
    }
}
