<?php

namespace App\Policies\CreditCard;

use App\Models\CreditCard\CreditCardStatement;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Quyền với sao kê thực tế.
 *
 * Sao kê thuộc thẻ, thẻ thuộc user ⇒ phải resolve 2 bậc, giống
 * {@see TransactionPolicy}.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO `viewAny` KHÔNG NHẬN `UserCard`
 * ---------------------------------------------------------------------------
 * Laravel gọi `viewAny($user)` KHÔNG kèm model. Tham số thẻ chỉ có ở các action
 * gắn với một thẻ cụ thể (`create` nhận `UserCard` qua
 * `authorize('create', [CreditCardStatement::class, $card])`, `update`/`delete`
 * nhận chính dòng sao kê). `viewAny` chỉ cần user đã đăng nhập — dữ liệu đã bị
 * scope theo `auth()->id()` ngay ở truy vấn, không có chuyện lộ thẻ của người khác.
 */
class CreditCardStatementPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CreditCardStatement $statement): bool
    {
        return $this->ownsStatement($user, $statement);
    }

    /**
     * @param  UserCard  $card  thẻ sắp nhập sao kê
     */
    public function create(User $user, UserCard $card): bool
    {
        return $this->ownsCard($user, $card);
    }

    public function update(User $user, CreditCardStatement $statement): bool
    {
        if ($this->periodIsFinalized($statement)) {
            return false;
        }

        return $this->ownsStatement($user, $statement);
    }

    public function delete(User $user, CreditCardStatement $statement): bool
    {
        if ($this->periodIsFinalized($statement)) {
            return false;
        }

        return $this->ownsStatement($user, $statement);
    }

    /**
     * Sao kê của kỳ đã chốt là bản ghi lịch sử ⇒ không sửa/xoá được.
     *
     * Chặn ở POLICY (403) chứ không đợi service báo lỗi (422): "kỳ đã chốt" là
     * quyền, còn "sai định dạng" mới là lỗi dữ liệu. Giống hệt `TransactionPolicy`.
     *
     * Kỳ không còn nữa (`null`) KHÔNG chặn: đó là dữ liệu mồ côi chứ không phải kỳ
     * đã chốt, và chặn ở đây sẽ khoá luôn việc dọn dữ liệu rác.
     */
    private function periodIsFinalized(CreditCardStatement $statement): bool
    {
        $period = $statement->statementPeriod;

        return $period !== null && $period->isFinalized();
    }

    /**
     * Bản ghi mồ côi (không còn thẻ) là dữ liệu hỏng ⇒ từ chối. Dùng `?->` ở đây
     * sẽ biến "hỏng dữ liệu" thành "cho qua" — xem `ResolvesOwningCard`.
     */
    private function ownsStatement(User $user, CreditCardStatement $statement): bool
    {
        $card = $statement->userCard;

        return $card !== null && $this->ownsCard($user, $card);
    }

    private function ownsCard(User $user, UserCard $card): bool
    {
        return (int) $card->user_id === (int) $user->id;
    }
}
