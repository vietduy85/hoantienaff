<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Models\WithdrawRequest;
use App\Services\Push\WebPushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Lưu/xoá thiết bị nhận thông báo đẩy của admin (nút "Bật thông báo" trên trang duyệt rút tiền). */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => 'required|url|max:2000',
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint');

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $endpoint))
            ->delete();

        return response()->json(['ok' => true]);
    }

    /** Service worker gọi về khi đã nhận push và hiện thông báo — chỉ để dò lỗi trên điện thoại. */
    public function received(Request $request): JsonResponse
    {
        Log::info('Web push: thiết bị đã nhận', [
            'user_id' => $request->user()?->id,
            'sw_version' => $request->query('v'),
            'ua' => Str::limit((string) $request->userAgent(), 120, ''),
        ]);

        return response()->json(['ok' => true]);
    }

    /** Gửi thử tới các thiết bị của chính admin đang đăng nhập. */
    public function test(Request $request, WebPushSender $sender): JsonResponse
    {
        $sent = $sender->send(
            PushSubscription::where('user_id', $request->user()->id)->get(),
            '🔔 Thông báo đã bật',
            'Bạn sẽ nhận báo khi có yêu cầu rút tiền mới. Đang chờ: '.WithdrawRequest::pending()->count(),
            route('admin.payouts.index'),
        );

        return response()->json(['ok' => $sent > 0, 'sent' => $sent]);
    }
}
