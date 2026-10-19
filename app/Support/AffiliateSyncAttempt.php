<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Per-platform "last ATTEMPT" markers (state + timestamp + short reason).
 *
 * Complements App\Support\AffiliateSyncStatus, which only remembers the last
 * SUCCESS (by design — it is a "lần đồng bộ gần nhất" label, not a monitor).
 * The 24h Shopee/LiveTag warning needs to distinguish:
 *
 *   success — sync finished and applied (incl. 0 valid records)
 *   failed  — fetch / business status / apply / credit error
 *   skipped — did not run at all (sync lock held by another session)
 *
 * Stored in the existing `settings` table (no migration) as one JSON blob per
 * platform under `affiliate.last_attempt.<platform>`.
 *
 * Reasons are SHORT SAFE CODES only (e.g. `lock_timeout`, `credit_errors`,
 * `business_status`) — never the raw exception message, never order/user
 * payloads, never the API key, so nothing sensitive can leak via settings.
 */
final class AffiliateSyncAttempt
{
    public const TIKTOK = 'tiktok';

    public const LAZADA = 'lazada';

    public const SHOPEE = 'shopee';

    public const KEY_PREFIX = 'affiliate.last_attempt.';

    public const STATE_SUCCESS = 'success';

    public const STATE_FAILED = 'failed';

    public const STATE_SKIPPED = 'skipped';

    /** Settings key backing the given platform's last-attempt marker. */
    public static function key(string $platform): string
    {
        return self::KEY_PREFIX.$platform;
    }

    /**
     * @param  string  $state  one of the STATE_* constants
     * @param  string|null  $reason  short safe code, no PII / no secrets
     */
    public static function record(string $platform, string $state, ?string $reason = null, ?Carbon $at = null): void
    {
        $payload = json_encode([
            'state' => $state,
            'reason' => $reason,
            'at' => ($at ?? now())->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);

        Setting::set(self::key($platform), $payload ?: null);
    }

    /**
     * The last attempt, or null when nothing has ever been recorded.
     *
     * @return array{state: string, reason: ?string, at: ?Carbon}|null
     */
    public static function last(string $platform): ?array
    {
        $value = Setting::get(self::key($platform));

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded) || ! isset($decoded['state'])) {
            return null;
        }

        $at = null;

        if (isset($decoded['at']) && is_string($decoded['at'])) {
            try {
                $at = Carbon::parse($decoded['at']);
            } catch (\Throwable) {
                $at = null;
            }
        }

        return [
            'state' => (string) $decoded['state'],
            'reason' => isset($decoded['reason']) && is_string($decoded['reason']) ? $decoded['reason'] : null,
            'at' => $at,
        ];
    }
}
