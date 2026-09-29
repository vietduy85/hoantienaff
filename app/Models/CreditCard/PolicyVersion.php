<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PolicyVersion — MỘT version cụ thể của Card Policy.
 *
 * Cố ý dùng CHUNG bảng `credit_card_policies` với `Policy` (spec Phase 1A chốt
 * đúng 10 bảng, không có bảng `credit_card_policy_versions`).
 *
 * Version là khái niệm NỘI BỘ: user nhìn thấy "MB JCB Ultimate Cashback", không
 * thấy "Version 1 / 2 / 3". Hệ thống tự resolve version theo kỳ sao kê.
 *
 * Business rules của một version là BẤT BIẾN. Khi policy đổi, `PolicyCloneService`
 * tạo version mới; version cũ chỉ bị đóng `effective_to` + chuyển `superseded`
 * (metadata vòng đời, không phải business rule).
 *
 * @property int $id
 * @property int|null $user_card_id
 * @property int $root_policy_id
 * @property int $version_no
 * @property string $status
 * @property string $name
 */
class PolicyVersion extends Policy
{
    /**
     * Bản ghi root (version_no = 1, root_policy_id NULL lúc tạo).
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class, 'root_policy_id');
    }

    public function isFirstVersion(): bool
    {
        return (int) $this->version_no === 1;
    }
}
