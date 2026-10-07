<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SpendQualificationCondition — MỘT điều kiện chi tiêu của `SpendQualification`.
 *
 * Giống hệt `SpendQualificationTemplateCondition` nhưng gắn với MỘT policy version
 * (bản clone) thay vì template. Nhìn chung mọi bất biến của template condition
 * cũng áp dụng ở đây; service dùng CÙNG một lược đồ payload cho cả hai chiều.
 */
class SpendQualificationCondition extends CreditCardModel
{
    public const TYPE_CATEGORY = 'category';

    public const TYPE_OTHER = 'other';

    protected $table = 'credit_card_spend_qualification_conditions';

    protected $fillable = [
        'qualification_id',
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

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(SpendQualification::class, 'qualification_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function excludedCategories(): HasMany
    {
        return $this->hasMany(SpendQualificationConditionExcludedCategory::class, 'condition_id');
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