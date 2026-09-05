<?php

namespace Tests\Unit;

use App\Services\ShopeeFood\DTOs\ShopeeFoodOrderItem;
use App\Services\ShopeeFood\ShopeeFoodOrderNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShopeeFoodOrderNormalizerTest extends TestCase
{
    private ShopeeFoodOrderNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ShopeeFoodOrderNormalizer();
    }

    public static function moneyProvider(): array
    {
        return [
            ['6500000000', 65000.0],
            ['2500000000', 25000.0],
            ['100000', 1.0],
            [6500000000, 65000.0],
        ];
    }

    #[DataProvider('moneyProvider')]
    public function test_money_normalised_to_vnd(mixed $raw, float $expected): void
    {
        $this->assertSame($expected, $this->normalizer->money($raw));
    }

    public static function rateProvider(): array
    {
        return [
            ['9000', 9.0],
            ['5000', 5.0],
            ['25000', 25.0],
            [9000, 9.0],
        ];
    }

    #[DataProvider('rateProvider')]
    public function test_rate_normalised_to_percent(mixed $raw, float $expected): void
    {
        $this->assertSame($expected, $this->normalizer->rate($raw));
    }

    public function test_gross_commission_from_actual_amount(): void
    {
        // actual_amount = 65000 VND, rate = 9% (normalised) -> 65000*9/100 = 5850
        $gross = $this->normalizer->grossCommission(65000.0, 9.0);
        $this->assertSame(5850.0, $gross);
    }

    /**
     * Gross commission must use actual_amount (never item_price * qty).
     */
    public function test_gross_commission_ignores_item_price(): void
    {
        $item = $this->singleItemFromRaw([
            'item_price'  => 5000000000, // 50000 VND listed
            'quantity'    => 2,          // quantity does not scale commission
            'actual_amount' => 6500000000,
            'platform_commission_rate' => 9000,
        ]);

        $this->assertSame(50000.0, $item->getItemPrice());
        $this->assertSame(2, $item->getQuantity());
        $this->assertSame(65000.0, $item->getActualAmount());
        $this->assertSame(9.0, $item->getPlatformCommissionRate());

        // Contract: grossCommission always receives the NORMALISED percent
        // straight from the DTO getter - raw 9000 is never passed here.
        $gross = $this->normalizer->grossCommission(
            $item->getActualAmount(),
            $item->getPlatformCommissionRate(),
        );
        $this->assertSame(5850.0, $gross);
    }

    /**
     * When capped, item_commission is re-allocated by the API and must be kept
     * as-is; capped_commission / affiliate_net_commission are preserved, never
     * recomputed from item_price and never taxed.
     */
    public function test_capped_commission_is_preserved_not_recomputed(): void
    {
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray([
                'checkout_id'          => 'C1',
                'is_shopee_capped'     => true,
                'capped_commission'    => 2000000000, // 20000 VND
                'affiliate_net_commission' => '2000000000', // numeric string
            ]),
        ])[0];

        $this->assertTrue($checkout->isShopeeCapped());
        $this->assertSame(20000.0, $checkout->getCappedCommission());
        $this->assertSame(20000.0, $checkout->getAffiliateNetCommission());
    }

    public function test_affiliate_net_commission_handles_numeric_string(): void
    {
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray([
                'checkout_id' => 'C9',
                'affiliate_net_commission' => '1500000000',
            ]),
        ])[0];

        $this->assertSame(15000.0, $checkout->getAffiliateNetCommission());
    }

    /**
     * FORMAT A: sub_id1..5 are parsed once and exposed directly on the DTO (no
     * re-parsing needed in Phase 3); raw utm_content is preserved.
     */
    public function test_format_a_sub_ids_exposed_on_checkout_dto(): void
    {
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray([
                'checkout_id' => 'A1',
                'utm_content' => '225-aaa-bbb-ccc-',
            ]),
        ])[0];

        $this->assertSame('A', $checkout->getUtmFormat());
        $this->assertSame('225-aaa-bbb-ccc-', $checkout->getUtmContent());
        $this->assertSame('225', $checkout->getSubId1());
        $this->assertSame('aaa', $checkout->getSubId2());
        $this->assertSame('bbb', $checkout->getSubId3());
        $this->assertSame('ccc', $checkout->getSubId4());
        $this->assertSame('', $checkout->getSubId5());
        $this->assertNull($checkout->getContentId());
    }

    /**
     * FORMAT A with delimiters only -> all sub ids empty, content_id null.
     */
    public function test_format_a_all_empty_exposed_on_checkout_dto(): void
    {
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray(['checkout_id' => 'A2', 'utm_content' => '----']),
        ])[0];

        $this->assertSame('A', $checkout->getUtmFormat());
        $this->assertSame('', $checkout->getSubId1());
        $this->assertSame('', $checkout->getSubId2());
        $this->assertSame('', $checkout->getSubId3());
        $this->assertSame('', $checkout->getSubId4());
        $this->assertSame('', $checkout->getSubId5());
        $this->assertNull($checkout->getContentId());
    }

    /**
     * FORMAT B (AppS): content_id kept as STRING and exposed directly; AppS /
     * platform / build must NOT leak into sub_id fields.
     */
    public function test_format_b_apps_exposed_on_checkout_dto(): void
    {
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray([
                'checkout_id' => 'B1',
                'utm_content' => '37712193991759004-AppS-android-11010',
            ]),
        ])[0];

        $this->assertSame('B', $checkout->getUtmFormat());
        $this->assertSame('37712193991759004', $checkout->getContentId());
        $this->assertIsString($checkout->getContentId());

        $this->assertSame('', $checkout->getSubId1());
        $this->assertSame('', $checkout->getSubId2());
        $this->assertSame('', $checkout->getSubId3());
        $this->assertSame('', $checkout->getSubId4());
        $this->assertSame('', $checkout->getSubId5());

        $joined = $checkout->getSubId1() . '|' . $checkout->getSubId2() . '|'
            . $checkout->getSubId3() . '|' . $checkout->getSubId4() . '|' . $checkout->getSubId5();
        $this->assertStringNotContainsString('AppS', $joined);
        $this->assertStringNotContainsString('android', $joined);
        $this->assertStringNotContainsString('11010', $joined);
    }

    /**
     * FORMAT B (AccS): content_id kept as STRING; AccS / platform must NOT leak.
     */
    public function test_format_b_accs_exposed_on_checkout_dto(): void
    {
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray([
                'checkout_id' => 'B2',
                'utm_content' => '6938992619562034-AccS-webapp',
            ]),
        ])[0];

        $this->assertSame('B', $checkout->getUtmFormat());
        $this->assertSame('6938992619562034', $checkout->getContentId());
        $this->assertIsString($checkout->getContentId());
        $this->assertSame('', $checkout->getSubId1());
        $this->assertSame('', $checkout->getSubId5());
    }

    /**
     * FORMAT B with 32-char hex content_id -> content_id preserved as STRING.
     */
    public function test_format_b_hex_content_id_stays_string_on_dto(): void
    {
        $hex = str_repeat('a', 32);
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray([
                'checkout_id' => 'B3',
                'utm_content' => $hex . '-AppS-ios-9999',
            ]),
        ])[0];

        $this->assertSame($hex, $checkout->getContentId());
        $this->assertIsString($checkout->getContentId());
    }

    /**
     * When utm_content is absent, format is null, sub ids empty, content_id null,
     * and the raw value stays null.
     */
    public function test_utm_absent_exposed_as_empty_on_dto(): void
    {
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray(['checkout_id' => 'X1', 'utm_content' => null]),
        ])[0];

        $this->assertNull($checkout->getUtmContent());
        $this->assertNull($checkout->getUtmFormat());
        $this->assertSame('', $checkout->getSubId1());
        $this->assertNull($checkout->getContentId());
    }

    /**
     * Unknown / unmapped raw fields (e.g. mcn_*, linked_mcn_*, eligible_seller_commission)
     * are NOT speculatively mapped, but the full raw payload is preserved so
     * Phase 3 can inspect the real API data before committing a mapping.
     */
    public function test_raw_payload_is_preserved_for_phase_3_inspection(): void
    {
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray([
                'checkout_id'              => 'R1',
                'mcn_commission'           => '500000000',
                'linked_mcn_commission'    => '300000000',
                'eligible_seller_commission' => '1200000000',
            ]),
        ])[0];

        $raw = $checkout->getRaw();
        $this->assertSame('500000000', $raw['mcn_commission']);
        $this->assertSame('300000000', $raw['linked_mcn_commission']);
        $this->assertSame('1200000000', $raw['eligible_seller_commission']);
    }

    public function test_conversion_status_mapping_to_int(): void
    {
        foreach ([1, 2, 3] as $status) {
            $checkout = $this->normalizer->normalizeCheckouts([
                $this->checkoutArray(['checkout_id' => "C{$status}", 'conversion_status' => $status]),
            ])[0];

            $this->assertSame($status, $checkout->getConversionStatus());
        }
    }

    /**
     * Business status comes only from conversion_status, never checkout_status.
     */
    public function test_conversion_status_not_derived_from_checkout_status(): void
    {
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray([
                'checkout_id'       => 'C10',
                'checkout_status'   => 'DELIVERED',
                'conversion_status' => 2,
            ]),
        ])[0];

        $this->assertSame(2, $checkout->getConversionStatus());
    }

    public function test_timestamp_zero_becomes_null(): void
    {
        foreach ([0, '0', null, ''] as $value) {
            $this->assertNull($this->normalizer->timestamp($value));
        }
    }

    public function test_valid_unix_timestamp_converted_in_ho_chi_minh(): void
    {
        $dt = new \DateTimeImmutable('2026-08-31 12:00:00', new \DateTimeZone('Asia/Ho_Chi_Minh'));
        $expected = $dt->format('Y-m-d H:i:s');

        $this->assertSame($expected, $this->normalizer->timestamp($dt->getTimestamp()));
    }

    /**
     * Multiple checkouts, multiple orders[], multiple items[] - never just [0].
     */
    public function test_multiple_checkouts_orders_and_items_not_just_zero(): void
    {
        $list = [
            $this->checkoutArray([
                'checkout_id' => 'A',
                'orders' => [
                    ['order_sn' => '', 'items' => [['item_name' => 'A1'], ['item_name' => 'A2']]],
                    ['order_sn' => '', 'items' => [['item_name' => 'A3']]],
                ],
            ]),
            $this->checkoutArray([
                'checkout_id' => 'B',
                'orders' => [
                    ['order_sn' => '', 'items' => [['item_name' => 'B1']]],
                ],
            ]),
        ];

        $checkouts = $this->normalizer->normalizeCheckouts($list);

        $this->assertCount(2, $checkouts);
        $this->assertSame('A', $checkouts[0]->getCheckoutId());
        $this->assertSame('B', $checkouts[1]->getCheckoutId());

        $this->assertSame(['A1', 'A2', 'A3'], $this->collectItemNames($checkouts[0]->getOrders()));
        $this->assertSame(['B1'], $this->collectItemNames($checkouts[1]->getOrders()));
    }

    public function test_display_item_status_defaults_to_empty_string(): void
    {
        $item = $this->singleItemFromRaw(['item_name' => 'X']);
        $this->assertSame('', $item->getDisplayItemStatus());
    }

    /**
     * Real API items use `qty`, not `quantity`.
     */
    public function test_quantity_from_qty_field(): void
    {
        $item = $this->singleItemFromRaw(['qty' => 1]);
        $this->assertSame(1, $item->getQuantity());
    }

    /**
     * Fall back to `quantity` for synthetic/legacy payloads.
     */
    public function test_quantity_falls_back_to_quantity_field(): void
    {
        $item = $this->singleItemFromRaw(['quantity' => 3]);
        $this->assertSame(3, $item->getQuantity());
    }

    /**
     * Item inherits checkout_id from the wrapper (real items carry no
     * checkout_id themselves).
     */
    public function test_item_inherits_checkout_id_from_wrapper(): void
    {
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray([
                'checkout_id' => '1879578695',
                'orders' => [['order_sn' => '', 'items' => [['item_name' => 'A1']]]],
            ]),
        ])[0];

        $item = $checkout->getOrders()[0]->getItems()[0];
        $this->assertSame('1879578695', $item->getCheckoutId());
        $this->assertSame('1879578695', $checkout->getCheckoutId());
    }

    /**
     * Rounded-trip check on the exact real values:
     * raw 9000 -> normalised 9.0 (rate() divides only once),
     * 182,250 VND * 9.0 / 100 = 16,402.5 VND == item_commission scaled.
     */
    public function test_canonical_gross_commission_matches_real_item_commission(): void
    {
        $item = $this->singleItemFromRaw([
            'item_price'  => 4500000000,
            'qty'  => 1,
            'actual_amount' => 18225000000,
            'platform_commission_rate' => 9000,
            'item_commission' => 1640250000,
        ]);

        $this->assertSame(45000.0, $item->getItemPrice());
        $this->assertSame(1, $item->getQuantity());
        $this->assertSame(182250.0, $item->getActualAmount());
        $this->assertSame(9.0, $item->getPlatformCommissionRate());

        $gross = $this->normalizer->grossCommission(
            $item->getActualAmount(),
            $item->getPlatformCommissionRate(),
        );
        $this->assertSame(16402.5, $gross);
        $this->assertSame(16402.5, $item->getItemCommission());

        // Math-equivalent to the canonical raw formula: actual * 9000 / 100000.
        $this->assertSame($gross, 182250.0 * 9000 / 100000);
    }

    /**
     * Contract guard: raw 9000 must be divided EXACTLY ONCE (inside rate()).
     * Passing normalised 9.0 into grossCommission must NOT divide again.
     */
    public function test_raw_rate_is_converted_exactly_once(): void
    {
        $this->assertSame(9.0, $this->normalizer->rate(9000));

        $gross = $this->normalizer->grossCommission(182250.0, 9.0);
        $this->assertSame(16402.5, $gross);
    }

    /**
     * Contract guard: normalised 9.0 must never be treated as raw 9.
     * raw 9 would imply 0.009% -> 182250*9/100000 = 16.4025 (wrong).
     */
    public function test_normalised_percent_is_not_treated_as_raw_rate(): void
    {
        $correct = $this->normalizer->grossCommission(182250.0, 9.0);
        $this->assertSame(16402.5, $correct);

        $wrongIfRaw = 182250.0 * 9 / 100000;
        $this->assertNotSame($wrongIfRaw, $correct);
    }

    /**
     * Contract guard: normalising raw 9000 then computing gross must equal the
     * canonical raw formula for the real fixture.
     */
    public function test_normalised_rate_path_matches_canonical_raw_formula(): void
    {
        $rawActual = 18225000000;
        $rawRate = 9000;
        $rawItemCommission = 1640250000;

        $normalisedActual = $this->normalizer->money($rawActual);
        $normalisedRate = $this->normalizer->rate($rawRate);

        $gross = $this->normalizer->grossCommission($normalisedActual, $normalisedRate);

        $this->assertSame(182250.0, $normalisedActual);
        $this->assertSame(9.0, $normalisedRate);
        $this->assertSame($rawActual / 100000 * $rawRate / 100000, $gross);
        $this->assertSame($rawItemCommission / 100000, $gross);
    }

    /**
     * Capped checkout fixtures (real numbers): cap, capped_commission and
     * affiliate_net_commission are preserved from the API and NOT recomputed.
     */
    public function test_real_capped_checkout_values_preserved(): void
    {
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray([
                'checkout_id'             => '1877444014',
                'is_shopee_capped'        => true,
                'gross_commission'        => 3006000000,
                'checkout_cap'            => 2500000000,
                'capped_commission'       => 2500000000,
                'affiliate_net_commission' => '2500000000',
            ]),
        ])[0];

        $this->assertTrue($checkout->isShopeeCapped());
        $this->assertSame(25000.0, $checkout->getCheckoutCap());
        $this->assertSame(25000.0, $checkout->getCappedCommission());
        $this->assertSame(25000.0, $checkout->getAffiliateNetCommission());
    }

    /**
     * Timestamps live at checkout/order level, not on the item.
     */
    public function test_checkout_and_order_timestamps_from_real_fields(): void
    {
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray([
                'checkout_id'          => '1879578695',
                'click_time'           => 1788176079,
                'purchase_time'        => 1788176218,
                'checkout_complete_time' => 1788436999,
                'orders'               => [[
                    'order_sn'              => '',
                    'complete_time'         => 1788436999,
                    'fraud_complete_time'   => 1788222094,
                    'items'                 => [['item_name' => 'A1']],
                ]],
            ]),
        ])[0];

        $this->assertSame(
            \Carbon\Carbon::createFromTimestamp(1788176079, 'Asia/Ho_Chi_Minh')->toDateTimeString(),
            $checkout->getClickedAt(),
        );
        $this->assertSame(
            \Carbon\Carbon::createFromTimestamp(1788176218, 'Asia/Ho_Chi_Minh')->toDateTimeString(),
            $checkout->getPurchasedAt(),
        );
        $this->assertSame(
            \Carbon\Carbon::createFromTimestamp(1788436999, 'Asia/Ho_Chi_Minh')->toDateTimeString(),
            $checkout->getCompletedAt(),
        );

        $order = $checkout->getOrders()[0];
        $this->assertSame(
            \Carbon\Carbon::createFromTimestamp(1788436999, 'Asia/Ho_Chi_Minh')->toDateTimeString(),
            $order->getCompletedAt(),
        );
        $this->assertSame(
            \Carbon\Carbon::createFromTimestamp(1788222094, 'Asia/Ho_Chi_Minh')->toDateTimeString(),
            $order->getFraudCompletedAt(),
        );
    }

    /**
     * Absent / zero timestamps must stay null (never 1970-01-01).
     */
    public function test_absent_timestamps_are_null_not_epoch(): void
    {
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray(['checkout_id' => 'T0', 'orders' => [
                ['order_sn' => '', 'complete_time' => 0, 'items' => [['item_name' => 'X']]],
            ]]),
        ])[0];

        $this->assertNull($checkout->getClickedAt());
        $this->assertNull($checkout->getPurchasedAt());
        $this->assertNull($checkout->getCompletedAt());
        $this->assertNull($checkout->getOrders()[0]->getCompletedAt());
        $this->assertNull($checkout->getOrders()[0]->getFraudCompletedAt());
        $this->assertNull($checkout->getOrders()[0]->getItems()[0]->getPaidAt());
        $this->assertNull($checkout->getOrders()[0]->getItems()[0]->getSettledAt());
    }

    /**
     * order_sn empty string -> null (real API always returns '').
     */
    public function test_order_sn_empty_becomes_null(): void
    {
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray(['checkout_id' => 'OS1', 'orders' => [
                ['order_sn' => '', 'items' => [['item_name' => 'X']]],
            ]]),
        ])[0];

        $this->assertNull($checkout->getOrders()[0]->getOrderSn());
    }

    /**
     * Business/line key is checkout_id:promotion_id - never item_id.
     */
    public function test_line_key_is_checkout_and_promotion_not_item_id(): void
    {
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray(['checkout_id' => '1879578695', 'orders' => [
                ['order_sn' => '', 'items' => [
                    ['item_name' => 'A1', 'item_id' => 7819, 'promotion_id' => '0_0_1909678782'],
                ]],
            ]]),
        ])[0];

        $item = $checkout->getOrders()[0]->getItems()[0];
        $this->assertSame('1879578695:0_0_1909678782', $item->getLineKey());
        $this->assertSame('7819', $item->getItemId());
        $this->assertStringNotContainsString((string) $item->getItemId(), $item->getLineKey());
    }

    /**
     * content_id uses a 16-17 digit number which must never be float-cast.
     */
    public function test_format_a_single_field_does_not_lose_precision(): void
    {
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray([
                'checkout_id' => 'L1',
                'utm_content' => '17342330566----',
            ]),
        ])[0];

        $this->assertSame('17342330566', $checkout->getSubId1());
    }

    private function collectItemNames(array $orders): array
    {
        $names = [];
        foreach ($orders as $order) {
            foreach ($order->getItems() as $item) {
                $names[] = $item->getItemName();
            }
        }

        return $names;
    }

    private function singleItemFromRaw(array $override): ShopeeFoodOrderItem
    {
        $checkout = $this->normalizer->normalizeCheckouts([
            $this->checkoutArray(['orders' => [['items' => [array_merge(['item_name' => 'X'], $override)]]]]),
        ])[0];

        return $checkout->getOrders()[0]->getItems()[0];
    }

    private function checkoutArray(array $override): array
    {
        return array_merge([
            'checkout_id'       => 'DEFAULT',
            'conversion_status' => 2,
            'utm_content'       => '----',
            'orders'            => [],
        ], $override);
    }
}
