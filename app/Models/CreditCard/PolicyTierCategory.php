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
 * KHÔNG có `min_cashback`. KHÔNG có `min_total_spend` (minimum spend thuộc
 * Card Policy / Policy Version, §11).
 *
 * @property int $id
 * @property int $tier_id
 * @property int $category_id
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
    protected $table = 'credit_card_policy_tier_categories';

    protected $fillable = [
        'tier_id',
        'category_id',
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

    public function tier(): BelongsTo
    {
        return $this->belongsTo(PolicyTier::class, 'tier_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
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
