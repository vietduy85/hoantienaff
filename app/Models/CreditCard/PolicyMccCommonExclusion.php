<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PolicyMccCommonExclusion — MCC loại trừ CHUNG của cả một chính sách hoàn tiền.
 *
 * ĐỘC LẬP với MCC loại trừ riêng của từng nhóm ({@see PolicyMccGroupCode} role
 * = excluded): áp dụng cho TOÀN BỘ các nhóm của chính sách khi tra cứu.
 *
 * @property int $id
 * @property int|null $policy_template_id
 * @property int|null $mcc_code_id
 * @property string $mcc
 * @property string|null $note
 */
class PolicyMccCommonExclusion extends CreditCardModel
{
    protected $table = 'credit_card_policy_mcc_common_exclusions';

    protected $fillable = [
        'policy_template_id',
        'mcc_code_id',
        'mcc',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'policy_template_id' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PolicyTemplate::class, 'policy_template_id');
    }

    public function mccCode(): BelongsTo
    {
        return $this->belongsTo(MccCode::class, 'mcc_code_id');
    }

    public function scopeForTemplate(Builder $query, int $templateId): Builder
    {
        return $query->where('policy_template_id', $templateId);
    }
}
