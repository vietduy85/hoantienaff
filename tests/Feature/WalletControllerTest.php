<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WithdrawRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'testuser',
            'wallet_balance' => 50000,
            'total_withdrawn' => 10000,
            'bank_name' => 'BIDV',
            'bank_account_name' => 'Test User',
            'bank_account_number' => '1234567890',
        ]);
    }

    public function test_index_displays_correct_balances(): void
    {
        AffiliateOrderItem::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'affiliate_status' => 'Đang chờ xử lý',
            'cashback_amount' => 20000,
        ]);

        AffiliateOrderItem::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'affiliate_status' => 'Hoàn thành',
            'cashback_amount' => 30000,
        ]);

        WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 5000,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)->get(route('wallet.index'));

        $response->assertOk();
        $response->assertViewHasAll([
            'available' => 45000.0,
            'pending' => 20000.0,
            'paid' => 10000.0,
        ]);
    }

    public function test_available_is_wallet_balance_minus_pending_withdraws(): void
    {
        WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 10000,
            'status' => 'pending',
        ]);

        WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 5000,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)->get(route('wallet.index'));

        $response->assertViewHas('available', 35000.0);
    }

    public function test_available_is_zero_when_negative(): void
    {
        $this->user->wallet_balance = 3000;
        $this->user->save();

        WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 10000,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)->get(route('wallet.index'));

        $response->assertViewHas('available', 0.0);
    }

    public function test_pending_is_from_affiliate_order_items(): void
    {
        AffiliateOrderItem::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'affiliate_status' => 'Đang chờ xử lý',
            'cashback_amount' => 15000,
        ]);

        AffiliateOrderItem::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'affiliate_status' => 'Đang chờ xử lý',
            'cashback_amount' => 25000,
        ]);

        AffiliateOrderItem::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'affiliate_status' => 'Hoàn thành',
            'cashback_amount' => 99999,
        ]);

        $response = $this->actingAs($this->user)->get(route('wallet.index'));

        $response->assertViewHas('pending', 40000.0);
    }

    public function test_paid_is_from_users_total_withdrawn(): void
    {
        $this->user->total_withdrawn = 25000;
        $this->user->save();

        $response = $this->actingAs($this->user)->get(route('wallet.index'));

        $response->assertViewHas('paid', 25000.0);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('wallet.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_index_renders_correct_view(): void
    {
        $response = $this->actingAs($this->user)->get(route('wallet.index'));

        $response->assertViewIs('wallet.index');
        $response->assertViewHas('transactions');
    }

    public function test_index_shows_transactions_from_wallet_transactions(): void
    {
        WalletTransaction::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'type' => 'cashback',
            'direction' => 'credit',
            'amount' => 15000,
            'running_no' => 'WT202607100001',
            'description' => 'Cashback đơn hàng ORD001',
            'status' => 'completed',
            'completed_at' => now(),
            'reference_type' => 'affiliate_order_item',
            'reference_id' => 1,
            'metadata' => ['order_id' => 'ORD001'],
        ]);

        WalletTransaction::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'type' => 'withdraw',
            'direction' => 'debit',
            'amount' => 10000,
            'running_no' => 'WT202607100002',
            'description' => 'Rút tiền BIDV',
            'status' => 'completed',
            'completed_at' => now(),
            'reference_type' => 'withdraw_request',
            'reference_id' => 1,
            'metadata' => ['withdraw_running_no' => 'WR202607100001'],
        ]);

        $response = $this->actingAs($this->user)->get(route('wallet.index'));

        $response->assertViewHas('transactions');
        $txs = $response->viewData('transactions');
        $this->assertCount(2, $txs);
        $this->assertSame('WT202607100002', $txs[0]->running_no);
    }

    public function test_index_transactions_empty_when_no_transactions(): void
    {
        $response = $this->actingAs($this->user)->get(route('wallet.index'));

        $txs = $response->viewData('transactions');
        $this->assertCount(0, $txs);
    }

    public function test_index_shows_pending_withdraw_transaction(): void
    {
        WalletTransaction::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'type' => 'withdraw',
            'direction' => 'debit',
            'amount' => 30000,
            'running_no' => 'WT202607100003',
            'description' => 'Rút tiền BIDV',
            'status' => 'pending',
            'completed_at' => null,
            'reference_type' => 'withdraw_request',
            'reference_id' => 1,
            'metadata' => ['withdraw_running_no' => 'WR202607100001'],
        ]);

        $response = $this->actingAs($this->user)->get(route('wallet.index'));

        $response->assertViewHas('transactions');
        $txs = $response->viewData('transactions');
        $this->assertCount(1, $txs);
        $this->assertSame('WT202607100003', $txs[0]->running_no);
        $this->assertNull($txs[0]->completed_at);
    }

    public function test_pending_withdraw_appears_in_top_20_even_with_30_old_cashbacks(): void
    {
        for ($i = 0; $i < 30; $i++) {
            WalletTransaction::factory()->create([
                'user_id' => $this->user->id,
                'username' => $this->user->username,
                'type' => 'cashback',
                'direction' => 'credit',
                'amount' => 10000,
                'status' => 'completed',
                'completed_at' => now()->subDays(30)->addMinutes($i),
                'created_at' => now()->subDays(30)->addMinutes($i),
            ]);
        }

        WalletTransaction::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'type' => 'withdraw',
            'direction' => 'debit',
            'amount' => 20000,
            'running_no' => 'WT202607110027',
            'description' => 'Rút tiền BIDV',
            'status' => 'pending',
            'completed_at' => null,
            'created_at' => now(),
            'reference_type' => 'withdraw_request',
            'reference_id' => 99,
            'metadata' => ['withdraw_running_no' => 'WR202607110099'],
        ]);

        $response = $this->actingAs($this->user)->get(route('wallet.index'));

        $response->assertViewHas('transactions');
        $txs = $response->viewData('transactions');
        $this->assertCount(20, $txs);
        $this->assertSame('WT202607110027', $txs[0]->running_no);
        $this->assertNull($txs[0]->completed_at);
    }
}
