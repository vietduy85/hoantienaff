<?php

namespace Tests\Feature\Services\ShopeeFood;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ShopeeFood\ShopeeFoodClient;
use App\Services\ShopeeFood\ShopeeFoodOrderSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 2 REAL-persist contract: line identity (closure), invalid lines,
 * status -> wallet transitions and repeat-sync idempotency.
 *
 * Everything here runs with persist=true and creditWallet=true against the real
 * (test) DB, proving that the same feed can be re-synced any number of times
 * without double-crediting or double-reversing cashback.
 */
class ShopeeFoodPhase2PersistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function makeService(): ShopeeFoodOrderSyncService
    {
        return new ShopeeFoodOrderSyncService((new ShopeeFoodClient())->setCookie('FAKE_SPF_COOKIE'));
    }

    private function fakeCheckouts(array $checkouts): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response([
                'code' => 0,
                'msg'  => 'success',
                'data' => [
                    'total_count' => count($checkouts),
                    'page_size'   => 100,
                    'list'        => $checkouts,
                ],
            ]),
        ]);
    }

    private function shopeeCheckout(
        int $conversionStatus = 2,
        string $utm = 'alice123----',
        ?array $items = null,
        string $id = 'C1',
    ): array {
        return [
            'checkout_id'          => $id,
            'conversion_status'    => $conversionStatus,
            'is_shopee_capped'     => false,
            'checkout_cap'         => 0,
            'capped_commission'    => 0,
            'affiliate_net_commission' => '45000000', // 450 VND == sum(item_commission)
            'utm_content'          => $utm,
            'orders'               => [
                ['order_sn' => '', 'items' => $items ?? [$this->validItem()]],
            ],
        ];
    }

    private function validItem(string $promotionId = 'P1', int $itemId = 11): array
    {
        return [
            'promotion_id' => $promotionId,
            'item_id'      => $itemId,
            'item_name'    => 'Pho',
            'shop_name'    => 'Quan Pho',
            'actual_amount' => 500000000, // 5000 VND
            'platform_commission_rate' => 9000, // 9%
            'item_commission' => 45000000, // 450 VND
            'affiliate_item_status' => 0,
        ];
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
    //  Line identity (Section XXI — cases 1-4)
    // ------------------------------------------------------------------

    public function test_same_item_id_different_promotions_are_distinct_lines(): void
    {
        // Same checkout, same item_id 999, two different promotions -> two lines.
        $items = [$this->validItem('P1', 999), $this->validItem('P2', 999)];
        $this->fakeCheckouts([$this->shopeeCheckout(items: $items)]);

        $result = $this->makeService()->run();

        $this->assertCount(2, $result->lines);
        $this->assertSame('C1:P1', $result->lines[0]['line_key']);
        $this->assertSame('C1:P2', $result->lines[1]['line_key']);
        $this->assertSame(2, $result->wouldInsert);
        $this->assertSame(0, $result->invalidLines);
    }

    public function test_null_promotion_id_marks_line_invalid_no_fake_key_no_persist(): void
    {
        $item = $this->validItem();
        $item['promotion_id'] = null;

        $this->fakeCheckouts([$this->shopeeCheckout(items: [$item])]);
        $result = $this->makeService()->run(persist: true);

        $line = $result->lines[0];
        $this->assertTrue($line['invalid']);
        $this->assertNull($line['line_key'], 'must NOT fabricate checkout:item-<id> fallback key');
        $this->assertSame(1, $result->invalidLines);
        $this->assertSame(1, $result->errors);
        $this->assertStringContainsString('INVALID', $result->errorsDetail[0]);
        $this->assertSame(0, $result->wouldInsert);
        $this->assertSame(0, $result->inserted);

        $this->assertSame(0, AffiliateOrderItem::where('platform', 'ShopeeFood')->count());
        $this->assertSame(0, AffiliateOrderItem::where('shopee_food_line_key', 'like', 'C1:item-%')->count());
    }

    public function test_empty_string_promotion_id_is_also_invalid(): void
    {
        $item = $this->validItem();
        $item['promotion_id'] = '';

        $this->fakeCheckouts([$this->shopeeCheckout(items: [$item])]);
        $result = $this->makeService()->run(persist: true);

        $this->assertTrue($result->lines[0]['invalid']);
        $this->assertNull($result->lines[0]['line_key']);
        $this->assertSame(1, $result->invalidLines);
        $this->assertSame(0, AffiliateOrderItem::where('platform', 'ShopeeFood')->count());
    }

    public function test_invalid_line_does_not_block_valid_lines_in_same_checkout(): void
    {
        $good = $this->validItem('P1');
        $bad  = $this->validItem();
        $bad['promotion_id'] = null;

        $this->fakeCheckouts([$this->shopeeCheckout(items: [$good, $bad])]);
        $result = $this->makeService()->run(persist: true);

        $this->assertSame(1, $result->inserted, 'valid line still persisted');
        $this->assertSame(1, $result->invalidLines);
        $this->assertSame(1, $result->errors);
        $this->assertSame(1, AffiliateOrderItem::where('platform', 'ShopeeFood')->count());
        $this->assertSame('C1:P1', AffiliateOrderItem::where('platform', 'ShopeeFood')->first()->shopee_food_line_key);
    }

    // ------------------------------------------------------------------
    //  Status -> wallet transitions (Section XXII)
    // ------------------------------------------------------------------

    public function test_pending_saved_but_no_wallet_credit(): void
    {
        $this->createMember();
        $this->fakeCheckouts([$this->shopeeCheckout(conversionStatus: 1)]);

        $result = $this->makeService()->run(persist: true, creditWallet: true);

        $this->assertSame(1, $result->inserted);
        $this->assertSame(0, $result->cashbackCredited);
        $this->assertSame(0, $result->cashbackReversed);
        $this->assertSame(0, $this->walletTxCount());

        $row = AffiliateOrderItem::where('platform', 'ShopeeFood')->first();
        $this->assertSame('Đang xử lý', $row->affiliate_status);
        $this->assertSame(0.0, (float) $row->cashback_amount);
    }

    public function test_completed_credits_wallet_once(): void
    {
        $member = $this->createMember();
        $this->fakeCheckouts([$this->shopeeCheckout()]);

        $result = $this->makeService()->run(persist: true, creditWallet: true);

        $this->assertSame(1, $result->cashbackCredited);
        $this->assertSame(0, $result->cashbackSkipped);
        $this->assertSame(1, $this->credits());
        $this->assertSame(225.0, (float) WalletTransaction::where('type', WalletTransaction::TYPE_CASHBACK)->first()->amount);
        $this->assertSame(225.0, (float) $member->fresh()->wallet_balance);
    }

    public function test_repeat_completed_sync_never_double_credits(): void
    {
        $member = $this->createMember();
        $this->fakeCheckouts([$this->shopeeCheckout()]);
        $service = $this->makeService();

        $first  = $service->run(persist: true, creditWallet: true);
        $second = $service->run(persist: true, creditWallet: true);

        $this->assertSame(1, $first->inserted);
        $this->assertSame(1, $first->cashbackCredited);

        $this->assertSame(0, $second->inserted);
        $this->assertSame(1, $second->updated);
        $this->assertSame(0, $second->cashbackCredited);
        $this->assertSame(1, $second->cashbackSkipped);

        $this->assertSame(1, $this->credits());
        $this->assertSame(1, $this->walletTxCount());
        $this->assertSame(225.0, (float) $member->fresh()->wallet_balance);
        $this->assertSame(1, AffiliateOrderItem::where('platform', 'ShopeeFood')->count());
    }

    public function test_pending_to_completed_transition_credits_once(): void
    {
        $this->createMember();
        $service = $this->makeService();

        $status = 1;
        Http::fake([
            'data.addlivetag.com/*' => function () use (&$status) {
                return Http::response([
                    'code' => 0,
                    'msg'  => 'success',
                    'data' => [
                        'total_count' => 1,
                        'page_size'   => 100,
                        'list'        => [$this->shopeeCheckout(conversionStatus: $status)],
                    ],
                ]);
            },
        ]);

        $service->run(persist: true, creditWallet: true);
        $this->assertSame(0, $this->credits());

        $status = 2;
        $result = $service->run(persist: true, creditWallet: true);

        $this->assertSame(0, $result->inserted);
        $this->assertSame(1, $result->updated);
        $this->assertSame(1, $result->cashbackCredited);
        $this->assertSame(1, $this->credits());
        $this->assertSame(1, $this->walletTxCount());
    }

    public function test_pending_to_rejected_never_credits_nor_reverses(): void
    {
        $this->createMember();
        $service = $this->makeService();

        $status = 1;
        Http::fake([
            'data.addlivetag.com/*' => function () use (&$status) {
                return Http::response([
                    'code' => 0,
                    'msg'  => 'success',
                    'data' => [
                        'total_count' => 1,
                        'page_size'   => 100,
                        'list'        => [$this->shopeeCheckout(conversionStatus: $status)],
                    ],
                ]);
            },
        ]);

        $service->run(persist: true, creditWallet: true);

        $status = 3;
        $result = $service->run(persist: true, creditWallet: true);

        $this->assertSame(0, $result->cashbackCredited);
        $this->assertSame(0, $result->cashbackReversed);
        $this->assertSame(0, $this->walletTxCount());
        $this->assertSame('Đã hủy', AffiliateOrderItem::where('platform', 'ShopeeFood')->first()->affiliate_status);
    }

    public function test_completed_to_rejected_reverses_once(): void
    {
        $member = $this->createMember();
        $service = $this->makeService();

        $status = 2;
        Http::fake([
            'data.addlivetag.com/*' => function () use (&$status) {
                return Http::response([
                    'code' => 0,
                    'msg'  => 'success',
                    'data' => [
                        'total_count' => 1,
                        'page_size'   => 100,
                        'list'        => [$this->shopeeCheckout(conversionStatus: $status)],
                    ],
                ]);
            },
        ]);

        $service->run(persist: true, creditWallet: true);
        $this->assertSame(1, $this->credits());

        $status = 3;
        $result = $service->run(persist: true, creditWallet: true);

        $this->assertSame(1, $result->cashbackReversed);
        $this->assertSame(1, $this->reversals());
        $this->assertSame(2, $this->walletTxCount());
        $this->assertSame(0.0, (float) $member->fresh()->wallet_balance);
    }

    public function test_repeat_rejected_does_not_double_reverse(): void
    {
        $member = $this->createMember();
        $service = $this->makeService();

        $status = 2;
        Http::fake([
            'data.addlivetag.com/*' => function () use (&$status) {
                return Http::response([
                    'code' => 0,
                    'msg'  => 'success',
                    'data' => [
                        'total_count' => 1,
                        'page_size'   => 100,
                        'list'        => [$this->shopeeCheckout(conversionStatus: $status)],
                    ],
                ]);
            },
        ]);

        $service->run(persist: true, creditWallet: true); // credit

        $status = 3;
        $service->run(persist: true, creditWallet: true); // reverse once

        $third = $service->run(persist: true, creditWallet: true); // must not reverse again

        $this->assertSame(0, $third->cashbackReversed);
        $this->assertSame(1, $this->reversals());
        $this->assertSame(2, $this->walletTxCount());
        $this->assertSame(0.0, (float) $member->fresh()->wallet_balance);
    }

    public function test_unresolved_user_saved_but_never_credited(): void
    {
        $this->fakeCheckouts([$this->shopeeCheckout(utm: 'ghost----')]);
        $result = $this->makeService()->run(persist: true, creditWallet: true);

        $this->assertSame(1, $result->inserted);
        $this->assertSame(1, $result->unresolvedUsers);
        $this->assertSame(0, $result->cashbackCredited);
        $this->assertSame(0, $this->walletTxCount());
        $this->assertNull(AffiliateOrderItem::where('platform', 'ShopeeFood')->first()->user_id);
    }
}