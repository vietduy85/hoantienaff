<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditCardBank extends Model
{
    use HasFactory;

    protected $table = 'credit_card_banks';

    protected $fillable = [
        'name',
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

    public function creditCards(): HasMany
    {
        return $this->hasMany(CreditCard::class, 'bank_id');
    }
}
