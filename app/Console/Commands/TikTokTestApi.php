<?php

namespace App\Console\Commands;

use App\Services\RioHub\RioHubClient;
use App\Services\RioHub\RioHubException;
use Illuminate\Console\Command;

class TikTokTestApi extends Command
{
    protected $signature = 'tiktok:test-api {--url= : Product URL to test createAffiliateLink}';
    protected $description = 'Test RioHub TikTok Affiliate API connectivity and responses';

    public function handle(): int
    {
        $this->info('================================================');
        $this->info('  RioHub TikTok API Test');
        $this->info('================================================');
        $this->newLine();

        $baseUrl    = config('services.riohub.base_url');
        $apiKey     = config('services.riohub.api_key');
        $creator    = config('services.riohub.creator_username');

        $this->line("  Base URL:        {$baseUrl}");
        $this->line("  API Key:         " . substr($apiKey, 0, 8) . '...' . substr($apiKey, -4));
        $this->line("  Creator:         {$creator}");
        $this->newLine();

        if (!$apiKey) {
            $this->error('  RIOHUB_API_KEY is empty in .env');
            return 1;
        }

        if (!$creator) {
            $this->error('  RIOHUB_CREATOR_USERNAME is empty in .env');
            return 1;
        }

        $client = new RioHubClient();

        // --- TEST 1: GET Orders ---
        $this->testGetOrders($client);

        // --- TEST 2: GET Product (optional, needs product_id) ---
        $this->testGetProduct($client);

        // --- TEST 3: POST Create Link (optional, needs --url) ---
        $url = $this->option('url');
        if ($url) {
            $this->testCreateLink($client, $url);
        } else {
            $this->warn('  [SKIP] CreateAffiliateLink — pass --url="https://tiktok.com/..." to test');
            $this->newLine();
        }

        $this->info('================================================');
        $this->info('  Done');
        $this->info('================================================');

        return 0;
    }

    private function testGetOrders(RioHubClient $client): void
    {
        $this->warn('--- TEST 1: GET /partner/tiktok/affiliate/orders ---');

        $start = microtime(true);

        try {
            $response = $client->getOrders(['limit' => 5]);
            $elapsed  = round((microtime(true) - $start) * 1000, 2);

            $this->line("  Status: {$response->getStatusCode()}");
            $this->line("  Time:   {$elapsed} ms");

            $data = $response->getData();
            $this->line("  Response keys: " . implode(', ', array_keys($data)));

            $orders = $data['orders'] ?? [];
            if (is_array($orders)) {
                $this->line("  Orders returned: " . count($orders));
            }

            $this->line("  Full response:");
            $this->line('  ' . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();
        } catch (RioHubException $e) {
            $elapsed = round((microtime(true) - $start) * 1000, 2);
            $this->error("  FAILED in {$elapsed} ms");
            $this->error("  HTTP {$e->getStatusCode()}: {$e->getRioHubMessage()}");
            $this->error("  Message: {$e->getMessage()}");
            $this->newLine();
        } catch (\Throwable $e) {
            $elapsed = round((microtime(true) - $start) * 1000, 2);
            $this->error("  EXCEPTION in {$elapsed} ms");
            $this->error("  Class: " . get_class($e));
            $this->error("  Message: {$e->getMessage()}");
            $this->error("  File: {$e->getFile()}:{$e->getLine()}");
            $this->newLine();
        }
    }

    private function testGetProduct(RioHubClient $client): void
    {
        $this->warn('--- TEST 2: GET /partner/tiktok/affiliate/products ---');

        $this->line("  [INFO] Requires a valid product_id — skipping (no product_id configured)");
        $this->line("  To test: manually call with a known TikTok product_id");
        $this->newLine();
    }

    private function testCreateLink(RioHubClient $client, string $url): void
    {
        $this->warn('--- TEST 3: POST /partner/tiktok/affiliate/links ---');
        $this->line("  URL: {$url}");

        $start = microtime(true);

        try {
            $response = $client->createAffiliateLink($url);
            $elapsed  = round((microtime(true) - $start) * 1000, 2);

            $this->line("  Status: {$response->getStatusCode()}");
            $this->line("  Time:   {$elapsed} ms");

            $data = $response->getData();
            $this->line("  Full response:");
            $this->line('  ' . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();
        } catch (RioHubException $e) {
            $elapsed = round((microtime(true) - $start) * 1000, 2);
            $this->error("  FAILED in {$elapsed} ms");
            $this->error("  HTTP {$e->getStatusCode()}: {$e->getRioHubMessage()}");
            $this->error("  Message: {$e->getMessage()}");
            $this->newLine();
        } catch (\Throwable $e) {
            $elapsed = round((microtime(true) - $start) * 1000, 2);
            $this->error("  EXCEPTION in {$elapsed} ms: {$e->getMessage()}");
            $this->newLine();
        }
    }
}
