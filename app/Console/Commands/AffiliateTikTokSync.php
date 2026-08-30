<?php

namespace App\Console\Commands;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\RioHub\RioHubClient;
use App\Services\RioHub\RioHubException;
use App\Services\TikTok\DTOs\TikTokOrder;
use App\Services\TikTok\TikTokCashbackCalculator;
use App\Services\TikTok\TikTokOrderSyncService;
use App\Services\TikTok\TikTokServiceException;
use App\Services\TikTok\TikTokUserResolver;
use Illuminate\Console\Command;

class AffiliateTikTokSync extends Command
{
    protected $signature = 'affiliate:tiktok-sync
        {--dry-run : Chế độ chỉ đọc: gọi API + báo cáo, không ghi database}
        {--import : Phase 2.2: ghi affiliate_order_items từ API, KHÔNG credit wallet}
        {--page-size=50 : Số order mỗi trang RioHub}';

    protected $description = 'Đồng bộ đơn hàng TikTok từ RioHub (dry-run / import Phase 2.2)';

    private const MAX_PAGES = 1000;

    private const EXPECTED_CREATOR = 'hoan_tien_mua_sam';

    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('import')) {
            $this->error('[BLOCK] Không được dùng đồng thời --dry-run và --import.');
            return self::FAILURE;
        }

        if ($this->option('import')) {
            return $this->handleImport();
        }

        if (! $this->option('dry-run')) {
            $this->error('[BLOCK] Production sync chưa được phép — phải chọn chế độ rõ ràng.');
            $this->line('  • php artisan affiliate:tiktok-sync --dry-run   (chỉ đọc, không ghi DB)');
            $this->line('  • php artisan affiliate:tiktok-sync --import    (ghi affiliate_order_items, KHÔNG credit wallet)');
            return self::FAILURE;
        }

        return $this->handleDryRun();
    }

    private function handleDryRun(): int
    {
        $client   = new RioHubClient();
        $resolver = new TikTokUserResolver();
        $cashback = new TikTokCashbackCalculator();

        $this->printConfig($client);

        $before = $this->dbSnapshot();
        $this->newLine();
        $this->printSnapshot('DB SNAPSHOT — TRƯỚC (read-only)', $before);

        $fetchStart = microtime(true);
        $fetched    = $this->fetchAllOrders($client);
        $apiSeconds = round(microtime(true) - $fetchStart, 3);

        if ($fetched['error'] !== null) {
            $this->newLine();
            $this->error('--- API ERROR (DỪNG, không ghi DB) ---');
            $this->error("  Endpoint        : /partner/tiktok/affiliate/orders");
            $this->error("  HTTP status     : {$fetched['error']['http']}");
            $this->error("  RioHub message  : {$fetched['error']['riohub']}");
            $this->error("  Message         : {$fetched['error']['message']}");

            $after = $this->dbSnapshot();
            $this->newLine();
            $this->printSnapshot('DB SNAPSHOT — SAU (read-only)', $after);
            $this->printComparison($before, $after);

            return self::FAILURE;
        }

        $processStart = microtime(true);

        $rows          = [];
        $mapStats      = ['sub_id' => 0, 'sub1' => 0, 'fallback_empty' => 0, 'fallback_not_found' => 0];
        $statusCounts  = [];
        $commission    = ['match' => 0, 'different' => 0, 'no_actual' => 0];
        $parseIssues   = [];
        $cashbackTotal = 0.0;

        foreach ($fetched['orders'] as $raw) {
            try {
                $order = TikTokOrder::fromArray($raw);
            } catch (\Throwable $e) {
                $parseIssues[] = sprintf(
                    'Order (raw) %s: parse failed — %s',
                    json_encode($raw['order_id'] ?? $raw['id'] ?? '?', JSON_UNESCAPED_UNICODE),
                    $e->getMessage(),
                );
                continue;
            }

            if ($order->getOrderId() === '' || $order->getProductId() === null) {
                $parseIssues[] = sprintf(
                    'Order %s: thiếu order_id (%s) hoặc product_id (%s)',
                    $order->getOrderId() === '' ? '?' : $order->getOrderId(),
                    var_export($order->getOrderId(), true),
                    var_export($order->getProductId(), true),
                );
            }

            $resolved = $resolver->resolveWithDetail($order);
            $mapStats[$resolved['matched_by']]++;

            $cb = $cashback->calculate($order);
            $cashbackTotal += $cb['cashback_amount'];

            $est    = $order->getEstCommission();
            $actual = $order->getActualCommission();
            $diff   = $this->commissionDiff($est, $actual);
            if ($actual === null) {
                $commission['no_actual']++;
            } elseif ($diff === 'MATCH') {
                $commission['match']++;
            } else {
                $commission['different']++;
            }

            $statusKey = ($order->getStatus() ?? '?') . ' / ' . ($order->getSettlementStatus() ?? '?');
            $statusCounts[$statusKey] = ($statusCounts[$statusKey] ?? 0) + 1;

            $rows[] = [
                'Order'         => $order->getOrderId(),
                'Product'       => (string) ($order->getProductId() ?? '-'),
                'Content ID'    => $order->getContentId() === null || $order->getContentId() === '' ? '-' : $order->getContentId(),
                'Status'        => $statusKey,
                'SubID'         => $order->getSubId() ?? '-',
                'Sub1'          => $order->getSub1() ?? '-',
                'Est cm'        => $this->money($est),
                'Actual cm'     => $this->money($actual),
                'PIT'           => $order->getPit() ?? '-',
                'Diff'          => $diff,
                'Resolved User' => $resolved['username'],
                'User ID'       => (string) $resolved['user_id'],
                'Rate'          => $this->percent($cb['cashback_rate']),
                'Cashback'      => $this->money($cb['cashback_amount'], 0),
            ];
        }

        $processSeconds = round(microtime(true) - $processStart, 3);

        $after = $this->dbSnapshot();

        $this->info('--- STATUS AUDIT (theo dữ liệu thật API) ---');
        $this->table(
            ['Status / Settlement', 'Count', 'Affiliate status', 'Cashback (dry-run)', 'Wallet credit'],
            collect($statusCounts)->map(function (int $count, string $key) {
                [$num, $settle] = array_pad(explode(' / ', $key, 2), 2, '?');
                $tok    = $this->statusMeaning((int) ($num === '?' ? 0 : $num), $settle);
                $aff    = $tok['affiliate'];
                $willCb = $tok['cashback'] ? 'CÓ' : 'KHÔNG';

                return [$key, (string) $count, $aff, $willCb, 'NO (dry-run)'];
            })->sortKeys()->values()->all(),
        );

        $this->newLine();
        $this->info(sprintf('--- SAMPLE ORDERS (orders fetched: %d) ---', $fetched['orderCount']));
        $printRows   = array_slice($rows, 0, 50);
        $truncated   = count($rows) > count($printRows);
        if (count($printRows) > 0) {
            $this->table(array_keys($printRows[0]), $printRows);
        } else {
            $this->line('  (không có order nào trả về)');
        }
        if ($truncated) {
            $this->line(sprintf('  ... còn %d order nữa, chỉ in 50.', count($rows) - count($printRows)));
        }

        $this->newLine();
        $this->info('--- USER MAPPING ---');
        $mapRows = [
            ['Matched by sub_id', (string) $mapStats['sub_id']],
            ['Matched by sub1', (string) $mapStats['sub1']],
            ['Fallback — sub_id & sub1 rỗng', (string) $mapStats['fallback_empty']],
            ['Fallback — không tìm thấy user', (string) $mapStats['fallback_not_found']],
            ['Unresolved (user_id NULL)', '0'],
            ['Total', (string) $fetched['orderCount']],
        ];
        $this->table(['Tiêu chí', 'Số order'], $mapRows);

        $this->newLine();
        $this->info('--- COMMISSION (actual = NET, KHÔNG trừ 10%) ---');
        $this->table(['Tiêu chí', 'Giá trị'], [
            ['Basis', 'actual_commission (NET)'],
            ['10% tax deduction', 'NO'],
            ['actual == est (MATCH)', (string) $commission['match']],
            ['actual != est (DIFFERENT)', (string) $commission['different']],
            ['actual = null (chưa settle)', (string) $commission['no_actual']],
        ]);

        $this->newLine();
        $this->printSnapshot('DB SNAPSHOT — SAU (read-only)', $after);
        $this->printComparison($before, $after);

        $this->newLine();
        $this->info('--- PAGINATION & PERFORMANCE ---');
        $pagesElapsed = implode(', ', array_map(
            fn (int $p, float $ms) => "page {$p}: {$ms} ms",
            array_keys($fetched['pageTimes']),
            array_values($fetched['pageTimes']),
        ));
        $this->table(['Tiêu chí', 'Giá trị'], [
            ['API total (meta)', (string) ($fetched['total'] ?? 0)],
            ['Pages fetched', (string) $fetched['pages']],
            ['Orders fetched', (string) $fetched['orderCount']],
            ['API time', "{$apiSeconds} s ({$pagesElapsed})"],
            ['Processing time', "{$processSeconds} s"],
            ['Total cashback dự kiến', $this->money($cashbackTotal, 0)],
        ]);

        $this->newLine();
        if (count($parseIssues) > 0) {
            $this->warn('--- PARSE / VALIDATION ISSUES (không bỏ qua) ---');
            foreach ($parseIssues as $issue) {
                $this->line('  ! ' . $issue);
            }
            $this->newLine();
        }

        $this->info('Dry-run hoàn tất. KHÔNG ghi affiliate_order_items / wallet_transactions / users.');

        return self::SUCCESS;
    }

    private function handleImport(): int
    {
        $client = new RioHubClient();

        $this->printConfig($client);

        if (! config('services.riohub.base_url', '') || ! config('services.riohub.api_key', '')) {
            $this->error('[BLOCK] Thiếu cấu hình RioHub — không import.');
            return self::FAILURE;
        }

        $before = $this->dbSnapshot();
        $this->newLine();
        $this->printSnapshot('DB SNAPSHOT — TRƯỚC (read-only)', $before);

        $service = new TikTokOrderSyncService($client);
        $start   = microtime(true);

        try {
            $result = $service->run(creditWallet: false);
        } catch (\Throwable $e) {
            $elapsed = round(microtime(true) - $start, 3);
            $this->newLine();
            $this->error('--- API ERROR (DỪNG, không ghi DB) ---');
            $this->error('  HTTP status   : ' . $e->getCode());
            if ($e instanceof TikTokServiceException) {
                $this->error('  RioHub message : ' . ($e->getRioHubMessage() ?? ''));
            }
            $this->error('  Message        : ' . $e->getMessage());
            $this->error("  Elapsed        : {$elapsed} s");

            $after = $this->dbSnapshot();
            $this->newLine();
            $this->printSnapshot('DB SNAPSHOT — SAU (read-only)', $after);
            $this->printComparison($before, $after);

            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $start, 3);
        $after   = $this->dbSnapshot();

        $this->newLine();
        $this->info('--- KẾT QUẢ IMPORT (Phase 2.2 — KHÔNG credit wallet) ---');
        $this->table(['Tiêu chí', 'Giá trị'], [
            ['Orders fetched', (string) $result->ordersFetched],
            ['Items fetched', (string) $result->itemsFetched],
            ['INSERTED', (string) $result->inserted],
            ['UPDATED', (string) $result->updated],
            ['SKIPPED', (string) $result->skipped],
            ['Errors', (string) $result->errors],
            ['Cashback credited', '0 (chưa gọi WalletService)'],
            ['Elapsed', "{$elapsed} s"],
        ]);

        if (count($result->errorsDetail) > 0) {
            $this->warn('--- ERRORS (không bỏ qua) ---');
            foreach ($result->errorsDetail as $err) {
                $this->line('  ! ' . $err);
            }
            $this->newLine();
        }

        $this->newLine();
        $this->info('--- TIKTOK ROWS (đọc lại từ DB sau import) ---');
        $items = AffiliateOrderItem::where('platform', 'TikTok')->orderBy('id')->get();
        $this->table(
            ['ID', 'Order', 'Item', 'Content ID', 'Checkout', 'Username', 'User ID', 'Status', 'Sub1', 'Sub2', 'Rate', 'Cashback', '1st Import', 'Last TikTok Sync'],
            $items->map(fn ($r) => [
                (string) $r->id,
                $r->order_id,
                (string) $r->item_id,
                $r->content_id ?? '-',
                $r->checkout_id === '' ? '(empty)' : (string) $r->checkout_id,
                $r->username ?? '-',
                (string) ($r->user_id ?? '-'),
                $r->affiliate_status,
                $r->sub_id1 ?? '-',
                $r->sub_id2 ?? '-',
                $r->cashback_rate !== null ? $this->percent((float) $r->cashback_rate) : '-',
                $r->cashback_amount !== null ? $this->money((float) $r->cashback_amount, 0) : '-',
                $r->first_imported_at?->format('Y-m-d H:i:s') ?? '-',
                $r->last_tiktok_sync_at?->format('Y-m-d H:i:s') ?? '-',
            ])->all(),
        );

        $this->newLine();
        $distinct = AffiliateOrderItem::where('platform', 'TikTok')->count();
        $dups     = AffiliateOrderItem::where('platform', 'TikTok')
            ->groupBy('order_id', 'item_id')
            ->selectRaw('order_id, item_id, COUNT(*) AS c')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->info('--- DUPLICATE CHECK (platform + order_id + item_id) ---');
        $this->table(['Tiêu chí', 'Giá trị'], [
            ['TikTok rows (total)', (string) $distinct],
            ['Duplicate groups (>1)', (string) $dups->count()],
        ]);
        foreach ($dups as $dup) {
            $this->line(sprintf('  ! Duplicate: order %s / item %s x %d', $dup->order_id, (string) $dup->item_id, (int) $dup->c));
        }

        $this->newLine();
        $this->printSnapshot('DB SNAPSHOT — SAU (read-only)', $after);
        $this->printComparison($before, $after);

        $walletOk  = $before['walletTx'] === $after['walletTx']
            && $before['walletBalance'] === $after['walletBalance']
            && $before['totalEarned'] === $after['totalEarned'];
        $shopeeOk  = $before['shopee'] === $after['shopee'];
        $dupOk     = $dups->count() === 0;

        $this->newLine();
        if ($walletOk && $shopeeOk && $dupOk) {
            $this->info('KẾT LUẬN: wallet + Shopee KHÔNG đổi ✓, không duplicate ✓.');
        } else {
            $this->error('CẢNH BÁO: phát hiện thay đổi ngoài TikTok hoặc duplicate!');
            return self::FAILURE;
        }

        $this->info(sprintf('Import hoàn tất: %d inserted / %d updated / %d skipped.', $result->inserted, $result->updated, $result->skipped));

        return self::SUCCESS;
    }

    private function printConfig(RioHubClient $client): void
    {
        $baseUrl = config('services.riohub.base_url', '');
        $apiKey  = (string) config('services.riohub.api_key', '');
        $creator = (string) config('services.riohub.creator_username', '');

        $this->info('--- RIOHUB CONFIG (API key được che) ---');
        $this->line('  Base URL            : ' . $baseUrl);
        $this->line('  API Key             : ' . $this->maskKey($apiKey));
        $this->line('  Creator (.env)      : ' . $creator);
        $this->line('  Creator (yêu cầu)   : ' . self::EXPECTED_CREATOR);
        $this->line('  Creator đúng?       : ' . ($creator === self::EXPECTED_CREATOR ? 'YES' : 'NO'));
        if (! $baseUrl || ! $apiKey) {
            $this->error('  [FATAL] Thiếu RIOHUB_BASE_URL hoặc RIOHUB_API_KEY trong cấu hình.');
        }
    }

    /**
     * @return array{pages: int, orderCount: int, total: ?int, pageTimes: array<int, float>, orders: array<int, array<string, mixed>>, error: ?array{http: string, riohub: string, message: string}}
     */
    private function fetchAllOrders(RioHubClient $client): array
    {
        $orders   = [];
        $pageTimes = [];
        $page      = 1;
        $total     = null;
        $error     = null;

        $pageSize = max(1, min((int) ($this->option('page-size') ?: 50), 100));

        do {
            $t0 = microtime(true);

            try {
                $response = $client->getOrders([
                    'page'      => $page,
                    'page_size' => $pageSize,
                ]);
            } catch (RioHubException $e) {
                $error = [
                    'http'    => (string) $e->getStatusCode(),
                    'riohub'  => (string) ($e->getRioHubMessage() ?? ''),
                    'message' => $e->getMessage(),
                ];
                break;
            } catch (\Throwable $e) {
                $error = [
                    'http'    => 'n/a',
                    'riohub'  => get_class($e),
                    'message' => $e->getMessage(),
                ];
                break;
            }

            $pageTimes[$page] = round((microtime(true) - $t0) * 1000, 2);

            $data  = $response->getData();
            $batch = $data['orders'] ?? [];

            if (! is_array($batch)) {
                $error = [
                    'http'    => (string) $response->getStatusCode(),
                    'riohub'  => '',
                    'message' => 'Malformed response: orders không phải array.',
                ];
                break;
            }

            foreach ($batch as $raw) {
                $orders[] = $raw;
            }

            $total   = (int) ($data['total'] ?? count($orders));
            $fetched = count($orders);
            $page++;
        } while ($fetched < $total && count($batch) > 0 && $page <= self::MAX_PAGES);

        if ($page > self::MAX_PAGES && $error === null) {
            $error = [
                'http'    => 'n/a',
                'riohub'  => '',
                'message' => 'Vượt quá ' . self::MAX_PAGES . ' trang — dừng để tránh vòng lặp vô hạn.',
            ];
        }

        return [
            'pages'      => count($pageTimes),
            'orderCount' => count($orders),
            'total'      => $total,
            'pageTimes'  => $pageTimes,
            'orders'     => $orders,
            'error'      => $error,
        ];
    }

    /**
     * @return array{tiktok: int, shopee: int, walletTx: int, walletBalance: float, totalEarned: float}
     */
    private function dbSnapshot(): array
    {
        return [
            'tiktok'        => (int) AffiliateOrderItem::where('platform', 'TikTok')->count(),
            'shopee'        => (int) AffiliateOrderItem::where('platform', 'Shopee')->count(),
            'walletTx'      => (int) WalletTransaction::count(),
            'walletBalance' => (float) User::sum('wallet_balance'),
            'totalEarned'   => (float) User::sum('total_earned'),
        ];
    }

    /**
     * @param  array{tiktok: int, shopee: int, walletTx: int, walletBalance: float, totalEarned: float}  $snapshot
     */
    private function printSnapshot(string $title, array $snapshot): void
    {
        $this->info("--- {$title} ---");
        $this->table(['Bảng', 'Giá trị'], [
            ['affiliate_order_items (TikTok)', (string) $snapshot['tiktok']],
            ['affiliate_order_items (Shopee)', (string) $snapshot['shopee']],
            ['wallet_transactions', (string) $snapshot['walletTx']],
            ['users.wallet_balance (SUM)', $this->money($snapshot['walletBalance'])],
            ['users.total_earned (SUM)', $this->money($snapshot['totalEarned'])],
        ]);
    }

    /**
     * @param  array{tiktok: int, shopee: int, walletTx: int, walletBalance: float, totalEarned: float}  $before
     * @param  array{tiktok: int, shopee: int, walletTx: int, walletBalance: float, totalEarned: float}  $after
     */
    private function printComparison(array $before, array $after): void
    {
        $this->info('--- SO SÁNH TRƯỚC/SAU (phải GIỐNG NHAU) ---');
        $this->table(['Tiêu chí', 'Trước', 'Sau', 'Không đổi'], [
            ['TikTok orders', (string) $before['tiktok'], (string) $after['tiktok'], $this->yesNo($before['tiktok'] === $after['tiktok'])],
            ['Shopee orders', (string) $before['shopee'], (string) $after['shopee'], $this->yesNo($before['shopee'] === $after['shopee'])],
            ['wallet_transactions', (string) $before['walletTx'], (string) $after['walletTx'], $this->yesNo($before['walletTx'] === $after['walletTx'])],
            ['wallet_balance (SUM)', $this->money($before['walletBalance']), $this->money($after['walletBalance']), $this->yesNo($before['walletBalance'] === $after['walletBalance'])],
            ['total_earned (SUM)', $this->money($before['totalEarned']), $this->money($after['totalEarned']), $this->yesNo($before['totalEarned'] === $after['totalEarned'])],
        ]);
    }

    private function statusMeaning(int $status, ?string $settle): array
    {
        $settleUpper = strtoupper((string) $settle);

        if ($status === 2 || str_contains($settleUpper, 'SETTLED')) {
            return ['affiliate' => 'Hoàn thành', 'cashback' => true];
        }

        if ($status === 3 || str_contains($settleUpper, 'REFUND') || str_contains($settleUpper, 'CANCEL')) {
            return ['affiliate' => 'Đã hủy', 'cashback' => false];
        }

        return ['affiliate' => 'Đang xử lý', 'cashback' => false];
    }

    private function commissionDiff(?float $est, ?float $actual): string
    {
        if ($actual === null) {
            return '-';
        }

        if ($est === null) {
            return 'n/a (no est)';
        }

        if (abs($actual - $est) < 0.005) {
            return 'MATCH';
        }

        return 'DIFFERENT (' . number_format($actual - $est, 2, '.', ',') . ')';
    }

    private function money(?float $value, int $decimals = 2): string
    {
        return $value === null ? '-' : number_format($value, $decimals, '.', ',');
    }

    private function percent(float $rate): string
    {
        return ((int) round($rate * 100)) . '%';
    }

    private function maskKey(string $key): string
    {
        if ($key === '') {
            return '(empty)';
        }

        if (strlen($key) <= 8) {
            return $key[0] . '****';
        }

        return substr($key, 0, 4) . '...' . substr($key, -4);
    }

    private function yesNo(bool $ok): string
    {
        return $ok ? 'YES' : 'NO';
    }
}