<?php

namespace App\Services\AddLiveTag;

use App\Services\WalletService;
use Illuminate\Support\Carbon;

/**
 * Shared Shopee/AddLiveTag conversions sync runner — SINGLE source of truth
 * for step 5 of `affiliate:sync-all` (Windows Task Scheduler) AND the admin
 * order-sync screen's "Đồng bộ ngay" button. Both entry points execute the
 * identical pipeline:
 *
 *   fetch (pagination MUST reach data==[]) → normalize → plan → apply rows
 *   → credit wallet (ConversionsImporter::credit → WalletService::creditCashback)
 *
 * Hard rules (never reimplemented at call sites):
 *   - default window = last 14 days; explicit $from/$to override it
 *   - truncated pagination = FAIL before any write (no partial dataset)
 *   - idempotent: repeat runs never duplicate rows / credits
 *   - $dryRun = true keeps the whole run read-only (no row writes, no wallet)
 *   - credit errors are reported in `credit['errors']`, never silently ignored
 *   - the AddLiveTag API key never reaches console / logs / flash messages
 */
class ShopeeApiSyncService
{
    public function __construct(
        private readonly ConversionsClient $client,
        private readonly ConversionsNormalizer $normalizer,
        private readonly ConversionsImporter $importer,
        private readonly WalletService $wallet,
    ) {}

    /** Default lookback: 14 days (kept for display + run defaults). */
    public static function defaultFrom(): string
    {
        return Carbon::now()->subDays(14)->format('Y-m-d');
    }

    /**
     * Run the Shopee/AddLiveTag sync.
     *
     * @param  string|null  $from  YYYY-MM-DD (default: 14 days ago)
     * @param  string|null  $to  YYYY-MM-DD (default: today, API side)
     * @param  bool  $dryRun  read-only: no row writes, no wallet credit
     * @return array{
     *     success: bool,
     *     error: ?string,
     *     dry_run: bool,
     *     from: string,
     *     to: ?string,
     *     raw_items: int,
     *     normalized: int,
     *     orders: int,
     *     plan_create: int,
     *     plan_update: int,
     *     plan_protected: int,
     *     user_conflict: int,
     *     sub_id_mismatch: int,
     *     overlap_groups: int,
     *     applied_created: int,
     *     applied_updated: int,
     *     credit: array<string, int>,
     *     duration: float
     * }
     */
    public function run(?string $from = null, ?string $to = null, bool $dryRun = false): array
    {
        $startedAt = microtime(true);

        $from = ($from !== null && $from !== '') ? $from : self::defaultFrom();
        $to = ($to !== null && $to !== '') ? $to : null;

        $result = [
            'success' => false,
            'error' => null,
            'dry_run' => $dryRun,
            'from' => $from,
            'to' => $to,
            'raw_items' => 0,
            'normalized' => 0,
            'orders' => 0,
            'plan_create' => 0,
            'plan_update' => 0,
            'plan_protected' => 0,
            'user_conflict' => 0,
            'sub_id_mismatch' => 0,
            'overlap_groups' => 0,
            'applied_created' => 0,
            'applied_updated' => 0,
            'credit' => $this->emptyCredit(),
            'duration' => 0.0,
        ];

        try {
            $fetched = $this->client->fetch(from: $from, to: $to, orderId: null);
        } catch (\Throwable $e) {
            $result['error'] = 'Shopee AddLiveTag fetch failed: '.$this->maskSecrets($e->getMessage());
            $result['duration'] = round(microtime(true) - $startedAt, 2);

            return $result;
        }

        if (! empty($fetched['truncated'])) {
            $result['error'] = '[FAIL] Pagination truncated before data==[] — incomplete dataset, aborting.';
            $result['duration'] = round(microtime(true) - $startedAt, 2);

            return $result;
        }

        $result['raw_items'] = count($fetched['items']);

        $normalized = $this->normalizer->normalize($fetched['items']);
        $rows = $normalized['all'];
        $result['normalized'] = count($rows);
        $result['orders'] = count(array_unique(array_map(static fn (array $r): string => (string) $r['order_id'], $rows)));

        $plan = $this->importer->plan($rows, now()->format('Ymd_His'));
        $result['plan_create'] = (int) ($plan['counts'][ConversionsImporter::ACTION_CREATE] ?? 0);
        $result['plan_update'] = (int) ($plan['counts'][ConversionsImporter::ACTION_UPDATE] ?? 0);
        $result['plan_protected'] = (int) ($plan['counts'][ConversionsImporter::ACTION_PROTECTED] ?? 0);
        $result['user_conflict'] = count($plan['user_conflicts']);
        $result['sub_id_mismatch'] = count($plan['sub_id_mismatches']);
        $result['overlap_groups'] = (int) $plan['overlap_groups'];

        if ($dryRun) {
            $result['success'] = true;
            $result['duration'] = round(microtime(true) - $startedAt, 2);

            return $result;
        }

        $applied = $this->importer->apply($plan);
        $result['applied_created'] = (int) $applied['created'];
        $result['applied_updated'] = (int) $applied['updated'];

        $result['credit'] = $this->importer->credit($plan, $this->wallet);
        $result['success'] = true;
        $result['duration'] = round(microtime(true) - $startedAt, 2);

        return $result;
    }

    /** Never let the AddLiveTag API key reach console / logs / flash. */
    public function maskSecrets(string $message): string
    {
        $key = trim((string) config('services.addlivetag.api_key', ''));

        if ($key !== '' && str_contains($message, $key)) {
            $message = str_replace($key, '****', $message);
        }

        return preg_replace('/([?&]key=)[^&\s]+/i', '$1****', $message) ?? $message;
    }

    /**
     * Zeroed counters mirroring ConversionsImporter::credit() so callers can
     * always read credit['errors'] / credit['credited'] without null checks.
     *
     * @return array<string, int>
     */
    private function emptyCredit(): array
    {
        return [
            'checked' => 0,
            'credited' => 0,
            'already_credited' => 0,
            'protected' => 0,
            'user_conflict' => 0,
            'not_completed' => 0,
            'zero_cashback' => 0,
            'no_user' => 0,
            'missing' => 0,
            'errors' => 0,
        ];
    }
}
