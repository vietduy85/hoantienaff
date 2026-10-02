<?php

namespace App\Support\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyTierCategoryTransactionCap;
use App\Models\CreditCard\PolicyVersion;

/**
 * SystemPolicyPresenter — định dạng dữ liệu System Policy (Template + blueprint)
 * cho trang vận hành admin và JSON API, đúng MỘT nơi.
 *
 * Cả trang Blade lẫn API dùng chung class này để payload không bao giờ lệch nhau:
 * admin xem, sửa và test khẳng định cùng một hình dạng dữ liệu.
 */
class SystemPolicyPresenter
{
    public function template(PolicyTemplate $template, bool $withDetails = false): array
    {
        // Bản version "đang phát hành cho user mới" = DEFAULT, fallback về current.
        $blueprint = $template->defaultBlueprint();

        $data = [
            'id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'scope' => $template->scope,
            'is_system' => $template->isSystemScope(),
            'is_builtin' => (bool) $template->is_builtin,
            'is_active' => (bool) $template->is_active,
            'default_version_id' => $template->default_version_id,
            'default_version_no' => $template->default_version_id === null
                ? null
                : $this->safeBlueprint($template, $template->default_version_id)?->version_no,
            'version_no' => $blueprint?->version_no,
            'blueprint_count' => $template->blueprints()->count(),
            'tiers_count' => $blueprint === null ? 0 : $this->countTiers($blueprint),
            'categories_count' => $blueprint === null ? 0 : $this->countCategories($blueprint),
            'effective_from' => $blueprint?->effective_from?->toDateString(),
            'effective_to' => $blueprint?->effective_to?->toDateString(),
            'min_total_spend' => $blueprint === null ? null : (float) $blueprint->min_total_spend,
            'rounding_mode' => $blueprint?->rounding_mode ?? 'round',
        ];

        if (! $withDetails) {
            return $data;
        }

        $data['tiers'] = $blueprint === null ? [] : $this->tiers($blueprint);

        return $data;
    }

    /**
     * Một blueprint (một version của chính sách hệ thống) kèm bậc/rule.
     *
     * Kèm các cờ có tính NGUYÊN TỬ để UI quyết định được "đặt mặc định" / "xóa"
     * mà không cần gọi thêm API: `is_default`, `referenced`, `can_delete`.
     */
    public function blueprint(Policy $blueprint): array
    {
        $template = $blueprint->template;

        $isDefault = $template !== null
            && $template->default_version_id !== null
            && (int) $template->default_version_id === (int) $blueprint->id;

        $usage = PolicyVersion::usageCountsForPolicy((int) $blueprint->id);
        $referenced = array_sum($usage) > 0;

        $deletability = $this->deletability($blueprint);

        return [
            'id' => $blueprint->id,
            'version_no' => (int) $blueprint->version_no,
            'status' => $blueprint->status,
            'active' => $blueprint->status === Policy::STATUS_ACTIVE,
            'name' => $blueprint->name,
            'is_locked' => (bool) $blueprint->is_locked,
            'is_default' => $isDefault,
            'referenced' => $referenced,
            'referenced_label' => $referenced ? $this->referencedLabel($usage) : null,
            'can_delete' => $deletability['can_delete'],
            'delete_block_reason' => $deletability['reason'],
            'effective_from' => $blueprint->effective_from?->toDateString(),
            'effective_to' => $blueprint->effective_to?->toDateString(),
            'min_total_spend' => (float) $blueprint->min_total_spend,
            'rounding_mode' => $blueprint->rounding_mode,
            'tiers_count' => $this->countTiers($blueprint),
            'categories_count' => $this->countCategories($blueprint),
            'tiers' => $this->tiers($blueprint),
        ];
    }

    /**
     * Version mặc định có còn tồn tại trong blueprint của template không.
     */
    private function safeBlueprint(PolicyTemplate $template, int $defaultVersionId): ?Policy
    {
        return PolicyVersion::query()
            ->where('id', $defaultVersionId)
            ->where('template_id', $template->id)
            ->whereNull('user_card_id')
            ->first();
    }

