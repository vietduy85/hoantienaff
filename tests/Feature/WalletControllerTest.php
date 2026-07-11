<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WithdrawRequest;
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
    }
}
