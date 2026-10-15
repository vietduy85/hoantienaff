<?php

namespace App\Services\AccessTrade;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * AccessTrade API configuration (Phase C).
 *
 * The access token is stored ENCRYPTED at rest in the `settings` table and is
 * never returned to the frontend, never logged and never placed in a URL or an
 * error message. {@see mask()} only ever exposes the last 4 characters.
 *
 * A token being present does NOT mean the connection works — only a real
 * response from the API does (see {@see AccessTradeClient::ping()}).
 */
class AccessTradeConfig
{
    public const KEY_ENABLED = 'accesstrade.enabled';

    public const KEY_TOKEN = 'accesstrade.token';

    public const KEY_BASE_URL = 'accesstrade.base_url';

    public const KEY_LAST_CHECK_AT = 'accesstrade.last_check_at';

    public const KEY_LAST_CHECK_OK = 'accesstrade.last_check_ok';

    public const KEY_LAST_CHECK_MESSAGE = 'accesstrade.last_check_message';

    public function enabled(): bool
    {
        return Setting::get(self::KEY_ENABLED, 'false') === 'true';
    }

    public function setEnabled(bool $enabled): void
    {
        Setting::set(self::KEY_ENABLED, $enabled ? 'true' : 'false');
    }

    public function baseUrl(): string
    {
        $configured = trim((string) Setting::get(self::KEY_BASE_URL, ''));

        return $configured !== ''
            ? $configured
            : (string) config('services.accesstrade.base_url', 'https://api.accesstrade.vn');
    }

    public function setBaseUrl(?string $url): void
    {
        Setting::set(self::KEY_BASE_URL, trim((string) $url));
    }

    /**
     * Raw token (decrypted). Falls back to `services.accesstrade.token` (env)
     * when no token has been saved through the UI. Never expose the return
     * value to the frontend or logs.
     */
    public function token(): string
    {
        $stored = (string) Setting::get(self::KEY_TOKEN, '');

        if ($stored !== '') {
            try {
                return (string) Crypt::decryptString($stored);
            } catch (Throwable) {
                // Value not encrypted (legacy/plain) — return as-is but do not
                // break. It will be re-encrypted on the next save.
                return $stored;
            }
        }

        return (string) config('services.accesstrade.token', '');
    }

    public function hasToken(): bool
    {
        return trim($this->token()) !== '';
    }

    /**
     * Store the token encrypted at rest. An empty string clears it.
     */
    public function setToken(?string $token): void
    {
        $token = trim((string) $token);

        if ($token === '') {
            Setting::set(self::KEY_TOKEN, '');

            return;
        }

        Setting::set(self::KEY_TOKEN, Crypt::encryptString($token));
    }

    /**
     * Masked token safe for display, e.g. `••••••••1234`, or null when unset.
     */
    public function maskedToken(): ?string
    {
        $token = trim($this->token());

        if ($token === '') {
            return null;
        }

        $tail = mb_substr($token, -4);

        return '••••••••'.$tail;
    }

    public function lastCheckAt(): ?string
    {
        $value = Setting::get(self::KEY_LAST_CHECK_AT, '');

        return $value !== '' ? (string) $value : null;
    }

    public function lastCheckOk(): ?bool
    {
        $value = Setting::get(self::KEY_LAST_CHECK_OK, '');

        return $value === '' ? null : $value === 'true';
    }

    public function lastCheckMessage(): ?string
    {
        $value = Setting::get(self::KEY_LAST_CHECK_MESSAGE, '');

        return $value !== '' ? (string) $value : null;
    }

    /**
     * Record the outcome of a connection check. The message must already be
     * safe (no token/credential).
     */
    public function recordCheck(bool $ok, string $message): void
    {
        Setting::set(self::KEY_LAST_CHECK_AT, now()->format('Y-m-d H:i:s'));
        Setting::set(self::KEY_LAST_CHECK_OK, $ok ? 'true' : 'false');
        Setting::set(self::KEY_LAST_CHECK_MESSAGE, $message);
    }

    /**
     * Redact anything that looks like the configured token from a message.
     */
    public function scrub(string $message): string
    {
        $token = trim($this->token());

        if ($token !== '' && str_contains($message, $token)) {
            $message = str_replace($token, '****', $message);
        }

        return $message;
    }
}
