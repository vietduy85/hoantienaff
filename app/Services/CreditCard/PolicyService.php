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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * PolicyService — điều phối các thao tác lên Card Policy của một thẻ.
 *
 * Lớp này là FACADE mỏng: mọi thao tác "tạo bản sao" đều ủy quyền cho
 * `PolicyCloneService` (deep clone Policy → Version → Tiers → Category Rules).
 * Không có logic clone thứ hai ở đây, để tránh hai nơi cùng quyết định "có share
 * child record hay không".
 *
 * ---------------------------------------------------------------------------
 * CÁC ĐƯỜNG VÀO
 * ---------------------------------------------------------------------------
 *  1. `createFromScratch()`   — user tự dựng policy, không qua template.
 *  2. `cloneSystemTemplate()` — chọn 1 template hệ thống.
 *  3. `cloneUserTemplate()`   — chọn template riêng của chính user đó.
 *  4. `createVersion()`       — sửa cấu hình ⇒ sinh version N+1.
 *  5. `saveAsUserTemplate()`  — lưu cấu hình hiện tại thành template riêng.
 *
 * ---------------------------------------------------------------------------
 * VERSIONING LÀ APPEND-ONLY
 * ---------------------------------------------------------------------------
 * Không có `update()` sửa thẳng business rule của version đang active. Mọi thay
 * đổi đều tạo version mới; version cũ chỉ bị đóng `effective_to` + chuyển
 * `superseded`. Nhờ vậy giao dịch của kỳ cũ vẫn resolve đúng version CŨ về sau.
 *
 * Ngoại lệ duy nhất: sửa metadata của chính version CHƯA dùng cho kỳ nào
 * (chưa bị khoá, chưa superseded) — xem `rename()`.
 */
class PolicyService
{
    public function __construct(
        private readonly PolicyCloneService $cloner,
        private readonly PolicyEngineService $engine,
        private readonly TierService $tiers,
        private readonly CategoryRuleService $rules,
    ) {}

    /**
     * Tạo policy version 1 từ đầu, không qua template.
     *
     * @param  array<string, mixed>  $attributes
     *                                            name, min_total_spend, max_cashback_total_per_period, rounding_mode,
     *                                            effective_from, tiers[]
     */
    public function createFromScratch(
        UserCard $userCard,
        DateTimeInterface $effectiveFrom,
        array $attributes,
    ): PolicyVersion {
        $this->assertCardUsable($userCard);

        return DB::connection('creditcard')->transaction(function () use ($userCard, $effectiveFrom, $attributes): PolicyVersion {
            $policy = new PolicyVersion;
            $policy->forceFill([
                'user_card_id' => $userCard->id,
                'template_id' => null,
                'version_no' => 1,
                'root_policy_id' => null,
                'status' => Policy::STATUS_ACTIVE,
                'name' => $attributes['name'] ?? 'Chính sách cashback',
                'effective_from' => CarbonImmutable::instance($effectiveFrom)->toDateString(),
                'effective_to' => null,
                'min_total_spend' => $this->money($attributes['min_total_spend'] ?? 0),
                'max_cashback_total_per_period' => $this->money($attributes['max_cashback_total_per_period'] ?? null),
                'rounding_mode' => $this->rounding($attributes['rounding_mode'] ?? 'round'),
                'note' => $attributes['note'] ?? null,
            ]);
            $policy->save();

            // root tự trỏ về chính nó.
            $policy->forceFill(['root_policy_id' => $policy->id])->save();

            // Tạo ít nhất một bậc, nếu không sẽ không bao giờ resolve được tier.
            // `TierService::create()` tự đảm bảo mỗi bậc có fallback "các danh mục còn lại".
            $tiers = $attributes['tiers'] ?? [[
                'name' => 'Bậc cơ bản',
                'sort_order' => 1,
                'min_total_spend' => 0,
                'max_total_spend' => null,
            ]];

            $this->insertTiers($policy, $tiers);

            $userCard->forceFill([
                'current_policy_id' => $policy->id,
                'status' => UserCard::STATUS_ACTIVE,
            ])->save();
            $userCard->unsetRelation('currentPolicy');

            return $policy->refresh();
        });
    }

    /**
     * Chọn System Template: user clone, KHÔNG được sửa template gốc.
     *
     * Bắt buộc phải là template hệ thống. Nếu không kiểm scope ở đây thì caller
     * chỉ cần đổi đường vào (gọi `cloneUserTemplate` bằng id template riêng của
     * user khác... hoặc ngược lại, gọi hàm này bằng id template riêng) là bypass
     * được kiểm tra sở hữu. `cloneTemplate()` cố tình KHÔNG kiểm scope vì nó
     * phục vụ cả hai đường vào.
     */
    public function cloneSystemTemplate(
        UserCard $userCard,
        int $templateId,
        DateTimeInterface $effectiveFrom,
        ?string $name = null,
        array $overrides = [],
    ): PolicyVersion {
        $template = PolicyTemplate::query()->whereKey($templateId)->first();

        if ($template === null) {
            throw new InvalidArgumentException("Template #{$templateId} không tồn tại.");
        }

        if (! $template->isSystemScope()) {
            throw new InvalidArgumentException('Chỉ được chọn template hệ thống tại đây.');
        }

        return $this->cloneTemplate($userCard, $templateId, $effectiveFrom, $name, $overrides);
    }

