<?php

namespace App\Models\CreditCard;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Transaction — giao dịch của thẻ (nhập tay hoặc import Excel).
 *
 * KHÔNG có `calculation_date` (§15/§17). Ngày dùng để tính là `calc_basis`,
 * được derive từ `statement_date_basis` của thẻ.
 *
 * KHÔNG có ô nhập cashback: cashback LUÔN do hệ thống tính (§23).
 *
 * Vì tier RETROACTIVE, `cashback_*_snapshot` có thể được cập nhật lại khi tổng
 * chi tiêu kỳ thay đổi (chỉ khi kỳ còn `open`). Business rule của policy version
 * thì bất biến.
 *
 * @property int $id
 * @property int $user_card_id
 * @property int|null $statement_period_id
 * @property int|null $category_id
 * @property string|null $merchant
 * @property string $amount
 * @property string|null $note
 * @property CarbonImmutable $transaction_date
 * @property CarbonImmutable|null $posted_date
 * @property string $source
 * @property string|null $source_reference
 * @property int|null $policy_version_id
 * @property int|null $policy_tier_id
 * @property int|null $policy_tier_category_id
 * @property string|null $cashback_percent_snapshot
 * @property string|null $cashback_amount_snapshot
 * @property bool|null $is_eligible
 * @property string|null $ineligible_reason
 * @property CarbonImmutable|null $calc_basis
 * @property array|null $calc_meta
 * @property CarbonImmutable|null $calculated_at
 */
class Transaction extends CreditCardModel
{
    use SoftDeletes;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_EXCEL = 'excel';

    public const REASON_BELOW_MINIMUM_SPEND = 'below_minimum_spend';

    public const REASON_NO_POLICY = 'no_policy_version';

    public const REASON_NO_CATEGORY = 'no_category';

    public const REASON_NO_CATEGORY_RULE = 'no_category_rule';

    public const REASON_BELOW_MIN_TRANSACTION = 'below_min_transaction_amount';

    public const REASON_NO_TIER = 'no_matching_tier';

    protected $table = 'credit_card_transactions';

    protected $fillable = [
        'user_card_id',
        'statement_period_id',
        'category_id',
        'merchant',
        'amount',
        'note',
        'transaction_date',
        'posted_date',
        'source',
        'source_reference',
        'policy_version_id',
        'policy_tier_id',
        'policy_tier_category_id',
        'cashback_percent_snapshot',
        'cashback_amount_snapshot',
        'is_eligible',
        'ineligible_reason',
        'calc_basis',
        'calc_meta',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'user_card_id' => 'integer',
            'statement_period_id' => 'integer',
            'category_id' => 'integer',
            'amount' => 'decimal:2',
            'transaction_date' => 'date',
            'posted_date' => 'date',
            'policy_version_id' => 'integer',
            'policy_tier_id' => 'integer',
            'policy_tier_category_id' => 'integer',
            'cashback_percent_snapshot' => 'decimal:3',
            'cashback_amount_snapshot' => 'decimal:2',
            'is_eligible' => 'boolean',
            'calc_basis' => 'date',
            'calc_meta' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    public function userCard(): BelongsTo
    {
        return $this->belongsTo(UserCard::class, 'user_card_id');
    }

    public function statementPeriod(): BelongsTo
    {
        return $this->belongsTo(StatementPeriod::class, 'statement_period_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * Policy VERSION đã dùng để tính giao dịch này (snapshot trỏ đúng version).
     */
    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class, 'policy_version_id');
    }

    public function policyTier(): BelongsTo
    {
        return $this->belongsTo(PolicyTier::class, 'policy_tier_id');
    }

    public function policyTierCategory(): BelongsTo
    {
        return $this->belongsTo(PolicyTierCategory::class, 'policy_tier_category_id');
    }

    /**
     * Thứ tự xác định kỳ cho giao dịch này.
     *
     * `statement_date_basis` chỉ ảnh hưởng việc CHỌN NGÀY, không ảnh hưởng việc
     * tính cashback. Nếu basis = posted_date mà posted_date chưa có thì rơi về
     * transaction_date.
     */
    public function basisDate(string $statementDateBasis): CarbonImmutable
    {
        if ($statementDateBasis === UserCard::BASIS_POSTED_DATE && $this->posted_date !== null) {
            return $this->posted_date->toImmutable();
        }

        return $this->transaction_date->toImmutable();
    }

    public function scopeInPeriod(Builder $query, int $statementPeriodId): Builder
    {
        return $query->where('statement_period_id', $statementPeriodId);
    }

    public function scopeChronological(Builder $query): Builder
    {
        // Thứ tự cố định ⇒ phân bổ quota TẤT ĐỊNH (§16.2).
        return $query->orderBy('transaction_date')->orderBy('id');
    }

    public function scopeEligible(Builder $query): Builder
    {
        return $query->where('is_eligible', true);
    }
}
