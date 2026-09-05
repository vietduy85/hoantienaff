<?php

namespace Tests\Feature\Services\ShopeeFood;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Services\ShopeeFood\ShopeeFoodClient;
use App\Services\ShopeeFood\ShopeeFoodException;
use App\Services\ShopeeFood\ShopeeFoodOrderSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 1 DRY-RUN contract for the ShopeeFood order sync pipeline.
 *
 * Everything here runs with persist=false (default), which MUST never touch
 * affiliate_order_items or the wallet. Also verifies the pagination loop,
 * business keys, status mapping, user resolution, commission validation and
 * the cashback ESTIMATE.
 */
class ShopeeFoodOrderSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function makeService(): ShopeeFoodOrderSyncService
    {
        $client = (new ShopeeFoodClient())->setCookie('FAKE_SPF_COOKIE');

        return new ShopeeFoodOrderSyncService($client);
    }

    private function fakeOrders(array $overrideData = []): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response($this->payload($overrideData)),
        ]);
    }

    private function payload(array $data = []): array
    {
        return [
            'code' => 0,
            'msg'  => 'success',
            'data' => array_merge([
                'total_count' => 1,
                'page_size'   => 100,
                'list'        => [
                    $this->checkoutA(),
                ],
            ], $data),
        ];
    }

    private function checkoutA(): array
    {
        return [
            'checkout_id'    => '1879578695',
            'conversion_status' => 2,
            'is_shopee_capped'  => false,
            'checkout_cap'      => 0,
            'capped_commission' => 0,
            'affiliate_net_commission' => '45000000', // 450 VND == sum(item_commission)
            'utm_content'    => 'alice123----',
            'checkout_complete_time' => 1788436999,
            'purchase_time'  => 1788176218,
            'click_time'     => 1788176079,
            'orders'         => [
                [
                    'order_sn' => '',
                    'items'    => [
                        [
                            'promotion_id' => '0_0_1909678782',
                            'item_id'  => 7819,
                            'item_name' => 'Pho Bo',
                            'shop_name' => 'Quan Pho',
                            'actual_amount' => 500000000,   // 5000 VND
                            'platform_commission_rate' => 9000, // 9%
                            'item_commission' => 45000000,  // 450 VND
                            'affiliate_item_status' => 0,
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_cookie_guard_fails_fast_without_http(): void
    {
        Http::fake(); // disable the default fake -> ensure no HTTP dispatched

        $client = new ShopeeFoodClient(); // cookie == null from config
        $service = new ShopeeFoodOrderSyncService($client);

        try {
            $service->run();
            $this->fail('Expected config_missing exception');
        } catch (ShopeeFoodException $e) {
            $this->assertSame('config_missing', $e->getKind());
        }

        Http::assertNothingSent();
    }

    public function test_dry_run_counts_every_level_and_pagination_stops_at_total_count(): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response($this->payload([
                'total_count' => 2,
                'list' => [$this->checkoutA(), $this->checkoutA()],
            ])),
        ]);

        $result = $this->makeService()->run('2026-08-01', '2026-08-31');

        $this->assertSame(2, $result->checkoutsFetched);
        $this->assertSame(2, $result->ordersFetched);
        $this->assertSame(2, $result->itemsFetched);
        $this->assertSame(2, $result->completed);
        $this->assertSame(2, $result->wouldInsert);
        $this->assertSame(0, $result->wouldUpdate);
    }

    public function test_line_key_uses_checkout_and_promotion_not_item_id_alone(): void
    {
        $this->fakeOrders();
        $result = $this->makeService()->run();
        $line = $result->lines[0];

        $this->assertSame('1879578695:0_0_1909678782', $line['line_key']);
        $this->assertSame('1879578695', $line['checkout_id']);
        $this->assertSame('0_0_1909678782', $line['promotion_id']);
    }

    public function test_status_mapping_from_conversion_status_only(): void
    {
        $base = $this->checkoutA();
        $base['conversion_status'] = 2; // Hoàn thành
        $p2 = $base;
        $p2['checkout_id'] = 'C2';
        $p2['conversion_status'] = 1; // Đang xử lý
        $p3 = $base;
        $p3['checkout_id'] = 'C3';
        $p3['conversion_status'] = 3; // Đã hủy

        Http::fake([
            'data.addlivetag.com/*' => Http::response($this->payload([
                'total_count' => 3,
                'list' => [$base, $p2, $p3],
            ])),
        ]);

        $result = $this->makeService()->run();

        $this->assertSame(1, $result->completed);
        $this->assertSame(1, $result->pending);
        $this->assertSame(1, $result->cancelled);

        $statusByCheckout = collect($result->lines)->mapWithKeys(
            fn (array $l) => [$l['checkout_id'] => $l['status']]
        )->all();

        $this->assertSame('Hoàn thành', $statusByCheckout['1879578695']);
        $this->assertSame('Đang xử lý', $statusByCheckout['C2']);
        $this->assertSame('Đã hủy', $statusByCheckout['C3']);
    }

    public function test_user_resolution_via_sub_id1_format_a(): void
    {
        $this->fakeOrders();
        User::factory()->create(['username' => 'alice123']);

        $result = $this->makeService()->run();
        $line = $result->lines[0];

        $this->assertSame('alice123', $line['username']);
        $this->assertSame('sub_id1', $line['matched_by']);
        $this->assertSame(0, $result->unresolvedUsers);
    }

    public function test_unresolved_user_counts_and_never_guesses(): void
    {
        // 'alice123' does not exist
        $this->fakeOrders();
        $result = $this->makeService()->run();

        $line = $result->lines[0];

        $this->assertNull($line['user_id']);
        $this->assertNull($line['username']);
        $this->assertSame(1, $result->unresolvedUsers);
    }

    public function test_commission_canonical_gross_from_actual_times_rate(): void
    {
        $this->fakeOrders();
        $result = $this->makeService()->run();
        $line = $result->lines[0];

        // actual 5000 VND * 9% = 450 VND exactly equals item_commission.
        $this->assertSame(5000.0, $line['actual_amount']);
        $this->assertSame(9.0, $line['rate_percent']);
        $this->assertSame(450.0, $line['gross_commission']);
        $this->assertSame(450.0, $line['item_commission']);
        $this->assertSame(0, $result->commissionMismatches);
    }

    public function test_commission_mismatch_is_counted(): void
    {
        $checkout = $this->checkoutA();
        // net says 900 but item commission sums to 450 -> mismatch
        $checkout['affiliate_net_commission'] = '90000';

        Http::fake([
            'data.addlivetag.com/*' => Http::response($this->payload([
                'list' => [$checkout],
            ])),
        ]);

        $result = $this->makeService()->run();
        $this->assertSame(1, $result->commissionMismatches);
    }

    public function test_capped_commission_flag_is_preserved(): void
    {
        $checkout = $this->checkoutA();
        $checkout['is_shopee_capped'] = true;
        $checkout['checkout_cap'] = 2500000000; // 25000 VND
        $checkout['capped_commission'] = 2500000000;
        $checkout['affiliate_net_commission'] = '2500000000';

        Http::fake([
            'data.addlivetag.com/*' => Http::response($this->payload([
                'list' => [$checkout],
            ])),
        ]);

        $result = $this->makeService()->run();
        $line = $result->lines[0];

        $this->assertTrue($line['is_shopee_capped']);
        $this->assertSame(25000.0, $line['checkout_cap']);
    }

    public function test_cashback_is_estimate_only_and_never_writes_wallet(): void
    {
        $this->fakeOrders();
        User::factory()->create(['username' => 'alice123']);

        $result = $this->makeService()->run();

        // 450 * 0.60 (ratio 0.09 < 0.12 -> hmm, 450/5000 = 0.09 -> tier 50%)
        $line = $result->lines[0];
        $this->assertSame(0.50, $line['cashback_rate']);
        $this->assertSame(225.0, $line['cashback_amount']);
        $this->assertSame(225.0, $result->cashbackEstimate);
        $this->assertSame(1, $result->cashbackEligible);

        // Dry-run: zero DB writes, zero wallet writes.
        $this->assertSame(0, AffiliateOrderItem::count());
        $this->assertSame(0, $result->inserted);
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->cashbackCredited);
        $this->assertSame(0, $result->cashbackReversed);
    }

    public function test_dry_run_would_update_when_row_exists(): void
    {
        $this->fakeOrders();
        AffiliateOrderItem::create($this->fullFoodRow());

        $result = $this->makeService()->run();

        $this->assertSame(0, $result->wouldInsert);
        $this->assertSame(1, $result->wouldUpdate);

        // Existing row is left untouched in dry-run.
        $row = AffiliateOrderItem::first();
        $this->assertSame('Pho Bo', $row->item_name);
    }

    private function fullFoodRow(array $overrides = []): array
    {
        return array_merge([
            'order_id'                  => '1879578695',
            'order_status'              => 'Hoàn thành',
            'checkout_id'               => '1879578695',
            'shop_name'                 => 'Quan Pho',
            'shop_id'                   => 800123,
            'item_id'                   => 7819,
            'item_name'                 => 'Pho Bo',
            'model_id'                  => 0,
            'item_price'                => 5000,
            'quantity'                  => 1,
            'order_amount'              => 5000,
            'refund_amount'             => 0,
            'commission_type'           => 'Shopee Comm',
            'shopee_commission_rate'    => 9,
            'shopee_commission'         => 450,
            'seller_commission_rate'    => 0,
            'xtra_commission'           => 0,
            'total_product_commission'  => 450,
            'order_commission_shopee'   => 0,
            'order_commission_seller'   => 0,
            'total_order_commission'    => 450,
            'mcn_management_fee_rate'   => 0,
            'mcn_management_fee'        => 0,
            'agreed_commission_rate'    => 9,
            'net_commission'            => 450,
            'affiliate_status'          => 'Hoàn thành',
            'platform'                  => 'ShopeeFood',
            'shopee_food_line_key'      => '1879578695:0_0_1909678782',
            'import_batch'              => '20260831_000000',
            'source_file'               => 'shopeefood-api',
            'first_imported_at'         => now(),
        ], $overrides);
    }

    public function test_status_never_taken_from_order_sn_or_item_id_alone(): void
    {
        // conversion_status drives everything; order_sn always empty.
        Http::fake([
            'data.addlivetag.com/*' => Http::response($this->payload([
                'list' => [$this->checkoutA()],
            ])),
        ]);

        $result = $this->makeService()->run();
        $line = $result->lines[0];

        $this->assertSame('Hoàn thành', $line['status']);
        $this->assertSame(2, $line['conversion_status']);
        $this->assertNotNull($line['affiliate_item_status']);
    }

    public function test_persist_true_reuses_pipeline_to_create_row(): void
    {
        $this->fakeOrders();
        User::factory()->create(['username' => 'alice123']);

        $result = $this->makeService()->run(persist: true);

        $this->assertSame(1, $result->inserted);
        $this->assertSame(0, $result->updated);
        $this->assertSame(1, AffiliateOrderItem::count());

        $row = AffiliateOrderItem::first();
        $this->assertSame('ShopeeFood', $row->platform);
        $this->assertSame('1879578695:0_0_1909678782', $row->shopee_food_line_key);
        $this->assertSame('Hoàn thành', $row->affiliate_status);
        $this->assertNotNull($row->cashback_amount);
        $this->assertNotNull($row->last_shopeefood_sync_at);
    }

    public function test_persist_true_updates_existing_row_and_no_wallet_for_unresolved(): void
    {
        $checkout = $this->checkoutA();
        $checkout['utm_content'] = 'unknown-user----';

        Http::fake([
            'data.addlivetag.com/*' => Http::response($this->payload(['list' => [$checkout]])),
        ]);

        $result = $this->makeService()->run(persist: true, creditWallet: true);

        $this->assertSame(1, $result->inserted);
        $this->assertSame(0, $result->cashbackCredited, 'unresolved user must not be credited');
        $this->assertSame(1, $result->unresolvedUsers);
    }
}