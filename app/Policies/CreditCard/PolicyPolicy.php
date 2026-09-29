<?php

namespace App\Policies\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Quyền với Card Policy.
 *
 * Policy thuộc quyền sở hữu của THẺ (qua `user_card_id`), không phải của user trực tiếp.
 * Nên phải resolve `user_card.user_id` trước khi so sánh.
 *
 * Lưu ý: version đã `superseded` là bản ghi lịch sử ⇒ không sửa được.
 */
class PolicyPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Policy $policy): bool
    {
        if ($policy->isBlueprint()) {
            // Policy blueprint chỉ thuộc về template; user nhìn qua template.
            return true;
        }

        $userCard = $policy->userCard;

        return $userCard !== null && (int) $userCard->user_id === (int) $user->id;
    }

    public function update(User $user, Policy $policy): bool
    {
        if (! $this->view($user, $policy)) {
            return false;
        }

        // Bản ghi đã superseded là lịch sử bất biến.
        return $policy->status !== Policy::STATUS_SUPERSEDED;
    }

    public function delete(User $user, Policy $policy): bool
    {
        if (! $this->view($user, $policy)) {
            return false;
        }

        return $policy->status === Policy::STATUS_DRAFT;
    }
}
