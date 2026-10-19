<?php

namespace App\Support;

use App\Models\AffiliateOrderItem;
use App\Services\AddLiveTag\ConversionsImporter;
use Illuminate\Support\Carbon;

/**
 * Per-platform freshness monitor for the admin order-sync screen:
 * "Đơn cập nhật gần nhất" + "Lần đồng bộ gần nhất" + trạng thái lần thử
 * + cảnh báo 24h cho Shopee (AddLiveTag), Lazada và TikTok.
 *
 * "Đơn cập nhật gần nhất" is ROW-WRITE based:
 *
 *   Shopee  = MAX(last_shopee_sync_at) WHERE platform='Shopee'
 *               AND source_file='addlivetag-api'  (CSV imports never mask it)
 *   Lazada  = MAX(last_lazada_sync_at) WHERE platform='Lazada'
 *   TikTok  = MAX(last_tiktok_sync_at) WHERE platform='TikTok'
 *
 * All three maxima come from ONE aggregate query. The TikTok/Lazada
 * timestamps only advance on a genuine content change (see
 * App\Support\OrderUpdateMarker), so an idempotent re-write with unchanged
 * data never fakes a fresh order feed.
 *
 * "Lần đồng bộ gần nhất" reads App\Support\AffiliateSyncStatus (last
 * SUCCESSFUL run), the attempt reads App\Support\AffiliateSyncAttempt
 * (success / failed / skipped + short safe reason).
 *
 * Warning states (same 24h rule as the original ShopeeSyncAlert):
 *   stale         — last order update older than 24h → WARN
 *   never_updated — sync activity observed but no order row ever written → WARN
 *   ok            — fresh within 24h, or nothing has ever run (no scary banner)
 */
final class AffiliateOrderUpdateStatus
{
    public const THRESHOLD_HOURS = 24;

    public const SHOPEE_MESSAGE = 'CẢNH BÁO: Đã quá 24 giờ không có đơn Shopee nào được cập nhật từ LiveTag. Hãy kiểm tra API, API key, lịch đồng bộ và dữ liệu đơn hàng.';

    public const LAZADA_MESSAGE = 'CẢNH BÁO: Đã quá 24 giờ không có đơn Lazada nào được cập nhật. Hãy kiểm tra API, lịch đồng bộ và dữ liệu đơn hàng.';

    public const TIKTOK_MESSAGE = 'CẢNH BÁO: Đã quá 24 giờ không có đơn TikTok nào được cập nhật. Hãy kiểm tra API, lịch đồng bộ và dữ liệu đơn hàng.';

    public const PLATFORMS = ['shopee', 'lazada', 'tiktok'];

    private const DEFINITIONS = [
        'shopee' => [
            'platform' => 'Shopee',
            'source_file' => ConversionsImporter::SOURCE_FILE,
            'status' => AffiliateSyncStatus::SHOPEE,
            'attempt' => AffiliateSyncAttempt::SHOPEE,
            'message' => self::SHOPEE_MESSAGE,
        ],
        'lazada' => [
            'platform' => 'Lazada',
            'source_file' => null,
            'status' => AffiliateSyncStatus::LAZADA,
            'attempt' => AffiliateSyncAttempt::LAZADA,
            'message' => self::LAZADA_MESSAGE,
        ],
        'tiktok' => [
            'platform' => 'TikTok',
            'source_file' => null,
            'status' => AffiliateSyncStatus::TIKTOK,
            'attempt' => AffiliateSyncAttempt::TIKTOK,
            'message' => self::TIKTOK_MESSAGE,
        ],
    ];

    /**
     * Freshness of all three platforms in ONE aggregate query + settings reads.
     *
     * @return array<string, array{
     *     should_warn: bool,
     *     state: 'ok'|'stale'|'never_updated',
     *     message: string,
     *     last_order_update_at: ?Carbon,
     *     last_sync_at: ?Carbon,
     *     attempt: ?array{state: string, reason: ?string, at: ?Carbon},
     *     hours_stale: ?float,
     *     threshold_hours: int
     * }>
     */
    public static function evaluateAll(?Carbon $now = null): array
    {
        $now = $now ?? now();

        $maxima = self::orderUpdateMaxima();

        $statuses = [];

        foreach (self::PLATFORMS as $slug) {
            $statuses[$slug] = self::build($slug, $maxima[$slug], $now);
        }

        return $statuses;
    }

    /**
     * Freshness of a single platform ('shopee'|'lazada'|'tiktok').
     *
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
    public static function evaluate(string $platform, ?Carbon $now = null): array
    {
        if (! isset(self::DEFINITIONS[$platform])) {
            throw new \InvalidArgumentException("Unknown order-update platform [{$platform}].");
        }

        $now = $now ?? now();

        return self::build($platform, self::orderUpdateMaxima()[$platform], $now);
    }

    /**
     * One aggregate query for the three "đơn cập nhật gần nhất" maxima.
     *
     * @return array{shopee: ?Carbon, lazada: ?Carbon, tiktok: ?Carbon}
     */
    private static function orderUpdateMaxima(): array
    {
        $row = AffiliateOrderItem::query()
            ->selectRaw(
                "MAX(CASE WHEN platform = 'Shopee' AND source_file = ? THEN last_shopee_sync_at END) AS shopee_marker",
                [ConversionsImporter::SOURCE_FILE],
            )
            ->selectRaw("MAX(CASE WHEN platform = 'Lazada' THEN last_lazada_sync_at END) AS lazada_marker")
            ->selectRaw("MAX(CASE WHEN platform = 'TikTok' THEN last_tiktok_sync_at END) AS tiktok_marker")
            ->first();

        return [
            'shopee' => self::parseMax($row?->shopee_marker ?? null),
            'lazada' => self::parseMax($row?->lazada_marker ?? null),
            'tiktok' => self::parseMax($row?->tiktok_marker ?? null),
        ];
    }

    private static function parseMax(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{
     *     platform: string,
     *     source_file: ?string,
     *     status: string,
     *     attempt: string,
     *     message: string
     * }  $definition
     */
    private static function build(string $slug, ?Carbon $lastOrderUpdateAt, Carbon $now): array
    {
        $definition = self::DEFINITIONS[$slug];

        $lastSyncAt = AffiliateSyncStatus::lastRun($definition['status']);
        $attempt = AffiliateSyncAttempt::last($definition['attempt']);
        $hasSyncEvidence = $lastSyncAt !== null || $attempt !== null;

        if ($lastOrderUpdateAt === null) {
            // Never wrote an order row. Warn ONLY when sync activity has been
            // observed, so a brand-new install shows no scary banner.
            $shouldWarn = $hasSyncEvidence;

            return [
                'should_warn' => $shouldWarn,
                'state' => $shouldWarn ? 'never_updated' : 'ok',
                'message' => $definition['message'],
                'last_order_update_at' => null,
                'last_sync_at' => $lastSyncAt,
                'attempt' => $attempt,
                'hours_stale' => null,
                'threshold_hours' => self::THRESHOLD_HOURS,
            ];
        }

        $hoursStale = $lastOrderUpdateAt->diffInHours($now);
        $shouldWarn = $hoursStale >= self::THRESHOLD_HOURS;

        return [
            'should_warn' => $shouldWarn,
            'state' => $shouldWarn ? 'stale' : 'ok',
            'message' => $definition['message'],
            'last_order_update_at' => $lastOrderUpdateAt,
            'last_sync_at' => $lastSyncAt,
            'attempt' => $attempt,
            'hours_stale' => round($hoursStale, 1),
            'threshold_hours' => self::THRESHOLD_HOURS,
        ];
    }
}
