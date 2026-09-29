<?php

namespace App\Policies\CreditCard;

use App\Models\CreditCard\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Quyền với Transaction.
 *
 * Giao dịch thuộc thẻ, thẻ thuộc user ⇒ phải resolve 2 bậc.
 */
class TransactionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Transaction $transaction): bool
    {
        $userCard = $transaction->userCard;

        return $userCard !== null && (int) $userCard->user_id === (int) $user->id;
    }

    public function create(User $user, ?Transaction $transaction = null): bool
    {
        return true;
    }

    public function update(User $user, Transaction $transaction): bool
    {
        // Giao dịch thuộc kỳ đã finalize là bản ghi lịch sử ⇒ không sửa được.
        $period = $transaction->statementPeriod;

        if ($period !== null && $period->isFinalized()) {
            return false;
        }

        return $this->view($user, $transaction);
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        $period = $transaction->statementPeriod;

        if ($period !== null && $period->isFinalized()) {
            return false;
        }

        return $this->view($user, $transaction);
    }
}
