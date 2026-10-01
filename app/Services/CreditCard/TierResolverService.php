<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\CategoryComboItem;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyTierCategoryTransactionCap;
use App\Models\CreditCard\PolicyVersion;
use App\Models\CreditCard\UserCard;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * TierResolverService — tra cứu từ dữ liệu ĐÃ RESOLVE, không tự tính.
 *
 * Nhận `totalEligibleSpend` làm input rồi trả về bậc khớp. Việc cộng tổng chi
 * tiêu thuộc về `CashbackRecordService` (tầng DB); tầng này chỉ tra bảng.
 *
 * Tier là RETROACTIVE: bậc phụ thuộc TỔNG của cả kỳ, không phụ thuộc tiến trình
 * từng giao dịch. Khoảng là nửa mở [min_total_spend, max_total_spend).
 */
class TierResolverService
{
    /**
     * Bậc chứa `total` (nửa mở). Trả null nếu không có bậc nào phủ `total`.
     *
     * Nếu dữ liệu cấu hình chồng nhau, chọn bậc hẹp nhất rồi theo id để TẤT ĐỊNH.
     */
    public function resolveTier(PolicyVersion $policyVersion, float $total): ?PolicyTier
    {
        $tiers = PolicyTier::query()
            ->where('policy_id', $policyVersion->id)
            ->get()
            ->filter(fn (PolicyTier $tier): bool => $this->contains($tier, $total))
            ->sortBy(fn (PolicyTier $tier): array => [
                $this->width($tier),
                $tier->id,
            ])
            ->values();

        return $tiers->first();
    }

    /**
     * Toàn bộ rule cashback của một bậc, đã hydrate thành mảng phẳng cho
     * `CashbackCalculator`.
     *
     * CHỈ lấy rule `is_enabled = 1`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rulesForTier(?PolicyTier $tier): array
    {
        if ($tier === null) {
            return [];
        }

        $rules = PolicyTierCategory::query()
            ->where('tier_id', $tier->id)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->hydrate($rules);
    }

    /**
     * Các điều kiện cap động của một bậc — áp cho MỌI rule trong bậc.
     *
     * @return array<int, array<string, mixed>>
     */
    public function transactionCapsForTier(?PolicyTier $tier): array
    {
        if ($tier === null) {
            return [];
        }

        return PolicyTierCategoryTransactionCap::query()
            ->where('policy_tier_id', $tier->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (PolicyTierCategoryTransactionCap $cap): array => [
                'min_transaction_amount' => (float) $cap->min_transaction_amount,
                'max_transaction_amount' => $cap->max_transaction_amount === null
                    ? null
                    : (float) $cap->max_transaction_amount,
                'max_cashback_per_transaction' => (float) $cap->max_cashback_per_transaction,
            ])
            ->all();
    }

    /**
     * Toàn bộ rule cashback của MỌI bậc trong một version.
     *
     * Dùng để xác định "giao dịch nào đủ điều kiện về mặt rule" — phải KHÔNG
     * phụ thuộc bậc, vì bậc là kết quả của tổng chi tiêu (retroactive). Nếu lấy
     * rule theo bậc trước rồi mới cộng tổng thì thành vòng lặp: tổng phụ thuộc
     * bậc, bậc phụ thuộc tổng.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allEnabledRulesFor(PolicyVersion $policyVersion): array
    {
        $tierIds = PolicyTier::query()
            ->where('policy_id', $policyVersion->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id');

        if ($tierIds->isEmpty()) {
            return [];
        }

        $rules = PolicyTierCategory::query()
            ->whereIn('tier_id', $tierIds)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->hydrate($rules);
    }

    /**
     * Chuyển collection rule thành mảng phẳng cho `CashbackCalculator`.
     *
     * ---------------------------------------------------------------------------
     * `combo_category_ids` — VÌ SAO PHẢI HYDRATE SẴN
     * ---------------------------------------------------------------------------
     * Rule combo khớp giao dịch theo membership, nên engine cần biết TRƯỚC danh
     * sách danh mục thành viên. Nếu để `CashbackCalculator` tự truy vấn, mỗi lần
     * gọi sẽ bắn thêm N query trong vòng lặp giao dịch.
     *
     * Gom TẤT CẢ membership của các combo trong truy vấn này bằng MỘT lần query
     * (`whereIn(combo_id)`), rồi đính vào từng rule. Một policy chỉ có vài chục
     * rule nên chi phí không đáng kể, và đảm bảo engine thuần túy: không query DB.
     *
     * Rule không phải combo ⇒ `combo_category_ids = []` (KHÔNG phải null) để
     * `CashbackCalculator` không phải null-check ở mọi chỗ.
     *
     * @param  Collection<int, PolicyTierCategory>  $rules
     * @return array<int, array<string, mixed>>
     */
    private function hydrate(Collection $rules): array
    {
        $comboIds = $rules
            ->pluck('combo_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $membersByCombo = $comboIds->isEmpty()
            ? collect()
            : CategoryComboItem::query()
                ->whereIn('combo_id', $comboIds->all())
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['combo_id', 'category_id'])
                ->groupBy('combo_id')
                ->map(fn (Collection $items): array => $items
                    ->map(fn (CategoryComboItem $item): int => (int) $item->category_id)
                    ->all());

        return $rules
            ->map(fn (PolicyTierCategory $rule): array => [
                'id' => $rule->id,
                'category_id' => $rule->category_id,
                'combo_id' => $rule->combo_id,
                'combo_category_ids' => $rule->combo_id === null
                    ? []
                    : $membersByCombo->get((int) $rule->combo_id, []),
                'scope_type' => $rule->scope_type ?? PolicyTierCategory::SCOPE_CATEGORY,
                'counts_toward_tier_cap' => (bool) ($rule->counts_toward_tier_cap ?? true),
                'spend_from' => (float) $rule->spend_from,
                'spend_to' => $rule->spend_to === null ? null : (float) $rule->spend_to,
                'cashback_percent' => (float) $rule->cashback_percent,
                'max_cashback_per_transaction' => $rule->max_cashback_per_transaction === null
                    ? null
                    : (float) $rule->max_cashback_per_transaction,
                'max_cashback_per_category_per_period' => $rule->max_cashback_per_category_per_period === null
                    ? null
                    : (float) $rule->max_cashback_per_category_per_period,
                'min_transaction_amount' => $rule->min_transaction_amount === null
                    ? null
                    : (float) $rule->min_transaction_amount,
            ])
            ->all();
    }

