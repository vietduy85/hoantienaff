<?php

namespace Tests\Feature\Services\Lazada;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Lazada\LazadaApiClient;
use App\Services\Lazada\LazadaOrderSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Fixture\LazadaConversionFixture;
use Tests\TestCase;

/**
 * Phase 2 REAL-persist contract: line identity, status -> wallet transitions
 * and repeat-sync idempotency for Lazada.
 *
 * Everything here runs with persist=true and creditWallet=true against the
 * (test) DB, proving the same conversion feed can be re-synced any number of
 * times without double-crediting or double-reversing cashback.
 */
class LazadaPhase2PersistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeLazadaCredentials();
        Http::preventStrayRequests();
    }

    private function fakeLazadaCredentials(): void
    {
        config([
            'services.lazada.app_key'    => 'FAKE_APP_KEY',
            'services.lazada.app_secret' => 'FAKE_APP_SECRET_OF_32_CHARS___',
            'services.lazada.user_token' => 'FAKE_USER_TOKEN',
            'services.lazada.base_url'   => 'https://api.lazada.vn/rest',
        ]);
    }

    private function makeService(): LazadaOrderSyncService
    {
        return new LazadaOrderSyncService(new LazadaApiClient());
    }

    private function payload(array $records): array
    {
        return [
            'code'       => 0,
            'request_id' => 'req-test',
            '_trace_id_' => 'trace-test',
            'result'     => [
                'success' => true,
                'data'    => $records,
            ],
        ];
    }

    private function fakeConversionStatus(string &$status, ?int $subId1 = null): void
    {
        Http::fake([
            'https://api.lazada.vn/*' => function (HttpRequest $request) use (&$status, $subId1) {
                $query = [];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);

                $records = $page === 1
                    ? [array_merge(LazadaConversionFixture::base(), ['status' => $status], $subId1 === null ? [] : ['subId1' => (string) $subId1])]
                    : [];

                return Http::response($this->payload($records));
            },
        ]);
    }

    private function createMember(): User
    {
        return User::factory()->create([
            'username'       => 'alice123',
            'wallet_balance' => 0,
            'total_earned'   => 0,
        ]);
    }

    private function credits(): int
    {
        return WalletTransaction::where('reference_type', 'affiliate_order_item')
            ->where('type', WalletTransaction::TYPE_CASHBACK)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->count();
    }

    private function reversals(): int
    {
        return WalletTransaction::where('reference_type', 'affiliate_order_item')
            ->where('type', WalletTransaction::TYPE_REFUND)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->count();
    }

    private function walletTxCount(): int
    {
        return WalletTransaction::count();
    }

    // ------------------------------------------------------------------
    //  Status -> wallet transitions
    // ------------------------------------------------------------------

    public function test_pending_saved_but_no_wallet_credit(): void
    {
        $this->createMember();
        $status = 'confirmed'; // not fulfilled/delivered/returned -> Đang xử lý
        $this->fakeConversionStatus($status);

        $result = $this->makeService()->run(persist: true, creditWallet: true);

        $this->assertSame(1, $result->inserted);
        $this->assertSame(0, $result->cashbackCredited);
        $this->assertSame(0, $this->walletTxCount());

        $row = AffiliateOrderItem::where('platform', 'Lazada')->first();
        $this->assertSame('Đang xử lý', $row->affiliate_status);
        $this->assertSame(6000.0, (float) $row->cashback_amount, 'pending shows estimate 12000@50% = 6000, wallet untouched');
        $this->assertSame(0.50, (float) $row->cashback_rate);
    }

    public function test_fulfilled_credits_wallet_once(): void
    {
        $member = $this->createMember();
        $status = 'fulfilled';
        $this->fakeConversionStatus($status, $member->id);

        $result = $this->makeService()->run(persist: true, creditWallet: true);

        $this->assertSame(1, $result->cashbackCredited);
        $this->assertSame(0, $result->cashbackSkipped);
        $this->assertSame(1, $this->credits());

        // estPayout 12000, orderAmt 200000 -> ratio 0.06 -> 50% tier -> floor()
        $credit = WalletTransaction::where('type', WalletTransaction::TYPE_CASHBACK)->first();
        $this->assertSame(6000.0, (float) $credit->amount);
        $this->assertSame(6000.0, (float) $member->fresh()->wallet_balance);
    }

    public function test_repeat_fulfilled_sync_never_double_credits(): void
    {
        $member = $this->createMember();
        $status = 'fulfilled';
        $this->fakeConversionStatus($status, $member->id);
        $service = $this->makeService();

        $first = $service->run(persist: true, creditWallet: true);
        $second = $service->run(persist: true, creditWallet: true);

        $this->assertSame(1, $first->inserted);
        $this->assertSame(1, $first->cashbackCredited);

        $this->assertSame(0, $second->inserted);
        $this->assertSame(1, $second->updated);
        $this->assertSame(0, $second->cashbackCredited);
        $this->assertSame(1, $second->cashbackSkipped);

        $this->assertSame(1, $this->credits());
        $this->assertSame(1, $this->walletTxCount());
        $this->assertSame(6000.0, (float) $member->fresh()->wallet_balance);
        $this->assertSame(1, AffiliateOrderItem::where('platform', 'Lazada')->count());
    }

    public function test_pending_to_fulfilled_transition_credits_once(): void
    {
        $member = $this->createMember();
        $service = $this->makeService();

        $status = 'confirmed';
        $this->fakeConversionStatus($status, $member->id);
        $service->run(persist: true, creditWallet: true);
        $this->assertSame(0, $this->credits());

        $status = 'fulfilled';
        $this->fakeConversionStatus($status, $member->id);
        $result = $service->run(persist: true, creditWallet: true);

        $this->assertSame(0, $result->inserted);
        $this->assertSame(1, $result->updated);
        $this->assertSame(1, $result->cashbackCredited);
        $this->assertSame(1, $this->credits());
        $this->assertSame(1, $this->walletTxCount());
    }

    public function test_pending_to_fulfilled_credit_uses_latest_payout_not_estimate(): void
    {
        $member = $this->createMember();
        $service = $this->makeService();

        $status = 'confirmed';
        $payout = '12000.00';
        Http::fake([
            'https://api.lazada.vn/*' => function (HttpRequest $request) use (&$status, &$payout, $member) {
                $query = [];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);

                $records = $page === 1
                    ? [array_merge(LazadaConversionFixture::base(), [
                        'status'    => $status,
                        'estPayout' => $payout,
                        'subId1'    => (string) $member->id,
                    ])]
                    : [];

                return Http::response($this->payload($records));
            },
        ]);

        $service->run(persist: true, creditWallet: true);

        $pendingRow = AffiliateOrderItem::where('platform', 'Lazada')->first();
        $this->assertSame('Đang xử lý', $pendingRow->affiliate_status);
        $this->assertSame(6000.0, (float) $pendingRow->cashback_amount, 'pending estimate 12000@50%');
        $this->assertSame(0, $this->walletTxCount());

        // Payout changes on settle -> wallet credits the REAL value only.
        $status = 'fulfilled';
        $payout = '14000.00';
        $result = $service->run(persist: true, creditWallet: true);

        $this->assertSame(1, $result->updated);
        $this->assertSame(1, $result->cashbackCredited);
        $this->assertSame(7000.0, (float) WalletTransaction::where('type', WalletTransaction::TYPE_CASHBACK)->first()->amount);
        $this->assertSame(1, $this->walletTxCount());
        $this->assertSame(7000.0, (float) $member->fresh()->wallet_balance);
    }

    public function test_pending_to_returned_never_credits_nor_reverses(): void
    {
        $this->createMember();
        $service = $this->makeService();

        $status = 'confirmed';
        $this->fakeConversionStatus($status);
        $service->run(persist: true, creditWallet: true);

        $status = 'returned';
        $this->fakeConversionStatus($status);
        $result = $service->run(persist: true, creditWallet: true);

        $this->assertSame(0, $result->cashbackCredited);
        $this->assertSame(0, $result->cashbackReversed);
        $this->assertSame(0, $this->walletTxCount());
        $this->assertSame('Đã hủy', AffiliateOrderItem::where('platform', 'Lazada')->first()->affiliate_status);
    }

    public function test_fulfilled_to_returned_reverses_once(): void
    {
        $member = $this->createMember();
        $service = $this->makeService();

        $status = 'fulfilled';
        $this->fakeConversionStatus($status, $member->id);
        $service->run(persist: true, creditWallet: true);
        $this->assertSame(1, $this->credits());

        $status = 'returned';
        $this->fakeConversionStatus($status);
        $result = $service->run(persist: true, creditWallet: true);

        $this->assertSame(1, $result->cashbackReversed);
        $this->assertSame(1, $this->reversals());
        $this->assertSame(2, $this->walletTxCount());
        $this->assertSame(0.0, (float) $member->fresh()->wallet_balance);
    }

    public function test_repeat_returned_does_not_double_reverse(): void
    {
        $member = $this->createMember();
        $service = $this->makeService();

        $status = 'fulfilled';
        $this->fakeConversionStatus($status, $member->id);
        $service->run(persist: true, creditWallet: true); // credit

        $status = 'returned';
        $this->fakeConversionStatus($status);
        $service->run(persist: true, creditWallet: true); // reverse once

        $third = $service->run(persist: true, creditWallet: true); // must not reverse again

        $this->assertSame(0, $third->cashbackReversed);
        $this->assertSame(1, $this->reversals());
        $this->assertSame(2, $this->walletTxCount());
        $this->assertSame(0.0, (float) $member->fresh()->wallet_balance);
    }

    public function test_unresolved_user_saved_but_never_credited(): void
    {
        // fixture subId1='5' but no user id 5 exists -> unresolved
        $status = 'fulfilled';
        $this->fakeConversionStatus($status);

        $result = $this->makeService()->run(persist: true, creditWallet: true);

        $this->assertSame(1, $result->inserted);
        $this->assertSame(1, $result->unresolvedUsers);
        $this->assertSame(0, $result->cashbackCredited);
        $this->assertSame(0, $this->walletTxCount());
        $this->assertNull(AffiliateOrderItem::where('platform', 'Lazada')->first()->user_id);
    }

    public function test_persist_row_maps_real_payout_into_schema_columns(): void
    {
        $member = $this->createMember();
        $status = 'fulfilled';
        $this->fakeConversionStatus($status, $member->id);

        $this->makeService()->run(persist: true, creditWallet: true);

        $row = AffiliateOrderItem::where('platform', 'Lazada')->first();

        $this->assertSame('839912345678901:839912345678902:6021831634002', $row->lazada_line_key);
        $this->assertSame('Hoàn thành', $row->affiliate_status);
        $this->assertSame('Son Kem Brand X 01', $row->item_name);
        $this->assertSame('Seller X', $row->shop_name);
        $this->assertSame(12000.0, (float) $row->net_commission);
        $this->assertSame(8000.0, (float) $row->shopee_commission);
        $this->assertSame(4000.0, (float) $row->xtra_commission);
        $this->assertSame(200000.0, (float) $row->order_amount);
        $this->assertNull($row->item_id, 'Lazada never populates item_id');
        $this->assertSame((string) $member->id, $row->sub_id1);
        $this->assertSame('alice123', $row->sub_id2);
        $this->assertSame($member->id, $row->user_id);
        $this->assertNotNull($row->last_lazada_sync_at);
    }
}