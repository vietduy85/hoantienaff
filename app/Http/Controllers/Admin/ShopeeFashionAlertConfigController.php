<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Trang Admin "Cập nhật link cào xu Shopee".
 *
 * Cho phép Admin thay đổi URL ĐÍCH (href) được mở khi người dùng nhấn vào
 * liên kết cào xu trong thông báo trên trang chủ, và URL HIỂN THỊ trên giao
 * diện. Hai giá trị lưu độc lập trong bảng `settings` (tái sử dụng cơ chế
 * Setting sẵn có).
 *
 * Chỉ chấp nhận URL HTTPS hợp lệ (loại bỏ javascript:/data:/file:/http).
 * URL được lưu nguyên văn — không chuẩn hoá để không làm hỏng mã giới thiệu
 * affiliate (path + query string được giữ nguyên).
 */
class ShopeeFashionAlertConfigController extends Controller
{
    public function index(): View
    {
        return view('admin.shopee-fashion-alert-config.index', [
            'destinationUrl' => Setting::shopeeFashionAlertDestinationUrl(),
            'displayUrl' => Setting::shopeeFashionAlertDisplayUrl(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'destination_url' => ['required', 'string', 'max:2048', 'url:https'],
            'display_url' => ['required', 'string', 'max:2048', 'url:https'],
        ]);

        Setting::set(Setting::SHOPEE_FASHION_ALERT_DESTINATION_URL, $validated['destination_url']);
        Setting::set(Setting::SHOPEE_FASHION_ALERT_DISPLAY_URL, $validated['display_url']);

        return redirect()->route('admin.shopee-fashion-alert-config.index')
            ->with('success', 'Đã lưu cấu hình link cào xu.');
    }
}
