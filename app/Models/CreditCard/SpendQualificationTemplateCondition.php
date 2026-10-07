<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SpendQualificationTemplateCondition — MỘT điều kiện chi tiêu của mẫu.
 *
 * `condition_type`:
 *   - 'category': cần `category_id`, tổng chi tiêu THỰC TẾ của danh mục này
 *     trong kỳ >= `min_spend`. Không có danh mục loại trừ.
 *   - 'other' ("Lĩnh vực khác"): `category_id` LUÔN NULL; danh mục loại trừ nằm ở
 *     `excludedCategories`. `min_spend` bắt buộc. Mỗi qualification tối đa MỘT
 *     điều kiện `other` và nó LUÔN đứng cuối.
 */
class SpendQualificationTemplateCondition extends CreditCardModel
{
    public const TYPE_CATEGORY = 'category';

    public const TYPE_OTHER = 'other';

    protected $table = 'credit_card_spend_qualification_template_conditions';

    protected $fillable = [
        'template_id',
        'condition_type',
        'category_id',
        'min_spend',
        'is_enabled',
        'sort_order',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'condition_type' => 'string',
            'category_id' => 'integer',
            'min_spend' => 'decimal:2',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SpendQualificationTemplate::class, 'template_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function excludedCategories(): HasMany
    {
        return $this->hasMany(SpendQualificationTemplateConditionExcludedCategory::class, 'condition_id');
    }

    public function isCategory(): bool
    {
        return $this->condition_type === self::TYPE_CATEGORY;
    }

    public function isOther(): bool
    {
        return $this->condition_type === self::TYPE_OTHER;
    }
}