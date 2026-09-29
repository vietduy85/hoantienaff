<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Product — sản phẩm thẻ do bank phát hành (thay cho bảng legacy `credit_cards`).
 *
 * KHÔNG có quan hệ danh mục: "danh mục loại thẻ" của skeleton Giai đoạn 1 KHÔNG
 * phải Spending Category nên đã bị loại bỏ có chủ đích.
 *
 * @property int $id
 * @property int $bank_id
 * @property string $name
 * @property string $slug
 * @property string|null $image
 * @property string|null $annual_fee
 * @property string|null $description
 * @property bool $is_active
 */
class Product extends CreditCardModel
{
    protected $table = 'credit_card_products';

    protected $fillable = [
        'bank_id',
        'name',
        'slug',
        'image',
        'annual_fee',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'annual_fee' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    /**
     * Các thẻ thật của user thuộc sản phẩm này (nhiều user có thể dùng cùng 1 sản phẩm).
     */
    public function userCards(): HasMany
    {
        return $this->hasMany(UserCard::class, 'product_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
