<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyVersion;
use App\Models\CreditCard\UserCard;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * PolicyCloneService — mọi thao tác "sao chép" policy đều ĐI QUA đây.
 *
 * Ba nghiệp vụ dùng chung đúng một cơ chế deep clone:
 *
 *  1. `attachTemplateToCard()` — chọn template cho thẻ: copy blueprint
 *     (policy + tiers + rules) thành chuỗi version riêng của thẻ.
 *  2. `saveAsTemplate()`         — lưu policy của thẻ thành template mới.
 *  3. `createNextVersion()`      — tạo version N+1 khi policy đổi.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO PHẢI DEEP CLONE (§13)
 * ---------------------------------------------------------------------------
 * Nếu thẻ B trỏ thẳng vào bản ghi policy của thẻ A, thì:
 *   - sửa policy của A ⇒ B đổi theo (không ai muốn điều này),
 *   - xoá A ⇒ mất cấu hình của B.
 * Nên blueprint của template KHÔNG bao giờ được thẻ nào tham chiếu trực tiếp.
 * Mỗi lần "dùng template" tạo một bản sao độc lập hoàn toàn.
 *
 * ---------------------------------------------------------------------------
 * VERSION LÀ APPEND-ONLY (§9.2)
 * ---------------------------------------------------------------------------
 * `createNextVersion()` KHÔNG sửa business rule của version cũ. Version cũ chỉ
 * bị đóng `effective_to` và chuyển `superseded` — đó là metadata vòng đời.
 * Nhờ vậy giao dịch của kỳ cũ vẫn resolve đúng version cũ về sau.
 */
class PolicyCloneService
{
    /**
     * Gắn template lên thẻ: tạo chuỗi version version 1 của thẻ từ blueprint.
     *
     * @return PolicyVersion version 1 vừa tạo
     */
    public function attachTemplateToCard(
        UserCard $userCard,
        PolicyTemplate $template,
        DateTimeInterface $effectiveFrom,
        ?string $name = null,
    ): PolicyVersion {
        return DB::connection('creditcard')->transaction(function () use ($userCard, $template, $effectiveFrom, $name): PolicyVersion {
            $blueprint = $template->blueprint;

            if ($blueprint === null) {
                throw new LogicException("Template \"{$template->name}\" chưa có policy blueprint nào để sao chép.");
            }

            $root = $this->insertPolicyRoot([
                'user_card_id' => $userCard->id,
                'template_id' => $template->id,
                'name' => $name ?? $blueprint->name,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'min_total_spend' => $blueprint->min_total_spend,
                'max_cashback_total_per_period' => $blueprint->max_cashback_total_per_period,
                'rounding_mode' => $blueprint->rounding_mode,
                'status' => Policy::STATUS_ACTIVE,
                'note' => "Sao chép từ template \"{$template->name}\" (#{$template->id}).",
            ]);

            $this->copyChildren($blueprint->id, $root->id);

            // Thẻ trỏ về chuỗi version của chính nó.
            $userCard->forceFill([
                'current_policy_id' => $root->id,
                'status' => UserCard::STATUS_ACTIVE,
            ])->save();

            $this->forgetCurrentPolicy($userCard);

            return $root;
        });
    }

    /**
     * Lưu policy của thẻ thành một template MỚI (deep clone ngược chiều).
     *
     * Template mới là scope `user`, thuộc `ownerUserId`. Không dùng chung bản ghi
     * với template gốc.
     */
    public function saveAsTemplate(
        UserCard $userCard,
        int $ownerUserId,
        string $name,
        ?string $description = null,
        bool $isActive = true,
    ): PolicyTemplate {
        return DB::connection('creditcard')->transaction(function () use ($userCard, $ownerUserId, $name, $description, $isActive): PolicyTemplate {
            $source = $userCard->currentPolicy;

            if ($source === null) {
                throw new LogicException('Thẻ chưa có policy nào để lưu thành template.');
            }

            $template = PolicyTemplate::create([
                'scope' => PolicyTemplate::SCOPE_USER,
                'owner_user_id' => $ownerUserId,
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'description' => $description,
                'is_builtin' => false,
                'is_active' => $isActive,
                'sort_order' => 0,
            ]);

            $blueprint = $this->insertPolicyRoot([
                'user_card_id' => null,
                'template_id' => $template->id,
                'name' => $name,
                'effective_from' => now()->toDateString(),
                'effective_to' => null,
                'min_total_spend' => $source->min_total_spend,
                'max_cashback_total_per_period' => $source->max_cashback_total_per_period,
                'rounding_mode' => $source->rounding_mode,
                'status' => Policy::STATUS_ACTIVE,
                'note' => "Blueprint lưu từ thẻ #{$userCard->id}.",
            ]);

            // Tiers/rule lấy từ CHÍNH `$source` (version đang áp dụng cho thẻ),
            // khớp với metadata ở trên. Lấy từ `root` sẽ tạo ra template mang
            // cấu hình version 1 trong khi metadata lại lấy từ version N.
            $this->copyChildren($source->id, $blueprint->id);

            return $template->refresh();
        });
    }

