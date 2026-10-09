<?php

namespace App\Policies\CreditCard;

use App\Models\CreditCard\Report;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Quyền với báo cáo của user.
 *
 * `user_id` là logical reference sang `hoantienaff.users` — so sánh số, không
 * cần (và không được có) foreign key vật lý. Chỉ chủ báo cáo được xem/sửa/xoá;
 * báo cáo của người khác trả 403 (không lọc owner ở tầng truy vấn để người dùng
 * hiểu đúng "không phải của bạn" thay vì "không tồn tại").
 */
class ReportPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Report $report): bool
    {
        return (int) $report->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Report $report): bool
    {
        return $this->view($user, $report);
    }

    public function delete(User $user, Report $report): bool
    {
        return $this->view($user, $report);
    }
}
