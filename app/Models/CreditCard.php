<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditCard extends Model
{
    use HasFactory;

    protected $table = 'credit_cards';

    protected $fillable = [
        'bank_id',
        'category_id',
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
        return $this->belongsTo(CreditCardBank::class, 'bank_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CreditCardCategory::class, 'category_id');
    }

    public function userCreditCards(): HasMany
    {
        return $this->hasMany(UserCreditCard::class, 'credit_card_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