    /**
     * Chọn User Template: CHỈ được dùng template thuộc chính user sở hữu thẻ.
     *
     * KHÔNG nhận tham số user id từ caller. Lý do: nếu nhận, chỉ cần truyền
     * nhầm một id khác là bypass được kiểm tra sở hữu. User id luôn lấy từ
     * `$userCard->user_id` — thứ không thể bị caller tự khai sai mà vẫn gắn được
     * thẻ.
     */
    public function cloneUserTemplate(
        UserCard $userCard,
        int $templateId,
        DateTimeInterface $effectiveFrom,
        ?string $name = null,
        array $overrides = [],
    ): PolicyVersion {
        $template = PolicyTemplate::query()->whereKey($templateId)->first();

        if ($template === null) {
            throw new InvalidArgumentException("Template #{$templateId} không tồn tại.");
        }

        if ($template->isSystemScope()) {
            return $this->cloneTemplate($userCard, $templateId, $effectiveFrom, $name, $overrides);
        }

        if (! $template->isOwnedBy((int) $userCard->user_id)) {
            throw new InvalidArgumentException('Bạn chỉ được dùng template của chính mình.');
        }

        return $this->cloneTemplate($userCard, $templateId, $effectiveFrom, $name, $overrides);
    }

    /**
     * Sửa cấu hình ⇒ TẠO VERSION MỚI, không sửa version cũ.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function createVersion(UserCard $userCard, DateTimeInterface $effectiveFrom, array $overrides = []): PolicyVersion
    {
        $this->assertCardUsable($userCard);

        $version = $this->cloner->createNextVersion($userCard, $effectiveFrom, $overrides);

        $this->engine->currentVersion($userCard);

        return $version;
    }

    /**
     * Sửa policy RIÊNG của thẻ ⇒ ghi ĐÈ version đang chạy, KHÔNG tạo version mới.
     *
     * ---------------------------------------------------------------------------
     * KHÁC `createVersion()` Ở ĐÂY
     * ---------------------------------------------------------------------------
     * `createVersion()` là luồng APPEND-ONLY: version cũ giữ nguyên, version mới
     * mở `effective_from` mới. Đó là điều đúng cho SYSTEM POLICY (thay đổi luật
     * phải giữ được lịch sử) và cho việc thêm version thủ công.
     *
     * Màn "Sửa thẻ" thì khác: người dùng đang chỉnh CẤU HÌNH RIÊNG CỦA MÌNH, và
     * mỗi lần bấm "Lưu thẻ" lại sinh version mới khiến lịch sử đầy phiên bản trùng
     * nội dung. Nên ở đây version đang chạy được sửa tại chỗ.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO VẪN AN TOÀN
     * ---------------------------------------------------------------------------
     *   - Chỉ đụng version của CHÍNH thẻ này. Template hệ thống / template của user
     *     là bản ghi khác hẳn (thẻ luôn clone, không tham chiếu trực tiếp), nên
     *     sửa ở đây không chạm nguồn và không đụng lịch sử version của System Policy.
     *   - `syncTiers()`/`syncRules()` GIỮ `id` của bậc/rule còn tồn tại, chỉ thêm
     *     dòng mới và xoá dòng bị gỡ. Nhờ vậy giao dịch đã finalize vẫn trỏ đúng
     *     rule cũ, và tiền hoàn đã ghi (`*_snapshot`) không bị đụng.
     *   - Version `is_locked` (kỳ đã finalize) hoặc `superseded` bị từ chối: những
     *     version đó thuộc lịch sử, sửa là phá invariant.
     *
     * `effective_from` CỐ Ý KHÔNG đổi: version đã chạy từ ngày nào thì giữ nguyên,
     * nếu không giao dịch kỳ trước sẽ bị áp nhầm cấu hình mới.
     *
     * @param  array<string, mixed>  $overrides  `name` (tuỳ chọn) + `tiers`
     */
    public function updateCurrentVersionInPlace(UserCard $userCard, array $overrides = []): PolicyVersion
    {
        $this->assertCardUsable($userCard);

        $current = $userCard->currentPolicy;

        if ($current === null) {
            throw new LogicException('Thẻ chưa có policy nào để sửa.');
        }

        if ($current->is_locked) {
            throw new LogicException('Policy đang bị khoá (kỳ đã finalize), không sửa được.');
        }

        if ($current->status === Policy::STATUS_SUPERSEDED) {
            throw new LogicException('Policy version đã bị thay thế, không sửa được.');
        }

        return DB::connection('creditcard')->transaction(function () use ($userCard, $current, $overrides): PolicyVersion {
            // Khoá dòng version: hai request sửa thẻ song song không được ghi đè
            // lẫn nhau (mỗi request đều xoá/insert lại tier/rule).
            $version = PolicyVersion::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();

            $this->syncTiers($version, $overrides['tiers'] ?? []);

            if (array_key_exists('name', $overrides) && $overrides['name'] !== null) {
                $version->forceFill(['name' => trim((string) $overrides['name'])])->save();
            }

            $userCard->unsetRelation('currentPolicy');

            return $version->refresh();
        });
    }

    /**
     * Lưu policy hiện tại của thẻ thành User Template (deep clone ngược chiều).
     *
     * Template thuộc user sở hữu thẻ — suy ra từ thẻ, không nhận từ caller.
     */
    public function saveAsUserTemplate(
        UserCard $userCard,
        string $name,
        ?string $description = null,
    ): PolicyTemplate {
        return $this->cloner->saveAsTemplate($userCard, (int) $userCard->user_id, $name, $description);
    }

