<?php

namespace App\Policies\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\User;
use App\Policies\CreditCard\Concerns\ResolvesOwningCard;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Quyền với quy tắc cashback của một danh mục trong một bậc (Category Rule).
 *
 * Rule không có `user_id`: `rule → tier → policyVersion → userCard → user_id`.
 *
 * Mọi quyền ở đây TRÙNG với quyền của bậc chứa nó, vì sửa rule cũng là sửa cấu
 * hình cashback của bậc — cùng một ràng buộc bất biến lịch sử.
 */
class PolicyTierCategoryPolicy
{
    use HandlesAuthorization, ResolvesOwningCard;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PolicyTierCategory $rule): bool
    {
        $ownerId = $this->ownerIdOfCard($this->owningCardOfRule($rule));

        return $ownerId !== null && $ownerId === (int) $user->id;
    }

    public function create(User $user, PolicyTier $tier): bool
    {
        $ownerId = $this->ownerIdOfCard($this->owningCardOfTier($tier));

        return $ownerId !== null
            && $ownerId === (int) $user->id
            && $this->versionIsMutable($tier);
    }

    public function update(User $user, PolicyTierCategory $rule): bool
    {
        if (! $this->view($user, $rule)) {
            return false;
        }

        $tier = $rule->tier;

        return $tier !== null && $this->versionIsMutable($tier);
    }

    public function delete(User $user, PolicyTierCategory $rule): bool
    {
        return $this->update($user, $rule);
    }

    /**
     * Nhân bản rule sang bậc khác: clone là phép COPY dữ liệu, bản gốc chỉ cần
     * thuộc user; đích được kiểm riêng qua `create` trên bậc target trong controller.
     */
    public function clone(User $user, PolicyTierCategory $rule): bool
    {
        return $this->view($user, $rule);
    }

    private function versionIsMutable(PolicyTier $tier): bool
    {
        $version = $tier->policyVersion;

        if ($version === null) {
            return false;
        }

        return ! $version->is_locked && $version->status !== Policy::STATUS_SUPERSEDED;
    }
}
