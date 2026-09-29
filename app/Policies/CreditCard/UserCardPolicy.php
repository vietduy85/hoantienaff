<?php

namespace App\Policies\CreditCard;

use App\Models\CreditCard\UserCard;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Quyền với User Card.
 *
 * `user_id` là logical reference sang `hoantienaff.users` — so sánh số, không
 * cần (và không được có) foreign key vật lý.
 */
class UserCardPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, UserCard $userCard): bool
    {
        return (int) $userCard->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, UserCard $userCard): bool
    {
        return $this->view($user, $userCard);
    }

    public function delete(User $user, UserCard $userCard): bool
    {
        return $this->view($user, $userCard);
    }
}
