<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * StatementPeriod — một kỳ sao kê.
 *
 * Ranh giới: kỳ kết thúc tại tháng M là [clamp(statement_day, tháng trước) + 1 ngày,
 * clamp(statement_day, tháng M)]. Vì vậy period_start(M) luôn = period_end(M-1) + 1
 * ⇒ không trùng, không hở, kể cả statement_day = 29/30/31.
 *
 * @property int $id
 * @property int $user_card_id
 * @property \Carbon\CarbonImmutable $period_start
 * @property \Carbon\CarbonImmutable $period_end
 * @property \Carbon\CarbonImmutable $statement_date
 * @property \Carbon\CarbonImmutable|null $payment_due_date
 * @property string $status
 * @property \Carbon\CarbonImmutable|null $finalized_at
 * @property string|null $total_eligible_spend
 * @property string|null $total_cashback
 * @property string|null $effective_cashback_rate
 * @property int|null $policy_id
 * @property array|null $calculation_meta
 */
class StatementPeriod extends CreditCardModel
{
    public const STATUS_OPEN = 'open';

    public const STATUS_FINALIZED = 'finalized';

    protected $table = 'credit_card_statement_periods';

    protected $fillable = [
        'user_card_id',
        'period_start',
        'period_end',
        'statement_date',
        'payment_due_date',
        'status',
        'finalized_at',
        'total_eligible_spend',
        'total_cashback',
        'effective_cashback_rate',
        'policy_id',
        'calculation_meta',
    ];

    protected function casts(): array
    {
        return [
            'user_card_id' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'statement_date' => 'date',
            'payment_due_date' => 'date',
            'finalized_at' => 'datetime',
            'total_eligible_spend' => 'decimal:2',
            'total_cashback' => 'decimal:2',
            'effective_cashback_rate' => 'decimal:3',
            'policy_id' => 'integer',
            'calculation_meta' => 'array',
        ];
    }

    public function userCard(): BelongsTo
    {
        return $this->belongsTo(UserCard::class, 'user_card_id');
    }

    /**
     * Policy Version đã resolve cho kỳ này.
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class, 'policy_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'statement_period_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    public function contains(\DateTimeInterface $date): bool
    {
        $date = \Carbon\CarbonImmutable::instance($date)->startOfDay();

        return $date->greaterThanOrEqualTo($this->period_start->startOfDay())
            && $date->lessThanOrEqualTo($this->period_end->startOfDay());
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeFinalized(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FINALIZED);
    }
}
