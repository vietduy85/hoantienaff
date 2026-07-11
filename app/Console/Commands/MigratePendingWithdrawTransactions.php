<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WithdrawRequest;
use App\Services\WalletService;
use Illuminate\Console\Command;

class MigratePendingWithdrawTransactions extends Command
{
    protected $signature = 'wallet:migrate-pending-withdraw-transactions
                        {--dry-run : Thống kê mà không ghi database}';
    protected $description = 'Tạo WalletTransaction(pending) cho WithdrawRequest pending chưa có ledger (migration một lần)';

    private int $totalPending = 0;
    private int $willCreate = 0;
    private int $alreadyExists = 0;
    private int $noUser = 0;
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

        $this->info('============================================');
        $this->info('  Migrate Pending Withdraw Transactions');
        $this->info('============================================');

        $pendingRequests = WithdrawRequest::pending()
            ->orderBy('id')
            ->get();

        $this->totalPending = $pendingRequests->count();
        $this->line('');
        $this->line('Tổng số WithdrawRequest pending: ' . $this->totalPending);

        if ($this->totalPending === 0) {
            $this->line('');
            $this->info('Không có yêu cầu nào cần xử lý.');
            return Command::SUCCESS;
        }

        $this->line('');

        $bar = $this->output->createProgressBar($this->totalPending);
        $bar->start();

        foreach ($pendingRequests as $request) {
            if ($isDryRun) {
                $this->processDryRun($request);
            } else {
                $this->processRequest($request);
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

    private function processRequest(WithdrawRequest $request): void
    {
        if ($request->user_id === null) {
            $this->noUser++;
            return;
        }

        try {
            $exists = WalletTransaction::where('reference_type', 'withdraw_request')
                ->where('reference_id', $request->id)
                ->where('type', WalletTransaction::TYPE_WITHDRAW)
                ->exists();

            if ($exists) {
                $this->alreadyExists++;
                return;
            }

            $user = User::find($request->user_id);
            $balance = (float) ($user?->wallet_balance ?? 0);

            WalletTransaction::create([
                'running_no' => $this->walletService->generateRunningNo(),
                'user_id' => $request->user_id,
                'username' => $request->username,
                'platform' => null,
                'type' => WalletTransaction::TYPE_WITHDRAW,
                'direction' => WalletTransaction::DIRECTION_DEBIT,
                'amount' => (float) $request->amount,
                'balance_before' => $balance,
                'balance_after' => $balance,
                'reference_type' => 'withdraw_request',
                'reference_id' => $request->id,
                'description' => 'Rút tiền ' . ($request->bank_name ?? ''),
                'status' => WalletTransaction::STATUS_PENDING,
                'completed_at' => null,
                'processed_by' => null,
                'metadata' => [
                    'withdraw_running_no' => $request->running_no,
                    'bank' => $request->bank_name,
                    'account_number' => $request->bank_account,
                    'account_name' => $request->account_name,
                ],
            ]);

            $this->willCreate++;
        } catch (\Throwable $e) {
            $this->errorCount++;
            $this->line('');
            $this->error(sprintf(
                'Lỗi: WithdrawRequest #%d (%s) — %s',
                $request->id,
                $request->running_no,
                $e->getMessage(),
            ));
        }
    }

    private function processDryRun(WithdrawRequest $request): void
    {
        if ($request->user_id === null) {
            $this->noUser++;
            return;
        }

        $exists = WalletTransaction::where('reference_type', 'withdraw_request')
            ->where('reference_id', $request->id)
            ->where('type', WalletTransaction::TYPE_WITHDRAW)
            ->exists();

        if ($exists) {
            $this->alreadyExists++;
        } else {
            $this->willCreate++;
        }
    }

    private function outputSummary(bool $isDryRun): void
    {
        $this->info('============================================');
        $this->info('  Migrate Pending Withdraw Transactions');
        $this->info('============================================');
        $this->line('  Tổng số WithdrawRequest pending: ' . $this->totalPending);
        $this->line('  WalletTransaction sẽ tạo:        ' . $this->willCreate);
        $this->line('  WalletTransaction đã tồn tại:     ' . $this->alreadyExists);
        $this->line('  Không có user:                   ' . $this->noUser);
        $this->line('  Lỗi:                             ' . $this->errorCount);

        if ($isDryRun) {
            $this->info('============================================');
            $this->info('  DRY RUN');
            $this->info('============================================');
            $this->line('');
            $this->line('  Không có thay đổi nào được ghi vào Database.');
        } else {
            $this->info('============================================');
            $this->info('  SUCCESS');
            $this->info('============================================');
        }
    }
}
