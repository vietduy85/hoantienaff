<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
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
    ): PolicyVersion {
        $template = PolicyTemplate::query()->whereKey($templateId)->first();

        if ($template === null) {
            throw new InvalidArgumentException("Template #{$templateId} không tồn tại.");
        }

        if (! $template->isSystemScope()) {
            throw new InvalidArgumentException('Chỉ được chọn template hệ thống tại đây.');
        }

        return $this->cloneTemplate($userCard, $templateId, $effectiveFrom, $name);
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
    ): PolicyVersion {
        $template = PolicyTemplate::query()->whereKey($templateId)->first();

        if ($template === null) {
            throw new InvalidArgumentException("Template #{$templateId} không tồn tại.");
        }

        if ($template->isSystemScope()) {
            return $this->cloneTemplate($userCard, $templateId, $effectiveFrom, $name);
        }

        if (! $template->isOwnedBy((int) $userCard->user_id)) {
            throw new InvalidArgumentException('Bạn chỉ được dùng template của chính mình.');
        }

        return $this->cloneTemplate($userCard, $templateId, $effectiveFrom, $name);
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
     */
    public function currentVersion(UserCard $userCard): ?PolicyVersion
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
    ): PolicyVersion {
        $this->assertCardUsable($userCard);

        $template = PolicyTemplate::query()->whereKey($templateId)->first();

        if ($template === null) {
            throw new InvalidArgumentException("Template #{$templateId} không tồn tại.");
        }

        if (! $template->is_active) {
            throw new InvalidArgumentException('Template này đã bị vô hiệu hoá.');
        }

        if ($template->blueprint === null) {
            throw new LogicException("Template \"{$template->name}\" chưa có cấu hình để sao chép.");
        }

        return $this->cloner->attachTemplateToCard($userCard, $template, $effectiveFrom, $name);
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
            ]);

            foreach ($tier['categories'] ?? [] as $rule) {
                // Tương tự: `CategoryRuleService` kiểm danh mục còn dùng được,
                // phần trăm hợp lệ và khoảng chi tiêu của rule.
                $this->rules->create($created, [
                    'category_id' => (int) $rule['category_id'],
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

    private function money(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
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
