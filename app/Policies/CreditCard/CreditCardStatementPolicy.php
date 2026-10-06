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
     * Đổi trạng thái thanh toán / nhắc — CHỈ kiểm sở hữu, KHÔNG chặn kỳ đã chốt.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO CỐ Ý KHÁC `update`
     * ---------------------------------------------------------------------------
     * `update` (sửa tiền) chặn kỳ đã chốt vì số tiền của kỳ đó là bản ghi lịch sử.
     * Trạng thái thanh toán thì NGƯỢC LẠI: trả hóa đơn xảy ra *sau* khi kỳ đã
     * đóng. Nếu chặn luôn ở đây thì đúng những kỳ cần nhắc nhất — kỳ đã chốt, đang
     * chờ trả — lại không đánh dấu "đã trả" và không tắt được cảnh báo. Người dùng
     * sẽ bị báo "đến hạn" mãi cho một hóa đơn đã trả.
     *
     * Vì vậy action này tách riêng thay vì dùng lại `update`: quyền khác nhau thì
     * phải là hai method khác nhau, đừng nhồi vào một chỗ rồi lạm dụng.
     */
    public function updatePayment(User $user, CreditCardStatement $statement): bool
    {
        return $this->ownsStatement($user, $statement);
    }

    /**
     * Đổi trạng thái thanh toán theo KỲ, kể cả khi kỳ chưa có dòng sao kê.
     *
     * ---------------------------------------------------------------------------
     * TẠI SAO CẦN MỘT ACTION RIÊNG
     * ---------------------------------------------------------------------------
     * `updatePayment()` nhận dòng sao kê, nên gọi nó lúc kỳ CHƯA có dòng là không
     * gọi được — và đó chính là tình huống duy nhất mà người dùng cần đánh dấu:
     * kỳ vừa chốt, chưa kịp nhập số liệu. Action này nhận (user, thẻ) nên chạy
     * được cho cả hai trường hợp, và quyền thì GIỐNG HỆT: chỉ sở hữu thẻ, không
     * chặn kỳ đã chốt — vẫn lý do ở `updatePayment()`.
     *
     * Chuyện có tạo dòng `0/0/0` hay không KHÔNG thuộc policy: đó là quy tắc nghiệp
     * vụ, và nó nằm ở `CreditCardStatementService::setPaymentStatusForPeriod()`.
     *
     * @param  UserCard  $card  thẻ có kỳ cần đánh dấu trạng thái
     */
    public function updatePaymentForPeriod(User $user, UserCard $card): bool
    {
        return $this->ownsCard($user, $card);
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
