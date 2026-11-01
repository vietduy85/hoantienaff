<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Gửi thông báo đẩy (Web Push, khoá VAPID trong .env) tới các thiết bị đã bấm "Bật thông báo".
 * Thiết bị đã gỡ quyền / hết hạn (404, 410) bị xoá khỏi danh sách.
 */
class WebPushSender
{
    public function isConfigured(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'));
    }

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @return int số thiết bị gửi thành công
     */
    public function send(Collection $subscriptions, string $title, string $body, string $url): int
    {
        if ($subscriptions->isEmpty() || ! $this->isConfigured()) {
            return 0;
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ]], ['TTL' => 86400, 'urgency' => 'high'], new \GuzzleHttp\Client(['timeout' => 10]));

            $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url], JSON_UNESCAPED_UNICODE);

            foreach ($subscriptions as $sub) {
                $webPush->queueNotification(Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->public_key,
                    'authToken' => $sub->auth_token,
                ]), $payload);
            }

            $sent = 0;
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $sent++;
                } elseif ($report->isSubscriptionExpired()) {
                    PushSubscription::where('endpoint_hash', hash('sha256', $report->getEndpoint()))->delete();
                } else {
                    Log::warning('Web push thất bại', ['reason' => $report->getReason()]);
                }
            }

            Log::info('Web push đã gửi', ['title' => $title, 'sent' => $sent, 'devices' => $subscriptions->count()]);

            return $sent;
        } catch (Throwable $e) {
            Log::warning('Web push lỗi', ['error' => $e->getMessage()]);

            return 0;
        }
    }
}
