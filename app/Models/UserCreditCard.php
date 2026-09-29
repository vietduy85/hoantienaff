<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCreditCard extends Model
{
    use HasFactory;

    protected $table = 'user_credit_cards';

    protected $fillable = [
        'user_id',
        'credit_card_id',
        'card_number_last4',
        'credit_limit',
        'statement_day',
        'payment_due_day',
        'is_active',
    ];

    protected $hidden = [
        // Chỉ lưu 4 số cuối — không bao giờ serialize thêm dữ liệu nhạy cảm.
        'card_number_last4',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'statement_day' => 'integer',
            'payment_due_day' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class, 'credit_card_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
