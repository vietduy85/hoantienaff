<?php

namespace App\View\Composers;

use App\Services\ShopeeFashionAlertService;
use Illuminate\View\View;

/**
 * Cung cấp số đơn Shopee thời trang "cào xu" CHƯA xác nhận cho navigation
 * dùng chung (badge đỏ cạnh mục "Trang chủ").
 *
 * Bảo mật: chỉ tính cho người dùng đang đăng nhập và CHƯA tắt chức năng;
 * khách (guest) luôn nhận 0 và badge không được render.
 *
 * Hiệu năng: kết quả memoize theo TỪNG request (request attributes) nên dù
 * navigation được render nhiều lần trong cùng một request thì truy vấn cũng
 * chỉ chạy một lần. Giữa các request luôn đọc lại trạng thái mới nhất từ DB.
 *
 * Tách biệt: composer này KHÔNG ghi đè badge yêu cầu rút tiền
 * (`pendingWithdrawCount`) — hai badge được render độc lập cạnh nhau.
 */
class ShopeeFashionBadgeComposer
{
    private const REQUEST_KEY = '_shopee_fashion_alert_unseen_count';

    public function __construct(
        private readonly ShopeeFashionAlertService $service,
    ) {}

    public function compose(View $view): void
    {
        $view->with('shopeeFashionBadgeCount', $this->unseenCount());
    }

    private function unseenCount(): int
    {
        $request = request();

        if ($request->attributes->has(self::REQUEST_KEY)) {
            return (int) $request->attributes->get(self::REQUEST_KEY);
        }

        $user = $request->user();

        $count = ($user !== null && $this->service->isEnabled($user))
            ? $this->service->unseenCount($user)
            : 0;

        $request->attributes->set(self::REQUEST_KEY, $count);

        return $count;
    }
}
