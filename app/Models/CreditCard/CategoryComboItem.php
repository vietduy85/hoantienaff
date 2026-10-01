<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CategoryComboItem — MỘT danh mục thuộc về MỘT combo.
 *
 * Đây là toàn bộ định nghĩa membership; không có bảng nào khác chứa danh sách này
 * nên không thể lệch dữ liệu.
 *
 * Bất biến do DB bảo vệ: `UNIQUE (combo_id, category_id)` — một danh mục không
 * được lặp trong cùng một combo.
 *
 * KHÔNG có trường trỏ tới combo khác ⇒ combo không thể chứa combo.
 *
 * `category_id` FK RESTRICT: danh mục đã thuộc combo thì không xoá cứng được.
 *
 * @property int $id
 * @property int $combo_id
 * @property int $category_id
 * @property int $sort_order
 */
class CategoryComboItem extends CreditCardModel
{
    protected $table = 'credit_card_category_combo_items';

    protected $fillable = [
        'combo_id',
        'category_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'combo_id' => 'integer',
            'category_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function combo(): BelongsTo
    {
        return $this->belongsTo(CategoryCombo::class, 'combo_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
