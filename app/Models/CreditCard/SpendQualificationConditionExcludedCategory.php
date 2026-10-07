<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SpendQualificationConditionExcludedCategory — danh mục LOẠI TRỪ của điều kiện
 * `other` của MỘT policy version.
 *
 * Xem ngữ nghĩa tại `SpendQualificationTemplateConditionExcludedCategory` — hai
 * bảng này đối xứng nhau (khối template / khối policy version).
 */
class SpendQualificationConditionExcludedCategory extends CreditCardModel
{
    protected $table = 'credit_card_spend_qualification_condition_excluded_categories';

    protected $fillable = [
        'condition_id',
        'category_id',
    ];

    protected function casts(): array
    {
        return [
            'condition_id' => 'integer',
            'category_id' => 'integer',
        ];
    }

    public function condition(): BelongsTo
    {
        return $this->belongsTo(SpendQualificationCondition::class, 'condition_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}