    /**
     * Tạo version N+1 cho chuỗi policy của thẻ.
     *
     * Business rule của version cũ KHÔNG bị sửa. Chỉ đóng `effective_to` +
     * chuyển `superseded` để kỳ cũ không resolve nhầm.
     *
     * @param  array<string, mixed>  $overrides  field cho phép ghi đè (dùng cho "save as new version")
     */
    public function createNextVersion(
        UserCard $userCard,
        DateTimeInterface $effectiveFrom,
        array $overrides = [],
    ): PolicyVersion {
        return DB::connection('creditcard')->transaction(function () use ($userCard, $effectiveFrom, $overrides): PolicyVersion {
            $current = $userCard->currentPolicy;

            if ($current === null) {
                throw new LogicException('Thẻ chưa có policy nào để tạo version mới.');
            }

            if ($current->is_locked) {
                throw new LogicException('Policy đang bị khoá (kỳ đã finalize), không tạo version mới được.');
            }

            $root = $current->isRoot() ? $current : $current->root;

            $nextVersionNo = (int) PolicyVersion::query()
                ->where(function ($query) use ($root): void {
                    $query->where('id', $root->id)->orWhere('root_policy_id', $root->id);
                })
                ->max('version_no') + 1;

            $previous = PolicyVersion::query()
                ->where(function ($query) use ($root): void {
                    $query->where('id', $root->id)->orWhere('root_policy_id', $root->id);
                })
                ->orderByDesc('version_no')
                ->lockForUpdate()
                ->first();

            // Nguồn để tạo version mới là VERSION MỚI NHẤT trong chuỗi, KHÔNG
            // phải `root`. Nếu lấy từ `root` thì mọi chỉnh sửa đã làm ở version 2,
            // 3... sẽ bị mất khi tạo version tiếp theo.
            $latest = $previous ?? $root;

            $newVersion = $this->insertPolicyRoot([
                'user_card_id' => $userCard->id,
                'template_id' => $latest->template_id,
                'name' => $overrides['name'] ?? $latest->name,
                'effective_from' => $effectiveFrom,
                'effective_to' => $overrides['effective_to'] ?? null,
                'min_total_spend' => $overrides['min_total_spend'] ?? $latest->min_total_spend,
                'max_cashback_total_per_period' => array_key_exists('max_cashback_total_per_period', $overrides)
                    ? $overrides['max_cashback_total_per_period']
                    : $latest->max_cashback_total_per_period,
                'rounding_mode' => $overrides['rounding_mode'] ?? $latest->rounding_mode,
                'status' => Policy::STATUS_ACTIVE,
                'note' => $overrides['note'] ?? "Version {$nextVersionNo} tạo từ version ".(int) $previous?->version_no.'.',
                'root_policy_id' => $root->id,
                'version_no' => $nextVersionNo,
            ]);

            // Sửa business rule của version mới (version cũ bất biến).
            if (isset($overrides['tiers'])) {
                $this->replaceChildren($newVersion->id, $overrides['tiers']);
            } else {
                $this->copyChildren($latest->id, $newVersion->id);
            }

            // Đóng version cũ — CHỈ đổi metadata vòng đời, không sửa business rule.
            if ($previous !== null && $previous->id !== $newVersion->id) {
                $previous->forceFill([
                    'effective_to' => $this->dayBefore($effectiveFrom),
                    'status' => Policy::STATUS_SUPERSEDED,
                ])->save();
            }

            $userCard->forceFill(['current_policy_id' => $newVersion->id])->save();

            $this->forgetCurrentPolicy($userCard);

            return $newVersion;
        });
    }

