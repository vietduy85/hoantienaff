<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PolicyMccGroupCode — một mã MCC trong một nhóm MCC ({@see PolicyMccGroup}).
 *
 * Mỗi mã là MỘT dòng (không nhét chuỗi mã vào một cột JSON) nên truy vấn được và
 * kiểm tra trùng bằng UNIQUE [group, mcc]. `role`:
 *   applied  — mã được áp dụng hoàn tiền theo nhóm.
 *   excluded — mã bị loại trừ RIÊNG trong nhóm này.
 *
 * `mcc_code_id` là khóa ngoại tới MCC master; `mcc` giữ mã 4 ký tự để hiển thị.
 *
 * @property int $id
 * @property int|null $policy_mcc_group_id
 * @property int|null $mcc_code_id
 * @property string $mcc
 * @property string $role
 */
class PolicyMccGroupCode extends CreditCardModel
{
    public const ROLE_APPLIED = 'applied';

    public const ROLE_EXCLUDED = 'excluded';

    protected $table = 'credit_card_policy_mcc_group_codes';

    protected $fillable = [
        'policy_mcc_group_id',
        'mcc_code_id',
        'mcc',
        'role',
    ];

    protected function casts(): array
    {
        return [
            'policy_mcc_group_id' => 'integer',
            'mcc_code_id' => 'integer',
            'mcc' => 'string',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(PolicyMccGroup::class, 'policy_mcc_group_id');
    }

    public function mccCode(): BelongsTo
    {
        return $this->belongsTo(MccCode::class, 'mcc_code_id');
    }
}
