<?php

namespace App\Policies\CreditCard;

use App\Models\CreditCard\PolicyTemplate;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Quyền với Policy Template.
 *
 * - System template: user KHÔNG BAO GIỜ sửa được. Chỉ admin.
 * - User template: chỉ chủ sở hữu được sửa/xoá.
 *
 * Việc thêm/sửa template LUÔN đi qua `PolicyCloneService` (deep clone), nên policy
 * này chỉ kiểm soát quyền, không kiểm soát cơ chế clone.
 */
class PolicyTemplatePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PolicyTemplate $template): bool
    {
        return $template->isSystemScope() || $template->isOwnedBy((int) $user->id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PolicyTemplate $template): bool
    {
        if ($template->isSystemScope()) {
            return $user->isAdmin();
        }

        return $template->isOwnedBy((int) $user->id);
    }

    public function delete(User $user, PolicyTemplate $template): bool
    {
        if ($template->is_builtin) {
            return false;
        }

        if ($template->isSystemScope()) {
            return $user->isAdmin();
        }

        return $template->isOwnedBy((int) $user->id);
    }
}
