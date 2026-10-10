<?php

namespace App\View\Composers;

use App\Models\WithdrawRequest;
use Illuminate\View\View;

/**
 * Cung cấp số yêu cầu rút tiền đang CHỜ XỬ LÝ (status = pending) cho navigation.
 *
 * Bảo mật: chỉ tính cho người dùng có quyền `withdrawals.view`. Người không có
 * quyền (kể cả người dùng thường) luôn nhận 0 và badge không được render
 * (xem `layouts/navigation.blade.php` dùng `@can('withdrawals.view')`).
 *
 * Hiệu năng: kết quả được memoize theo TỪNG request, nên dù navigation có được
 * render nhiều lần trong cùng một request thì truy vấn đếm cũng chỉ chạy một lần.
 * Giữa các request khác nhau thì luôn đọc lại trạng thái mới nhất từ database
 * (không lưu session/giá trị cũ).
 */
class PendingWithdrawBadgeComposer
{
    private const REQUEST_KEY = '_pending_withdraw_request_count';

    public function compose(View $view): void
    {
        $view->with('pendingWithdrawCount', $this->pendingCount());
    }

    private function pendingCount(): int
    {
        $request = request();

        if ($request->attributes->has(self::REQUEST_KEY)) {
            return (int) $request->attributes->get(self::REQUEST_KEY);
        }

        $user = $request->user();

        $count = ($user !== null && $user->can('withdrawals.view'))
            ? WithdrawRequest::query()->pending()->count()
            : 0;

        $request->attributes->set(self::REQUEST_KEY, $count);

        return $count;
    }
}
