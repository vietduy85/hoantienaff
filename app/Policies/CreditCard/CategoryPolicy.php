<?php

namespace App\Policies\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Quyền với Spending Category.
 *
 * ---------------------------------------------------------------------------
 * HAI SCOPE, HAI QUY TẮC KHÁC NHAU
 * ---------------------------------------------------------------------------
 * `scope = 'system'`, `owner_user_id = 0`:
 *   - Ai cũng ĐỌC được (danh mục chung của hệ thống).
 *   - KHÔNG ai sửa/xoá được, kể cả admin user thường: danh sách 19 danh mục là
 *     master data do owner chốt, chỉ đổi qua seeder. Nếu cho sửa, user sẽ đổi
 *     tên "Shopee" thành tên riêng ⇒ mọi policy rule đã cấu hình theo danh mục đó
 *     trở nên khó tra cứu, và báo cáo so sánh mất ý nghĩa.
 *
 * `scope = 'user'`, `owner_user_id = N`:
 *   - Chỉ CHỦ NHÂN được đọc/ghi. User khác không được thấy, kể cả qua endpoint
 *     index — nên mọi truy vấn đều phải scope theo `scopeSelectableBy($userId)`.
 *
 * ---------------------------------------------------------------------------
 * USAGE GUARD
 * ---------------------------------------------------------------------------
 * Việc "danh mục đang được dùng thì không hard-delete" được xử lý ở
 * `CategoryService::delete()` (cần truy vấn bổ sung), không thuần policy: policy
 * chỉ trả lời "có được phép xoá về mặt quyền" hay không.
 */
class CategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Danh mục hệ thống: ai cũng đọc được.
     * Danh mục riêng: chỉ chủ nhân.
     */
    public function view(User $user, Category $category): bool
    {
        if ($category->isSystem()) {
            return true;
        }

        return $this->owns($user, $category);
    }

    /**
     * Chỉ tạo được danh mục RIÊNG của mình — không có API tạo danh mục hệ thống.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Danh mục hệ thống bất biến: KHÔNG cho sửa bởi user (kể cả admin).
     * Danh mục riêng: chỉ chủ nhân.
     */
    public function update(User $user, Category $category): bool
    {
        if ($category->isSystem()) {
            return false;
        }

        return $this->owns($user, $category);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->update($user, $category);
    }

    /**
     * Gán danh mục lên giao dịch: system bất biến nên ai cũng dùng được,
     * nhưng danh mục riêng thì chỉ chủ nhân mới dùng được.
     */
    public function use(User $user, Category $category): bool
    {
        return $this->view($user, $category);
    }

    private function owns(User $user, Category $category): bool
    {
        return (int) $category->owner_user_id === (int) $user->id
            && $category->scope === Category::SCOPE_USER;
    }
}
