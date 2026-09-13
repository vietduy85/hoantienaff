<?php

namespace App\Console\Commands;

use App\Services\Lazada\LazadaFinalizeService;
use App\Services\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3 Lazada finalizer command — thin wrapper around
 * LazadaFinalizeService so both manual runs and the affiliate:sync-all
 * orchestrator share the exact same idempotent business logic.
 *
 * NOT scheduled via Laravel Scheduler — Windows Task Scheduler owns the
 * frequency (affiliate:sync-all every 6 hours).
 */
class AffiliateLazadaFinalize extends Command
{
    protected $signature = 'affiliate:lazada-finalize';

    protected $description = 'Chốt (FINALIZE) cashback đơn Lazada sau 10 ngày kể từ delivered_at — idempotent, chỉ đụng Lazada';

    public function handle(WalletService $wallet, LazadaFinalizeService $finalizer): int
    {
        $counts = $finalizer->run($wallet);

        $this->info('--- LAZADA FINALIZE REPORT (affiliate:lazada-finalize) ---');
        $this->table(['Tiêu chí', 'Giá trị'], [
            ['Rows checked', (string) $counts['checked']],
            ['Eligible (delivered_at >= 10 ngày)', (string) $counts['eligible']],
            ['Finalized (wallets credited)', (string) $counts['finalized']],
            ['Credited', (string) $counts['credited']],
            ['Skipped (chưa đủ 10 ngày / không đủ điều kiện)', (string) $counts['skipped']],
            ['Historical protected (không đụng)', (string) $counts['historical_protected']],
            ['Reversed (đã hoàn tiền, informational)', (string) $counts['reversed']],
            ['Errors', (string) $counts['errors']],
        ]);

        return $counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}