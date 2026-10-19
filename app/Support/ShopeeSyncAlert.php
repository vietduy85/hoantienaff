<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * 24-hour "no Shopee order updated from LiveTag" warning for the admin
 * order-sync screen.
 *
 * Thin delegate kept for backward compatibility: the evaluation logic (and
 * the equivalent Lazada/TikTok monitors) now live in
 * App\Support\AffiliateOrderUpdateStatus, which evaluates all three platforms
 * in one aggregate query.
 *
 * The trigger stays ROW-WRITE based (not run-marker based):
 *
 *   MAX(last_shopee_sync_at)
 *     WHERE platform = 'Shopee'
 *       AND source_file = 'addlivetag-api'   ← LiveTag API only
 *
 * A sync that fetches 0 records, fails, or is skipped by the lock moves NO
 * order row — so the warning fires exactly when data really stopped flowing.
 *
 * `source_file = 'addlivetag-api'` EXCLUDES CSV imports: those also set
 * `last_shopee_sync_at` (AffiliateImportShopee) but must never mask a broken
 * API feed. `platform='Shopee'` excludes the other three platforms.
 *
 * States:
 *   stale        — last LiveTag order update is older than 24h → WARN
 *   never_updated— sync has run before but never wrote a LiveTag order → WARN
 *   ok           — fresh data within 24h (or nothing has ever run → no warn)
 */
final class ShopeeSyncAlert
{
    public const THRESHOLD_HOURS = AffiliateOrderUpdateStatus::THRESHOLD_HOURS;

    public const MESSAGE = AffiliateOrderUpdateStatus::SHOPEE_MESSAGE;

    /**
     * @return array{
     *     should_warn: bool,
     *     state: 'ok'|'stale'|'never_updated',
     *     message: string,
     *     last_order_update_at: ?Carbon,
     *     last_sync_at: ?Carbon,
     *     attempt: ?array{state: string, reason: ?string, at: ?Carbon},
     *     hours_stale: ?float,
     *     threshold_hours: int
     * }
     */
    public static function evaluate(?Carbon $now = null): array
    {
        return AffiliateOrderUpdateStatus::evaluate('shopee', $now);
    }
}
