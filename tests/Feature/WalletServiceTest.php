<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateCashbackException;
use App\Exceptions\DuplicateWithdrawException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\InvalidWithdrawException;
use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WithdrawRequest;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $service;
    private User $user;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WalletService::class);

        $this->user = User::factory()->create([
            'wallet_balance' => 0,
            'username' => 'testuser',
        ]);

        $this->admin = User::factory()->create([
            'wallet_balance' => 0,
            'username' => 'admin',
        ]);
    }

    public function test_getBalance_returns_current_wallet_balance(): void
    {
        $this->user->wallet_balance = 50000;
        $this->user->save();

        $balance = $this->service->getBalance($this->user);

        $this->assertSame(50000.0, $balance);
    }

    public function test_credit_cashback_creates_transaction_and_updates_balance(): void
    {
        $item = AffiliateOrderItem::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'platform' => 'Shopee',
            'cashback_amount' => 18500,
            'order_id' => 'ORD001',
            'affiliate_status' => 'Hoàn thành',
        ]);

        $transaction = $this->service->creditCashback($item);

        $this->assertInstanceOf(WalletTransaction::class, $transaction);
        $this->assertSame('cashback', $transaction->type);
        $this->assertSame('credit', $transaction->direction);
        $this->assertSame(18500.0, (float) $transaction->amount);
        $this->assertSame(0.0, (float) $transaction->balance_before);
        $this->assertSame(18500.0, (float) $transaction->balance_after);
        $this->assertSame('completed', $transaction->status);
        $this->assertSame($this->user->id, $transaction->user_id);
        $this->assertSame('affiliate_order_item', $transaction->reference_type);
        $this->assertSame($item->id, $transaction->reference_id);
        $this->assertStringContainsString('ORD001', $transaction->description);

        $this->assertDatabaseHas('wallet_transactions', [
            'id' => $transaction->id,
        ]);

        $this->user->refresh();
        $this->assertSame(18500.0, (float) $this->user->wallet_balance);
    }

    public function test_credit_cashback_with_throwOnDuplicate_false_returns_null_on_duplicate(): void
    {
        $item = AffiliateOrderItem::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'platform' => 'Shopee',
            'cashback_amount' => 18500,
        ]);

        $this->service->creditCashback($item);

        $result = $this->service->creditCashback($item, throwOnDuplicate: false);

        $this->assertNull($result);
    }

    public function test_credit_cashback_throws_on_duplicate(): void
    {
        $item = AffiliateOrderItem::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'platform' => 'Shopee',
            'cashback_amount' => 18500,
        ]);

        $this->service->creditCashback($item);

        $this->expectException(DuplicateCashbackException::class);

        $this->service->creditCashback($item);
    }

    public function test_debit_withdraw_creates_transaction_and_updates_balance(): void
    {
        $this->user->wallet_balance = 100000;
        $this->user->save();

        $withdrawRequest = WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 50000,
            'bank_name' => 'BIDV',
            'bank_account' => '1234567890',
            'account_name' => 'TEST USER',
            'status' => 'pending',
        ]);

        $transaction = $this->service->debitWithdraw($withdrawRequest, $this->admin);

        $this->assertInstanceOf(WalletTransaction::class, $transaction);
        $this->assertSame('withdraw', $transaction->type);
        $this->assertSame('debit', $transaction->direction);
        $this->assertSame(50000.0, (float) $transaction->amount);
        $this->assertSame(100000.0, (float) $transaction->balance_before);
        $this->assertSame(50000.0, (float) $transaction->balance_after);
        $this->assertSame('completed', $transaction->status);
        $this->assertSame($this->admin->id, $transaction->processed_by);
        $this->assertSame('withdraw_request', $transaction->reference_type);
        $this->assertSame($withdrawRequest->id, $transaction->reference_id);

        $this->user->refresh();
        $this->assertSame(50000.0, (float) $this->user->wallet_balance);

        $withdrawRequest->refresh();
        $this->assertSame('paid', $withdrawRequest->status);
        $this->assertSame($this->admin->id, $withdrawRequest->processed_by_user_id);
        $this->assertNotNull($withdrawRequest->processed_at);
    }

    public function test_debit_withdraw_throws_on_insufficient_balance(): void
    {
        $this->user->wallet_balance = 10000;
        $this->user->save();

        $withdrawRequest = WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 50000,
            'bank_name' => 'BIDV',
            'bank_account' => '1234567890',
            'account_name' => 'TEST USER',
            'status' => 'pending',
        ]);

        $this->expectException(InsufficientBalanceException::class);

        $this->service->debitWithdraw($withdrawRequest, $this->admin);
    }

    public function test_debit_withdraw_throws_when_not_pending(): void
    {
        $this->user->wallet_balance = 100000;
        $this->user->save();

        $withdrawRequest = WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 50000,
            'bank_name' => 'BIDV',
            'bank_account' => '1234567890',
            'account_name' => 'TEST USER',
            'status' => 'paid',
        ]);

        $this->expectException(InvalidWithdrawException::class);

        $this->service->debitWithdraw($withdrawRequest, $this->admin);
    }

    public function test_debit_withdraw_throws_on_duplicate(): void
    {
        $this->user->wallet_balance = 100000;
        $this->user->save();

        $withdrawRequest = WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 50000,
            'bank_name' => 'BIDV',
            'bank_account' => '1234567890',
            'account_name' => 'TEST USER',
            'status' => 'pending',
        ]);

        // Create a ledger entry that already exists (simulates duplicate processing)
        WalletTransaction::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'type' => 'withdraw',
            'direction' => 'debit',
            'amount' => 50000,
            'reference_type' => 'withdraw_request',
            'reference_id' => $withdrawRequest->id,
            'status' => 'completed',
        ]);

        $this->expectException(DuplicateWithdrawException::class);

        $this->service->debitWithdraw($withdrawRequest, $this->admin);
    }

    public function test_adjust_credit_increases_balance(): void
    {
        $this->user->wallet_balance = 100000;
        $this->user->save();

        $transaction = $this->service->adjust(
            $this->user,
            50000,
            'credit',
            'Thưởng KPI tháng 7',
            $this->admin,
        );

        $this->assertSame('adjustment', $transaction->type);
        $this->assertSame('credit', $transaction->direction);
        $this->assertSame(50000.0, (float) $transaction->amount);
        $this->assertSame(100000.0, (float) $transaction->balance_before);
        $this->assertSame(150000.0, (float) $transaction->balance_after);

        $this->user->refresh();
        $this->assertSame(150000.0, (float) $this->user->wallet_balance);
    }

    public function test_adjust_debit_decreases_balance(): void
    {
        $this->user->wallet_balance = 100000;
        $this->user->save();

        $transaction = $this->service->adjust(
            $this->user,
            30000,
            'debit',
            'Trừ tiền do sai sót',
            $this->admin,
        );

        $this->assertSame('adjustment', $transaction->type);
        $this->assertSame('debit', $transaction->direction);
        $this->assertSame(30000.0, (float) $transaction->amount);
        $this->assertSame(100000.0, (float) $transaction->balance_before);
        $this->assertSame(70000.0, (float) $transaction->balance_after);

        $this->user->refresh();
        $this->assertSame(70000.0, (float) $this->user->wallet_balance);
    }

    public function test_adjust_debit_throws_on_insufficient_balance(): void
    {
        $this->user->wallet_balance = 10000;
        $this->user->save();

        $this->expectException(InsufficientBalanceException::class);

        $this->service->adjust(
            $this->user,
            50000,
            'debit',
            'Trừ tiền',
            $this->admin,
        );
    }

    public function test_getAvailableBalance_excludes_pending_withdraw(): void
    {
        $this->user->wallet_balance = 100000;
        $this->user->save();

        WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 30000,
            'status' => 'pending',
        ]);

        WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 20000,
            'status' => 'paid',
        ]);

        $available = $this->service->getAvailableBalance($this->user);

        $this->assertSame(70000.0, $available);
    }

    public function test_syncBalance_recalculates_from_ledger(): void
    {
        $this->user->wallet_balance = 99999;
        $this->user->save();

        WalletTransaction::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'type' => 'cashback',
            'direction' => 'credit',
            'amount' => 50000,
            'balance_before' => 0,
            'balance_after' => 50000,
            'status' => 'completed',
        ]);

        WalletTransaction::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'type' => 'withdraw',
            'direction' => 'debit',
            'amount' => 10000,
            'balance_before' => 50000,
            'balance_after' => 40000,
            'status' => 'completed',
        ]);

        $synced = $this->service->syncBalance($this->user);

        $this->assertSame(40000.0, $synced);

        $this->user->refresh();
        $this->assertSame(40000.0, (float) $this->user->wallet_balance);
    }

    public function test_isCashbackCredited_returns_true_when_already_credited(): void
    {
        $item = AffiliateOrderItem::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'platform' => 'Shopee',
            'cashback_amount' => 18500,
        ]);

        WalletTransaction::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'type' => 'cashback',
            'direction' => 'credit',
            'amount' => 18500,
            'reference_type' => 'affiliate_order_item',
            'reference_id' => $item->id,
            'status' => 'completed',
        ]);

        $this->assertTrue($this->service->isCashbackCredited($item));
    }

    public function test_isCashbackCredited_returns_false_when_not_credited(): void
    {
        $item = AffiliateOrderItem::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'platform' => 'Shopee',
            'cashback_amount' => 18500,
        ]);

        $this->assertFalse($this->service->isCashbackCredited($item));
    }

    public function test_credit_cashback_updates_total_earned(): void
    {
        $this->user->total_earned = 0;
        $this->user->save();

        $item = AffiliateOrderItem::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'platform' => 'Shopee',
            'cashback_amount' => 18500,
            'order_id' => 'ORD001',
        ]);

        $this->service->creditCashback($item);

        $this->user->refresh();
        $this->assertSame(18500.0, (float) $this->user->total_earned);
    }

    public function test_generateRunningNo_creates_unique_numbers(): void
    {
        $this->user->wallet_balance = 100000;
        $this->user->save();

        $item1 = AffiliateOrderItem::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'platform' => 'Shopee',
            'cashback_amount' => 18500,
            'order_id' => 'ORD001',
        ]);
        $item2 = AffiliateOrderItem::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'platform' => 'Shopee',
            'cashback_amount' => 22000,
            'order_id' => 'ORD002',
        ]);

        $tx1 = $this->service->creditCashback($item1);
        $tx2 = $this->service->creditCashback($item2);

        $this->assertNotSame($tx1->running_no, $tx2->running_no);
        $this->assertStringStartsWith('WT' . now()->format('Ymd'), $tx1->running_no);
        $this->assertStringStartsWith('WT' . now()->format('Ymd'), $tx2->running_no);
    }

    public function test_credit_cashback_uses_description_with_order_id(): void
    {
        $item = AffiliateOrderItem::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'platform' => 'Shopee',
            'cashback_amount' => 10000,
            'order_id' => 'ORD-TEST-001',
        ]);

        $transaction = $this->service->creditCashback($item);

        $this->assertStringContainsString('ORD-TEST-001', $transaction->description);
    }

    public function test_adjust_without_admin_sets_processed_by_null(): void
    {
        $this->user->wallet_balance = 50000;
        $this->user->save();

        $transaction = $this->service->adjust(
            $this->user,
            10000,
            'credit',
            'Bonus',
        );

        $this->assertNull($transaction->processed_by);
    }

    public function test_adjust_platform_is_null(): void
    {
        $this->user->wallet_balance = 50000;
        $this->user->save();

        $transaction = $this->service->adjust(
            $this->user,
            10000,
            'credit',
            'Bonus',
        );

        $this->assertNull($transaction->platform);
    }

    public function test_isWithdrawCredited_returns_true_when_already_paid(): void
    {
        $this->user->wallet_balance = 100000;
        $this->user->save();

        $withdrawRequest = WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 50000,
            'bank_name' => 'BIDV',
            'bank_account' => '1234567890',
            'account_name' => 'TEST USER',
            'status' => 'paid',
        ]);

        WalletTransaction::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'type' => 'withdraw',
            'direction' => 'debit',
            'amount' => 50000,
            'reference_type' => 'withdraw_request',
            'reference_id' => $withdrawRequest->id,
            'status' => 'completed',
        ]);

        $this->assertTrue($this->service->isWithdrawCredited($withdrawRequest));
    }

    public function test_isWithdrawCredited_returns_false_when_not_paid(): void
    {
        $withdrawRequest = WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 50000,
            'bank_name' => 'BIDV',
            'bank_account' => '1234567890',
            'account_name' => 'TEST USER',
            'status' => 'pending',
        ]);

        $this->assertFalse($this->service->isWithdrawCredited($withdrawRequest));
    }

    public function test_complete_withdraw_creates_transaction_and_updates_balance(): void
    {
        $this->user->wallet_balance = 100000;
        $this->user->total_withdrawn = 0;
        $this->user->save();

        $withdrawRequest = WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 50000,
            'bank_name' => 'BIDV',
            'bank_account' => '1234567890',
            'account_name' => 'TEST USER',
            'status' => 'pending',
        ]);

        $transaction = $this->service->completeWithdraw($withdrawRequest, $this->admin);

        $this->assertInstanceOf(WalletTransaction::class, $transaction);
        $this->assertSame('withdraw', $transaction->type);
        $this->assertSame('debit', $transaction->direction);
        $this->assertSame(50000.0, (float) $transaction->amount);
        $this->assertSame(100000.0, (float) $transaction->balance_before);
        $this->assertSame(50000.0, (float) $transaction->balance_after);
        $this->assertSame('completed', $transaction->status);
        $this->assertSame($this->admin->id, $transaction->processed_by);
        $this->assertSame('withdraw_request', $transaction->reference_type);
        $this->assertSame($withdrawRequest->id, $transaction->reference_id);
        $this->assertSame(
            $withdrawRequest->running_no,
            $transaction->metadata['withdraw_running_no']
        );

        $this->user->refresh();
        $this->assertSame(50000.0, (float) $this->user->wallet_balance);
        $this->assertSame(50000.0, (float) $this->user->total_withdrawn);

        $withdrawRequest->refresh();
        $this->assertSame('paid', $withdrawRequest->status);
        $this->assertSame($this->admin->id, $withdrawRequest->processed_by_user_id);
        $this->assertNotNull($withdrawRequest->processed_at);
    }

    public function test_complete_withdraw_throws_when_not_pending(): void
    {
        $this->user->wallet_balance = 100000;
        $this->user->save();

        $withdrawRequest = WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 50000,
            'bank_name' => 'BIDV',
            'bank_account' => '1234567890',
            'account_name' => 'TEST USER',
            'status' => 'paid',
        ]);

        $this->expectException(InvalidWithdrawException::class);

        $this->service->completeWithdraw($withdrawRequest, $this->admin);
    }

    public function test_complete_withdraw_throws_on_insufficient_balance(): void
    {
        $this->user->wallet_balance = 10000;
        $this->user->save();

        $withdrawRequest = WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 50000,
            'bank_name' => 'BIDV',
            'bank_account' => '1234567890',
            'account_name' => 'TEST USER',
            'status' => 'pending',
        ]);

        $this->expectException(InsufficientBalanceException::class);

        $this->service->completeWithdraw($withdrawRequest, $this->admin);
    }

    public function test_reject_withdraw_updates_status_and_does_not_create_transaction(): void
    {
        $this->user->wallet_balance = 100000;
        $this->user->total_withdrawn = 0;
        $this->user->save();

        $withdrawRequest = WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 50000,
            'bank_name' => 'BIDV',
            'bank_account' => '1234567890',
            'account_name' => 'TEST USER',
            'status' => 'pending',
        ]);

        $result = $this->service->rejectWithdraw($withdrawRequest, $this->admin, 'Sai thông tin tài khoản');

        $this->assertSame('rejected', $result->status);
        $this->assertSame('Sai thông tin tài khoản', $result->note);
        $this->assertSame($this->admin->id, $result->processed_by_user_id);
        $this->assertNotNull($result->processed_at);

        $this->user->refresh();
        $this->assertSame(100000.0, (float) $this->user->wallet_balance);
        $this->assertSame(0.0, (float) $this->user->total_withdrawn);

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_reject_withdraw_throws_when_not_pending(): void
    {
        $withdrawRequest = WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 50000,
            'bank_name' => 'BIDV',
            'bank_account' => '1234567890',
            'account_name' => 'TEST USER',
            'status' => 'paid',
        ]);

        $this->expectException(InvalidWithdrawException::class);

        $this->service->rejectWithdraw($withdrawRequest, $this->admin);
    }
}
