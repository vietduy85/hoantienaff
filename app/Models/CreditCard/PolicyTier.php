<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PolicyTier — bậc chi tiêu của một Policy Version.
 *
 * Bậc được chọn theo TỔNG eligible spend của CẢ kỳ sao kê (RETROACTIVE).
 * Khoảng `[min_total_spend, max_total_spend)`; `max_total_spend = NULL` ⇒ mở.
 *
 * @property int $id
 * @property int $policy_id
 * @property string $name
 * @property int $sort_order
 * @property string $min_total_spend
 * @property string|null $max_total_spend
 * @property string|null $max_cashback_per_period
 */
class PolicyTier extends CreditCardModel
{
    protected $table = 'credit_card_policy_tiers';

    protected $fillable = [
        'policy_id',
        'name',
        'sort_order',
        'min_total_spend',
        'max_total_spend',
        'max_cashback_per_period',
    ];

    protected function casts(): array
    {
        return [
            'policy_id' => 'integer',
            'sort_order' => 'integer',
            'min_total_spend' => 'decimal:2',
            'max_total_spend' => 'decimal:2',
            'max_cashback_per_period' => 'decimal:2',
        ];
    }

    /**
     * Policy VERSION chứa bậc này (không phải policy cha).
     */
    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class, 'policy_id');
    }

    public function tierCategoryRules(): HasMany
    {
        return $this->hasMany(PolicyTierCategory::class, 'tier_id')->orderBy('sort_order');
    }

    /**
     * Giới hạn hoàn tiền theo giá trị giao dịch của TOÀN BẬC.
     */
    public function transactionCaps(): HasMany
    {
        return $this->hasMany(PolicyTierCategoryTransactionCap::class, 'policy_tier_id')->orderBy('sort_order');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('min_total_spend');
    }

    /**
     * Bậc chứa tổng chi tiêu `total` (nửa mở [min, max)).
     */
    public function scopeForTotalSpend(Builder $query, float $total): Builder
    {
        return $query->where('min_total_spend', '<=', $total)
            ->where(function (Builder $inner) use ($total): void {
                $inner->whereNull('max_total_spend')
                    ->orWhere('max_total_spend', '>', $total);
            });
    }
}
