<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bank — ngân hàng phát hành thẻ.
 *
 * @property int $id
 * @property string $name
 * @property string|null $short_name
 * @property string $slug
 * @property string|null $logo
 * @property bool $is_active
 */
class Bank extends CreditCardModel
{
    protected $table = 'credit_card_banks';

    protected $fillable = [
        'name',
        'short_name',
        'slug',
        'logo',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Sản phẩm thẻ do bank này phát hành.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'bank_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $keyword): Builder
    {
        if ($keyword === null || trim($keyword) === '') {
            return $query;
        }

        $keyword = trim($keyword);

        return $query->where(function (Builder $inner) use ($keyword): void {
            $inner->where('name', 'like', '%'.$keyword.'%')
                ->orWhere('short_name', 'like', '%'.$keyword.'%');
        });
    }
}
