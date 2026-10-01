<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Category — DANH MỤC CHI TIÊU (spending category), KHÔNG phải danh mục loại thẻ.
 *
 * Hai loại dùng chung một bảng, phân biệt bằng `scope` + `owner_user_id`:
 *   - `scope = 'system'`, `owner_user_id = 0` → danh mục hệ thống (Online, Dining, ...)
 *   - `scope = 'user'`,   `owner_user_id = N` → danh mục riêng của user
 *
 * KHÔNG có cashback / quota ở đây (§12). Cashback nằm ở
 * Policy Version → Policy Tier → Tier Category Rule.
 *
 * @property int $id
 * @property string $scope
 * @property int $owner_user_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_active
 * @property bool $is_default
 * @property int $sort_order
 */
class Category extends CreditCardModel
{
    public const SCOPE_SYSTEM = 'system';

    public const SCOPE_USER = 'user';

    /**
     * Sentinel cho `owner_user_id` khi danh mục thuộc hệ thống.
     * `users.id` là AUTO_INCREMENT nên 0 không bao giờ là id hợp lệ.
     */
    public const SYSTEM_OWNER_ID = 0;

    protected $table = 'credit_card_categories';

    protected $fillable = [
        'scope',
        'owner_user_id',
        'name',
        'slug',
        'description',
        'is_active',
        'is_default',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'owner_user_id' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Các rule tham chiếu danh mục này (fk RESTRICT ⇒ không xoá cứng khi đã dùng).
     */
    public function tierCategoryRules(): HasMany
    {
        return $this->hasMany(PolicyTierCategory::class, 'category_id');
    }

    /**
     * Combo nào chứa danh mục này (fk RESTRICT ⇒ không xoá cứng khi đã dùng).
     *
     * Một danh mục CÓ THỂ nằm trong nhiều combo khác nhau (cho phép ở tầng dữ
     * liệu); việc chặn trùng khi gán rule là ràng buộc nghiệp vụ ở
     * `CategoryRuleService`.
     */
    public function comboMemberships(): HasMany
    {
        return $this->hasMany(CategoryComboItem::class, 'category_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'category_id');
    }

    public function isSystem(): bool
    {
        return $this->scope === self::SCOPE_SYSTEM;
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('scope', self::SCOPE_SYSTEM);
    }

    public function scopeUserOwned(Builder $query, int $userId): Builder
    {
        return $query->where('scope', self::SCOPE_USER)->where('owner_user_id', $userId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Danh mục user được phép dùng: toàn bộ danh mục hệ thống + danh mục riêng của họ.
     *
     * Luôn phải scope theo user khi hiển thị danh mục, nếu không sẽ lộ danh mục
     * riêng của người khác.
     */
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
