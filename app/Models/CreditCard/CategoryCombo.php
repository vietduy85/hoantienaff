<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CategoryCombo — NHÓM danh mục chi tiêu, dùng làm target của cashback rule.
 *
 * Combo KHÔNG chứa cashback (§12): một combo có thể được nhiều rule tham chiếu,
 * mỗi rule một mức %/cap riêng. Cashback vẫn nằm ở
 * `credit_card_policy_tier_categories.combo_id`.
 *
 * ---------------------------------------------------------------------------
 * PHÂN BIỆT VỚI `Category` — VÌ SAO KHÔNG NHỒI VÀO MỘT BẢNG
 * ---------------------------------------------------------------------------
 * `Category` là NHÃN chi tiêu, bất biến về danh tính: `SystemCategoryService::update()`
 * chỉ cho đổi `name`/`description`, `id` không bao giờ đổi. Vì thế mọi tham chiếu
 * (rule, giao dịch, báo cáo) điểm thẳng vào master row và tự hiển thị tên mới.
 *
 * `CategoryCombo` thì mang NỘI DUNG (membership) và nội dung đó THAY ĐỔI ĐƯỢC.
 * Không thể áp quy ước "share master row" của Category cho Combo, vì sửa
 * membership combo của hệ thống sẽ đổi hành vi cashback của mọi user policy đã
 * clone. Xem `CategoryComboService::cloneForUser()` — đó là lý do membership
 * được SNAPSHOT vào scope `user` khi deep-clone policy.
 *
 * ---------------------------------------------------------------------------
 * SCOPE
 * ---------------------------------------------------------------------------
 *   - `scope = 'system'`, `owner_user_id = 0` → admin quản lý; user ĐỌC được và
 *     được dùng trong policy của thẻ mình.
 *   - `scope = 'user'`,   `owner_user_id = N` → chỉ chủ nhân đọc/ghi.
 *
 * KHÔNG nested: không FK tới chính bảng này, thành viên luôn là `category_id`.
 *
 * @property int $id
 * @property string $scope
 * @property int $owner_user_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_active
 * @property int $sort_order
 */
class CategoryCombo extends CreditCardModel
{
    public const SCOPE_SYSTEM = 'system';

    public const SCOPE_USER = 'user';

    /**
     * Sentinel cho `owner_user_id` khi combo thuộc hệ thống.
     * `users.id` là AUTO_INCREMENT nên 0 không bao giờ là id hợp lệ.
     */
    public const SYSTEM_OWNER_ID = 0;

    protected $table = 'credit_card_category_combos';

    protected $fillable = [
        'scope',
        'owner_user_id',
        'name',
        'slug',
        'description',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'owner_user_id' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Thành viên của combo, đã sort.
     */
    public function items(): HasMany
    {
        return $this->hasMany(CategoryComboItem::class, 'combo_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Các rule cashback trỏ vào combo này (fk RESTRICT ⇒ không xoá cứng khi đã dùng).
     */
    public function tierCategoryRules(): HasMany
    {
        return $this->hasMany(PolicyTierCategory::class, 'combo_id');
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
     * Combo user được phép dùng: toàn bộ combo hệ thống + combo riêng của họ.
     *
     * Luôn phải scope theo user khi hiển thị/validate, nếu không sẽ lộ combo
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
