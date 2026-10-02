<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TierCategoryRule — quy tắc cashback của MỘT danh mục trong MỘT bậc.
 *
 * Nơi DUY NHẤT chứa cashback % (§12). Danh mục không chứa cashback.
 *
 * Một category có thể có nhiều mức theo spend:
 *   [0, 5tr) → 5%   [5tr, 10tr) → 7%   [10tr, NULL) → 10%
 *
 * `spend_from` / `spend_to` là chi tiêu eligible CỦA DANH MỤC ĐÓ trong kỳ
 * (cùng nguyên tắc retroactive như tier) — xem CashbackCalculator.
 *
 * ---------------------------------------------------------------------------
 * BA DẠNG TARGET
 * ---------------------------------------------------------------------------
 * Một rule trỏ vào ĐÚNG MỘT target, hoặc không target gì (fallback):
 *
 *   | scope_type | category_id | combo_id | Ý nghĩa                           |
 *   |------------|-------------|----------|-----------------------------------|
 *   | category   | CÓ          | NULL     | rule danh mục cụ thể              |
 *   | category   | NULL        | CÓ       | rule combo (một rule, nhiều mục)  |
 *   | other      | NULL        | NULL     | fallback "📦 Các danh mục còn lại"|
 *
 * Cột `combo_id` thêm ở migration 000020. Cột `category_id` CỐ Ý được giữ
 * nguyên cho mọi rule cũ (không backfill sang dạng target_type/target_id) để
 * không phải sửa `CashbackCalculator` hay báo cáo nào.
 *
 * Với rule combo, `spend_from`/`spend_to` đo trên TỔNG chi tiêu eligible của
 * TOÀN BỘ danh mục thành viên trong kỳ — một mức áp cho cả nhóm.
 *
 * Bất biến "không được có cả hai" và "một category chỉ gán 1 rule trong 1 bậc"
 * được chặn ở `CategoryRuleService`, không phải bằng UNIQUE index (NULL lặp
 * được trong MySQL nên UNIQUE không bảo vệ được các case này).
 *
 * ---------------------------------------------------------------------------
 * `is_quota_category` — CƠ SỞ TÍNH "HẠN MỨC CHI TIÊU CÒN LẠI"
 * ---------------------------------------------------------------------------
 * Cờ CẤU HÌNH, không phải cấu hình engine: `CashbackCalculator` KHÔNG đọc cột
 * này. Rule được tick ⇒ cashback của nó là cơ sở để báo cho user "còn có thể
 * chi thêm bao nhiêu để nhận tối đa hoàn tiền" (xem `CashbackQuotaService`).
 *
 * Chỉ rule DANH MỤC và rule COMBO được tick; fallback "📦 Các danh mục còn lại"
 * không phải một danh mục cụ thể nên `canBeQuotaCategory()` trả false.
 *
 * Trong MỘT policy version, các rule được tick phải thuộc cùng một bậc — vì
 * quota chỉ xác định một bậc (từ `UserCard.desired_spend`) rồi đọc các rule
 * được tick của bậc đó. Chặn ở `CategoryRuleService`.
 *
 * KHÔNG có `min_cashback`. KHÔNG có `min_total_spend` (minimum spend thuộc
 * Card Policy / Policy Version, §11).
 *
 * @property int $id
 * @property int $tier_id
 * @property int|null $category_id (NULL khi rule combo hoặc fallback)
 * @property int|null $combo_id (NULL khi rule danh mục hoặc fallback)
 * @property string $scope_type category | other
 * @property bool $counts_toward_tier_cap
 * @property bool $is_quota_category
 * @property string|null $name
 * @property int $sort_order
 * @property string $spend_from
 * @property string|null $spend_to
 * @property string $cashback_percent
 * @property string|null $max_cashback_per_transaction
 * @property string|null $max_cashback_per_category_per_period
 * @property string|null $min_transaction_amount
 * @property bool $is_enabled
 */
class PolicyTierCategory extends CreditCardModel
{
    /** Phạm vi: rule cho ĐÚNG MỘT danh mục (category_id bắt buộc). */
    public const SCOPE_CATEGORY = 'category';

    /** Phạm vi: fallback "📦 Các danh mục còn lại" (category_id = NULL). */
    public const SCOPE_OTHER = 'other';