    /**
     * Admin TẠO MỚI một chính sách hệ thống (System Template + blueprint version 1).
     *
     * Template thuộc `scope = system`, owner là `SYSTEM_OWNER_ID`, KHÔNG ai sửa
     * được ngoài admin (§12). Blueprint đầu tiên được tạo ngay với cấu hình từ
     * `$overrides` (hoặc một bậc mặc định nếu bỏ trống).
     *
     * @param  array<string, mixed>  $overrides  name, min_total_spend, max_cashback_total_per_period, rounding_mode, tiers[]
     */
    public function createSystemTemplate(
        string $name,
        ?string $description,
        DateTimeInterface $effectiveFrom,
        array $overrides = [],
        bool $isActive = true,
    ): PolicyTemplate {
        $this->assertSystemOnlyCategories($overrides['tiers'] ?? []);

        return DB::connection('creditcard')->transaction(function () use ($name, $description, $effectiveFrom, $overrides, $isActive): PolicyTemplate {
            $template = PolicyTemplate::create([
                'scope' => PolicyTemplate::SCOPE_SYSTEM,
                'owner_user_id' => PolicyTemplate::SYSTEM_OWNER_ID,
                'name' => $name,
                'slug' => $this->cloner->uniqueSlug($name),
                'description' => $description,
                'is_builtin' => false,
                'is_active' => $isActive,
                'sort_order' => 0,
            ]);

            $blueprint = $this->cloner->createTemplateBlueprint($template, $effectiveFrom, $overrides);

            // Blueprint đầu tiên là mặc định: user mới nhận version nền tảng này
            // cho đến khi admin chủ động đặt phiên bản khác làm default.
            $template->forceFill(['default_version_id' => $blueprint->id])->save();

            return $template->refresh();
        });
    }

    /**
     * Admin đổi cấu hình chính sách hệ thống ⇒ tạo blueprint version N+1.
     *
     * Append-only giống version của thẻ: blueprint cũ bị đóng, KHÔNG được sửa.
     * `PolicyCloneService::createTemplateBlueprint()` lo việc clone + đóng version.
     *
     * Truyền `$sourceBlueprintId` khi luồng "Chỉnh sửa version N": version mới được
     * tạo từ CHÍNH version đó cộng `$overrides` thay vì từ current/latest.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function createSystemVersion(
        PolicyTemplate $template,
        DateTimeInterface $effectiveFrom,
        array $overrides = [],
        ?int $sourceBlueprintId = null,
    ): PolicyVersion {
        $this->assertSystemOnlyCategories($overrides['tiers'] ?? []);

        return $this->cloner->createTemplateBlueprint($template, $effectiveFrom, $overrides, $sourceBlueprintId);
    }

    /**
     * Admin "Lưu lại" — cập nhật IN-PLACE cấu hình của CHÍNH blueprint đang sửa.
     *
     * KHÔNG tạo version mới, KHÔNG đổi `version_no`, KHÔNG đổi default, KHÔNG
     * cascade sang user policy (thẻ đã clone là bản SAO độc lập). Tier/rule cũ
     * giữ nguyên `id` khi còn khớp với payload; rule vắng trong payload bị XÓA
     * (chỉ xóa dòng `PolicyTierCategory`, không bao giờ xóa Category Master).
     *
     * Ngược lại hẳn với "Lưu phiên bản mới" (route `versions.store` đi qua
     * `createSystemVersion()` + `createTemplateBlueprint()`): hai luồng này không
     * được trộn.
     *
     * @param  array<string, mixed>  $data  effective_from, min_total_spend, max_cashback_total_per_period, tiers[]
     */
    public function updateSystemVersion(
        PolicyTemplate $template,
        PolicyVersion $version,
        array $data,
    ): PolicyVersion {
        $this->assertSystemOnlyCategories($data['tiers'] ?? []);

        return DB::connection('creditcard')->transaction(function () use ($template, $version, $data): PolicyVersion {
            if ((int) $version->template_id !== (int) $template->id || $version->user_card_id !== null) {
                throw new InvalidArgumentException('Phiên bản không thuộc blueprint của chính sách này.');
            }

            if ($version->is_locked) {
                throw new LogicException('Phiên bản đã bị khoá (kỳ đã finalize), không cập nhật được.');
            }

            foreach (['effective_from', 'min_total_spend', 'max_cashback_total_per_period'] as $field) {
                if (array_key_exists($field, $data)) {
                    $value = in_array($field, ['min_total_spend', 'max_cashback_total_per_period'], true)
                        ? $this->money($data[$field])
                        : $data[$field];
                    $version->forceFill([$field => $value])->save();
                }
            }

            if (array_key_exists('tiers', $data)) {
                $this->syncTiers($version, $data['tiers']);
            }

            return $version->refresh();
        });
    }

    /**
     * Đặt một blueprint của template thành version MẶC ĐỊNH.
     *
     * Đây là nguồn clone cho user MỚI (thay cho khái niệm "latest" cũ). Bất kỳ
     * version nào thuộc blueprint của template đều có thể được chọn; cột scalar
     * `default_version_id` đảm bảo luôn chỉ có MỘT default.
     */
    public function setDefaultVersion(PolicyTemplate $template, int $versionId): PolicyVersion
    {
        return DB::connection('creditcard')->transaction(function () use ($template, $versionId): PolicyVersion {
            $blueprint = PolicyVersion::query()
                ->where('id', $versionId)
                ->where('template_id', $template->id)
                ->whereNull('user_card_id')
                ->first();

            if ($blueprint === null) {
                throw new InvalidArgumentException('Phiên bản không thuộc blueprint của chính sách này.');
            }

            $template->forceFill(['default_version_id' => $blueprint->id])->save();

            return $blueprint->refresh();
        });
    }

