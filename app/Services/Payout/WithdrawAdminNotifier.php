<?php

namespace App\Services\Payout;

use App\Models\PushSubscription;
use App\Models\User;
use App\Models\WithdrawRequest;
use App\Services\Push\WebPushSender;
use App\Services\Zalo\ZaloOaClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Báo admin khi có yêu cầu rút tiền mới: thông báo đẩy lên màn hình khoá (Web Push) cho các thiết bị
 * admin đã bật, và Zalo OA nếu có cấu hình. Bấm vào mở trang duyệt trên điện thoại.
 * Lỗi gửi chỉ ghi log — không bao giờ làm hỏng yêu cầu rút của user.
 */
class WithdrawAdminNotifier
{
    public function __construct(private ZaloOaClient $zalo, private WebPushSender $push) {}

    public function notifyNew(WithdrawRequest $request): void
    {
        $amount = number_format((float) $request->amount, 0, ',', '.').'đ';
        $pending = WithdrawRequest::pending()->count();
        $url = route('admin.payouts.index');

        $this->sendPush($request, $amount, $pending, $url);
        $this->sendZalo($request, $amount, $pending, $url);
    }

    private function sendPush(WithdrawRequest $request, string $amount, int $pending, string $url): void
    {
        try {
            $adminIds = User::permission('withdrawals.view')->pluck('id');
        } catch (Throwable $e) {
            // Chưa có permission (môi trường mới) thì bỏ qua.
            Log::warning('Không lấy được danh sách admin nhận web push', ['error' => $e->getMessage()]);

            return;
        }

        $this->push->send(
            PushSubscription::whereIn('user_id', $adminIds)->get(),
            "💸 Rút tiền {$amount}",
            "{$request->username} · {$request->bank_name}\nĐang chờ duyệt: {$pending} yêu cầu",
            $url,
        );
    }

    private function sendZalo(WithdrawRequest $request, string $amount, int $pending, string $url): void
    {
        $recipients = array_values(array_filter(array_map('trim', explode(',', (string) config('services.zalo_oa.admin_user_ids')))));

        if ($recipients === [] || ! $this->zalo->isConfigured()) {
            return;
        }

        $text = "💸 Yêu cầu rút tiền mới #{$request->id}\n"
            .'User: '.$request->username."\n"
            .'Số tiền: '.$amount."\n"
            .'Ngân hàng: '.$request->bank_name."\n"
            ."Đang chờ duyệt: {$pending} yêu cầu\n\n"
            .'Mở để chuyển khoản: '.$url;

        foreach ($recipients as $userId) {
            try {
                $this->zalo->sendText($userId, $text);
            } catch (Throwable $e) {
                Log::warning('Không gửi được Zalo báo rút tiền', ['withdraw_request_id' => $request->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
