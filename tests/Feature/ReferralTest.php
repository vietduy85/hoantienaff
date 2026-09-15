<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use App\Models\Referral;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ReferralService;
use App\Services\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use RefreshDatabase;

    private ReferralService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://hoantien.xyz']);

        $this->service = app(ReferralService::class);
    }

    /** TEST 1: Link giới thiệu dùng username */
    #[Test]
    public function referral_link_uses_username_on_canonical_domain(): void
    {
        $referrer = User::factory()->create(['username' => '1005']);

        $this->assertSame('https://hoantien.xyz/?ref=1005', $this->service->referralLink($referrer));
    }

    /** TEST 2: Đăng ký qua ?ref= tạo referral pending */
    #[Test]
    public function registration_with_valid_ref_creates_referral(): void
    {
        $referrer = User::factory()->create(['username' => '1005']);

        $this->withSession(['referral_ref' => '1005'])
            ->post('/register', [
                'username' => '1008',
                'name' => 'User B',
                'email' => 'b@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])->assertRedirect(route('dashboard', absolute: false));

        $referred = User::where('username', '1008')->firstOrFail();

        $this->assertDatabaseHas('referrals', [
            'referrer_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'completed_orders' => 0,
            'status' => Referral::STATUS_PENDING,
            'rewarded_at' => null,
        ]);

        $this->assertSame($referrer->id, $referred->refresh()->referred_by);
    }

    /** TEST 2b: Middleware CaptureReferral lưu ?ref= vào session */
    #[Test]
    public function middleware_captures_ref_from_query(): void
    {
        $this->get('/?ref=1005')->assertSessionHas('referral_ref', '1005');
    }

    /** TEST 3: Đơn hợp lệ thứ 1 → progress 1/3, chưa thưởng */
    #[Test]
    public function first_completed_order_moves_progress_to_one(): void
    {
        [$referrer, $referred] = $this->makeReferralPair();
        $this->createCreditedCompletedOrder($referred, 'O1');

        $this->service->processCompletedOrder($referred->id);

        $referral = $this->referralFor($referred);
        $this->assertSame(1, (int) $referral->completed_orders);
        $this->assertSame(Referral::STATUS_PENDING, $referral->status);
        $this->assertNull($referral->rewarded_at);
        $this->assertSame(0.0, (float) $referrer->fresh()->wallet_balance);
    }

    /** TEST 4: Đơn hợp lệ thứ 2 → progress 2/3, chưa thưởng */
    #[Test]
    public function second_completed_order_moves_progress_to_two(): void
    {
        [$referrer, $referred] = $this->makeReferralPair();
        $this->createCreditedCompletedOrder($referred, 'O1');
        $this->createCreditedCompletedOrder($referred, 'O2');

        $this->service->processCompletedOrder($referred->id);

        $referral = $this->referralFor($referred);
        $this->assertSame(2, (int) $referral->completed_orders);
        $this->assertSame(Referral::STATUS_PENDING, $referral->status);
        $this->assertNull($referral->rewarded_at);
        $this->assertSame(0.0, (float) $referrer->fresh()->wallet_balance);
    }

    /** TEST 5: Đơn hợp lệ thứ 3 → hoàn thành + thưởng 20.000đ vào ví */
    #[Test]
    public function third_completed_order_completes_and_rewards_referrer(): void
    {
        [$referrer, $referred] = $this->makeReferralPair();
        $this->createCreditedCompletedOrder($referred, 'O1');
        $this->createCreditedCompletedOrder($referred, 'O2');
        $this->createCreditedCompletedOrder($referred, 'O3');

        $this->service->processCompletedOrder($referred->id);

        $referral = $this->referralFor($referred);
        $this->assertSame(3, (int) $referral->completed_orders);
        $this->assertSame(Referral::STATUS_COMPLETED, $referral->status);
        $this->assertNotNull($referral->rewarded_at);
        $this->assertSame(20000.0, (float) $referrer->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $referrer->id,
            'type' => WalletTransaction::TYPE_REFERRAL,
            'direction' => WalletTransaction::DIRECTION_CREDIT,
            'reference_type' => 'referral',
            'reference_id' => $referral->id,
            'status' => WalletTransaction::STATUS_COMPLETED,
            'amount' => 20000.0,
        ]);
    }

    /** TEST 6: Xử lý lại đơn thứ 3 → không thưởng lần 2 */
    #[Test]
    public function reprocessing_third_order_does_not_double_reward(): void
    {
        [$referrer, $referred] = $this->makeReferralPair();
        $this->createCreditedCompletedOrder($referred, 'O1');
        $this->createCreditedCompletedOrder($referred, 'O2');
        $this->createCreditedCompletedOrder($referred, 'O3');

        $this->service->processCompletedOrder($referred->id);
        $this->service->processCompletedOrder($referred->id);

        $referral = $this->referralFor($referred);
        $this->assertSame(Referral::STATUS_COMPLETED, $referral->status);
        $this->assertSame(20000.0, (float) $referrer->fresh()->wallet_balance);
        $this->assertSame(1, $this->referralCreditsFor($referrer)->count());
    }

    /** TEST 7: Xử lý lặp khi còn pending → idempotent, không nhân bản */
    #[Test]
    public function repeated_processing_while_pending_is_idempotent(): void
    {
        [$referrer, $referred] = $this->makeReferralPair();
        $this->createCreditedCompletedOrder($referred, 'O1');
        $this->createCreditedCompletedOrder($referred, 'O2');

        $this->service->processCompletedOrder($referred->id);
        $this->service->processCompletedOrder($referred->id);

        $referral = $this->referralFor($referred);
        $this->assertSame(2, (int) $referral->completed_orders);
        $this->assertSame(Referral::STATUS_PENDING, $referral->status);
        $this->assertSame(0.0, (float) $referrer->fresh()->wallet_balance);
    }

    /** TEST 8: Tự giới thiệu bản thân → bỏ qua, không tạo referral */
    #[Test]
    public function self_referral_is_ignored(): void
    {
        $this->withSession(['referral_ref' => '1005'])
            ->post('/register', [
                'username' => '1005',
                'name' => 'User A',
                'email' => 'a@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $this->assertDatabaseCount('referrals', 0);
    }

    /** TEST 9: Không thể đổi referrer sau khi đã gắn */
    #[Test]
    public function referrer_cannot_be_changed(): void
    {
        [$referrer, $referred] = $this->makeReferralPair();
        $other = User::factory()->create(['username' => '1010']);

        $this->assertNull($this->service->attachReferrer($referred, $other->username));

        $this->assertDatabaseHas('referrals', [
            'referred_user_id' => $referred->id,
            'referrer_id' => $referrer->id,
        ]);
        $this->assertSame($referrer->id, $referred->fresh()->referred_by);
    }

    /** TEST 10: Ref không tồn tại → đăng ký bình thường, không tạo referral */
    #[Test]
    public function invalid_ref_username_is_ignored(): void
    {
        $this->withSession(['referral_ref' => 'khong-ton-tai']);
        $this->post('/register', [
            'username' => '1008',
            'name' => 'User B',
            'email' => 'b@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $this->assertDatabaseCount('referrals', 0);
    }

    /** TEST 11: Đơn đang xử lý (pending) không tính */
    #[Test]
    public function pending_orders_do_not_count(): void
    {
        [$referrer, $referred] = $this->makeReferralPair();
        $this->createCreditedCompletedOrder($referred, 'O1');
        $this->createCreditedCompletedOrder($referred, 'O2');
        $this->createOrder($referred, 'O3', AffiliateOrderItem::STATUS_PENDING);

        $this->service->processCompletedOrder($referred->id);

        $referral = $this->referralFor($referred);
        $this->assertSame(2, (int) $referral->completed_orders);
        $this->assertSame(Referral::STATUS_PENDING, $referral->status);
        $this->assertSame(0.0, (float) $referrer->fresh()->wallet_balance);
    }

    /** TEST 12: Đơn hủy / bị đảo (reversed/fraud) không tính */
    #[Test]
    public function cancelled_and_reversed_orders_do_not_count(): void
    {
        [$referrer, $referred] = $this->makeReferralPair();
        $this->createCreditedCompletedOrder($referred, 'O1');
        $this->createCreditedCompletedOrder($referred, 'O2');

        $cancelled = $this->createOrder($referred, 'O3', AffiliateOrderItem::STATUS_CANCELLED);
        $this->giveCashbackCredit($cancelled);

        $reversed = $this->createCreditedCompletedOrder($referred, 'O4');
        $reversed->update(['reversed_at' => now()]);

        $this->service->processCompletedOrder($referred->id);

        $referral = $this->referralFor($referred);
        $this->assertSame(2, (int) $referral->completed_orders);
        $this->assertSame(Referral::STATUS_PENDING, $referral->status);
        $this->assertSame(0.0, (float) $referrer->fresh()->wallet_balance);
    }

    /** TEST 13: Xử lý đồng thời 2 worker → chỉ thưởng đúng 1 lần */
    #[Test]
    public function concurrent_double_processing_rewards_once(): void
    {
        [$referrer, $referred] = $this->makeReferralPair();
        $this->createCreditedCompletedOrder($referred, 'O1');
        $this->createCreditedCompletedOrder($referred, 'O2');
        $this->createCreditedCompletedOrder($referred, 'O3');

        $referral = $this->referralFor($referred);
        (new WalletService())->creditReferral($referral);
        (new WalletService())->creditReferral($referral);

        $this->assertSame(1, $this->referralCreditsFor($referrer)->count());
        $this->assertSame(20000.0, (float) $referrer->fresh()->wallet_balance);

        // Unique index (reference_type, reference_id, type) là backstop chặn trùng.
        $credit = $this->referralCreditsFor($referrer)->first();
        $this->expectException(QueryException::class);
        WalletTransaction::create([
            'running_no' => 'DUP0001',
            'user_id' => $credit->user_id,
            'username' => $credit->username,
            'type' => $credit->type,
            'direction' => $credit->direction,
            'amount' => $credit->amount,
            'balance_before' => $credit->balance_before,
            'balance_after' => $credit->balance_after,
            'reference_type' => $credit->reference_type,
            'reference_id' => $credit->reference_id,
            'description' => $credit->description,
            'status' => $credit->status,
        ]);
    }

    /** TEST 14: Wallet credit idempotent theo reference_type/reference_id */
    #[Test]
    public function wallet_referral_credit_is_idempotent_by_reference(): void
    {
        [$referrer, $referred] = $this->makeReferralPair();
        $this->createCreditedCompletedOrder($referred, 'O1');
        $this->createCreditedCompletedOrder($referred, 'O2');
        $this->createCreditedCompletedOrder($referred, 'O3');

        $referral = $this->referralFor($referred);
        $wallet = new WalletService();

        $first = $wallet->creditReferral($referral);
        $second = $wallet->creditReferral($referral);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('referral', (string) $first->reference_type);
        $this->assertSame($referral->id, $first->reference_id);
        $this->assertSame(WalletTransaction::TYPE_REFERRAL, $first->type);
        $this->assertSame(1, $this->referralCreditsFor($referrer)->count());
        $this->assertSame(20000.0, (float) $referrer->fresh()->wallet_balance);
    }

    /** TEST 15: Cashback credit vẫn hoạt động bình thường và vẫn kích hoạt referral */
    #[Test]
    public function cashback_credit_triggers_referral_progress_and_keeps_working(): void
    {
        [$referrer, $referred] = $this->makeReferralPair();

        $item = $this->createOrder($referred, 'O1', AffiliateOrderItem::STATUS_COMPLETED, 12000);

        (new WalletService())->creditCashback($item);

        $referral = $this->referralFor($referred);
        $this->assertSame(1, (int) $referral->completed_orders);
        $this->assertSame(Referral::STATUS_PENDING, $referral->status);
        $this->assertSame(0.0, (float) $referrer->fresh()->wallet_balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'reference_type' => 'affiliate_order_item',
            'reference_id' => $item->id,
            'type' => WalletTransaction::TYPE_CASHBACK,
            'status' => WalletTransaction::STATUS_COMPLETED,
        ]);
    }

    /** TEST 17: Một order có nhiều items → chỉ tính 1 order (DISTINCT order_id) */
    #[Test]
    public function multiple_items_same_order_count_as_one_order(): void
    {
        [$referrer, $referred] = $this->makeReferralPair();

        $item1 = $this->createOrder($referred, 'O1', AffiliateOrderItem::STATUS_COMPLETED);
        $this->giveCashbackCredit($item1);

        $item2 = $this->createOrder($referred, 'O1', AffiliateOrderItem::STATUS_COMPLETED);
        $this->giveCashbackCredit($item2);

        $this->service->processCompletedOrder($referred->id);

        $referral = $this->referralFor($referred);
        $this->assertSame(1, (int) $referral->completed_orders);
        $this->assertSame(Referral::STATUS_PENDING, $referral->status);
        $this->assertNull($referral->rewarded_at);
        $this->assertSame(0.0, (float) $referrer->fresh()->wallet_balance);
    }

    /** TEST 18: Đơn thứ 4 → vẫn chỉ 1 reward, không cộng thêm */
    #[Test]
    public function fourth_order_does_not_add_second_reward(): void
    {
        [$referrer, $referred] = $this->makeReferralPair();
        $this->createCreditedCompletedOrder($referred, 'O1');
        $this->createCreditedCompletedOrder($referred, 'O2');
        $this->createCreditedCompletedOrder($referred, 'O3');
        $this->createCreditedCompletedOrder($referred, 'O4');

        $this->service->processCompletedOrder($referred->id);

        $referral = $this->referralFor($referred);
        $this->assertSame(4, (int) $referral->completed_orders);
        $this->assertSame(Referral::STATUS_COMPLETED, $referral->status);
        $this->assertNotNull($referral->rewarded_at);
        $this->assertSame(20000.0, (float) $referrer->fresh()->wallet_balance);
        $this->assertSame(1, $this->referralCreditsFor($referrer)->count());
    }

    /** TEST 19: creditReferral() khi reward_amount = 0 → null, không tạo wallet tx */
    #[Test]
    public function credit_referral_with_zero_reward_returns_null(): void
    {
        [$referrer, $referred] = $this->makeReferralPair();
        $referral = $this->referralFor($referred);
        $referral->update(['reward_amount' => 0]);

        $result = (new WalletService())->creditReferral($referral);
        $this->assertNull($result);
        $this->assertSame(0.0, (float) $referrer->fresh()->wallet_balance);
        $this->assertSame(0, $this->referralCreditsFor($referrer)->count());
    }

    /** TEST 20: Hoàn thành đúng 3 đơn qua creditCashback() real flow → reward đủ 20K */
    #[Test]
    public function real_cashback_flow_triggers_referral_reward_at_three(): void
    {
        [$referrer, $referred] = $this->makeReferralPair();
        $ws = new WalletService();

        $i1 = $this->createOrder($referred, 'O1', AffiliateOrderItem::STATUS_COMPLETED, 10000);
        $ws->creditCashback($i1);
        $this->assertSame(0.0, (float) $referrer->fresh()->wallet_balance);

        $i2 = $this->createOrder($referred, 'O2', AffiliateOrderItem::STATUS_COMPLETED, 12000);
        $ws->creditCashback($i2);
        $this->assertSame(0.0, (float) $referrer->fresh()->wallet_balance);

        $i3 = $this->createOrder($referred, 'O3', AffiliateOrderItem::STATUS_COMPLETED, 15000);
        $ws->creditCashback($i3);

        $referral = $this->referralFor($referred);
        $this->assertSame(3, (int) $referral->completed_orders);
        $this->assertSame(Referral::STATUS_COMPLETED, $referral->status);
        $this->assertSame(20000.0, (float) $referrer->fresh()->wallet_balance);
        $this->assertSame(1, $this->referralCreditsFor($referrer)->count());
    }

    /** UI: trang quản lý giới thiệu hiển thị link, thống kê, danh sách */
    #[Test]
    public function referral_page_renders_link_stats_and_list(): void
    {
        [$referrer, $referred] = $this->makeReferralPair();

        $this->actingAs($referrer)
            ->get(route('referrals.index'))
            ->assertOk()
            ->assertSee('Quản lý giới thiệu')
            ->assertSee('https://hoantien.xyz/?ref=1005')
            ->assertSee('Đã mời')
            ->assertSee('Đã nhận')
            ->assertSee($referred->username)
            ->assertSee('0/3 đơn')
            ->assertSee('Chưa có đơn');
    }

    /** UI: empty state khi chưa mời ai */
    #[Test]
    public function referral_page_renders_empty_state(): void
    {
        $user = User::factory()->create(['username' => '1005']);

        $this->actingAs($user)
            ->get(route('referrals.index'))
            ->assertOk()
            ->assertSee('Danh sách người được giới thiệu')
            ->assertSee('Bạn chưa mời được ai. Hãy chia sẻ link giới thiệu của bạn để nhận thưởng!');
    }

    private function makeReferralPair(): array
    {
        $referrer = User::factory()->create(['username' => '1005']);

        return [$referrer, $this->referred()];
    }

    private function referred(): User
    {
        $referred = User::factory()->create(['username' => '1008']);
        $this->service->createReferral(User::where('username', '1005')->firstOrFail(), $referred);

        return $referred;
    }

    private function referralFor(User $user): Referral
    {
        return Referral::where('referred_user_id', $user->id)->firstOrFail();
    }

    private function referralCreditsFor(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return WalletTransaction::where('user_id', $user->id)
            ->where('type', WalletTransaction::TYPE_REFERRAL)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->get();
    }

    private function createCreditedCompletedOrder(User $user, string $orderId): AffiliateOrderItem
    {
        $item = $this->createOrder($user, $orderId, AffiliateOrderItem::STATUS_COMPLETED);
        $this->giveCashbackCredit($item);

        return $item;
    }

    private function createOrder(User $user, string $orderId, string $status, float $cashback = 15000): AffiliateOrderItem
    {
        return AffiliateOrderItem::factory()->create([
            'user_id' => $user->id,
            'username' => $user->username,
            'order_id' => $orderId,
            'order_status' => $status,
            'affiliate_status' => $status,
            'cashback_amount' => $cashback,
            'reversed_at' => null,
        ]);
    }

    private function giveCashbackCredit(AffiliateOrderItem $item): void
    {
        WalletTransaction::create([
            'running_no' => 'TST'.str_pad((string) mt_rand(1, 9999999), 7, '0', STR_PAD_LEFT),
            'user_id' => $item->user_id,
            'username' => $item->username,
            'platform' => $item->platform,
            'type' => WalletTransaction::TYPE_CASHBACK,
            'direction' => WalletTransaction::DIRECTION_CREDIT,
            'amount' => (float) $item->cashback_amount,
            'balance_before' => 0,
            'balance_after' => (float) $item->cashback_amount,
            'reference_type' => 'affiliate_order_item',
            'reference_id' => $item->id,
            'description' => 'Cashback '.$item->order_id,
            'status' => WalletTransaction::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }
}