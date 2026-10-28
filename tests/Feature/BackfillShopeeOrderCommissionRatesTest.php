<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillShopeeOrderCommissionRatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.addlivetag.api_key' => 'test-key',
            'services.addlivetag.base_url' => 'https://data.addlivetag.com',
        ]);
    }

    public function test_dry_run_reports_proposal_without_writing(): void
    {
        $order = $this->seedOrder();
        $this->fakeApi([$order->item_id => [7.0, 0.0]]);

        $payload = $this->runCommand(['--json' => true]);

        $this->assertSame('dry-run', $payload['mode']);
        $this->assertSame(1, $payload['summary']['proposed']);
        $this->assertSame(0, $payload['summary']['applied']);
        $this->assertSame('propose', $payload['rows'][0]['action']);
        $this->assertSame('historical_total_matches_api', $payload['rows'][0]['reason']);
        $this->assertSame(0.0, (float) $order->fresh()->shopee_commission_rate);
        $this->assertSame(0.0, (float) $order->fresh()->seller_commission_rate);
    }

    public function test_apply_writes_percent_rates_when_historical_total_matches(): void
    {
        $order = $this->seedOrder();
        $this->fakeApi([$order->item_id => [7.0, 0.0]]);

        $payload = $this->runCommand(['--apply' => true, '--json' => true]);

        $this->assertSame('apply', $payload['mode']);
        $this->assertSame(1, $payload['summary']['applied']);
        $this->assertSame('applied', $payload['rows'][0]['action']);
        $this->assertSame(7.0, (float) $order->fresh()->shopee_commission_rate);
        $this->assertSame(0.0, (float) $order->fresh()->seller_commission_rate);
    }

    public function test_apply_is_idempotent(): void
    {
        $order = $this->seedOrder();
        $this->fakeApi([$order->item_id => [7.0, 0.0]]);

        $this->runCommand(['--apply' => true, '--json' => true]);
        $payload = $this->runCommand(['--apply' => true, '--json' => true]);

        $this->assertSame(0, $payload['summary']['applied']);
        $this->assertSame('already_has_rate', $payload['rows'][0]['reason']);
        $this->assertSame(7.0, (float) $order->fresh()->shopee_commission_rate);
    }

    public function test_conflict_with_historical_total_is_not_written(): void
    {
        $order = $this->seedOrder([
            'order_amount' => 100000,
            'total_order_commission' => 4500,
        ]);
        $this->fakeApi([$order->item_id => [4.0, 2.0]]);

        $payload = $this->runCommand(['--apply' => true, '--json' => true]);

        $this->assertSame(0, $payload['summary']['applied']);
        $this->assertSame('historical_total_mismatch', $payload['rows'][0]['reason']);
        $this->assertSame(4.5, round($payload['rows'][0]['implied_total_pct'], 4));
        $this->assertSame(6.0, (float) $payload['rows'][0]['api_total_pct']);
        $this->assertSame(0.0, (float) $order->fresh()->shopee_commission_rate);
        $this->assertSame(0.0, (float) $order->fresh()->seller_commission_rate);
    }

    public function test_rows_without_amount_or_commission_are_skipped_without_api_calls(): void
    {
        $noAmount = $this->seedOrder(['order_amount' => 0, 'total_order_commission' => 10000]);
        $noCommission = $this->seedOrder(['order_amount' => 100000, 'total_order_commission' => 0]);

        $payload = $this->runCommand(['--apply' => true, '--json' => true]);

        $this->assertSame(0, $payload['api_calls']);
        $this->assertSame('no_order_amount', $payload['rows'][0]['reason']);
        $this->assertSame('no_commission', $payload['rows'][1]['reason']);
        $this->assertSame(0.0, (float) $noAmount->fresh()->shopee_commission_rate);
        $this->assertSame(0.0, (float) $noCommission->fresh()->shopee_commission_rate);
        Http::assertNothingSent();
    }

    public function test_existing_rates_are_never_overwritten(): void
    {
        $order = $this->seedOrder([
            'shopee_commission_rate' => 3.50,
            'seller_commission_rate' => 0.00,
        ]);
        $this->fakeApi([$order->item_id => [7.0, 0.0]]);

        $payload = $this->runCommand(['--apply' => true, '--json' => true]);

        $this->assertSame(0, $payload['summary']['applied']);
        $this->assertSame('already_has_rate', $payload['rows'][0]['reason']);
        $this->assertSame(3.5, (float) $order->fresh()->shopee_commission_rate);
        Http::assertNothingSent();
    }

    public function test_only_shopee_rows_inside_window_are_considered(): void
    {
        $before = $this->seedOrder(['ordered_at' => '2026-10-24 23:59:59']);
        $onBoundary = $this->seedOrder(['ordered_at' => '2026-10-25 00:00:00']);
        $lazada = $this->seedOrder(['platform' => 'Lazada', 'ordered_at' => '2026-10-26 10:00:00']);

        $this->fakeApi([
            $before->item_id => [7.0, 0.0],
            $onBoundary->item_id => [7.0, 0.0],
            $lazada->item_id => [7.0, 0.0],
        ]);

        $payload = $this->runCommand(['--apply' => true, '--json' => true]);

        $this->assertSame(1, $payload['scope_rows']);
        $this->assertSame(1, $payload['summary']['applied']);
        $this->assertSame(0.0, (float) $before->fresh()->shopee_commission_rate);
        $this->assertSame(7.0, (float) $onBoundary->fresh()->shopee_commission_rate);
        $this->assertSame(0.0, (float) $lazada->fresh()->shopee_commission_rate);
        Http::assertSentCount(1);
    }

    public function test_api_failures_are_skipped(): void
    {
        $order = $this->seedOrder();
        Http::fake(['*' => Http::response(['status' => 'error'], 500)]);

        $payload = $this->runCommand(['--apply' => true, '--json' => true]);

        $this->assertSame(0, $payload['summary']['applied']);
        $this->assertSame('api_unavailable', $payload['rows'][0]['reason']);
        $this->assertSame(0.0, (float) $order->fresh()->shopee_commission_rate);
    }

    public function test_api_calls_are_deduplicated_by_item_id(): void
    {
        $first = $this->seedOrder(['item_id' => 777001]);
        $second = $this->seedOrder(['item_id' => 777001]);
        $this->fakeApi([777001 => [7.0, 0.0]]);

        $payload = $this->runCommand(['--apply' => true, '--json' => true]);

        $this->assertSame(1, $payload['api_calls']);
        $this->assertSame(2, $payload['summary']['applied']);
        $this->assertSame(7.0, (float) $first->fresh()->shopee_commission_rate);
        $this->assertSame(7.0, (float) $second->fresh()->shopee_commission_rate);
        Http::assertSentCount(1);
    }

    public function test_only_rate_columns_change(): void
    {
        $order = $this->seedOrder();
        $this->fakeApi([$order->item_id => [7.0, 0.0]]);
        $before = (array) DB::table('affiliate_order_items')->where('id', $order->id)->first();

        $this->runCommand(['--apply' => true, '--json' => true]);

        $after = (array) DB::table('affiliate_order_items')->where('id', $order->id)->first();
        unset($before['shopee_commission_rate'], $before['seller_commission_rate'], $after['shopee_commission_rate'], $after['seller_commission_rate']);

        $this->assertSame($before, $after);
    }

    public function test_backup_contains_old_and_new_values(): void
    {
        $order = $this->seedOrder();
        $this->fakeApi([$order->item_id => [7.0, 0.0]]);
        $path = storage_path('app/audit/test_backfill_backup_'.uniqid().'.json');

        try {
            $this->runCommand(['--apply' => true, '--json' => true, '--backup' => $path]);

            $this->assertFileExists($path);
            $backup = json_decode((string) file_get_contents($path), true);
            $row = $backup['rows'][0];
            $this->assertSame(0.0, (float) $row['old_shopee_rate']);
            $this->assertSame(7.0, (float) $row['new_shopee_rate']);
            $this->assertSame(0.0, (float) $row['new_seller_rate']);
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function test_json_output_is_machine_readable(): void
    {
        $order = $this->seedOrder(['seller_commission_rate' => 0.00]);
        $this->fakeApi([$order->item_id => [2.5, 2.0]]);

        $payload = $this->runCommand(['--json' => true]);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('mode', $payload);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('rows', $payload);
        $this->assertSame($order->order_id, $payload['rows'][0]['order_id']);
    }

    private function seedOrder(array $overrides = []): AffiliateOrderItem
    {
        return AffiliateOrderItem::factory()->create(array_merge([
            'platform' => 'Shopee',
            'ordered_at' => '2026-10-26 10:00:00',
            'order_amount' => 100000,
            'total_order_commission' => 7000,
            'shopee_commission_rate' => 0.00,
            'seller_commission_rate' => 0.00,
            'affiliate_status' => 'Hoàn thành',
        ], $overrides));
    }

    private function runCommand(array $options): array
    {
        $cleanup = null;

        if (($options['--apply'] ?? false) && ! isset($options['--backup'])) {
            $cleanup = $options['--backup'] = storage_path('app/audit/test_backfill_backup_'.uniqid().'.json');
        }

        try {
            $exitCode = Artisan::call('affiliate:backfill-shopee-order-rates', $options);
            $this->assertSame(0, $exitCode);

            $decoded = json_decode(trim(Artisan::output()), true);
            $this->assertIsArray($decoded);

            return $decoded;
        } finally {
            if (is_string($cleanup) && file_exists($cleanup)) {
                unlink($cleanup);
            }
        }
    }

    private function fakeApi(array $ratesByItem): void
    {
        $normalized = [];
        foreach ($ratesByItem as $itemId => $rates) {
            $normalized[(int) $itemId] = $rates;
        }

        Http::fake(function (Request $request) use ($normalized) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $itemId = (int) ($query['item_id'] ?? 0);

            if (! array_key_exists($itemId, $normalized)) {
                return Http::response(['status' => 'error', 'message' => 'not found'], 200);
            }

            [$shopeeRate, $sellerRate] = $normalized[$itemId];

            return Http::response([
                'status' => 'success',
                'productInfo' => [
                    'itemId' => $itemId,
                    'shopeeRate' => $shopeeRate,
                    'sellerRate' => $sellerRate,
                    'shopeeRatePercent' => $shopeeRate,
                    'sellerRatePercent' => $sellerRate,
                    'shopeeRateSource' => 'item',
                    'hasShopeeCommission' => $shopeeRate > 0,
                    'hasSellerCommission' => $sellerRate > 0,
                ],
            ], 200);
        });
    }
}
