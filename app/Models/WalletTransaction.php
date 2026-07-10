<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    use HasFactory;
    public const TYPE_CASHBACK = 'cashback';
    public const TYPE_WITHDRAW = 'withdraw';
    public const TYPE_PROMOTION = 'promotion';
    public const TYPE_BONUS = 'bonus';
    public const TYPE_REFERRAL = 'referral';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_REFUND = 'refund';

    public const DIRECTION_CREDIT = 'credit';
    public const DIRECTION_DEBIT = 'debit';

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    public const TYPES = [
        self::TYPE_CASHBACK,
        self::TYPE_WITHDRAW,
        self::TYPE_PROMOTION,
        self::TYPE_BONUS,
        self::TYPE_REFERRAL,
        self::TYPE_ADJUSTMENT,
        self::TYPE_REFUND,
    ];

    public const DIRECTIONS = [
        self::DIRECTION_CREDIT,
        self::DIRECTION_DEBIT,
    ];

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'running_no',
        'user_id',
        'username',
        'platform',
        'type',
        'direction',
        'amount',
        'balance_before',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
        'status',
        'completed_at',
        'note',
        'processed_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeCredit(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_CREDIT);
    }

    public function scopeDebit(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_DEBIT);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function isCredit(): bool
    {
        return $this->direction === self::DIRECTION_CREDIT;
    }

    public function isDebit(): bool
    {
        return $this->direction === self::DIRECTION_DEBIT;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
