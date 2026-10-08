<?php

namespace App\Console\Commands;

use App\Services\AddLiveTag\ShopeeApiSyncService;
use App\Services\Lazada\LazadaFinalizeService;
use App\Services\Lazada\LazadaOrderSyncService;
use App\Services\ShopeeFood\ShopeeFoodOrderSyncService;
use App\Services\TikTok\TikTokOrderSyncService;
use App\Services\WalletService;
use App\Support\AffiliateSyncLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Aggregate affiliate sync orchestrator — drives the full multi-platform
 * workflow in ONE Windows Task Scheduler run:
 *
 *   [1/5] TikTok Sync
 *   [2/5] Lazada Sync
 *   [3/5] Lazada Finalize   (ONLY after a fully successful Lazada Sync)
 *   [4/5] ShopeeFood Sync
 *   [5/5] Shopee/AddLiveTag API Sync
 *
 * Windows Task Scheduler invokes `php artisan affiliate:sync-all` (no Laravel
 * Scheduler / schedule:run anywhere). Each platform calls its own service
 * DIRECTLY (no nested artisan processes) and runs inside an isolated
 * try/catch so one failure is logged and reported without losing the other
 * platforms' already-synced data.
 *
 * Hard rules preserved from the underlying services (NOT reimplemented here):
 *   - idempotent upserts (no duplicate orders / credits / WalletTransactions)
 *   - historical credited orders are never recalculated / downgraded /
 *     reversed (hasCompletedCashbackCredit), incl. Lazada 720/721/722
 *   - TikTok SETTLED belt + true-lock + historical protection unchanged
 *   - Lazada delivered_at = RAW deliveredTime only (never fulfilledTime),
 *     10-day window, reversal of NEW finalized orders returns
 *   - ShopeeFood API / cookie / pagination / commission / cashback untouched
 *   - Step 5 (Shopee/AddLiveTag): APPLY + wallet credit by default (Phase 2,
 *     enabled after the 3-way CSV/API/DB cross-check PASSed 2026-10-08);
 *     --shopee-dry-run keeps it read-only. Pagination must reach data==[] or
 *     abort. Credit goes ONLY through ConversionsImporter::credit() →
 *     WalletService::creditCashback() with idempotency + lifecycle guards.
 *
 * Exit codes: 0 = every step OK; 1 = anything failed (never silent).
 */
class AffiliateSyncAll extends Command
{
    protected $signature = 'affiliate:sync-all
                            {--shopee-dry-run : Run step 5 read-only (no row writes, no wallet credit) — verification mode}
                            {--shopee-apply : Deprecated no-op: applying rows + crediting is now the default}
                            {--shopee-from= : Shopee from date YYYY-MM-DD (default 14 days ago)}
                            {--shopee-to= : Shopee to date YYYY-MM-DD (optional)}
                            {--shopee-only : Run ONLY step 5 (Shopee/AddLiveTag), skips steps 1-4}';

    protected $description = 'Đồng bộ toàn bộ affiliate: TikTok → Lazada (sync + finalize) → ShopeeFood → Shopee/AddLiveTag API. Dành cho Windows Task Scheduler.';

