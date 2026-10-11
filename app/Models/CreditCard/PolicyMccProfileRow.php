<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * PolicyMccProfileRow — một DÒNG MCC trong một hồ sơ.
 *
 * `row_type`:
 *   included — MCC được hoàn tiền.
 *   excluded — MCC loại trừ (ưu tiên hơn áp dụng khi tra cứu, xem spec §6).
 *
 * `category_name` là TEXT tự do theo tài liệu ngân hàng, KHÔNG liên kết
 * `credit_card_categories`. Mỗi dòng có thể chứa NHIỀU mã ({@see PolicyMccProfileRowCode}).
 *
 * @property int $id
 * @property int $policy_mcc_profile_id
 * @property string $row_type
 * @property string|null $category_name
 * @property string|null $description
 * @property string|null $note
 * @property int $display_order
 */
class PolicyMccProfileRow extends CreditCardModel
{
    public const TYPE_INCLUDED = 'included';

    public const TYPE_EXCLUDED = 'excluded';

    protected $table = 'credit_card_policy_mcc_rows';

    protected $fillable = [
        'policy_mcc_profile_id',
        'row_type',
        'category_name',
        'description',
        'note',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'policy_mcc_profile_id' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PolicyMccProfile::class, 'policy_mcc_profile_id');
    }

    public function codes(): HasMany
    {
        return $this->hasMany(PolicyMccProfileRowCode::class, 'policy_mcc_row_id')
            ->orderBy('mcc');
    }

    public function isIncluded(): bool
    {
        return $this->row_type === self::TYPE_INCLUDED;
    }

    /**
     * @return Collection<int, string>
     */
    public function codeStrings(): Collection
    {
        return $this->codes
            ->pluck('mcc')
            ->map(fn ($code): string => (string) $code)
            ->values();
    }

    public function scopeIncluded(Builder $query): Builder
    {
        return $query->where('row_type', self::TYPE_INCLUDED);
    }

    public function scopeExcluded(Builder $query): Builder
    {
        return $query->where('row_type', self::TYPE_EXCLUDED);
    }
}
