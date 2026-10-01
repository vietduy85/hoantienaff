<?php

namespace App\Policies\CreditCard;

use App\Models\CreditCard\CategoryCombo;
use App\Models\User;

/**
 * Quyền với CategoryCombo.
 */
class CategoryComboPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CategoryCombo $combo): bool
    {
        if ($combo->isSystem()) {
            return true;
        }

        return (int) $combo->owner_user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, CategoryCombo $combo): bool
    {
        if ($combo->isSystem()) {
            return false;
        }

        return (int) $combo->owner_user_id === (int) $user->id;
    }

    public function delete(User $user, CategoryCombo $combo): bool
    {
        return $this->update($user, $combo);
    }
}