    /**
     * Xóa một blueprint (version) của template hệ thống.
     *
     * Quy tắc duy nhất chặn xóa: version đang là MẶC ĐỊNH (`default_version_id`).
     * Mọi trường hợp còn lại đều xóa được — kể cả version gốc của chuỗi, kể cả
     * version đã có thẻ/giao dịch/kỳ sao kê trỏ tới: thẻ nhận DEEP CLONE nên
     * policy của thẻ là bản SAO độc lập, không FK trực tiếp về blueprint.
     *
     * Khi xóa version gốc, `root_policy_id` của các version còn lại được remap sang
     * version thấp nhất còn lại (version mới tự trỏ về chính nó) để không còn
     * `root_policy_id` nào trỏ tới bản ghi đã xóa.
     */
    public function deleteSystemVersion(PolicyTemplate $template, PolicyVersion $version): void
    {
        if ((int) $version->template_id !== (int) $template->id || $version->user_card_id !== null) {
            throw new InvalidArgumentException('Phiên bản không thuộc blueprint của chính sách này.');
        }

        if ((int) $template->default_version_id === (int) $version->id) {
            throw new LogicException('Không thể xóa phiên bản MẶC ĐỊNH. Hãy đặt phiên bản khác làm mặc định trước.');
        }

        DB::connection('creditcard')->transaction(function () use ($version): void {
            // Chuỗi version được nhận diện bằng root. Bản ghi root luôn tự trỏ về
            // chính nó; fallback về chính id cho trường hợp legacy root_policy_id NULL.
            $oldRootId = (int) ($version->root_policy_id ?? $version->id);

            if ($version->isRoot()) {
                $chainVersions = PolicyVersion::query()
                    ->where(function ($query) use ($oldRootId): void {
                        $query->where('id', $oldRootId)->orWhere('root_policy_id', $oldRootId);
                    })
                    ->whereKeyNot($version->id)
                    ->orderBy('version_no')
                    ->orderBy('id')
                    ->get();

                if ($chainVersions->isNotEmpty()) {
                    $newRootId = (int) $chainVersions->first()->id;

                    // Version thấp nhất còn lại làm gốc mới và tự trỏ về chính nó;
                    // các version còn lại của chuỗi trỏ về gốc mới.
                    PolicyVersion::query()
                        ->where(function ($query) use ($oldRootId): void {
                            $query->where('id', $oldRootId)->orWhere('root_policy_id', $oldRootId);
                        })
                        ->whereKeyNot($version->id)
                        ->update(['root_policy_id' => $newRootId]);
                }
            }

            PolicyVersion::query()->whereKey($version->id)->delete();
        });
    }

    /**
     * Mô tả các tham chiếu khiến version không xóa được, dạng ["N giao dịch", ...].
     *
     * @return Collection<int, string>
     */
    public function versionUsageLabels(PolicyVersion $version): Collection
    {
        $counts = $version->usageCounts();

        $labels = collect();

        if ($counts['transactions'] > 0) {
            $labels->push($counts['transactions'].' giao dịch');
        }

        if ($counts['periods'] > 0) {
            $labels->push($counts['periods'].' kỳ sao kê');
        }

        if ($counts['cards'] > 0) {
            $labels->push($counts['cards'].' thẻ');
        }

        return $labels;
    }

    /**
     * Backfill trần hoàn mỗi kỳ cho blueprint hệ thống legacy (idempotent).
     *
     * Trước đây trần hoàn mỗi kỳ nằm ở POLICY
     * (`credit_card_policies.max_cashback_total_per_period`); nay nằm ở TỪNG BẬC
     * (`credit_card_policy_tiers.max_cashback_per_period`). Hàm này được gọi từ
     * migration 000014 để chuyển dữ liệu cũ:
     *
     *   - Blueprint có ĐÚNG MỘT bậc + trần policy ⇒ copy trần vào bậc (nếu bậc
     *     chưa có trần). Cột policy cũ giữ nguyên.
     *   - Blueprint NHIỀU bậc ⇒ không backfill (không biết trần thuộc bậc nào),
     *     trả về danh sách để vận hành xử lý sau.
     *
     * Chạy lại an toàn: bậc đã có trần thì bỏ qua.
     *
     * @return array{backfilled: int, skipped_multi_tier: array<int, int>}
     */
    public function backfillSystemBlueprintCaps(): array
    {
        $blueprints = PolicyVersion::query()
            ->whereNull('user_card_id')
            ->whereNotNull('max_cashback_total_per_period')
            ->with('tiers')
            ->get();

        $backfilled = 0;
        $skipped = [];

        foreach ($blueprints as $blueprint) {
            $tiers = $blueprint->tiers;

            if ($tiers->count() === 1) {
                $tier = $tiers->first();

                if ($tier->max_cashback_per_period === null) {
                    $tier->forceFill([
                        'max_cashback_per_period' => $blueprint->max_cashback_total_per_period,
                    ])->save();

                    $backfilled++;
                }

                continue;
            }

            $skipped[] = (int) $blueprint->id;
        }

        return [
            'backfilled' => $backfilled,
            'skipped_multi_tier' => array_values($skipped),
        ];
    }

    /**
     * Xoá bỏ con trỏ áp dụng khi template trỏ vào blueprint không còn tồn tại.
     *
     * Nội bộ: chỉ dùng khi phiên bản mặc định bị xóa (nullOnDelete) — đưa default
     * về trạng thái chưa đặt, hệ thống sẽ fallback về current/latest.
     */
    public function normaliseDefault(PolicyTemplate $template): PolicyTemplate
    {
        if ($template->default_version_id !== null && $template->defaultVersion === null) {
            $template->forceFill(['default_version_id' => null])->save();
        }

        return $template->refresh();
    }

    /**
     * Sửa METADATA của template hệ thống (tên, mô tả, trang thái xuất bản).
     *
     * Đây là dữ liệu vỏ ngoài của template, KHÔNG phải business rule của blueprint
     * (business rule chỉ đổi qua tạo version mới).
     */
    public function updateSystemMeta(PolicyTemplate $template, array $meta): PolicyTemplate
    {
        return DB::connection('creditcard')->transaction(function () use ($template, $meta): PolicyTemplate {
            foreach (['name', 'description', 'is_active'] as $field) {
                if (array_key_exists($field, $meta)) {
                    $value = $meta[$field];

                    if ($field === 'is_active') {
                        $value = (bool) $value;
                    }

                    if ($field === 'name') {
                        $value = trim((string) $value);
                    }

                    $template->forceFill([$field => $value])->save();
                }
            }

            return $template->refresh();
        });
    }

