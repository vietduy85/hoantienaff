<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const REQUIRED_COMPLETED_ORDERS = 3;

    public const REWARD_AMOUNT = 20000;

    protected $fillable = [
        'referrer_id',
        'referred_user_id',
        'completed_orders',
        'status',
        'reward_amount',
        'rewarded_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_orders' => 'integer',
            'reward_amount' => 'integer',
            'rewarded_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isRewarded(): bool
    {
        return $this->rewarded_at !== null;
    }
}