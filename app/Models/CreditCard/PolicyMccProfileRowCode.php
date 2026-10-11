<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PolicyMccProfileRowCode — một mã MCC trong một DÒNG hồ sơ.
 *
 * Mỗi mã là MỘT dòng (không nhét chuỗi mã vào cột JSON) nên truy vấn được và bảo
 * vệ trùng bằng UNIQUE [row, mcc]. `mcc_code_id` là khóa ngoại tới MCC master;
 * `mcc` giữ mã 4 ký tự để hiển thị ngay cả khi master bị xoá.
 *
 * @property int $id
 * @property int $policy_mcc_row_id
 * @property int|null $mcc_code_id
 * @property string $mcc
 */
class PolicyMccProfileRowCode extends CreditCardModel
{
    protected $table = 'credit_card_policy_mcc_row_codes';

    protected $fillable = [
        'policy_mcc_row_id',
        'mcc_code_id',
        'mcc',
    ];

    protected function casts(): array
    {
        return [
            'policy_mcc_row_id' => 'integer',
            'mcc_code_id' => 'integer',
            'mcc' => 'string',
        ];
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(PolicyMccProfileRow::class, 'policy_mcc_row_id');
    }

    public function mccCode(): BelongsTo
    {
        return $this->belongsTo(MccCode::class, 'mcc_code_id');
    }
}
