<?php

namespace App\Services\Zalo;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Gửi tin nhắn tư vấn (CS) từ Zalo Official Account tới người đã quan tâm OA.
 *
 * Token: access token sống ~25 giờ; refresh token dùng MỘT lần, mỗi lần làm mới Zalo trả cặp mới
 * nên phải lưu lại ngay (bảng settings). Token đầu tiên nạp bằng `php artisan zalo-oa:token`.
 * Giới hạn của Zalo: chỉ gửi được tin tư vấn cho người đã nhắn OA trong khung thời gian gần đây.
 */
class ZaloOaClient
{
    public const ACCESS_TOKEN = 'zalo_oa.access_token';
    public const REFRESH_TOKEN = 'zalo_oa.refresh_token';
    public const EXPIRES_AT = 'zalo_oa.access_token_expires_at';

    private const TOKEN_URL = 'https://oauth.zaloapp.com/v4/oa/access_token';
    private const MESSAGE_URL = 'https://openapi.zalo.me/v3.0/oa/message/cs';
    private const ERROR_INVALID_TOKEN = -216;

    public function isConfigured(): bool
    {
        return filled(config('services.zalo_oa.app_id'))
            && filled(config('services.zalo_oa.secret_key'))
            && filled(Setting::get(self::REFRESH_TOKEN));
    }

    public function sendText(string $userId, string $text): void
    {
        $response = $this->postMessage($userId, $text, $this->accessToken());

        if (($response['error'] ?? null) === self::ERROR_INVALID_TOKEN) {
            $response = $this->postMessage($userId, $text, $this->refresh());
        }

        if (($response['error'] ?? -1) !== 0) {
            throw new RuntimeException('Zalo OA gửi tin lỗi '.($response['error'] ?? '?').': '.($response['message'] ?? 'không rõ'));
        }
    }

    /** Đổi refresh token lấy cặp token mới và lưu lại. */
    public function refresh(?string $refreshToken = null): string
    {
        $refreshToken ??= (string) Setting::get(self::REFRESH_TOKEN);

        $data = Http::asForm()
            ->withHeaders(['secret_key' => (string) config('services.zalo_oa.secret_key')])
            ->timeout(10)
            ->post(self::TOKEN_URL, [
                'app_id' => config('services.zalo_oa.app_id'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ])
            ->json() ?? [];

        if (empty($data['access_token']) || empty($data['refresh_token'])) {
            Log::warning('Zalo OA refresh token thất bại', ['error' => $data['error'] ?? null, 'description' => $data['error_description'] ?? null]);

            throw new RuntimeException('Không làm mới được token Zalo OA: '.($data['error_description'] ?? $data['error_name'] ?? 'không rõ'));
        }

        Setting::set(self::ACCESS_TOKEN, $data['access_token']);
        Setting::set(self::REFRESH_TOKEN, $data['refresh_token']);
        // Trừ hao 10 phút để không dùng token sát giờ hết hạn.
        Setting::set(self::EXPIRES_AT, (string) (time() + max(0, (int) ($data['expires_in'] ?? 0) - 600)));

        return $data['access_token'];
    }

    private function accessToken(): string
    {
        $token = (string) Setting::get(self::ACCESS_TOKEN, '');

        if ($token === '' || (int) Setting::get(self::EXPIRES_AT, 0) <= time()) {
            return $this->refresh();
        }

        return $token;
    }

    private function postMessage(string $userId, string $text, string $accessToken): array
    {
        return Http::withHeaders(['access_token' => $accessToken])
            ->timeout(10)
            ->post(self::MESSAGE_URL, [
                'recipient' => ['user_id' => $userId],
                'message' => ['text' => mb_substr($text, 0, 2000)],
            ])
            ->json() ?? [];
    }
}
