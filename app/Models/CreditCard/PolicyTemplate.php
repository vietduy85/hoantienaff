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
 * @property int $sort_order
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
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'owner_user_id' => 'integer',
            'is_builtin' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
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
     * Tất cả policy đã clone ra từ template này (mỗi thẻ một bản độc lập).
     */
    public function clonedPolicies(): HasMany
    {
        return $this->hasMany(Policy::class, 'template_id')
            ->whereNotNull('user_card_id');
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