    /**
     * Toàn bộ blueprint (các version) của một template hệ thống, theo version_no.
     *
     * @return Collection<int, PolicyVersion>
     */
    public function systemVersions(PolicyTemplate $template): Collection
    {
        return $template->blueprints()->get();
    }

    /**
     * Đổi tên version CHƯA dùng cho kỳ nào.
     *
     * Chỉ cho đổi metadata khi version chưa bị khoá và chưa superseded. Mọi thay
     * đổi business rule (tỷ lệ, cap, khoảng bậc) KHÔNG đi qua đây — phải tạo
     * version mới.
     */
    public function rename(UserCard $userCard, int $policyId, string $name): PolicyVersion
    {
        $version = $this->findVersionOfCard($userCard, $policyId);

        if ($version->is_locked) {
            throw new LogicException('Policy version đã bị khoá, không được sửa.');
        }

        if ($version->status === Policy::STATUS_SUPERSEDED) {
            throw new LogicException('Policy version đã bị thay thế, không được sửa.');
        }

        $version->forceFill(['name' => trim($name)])->save();

        return $version->refresh();
    }

    /**
     * Toàn bộ version của thẻ, theo version_no.
     *
     * @return Collection<int, PolicyVersion>
     */
    public function versionsOf(UserCard $userCard): Collection
    {
        return $this->engine->versionsOf($userCard);
    }

    /**
     * Version hiện hành của thẻ.
     *
     * Quan hệ `currentPolicy` khai báo trỏ tới `Policy` (không phải `PolicyVersion`)
     * vì `Gate` tra policy theo tên class — xem `PolicyController::asVersion()`. Ở đây
     * nên trả về đúng kiểu mà quan hệ trả về, đừng hứa `PolicyVersion` rồi ném
     * TypeError.
     */
    public function currentVersion(UserCard $userCard): ?Policy
    {
        return $userCard->currentPolicy;
    }

    /**
     * Khoá version (kỳ đã finalize) — sau đó business rule bất biến vĩnh viễn.
     */
    public function lock(PolicyVersion $version): void
    {
        $this->engine->lockVersion($version);
    }

    /**
     * Tháo policy khỏi thẻ.
     *
     * KHÔNG xoá các bản ghi policy: giao dịch cũ đã snapshot `policy_version_id`.
     * Chỉ bỏ con trỏ `current_policy_id`; từ kỳ sau thẻ không còn policy ⇒ giao
     * dịch mới không có cashback thay vì áp nhầm cấu hình cũ.
     */
    public function detachFromCard(UserCard $userCard): void
    {
        DB::connection('creditcard')->transaction(function () use ($userCard): void {
            $userCard->forceFill(['current_policy_id' => null])->save();
            $userCard->unsetRelation('currentPolicy');
        });
    }

    /**
     * Deep clone một template bất kỳ lên thẻ.
     *
     * `PolicyCloneService` copy blueprint (policy + tiers + rules) thành chuỗi
     * version riêng của thẻ, nên thẻ KHÔNG BAO GIỜ tham chiếu trực tiếp bản ghi
     * của template. Sửa template sau này không ảnh hưởng thẻ đã dùng, và ngược
     * lại xoá thẻ cũng không làm hỏng template.
     */
    private function cloneTemplate(
        UserCard $userCard,
        int $templateId,
        DateTimeInterface $effectiveFrom,
        ?string $name,
        array $overrides = [],
    ): PolicyVersion {
        $this->assertCardUsable($userCard);

        $template = PolicyTemplate::query()->whereKey($templateId)->first();

        if ($template === null) {
            throw new InvalidArgumentException("Template #{$templateId} không tồn tại.");
        }

        if (! $template->is_active) {
            throw new InvalidArgumentException('Template này đã bị vô hiệu hoá.');
        }

        if ($template->defaultBlueprint() === null) {
            throw new LogicException("Template \"{$template->name}\" chưa có cấu hình để sao chép.");
        }

        return $this->cloner->attachTemplateToCard($userCard, $template, $effectiveFrom, $name, $overrides);
    }

    private function findVersionOfCard(UserCard $userCard, int $policyId): PolicyVersion
    {
        $version = PolicyVersion::query()->whereKey($policyId)->first();

        if ($version === null || (int) $version->user_card_id !== (int) $userCard->id) {
            throw new InvalidArgumentException('Policy version không thuộc thẻ này.');
        }

        return $version;
    }

