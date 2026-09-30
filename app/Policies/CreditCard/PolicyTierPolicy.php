<?php

namespace App\Policies\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTier;
use App\Models\User;
use App\Policies\CreditCard\Concerns\ResolvesOwningCard;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Quyền với bậc chi tiêu (Policy Tier).
 *
 * Bậc KHÔNG có `user_id`: nó thuộc thẻ qua
 * `tier → policyVersion → userCard → user_id` (xem `ResolvesOwningCard`).
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO `update` / `delete` CÒN CHẶN NỮA
 * ---------------------------------------------------------------------------
 * Sở hữu đúng KHÔNG đồng nghĩa được sửa. Bậc thuộc version đã `superseded` hoặc
 * `is_locked` là bản ghi lịch sử: giao dịch kỳ trước đã snapshot tier đó. Sửa ở
 * đây sẽ làm cashback lịch sử không còn tái lập được.
 *
 * Lớp phòng thủ thứ hai cùng nghĩa nằm ở `TierService::assertMutable()`; ở đây
 * chặn sớm hơn để controller trả 403 thay vì 409.
 */
class PolicyTierPolicy
{
    use HandlesAuthorization, ResolvesOwningCard;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PolicyTier $tier): bool
    {
        $ownerId = $this->ownerIdOfCard($this->owningCardOfTier($tier));

        return $ownerId !== null && $ownerId === (int) $user->id;
    }

    /**
     * Thêm bậc vào một policy version: version phải của user này và còn sửa được.
     */
    public function create(User $user, Policy $version): bool
    {
        $card = $version->userCard;

        if ($card === null || (int) $card->user_id !== (int) $user->id) {
            return false;
        }

        return ! $version->is_locked && $version->status !== Policy::STATUS_SUPERSEDED;
    }

    public function update(User $user, PolicyTier $tier): bool
    {
        if (! $this->view($user, $tier)) {
            return false;
        }

        return $this->versionIsMutable($tier);
    }

    public function delete(User $user, PolicyTier $tier): bool
    {
        if (! $this->view($user, $tier)) {
            return false;
        }

        return $this->versionIsMutable($tier);
    }

    /**
     * Nhân bản bậc sang version khác: đích phải thuộc chính user này và còn sửa được
     * (kiểm ở `create` trên version đích — controller truyền target vào riêng).
     * Bản gốc chỉ cần thuộc user: clone là phép COPY dữ liệu, không sửa bản gốc,
     * nên bậc thuộc version đã superseded/khoá vẫn có thể copy sang version mới.
     */
    public function clone(User $user, PolicyTier $tier): bool
    {
        return $this->view($user, $tier);
    }

    private function versionIsMutable(PolicyTier $tier): bool
    {
        $version = $tier->policyVersion;

        if ($version === null) {
            return false;
        }

        return ! $version->is_locked
            && $version->status !== Policy::STATUS_SUPERSEDED;
    }
}
