<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PolicyMccProfileTemplateLink — liên kết HỒ SƠ MCC ↔ template DÙNG CHUNG.
 *
 * Một chương trình ngân hàng có thể dùng chung tài liệu + danh mục MCC cho nhiều
 * HẠNG thẻ, mỗi hạng là một {@see PolicyTemplate} hệ thống riêng (VIB Max Card:
 * Starter/Plus/Pro/Elite; VIB One Card: Gold/Platinum/Signature).
 *
 * Hồ sơ vẫn thuộc template SỞ HỮU qua `policy_mcc_profiles.policy_template_id`
 * (nguồn chính); bảng liên kết này bổ sung các template DÙNG CHUNG thêm — KHÔNG
 * nhân bản hồ sơ/dòng/mã/tài liệu.
 *
 * @property int $id
 * @property int $policy_mcc_profile_id
 * @property int $policy_template_id
 */
class PolicyMccProfileTemplateLink extends CreditCardModel
{
    protected $table = 'credit_card_policy_mcc_profile_template_links';

    protected $fillable = [
        'policy_mcc_profile_id',
        'policy_template_id',
    ];

    protected function casts(): array
    {
        return [
            'policy_mcc_profile_id' => 'integer',
            'policy_template_id' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PolicyMccProfile::class, 'policy_mcc_profile_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PolicyTemplate::class, 'policy_template_id');
    }
}
