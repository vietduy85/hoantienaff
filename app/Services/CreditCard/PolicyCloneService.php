<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryCombo;
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
use InvalidArgumentException;
use LogicException;

/**
 * PolicyCloneService — mọi thao tác "sao chép" policy đều ĐI QUA đây.
 *
 * Các nghiệp vụ dùng chung đúng một cơ chế deep clone:
 *
 *  1. `attachTemplateToCard()`      — chọn template cho thẻ: copy blueprint
 *     (policy + tiers + rules) thành chuỗi version riêng của thẻ.
 *  2. `saveAsTemplate()`            — lưu policy của thẻ thành template mới.
 *  3. `createNextVersion()`         — tạo version N+1 khi policy đổi.
 *  4. `createSystemPolicyFromEditor()` — sao chép chính sách hệ thống thành
 *     template mới từ payload editor (clone có chỉnh sửa); `cloneSystemPolicy()`
 *     là lớp mỏng gọi engine này khi không có thay đổi cấu hình.
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
    public function __construct(
        private readonly CategoryRuleService $rules,
        private readonly TierService $tiers,
        private readonly CategoryComboService $combos,
    ) {}

    /**
     * Gắn template lên thẻ: tạo chuỗi version version 1 của thẻ từ blueprint.
     *
     * @param  array<string, mixed>  $overrides  Khi có `tiers`, blueprint KHÔNG được
     *                                           copy nguyên xi mà được ghi bằng cấu hình
     *                                           người dùng đã sửa ở màn hình thêm/sửa
     *                                           thẻ. Vẫn là MỘT version: nếu clone rồi mới
     *                                           áp override thì sẽ dựng version 1 rỗng +
     *                                           version 2 trong cùng một lần bấm "Lưu thẻ",
     *                                           gây nhiễu lịch sử version.
     * @return PolicyVersion version 1 vừa tạo
     */
    public function attachTemplateToCard(
        UserCard $userCard,
        PolicyTemplate $template,
        DateTimeInterface $effectiveFrom,
        ?string $name = null,
        array $overrides = [],
    ): PolicyVersion {
        return DB::connection('creditcard')->transaction(function () use ($userCard, $template, $effectiveFrom, $name, $overrides): PolicyVersion {
            $blueprint = $template->defaultBlueprint();

            if ($blueprint === null) {
                throw new LogicException("Template \"{$template->name}\" chưa có policy blueprint nào để sao chép.");
            }

            $root = $this->insertPolicyRoot([
                'user_card_id' => $userCard->id,
                'template_id' => $template->id,
                'name' => $overrides['name'] ?? $name ?? $blueprint->name,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'min_total_spend' => $overrides['min_total_spend'] ?? $blueprint->min_total_spend,
                'max_cashback_total_per_period' => array_key_exists('max_cashback_total_per_period', $overrides)
                    ? $overrides['max_cashback_total_per_period']
                    : $blueprint->max_cashback_total_per_period,
                'rounding_mode' => $overrides['rounding_mode'] ?? $blueprint->rounding_mode,
                'status' => Policy::STATUS_ACTIVE,
                'note' => "Sao chép từ template \"{$template->name}\" (#{$template->id}).",
            ]);

            if (isset($overrides['tiers'])) {
                $this->replaceChildren($root->id, $overrides['tiers']);
            } else {
                // `$userCard->user_id` ⇒ combo hệ thống được snapshot thành bản sao
                // scope `user` (xem `mapComboForClone`).
                $this->copyChildren($blueprint->id, $root->id, (int) $userCard->user_id);
            }

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
     * Tạo một blueprint mới cho TEMPLATE (append-only, giống `createNextVersion`).
     *
     * Mục đích: admin đổi cấu hình một chính sách hệ thống ⇒ tạo blueprint N+1,
     * KHÔNG sửa blueprint cũ. Thẻ đã clone từ blueprint cũ không bị ảnh hưởng; thẻ
     * clone từ thời điểm sau sẽ nhận blueprint mới. Version cũ chỉ bị đóng
     * `effective_to` + chuyển `superseded`.
     *
     * Khi `$sourceBlueprintId` được truyền vào (luồng "Chỉnh sửa version N"),
     * version mới được tạo từ CHÍNH version đó — copy đúng bậc/rule của version
     * được sửa cộng các thay đổi trong `$overrides` — thay vì từ current/latest.
     * Version_no vẫn được cấp tiếp nối trên chuỗi (max+1) và blueprint current
     * vẫn bị đóng như thường lệ. Blueprint nguồn phải thuộc template này.
     *
     * Với template MỚI (chưa có blueprint): tạo version 1 (root tự trỏ về chính nó).
     *
     * @param  array<string, mixed>  $overrides
     */
    public function createTemplateBlueprint(
        PolicyTemplate $template,
        DateTimeInterface $effectiveFrom,
        array $overrides = [],
        ?int $sourceBlueprintId = null,
    ): PolicyVersion {
        return DB::connection('creditcard')->transaction(function () use ($template, $effectiveFrom, $overrides, $sourceBlueprintId): PolicyVersion {
            $previous = $template->currentBlueprint();

            if ($previous !== null && $previous->is_locked) {
                throw new LogicException('Blueprint hiện tại đang bị khoá, không tạo version mới được.');
            }

            if ($sourceBlueprintId !== null && $sourceBlueprintId !== $previous?->id) {
                $source = PolicyVersion::query()
                    ->where('id', $sourceBlueprintId)
                    ->where('template_id', $template->id)
                    ->whereNull('user_card_id')
                    ->first();

                if ($source === null) {
                    throw new LogicException('Version nguồn không thuộc template này.');
                }

                $latest = $source;
            } else {
                // Không có source cụ thể: nguồn là current/latest như hành vi trước đây.
                $latest = $previous;
            }

            if ($previous === null) {
                $rootPolicyId = null;
                $versionNo = 1;
                $latest = null;
            } else {
                $root = $previous->isRoot() ? $previous : $previous->root;
                $rootPolicyId = $root->id;
                $versionNo = (int) PolicyVersion::query()
                    ->where(function ($query) use ($root): void {
                        $query->where('id', $root->id)->orWhere('root_policy_id', $root->id);
                    })
                    ->max('version_no') + 1;
            }

            $newBlueprint = $this->insertPolicyRoot([
                'user_card_id' => null,
                'template_id' => $template->id,
                'name' => $overrides['name'] ?? ($latest?->name ?? $template->name),
                'effective_from' => $effectiveFrom,
                'effective_to' => $overrides['effective_to'] ?? null,
                'min_total_spend' => $overrides['min_total_spend'] ?? ($latest?->min_total_spend ?? '0.00'),
                'max_cashback_total_per_period' => array_key_exists('max_cashback_total_per_period', $overrides)
                    ? $overrides['max_cashback_total_per_period']
                    : ($latest?->max_cashback_total_per_period ?? null),
                'rounding_mode' => $overrides['rounding_mode'] ?? ($latest?->rounding_mode ?? 'round'),
                'status' => Policy::STATUS_ACTIVE,
                'note' => $overrides['note'] ?? 'Blueprint version '.$versionNo.' của template "'.$template->name.'".',
                'root_policy_id' => $rootPolicyId,
                'version_no' => $versionNo,
            ]);

            if (isset($overrides['tiers'])) {
                $this->replaceChildren($newBlueprint->id, $overrides['tiers']);
            } elseif ($latest !== null) {
                $this->copyChildren($latest->id, $newBlueprint->id);
            } else {
                // Template mới không kèm cấu hình: tạo tối thiểu một bậc để policy
                // luôn resolve được tier (giống `createFromScratch` của user).
                $this->replaceChildren($newBlueprint->id, [[
                    'name' => 'Bậc cơ bản',
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                ]]);
            }

            // Đóng blueprint cũ — CHỈ metadata vòng đời, không sửa business rule.
            if ($previous !== null && $previous->id !== $newBlueprint->id) {
                $previous->forceFill([
                    'effective_to' => $this->dayBefore($effectiveFrom),
                    'status' => Policy::STATUS_SUPERSEDED,
                ])->save();
            }

            return $newBlueprint;
        });
    }

    /**
     * CLONE TOÀN BỘ chính sách hệ thống thành một template hệ thống MỚI, độc lập.
     *
     * Deep-clone: template → mọi blueprint (PolicyVersion) → tier → category rule.
     * Từng bản ghi clone nhận ID MỚI, KHÔNG trỏ về bản ghi nguồn — khác với việc
     * updateSystemVersion sửa cấu hình (không tạo identity mới), ở đây MỌI identity
     * đều mới nên không thể ảnh hưởng chéo tới chính sách gốc hay thẻ đã clone.
     *
     *   - `version_no`, `status`, `effective_from/to`, business rules: giữ nguyên.
     *   - `is_locked` luôn về `false` (bản clone mới chưa có kỳ nào finalize).
     *   - `default_version_id` được remap sang blueprint clone tương ứng.
     *
     * Chỉ nhận template hệ thống (scope = 'system').
     *
     * Lớp mỏng giữ giao diện clone "nguyên trạng"; mọi nghiệp vụ clone nằm trong
     * `createSystemPolicyFromEditor()` (cũng là luồng admin mở editor rồi lưu).
     */
    public function cloneSystemPolicy(PolicyTemplate $source, string $name): PolicyTemplate
    {
        return $this->createSystemPolicyFromEditor(
            $source,
            $name,
            $source->description,
            (bool) $source->is_active,
            now(),
            [],
            null,
        );
    }

    /**
     * Tạo một chính sách hệ thống MỚI từ editor clone.
     *
     * Đây là ENGINE chung cho mọi luồng "sao chép chính sách hệ thống":
     *
     *   1. Deep-clone toàn bộ blueprint (version) của `$source` — mọi identity mới,
     *      `is_locked` về `false`, chuỗi root/version_no giữ nguyên, TÀI NGUYÊN của
     *      chính sách gốc KHÔNG bị đụng tới.
     *   2. Overlay cấu hình blueprint đang chỉnh sửa (trỏ bởi `$sourceBlueprintId`
     *      — id blueprint mà editor hydrate từ đó) bằng payload editor: `tiers`
     *      thay toàn bộ (trần hoàn mỗi kỳ giờ nằm trong `tiers[].max_cashback_per_period`),
     *      kèm `effective_from`, `min_total_spend`, `rounding_mode`. Các blueprint
     *      còn lại được chép nguyên trạng.
     *   3. Metadata template mới (`name`, `description`, `is_active`) lấy từ payload;
     *      slug tự sinh; `sort_order` nối tiếp các template hệ thống.
     *   4. `default_version_id` remap sang blueprint clone tương ứng. Nếu blueprint
     *      đang sửa CHÍNH LÀ default của nguồn, default của bản clone trỏ về bản
     *      clone đã overlay cấu hình mới — tức bản clone dùng hiệu lực ngay.
     *
     * Toàn bộ nằm trong MỘT transaction: fail giữa chừng → rollback sạch, không để
     * lại template/blueprint dở dang. Danh mục phải thuộc scope hệ thống (giống
     * `PolicyService::createSystemTemplate` / `createSystemVersion`).
     *
     * @param  array<string, mixed>  $overrides  cấu hình blueprint đang sửa (tiers[], min_total_spend, ...)
     */
    public function createSystemPolicyFromEditor(
        PolicyTemplate $source,
        string $name,
        ?string $description,
        bool $isActive,
        DateTimeInterface $effectiveFrom,
        array $overrides = [],
        ?int $sourceBlueprintId = null,
    ): PolicyTemplate {
        if (! $source->isSystemScope()) {
            throw new InvalidArgumentException('Chỉ được clone chính sách hệ thống.');
        }

        $this->assertSystemOnlyCategories(array_key_exists('tiers', $overrides) ? $overrides['tiers'] : []);

        return DB::connection('creditcard')->transaction(function () use ($source, $name, $description, $isActive, $effectiveFrom, $overrides, $sourceBlueprintId): PolicyTemplate {
            if (! $source->blueprints()->exists()) {
                throw new LogicException('Chính sách hệ thống chưa có version nào để clone.');
            }

            $edited = null;

            if ($sourceBlueprintId !== null) {
                $edited = $source->blueprints()
                    ->where('id', $sourceBlueprintId)
                    ->whereNull('user_card_id')
                    ->first();

                if ($edited === null) {
                    throw new LogicException('Version nguồn không thuộc chính sách này.');
                }
            }

            $template = PolicyTemplate::create([
                'scope' => PolicyTemplate::SCOPE_SYSTEM,
                'owner_user_id' => PolicyTemplate::SYSTEM_OWNER_ID,
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'description' => $description,
                'is_builtin' => false,
                'is_active' => $isActive,
                'sort_order' => ((int) PolicyTemplate::query()
                    ->where('scope', PolicyTemplate::SCOPE_SYSTEM)
                    ->max('sort_order')) + 1,
            ]);

            $idMap = [];
            $clonedRootId = null;

            foreach ($source->blueprints()->orderBy('version_no')->get() as $blueprint) {
                $isEdited = $edited !== null && (int) $edited->id === (int) $blueprint->id;

                $cloned = $this->insertPolicyRoot([
                    'user_card_id' => null,
                    'template_id' => $template->id,
                    'root_policy_id' => $clonedRootId,
                    'version_no' => (int) $blueprint->version_no,
                    'status' => $blueprint->status,
                    'name' => $isEdited ? $name : $blueprint->name,
                    'effective_from' => $isEdited
                        ? CarbonImmutable::instance($effectiveFrom)->toDateString()
                        : $blueprint->effective_from?->toDateString(),
                    'effective_to' => $blueprint->effective_to?->toDateString(),
                    'min_total_spend' => $isEdited && array_key_exists('min_total_spend', $overrides)
                        ? $overrides['min_total_spend']
                        : $blueprint->min_total_spend,
                    'max_cashback_total_per_period' => $isEdited && array_key_exists('max_cashback_total_per_period', $overrides)
                        ? $overrides['max_cashback_total_per_period']
                        : $blueprint->max_cashback_total_per_period,
                    'rounding_mode' => $isEdited && array_key_exists('rounding_mode', $overrides)
                        ? $overrides['rounding_mode']
                        : $blueprint->rounding_mode,
                    'note' => $blueprint->note,
                    'is_locked' => false,
                ]);

                $idMap[$blueprint->id] = $cloned->id;
                $clonedRootId ??= $cloned->id;

                if ($isEdited && array_key_exists('tiers', $overrides)) {
                    $this->replaceChildren($cloned->id, $overrides['tiers']);
                } else {
                    $this->copyChildren($blueprint->id, $cloned->id);
                }
            }

            // Remap default: cùng `version_no` với default của nguồn. Nếu blueprint
            // đang sửa là default, default của bản clone là chính bản clone đó (đã
            // overlay cấu hình mới từ editor).
            $editedClone = $edited !== null ? ($idMap[$edited->id] ?? null) : null;
            $sourceDefaultId = $source->default_version_id
                ? ($idMap[$source->default_version_id] ?? null)
                : null;

            if ($edited !== null && $source->default_version_id !== null
                && (int) $source->default_version_id === (int) $edited->id && $editedClone !== null) {
                $defaultId = $editedClone;
            } elseif ($sourceDefaultId !== null) {
                $defaultId = $sourceDefaultId;
            } else {
                $defaultId = (int) $template->blueprints()->orderBy('version_no')->value('id');
            }

            $template->forceFill(['default_version_id' => $defaultId])->save();

            return $template->refresh();
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
    private function copyChildren(int $sourcePolicyId, int $targetPolicyId, ?int $targetUserId = null): void
    {
        $tiers = PolicyTier::query()
            ->where('policy_id', $sourcePolicyId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // Cache combo đã snapshot trong lượt clone này: cùng một combo hệ thống
        // dùng ở nhiều bậc chỉ tạo MỘT bản sao user, và các bậc đó dùng chung
        // bản sao — nếu không, mỗi bậc sẽ tạo một combo trùng lặp.
        $comboCache = [];

        foreach ($tiers as $tier) {
            $newTier = PolicyTier::create([
                'policy_id' => $targetPolicyId,
                'name' => $tier->name,
                'sort_order' => $tier->sort_order,
                'min_total_spend' => $tier->min_total_spend,
                'max_total_spend' => $tier->max_total_spend,
                'max_cashback_per_period' => $tier->max_cashback_per_period,
            ]);

            // Cap động là tài sản của BẬC: copy theo bậc sang bản ghi MỚI (không
            // tham chiếu dòng con của nguồn — "Lưu phiên bản mới" + clone).
            $this->tiers->syncTransactionCaps($newTier, $this->tiers->capsPayload($tier));

            $rules = PolicyTierCategory::query()
                ->where('tier_id', $tier->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            foreach ($rules as $rule) {
                $newRule = PolicyTierCategory::create([
                    'tier_id' => $newTier->id,
                    'category_id' => $rule->category_id,
                    'combo_id' => $this->mapComboForClone($rule, $targetUserId, $comboCache),
                    'scope_type' => $rule->scope_type ?? PolicyTierCategory::SCOPE_CATEGORY,
                    'counts_toward_tier_cap' => $rule->scope_type === PolicyTierCategory::SCOPE_OTHER
                        ? false
                        : (bool) ($rule->counts_toward_tier_cap ?? true),
                    // Cờ quota là CẤU HÌNH của policy version nên clone phải mang
                    // nguyên (§1.7). `isQuotaCategory()` ép false cho fallback kể cả
                    // khi cột bị bẩn — clone không được nhân bản dữ liệu sai.
                    'is_quota_category' => $rule->isQuotaCategory(),
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
     * `combo_id` của rule sau khi deep-clone sang policy của thẻ.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO PHẢI SNAPSHOT COMBO (đã chốt ở audit, Q3/Q4)
     * ---------------------------------------------------------------------------
     * `Category` được dùng CHUNG master row giữa blueprint và user policy, vì
     * `category_id` là danh tính bất biến: đổi tên không đổi hành vi cashback.
     *
     * Combo thì KHÁC: membership của nó là nội dung mang behavior. Nếu user policy
     * trỏ thẳng vào combo hệ thống, thì một lần sửa membership của admin sẽ đổi
     * cashback của user policy ĐÃ CLONE — vi phạm đúng bất biến §13 mà cả module
     * dựa vào ("sửa policy của A ⇒ B đổi theo — không ai muốn điều này").
     *
     * Nên khi clone sang policy của thẻ, combo được copy thành bản ghi `scope=user`
     * của chính user đó (membership snapshot) và rule trỏ vào bản sao. Lịch sử
     * cashback vì thế bất biến trước mọi thay đổi ở phía hệ thống.
     *
     * `$comboCache` đảm bảo một combo hệ thống chỉ bị copy MỘT lần cho cả policy
     * (dùng ở nhiều bậc), tránh sinh combo trùng lặp.
     *
     * @param  array<string, CategoryCombo>  $comboCache
     */
    private function mapComboForClone(PolicyTierCategory $rule, ?int $targetUserId, array &$comboCache): ?int
    {
        if ($rule->combo_id === null) {
            return null;
        }

        // Clone giữa hai policy CÙNG phạm vi (system→system, user→user): combo đã
        // thuộc đúng owner rồi nên tham chiếu chung là đúng, không cần copy.
        if ($targetUserId === null) {
            return (int) $rule->combo_id;
        }

        $source = CategoryCombo::query()->whereKey($rule->combo_id)->first();

        if ($source === null) {
            throw new LogicException("Combo #{$rule->combo_id} không tồn tại nên không thể sao chép quy tắc.");
        }

        // Combo đã là của chính user đích (vd clone version N+1 từ version N):
        // giữ nguyên, KHÔNG copy — copy sẽ sinh bản trùng mỗi lần lưu version.
        if (! $source->isSystem() && (int) $source->owner_user_id === $targetUserId) {
            return (int) $source->id;
        }

        $clone = $this->combos->cloneForUser(
            $source,
            $targetUserId,
            $source->name.' (từ thẻ)',
            $comboCache,
        );

        return (int) $clone->id;
    }

    /**
     * Thay toàn bộ tier/rule bằng cấu trúc mới (dùng khi admin sửa cấu hình rồi
     * lưu thành version mới).
     *
     * @param  array<int, array<string, mixed>>  $tiers
     */
    private function replaceChildren(int $policyId, array $tiers): void
    {
        // Bất biến §1.5 "mọi rule tính hạn mức phải cùng một bậc" — kiểm TRƯỚC khi
        // xoá: hàm này xoá sạch rule cũ rồi dựng lại, kiểm sau là quá muộn và policy
        // đã mất cấu hình.
        $this->rules->assertQuotaCategoryTierUniqueness($tiers);

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
                'max_cashback_per_period' => $tier['max_cashback_per_period'] ?? null,
            ]);

            // Cap động của bậc (đã xoá all child ở đầu hàm nên không lo rò rỉ cũ).
            $this->tiers->syncTransactionCaps($newTier, $tier['transaction_caps'] ?? []);

            // Fallback từ payload chỉ được tạo MỘT lần, sau đó phủ cấu hình lên
            // fallback của bậc. Bậc không có fallback trong payload vẫn được đảm
            // bảo một fallback mặc định (bất biến).
            $fallbackConfig = null;

            foreach ($tier['rules'] ?? [] as $ruleIndex => $rule) {
                $ruleScope = $rule['scope_type'] ?? PolicyTierCategory::SCOPE_CATEGORY;

                if ($ruleScope === PolicyTierCategory::SCOPE_OTHER) {
                    if ($fallbackConfig === null) {
                        $fallbackConfig = $rule;
                    }

                    continue;
                }

                $created = PolicyTierCategory::create([
                    'tier_id' => $newTier->id,
                    'category_id' => $rule['category_id'] ?? null,
                    'combo_id' => $rule['combo_id'] ?? null,
                    'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                    'counts_toward_tier_cap' => (bool) ($rule['counts_toward_tier_cap'] ?? true),
                    'is_quota_category' => (bool) ($rule['is_quota_category'] ?? false),
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

            $this->rules->ensureSingleFallback($newTier, $fallbackConfig);
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

    public function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'template';
        $slug = $base;
        $suffix = 1;

        while (PolicyTemplate::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    /**
     * Blueprint hệ thống CHỈ được tham chiếu danh mục hệ thống và combo hệ thống
     * đang hoạt động.
     *
     * Cùng chặn phạm vi như `PolicyService::assertSystemOnlyCategories()` — payload
     * editor là JSON tùy ý, nếu thiếu chặn này thì bản clone hệ thống có thể trỏ
     * vào danh mục/combo riêng của người dùng, phá vỡ cô lập tài nguyên.
     *
     * @param  array<int, array<string, mixed>>  $tiers
     */
    private function assertSystemOnlyCategories(array $tiers): void
    {
        $rules = collect($tiers)->flatMap(fn (array $tier) => collect($tier['rules'] ?? []));

        $ids = $rules->pluck('category_id')
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($ids->isNotEmpty()) {
            $valid = Category::query()
                ->system()
                ->active()
                ->whereIn('id', $ids->all())
                ->pluck('id')
                ->map(fn ($id) => (int) $id);

            $missing = $ids->diff($valid);

            if ($missing->isNotEmpty()) {
                throw new InvalidArgumentException(
                    'Blueprint hệ thống chỉ được dùng danh mục hệ thống đang hoạt động (#'.$missing->implode(', #').').'
                );
            }
        }

        // Combo trong blueprint phải là combo HỆ THỐNG đang hoạt động — combo
        // riêng của user không được nằm trong chính sách hệ thống.
        $comboIds = $rules->pluck('combo_id')
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($comboIds->isEmpty()) {
            return;
        }

        $validCombos = CategoryCombo::query()
            ->system()
            ->active()
            ->whereIn('id', $comboIds->all())
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $missingCombos = $comboIds->diff($validCombos);

        if ($missingCombos->isNotEmpty()) {
            throw new InvalidArgumentException(
                'Blueprint hệ thống chỉ được dùng combo hệ thống đang hoạt động (#'.$missingCombos->implode(', #').').'
            );
        }
    }
}
