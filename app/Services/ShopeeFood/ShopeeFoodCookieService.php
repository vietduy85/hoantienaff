<?php

namespace App\Services\ShopeeFood;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ShopeeFoodCookieService
{
    private const COOKIE_FILE = 'shopeefood/cookie.json';
    private const META_FILE = 'shopeefood/meta.json';

    public function hasCookie(): bool
    {
        return ! empty($this->getCookie());
    }

    public function getCookie(): ?string
    {
        $path = storage_path('app/' . self::COOKIE_FILE);
        if (File::exists($path)) {
            try {
                $raw = File::get($path);
                $data = json_decode($raw, true);
                if (is_array($data) && isset($data['cookie'])) {
                    $enc = $data['cookie'];
                    if (is_string($enc) && $enc !== '') {
                        try {
                            $dec = Crypt::decryptString($enc);
                            if (is_string($dec) && $dec !== '') {
                                return $dec;
                            }
                        } catch (\Throwable $e) {
                        }
                        return $enc;
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        $env = (string) (config('services.shopeefood.cookie') ?? '');
        if ($env !== '') {
            return $env;
        }
        return null;
    }

    public function getMeta(): array
    {
        $path = storage_path('app/' . self::META_FILE);
        if (! File::exists($path)) {
            return ['configured' => false, 'imported_at' => null];
        }
        try {
            $raw = File::get($path);
            $data = json_decode($raw, true);
            return is_array($data) ? $data : ['configured' => false, 'imported_at' => null];
        } catch (\Throwable $e) {
            return ['configured' => false, 'imported_at' => null];
        }
    }

    public function importFromArray(array $cookies): array
    {
        $cookieStr = $this->normalizeCookies($cookies);
        if ($cookieStr === '') {
            return ['ok' => false, 'message' => 'Không thể trích xuất cookie hợp lệ từ file JSON.'];
        }

        $cookiePath = storage_path('app/' . self::COOKIE_FILE);
        $metaPath = storage_path('app/' . self::META_FILE);

        try {
            $enc = Crypt::encryptString($cookieStr);
            $payload = json_encode(['cookie' => $enc], JSON_UNESCAPED_SLASHES);
            File::put($cookiePath, $payload);
            $meta = ['configured' => true, 'imported_at' => Carbon::now()->toIso8601String()];
            File::put($metaPath, json_encode($meta, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            Log::error('[ShopeeFoodCookie] import failed', ['err' => get_class($e)]);
            return ['ok' => false, 'message' => 'Không thể lưu cookie.'];
        }

        return ['ok' => true, 'message' => 'Đã import cookie ShopeeFood thành công.'];
    }

    private function normalizeCookies(array $input): string
    {
        if (array_key_exists(0, $input) && is_array($input[0])) {
            $parts = [];
            foreach ($input as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $name = $item['name'] ?? $item['key'] ?? null;
                $value = $item['value'] ?? null;
                if (is_string($name) && $name !== '' && (is_string($value) || is_numeric($value))) {
                    $parts[] = $name . '=' . $value;
                }
            }
            if (! empty($parts)) {
                return implode('; ', $parts);
            }
        }

        $parts = [];
        foreach ($input as $k => $v) {
            if (is_string($k) && $k !== '' && (is_string($v) || is_numeric($v)) && ! is_array($v)) {
                $kl = strtolower($k);
                if (in_array($kl, ['cookie', 'cookies', 'header'], true)) {
                    continue;
                }
                $parts[] = $k . '=' . $v;
            }
        }
        if (! empty($parts)) {
            return implode('; ', $parts);
        }

        if (isset($input['cookie']) && is_string($input['cookie']) && $input['cookie'] !== '') {
            return trim($input['cookie']);
        }
        if (isset($input['cookies']) && is_string($input['cookies']) && $input['cookies'] !== '') {
            return trim($input['cookies']);
        }

        return '';
    }

    public function testCookie(): array
    {
        $cookie = $this->getCookie();
        if (! $cookie) {
            return ['ok' => false, 'message' => 'Chưa cấu hình cookie ShopeeFood.'];
        }
        try {
            $client = app(ShopeeFoodClient::class);
            $client->setCookie($cookie);
            $client->getOrders(null, null, 1, 5);
            return ['ok' => true, 'message' => 'Cookie hợp lệ. Đã kết nối thành công tới API ShopeeFood.'];
        } catch (ShopeeFoodException $e) {
            $kind = $e->getKind();
            if ($kind === 'expired_session' || $kind === 'http_auth') {
                return ['ok' => false, 'message' => 'Cookie không hợp lệ hoặc đã hết hạn.'];
            }
            if ($kind === 'config_missing') {
                return ['ok' => false, 'message' => 'Chưa cấu hình cookie.'];
            }
            return ['ok' => false, 'message' => 'Kiểm tra cookie thất bại.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Lỗi kết nối khi kiểm tra cookie.'];
        }
    }
}