    /** Nhãn fallback hiển thị cho admin (§15). */
    public const FALLBACK_NAME = '📦 Các danh mục còn lại';

    protected $table = 'credit_card_policy_tier_categories';

    protected $fillable = [
        'tier_id',
        'category_id',
        'combo_id',
        'scope_type',
        'counts_toward_tier_cap',
        'is_quota_category',
        'name',
        'sort_order',
        'spend_from',
        'spend_to',
        'cashback_percent',
        'max_cashback_per_transaction',
        'max_cashback_per_category_per_period',
        'min_transaction_amount',
        'is_enabled',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'tier_id' => 'integer',
            'category_id' => 'integer',
            'combo_id' => 'integer',
            'scope_type' => 'string',
            'counts_toward_tier_cap' => 'boolean',
            'is_quota_category' => 'boolean',
            'sort_order' => 'integer',
            'spend_from' => 'decimal:2',
            'spend_to' => 'decimal:2',
            'cashback_percent' => 'decimal:3',
            'max_cashback_per_transaction' => 'decimal:2',
            'max_cashback_per_category_per_period' => 'decimal:2',
            'min_transaction_amount' => 'decimal:2',
            'is_enabled' => 'boolean',
        ];
    }

    public function isFallback(): bool
    {
        return $this->scope_type === self::SCOPE_OTHER;
    }

    public function isCategorySpecific(): bool
    {
        return $this->scope_type === self::SCOPE_CATEGORY;
    }

    public function isComboSpecific(): bool
    {
        return $this->scope_type === PolicyTierCategory::SCOPE_CATEGORY
            && $this->combo_id !== null
            && $this->category_id === null;
    }

    /**
     * Rule này CÓ được phép tick "Tính hạn mức chi tiêu còn lại" không?
     *
     * Chỉ danh mục và combo. Fallback "📦 Các danh mục còn lại" không phải một mục
     * tiêu chi tiêu cụ thể — nó là phần dư của bậc, tick vào sẽ tạo ra một "hạn
     * mức" không có nghĩa với người dùng.
     */
    public function canBeQuotaCategory(): bool
    {
        return ! $this->isFallback();
    }

    /**
     * Rule này có thực sự được tick làm cơ sở tính hạn mức không?
     *
     * `false` cho fallback kể cả khi cột bị để bẩn (`is_quota_category = true` do
     * dữ liệu tay/script): phần quota đọc qua đây nên không bao giờ rơi vào
     * fallback dù cột sai.
     */
    public function isQuotaCategory(): bool
    {
        return $this->is_quota_category === true && $this->canBeQuotaCategory();
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(PolicyTier::class, 'tier_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * Combo target của rule. NULL với rule danh mục và fallback.
     */
    public function combo(): BelongsTo
    {
        return $this->belongsTo(CategoryCombo::class, 'combo_id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeFallback(Builder $query): Builder
    {
        return $query->where('scope_type', self::SCOPE_OTHER);
    }

    public function scopeCategorySpecific(Builder $query): Builder
    {
        return $query->where('scope_type', self::SCOPE_CATEGORY);
    }

    /**
     * Rule trỏ vào COMBO (không phải rule danh mục, không phải fallback).
     */
    public function scopeComboSpecific(Builder $query): Builder
    {
        return $query->where('scope_type', self::SCOPE_CATEGORY)->whereNotNull('combo_id');
    }

    /**
     * Rule được tick làm cơ sở tính hạn mức chi tiêu còn lại.
     *
     * Luôn loại fallback ở tầng query (không chỉ dựa vào cột) để quota không bao
     * giờ đọc một rule fallback bị bẩn cờ.
     */
    public function scopeQuotaCategory(Builder $query): Builder
    {
        return $query->where('is_quota_category', true)->categorySpecific();
    }

    /**
     * Rule của `categoryId` chứa khoảng chi tiêu `spend` ([spend_from, spend_to)).
     */
    public function scopeForCategorySpend(Builder $query, int $categoryId, float $spend): Builder
    {
        return $query->where('category_id', $categoryId)
            ->where('spend_from', '<=', $spend)
            ->where(function (Builder $inner) use ($spend): void {
                $inner->whereNull('spend_to')
                    ->orWhere('spend_to', '>', $spend);
            });
    }
}