    /**
     * Version này có xóa được không — quy tắc DUY NHẤT là "đang là mặc định".
     *
     * Không chặn vì tham chiếu (giao dịch / kỳ sao kê / thẻ), không chặn vì là gốc
     * chuỗi, không chặc vì là version duy nhất: thẻ nhận deep clone nên policy thẻ
     * là bản sao độc lập; xóa gốc chuỗi sẽ tự remap `root_policy_id` sang version
     * còn lại thấp nhất.
     *
     * @return array{can_delete: bool, reason: string|null}
     */
    private function deletability(Policy $blueprint): array
    {
        $isDefault = $blueprint->template !== null
            && $blueprint->template->default_version_id !== null
            && (int) $blueprint->template->default_version_id === (int) $blueprint->id;

        return [
            'can_delete' => ! $isDefault,
            'reason' => $isDefault
                ? 'Không thể xóa phiên bản đang mặc định. Hãy đặt phiên bản khác làm mặc định trước.'
                : null,
        ];
    }

    /**
     * @param  array{transactions: int, periods: int, cards: int}  $usage
     */
    private function referencedLabel(array $usage): string
    {
        return collect([
            $usage['transactions'] > 0 ? $usage['transactions'].' giao dịch' : null,
            $usage['periods'] > 0 ? $usage['periods'].' kỳ sao kê' : null,
            $usage['cards'] > 0 ? $usage['cards'].' thẻ' : null,
        ])->filter()->implode(', ');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function tiers(Policy $blueprint): array
    {
        return $blueprint->tiers()->orderBy('sort_order')->orderBy('id')->with('transactionCaps')->get()->map(function (PolicyTier $tier): array {
            return [
                'id' => $tier->id,
                'name' => $tier->name,
                'sort_order' => (int) $tier->sort_order,
                'min_total_spend' => (float) $tier->min_total_spend,
                'max_total_spend' => $tier->max_total_spend === null ? null : (float) $tier->max_total_spend,
                'max_cashback_per_period' => $tier->max_cashback_per_period === null
                    ? null
                    : (float) $tier->max_cashback_per_period,
                'transaction_caps' => $tier->transactionCaps
                    ->sortBy('sort_order')
                    ->values()
                    ->map(fn (PolicyTierCategoryTransactionCap $cap): array => [
                        'min_transaction_amount' => (float) $cap->min_transaction_amount,
                        'max_transaction_amount' => $cap->max_transaction_amount === null
                            ? null
                            : (float) $cap->max_transaction_amount,
                        'max_cashback_per_transaction' => (float) $cap->max_cashback_per_transaction,
                    ])
                    ->all(),
                'rules' => $tier->tierCategoryRules()->orderBy('sort_order')->orderBy('id')->with(['category', 'combo'])->get()->map(
                    fn (PolicyTierCategory $rule): array => [
                        'id' => $rule->id,
                        // Target: danh mục | combo | fallback. `category_id` và
                        // `combo_id` không bao giờ cùng có giá trị.
                        'target_type' => $rule->isFallback()
                            ? 'other'
                            : ($rule->combo_id !== null ? 'combo' : 'category'),
                        'category_id' => $rule->category_id,
                        'category_name' => $rule->category?->name,
                        'combo_id' => $rule->combo_id,
                        'combo_name' => $rule->combo?->name,
                        'scope_type' => $rule->scope_type ?? PolicyTierCategory::SCOPE_CATEGORY,
                        'counts_toward_tier_cap' => (bool) ($rule->counts_toward_tier_cap ?? ! $rule->isFallback()),
                        // Cờ quota PHẢI xuất ra: không có ô nhập nào khác ghi nó, mà
                        // `replaceChildren()` dựng lại rule từ payload ⇒ thiếu là mất
                        // cấu hình (đúng bài học của `note` bên dưới). Fallback ép
                        // false để editor không bao giờ nhận fallback đang tick.
                        'is_quota_category' => $rule->isQuotaCategory(),
                        'name' => $rule->name,
                        'sort_order' => (int) $rule->sort_order,
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
                        'is_enabled' => (bool) $rule->is_enabled,
                        // `note` không có ô nhập trong editor nhưng `replaceChildren()`
                        // ghi lại từ payload, nên presenter PHẢI xuất ra: thiếu nó thì
                        // mở lại policy rồi lưu là mất ghi chú của rule cũ.
                        'note' => $rule->note,
                    ],
                )->all(),
            ];
        })->all();
    }

    private function countTiers(Policy $blueprint): int
    {
        return $blueprint->tiers()->count();
    }

    private function countCategories(Policy $blueprint): int
    {
        return (int) PolicyTierCategory::query()
            ->whereIn('tier_id', PolicyTier::query()->where('policy_id', $blueprint->id)->select('id'))
            ->distinct()
            ->count('category_id');
    }
}
