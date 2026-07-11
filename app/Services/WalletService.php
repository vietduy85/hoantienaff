<?php

namespace App\Services;

use App\Exceptions\DuplicateCashbackException;
use App\Exceptions\DuplicateWithdrawException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\InvalidWithdrawException;
use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WithdrawRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WalletService
{
    private const RUNNING_NO_PREFIX = 'WT';
    private const SEQ_PAD_LENGTH = 4;

    public function creditCashback(AffiliateOrderItem $item, bool $throwOnDuplicate = true): ?WalletTransaction
    {
        if ($this->isCashbackCredited($item)) {
            if ($throwOnDuplicate) {
                throw new DuplicateCashbackException($item->id);
            }

            Log::warning('Duplicate cashback skipped', [
                'affiliate_order_item_id' => $item->id,
                'order_id' => $item->order_id,
                'user_id' => $item->user_id,
            ]);

            return null;
        }

        $user = $item->user;

        return DB::transaction(function () use ($item, $user) {
            $runningNo = $this->generateRunningNo();
            $balanceBefore = $this->getBalance($user);
            $amount = (float) $item->cashback_amount;
            $balanceAfter = $balanceBefore + $amount;

            $transaction = WalletTransaction::create([
                'running_no' => $runningNo,
                'user_id' => $user->id,
                'username' => $user->username,
                'platform' => $item->platform,
                'type' => WalletTransaction::TYPE_CASHBACK,
                'direction' => WalletTransaction::DIRECTION_CREDIT,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_type' => 'affiliate_order_item',
                'reference_id' => $item->id,
                'description' => 'Cashback đơn hàng ' . $item->order_id,
                'status' => WalletTransaction::STATUS_COMPLETED,
                'completed_at' => now(),
                'processed_by' => null,
                'metadata' => [
                    'order_id' => $item->order_id,
                ],
            ]);

            $user->wallet_balance = $balanceAfter;
            $user->total_earned = (float) $user->total_earned + $amount;
            $user->save();

            return $transaction;
        });
    }

    public function debitWithdraw(WithdrawRequest $request, User $admin): WalletTransaction
    {
        if (! $request->isPending()) {
            throw new InvalidWithdrawException(
                sprintf(
                    'Yêu cầu rút tiền #%d không ở trạng thái pending (hiện tại: %s).',
                    $request->id,
                    $request->status,
                )
            );
        }

        if ($this->isWithdrawCredited($request)) {
            throw new DuplicateWithdrawException($request->id);
        }

        $user = $request->user;

        $balance = $this->getBalance($user);
        $amount = (float) $request->amount;

        if ($balance < $amount) {
            throw new InsufficientBalanceException($balance, $amount);
        }

        return DB::transaction(function () use ($request, $user, $admin, $balance, $amount) {
            $runningNo = $this->generateRunningNo();
            $balanceAfter = $balance - $amount;

            $transaction = WalletTransaction::create([
                'running_no' => $runningNo,
                'user_id' => $user->id,
                'username' => $user->username,
                'platform' => null,
                'type' => WalletTransaction::TYPE_WITHDRAW,
                'direction' => WalletTransaction::DIRECTION_DEBIT,
                'amount' => $amount,
                'balance_before' => $balance,
                'balance_after' => $balanceAfter,
                'reference_type' => 'withdraw_request',
                'reference_id' => $request->id,
                'description' => 'Rút tiền ' . $request->bank_name,
                'status' => WalletTransaction::STATUS_COMPLETED,
                'completed_at' => now(),
                'processed_by' => $admin->id,
                'metadata' => [
                    'bank' => $request->bank_name,
                    'account_number' => $request->bank_account,
                    'account_name' => $request->account_name,
                ],
            ]);

            $user->wallet_balance = $balanceAfter;
            $user->save();

            $request->update([
                'status' => WithdrawRequest::STATUS_PAID,
                'processed_by_user_id' => $admin->id,
                'processed_at' => now(),
            ]);

            return $transaction;
        });
    }

    public function completeWithdraw(WithdrawRequest $request, User $admin): WalletTransaction
    {
        return DB::transaction(function () use ($request, $admin) {
            $request = WithdrawRequest::where('id', $request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $request->isPending()) {
                throw new InvalidWithdrawException(
                    sprintf(
                        'Yêu cầu rút tiền #%d không ở trạng thái pending (hiện tại: %s).',
                        $request->id,
                        $request->status,
                    )
                );
            }

            $transaction = WalletTransaction::where('reference_type', 'withdraw_request')
                ->where('reference_id', $request->id)
                ->where('type', WalletTransaction::TYPE_WITHDRAW)
                ->where('status', WalletTransaction::STATUS_PENDING)
                ->lockForUpdate()
                ->firstOrFail();

            $user = User::where('id', $request->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $balance = (float) $user->wallet_balance;
            $amount = (float) $request->amount;

            if ($balance < $amount) {
                throw new InsufficientBalanceException($balance, $amount);
            }

            $balanceAfter = $balance - $amount;

            $transaction->update([
                'status' => WalletTransaction::STATUS_COMPLETED,
                'balance_before' => $balance,
                'balance_after' => $balanceAfter,
                'completed_at' => now(),
                'processed_by' => $admin->id,
            ]);

            $user->wallet_balance = $balanceAfter;
            $user->total_withdrawn = (float) $user->total_withdrawn + $amount;
            $user->save();

            $request->update([
                'status' => WithdrawRequest::STATUS_PAID,
                'processed_by_user_id' => $admin->id,
                'processed_at' => now(),
            ]);

            return $transaction->fresh();
        });
    }

    public function rejectWithdraw(WithdrawRequest $request, User $admin, ?string $note = null): WithdrawRequest
    {
        if (! $request->isPending()) {
            throw new InvalidWithdrawException(
                sprintf(
                    'Yêu cầu rút tiền #%d không ở trạng thái pending (hiện tại: %s).',
                    $request->id,
                    $request->status,
                )
            );
        }

        $transaction = WalletTransaction::where('reference_type', 'withdraw_request')
            ->where('reference_id', $request->id)
            ->where('type', WalletTransaction::TYPE_WITHDRAW)
            ->where('status', WalletTransaction::STATUS_PENDING)
            ->firstOrFail();

        $transaction->update([
            'status' => WalletTransaction::STATUS_CANCELLED,
            'completed_at' => now(),
            'processed_by' => $admin->id,
            'metadata' => array_merge($transaction->metadata ?? [], [
                'reject_reason' => $note,
            ]),
        ]);

        $request->update([
            'status' => WithdrawRequest::STATUS_REJECTED,
            'processed_by_user_id' => $admin->id,
            'processed_at' => now(),
            'note' => $note,
        ]);

        return $request->fresh();
    }

    public function adjust(
        User $user,
        float $amount,
        string $direction,
        string $reason,
        ?User $admin = null,
        ?array $metadata = null,
    ): WalletTransaction {
        if ($direction === WalletTransaction::DIRECTION_DEBIT) {
            $balance = $this->getBalance($user);
            if ($balance < $amount) {
                throw new InsufficientBalanceException($balance, $amount);
            }
        }

        return DB::transaction(function () use ($user, $amount, $direction, $reason, $admin, $metadata) {
            $runningNo = $this->generateRunningNo();
            $balanceBefore = $this->getBalance($user);
            $balanceAfter = $direction === WalletTransaction::DIRECTION_CREDIT
                ? $balanceBefore + $amount
                : $balanceBefore - $amount;

            $transaction = WalletTransaction::create([
                'running_no' => $runningNo,
                'user_id' => $user->id,
                'username' => $user->username,
                'platform' => null,
                'type' => WalletTransaction::TYPE_ADJUSTMENT,
                'direction' => $direction,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_type' => 'manual',
                'reference_id' => null,
                'description' => $reason,
                'status' => WalletTransaction::STATUS_COMPLETED,
                'completed_at' => now(),
                'processed_by' => $admin?->id,
                'metadata' => $metadata ?? ['reason' => $reason],
            ]);

            $user->wallet_balance = $balanceAfter;
            $user->save();

            return $transaction;
        });
    }

    public function getBalance(User $user): float
    {
        return (float) $user->wallet_balance;
    }

    public function getAvailableBalance(User $user): float
    {
        $balance = $this->getBalance($user);

        $pendingWithdrawTotal = (float) WithdrawRequest::where('user_id', $user->id)
            ->where('status', WithdrawRequest::STATUS_PENDING)
            ->sum('amount');

        return max(0, $balance - $pendingWithdrawTotal);
    }

    public function createWithdrawRequest(User $user, float $amount): WithdrawRequest
    {
        return DB::transaction(function () use ($user, $amount) {
            if (!$user->bank_name || !$user->bank_account_number || !$user->bank_account_name) {
                throw ValidationException::withMessages([
                    'bank_info' => __('Bạn chưa cập nhật thông tin ngân hàng.'),
                ]);
            }

            if ($this->getAvailableBalance($user) < $amount) {
                throw ValidationException::withMessages([
                    'amount' => __('Số dư khả dụng không đủ.'),
                ]);
            }

            $existsPending = WithdrawRequest::where('user_id', $user->id)
                ->where('status', WithdrawRequest::STATUS_PENDING)
                ->lockForUpdate()
                ->exists();

            if ($existsPending) {
                throw ValidationException::withMessages([
                    'amount' => __('Bạn đang có một yêu cầu rút tiền chờ xử lý. Vui lòng đợi hệ thống xử lý hoặc hủy yêu cầu hiện tại trước khi tạo yêu cầu mới.'),
                ]);
            }

            $runningNo = $this->generateWithdrawRunningNo();
            $balance = $this->getBalance($user);

            $request = WithdrawRequest::create([
                'running_no' => $runningNo,
                'user_id' => $user->id,
                'username' => $user->username,
                'amount' => $amount,
                'bank_name' => $user->bank_name,
                'bank_account' => $user->bank_account_number,
                'account_name' => $user->bank_account_name,
                'status' => WithdrawRequest::STATUS_PENDING,
                'processed_by_user_id' => null,
                'processed_at' => null,
                'note' => null,
            ]);

            WalletTransaction::create([
                'running_no' => $this->generateRunningNo(),
                'user_id' => $user->id,
                'username' => $user->username,
                'platform' => null,
                'type' => WalletTransaction::TYPE_WITHDRAW,
                'direction' => WalletTransaction::DIRECTION_DEBIT,
                'amount' => $amount,
                'balance_before' => $balance,
                'balance_after' => $balance,
                'reference_type' => 'withdraw_request',
                'reference_id' => $request->id,
                'description' => 'Rút tiền ' . $user->bank_name,
                'status' => WalletTransaction::STATUS_PENDING,
                'completed_at' => null,
                'processed_by' => null,
                'metadata' => [
                    'withdraw_running_no' => $runningNo,
                    'bank' => $user->bank_name,
                    'account_number' => $user->bank_account_number,
                    'account_name' => $user->bank_account_name,
                ],
            ]);

            return $request;
        });
    }

    // TODO: Use RunningNumberService for centralized running number generation
    public function generateWithdrawRunningNo(): string
    {
        $today = now()->format('Ymd');
        $prefix = 'WR' . $today;

        $lastRunningNo = WithdrawRequest::where('running_no', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('running_no');

        if ($lastRunningNo) {
            $lastSeq = (int) substr($lastRunningNo, strlen($prefix));
            $newSeq = $lastSeq + 1;
        } else {
            $newSeq = 1;
        }

        return $prefix . str_pad((string) $newSeq, self::SEQ_PAD_LENGTH, '0', STR_PAD_LEFT);
    }

    public function isWithdrawCredited(WithdrawRequest $request): bool
    {
        return WalletTransaction::where('reference_type', 'withdraw_request')
            ->where('reference_id', $request->id)
            ->where('type', WalletTransaction::TYPE_WITHDRAW)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->exists();
    }

    public function syncBalance(User $user): float
    {
        $calculated = (float) WalletTransaction::where('user_id', $user->id)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END), 0) as balance"
            )
            ->value('balance');

        $user->wallet_balance = $calculated;
        $user->save();

        return $calculated;
    }

    public function isCashbackCredited(AffiliateOrderItem $item): bool
    {
        return WalletTransaction::where('reference_type', 'affiliate_order_item')
            ->where('reference_id', $item->id)
            ->where('type', WalletTransaction::TYPE_CASHBACK)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->exists();
    }

    public function generateRunningNo(): string
    {
        $today = now()->format('Ymd');
        $prefix = self::RUNNING_NO_PREFIX . $today;

        $lastRunningNo = WalletTransaction::where('running_no', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('running_no');

        if ($lastRunningNo) {
            $lastSeq = (int) substr($lastRunningNo, strlen($prefix));
            $newSeq = $lastSeq + 1;
        } else {
            $newSeq = 1;
        }

        return $prefix . str_pad((string) $newSeq, self::SEQ_PAD_LENGTH, '0', STR_PAD_LEFT);
    }
}
