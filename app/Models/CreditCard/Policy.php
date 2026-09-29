<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Policy — Card Policy của một thẻ (và chuỗi version của nó).
 *
 * Ba dạng bản ghi trong cùng bảng `credit_card_policies`:
 *   1. Template blueprint : user_card_id = NULL
 *   2. Policy root của thẻ: user_card_id = Y, version_no = 1, root_policy_id = chính nó
 *   3. Policy version     : version_no >= 2, root_policy_id trỏ về root
 *
 * User KHÔNG chọn version — hệ thống tự resolve theo kỳ sao kê (§9.1).
 *
 * @property int $id
 * @property int|null $user_card_id
 * @property int|null $template_id
 * @property int|null $root_policy_id
 * @property int $version_no
 * @property string $status
 * @property string $name
 * @property \Carbon\CarbonImmutable $effective_from
 * @property \Carbon\CarbonImmutable|null $effective_to
 * @property string $min_total_spend
 * @property string|null $max_cashback_total_per_period
 * @property string $rounding_mode
 * @property bool $is_locked
 */
class Policy extends CreditCardModel
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $table = 'credit_card_policies';

    protected $fillable = [
        'user_card_id',
        'template_id',
        'root_policy_id',
        'version_no',
        'status',
        'name',
        'effective_from',
        'effective_to',
        'min_total_spend',
        'max_cashback_total_per_period',
        'rounding_mode',
        'note',
        'is_locked',
    ];

    protected function casts(): array
    {
        return [
            'root_policy_id' => 'integer',
            'version_no' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'min_total_spend' => 'decimal:2',
            'max_cashback_total_per_period' => 'decimal:2',
            'is_locked' => 'boolean',
        ];
    }

    public function userCard(): BelongsTo
    {
        return $this->belongsTo(UserCard::class, 'user_card_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PolicyTemplate::class, 'template_id');
    }

    /**
     * Bản ghi root của chuỗi version. Với bản ghi root, quan hệ này trỏ về chính nó.
     */
    public function root(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_policy_id');
    }

    /**
     * Các version sau bản ghi này (bản ghi root trả về version 2..n).
     */
    public function laterVersions(): HasMany
    {
        return $this->hasMany(PolicyVersion::class, 'root_policy_id')
            ->whereColumn('credit_card_policies.id', '!=', 'credit_card_policies.root_policy_id')
            ->orderBy('version_no');
    }

    /**
     * Toàn bộ version của chuỗi (bao gồm chính nó nếu đây là bản ghi root).
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PolicyVersion::class, 'root_policy_id')->orderBy('version_no');
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(PolicyTier::class, 'policy_id')->orderBy('sort_order');
    }

    public function statementPeriods(): HasMany
    {
        return $this->hasMany(StatementPeriod::class, 'policy_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'policy_version_id');
    }

    public function isRoot(): bool
    {
        return $this->root_policy_id === null || (int) $this->root_policy_id === (int) $this->id;
    }

    public function isBlueprint(): bool
    {
        return $this->user_card_id === null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForCard(Builder $query, int $userCardId): Builder
    {
        return $query->where('user_card_id', $userCardId);
    }

    public function scopeEffectiveOn(Builder $query, \DateTimeInterface $date): Builder
    {
        return $query->whereDate('effective_from', '<=', $date)
            ->where(function (Builder $inner) use ($date): void {
                $inner->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            });
    }
}
