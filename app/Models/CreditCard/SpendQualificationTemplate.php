<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SpendQualificationTemplate — MẪU "Điều kiện hoàn tiền đặc biệt" do ADMIN quản lý.
 *
 * Đây là master data cho tính năng: user chọn một template đang bật, hệ thống
 * DEEP-CLONE toàn bộ điều kiện của template đó vào policy version của thẻ
 * (xem `SpendQualificationService`). Sửa template KHÔNG ảnh hưởng bản clone.
 *
 * Một template gồm các điều kiện:
 *   - `category`: tổng chi tiêu THỰC TẾ của 1 danh mục trong kỳ >= min_spend;
 *   - `other` ("Lĩnh vực khác"): tổng chi tiêu thực tế cả kỳ TRỪ các danh mục
 *     bị loại trừ >= min_spend. Mỗi template tối đa 1 `other` và nó LUÔN đứng cuối.
 *
 * @property-read \Illuminate\Support\Collection<int, SpendQualificationTemplateCondition> $conditions
 */
class SpendQualificationTemplate extends CreditCardModel
{
    protected $table = 'credit_card_spend_qualification_templates';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'note',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(SpendQualificationTemplateCondition::class, 'template_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function isUsedByAnyQualification(): bool
    {
        return SpendQualification::query()
            ->where('source_template_id', $this->getKey())
            ->exists();
    }
}