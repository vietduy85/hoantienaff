<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Per-platform "last run" markers for the affiliate sync feeds.
 *
 * The admin order-sync page used to display "Lần đồng bộ <platform> gần nhất"
 * as `MAX(last_<platform>_sync_at)` on `affiliate_order_items`. That is a
 * ROW-WRITE marker: it only moves when a row is inserted/updated. A feed that
 * fetches data but writes no row (e.g. Lazada returning 0 records, or every
 * row being historical-protected) would therefore keep showing a stale time
 * forever, even though `affiliate:sync-all` keeps running.
 *
 * These markers record the moment a feed's sync STEP actually finished
 * successfully — independent of whether any row changed. They are stored in
 * the existing shared `settings` table (no migration needed) under
 * `affiliate.last_run.<platform>`.
 *
 * This is deliberately NOT a monitoring system: there is no failure state and
 * no history. A failed/skipped step simply leaves the last successful run
 * untouched (never a fake success).
 */
final class AffiliateSyncStatus
{
    public const TIKTOK = 'tiktok';

    public const LAZADA = 'lazada';

    public const SHOPEEFOOD = 'shopeefood';

    public const SHOPEE = 'shopee';

    public const KEY_PREFIX = 'affiliate.last_run.';

    /** Settings key backing the given platform's last-run marker. */
    public static function key(string $platform): string
    {
        return self::KEY_PREFIX.$platform;
    }

    /**
     * Record a successful run for the platform. Defaults to "now".
     * The timestamp is stored as ISO 8601 in the application timezone
     * (Asia/Ho_Chi_Minh), so the admin page shows local Vietnam time.
     */
    public static function record(string $platform, ?Carbon $at = null): void
    {
        Setting::set(self::key($platform), ($at ?? now())->toIso8601String());
    }

    /** The last successful run time, or null when it has never been recorded. */
    public static function lastRun(string $platform): ?Carbon
    {
        $value = Setting::get(self::key($platform));

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
