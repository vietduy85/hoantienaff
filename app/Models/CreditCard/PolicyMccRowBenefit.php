<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PolicyMccRowBenefit — DỮ LIỆU HOÀN TIỀN theo một DÒNG MCC của hồ sơ.
 *
 * Mỗi bản ghi là một BẬC/điều kiện hoàn tiền trích từ TÀI LIỆU NGÂN HÀNG GỐC
 * (không suy diễn). Thuộc đúng một {@see PolicyMccProfileRow}; dòng bị xoá thì
 * bản ghi bị xoá theo.
 *
 * CHỈ ĐỌC đối với engine cashback: bảng này KHÔNG tham gia tính hoàn tiền, chỉ
 * phục vụ tra cứu/đối chiếu tài liệu (§ "Tra cứu MCC").
 *
 * @property int $id
 * @property int $policy_mcc_row_id
 * @property int $tier_order
 * @property string|null $cashback_percent
 * @property string|null $rate_label
 * @property string|null $min_spend
 * @property string|null $max_spend
 * @property string|null $cap_amount
 * @property string|null $cap_period
 * @property string|null $cap_unit
 * @property string|null $condition_note
 * @property string|null $exception_note
 * @property int|null $source_document_id
 * @property string|null $source_page
 * @property string|null $source_note
 */
class PolicyMccRowBenefit extends CreditCardModel
{
    protected $table = 'credit_card_policy_mcc_row_benefits';

    protected $fillable = [
        'policy_mcc_row_id',
        'tier_order',
        'cashback_percent',
        'rate_label',
        'min_spend',
        'max_spend',
        'cap_amount',
        'cap_period',
        'cap_unit',
        'condition_note',
        'exception_note',
        'source_document_id',
        'source_page',
        'source_note',
    ];

    protected function casts(): array
    {
        return [
            'policy_mcc_row_id' => 'integer',
            'tier_order' => 'integer',
            'cashback_percent' => 'decimal:3',
            'min_spend' => 'decimal:2',
            'max_spend' => 'decimal:2',
            'cap_amount' => 'decimal:2',
            'source_document_id' => 'integer',
        ];
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(PolicyMccProfileRow::class, 'policy_mcc_row_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PolicyMccProfileDocument::class, 'source_document_id');
    }

    public function hasRate(): bool
    {
        return $this->cashback_percent !== null || $this->rate_label !== null;
    }
}
