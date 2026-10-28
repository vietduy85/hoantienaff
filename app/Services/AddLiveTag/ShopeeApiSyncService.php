<?php

namespace App\Services\AddLiveTag;

use App\Services\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

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
 *   - HTTP 200 + business error payload = FAILURE (ConversionsClient throws
 *     before plan/apply) — never counted as a valid empty page
 *   - every run logs ONE meta line (`[AddLiveTag] shopee sync result`):
 *     sync_state / http_status / business_status / pages / records / counters,
 *     no API key, no headers, no order payload, no user data
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
     *     duration: float,
     *     api_http_status: ?int,
     *     api_business_status: ?string,
     *     api_pages_fetched: int,
     *     api_records: int,
     *     api_duration_ms: int,
     *     api_endpoint: ?string,
     *     orders_written: int,
     *     sync_state: 'failed'|'success_zero'|'success_with_credit_errors'|'success',
     *     notice: ?string
     * }
     */
    public function run(?string $from = null, ?string $to = null, bool $dryRun = false): array
    {
        $startedAt = microtime(true);

        $from = ($from !== null && $from !== '') ? $from : self::defaultFrom();
        $to = ($to !== null && $to !== '') ? $to : Carbon::now(config('app.timezone'))->format('Y-m-d');

        if ($to < $from) {
            $result = [
                'success' => false,
                'error' => 'Invalid date range: to ('.$to.') < from ('.$from.').',
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
                'api_http_status' => null,
                'api_business_status' => null,
                'api_pages_fetched' => 0,
                'api_records' => 0,
                'api_duration_ms' => 0,
                'api_endpoint' => null,
                'orders_written' => 0,
                'sync_state' => 'failed',
                'notice' => null,
            ];
            $result['duration'] = round(microtime(true) - $startedAt, 2);
            $this->logResult($result);

            return $result;
        }

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
            'api_http_status' => null,
            'api_business_status' => null,
            'api_pages_fetched' => 0,
            'api_records' => 0,
            'api_duration_ms' => 0,
            'api_endpoint' => null,
            'orders_written' => 0,
            'sync_state' => 'failed',
            'notice' => null,
        ];

        try {
            $fetched = $this->client->fetch(from: $from, to: $to, orderId: null);
        } catch (\Throwable $e) {
            $result['error'] = 'Shopee AddLiveTag fetch failed: '.$this->maskSecrets($e->getMessage());
            $result['duration'] = round(microtime(true) - $startedAt, 2);
            $this->logResult($result);

            return $result;
        }

        $result = $this->applyApiMeta($result, $fetched);

        if (! empty($fetched['truncated'])) {
            $result['error'] = '[FAIL] Pagination truncated before data==[] — incomplete dataset, aborting.';
            $result['duration'] = round(microtime(true) - $startedAt, 2);
            $this->logResult($result);

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
            $result['sync_state'] = $result['raw_items'] > 0 ? 'success' : 'success_zero';
            $result['notice'] = $result['raw_items'] > 0
                ? 'Chạy thử read-only: KHÔNG ghi đơn, KHÔNG cộng cashback.'
                : 'API trả về 0 bản ghi trong khoảng thời gian này (chạy thử read-only).';
            $result['duration'] = round(microtime(true) - $startedAt, 2);
            $this->logResult($result);

            return $result;
        }

        $applied = $this->importer->apply($plan);
        $result['applied_created'] = (int) $applied['created'];
        $result['applied_updated'] = (int) $applied['updated'];
        $result['orders_written'] = $result['applied_created'] + $result['applied_updated'];

        $result['credit'] = $this->importer->credit($plan, $this->wallet);
        $result['success'] = true;
        $result['duration'] = round(microtime(true) - $startedAt, 2);

        if ($result['credit']['errors'] > 0) {
            $result['sync_state'] = 'success_with_credit_errors';
            $result['notice'] = 'Ghi đơn OK nhưng credit cashback có '.$result['credit']['errors'].' lỗi — cần kiểm tra ví.';
        } elseif ($result['raw_items'] === 0) {
            $result['sync_state'] = 'success_zero';
            $result['notice'] = 'API trả về 0 bản ghi trong khoảng thời gian này.';
        } else {
            $result['sync_state'] = 'success';
            $result['notice'] = null;
        }

        $this->logResult($result);

        return $result;
    }

    /**
     * Copy the client's safe observability meta into the result array.
     * Missing keys (custom/stub clients used by tests) simply stay zero/null —
     * the pipeline must never break because a meta field is absent.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $fetched
     * @return array<string, mixed>
     */
    private function applyApiMeta(array $result, array $fetched): array
    {
        $result['api_http_status'] = isset($fetched['http_status']) ? (int) $fetched['http_status'] : null;
        $result['api_business_status'] = isset($fetched['business_status']) ? (string) $fetched['business_status'] : null;
        $result['api_pages_fetched'] = (int) ($fetched['pages_fetched'] ?? 0);
        $result['api_records'] = (int) ($fetched['records'] ?? 0);
        $result['api_duration_ms'] = (int) ($fetched['duration_ms'] ?? 0);
        $result['api_endpoint'] = isset($fetched['endpoint']) ? (string) $fetched['endpoint'] : null;

        return $result;
    }

    /**
     * ONE structured line per sync (success AND failure) so operators can see
     * HTTP status, business status, page/record counts and sync_state.
     * NEVER logs the API key, headers, order payloads or user data — only
     * endpoint + counters (the endpoint URL itself is query-less: the key is
     * sent in the X-API-Key header, never in the URL).
     *
     * @param  array<string, mixed>  $result
     */
    private function logResult(array $result): void
    {
        Log::info('[AddLiveTag] shopee sync result', [
            'sync_state' => $result['sync_state'],
            'success' => $result['success'],
            'dry_run' => $result['dry_run'],
            'http_status' => $result['api_http_status'],
            'business_status' => $result['api_business_status'],
            'pages_fetched' => $result['api_pages_fetched'],
            'records' => $result['api_records'],
            'endpoint' => $result['api_endpoint'],
            'from' => $result['from'],
            'to' => $result['to'],
            'orders' => $result['orders'],
            'orders_written' => $result['orders_written'],
            'plan_create' => $result['plan_create'],
            'plan_update' => $result['plan_update'],
            'plan_protected' => $result['plan_protected'],
            'credit_errors' => (int) ($result['credit']['errors'] ?? 0),
            'api_duration_ms' => $result['api_duration_ms'],
            'duration' => $result['duration'],
            'error' => $result['error'],
            'notice' => $result['notice'],
            'finished_at' => now()->format('Y-m-d H:i:s'),
        ]);
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
