<?php

namespace App\Console\Commands;

use App\Models\AffiliateOrderItem;
use App\Services\WalletService;
use Illuminate\Console\Command;

class WalletBootstrapLedger extends Command
{
    protected $signature = 'wallet:bootstrap-ledger
                        {--dry-run : Thống kê mà không ghi database}';
    protected $description = 'Khởi tạo ledger từ affiliate_order_items hiện có (chạy một lần)';

    private const STATUS_FILTER = 'Hoàn thành';

    private int $totalCount = 0;
    private int $willCreateCount = 0;
    private int $alreadyExistsCount = 0;
    private int $noUserCount = 0;
    private int $errorCount = 0;

    private WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        parent::__construct();
        $this->walletService = $walletService;
    }

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $this->info('================================');
        $this->info('  Bootstrap Ledger');
        $this->info('================================');

        $items = AffiliateOrderItem::where('affiliate_status', self::STATUS_FILTER)
            ->orderBy('id')
            ->get();

        $this->totalCount = $items->count();
        $this->line('');
        $this->line('Tổng số đơn Hoàn thành: ' . $this->totalCount);

        if ($this->totalCount === 0) {
            $this->line('');
            $this->info('Không có đơn nào cần xử lý.');
            return Command::SUCCESS;
        }

        $this->line('');

        $bar = $this->output->createProgressBar($this->totalCount);
        $bar->start();

        foreach ($items as $item) {
            if ($isDryRun) {
                $this->processDryRun($item);
            } else {
                $this->processItem($item);
            }
            $bar->advance();
        }

        $bar->finish();
        $this->line('');
        $this->line('');

        $this->outputSummary($isDryRun);

        if ($this->errorCount > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function processItem(AffiliateOrderItem $item): void
    {
        if ($item->user_id === null) {
            $this->noUserCount++;
            return;
        }

        try {
            $transaction = $this->walletService->creditCashback($item, throwOnDuplicate: false);

            if ($transaction) {
                $this->willCreateCount++;
            } else {
                $this->alreadyExistsCount++;
            }
        } catch (\Throwable $e) {
            $this->errorCount++;
            $this->line('');
            $this->error(sprintf(
                'Lỗi: #%d (%s) — %s',
                $item->id,
                $item->order_id,
                $e->getMessage(),
            ));
        }
    }

    private function processDryRun(AffiliateOrderItem $item): void
    {
        if ($item->user_id === null) {
            $this->noUserCount++;
            return;
        }

        if ($this->walletService->isCashbackCredited($item)) {
            $this->alreadyExistsCount++;
        } else {
            $this->willCreateCount++;
        }
    }

    private function outputSummary(bool $isDryRun): void
    {
        $this->info('================================');
        $this->info('  Bootstrap Ledger');
        $this->info('================================');
        $this->line('  Tổng số đơn:              ' . $this->totalCount);
        $this->line('  Ledger sẽ tạo:            ' . $this->willCreateCount);
        $this->line('  Ledger đã tồn tại:         ' . $this->alreadyExistsCount);
        $this->line('  Không có user:            ' . $this->noUserCount);
        $this->line('  Lỗi:                      ' . $this->errorCount);

        if ($isDryRun) {
            $this->info('================================');
            $this->info('  DRY RUN');
            $this->info('================================');
            $this->line('');
            $this->line('  Không có thay đổi nào được ghi vào Database.');
        } else {
            $this->info('================================');
            $this->info('  SUCCESS');
            $this->info('================================');
        }
    }
}