    public function __construct(
        private readonly TikTokOrderSyncService $tikTokService,
        private readonly LazadaOrderSyncService $lazadaService,
        private readonly LazadaFinalizeService $lazadaFinalizer,
        private readonly ShopeeFoodOrderSyncService $shopeeFoodService,
        private readonly WalletService $wallet,
        private readonly ShopeeApiSyncService $shopeeSync,
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
        $shopeeOnly = (bool) $this->option('shopee-only');

        try {
            if (! $shopeeOnly) {
                // [1/5] TikTok Sync — always run; failure never blocks the rest.
                if (! $this->runStep(1, 5, 'TikTok Sync', fn (): bool => $this->syncTikTok())) {
                    $hasError = true;
                }

                // [2/5] Lazada Sync — the gate for Finalize.
                $lazadaSyncOk = $this->runStep(2, 5, 'Lazada Sync', fn (): bool => $this->syncLazada());
                if (! $lazadaSyncOk) {
                    $hasError = true;
                }

                // [3/5] Lazada Finalize — MANDATORY: only when Lazada Sync fully
                // succeeded. Finalize never rolls back a successful sync.
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

                // [4/5] ShopeeFood Sync — always run (independent feed).
                if (! $this->runStep(4, 5, 'ShopeeFood Sync', fn (): bool => $this->syncShopeeFood())) {
                    $hasError = true;
                }
            }

            // [5/5] Shopee/AddLiveTag API Sync — apply + credit unless --shopee-dry-run.
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

    // ------------------------------------------------------------------
    //  Step runner (isolated exception handling + logging)
    // ------------------------------------------------------------------

    /**
     * Print the step header, run the step, report success/failure. Returns
     * FALSE when the step threw OR when the step itself reported errors.
     */
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
            $this->error("{$label} FAILED: {$this->safeMessage($e)}");

            Log::error("{$label} failed", [
                'platform' => $label,
                'step' => $step,
                'exception' => get_class($e),
                'message' => $this->safeMessage($e),
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

    // ------------------------------------------------------------------
    //  Platform steps (service direct calls)
    // ------------------------------------------------------------------

    private function syncTikTok(): bool
    {
        // Same production call as affiliate:tiktok-sync --sync: fetch ALL
        // RioHub orders, upsert + idempotent wallet lifecycle.
        $result = $this->tikTokService->run(creditWallet: true);

        $this->resultSummary('TikTok', [
            ['Orders fetched', (string) $result->ordersFetched],
            ['Items fetched', (string) $result->itemsFetched],
            ['Inserted', (string) $result->inserted],
            ['Updated', (string) $result->updated],
            ['Skipped', (string) $result->skipped],
            ['Wallet credits', (string) $result->cashbackCredited],
            ['Wallet reversals', (string) $result->cashbackReversed],
            ['Protected', (string) $result->protectedSkipped],
            ['Errors', (string) $result->errors],
        ]);

        $this->logResult('TikTok Sync completed', $result->toArray());

        if ($result->errors > 0) {
            $this->warn('TikTok Sync completed with errors');

            return false;
        }

        $this->info('TikTok Sync completed');

        return true;
    }

    private function syncLazada(): bool
    {
        // Same production call as the admin Lazada sync: current month,
        // persist rows + idempotent wallet lifecycle (reversal of finalized
        // returned orders). NEVER credits estimates — finalize does that.
        $result = $this->lazadaService->run(persist: true, creditWallet: true);

        $this->resultSummary('Lazada', [
            ['Months fetched', (string) $result->monthsFetched],
            ['Pages fetched', (string) $result->pagesFetched],
            ['Records fetched', (string) $result->recordsFetched],
            ['Inserted', (string) $result->inserted],
            ['Updated', (string) $result->updated],
            ['Pending', (string) $result->pending],
            ['Completed', (string) $result->completed],
            ['Cancelled', (string) $result->cancelled],
            ['Unknown statuses', (string) $result->unknownStatuses],
            ['Wallet credits', (string) $result->cashbackCredited],
            ['Wallet reversals', (string) $result->cashbackReversed],
            ['Protected', (string) $result->protectedSkipped],
            ['Errors', (string) $result->errors],
        ]);

        $this->logResult('Lazada Sync completed', $result->toArray());

        if ($result->errors > 0) {
            $this->warn('Lazada Sync completed with errors — finalize sẽ KHÔNG chạy.');

            return false;
        }

        $this->info('Lazada Sync completed');

        return true;
    }

    private function finalizeLazada(): bool
    {
        $counts = $this->lazadaFinalizer->run($this->wallet);

        $this->resultSummary('Lazada Finalize', [
            ['Rows checked', (string) $counts['checked']],
            ['Eligible', (string) $counts['eligible']],
            ['Finalized (credited)', (string) $counts['finalized']],
            ['Credited', (string) $counts['credited']],
            ['Skipped', (string) $counts['skipped']],
            ['Historical protected', (string) $counts['historical_protected']],
            ['Reversed (informational)', (string) $counts['reversed']],
            ['Errors', (string) $counts['errors']],
        ]);

        if ($counts['errors'] > 0) {
            Log::error('Lazada Finalize completed with errors', [
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'counts' => $counts,
            ]);
            $this->warn('Lazada Finalize completed with errors');

            return false;
        }

        $this->info('Lazada Finalize completed');

        return true;
    }

    private function syncShopeeFood(): bool
    {
        // Same production call as the admin ShopeeFood sync.
        $result = $this->shopeeFoodService->run(persist: true, creditWallet: true);

        $this->resultSummary('ShopeeFood', [
            ['Checkouts fetched', (string) $result->checkoutsFetched],
            ['Orders fetched', (string) $result->ordersFetched],
            ['Items fetched', (string) $result->itemsFetched],
            ['Inserted', (string) $result->inserted],
            ['Updated', (string) $result->updated],
            ['Pending', (string) $result->pending],
            ['Completed', (string) $result->completed],
            ['Cancelled', (string) $result->cancelled],
            ['Wallet credits', (string) $result->cashbackCredited],
            ['Wallet reversals', (string) $result->cashbackReversed],
            ['Errors', (string) $result->errors],
        ]);

        $this->logResult('ShopeeFood Sync completed', $result->toArray());

        if ($result->errors > 0) {
            $this->warn('ShopeeFood Sync completed with errors');

            return false;
        }

        $this->info('ShopeeFood Sync completed');

        return true;
    }

    // ------------------------------------------------------------------
    //  Step 5: Shopee / AddLiveTag conversions API (Phase 1 plan + Phase 2)
    // ------------------------------------------------------------------

    /**
     * Default: fetch → plan → apply rows → credit wallet (Phase 2, enabled).
     * --shopee-dry-run: the whole step stays read-only (verification mode).
     * The pipeline itself lives in ShopeeApiSyncService — the SAME service the
     * admin order-sync screen runs — so the Windows Task and the manual button
     * can never drift apart. Wallet credit runs ONLY through
     * ConversionsImporter::credit() → WalletService::creditCashback()
     * (completed + cashback>0 + user resolved + not credited/locked/finalized/
     * reversed; cancelled never gets a NEW credit; no reversal here — spec §5/§11).
     */
    private function syncShopeeAddLiveTag(): bool
    {
        $dryRun = (bool) $this->option('shopee-dry-run');
        $from = $this->option('shopee-from');
        $to = $this->option('shopee-to');

        $this->info('Shopee/AddLiveTag: from='.($from ?: ShopeeApiSyncService::defaultFrom()).' to='.($to ?: '-').' mode='.($dryRun ? 'DRY-RUN (read-only)' : 'APPLY + CREDIT'));

        $result = $this->shopeeSync->run(from: $from, to: $to, dryRun: $dryRun);

        if ($result['error'] !== null) {
            $this->error($result['error']);

            return false;
        }

        $this->line('  raw items: '.$result['raw_items']);
        $this->line('  normalized: '.$result['normalized']);
        $this->line('  create: '.$result['plan_create']);
        $this->line('  update: '.$result['plan_update']);
        $this->line('  protected: '.$result['plan_protected']);
        $this->line('  user_conflict: '.$result['user_conflict']);
        $this->line('  sub_id_mismatch: '.$result['sub_id_mismatch']);
        $this->line('  overlap groups: '.$result['overlap_groups']);

        if ($dryRun) {
            $this->info('Shopee/AddLiveTag: READ-ONLY (dry-run) — no DB writes, no wallet credit.');

            return true;
        }

        $this->line('  applied created: '.$result['applied_created'].' updated: '.$result['applied_updated']);

        $credit = $result['credit'];
        $this->resultSummary('Shopee/AddLiveTag Wallet', [
            ['Checked', (string) $credit['checked']],
            ['Credited (new)', (string) $credit['credited']],
            ['Already credited', (string) $credit['already_credited']],
            ['Protected (skip)', (string) $credit['protected']],
            ['User conflict (skip)', (string) $credit['user_conflict']],
            ['Not completed', (string) $credit['not_completed']],
            ['Zero cashback', (string) $credit['zero_cashback']],
            ['No user', (string) $credit['no_user']],
            ['Errors', (string) $credit['errors']],
        ]);

        if ($credit['errors'] > 0) {
            $this->warn('Shopee/AddLiveTag sync applied but wallet credit completed with errors');

            return false;
        }

        $this->info('Shopee/AddLiveTag sync applied + wallet credit pass completed.');

        return true;
    }

    // ------------------------------------------------------------------
    //  Output helpers
    // ------------------------------------------------------------------

    /** Never let the AddLiveTag API key reach console / logs. */
    private function safeMessage(\Throwable $e): string
    {
        $msg = $e->getMessage();
        $key = trim((string) config('services.addlivetag.api_key', ''));

        if ($key !== '' && str_contains($msg, $key)) {
            $msg = str_replace($key, '****', $msg);
        }

        return preg_replace('/([?&]key=)[^&\s]+/i', '$1****', $msg) ?? $msg;
    }

    private function box(string $content): void
    {
        $this->newLine();
        $this->line('========================================');
        foreach (explode("\n", $content) as $line) {
            $this->line($line);
        }
        $this->line('========================================');
        $this->newLine();
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $rows
     */
    private function resultSummary(string $platform, array $rows): void
    {
        $this->table(['Tiêu chí', 'Giá trị'], $rows);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logResult(string $message, array $payload): void
    {
        Log::info($message, array_merge(['finished_at' => now()->format('Y-m-d H:i:s')], $payload));
    }
}
