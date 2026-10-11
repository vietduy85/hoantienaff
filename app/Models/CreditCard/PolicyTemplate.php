<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * PolicyTemplate — công thức mẫu để SAO CHÉP, không gắn thẻ nào.
 *
 * Template có "policy blueprint": một bản ghi `credit_card_policies` với
 * `user_card_id = NULL` và `template_id` trỏ về chính nó. Đây là nguồn để
 * `PolicyCloneService` deep-clone.
 *
 * KHÔNG bao giờ có thẻ nào tham chiếu trực tiếp tới bản ghi blueprint.
 *
 * @property int $id
 * @property string $scope
 * @property int $owner_user_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_builtin
 * @property bool $is_active
 * @property int|null $default_version_id
 * @property int $sort_order
 * @property int|null $applicable_bank_id
 * @property string|null $applicable_product_match
 */
class PolicyTemplate extends CreditCardModel
{
    public const SCOPE_SYSTEM = 'system';

    public const SCOPE_USER = 'user';

    public const SYSTEM_OWNER_ID = 0;

    protected $table = 'credit_card_policy_templates';

    protected $fillable = [
        'scope',
        'owner_user_id',
        'name',
        'slug',
        'description',
        'is_builtin',
        'is_active',
        'default_version_id',
        'sort_order',
        'applicable_bank_id',
        'applicable_product_match',
    ];

    protected function casts(): array
    {
        return [
            'owner_user_id' => 'integer',
            'is_builtin' => 'boolean',
            'is_active' => 'boolean',
            'default_version_id' => 'integer',
            'sort_order' => 'integer',
            'applicable_bank_id' => 'integer',
        ];
    }

    /**
     * Template có bị giới hạn bank/sản phẩm không?
     */
    public function restrictedToBankOrProduct(): bool
    {
        return $this->applicable_bank_id !== null || $this->applicable_product_match !== null;
    }

    /**
     * Template này có áp dụng được cho `$userCard` không?
     *
     * Cả hai ràng buộc đều phải thoả khi được đặt (AND):
     *   - `applicable_bank_id` ≠ null ⇒ thẻ phải thuộc đúng bank.
     *   - `applicable_product_match` ≠ null ⇒ `name` của thẻ phải chứa keyword
     *     (không phân biệt hoa/thường). Chuỗi rỗng được coi là không đặt ràng buộc.
     *
     * Template không giới hạn (cả hai null) luôn áp dụng được — đúng hành vi cũ.
     */
    public function applicableToCard(UserCard $userCard): bool
    {
        if ($this->applicable_bank_id !== null
            && (int) $userCard->bank_id !== (int) $this->applicable_bank_id) {
            return false;
        }

        $keyword = trim((string) $this->applicable_product_match);

        if ($keyword !== ''
            && ! str_contains(mb_strtolower((string) $userCard->name), mb_strtolower($keyword))) {
            return false;
        }

        return true;
    }

    /**
     * Policy blueprint rời của template này (user_card_id = NULL).
     */
    public function blueprint(): HasOne
    {
        return $this->hasOne(Policy::class, 'template_id')
            ->whereNull('user_card_id');
    }

    /**
     * TOÀN BỘ blueprint (các version) của template — dùng khi template có nhiều
     * version (admin đổi cấu hình ⇒ tạo blueprint N+1, giữ version cũ append-only).
     *
     * @return HasMany<int, Policy>
     */
    public function blueprints(): HasMany
    {
        return $this->hasMany(Policy::class, 'template_id')
            ->whereNull('user_card_id')
            ->orderBy('version_no');
    }

    /**
     * Blueprint ĐANG ÁP DỤNG của template: version cao nhất chưa bị đóng.
     *
     * Đây là nguồn để deep clone mỗi khi một thẻ chọn template. Template có một
     * bản ghi duy nhất (chưa từng đổi version) thì trả về chính nó.
     */
    public function currentBlueprint(): ?PolicyVersion
    {
        return PolicyVersion::query()
            ->whereNull('user_card_id')
            ->where('template_id', $this->id)
            ->where('status', Policy::STATUS_ACTIVE)
            ->orderByDesc('version_no')
            ->first();
    }

    public function defaultVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class, 'default_version_id');
    }

    /**
     * Blueprint được dùng làm NGUỒN clone cho user MỚI.
     *
     * Trả về version được admin đánh dấu "mặc định" nếu còn hợp lệ (thuộc
     * template này và là blueprint rời); nếu chưa đặt/default bị xoá thì fallback
     * về current/latest blueprint như hành vi trước đây.
     */
    public function defaultBlueprint(): ?PolicyVersion
    {
        $default = $this->defaultVersion;

        if ($default !== null
            && (int) $default->template_id === $this->id
            && $default->user_card_id === null) {
            return $default;
        }

        return $this->currentBlueprint();
    }

    /**
     * Tất cả policy đã clone ra từ template này (mỗi thẻ một bản độc lập).
     */
    public function clonedPolicies(): HasMany
    {
        return $this->hasMany(Policy::class, 'template_id')
            ->whereNotNull('user_card_id');
    }

    /**
     * Các nhóm MCC do admin nhập cho chính sách này (module "Quản lý MCC").
     */
    public function mccGroups(): HasMany
    {
        return $this->hasMany(PolicyMccGroup::class, 'policy_template_id')
            ->orderBy('display_order')
            ->orderBy('id');
    }

    /**
     * MCC loại trừ chung của cả chính sách.
     */
    public function mccCommonExclusions(): HasMany
    {
        return $this->hasMany(PolicyMccCommonExclusion::class, 'policy_template_id');
    }

    /**
     * Hồ sơ MCC chính sách ({@see PolicyMccProfile}) — nguồn dữ liệu do admin nhập
     * tay cho module "Quản lý MCC".
     */
    public function mccProfiles(): HasMany
    {
        return $this->hasMany(PolicyMccProfile::class, 'policy_template_id')
            ->orderBy('name');
    }

    /**
     * Template hệ thống: dùng để sinh data mẫu, không dùng làm FK nghiệp vụ.
     */
    public function isSystemScope(): bool
    {
        return $this->scope === self::SCOPE_SYSTEM;
    }

    public function isOwnedBy(int $userId): bool
    {
        return ! $this->isSystemScope() && (int) $this->owner_user_id === $userId;
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('scope', self::SCOPE_SYSTEM);
    }

    public function scopeUserOwned(Builder $query, int $userId): Builder
    {
        return $query->where('scope', self::SCOPE_USER)->where('owner_user_id', $userId);
    }

    public function scopeSelectableBy(Builder $query, int $userId): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $inner) use ($userId): void {
                $inner->where('scope', self::SCOPE_SYSTEM)
                    ->orWhere(function (Builder $user) use ($userId): void {
                        $user->where('scope', self::SCOPE_USER)
                            ->where('owner_user_id', $userId);
                    });
            });
    }
}
