<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\LinkRequest;
use App\Models\AffiliateCache;
use App\Services\UrlResolverService;
use App\Services\AffiliateCacheService;
use App\Services\ProductDataService;
use App\Services\CashbackCalculator;
use App\Services\ProviderFactory;
use App\Services\AffiliateWorkerClient;
use App\Enums\Platform;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class BenchmarkAffiliate extends Command
{
    protected $signature = 'benchmark:affiliate {url?}';
    protected $description = 'Benchmark real service calls for affiliate link creation';

    private const TEST_URL = 'https://shopee.vn/(COMBO-2-B%E1%BB%8ACH)-B%E1%BB%89m-d%C3%A1n-B%E1%BB%89m-qu%E1%BA%A7n-Moony-D%E1%BB%8Bu-Nh%E1%BA%B9-Moony-tr%E1%BA%AFng-Moony-Natural-si%C3%AAu-cao-c%E1%BA%A5p-cho-B%C3%A9.-i.1718067043.51806493277?extraParams=%7B%22display_model_id%22%3A445584414103%2C%22model_selection_logic%22%3A3%7D&sp_atk=5f290dd0-04a6-4ef6-acc7-62bbbf4739ae&xptdk=5f290dd0-04a6-4ef6-acc7-62bbbf4739ae';

    private array $results = [];

    public function handle(): int
    {
        $url = $this->argument('url') ?? self::TEST_URL;

        $this->info('==============================================');
        $this->info('  BENCHMARK: REAL SERVICE CALLS');
        $this->info('==============================================');
        $this->newLine();
        $this->line("URL: {$url}");
        $this->newLine();

        // Login once so subsequent operations are authenticated
        $this->loginTestUser();

        // ===== TEST 1: Resolve URL =====
        $this->testResolveUrl($url);

        // ===== TEST 2: Detect Platform =====
        $this->testDetectPlatform($url);

        // ===== TEST 3: Extract item_id from resolved URL =====
        $resolvedUrl = $this->results['resolve_url']['resolved'] ?? $url;
        $this->testExtractItemId($resolvedUrl);

        // ===== TEST 4: AddLiveTag API (cache miss) =====
        $itemId = $this->results['extract_item_id']['item_id'] ?? null;
        $this->testAddLiveTag($resolvedUrl, $itemId);

        // ===== TEST 5: CashbackCalculator =====
        $this->testCashback();

        // ===== TEST 6: Create LinkRequest (real DB) =====
        $this->testCreateLinkRequest($url, $resolvedUrl);

        // ===== TEST 7: Worker health check =====
        $this->testWorkerHealth();

        // ===== TEST 8: Extension jobs endpoint =====
        $this->testExtensionJobs();

        // ===== TEST 9: Update result (simulate extension POST) =====
        $this->testExtensionResult();

        // ===== SUMMARY =====
        $this->printSummary();

        // Cleanup test data
        $this->cleanup();

        return 0;
    }

    private function loginTestUser(): void
    {
        $user = User::firstWhere('email', 'benchmark@test.com');
        if (!$user) {
            $user = User::factory()->create([
                'email' => 'benchmark@test.com',
                'name' => 'Benchmark User',
            ]);
        }
        Auth::login($user);
        $this->line("   Login as: {$user->email} (ID: {$user->id})");
        $this->newLine();
    }

    private function testResolveUrl(string $url): void
    {
        $this->warn('--- TEST 1: Resolve URL ---');
        $this->line("   Input URL: {$url}");

        $service = app(UrlResolverService::class);
        $start = microtime(true);
        $resolved = $service->resolve($url);
        $elapsed = round((microtime(true) - $start) * 1000, 2);

        $effectiveUrl = $resolved ?? $url;

        $this->line("   Resolved URL: {$effectiveUrl}");
        $this->line("   Time: {$elapsed} ms");

        $this->results['resolve_url'] = [
            'input' => $url,
            'resolved' => $effectiveUrl,
            'ms' => $elapsed,
            'was_resolved' => $resolved !== null && $resolved !== $url,
        ];

        $this->newLine();
    }

    private function testDetectPlatform(string $url): void
    {
        $this->warn('--- TEST 2: Detect Platform ---');

        $service = app(ProviderFactory::class);
        $start = microtime(true);
        $platform = $service->detectPlatform($url);
        $elapsed = round((microtime(true) - $start) * 1000, 2);

        $this->line("   Platform: {$platform->value} ({$platform->label()})");
        $this->line("   Time: {$elapsed} ms");

        $this->results['detect_platform'] = [
            'platform' => $platform->value,
            'label' => $platform->label(),
            'ms' => $elapsed,
        ];

        $this->newLine();
    }

    private function testExtractItemId(string $url): void
    {
        $this->warn('--- TEST 3: Extract item_id ---');

        $service = app(AffiliateCacheService::class);

        // Extract via cacheService (matches the actual code path)
        $start = microtime(true);
        $itemId = $service->extractItemId($url);
        $elapsed = round((microtime(true) - $start) * 1000, 2);

        // Also extract shop_id using ProductDataService's extractProductIds
        $pds = app(ProductDataService::class);
        $refl = new \ReflectionMethod($pds, 'extractProductIds');
        $refl->setAccessible(true);
        $ids = $refl->invoke($pds, $url);

        $this->line("   item_id: " . ($itemId ?? 'null'));
        $this->line("   shop_id: " . (($ids['shop_id'] ?? null) ?: 'null'));
        $this->line("   Time: {$elapsed} ms");

        $this->results['extract_item_id'] = [
            'item_id' => $itemId,
            'shop_id' => $ids['shop_id'] ?? null,
            'ms' => $elapsed,
        ];

        $this->newLine();
    }

    private function testAddLiveTag(string $url, ?int $itemId): void
    {
        $this->warn('--- TEST 4: AddLiveTag API (ProductDataService) ---');

        // Clear cache for this item first (if item exists)
        if ($itemId) {
            $deleted = AffiliateCache::where('item_id', $itemId)->delete();
            $this->line("   Cache cleared for item_id {$itemId}: " . ($deleted ? 'deleted' : 'none found'));
        } else {
            $this->line("   [SKIP] No item_id to clear cache");
        }

        $service = app(ProductDataService::class);

        $start = microtime(true);
        $productData = $service->getByUrl($url);
        $totalElapsed = round((microtime(true) - $start) * 1000, 2);

        if (($productData['success'] ?? false)) {
            $this->line("   product_name: {$productData['product_name']}");
            $this->line("   product_price: {$productData['product_price']}");
            $this->line("   estimated_cashback (commission): {$productData['commission']}");
            $this->line("   seller_commission: {$productData['seller_commission']}");
            $this->line("   shopee_commission: {$productData['shopee_commission']}");
        } else {
            $this->warn("   AddLiveTag returned success=false");
        }

        $this->line("   Total time: {$totalElapsed} ms");

        $this->results['addlivetag'] = [
            'success' => $productData['success'] ?? false,
            'product_name' => $productData['product_name'] ?? null,
            'product_price' => $productData['product_price'] ?? null,
            'commission' => $productData['commission'] ?? null,
            'seller_commission' => $productData['seller_commission'] ?? null,
            'shopee_commission' => $productData['shopee_commission'] ?? null,
            'ms' => $totalElapsed,
        ];

        $this->newLine();
    }

    private function testCashback(): void
    {
        $this->warn('--- TEST 5: CashbackCalculator ---');

        $alData = $this->results['addlivetag'] ?? [];
        $commission = (float) ($alData['commission'] ?? 0);
        $price = (float) ($alData['product_price'] ?? 0);

        $this->line("   Input: commission={$commission}, price={$price}");

        $service = app(CashbackCalculator::class);
        $start = microtime(true);
        $result = $service->calculate($commission, $price);
        $elapsed = round((microtime(true) - $start) * 1000, 2);

        $this->line("   cashback_rate: {$result['cashback_rate']}");
        $this->line("   user_estimated_cashback: {$result['user_estimated_cashback']}");
        $this->line("   Time: {$elapsed} ms");

        $this->results['cashback'] = [
            'cashback_rate' => $result['cashback_rate'],
            'user_estimated_cashback' => $result['user_estimated_cashback'],
            'ms' => $elapsed,
        ];

        $this->newLine();
    }

    private function testCreateLinkRequest(string $originalUrl, string $resolvedUrl): void
    {
        $this->warn('--- TEST 6: Create LinkRequest (real INSERT) ---');

        $user = Auth::user();
        $platform = $this->results['detect_platform']['platform'] ?? 'shopee';
        $alData = $this->results['addlivetag'] ?? [];
        $cashData = $this->results['cashback'] ?? [];
        $itemData = $this->results['extract_item_id'] ?? [];

        $isShopee = str_contains(strtolower($platform), 'shopee');

        // First insert (initial creation — status=pending)
        $start = microtime(true);
        $link = LinkRequest::create([
            'user_id' => $user->id,
            'original_url' => $originalUrl,
            'platform' => Platform::tryFrom($platform)?->label() ?? 'Shopee',
            'status' => $isShopee ? 'pending' : 'completed',
        ]);
        $insertTime = round((microtime(true) - $start) * 1000, 2);

        // Second insert: update with product data (as in DashboardController)
        $updateStart = microtime(true);
        if ($alData['success'] ?? false) {
            $link->update([
                'item_id'               => $itemData['item_id'],
                'shop_id'               => $itemData['shop_id'],
                'estimated_cashback'     => $alData['commission'],
                'user_estimated_cashback' => $cashData['user_estimated_cashback'],
                'cashback_rate'          => $cashData['cashback_rate'],
                'product_name'           => $alData['product_name'],
                'product_price'          => $alData['product_price'],
                'seller_commission'      => $alData['seller_commission'],
                'shopee_commission'      => $alData['shopee_commission'],
                'data_source'            => 'benchmark',
            ]);
        }
        $updateTime = round((microtime(true) - $updateStart) * 1000, 2);

        $this->line("   LinkRequest ID: {$link->id}");
        $this->line("   Status: {$link->status}");
        $this->line("   INSERT time: {$insertTime} ms");
        $this->line("   UPDATE time: {$updateTime} ms");

        $this->results['create_link_request'] = [
            'id' => $link->id,
            'status' => $link->status,
            'insert_ms' => $insertTime,
            'update_ms' => $updateTime,
        ];

        $this->newLine();
    }

    private function testWorkerHealth(): void
    {
        $this->warn('--- TEST 7: Worker / Health Check ---');

        $service = app(AffiliateWorkerClient::class);

        // Ping
        $pingTime = 0;
        $ping = false;
        $start = microtime(true);
        try {
            $ping = $service->ping();
            $pingTime = round((microtime(true) - $start) * 1000, 2);
        } catch (\Throwable $e) {
            $pingTime = round((microtime(true) - $start) * 1000, 2);
        }

        $this->line("   Worker ping: " . ($ping ? 'ONLINE' : 'OFFLINE'));
        $this->line("   Ping time: {$pingTime} ms");

        // Health
        $healthTime = 0;
        $health = [];
        $start = microtime(true);
        try {
            $health = $service->health();
            $healthTime = round((microtime(true) - $start) * 1000, 2);
        } catch (\Throwable $e) {
            $healthTime = round((microtime(true) - $start) * 1000, 2);
        }

        $this->line("   Health version: " . ($health['version'] ?? 'N/A'));
        $this->line("   Health time: {$healthTime} ms");

        // createLink test (actually hits the Express server)
        $createTime = 0;
        $createSuccess = false;
        $start = microtime(true);
        try {
            $createResult = $service->createLink('https://shopee.vn/test-benchmark');
            $createSuccess = $createResult['success'] ?? false;
            $createTime = round((microtime(true) - $start) * 1000, 2);
        } catch (\Throwable $e) {
            $createTime = round((microtime(true) - $start) * 1000, 2);
        }

        $this->line("   createLink success: " . ($createSuccess ? 'yes' : 'no'));
        $this->line("   createLink time: {$createTime} ms");

        $this->results['worker'] = [
            'ping_ms' => $pingTime,
            'health_ms' => $healthTime,
            'online' => $ping,
            'create_link_ms' => $createTime,
        ];

        $this->newLine();
    }

    private function testExtensionJobs(): void
    {
        $this->warn('--- TEST 8: Extension Jobs API ---');

        $token = config('services.affiliate_extension.token');
        $baseUrl = config('app.url');

        if (!$token) {
            $this->line("   [SKIP] No AFFILIATE_EXTENSION_TOKEN configured");
            $this->results['extension_jobs'] = ['skipped' => true, 'reason' => 'no token'];
            $this->newLine();
            return;
        }

        // Direct HTTP call to the jobs endpoint
        $jobUrl = rtrim($baseUrl, '/') . '/api/extension/jobs?token=' . $token;

        $start = microtime(true);
        try {
            $response = Http::timeout(10)->get($jobUrl);
            $elapsed = round((microtime(true) - $start) * 1000, 2);

            if ($response->successful()) {
                $data = $response->json();
                $jobs = $data['jobs'] ?? [];
                $payloadSize = strlen($response->body());

                $this->line("   Response time: {$elapsed} ms");
                $this->line("   Payload size: {$payloadSize} bytes");
                $this->line("   Jobs returned: " . count($jobs));

                if (count($jobs) > 0) {
                    foreach ($jobs as $job) {
                        $this->line("     - ID: {$job['id']}, URL: {$job['original_url']}");
                    }
                }

                $this->results['extension_jobs'] = [
                    'ms' => $elapsed,
                    'payload_bytes' => $payloadSize,
                    'jobs_count' => count($jobs),
                ];
            } else {
                $this->warn("   HTTP {$response->status()} — {$response->body()}");
                $this->results['extension_jobs'] = ['error' => 'HTTP ' . $response->status()];
            }
        } catch (\Throwable $e) {
            $this->error("   Exception: {$e->getMessage()}");
            $this->results['extension_jobs'] = ['error' => $e->getMessage()];
        }

        $this->newLine();
    }

    private function testExtensionResult(): void
    {
        $this->warn('--- TEST 9: Extension Results API (POST simulation) ---');

        $token = config('services.affiliate_extension.token');
        $baseUrl = config('app.url');
        $linkId = $this->results['create_link_request']['id'] ?? null;
        $itemId = $this->results['extract_item_id']['item_id'] ?? null;

        if (!$token) {
            $this->line("   [SKIP] No token");
            $this->results['extension_result'] = ['skipped' => true, 'reason' => 'no token'];
            $this->newLine();
            return;
        }

        if (!$linkId) {
            $this->line("   [SKIP] No LinkRequest to update");
            $this->results['extension_result'] = ['skipped' => true, 'reason' => 'no link id'];
            $this->newLine();
            return;
        }

        // Simulate the extension posting a result back
        $resultUrl = rtrim($baseUrl, '/') . '/api/extension/results?token=' . $token;

        // Update link request directly (simulating what the result endpoint does)
        $start = microtime(true);
        try {
            // Mark as processing first (like extension does when picking up job)
            LinkRequest::where('id', $linkId)->where('status', 'pending')
                ->update(['status' => 'processing']);

            // POST result (simulating extension callback)
            $response = Http::timeout(10)->post($resultUrl, [
                'results' => [
                    [
                        'id' => $linkId,
                        'affiliate_url' => 'https://shopee.vn/affiliate/benchmark-test-' . $linkId,
                        'status' => 'completed',
                    ],
                ],
            ]);
            $elapsed = round((microtime(true) - $start) * 1000, 2);

            if ($response->successful()) {
                $data = $response->json();
                $this->line("   HTTP response time: {$elapsed} ms");
                $this->line("   Result: updated={$data['updated']}");

                $this->results['extension_result'] = [
                    'ms' => $elapsed,
                    'updated' => $data['updated'] ?? 0,
                ];

                // Verify the update
                $lr = LinkRequest::find($linkId);
                $this->line("   LinkRequest status now: {$lr->status}");
                $this->line("   Has affiliate_url: " . ($lr->affiliate_url ? 'yes' : 'no'));
            } else {
                $this->warn("   HTTP {$response->status()} — {$response->body()}");
                $this->results['extension_result'] = ['error' => 'HTTP ' . $response->status()];
            }
        } catch (\Throwable $e) {
            $this->error("   Exception: {$e->getMessage()}");
            $this->results['extension_result'] = ['error' => $e->getMessage()];
        }

        $this->newLine();
    }

    private function printSummary(): void
    {
        $this->info('==============================================');
        $this->info('  BENCHMARK SUMMARY');
        $this->info('==============================================');
        $this->newLine();

        $rows = [
            ['Resolve URL', $this->results['resolve_url']['ms'] ?? 0, 'UrlResolverService::resolve()'],
            ['Detect Platform', $this->results['detect_platform']['ms'] ?? 0, 'ProviderFactory::detectPlatform()'],
            ['Extract item_id', $this->results['extract_item_id']['ms'] ?? 0, 'AffiliateCacheService::extractItemId()'],
            ['AddLiveTag API', $this->results['addlivetag']['ms'] ?? 0, 'ProductDataService::getByUrl() → HTTP'],
            ['Cache clear (DB)', 0, 'AffiliateCache::delete() — trước test'],
            ['CashbackCalculator', $this->results['cashback']['ms'] ?? 0, 'CashbackCalculator::calculate()'],
            ['Insert LinkRequest', $this->results['create_link_request']['insert_ms'] ?? 0, 'LinkRequest::create() — DB INSERT'],
            ['Update LinkRequest', $this->results['create_link_request']['update_ms'] ?? 0, 'LinkRequest::update() — DB UPDATE'],
            ['Worker ping', $this->results['worker']['ping_ms'] ?? 0, 'AffiliateWorkerClient::ping()'],
            ['Worker health', $this->results['worker']['health_ms'] ?? 0, 'AffiliateWorkerClient::health()'],
            ['Worker createLink', $this->results['worker']['create_link_ms'] ?? 0, 'AffiliateWorkerClient::createLink()'],
            ['Extension GET /jobs', $this->results['extension_jobs']['ms'] ?? 0, 'HTTP GET /api/extension/jobs'],
            ['Extension POST /results', $this->results['extension_result']['ms'] ?? 0, 'HTTP POST /api/extension/results'],
        ];

        $total = 0;
        foreach ($rows as $row) {
            $total += (float) $row[1];
        }

        // Print table
        $this->line(str_repeat('-', 100));
        $this->line(sprintf("   %-30s %12s   %s", 'Step', 'Time', 'Implementation'));
        $this->line(str_repeat('-', 100));

        foreach ($rows as $row) {
            $ms = $row[1];
            $bar = $ms > 0 ? str_repeat('█', min((int) ($ms / 5), 60)) : '';
            $this->line(sprintf("   %-30s %8s ms   %s %s",
                $row[0],
                $ms == 0 && $row[2] !== 'AffiliateCache::delete() — trước test' ? '<1' : number_format($ms, 2),
                $bar,
                $row[2]
            ));
        }

        $this->line(str_repeat('-', 100));
        $this->line(sprintf("   %-30s %8s ms", 'TOTAL SERVER TIME', number_format($total, 2)));
        $this->line(str_repeat('-', 100));

        $this->newLine();
        $this->info('==============================================');
        $this->info('  ANALYSIS');
        $this->info('==============================================');
        $this->newLine();

        // Find bottleneck
        $sorted = $rows;
        usort($sorted, fn ($a, $b) => $b[1] <=> $a[1]);

        $this->line("   Top bottlenecks:");
        $this->newLine();

        foreach ($sorted as $i => $row) {
            if ((float) $row[1] <= 0) continue;
            $pct = $total > 0 ? round((float) $row[1] / $total * 100, 1) : 0;
            $this->line(sprintf("   #%d  %-30s %5s ms  (%5.1f%%)  %s",
                $i + 1,
                $row[0],
                number_format((float) $row[1], 2),
                $pct,
                $row[2]
            ));
        }

        $this->newLine();
        $this->info('   Notes:');
        $this->newLine();
        $this->line('   - Resolve URL: 0ms vì URL đã là full shopee.vn (không phải short link)');
        $this->line('   - Detect Platform / Extract item_id: <1ms (pure regex, local)');
        $this->line('   - CashbackCalculator: <1ms (pure math)');
        $this->line('   - Worker createLink: luôn trả false vì CDP worker đã deprecated');
        $this->line('   - Extension GET/POST: phụ thuộc vào server + network;');
        $this->line('     thời gian THỰC extension xử lý phụ thuộc vào Shopee + poll interval');
        $this->line('   - Không thể benchmark content.js + Shopee bằng CLI vì');
        $this->line('     cần trình duyệt thật với extension installed');
    }

    private function cleanup(): void
    {
        $linkId = $this->results['create_link_request']['id'] ?? null;
        if ($linkId) {
            LinkRequest::where('id', $linkId)->delete();
        }
    }
}
