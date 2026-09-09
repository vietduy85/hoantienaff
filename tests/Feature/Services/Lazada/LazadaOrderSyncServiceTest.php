<?php

namespace Tests\Feature\Services\Lazada;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Services\Lazada\LazadaApiClient;
use App\Services\Lazada\LazadaException;
use App\Services\Lazada\LazadaOrderSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Fixture\LazadaConversionFixture;
use Tests\TestCase;

/**
 * Phase 1 DRY-RUN contract for the Lazada order sync pipeline.
 *
 * Everything here runs with persist=false, which MUST never touch
 * affiliate_order_items or the wallet. Also verifies the single-month API
 * constraint handling, pagination, business keys, user resolution, status
 * mapping (documented + unknown), commission validation and cashback ESTIMATE.
 */
class LazadaOrderSyncServiceTest extends TestCase
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

    /**
     * Fake the API: page 1 returns $records, any later page returns empty (the
     * real API paginates until an empty page).
     */
    private function fakeConversion(array $records, array &$calls = []): void
    {
        Http::fake([
            'https://api.lazada.vn/*' => function (HttpRequest $request) use ($records, &$calls) {
                $calls[] = $request->url();
                $query = [];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);

                return Http::response($this->payload($page === 1 ? $records : []));
            },
        ]);
    }

    public function test_missing_credentials_fail_fast_without_http(): void
    {
        config([
            'services.lazada.app_key'    => '',
            'services.lazada.app_secret' => '',
            'services.lazada.user_token' => '',
        ]);

        Http::fake(); // no fake matcher -> any HTTP would throw "no matching fake"

        try {
            $this->makeService()->run();
            $this->fail('Expected config_missing exception');
        } catch (LazadaException $e) {
            $this->assertStringContainsString('chưa được cấu hình', $e->getUserMessage());
        }

        Http::assertNothingSent();
    }

    public function test_no_conversion_data_still_completes_cleanly(): void
    {
        $this->fakeConversion([]);

        $result = $this->makeService()->run('2026-08-01', '2026-08-31');

        $this->assertSame(1, $result->monthsFetched);
        $this->assertSame(1, $result->pagesFetched, 'page 1 is itself the empty page that stops the loop');
        $this->assertSame(0, $result->recordsFetched);
        $this->assertSame(0, $result->errors);
    }

    public function test_dry_run_counts_statuses_and_records(): void
    {
        $fulfilled = LazadaConversionFixture::base();
        $delivered = array_merge(LazadaConversionFixture::base(), ['orderId' => 'O2', 'subOrderId' => 'S2', 'status' => 'delivered']);
        $returned  = array_merge(LazadaConversionFixture::base(), ['orderId' => 'O3', 'subOrderId' => 'S3', 'status' => 'returned']);
        $unknown   = array_merge(LazadaConversionFixture::base(), ['orderId' => 'O4', 'subOrderId' => 'S4', 'status' => 'confirmed']);

        $this->fakeConversion([$fulfilled, $delivered, $returned, $unknown]);

        $result = $this->makeService()->run('2026-08-01', '2026-08-31');

        $this->assertSame(4, $result->recordsFetched);
        $this->assertSame(2, $result->completed);
        $this->assertSame(1, $result->cancelled);
        $this->assertSame(1, $result->pending);
        $this->assertSame(1, $result->unknownStatuses, 'confirmed is not in the documented mapping');
        $this->assertSame(4, $result->wouldInsert);
        $this->assertSame(0, $result->inserted);
    }

    public function test_line_key_is_order_suborder_sku(): void
    {
        $this->fakeConversion([LazadaConversionFixture::base()]);

        $result = $this->makeService()->run();
        $line = $result->lines[0];

        $this->assertSame('839912345678901:839912345678902:6021831634002', $line['line_key']);
        $this->assertFalse($line['invalid']);
    }

    public function test_missing_key_part_marks_line_invalid_and_never_persists(): void
    {
        $this->fakeConversion([
            array_merge(LazadaConversionFixture::base(), ['sku' => '']),
        ]);

        $result = $this->makeService()->run(persist: true);

        $this->assertTrue($result->lines[0]['invalid']);
        $this->assertNull($result->lines[0]['line_key']);
        $this->assertSame(1, $result->invalidLines);
        $this->assertSame(1, $result->errors);
        $this->assertSame(0, $result->inserted);
        $this->assertSame(0, AffiliateOrderItem::where('platform', 'Lazada')->count());
    }

    public function test_user_resolves_via_sub_id1_and_sub_id2(): void
    {
        $user = User::factory()->create(['username' => 'alice123']);
        $this->fakeConversion([
            array_merge(LazadaConversionFixture::base(), ['subId1' => (string) $user->id, 'subId2' => 'alice123']),
        ]);

        $result = $this->makeService()->run();
        $line = $result->lines[0];

        $this->assertSame($user->id, $line['user_id']);
        $this->assertSame('alice123', $line['username']);
        $this->assertSame('sub_id1', $line['matched_by']);
        $this->assertSame(0, $result->unresolvedUsers);
    }

    public function test_unresolved_user_counts_and_never_guesses(): void
    {
        $this->fakeConversion([LazadaConversionFixture::base()]); // subId1='5' no user id 5

        $result = $this->makeService()->run();

        $line = $result->lines[0];
        $this->assertNull($line['user_id']);
        $this->assertNull($line['username']);
        $this->assertSame(1, $result->unresolvedUsers);
    }

    public function test_commission_comes_from_real_payouts_not_order_amt(): void
    {
        $this->fakeConversion([LazadaConversionFixture::base()]);

        $result = $this->makeService()->run();
        $line = $result->lines[0];

        // estPayout = 12000, base 8000 + bonus 4000 exactly.
        $this->assertSame(12000.0, $line['est_payout']);
        $this->assertSame(12000.0, $line['row']['net_commission']);
        $this->assertSame(0, $result->commissionMismatches);
    }

    public function test_commission_mismatch_is_counted(): void
    {
        $this->fakeConversion([
            array_merge(LazadaConversionFixture::base(), ['estPayout' => '99999.00']),
        ]);

        $result = $this->makeService()->run();

        $this->assertSame(1, $result->commissionMismatches);
    }

    public function test_cashback_is_estimate_only_in_dry_run(): void
    {
        $user = User::factory()->create(['username' => 'alice123']);
        $this->fakeConversion([
            array_merge(LazadaConversionFixture::base(), ['subId1' => (string) $user->id, 'subId2' => 'alice123']),
        ]);

        $result = $this->makeService()->run();

        // ratio 12000/200000 = 0.06 -> tier 50% -> floor(12000*0.5) = 6000
        $line = $result->lines[0];
        $this->assertSame(0.50, $line['cashback_rate']);
        $this->assertSame(6000.0, $line['cashback_amount']);
        $this->assertSame(6000.0, $result->cashbackEstimate);
        $this->assertSame(1, $result->cashbackEligible);

        $this->assertSame(0, AffiliateOrderItem::count());
        $this->assertSame(0, $result->inserted);
        $this->assertSame(0, $result->cashbackCredited);
    }

    public function test_dry_run_would_update_when_row_exists(): void
    {
        $this->fakeConversion([LazadaConversionFixture::base()]);

        AffiliateOrderItem::create(array_merge(
            $this->existingLazadaRow(),
        ));

        $result = $this->makeService()->run();

        $this->assertSame(0, $result->wouldInsert);
        $this->assertSame(1, $result->wouldUpdate);

        $row = AffiliateOrderItem::where('platform', 'Lazada')->first();
        $this->assertSame('Seller X', $row->shop_name, 'dry-run must not touch the existing row');
    }

    public function test_multi_month_range_is_split_into_single_month_calls(): void
    {
        $calls = [];
        $this->fakeConversion([LazadaConversionFixture::base()], $calls);

        $result = $this->makeService()->run('2026-07-10', '2026-08-05');

        $this->assertSame(2, $result->monthsFetched);

        $this->assertCount(4, $calls, '2 months x (page1 + empty page2)');
        $this->assertStringContainsString('dateStart=2026-07-10', $calls[0]);
        $this->assertStringContainsString('dateEnd=2026-07-31', $calls[0]);
        $this->assertStringContainsString('dateStart=2026-08-01', $calls[2]);
        $this->assertStringContainsString('dateEnd=2026-08-05', $calls[2]);
    }

    public function test_invalid_from_after_to_is_rejected(): void
    {
        $this->expectException(LazadaException::class);
        $this->makeService()->run('2026-08-10', '2026-08-05');
    }

    private function existingLazadaRow(): array
    {
        return [
            'order_id'               => '839912345678901',
            'order_status'           => 'Hoàn thành',
            'checkout_id'            => '839912345678901',
            'shop_name'              => 'Seller X',
            'shop_id'                => 679188,
            'item_name'              => 'Son Kem Brand X 01',
            'model_id'               => 0,
            'item_price'             => 200000,
            'quantity'               => 1,
            'order_amount'           => 200000,
            'commission_type'        => 'cps',
            'shopee_commission_rate' => 4,
            'shopee_commission'      => 8000,
            'seller_commission_rate' => 2,
            'total_product_commission' => 12000,
            'order_commission_shopee'  => 12000,
            'order_commission_seller'  => 0,
            'total_order_commission'   => 12000,
            'agreed_commission_rate'   => 6,
            'net_commission'           => 12000,
            'affiliate_status'         => 'Hoàn thành',
            'platform'                 => 'Lazada',
            'lazada_line_key'          => '839912345678901:839912345678902:6021831634002',
            'import_batch'             => '20260831_000000',
            'source_file'              => 'lazada-api',
            'first_imported_at'        => now(),
        ];
    }
}