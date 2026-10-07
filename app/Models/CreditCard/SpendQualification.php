<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SpendQualification — bộ điều kiện chi tiêu gắn với MỘT policy version.
 *
 * `policy_version_id` UNIQUE ⇒ mỗi version có tối đa một bộ điều kiện. Khi clone
 * version (append-only), bộ điều kiện được sao chép thành bản riêng — khối này
 * là tài sản của version, KHÔNG bao giờ được sửa chung với template hay version
 * khác.
 *
 * `source_template_id` chỉ là dấu vết nguồn clone (trace). Engine tính cashback
 * KHÔNG bao giờ đọc template qua con trỏ này tại runtime (§26 cấm).
 *
 * `enabled = false`, hoặc không có điều kiện nào bật ⇒ no-op (cashback tính như
 * cũ) — xem `SpendQualificationService::evaluateForPeriod()`.
 *
 * @property-read \Illuminate\Support\Collection<int, SpendQualificationCondition> $conditions
 */
class SpendQualification extends CreditCardModel
{
    protected $table = 'credit_card_spend_qualifications';

    protected $fillable = [
        'policy_version_id',
        'source_template_id',
        'name',
        'note',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'policy_version_id' => 'integer',
            'source_template_id' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class, 'policy_version_id');
    }

    public function sourceTemplate(): BelongsTo
    {
        return $this->belongsTo(SpendQualificationTemplate::class, 'source_template_id');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(SpendQualificationCondition::class, 'qualification_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function enabledConditions(): HasMany
    {
        return $this->hasMany(SpendQualificationCondition::class, 'qualification_id')
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function isEnabled(): bool
    {
        return (bool) $this->enabled;
    }
}