    /**
     * Policy version hiện hành cho thẻ tại một ngày bất kỳ.
     *
     * Quy tắc:
     *   1. Ưu tiên chuỗi version có `current_policy_id` trên thẻ (nếu còn hiệu lực).
     *   2. Nếu không, lấy version hiệu lực mới nhất theo `effective_from`.
     *
     * User KHÔNG chọn version — hệ thống tự resolve.
     */
    public function resolvePolicyVersion(UserCard $userCard, DateTimeInterface $date): ?PolicyVersion
    {
        $current = $userCard->currentPolicy;

        if ($current !== null) {
            $chain = $current->isRoot() ? $current : $current->root;

            $ownerCardId = $chain !== null ? (int) $chain->user_card_id : (int) $userCard->id;

            if ($ownerCardId === (int) $userCard->id) {
                $version = $this->versionInChainEffectiveOn($chain, $date);

                if ($version !== null) {
                    return $version;
                }
            }
        }

        return PolicyVersion::query()
            ->where('user_card_id', $userCard->id)
            ->whereIn('status', [Policy::STATUS_ACTIVE, Policy::STATUS_SUPERSEDED])
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('version_no')
            ->first();
    }

    /**
     * Version đang hiệu lực trong chuỗi version `root` tại `$date`.
     */
    public function versionInChainEffectiveOn(Policy $root, DateTimeInterface $date): ?PolicyVersion
    {
        $rootId = $root->id;

        return PolicyVersion::query()
            ->where(function ($query) use ($rootId): void {
                // Bản ghi root có thể chưa tự trỏ về chính nó (nếu tạo bởi code cũ).
                $query->where('id', $rootId)
                    ->orWhere('root_policy_id', $rootId);
            })
            ->whereIn('status', [Policy::STATUS_ACTIVE, Policy::STATUS_SUPERSEDED])
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            })
            ->orderByDesc('version_no')
            ->first();
    }

    /**
     * Các version trong một chuỗi, sắp xếp tăng dần version_no.
     *
     * @return Collection<int, PolicyVersion>
     */
    public function chainOf(Policy $root): Collection
    {
        $rootId = $root->id;

        return PolicyVersion::query()
            ->where('id', $rootId)
            ->orWhere('root_policy_id', $rootId)
            ->orderBy('version_no')
            ->get();
    }

    private function contains(PolicyTier $tier, float $total): bool
    {
        if ($total < (float) $tier->min_total_spend) {
            return false;
        }

        if ($tier->max_total_spend !== null && $total >= (float) $tier->max_total_spend) {
            return false;
        }

        return true;
    }

    private function width(PolicyTier $tier): float
    {
        if ($tier->max_total_spend === null) {
            return PHP_FLOAT_MAX;
        }

        return (float) $tier->max_total_spend - (float) $tier->min_total_spend;
    }
}
