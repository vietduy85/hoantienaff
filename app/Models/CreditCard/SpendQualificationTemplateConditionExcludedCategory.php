<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SpendQualificationTemplateConditionExcludedCategory — danh mục LOẠI TRỪ của
 * điều kiện `other` của MẪU điều kiện.
 *
 * Ngữ nghĩa: tổng chi tiêu thực tế của các danh mục này TRỪ khỏi tổng chi tiêu
 * cả kỳ trước khi so với `min_spend`. Excluded rỗng = "Lĩnh vực khác" tính trên
 * toàn bộ chi tiêu trong kỳ.
 *
 * Tên bảng rút ngắn `..._template_excluded_categories` (bỏ `_condition`): tên gốc
 * 70 ký tự vượt giới hạn 64 ký tự của MySQL/MariaDB.
 */
class SpendQualificationTemplateConditionExcludedCategory extends CreditCardModel
{
    protected $table = 'credit_card_spend_qualification_template_excluded_categories';

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
        return $this->belongsTo(SpendQualificationTemplateCondition::class, 'condition_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}