<?php

namespace App\Console\Commands;

use App\Services\AddLiveTag\ConversionsClient;
use App\Services\AddLiveTag\ConversionsImporter;
use App\Services\AddLiveTag\ConversionsNormalizer;
use App\Services\Lazada\LazadaFinalizeService;
use App\Services\Lazada\LazadaOrderSyncService;
use App\Services\ShopeeFood\ShopeeFoodOrderSyncService;
use App\Services\TikTok\TikTokOrderSyncService;
use App\Support\AffiliateSyncLock;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AffiliateSyncAll extends Command
{
    protected $signature = 'affiliate:sync-all
                            {--shopee-apply : Apply Shopee/AddLiveTag API updates (rows only, wallet unchanged per Phase 1 rules)}
                            {--shopee-from= : Shopee from date YYYY-MM-DD (default 14 days ago)}
                            {--shopee-to= : Shopee to date YYYY-MM-DD (optional)}';

    protected $description = 'Đồng bộ toàn bộ affiliate: TikTok → Lazada (sync + finalize) → ShopeeFood → Shopee/AddLiveTag API. Dành cho Windows Task Scheduler.';

    public function __construct(
        private readonly TikTokOrderSyncService $tikTokService,
        private readonly LazadaOrderSyncService $lazadaService,
        private readonly LazadaFinalizeService $lazadaFinalizer,
        private readonly ShopeeFoodOrderSyncService $shopeeFoodService,
        private readonly ConversionsClient $shopeeClient,
        private readonly ConversionsNormalizer $shopeeNormalizer,
        private readonly ConversionsImporter $shopeeImporter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $started = now();

        $this->box("Affiliate Sync All\nStarted: {$started->format('Y-m-d H:i:s')}");
        Log::info('Affiliate Sync All started', ['started_at' => $started->format('Y-m-d H:i:s')]);

        $lock = Cache::lock(AffiliateSyncLock::KEY, AffiliateSyncLock::SECONDS);

        if (! $lock->get()) {
            $this->error('[BLOCK] Một phiên đồng bộ affiliate khác (sync-all / admin sync / import CSV) đang thực hiện — bỏ qua lần này (chống chạy chồng lấn).');
            Log::warning('Affiliate Sync All skipped (lock held)', [
                'started_at' => $started->format('Y-m-d H:i:s'),
            ]);

            return self::FAILURE;
        }

        $hasError = false;

        try {
            if (! $this->runStep(1, 5, 'TikTok Sync', fn (): bool => $this->syncTikTok())) {
                $hasError = true;
            }

            $lazadaSyncOk = $this->runStep(2, 5, 'Lazada Sync', fn (): bool => $this->syncLazada());
            if (! $lazadaSyncOk) {
                $hasError = true;
            }

            if ($lazadaSyncOk) {
                if (! $this->runStep(3, 5, 'Lazada Finalize', fn (): bool => $this->finalizeLazada())) {
                    $hasError = true;
                }
            } else {
                $this->warn('[3/5] Lazada Finalize SKIPPED — Lazada Sync was not fully successful.');
                Log::warning('Affiliate Sync All: Lazada Finalize skipped because Lazada Sync failed', [
                    'started_at' => $started->format('Y-m-d H:i:s'),
                ]);
            }

            if (! $this->runStep(4, 5, 'ShopeeFood Sync', fn (): bool => $this->syncShopeeFood())) {
                $hasError = true;
            }

            if (! $this->runStep(5, 5, 'Shopee/AddLiveTag API Sync', fn (): bool => $this->syncShopeeAddLiveTag())) {
                $hasError = true;
            }
        } finally {
            $lock->release();
        }

        $finished = now();

        if ($hasError) {
            $this->box("Affiliate Sync All completed WITH ERRORS\nFinished: {$finished->format('Y-m-d H:i:s')}");
            Log::error('Affiliate Sync All completed with errors', [
                'finished_at' => $finished->format('Y-m-d H:i:s'),
            ]);

            return self::FAILURE;
        }

        $this->box("Affiliate Sync All completed\nFinished: {$finished->format('Y-m-d H:i:s')}");
        Log::info('Affiliate Sync All completed', [
            'finished_at' => $finished->format('Y-m-d H:i:s'),
        ]);

        return self::SUCCESS;
    }

    private function runStep(int $step, int $total, string $label, \Closure $fn): bool
    {
        $this->newLine();
        $this->info("[{$step}/{$total}] {$label}");

        Log::info("{$label} started", [
            'step' => $step,
            'started_at' => now()->format('Y-m-d H:i:s'),
        ]);

        try {
            $ok = $fn();
        } catch (\Throwable $e) {
            $this->error("{$label} FAILED: {$e->getMessage()}");

            Log::error("{$label} failed", [
                'platform' => $label,
                'step' => $step,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'timestamp' => now()->format('Y-m-d H:i:s'),
            ]);

            return false;
        }

        if (! $ok) {
            Log::warning("{$label} completed with errors", [
                'step' => $step,
                'finished_at' => now()->format('Y-m-d H:i:s'),
            ]);
        }

        return $ok;
    }