    private function assertCardUsable(UserCard $userCard): void
    {
        if ($userCard->isClosed()) {
            throw new LogicException('Thẻ đã đóng nên không thể gắn policy.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $tiers
     */
    private function insertTiers(Policy $policy, array $tiers): void
    {
        if ($tiers === []) {
            throw new InvalidArgumentException('Policy phải có ít nhất một bậc chi tiêu.');
        }

        // Bất biến §1.5 "mọi rule tính hạn mức phải cùng một bậc" — kiểm TRÊN PAYLOAD
        // trước khi tạo bất kỳ dòng nào, nên policy không bao giờ bị dựng dở rồi mới
        // lỗi.
        $this->rules->assertQuotaCategoryTierUniqueness($tiers);

        foreach ($tiers as $index => $tier) {
            // Ủy quyền cho `TierService` thay vì tự `PolicyTier::create()`: nếu
            // insert thẳng ở đây thì các kiểm tra (khoảng đảo ngược, khoảng chồng
            // lấn) chỉ chạy khi user dùng đường `TierService`, còn policy dựng từ
            // đầu lại bỏ qua ⇒ hai đường vào cho ra hai bộ luật khác nhau.
            $created = $this->tiers->create($policy, [
                'name' => $tier['name'] ?? ('Bậc '.($index + 1)),
                'sort_order' => $tier['sort_order'] ?? ($index + 1),
                'min_total_spend' => $tier['min_total_spend'] ?? 0,
                'max_total_spend' => $tier['max_total_spend'] ?? null,
                'max_cashback_per_period' => $tier['max_cashback_per_period'] ?? null,
                'transaction_caps' => $tier['transaction_caps'] ?? [],
            ]);

            foreach ($tier['rules'] ?? [] as $rule) {
                // Tương tự: `CategoryRuleService` kiểm danh mục còn dùng được,
                // phần trăm hợp lệ và khoảng chi tiêu của rule.
                $ruleScope = $rule['scope_type'] ?? PolicyTierCategory::SCOPE_CATEGORY;

                // Fallback đã được `TierService::create()` tạo sẵn — payload chứa
                // fallback chỉ để ĐỌC, không tạo bản thứ hai.
                if ($ruleScope === PolicyTierCategory::SCOPE_OTHER) {
                    continue;
                }

                $this->rules->create($created, [
                    'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                    'category_id' => $rule['category_id'] ?? null,
                    'combo_id' => $rule['combo_id'] ?? null,
                    'counts_toward_tier_cap' => $rule['counts_toward_tier_cap'] ?? true,
                    'is_quota_category' => $rule['is_quota_category'] ?? false,
                    'name' => $rule['name'] ?? null,
                    'sort_order' => $rule['sort_order'] ?? null,
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
     * Đồng bộ tier của phiên bản với payload mới, GIỮ id của tier khi có thể.
     *
     * Tier vắng trong payload bị xóa (child rules xóa theo cascade của DB); tier
     * giữ `id` được cập nhật các trường; tier không có `id` được tạo mới.
     */
    private function syncTiers(Policy $policy, array $tiers): void
    {
        if ($tiers === []) {
            throw new InvalidArgumentException('Chính sách phải có ít nhất một bậc chi tiêu.');
        }

        // Bất biến §1.5 — kiểm trên TOÀN BỘ payload trước khi xoá/sửa dòng nào.
        $this->rules->assertQuotaCategoryTierUniqueness($tiers);

        $existing = $policy->tiers()->get()->keyBy('id');
        $incoming = collect($tiers)->values();
        $incomingIds = $incoming->pluck('id')->filter()->map(fn ($id): int => (int) $id)->all();

        foreach ($existing as $tierId => $tier) {
            if (! in_array($tierId, $incomingIds, true)) {
                $tier->delete();
                unset($existing[$tierId]);
            }
        }

        foreach ($incoming as $index => $tier) {
            $tierId = isset($tier['id']) && $tier['id'] !== null ? (int) $tier['id'] : null;
            $model = $tierId !== null && $existing->has($tierId) ? $existing->get($tierId) : null;

            if ($model === null) {
                $model = $this->tiers->create($policy, [
                    'name' => $tier['name'] ?? ('Bậc '.($index + 1)),
                    'sort_order' => $tier['sort_order'] ?? ($index + 1),
                    'min_total_spend' => $tier['min_total_spend'] ?? 0,
                    'max_total_spend' => $tier['max_total_spend'] ?? null,
                    'max_cashback_per_period' => $tier['max_cashback_per_period'] ?? null,
                    'transaction_caps' => $tier['transaction_caps'] ?? [],
                ]);
            } else {
                $model->forceFill([
                    'name' => $tier['name'] ?? $model->name,
                    'sort_order' => $tier['sort_order'] ?? ($index + 1),
                    'min_total_spend' => array_key_exists('min_total_spend', $tier)
                        ? (float) $tier['min_total_spend']
                        : $model->min_total_spend,
                    'max_total_spend' => array_key_exists('max_total_spend', $tier)
                        ? ($tier['max_total_spend'] === null ? null : (float) $tier['max_total_spend'])
                        : $model->max_total_spend,
                    'max_cashback_per_period' => array_key_exists('max_cashback_per_period', $tier)
                        ? $this->money($tier['max_cashback_per_period'])
                        : $model->max_cashback_per_period,
                ])->save();

                // Cap động là tài sản của BẬC: khi payload mang key là thay CẢ khối.
                if (array_key_exists('transaction_caps', $tier)) {
                    $this->tiers->syncTransactionCaps($model, $tier['transaction_caps']);
                }
            }

            $this->syncRules($model, $tier['rules'] ?? []);
        }
    }

    /**
     * Đồng bộ rule của tier với payload mới, GIỮ id của rule khi có thể.
     *
     * - Rule vắng trong payload bị xóa (chỉ xóa dòng `PolicyTierCategory`). FALLBACK
     *   ("Các danh mục còn lại") không bao giờ bị xóa dù vắng trong payload.
     * - Rule `scope_type = other` được hợp NHẤT vào đúng dòng fallback hiện có, không
     *   tạo bản thứ hai (bất biến: đúng một fallback mỗi bậc).
     * - Rule có `id` khớp được cập nhật các trường, kể cả chuyển target
     *   (`category_id` ↔ `combo_id`); target đích kiểm ở tầng service.
     * - Rule mới (không có `id` khớp) được tạo, kèm `spend_from=0` mặc định.
     * - Hai rule trong cùng tier trỏ cùng một target (cùng danh mục HOẶC cùng combo)
     *   là không hợp lệ: giữ rule xuất hiện trước, bỏ bản sau. Danh mục và combo là
     *   hai không gian target riêng nên được phép trùng id số.
     */
    private function syncRules(PolicyTier $tier, array $rules): void
    {
        $existing = $tier->tierCategoryRules()->get()->keyBy('id');
        $incoming = collect($rules)->values();
        $incomingIds = $incoming->pluck('id')->filter()->map(fn ($id): int => (int) $id)->all();

        foreach ($existing as $ruleId => $rule) {
            if ($rule->isFallback()) {
                continue;
            }

            if (! in_array($ruleId, $incomingIds, true)) {
                $rule->delete();
                unset($existing[$ruleId]);
            }
        }

        $fallbackId = $existing
            ->first(fn (PolicyTierCategory $row): bool => $row->isFallback())
            ?->id;

        $claimed = [];
        $fallbackSeen = false;

        foreach ($incoming as $ruleIndex => $rule) {
            $ruleScope = $rule['scope_type'] ?? PolicyTierCategory::SCOPE_CATEGORY;
            $ruleId = isset($rule['id']) && $rule['id'] !== null ? (int) $rule['id'] : null;

            // ---- Fallback "Các danh mục còn lại" ----
            if ($ruleScope === PolicyTierCategory::SCOPE_OTHER) {
                if ($fallbackSeen) {
                    continue;
                }
                $fallbackSeen = true;

                $model = null;

                if ($ruleId !== null && $existing->has($ruleId) && $existing->get($ruleId)->isFallback()) {
                    $model = $existing->get($ruleId);
                } elseif ($fallbackId !== null && $existing->has($fallbackId)) {
                    $model = $existing->get($fallbackId);
                }

                if ($model === null) {
                    $this->rules->create($tier, [
                        'scope_type' => PolicyTierCategory::SCOPE_OTHER,
                        'counts_toward_tier_cap' => $rule['counts_toward_tier_cap'] ?? false,
                        // Fallback không có hạn mức — `CategoryRuleService` ép false
                        // và từ chối payload gửi `true` lên fallback.
                        'is_quota_category' => $rule['is_quota_category'] ?? false,
                        'name' => $rule['name'] ?? PolicyTierCategory::FALLBACK_NAME,
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
                } else {
                    $model->forceFill([
                        // Fallback không mang target nào — ép null CẢ HAI.
                        'category_id' => null,
                        'combo_id' => null,
                        'scope_type' => PolicyTierCategory::SCOPE_OTHER,
                        'counts_toward_tier_cap' => array_key_exists('counts_toward_tier_cap', $rule)
                            ? (bool) $rule['counts_toward_tier_cap']
                            : false,
                        'is_quota_category' => false,
                        'name' => array_key_exists('name', $rule)
                            ? $rule['name']
                            : ($model->name ?? PolicyTierCategory::FALLBACK_NAME),
                        'sort_order' => $rule['sort_order'] ?? ($ruleIndex + 1),
                        'cashback_percent' => array_key_exists('cashback_percent', $rule)
                            ? (float) $rule['cashback_percent']
                            : $model->cashback_percent,
                        'max_cashback_per_transaction' => $this->money(array_key_exists('max_cashback_per_transaction', $rule)
                            ? $rule['max_cashback_per_transaction']
                            : $model->max_cashback_per_transaction),
                        'max_cashback_per_category_per_period' => $this->money(array_key_exists('max_cashback_per_category_per_period', $rule)
                            ? $rule['max_cashback_per_category_per_period']
                            : $model->max_cashback_per_category_per_period),
                        'min_transaction_amount' => $this->money(array_key_exists('min_transaction_amount', $rule)
                            ? $rule['min_transaction_amount']
                            : $model->min_transaction_amount),
                        'is_enabled' => array_key_exists('is_enabled', $rule)
                            ? (bool) $rule['is_enabled']
                            : $model->is_enabled,
                        // Ba field dưới không có ô nhập ở Policy Editor nhưng vẫn phải
                        // đi kèm khi SỬA TẠI CHỖ: bỏ sót là mất cấu hình của rule cũ.
                        'spend_from' => array_key_exists('spend_from', $rule)
                            ? (float) $rule['spend_from']
                            : $model->spend_from,
                        'spend_to' => array_key_exists('spend_to', $rule)
                            ? $this->money($rule['spend_to'])
                            : $model->spend_to,
                        'note' => array_key_exists('note', $rule) ? $rule['note'] : $model->note,
                    ])->save();
                }

                continue;
            }

            // ---- Rule cụ thể: danh mục HOẶC combo ----
            //
            // Payload có thể chỉ gửi `scope_type` mà không kèm target (client cũ /
            // PATCH một phần). Khi đó suy ra target hiện tại từ rule cùng `id` để
            // giữ nguyên thay vì cố ý gán `category_id = null`.
            $hasCombo = array_key_exists('combo_id', $rule)
                && $rule['combo_id'] !== null
                && $rule['combo_id'] !== '';
            $hasCategory = array_key_exists('category_id', $rule)
                && $rule['category_id'] !== null
                && $rule['category_id'] !== '';

            if (! $hasCombo && ! $hasCategory) {
                $current = $ruleId !== null ? $existing->get($ruleId) : null;

                if ($current === null) {
                    continue;
                }

                if ($current->combo_id !== null) {
                    $hasCombo = true;
                    $rule['combo_id'] = $current->combo_id;
                } elseif ($current->category_id !== null) {
                    $hasCategory = true;
                    $rule['category_id'] = $current->category_id;
                } else {
                    continue;
                }
            }

            // Nếu client gửi cả hai, combo thắng (khớp `CategoryRuleService`).
            if ($hasCombo) {
                $hasCategory = false;
            }

            $comboId = $hasCombo ? (int) $rule['combo_id'] : null;
            $categoryId = $hasCategory ? (int) $rule['category_id'] : null;
            $claimKey = $hasCombo ? 'combo:'.$comboId : 'category:'.$categoryId;

            if (isset($claimed[$claimKey])) {
                if ($ruleId !== null && $existing->has($ruleId)) {
                    $existing->get($ruleId)->delete();
                    unset($existing[$ruleId]);
                }

                continue;
            }
            $claimed[$claimKey] = true;

            $model = null;

            if ($ruleId !== null && $existing->has($ruleId)) {
                $model = $existing->get($ruleId);
            } else {
                $model = $existing->first(fn (PolicyTierCategory $row): bool => $hasCombo
                    ? (int) $row->combo_id === $comboId
                    : ((int) $row->category_id === $categoryId && $row->combo_id === null)) ?? null;
            }

            if ($model === null) {
                $this->rules->create($tier, [
                    'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                    'category_id' => $hasCombo ? null : $categoryId,
                    'combo_id' => $hasCombo ? $comboId : null,
                    'counts_toward_tier_cap' => $rule['counts_toward_tier_cap'] ?? true,
                    'is_quota_category' => $rule['is_quota_category'] ?? false,
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
            } else {
                // Trước khi đổi target, xóa các rule khác trong tier đang giữ cùng
                // target đích để không vi phạm UNIQUE (tier_id, category_id, spend_from).
                foreach ($existing as $otherId => $other) {
                    if ($otherId === $model->id) {
                        continue;
                    }

                    $sameTarget = $hasCombo
                        ? (int) $other->combo_id === $comboId
                        : ((int) $other->category_id === $categoryId && $other->combo_id === null);

                    if ($sameTarget) {
                        $other->delete();
                        unset($existing[$otherId]);
                    }
                }

                $model->forceFill([
                    'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                    'category_id' => $hasCombo ? null : $categoryId,
                    'combo_id' => $hasCombo ? $comboId : null,
                    'counts_toward_tier_cap' => array_key_exists('counts_toward_tier_cap', $rule)
                        ? (bool) $rule['counts_toward_tier_cap']
                        : $model->counts_toward_tier_cap,
                    'is_quota_category' => $this->quotaFlagFor($rule, $model),
                    'name' => array_key_exists('name', $rule) ? $rule['name'] : $model->name,
                    'sort_order' => $rule['sort_order'] ?? ($ruleIndex + 1),
                    'cashback_percent' => array_key_exists('cashback_percent', $rule)
                        ? (float) $rule['cashback_percent']
                        : $model->cashback_percent,
                    'max_cashback_per_transaction' => $this->money(array_key_exists('max_cashback_per_transaction', $rule)
                        ? $rule['max_cashback_per_transaction']
                        : $model->max_cashback_per_transaction),
                    'max_cashback_per_category_per_period' => $this->money(array_key_exists('max_cashback_per_category_per_period', $rule)
                        ? $rule['max_cashback_per_category_per_period']
                        : $model->max_cashback_per_category_per_period),
                    'min_transaction_amount' => $this->money(array_key_exists('min_transaction_amount', $rule)
                        ? $rule['min_transaction_amount']
                        : $model->min_transaction_amount),
                    // Giữ đủ field khi sửa tại chỗ — xem giải thích ở nhánh fallback.
                    'is_enabled' => array_key_exists('is_enabled', $rule)
                        ? (bool) $rule['is_enabled']
                        : $model->is_enabled,
                    'spend_from' => array_key_exists('spend_from', $rule)
                        ? (float) $rule['spend_from']
                        : $model->spend_from,
                    'spend_to' => array_key_exists('spend_to', $rule)
                        ? $this->money($rule['spend_to'])
                        : $model->spend_to,
                    'note' => array_key_exists('note', $rule) ? $rule['note'] : $model->note,
                ])->save();
            }
        }

        // Bất biến: đúng một fallback mỗi bậc sau khi đồng bộ.
        $this->rules->ensureSingleFallback($tier);
    }

    private function money(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * Cờ "tính hạn mức chi tiêu còn lại" khi sửa tại chỗ một rule.
     *
     * Editor luôn gửi kèm cờ trong payload (`versionConfig()`), nên nhánh có
     * `array_key_exists` là đường đi bình thường. Nhánh giữ giá trị cũ dành cho
     * PATCH một phần không nhắc tới cờ — giống hệt cách các field khác giữ mình.
     *
     * `assertQuotaCategoryTierUniqueness()` đã bảo đảm bất biến "cùng một bậc"
     * cho payload trước khi vào đây, nên không cần hỏi DB lần nữa.
     *
     * @param  array<string, mixed>  $rule
     */
    private function quotaFlagFor(array $rule, PolicyTierCategory $model): bool
    {
        if (! array_key_exists('is_quota_category', $rule)) {
            return (bool) $model->is_quota_category;
        }

        return (bool) $rule['is_quota_category'];
    }

    /**
     * Blueprint hệ thống CHỈ được tham chiếu danh mục hệ thống đang hoạt động.
     *
     * Tầng chặn phạm vi cấu hình (System / User Category) cho đường admin. Admin
     * chỉ chọn danh mục hệ thống ở editor, nhưng API JSON vẫn nhận payload tùy ý —
     * nếu thiếu chặn này, một blueprint (user_card_id = NULL) có thể trỏ vào danh
     * mục riêng của người dùng, và mọi thẻ clone từ nó sẽ vi phạm cô lập tài nguyên.
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
        // riêng của user không được nằm trong chính sách hệ thống. Cùng chặn
        // phạm vi với `PolicyCloneService::assertSystemOnlyCategories()`.
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

    private function rounding(string $mode): string
    {
        $allowed = ['round', 'floor', 'ceil'];

        if (! in_array($mode, $allowed, true)) {
            throw new InvalidArgumentException('rounding_mode không hợp lệ.');
        }

        return $mode;
    }
}