    /**
     * Tạo bản ghi policy version 1 (root tự trỏ về chính nó sau khi insert).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insertPolicyRoot(array $attributes): PolicyVersion
    {
        $versionNo = $attributes['version_no'] ?? 1;
        $rootPolicyId = $attributes['root_policy_id'] ?? null;

        unset($attributes['version_no'], $attributes['root_policy_id']);

        $policy = new PolicyVersion;
        $policy->forceFill($attributes);
        $policy->version_no = $versionNo;
        $policy->root_policy_id = $rootPolicyId;
        $policy->save();

        // root tự trỏ về chính nó ⇒ UNIQUE (root_policy_id, version_no) chống trùng version
        if ($policy->root_policy_id === null) {
            $policy->forceFill(['root_policy_id' => $policy->id])->save();
        }

        return $policy->refresh();
    }

    /**
     * Copy tier + tier_category_rule từ version nguồn sang version đích.
     */
    private function copyChildren(int $sourcePolicyId, int $targetPolicyId): void
    {
        $tiers = PolicyTier::query()
            ->where('policy_id', $sourcePolicyId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($tiers as $tier) {
            $newTier = PolicyTier::create([
                'policy_id' => $targetPolicyId,
                'name' => $tier->name,
                'sort_order' => $tier->sort_order,
                'min_total_spend' => $tier->min_total_spend,
                'max_total_spend' => $tier->max_total_spend,
            ]);

            $rules = PolicyTierCategory::query()
                ->where('tier_id', $tier->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            foreach ($rules as $rule) {
                PolicyTierCategory::create([
                    'tier_id' => $newTier->id,
                    'category_id' => $rule->category_id,
                    'name' => $rule->name,
                    'sort_order' => $rule->sort_order,
                    'spend_from' => $rule->spend_from,
                    'spend_to' => $rule->spend_to,
                    'cashback_percent' => $rule->cashback_percent,
                    'max_cashback_per_transaction' => $rule->max_cashback_per_transaction,
                    'max_cashback_per_category_per_period' => $rule->max_cashback_per_category_per_period,
                    'min_transaction_amount' => $rule->min_transaction_amount,
                    'is_enabled' => $rule->is_enabled,
                    'note' => $rule->note,
                ]);
            }
        }
    }

    /**
     * Thay toàn bộ tier/rule bằng cấu trúc mới (dùng khi admin sửa cấu hình rồi
     * lưu thành version mới).
     *
     * @param  array<int, array<string, mixed>>  $tiers
     */
    private function replaceChildren(int $policyId, array $tiers): void
    {
        PolicyTierCategory::query()
            ->whereIn('tier_id', PolicyTier::query()->where('policy_id', $policyId)->select('id'))
            ->delete();

        PolicyTier::query()->where('policy_id', $policyId)->delete();

        foreach ($tiers as $index => $tier) {
            $newTier = PolicyTier::create([
                'policy_id' => $policyId,
                'name' => $tier['name'] ?? 'Bậc '.($index + 1),
                'sort_order' => $tier['sort_order'] ?? ($index + 1),
                'min_total_spend' => $tier['min_total_spend'] ?? 0,
                'max_total_spend' => $tier['max_total_spend'] ?? null,
            ]);

            foreach ($tier['categories'] ?? [] as $ruleIndex => $rule) {
                PolicyTierCategory::create([
                    'tier_id' => $newTier->id,
                    'category_id' => $rule['category_id'],
                    'name' => $rule['name'] ?? null,
                    'sort_order' => $rule['sort_order'] ?? ($ruleIndex + 1),
                    'spend_from' => $rule['spend_from'] ?? 0,
                    'spend_to' => $rule['spend_to'] ?? null,
                    'cashback_percent' => $rule['cashback_percent'] ?? 0,
                    'max_cashback_per_transaction' => $rule['max_cashback_per_transaction'] ?? null,
                    'max_cashback_per_category_per_period' => $rule['max_cashback_per_category_per_period'] ?? null,
                    'min_transaction_amount' => $rule['min_transaction_amount'] ?? null,
                    'is_enabled' => $rule['is_enabled'] ?? true,
                    'note' => $rule['note'] ?? null,
                ]);
            }
        }
    }

    /**
     * Xoá relation `currentPolicy` đã cache trên instance `$userCard`.
     *
     * Bắt buộc sau khi đổi `current_policy_id`: các method trong lớp này đọc
     * `$userCard->currentPolicy`, và Eloquent CACHE relation đã load. Nếu không
     * xoá, lần đọc tiếp theo trong cùng request vẫn trả về version CŨ — ví dụ
     * `createNextVersion()` rồi `saveAsTemplate()` trong cùng một luồng sẽ lưu
     * nhầm cấu hình của version trước đó.
     */
    private function forgetCurrentPolicy(UserCard $userCard): void
    {
        $userCard->unsetRelation('currentPolicy');
    }

    private function dayBefore(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->subDay()->toDateString();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'template';
        $slug = $base;
        $suffix = 1;

        while (PolicyTemplate::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
