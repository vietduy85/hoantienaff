<?php

namespace App\Console\Commands;

use App\Services\ProductDataService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillShopeeOrderCommissionRates extends Command
{
    protected $signature = 'affiliate:backfill-shopee-order-rates
                            {--from=2026-10-25 00:00:00 : ordered_at lower bound}
                            {--to= : ordered_at upper bound}
                            {--item-id=* : Limit to item_id}
                            {--order-id=* : Limit to order_id}
                            {--limit=0 : Max rows}
                            {--apply : Write changes; default is dry-run}
                            {--backup= : Backup JSON path used with --apply}
                            {--json : Emit machine-readable JSON only}';

    protected $description = 'Backfill missing Shopee order-item commission rates with historical-total corroboration';

    private const TOTAL_TOLERANCE_PP = 0.01;

    private const PERCENT_FACTOR = 100.0;

    public function __construct(private readonly ProductDataService $productData)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $timezone = (string) config('app.timezone', 'Asia/Ho_Chi_Minh');

        try {
            $from = CarbonImmutable::parse((string) $this->option('from'), $timezone);
            $to = $this->option('to')
                ? CarbonImmutable::parse((string) $this->option('to'), $timezone)->endOfSecond()
                : CarbonImmutable::now($timezone);
        } catch (\Throwable) {
            $this->error('Invalid --from/--to datetime.');

            return Command::FAILURE;
        }

        if ($from->greaterThan($to)) {
            $this->error('--from must be before or equal to --to.');

            return Command::FAILURE;
        }

        $query = DB::table('affiliate_order_items')
            ->select([
                'id', 'order_id', 'item_id', 'user_id', 'platform', 'ordered_at',
                'affiliate_status', 'source_file', 'import_batch',
                'order_amount', 'total_order_commission',
                'shopee_commission_rate', 'seller_commission_rate',
            ])
            ->where('platform', 'Shopee')
            ->where('ordered_at', '>=', $from->format('Y-m-d H:i:s'))
            ->where('ordered_at', '<=', $to->format('Y-m-d H:i:s'));

        $itemIds = $this->numericFilter($this->option('item-id'));
        if ($itemIds !== []) {
            $query->whereIn('item_id', $itemIds);
        }

        $orderIds = $this->stringFilter($this->option('order-id'));
        if ($orderIds !== []) {
            $query->whereIn('order_id', $orderIds);
        }

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $query->limit($limit);
        }

        $rows = $query->orderBy('id')->get();
        $apiCache = [];
        $decisions = [];

        foreach ($rows as $row) {
            $decisions[] = $this->decide($row, $apiCache);
        }

        $apply = (bool) $this->option('apply');
        $backupPath = null;

        if ($apply) {
            $backupPath = $this->writeBackup($decisions);

            $proposals = array_values(array_filter(
                $decisions,
                static fn (array $d): bool => ($d['action'] ?? '') === 'propose'
            ));

            DB::transaction(function () use ($proposals, &$decisions): void {
                foreach ($proposals as $proposal) {
                    $affected = DB::table('affiliate_order_items')
                        ->where('id', $proposal['id'])
                        ->where('shopee_commission_rate', $proposal['old_shopee_rate'])
                        ->where('seller_commission_rate', $proposal['old_seller_rate'])
                        ->update([
                            'shopee_commission_rate' => $proposal['new_shopee_rate'],
                            'seller_commission_rate' => $proposal['new_seller_rate'],
                        ]);

                    foreach ($decisions as $index => $decision) {
                        if ($decision['id'] !== $proposal['id']) {
                            continue;
                        }

                        if ($affected === 1) {
                            $decisions[$index]['action'] = 'applied';
                            $decisions[$index]['reason'] = 'applied';
                        } else {
                            $decisions[$index]['action'] = 'skipped';
                            $decisions[$index]['reason'] = 'concurrent_change';
                        }

                        break;
                    }
                }
            });
        }

        $summary = $this->summarize($decisions);
        $payload = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'scope_rows' => $rows->count(),
            'unique_items' => $rows->pluck('item_id')->unique()->count(),
            'api_calls' => count($apiCache),
            'summary' => $summary,
            'backup' => $backupPath,
            'rows' => $decisions,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $this->info('Shopee order-item commission-rate backfill');
        $this->line('  mode:        '.$payload['mode']);
        $this->line('  from:        '.$payload['from']);
        $this->line('  to:          '.$payload['to']);
        $this->line('  scope rows:  '.$payload['scope_rows']);
        $this->line('  unique item: '.$payload['unique_items']);
        $this->line('  API calls:   '.$payload['api_calls']);
        if ($backupPath !== null) {
            $this->line('  backup:      '.$backupPath);
        }
        $this->line('');

        $table = [];
        foreach ($decisions as $d) {
            $table[] = [
                $d['id'],
                $d['order_id'],
                $d['item_id'],
                $d['affiliate_status'],
                $d['implied_total_pct'] === null ? '-' : number_format($d['implied_total_pct'], 2),
                $d['api_shopee_pct'] === null ? '-' : number_format($d['api_shopee_pct'], 2),
                $d['api_seller_pct'] === null ? '-' : number_format($d['api_seller_pct'], 2),
                $d['new_shopee_rate'] === null ? '-' : number_format($d['new_shopee_rate'], 2),
                $d['new_seller_rate'] === null ? '-' : number_format($d['new_seller_rate'], 2),
                $d['action'],
                $d['reason'],
            ];
        }

        $this->table(
            ['id', 'order_id', 'item_id', 'status', 'historical_total_%', 'api_shopee_%', 'api_seller_%', 'new_shopee_%', 'new_seller_%', 'action', 'reason'],
            $table
        );

        $this->line('');
        $this->line('Summary: '.json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (! $apply) {
            $this->warn('Dry-run only. Re-run with --apply after reviewing the table.');
        }

        return Command::SUCCESS;
    }

    private function decide(object $row, array &$apiCache): array
    {
        $base = [
            'id' => (int) $row->id,
            'order_id' => (string) $row->order_id,
            'item_id' => (int) $row->item_id,
            'affiliate_status' => (string) $row->affiliate_status,
            'ordered_at' => (string) $row->ordered_at,
            'source_file' => $row->source_file,
            'order_amount' => (float) $row->order_amount,
            'total_order_commission' => (float) $row->total_order_commission,
            'old_shopee_rate' => (string) $row->shopee_commission_rate,
            'old_seller_rate' => (string) $row->seller_commission_rate,
            'implied_total_pct' => null,
            'api_shopee_pct' => null,
            'api_seller_pct' => null,
            'api_total_pct' => null,
            'total_diff_pp' => null,
            'new_shopee_rate' => null,
            'new_seller_rate' => null,
            'action' => 'skipped',
            'reason' => 'unknown',
        ];

        if ((float) $row->shopee_commission_rate > 0 || (float) $row->seller_commission_rate > 0) {
            return array_merge($base, ['reason' => 'already_has_rate']);
        }

        if ((float) $row->order_amount <= 0) {
            return array_merge($base, ['reason' => 'no_order_amount']);
        }

        if ((float) $row->total_order_commission <= 0) {
            return array_merge($base, ['reason' => 'no_commission']);
        }

        $implied = ((float) $row->total_order_commission / (float) $row->order_amount) * self::PERCENT_FACTOR;
        $base['implied_total_pct'] = $implied;

        $itemId = (int) $row->item_id;
        if (! array_key_exists($itemId, $apiCache)) {
            $apiCache[$itemId] = $this->fetchApi($itemId);
        }

        $api = $apiCache[$itemId];
        if (! ($api['success'] ?? false)) {
            return array_merge($base, ['reason' => 'api_unavailable']);
        }

        if ($api['shopee_rate'] === null && $api['seller_rate'] === null) {
            return array_merge($base, ['reason' => 'api_rate_missing']);
        }

        $shopeePct = round(((float) ($api['shopee_rate'] ?? 0)) * self::PERCENT_FACTOR, 2);
        $sellerPct = round(((float) ($api['seller_rate'] ?? 0)) * self::PERCENT_FACTOR, 2);
        $apiTotal = $shopeePct + $sellerPct;

        $base['api_shopee_pct'] = $shopeePct;
        $base['api_seller_pct'] = $sellerPct;
        $base['api_total_pct'] = $apiTotal;
        $base['total_diff_pp'] = abs($apiTotal - $implied);

        if ($shopeePct < 0 || $shopeePct > 100 || $sellerPct < 0 || $sellerPct > 100) {
            return array_merge($base, ['reason' => 'invalid_api_rate']);
        }

        if ($base['total_diff_pp'] > self::TOTAL_TOLERANCE_PP) {
            return array_merge($base, ['reason' => 'historical_total_mismatch']);
        }

        if ($shopeePct <= 0 && $sellerPct <= 0) {
            return array_merge($base, ['reason' => 'api_rate_missing']);
        }

        $base['new_shopee_rate'] = $shopeePct;
        $base['new_seller_rate'] = $sellerPct;
        $base['action'] = 'propose';
        $base['reason'] = 'historical_total_matches_api';

        return $base;
    }

    private function fetchApi(int $itemId): array
    {
        try {
            $result = $this->productData->getByUrl('https://shopee.vn/product/0/'.$itemId);
        } catch (\Throwable) {
            return ['success' => false];
        }

        if (! ($result['success'] ?? false)) {
            return ['success' => false];
        }

        return [
            'success' => true,
            'shopee_rate' => $result['shopee_commission_rate'] ?? null,
            'seller_rate' => $result['seller_commission_rate'] ?? null,
        ];
    }

    private function summarize(array $decisions): array
    {
        $byReason = [];
        $applied = 0;

        foreach ($decisions as $decision) {
            $reason = (string) $decision['reason'];
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
            if ($decision['action'] === 'applied') {
                $applied++;
            }
        }

        return [
            'total' => count($decisions),
            'applied' => $applied,
            'proposed' => $byReason['historical_total_matches_api'] ?? 0,
            'by_reason' => $byReason,
        ];
    }

    private function writeBackup(array $decisions): string
    {
        $path = $this->option('backup');
        if (! is_string($path) || trim($path) === '') {
            $path = storage_path('app/audit/backfill_shopee_order_rates_backup_'.now()->format('Ymd_His').'.json');
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, (string) json_encode([
            'created_at' => now()->format('Y-m-d H:i:s'),
            'table' => 'affiliate_order_items',
            'rows' => $decisions,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function numericFilter(mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];

        return array_values(array_filter(array_map(
            static fn ($value): int => (int) $value,
            array_filter($values, static fn ($value): bool => ctype_digit((string) $value))
        ), static fn (int $value): bool => $value > 0));
    }

    private function stringFilter(mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];

        return array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $values
        ), static fn (string $value): bool => $value !== ''));
    }
}
