<?php

namespace Tests\Feature;

use App\Services\ShopeeFood\ShopeeFoodClient;
use App\Services\ShopeeFood\ShopeeFoodException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeFoodClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function okPayload(array $data = []): array
    {
        return [
            'code' => 0,
            'msg' => 'success',
            'data' => array_merge([
                'total_count' => 1,
                'page_size'   => 100,
                'list'        => [
                    [
                        'checkout_id' => 'C1',
                        'conversion_status' => 2,
                        'utm_content' => '----',
                        'orders' => [
                            ['order_sn' => '', 'items' => [['item_name' => 'Pho']]],
                        ],
                    ],
                ],
            ], $data),
        ];
    }

    public function test_uses_get_method_and_correct_url(): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response($this->okPayload()),
        ]);

        $client = (new ShopeeFoodClient())->getOrders('2026-08-01', '2026-08-31');

        Http::assertSent(function (Request $request) {
            $this->assertSame('GET', $request->method());
            $this->assertStringStartsWith(
                'https://data.addlivetag.com/shopeefood/orders.php',
                $request->url(),
            );

            return true;
        });
    }

    public function test_sends_x_spf_cookie_header(): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response($this->okPayload()),
        ]);

        (new ShopeeFoodClient())->setCookie('SPF_SECRET_COOKIE')->getOrders();

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('X-SPF-Cookie')
                && $request->header('X-SPF-Cookie')[0] === 'SPF_SECRET_COOKIE';
        });
    }

    public function test_cookie_not_in_query_string(): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response($this->okPayload()),
        ]);

        (new ShopeeFoodClient())->setCookie('SPF_SECRET_COOKIE')->getOrders();

        Http::assertSent(function (Request $request) {
            $this->assertStringNotContainsString('SPF_SECRET_COOKIE', $request->url());
            $this->assertSame([], $request->data()['SPF_SECRET_COOKIE'] ?? []);

            return true;
        });
    }

    public function test_pagination_and_date_params_sent(): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response($this->okPayload()),
        ]);

        (new ShopeeFoodClient())->getOrders('2026-08-01', '2026-08-31', page: 3, pageSize: 50);

        Http::assertSent(function (Request $request) {
            return $request['from'] === '2026-08-01'
                && $request['to'] === '2026-08-31'
                && (int) $request['page'] === 3
                && (int) $request['page_size'] === 50;
        });
    }

    public function test_page_size_capped_at_100_and_minimum_1(): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response($this->okPayload()),
        ]);

        $client = new ShopeeFoodClient();

        $client->getOrders(pageSize: 500);
        Http::assertSent(function (Request $request) {
            return (int) $request['page_size'] === 100;
        });

        Http::fake(); // reset for next assertion rule

        $client->getOrders(pageSize: 0);
        Http::assertSent(function (Request $request) {
            return (int) $request['page_size'] === 1;
        });
    }

    public function test_page_size_defaults_to_100(): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response($this->okPayload()),
        ]);

        (new ShopeeFoodClient())->getOrders();

        Http::assertSent(function (Request $request) {
            return (int) $request['page_size'] === 100;
        });
    }

    public function test_json_code_zero_is_parsed(): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response($this->okPayload()),
        ]);

        $response = (new ShopeeFoodClient())->getOrders();

        $this->assertSame(1, $response->getTotalCount());
        $this->assertSame(100, $response->getPageSize());
        $this->assertCount(1, $response->getCheckouts());
        $this->assertSame('C1', $response->getCheckouts()[0]->getCheckoutId());
    }

    public function test_http_400_throws(): void
    {
        Http::fake(['data.addlivetag.com/*' => Http::response('bad request', 400)]);

        $this->expectException(ShopeeFoodException::class);
        $this->expectExceptionMessage('HTTP 400');
        (new ShopeeFoodClient())->getOrders();
    }

    public function test_http_401_is_handled_as_auth_error(): void
    {
        Http::fake(['data.addlivetag.com/*' => Http::response('unauthorized', 401)]);

        $this->expectException(ShopeeFoodException::class);
        $this->expectExceptionMessage('HTTP 401');
        (new ShopeeFoodClient())->getOrders();
    }

    public function test_http_403_is_handled_as_auth_error(): void
    {
        Http::fake(['data.addlivetag.com/*' => Http::response('forbidden', 403)]);

        $this->expectException(ShopeeFoodException::class);
        $this->expectExceptionMessage('HTTP 403');
        (new ShopeeFoodClient())->getOrders();
    }

    public function test_http_500_throws(): void
    {
        Http::fake(['data.addlivetag.com/*' => Http::response('server error', 500)]);

        $this->expectException(ShopeeFoodException::class);
        $this->expectExceptionMessage('HTTP 500');
        (new ShopeeFoodClient())->getOrders();
    }

    public function test_code_zero_with_raw_html_is_expired_session(): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response([
                'code' => 0,
                'msg'  => 'success',
                'raw'  => '<!DOCTYPE html><html><head><title>Login</title></head><body></body></html>',
            ]),
        ]);

        try {
            (new ShopeeFoodClient())->getOrders();
            $this->fail('Expected expired session exception');
        } catch (ShopeeFoodException $e) {
            $this->assertSame('expired_session', $e->getKind());
        }
    }

    public function test_invalid_json_throws(): void
    {
        Http::fake(['data.addlivetag.com/*' => Http::response('<html>not json</html>', 200)]);

        $this->expectException(ShopeeFoodException::class);
        $this->expectExceptionMessage('JSON không hợp lệ');
        (new ShopeeFoodClient())->getOrders();
    }

    public function test_code_nonzero_throws_with_api_message(): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response([
                'code' => 401,
                'msg'  => 'Unauthorized',
            ]),
        ]);

        try {
            (new ShopeeFoodClient())->getOrders();
            $this->fail('Expected invalid status exception');
        } catch (ShopeeFoodException $e) {
            $this->assertSame('invalid_status', $e->getKind());
            $this->assertStringContainsString('Unauthorized', $e->getMessage());
        }
    }

    /**
     * The real API has NO `status` field; a body built around `status` alone
     * (without a `code` of 0) must be treated as invalid.
     */
    public function test_status_only_payload_is_invalid(): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response([
                'status' => 'ok',
                'data'   => ['total_count' => 0, 'list' => []],
            ]),
        ]);

        $this->expectException(ShopeeFoodException::class);
        $this->expectExceptionMessage('mã trạng thái không hợp lệ');
        (new ShopeeFoodClient())->getOrders();
    }

    /**
     * The cookie must never leak into exception messages / responses.
     */
    public function test_cookie_never_appears_in_exceptions(): void
    {
        Http::fake(['data.addlivetag.com/*' => Http::response('nope', 500)]);

        try {
            (new ShopeeFoodClient())->setCookie('TOP_SECRET_SPF_XYZ')->getOrders();
            $this->fail('Expected exception');
        } catch (ShopeeFoodException $e) {
            $this->assertStringNotContainsString('TOP_SECRET_SPF_XYZ', $e->getMessage());
        }
    }

    public function test_pagination_metadata_preserved_for_loop(): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response($this->okPayload([
                'total_count' => 3,
                'page_size'   => 2,
                'list'        => [
                    ['checkout_id' => 'X', 'orders' => []],
                    ['checkout_id' => 'Y', 'orders' => []],
                ],
            ])),
        ]);

        $response = (new ShopeeFoodClient())->getOrders(pageSize: 2);

        $this->assertSame(3, $response->getTotalCount());
        $this->assertSame(1, $response->getPage());
        $this->assertSame(2, $response->getPageSize());
        $this->assertCount(2, $response->getCheckouts());
        $this->assertTrue($response->hasMore()); // 1*2 < 3
    }

    /**
     * Full real-API-parity: 2 checkouts -> 2 orders -> 4 items, `qty` field,
     * checkout/order-level timestamps, capped checkout, FORMAT A utm, empty
     * order_sn. Verifies end-to-end compatibility with the observed API shape.
     */
    public function test_real_api_response_shape_is_fully_parsed(): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response([
                'code' => 0,
                'msg'  => 'success',
                'data' => [
                    'total_count' => 2,
                    'page_size'   => 100,
                    'list'        => [
                        [
                            'checkout_id'       => '1879578695',
                            'checkout_status'   => 'Waiting for payment',
                            'checkout_complete_time' => 1788436999,
                            'purchase_time'     => 1788176218,
                            'click_time'        => 1788176079,
                            'is_shopee_capped'  => false,
                            'gross_commission'  => 1640250000,
                            'checkout_cap'      => 0,
                            'capped_commission' => 0,
                            'affiliate_net_commission' => '1640250000',
                            'utm_content'       => 'tintuctonghop103----',
                            'conversion_status' => 2,
                            'orders'            => [
                                [
                                    'order_id'   => 1879578695,
                                    'order_sn'   => '',
                                    'complete_time'      => 1788436999,
                                    'fraud_complete_time' => 1788222094,
                                    'items'      => [
                                        [
                                            'promotion_id' => '0_0_1909678782',
                                            'item_id' => 7819,
                                            'item_name' => 'Pho Bo',
                                            'shop_name' => 'Quan Pho',
                                            'shop_id' => 800123,
                                            'item_price' => 4500000000,
                                            'qty' => 1,
                                            'actual_amount' => 18225000000,
                                            'refunded_amount' => 0,
                                            'platform_commission_rate' => 9000,
                                            'item_commission' => 1640250000,
                                            'affiliate_item_status' => 0,
                                            'item_status' => 'UNRATED',
                                        ],
                                        [
                                            'promotion_id' => '0_0_1907255368',
                                            'item_id' => 7820,
                                            'item_name' => 'Tra da',
                                            'shop_name' => 'Quan Pho',
                                            'shop_id' => 800123,
                                            'item_price' => 1000000000,
                                            'qty' => 2,
                                            'actual_amount' => 1000000000,
                                            'refunded_amount' => 0,
                                            'platform_commission_rate' => 5000,
                                            'item_commission' => 50000000,
                                            'affiliate_item_status' => 0,
                                            'item_status' => 'UNRATED',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'checkout_id'       => '1877444014',
                            'checkout_status'   => 'Waiting for payment',
                            'checkout_complete_time' => 0,
                            'purchase_time'     => 1788176219,
                            'click_time'        => 1788176080,
                            'is_shopee_capped'  => true,
                            'gross_commission'  => 3006000000,
                            'checkout_cap'      => 2500000000,
                            'capped_commission' => 2500000000,
                            'affiliate_net_commission' => '2500000000',
                            'utm_content'       => 'tintuctonghop103----',
                            'conversion_status' => 2,
                            'orders'            => [
                                [
                                    'order_id'   => 1877444014,
                                    'order_sn'   => '',
                                    'complete_time'      => 0,
                                    'fraud_complete_time' => 0,
                                    'items'      => [
                                        [
                                            'promotion_id' => '0_0_1907255366',
                                            'item_id' => 9001,
                                            'item_name' => 'Banh mi',
                                            'shop_name' => 'Tiem banh',
                                            'shop_id' => 700456,
                                            'item_price' => 2000000000,
                                            'qty' => 1,
                                            'actual_amount' => 2000000000,
                                            'refunded_amount' => 0,
                                            'platform_commission_rate' => 9000,
                                            'item_commission' => 180000000,
                                            'affiliate_item_status' => 0,
                                            'item_status' => 'UNRATED',
                                        ],
                                        [
                                            'promotion_id' => '0_0_1907255367',
                                            'item_id' => 9002,
                                            'item_name' => 'Nuoc mia',
                                            'shop_name' => 'Tiem banh',
                                            'shop_id' => 700456,
                                            'item_price' => 1000000000,
                                            'qty' => 1,
                                            'actual_amount' => 1000000000,
                                            'refunded_amount' => 0,
                                            'platform_commission_rate' => 3000,
                                            'item_commission' => 30000000,
                                            'affiliate_item_status' => 0,
                                            'item_status' => 'UNRATED',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $response = (new ShopeeFoodClient())->setCookie('REAL_COOKIE_HEADER_VALUE')->getOrders();

        $this->assertSame(2, $response->getTotalCount());
        $checkouts = $response->getCheckouts();
        $this->assertCount(2, $checkouts);

        $capped = $checkouts[1];
        $this->assertTrue($capped->isShopeeCapped());
        $this->assertSame(25000.0, $capped->getCheckoutCap());
        $this->assertSame(25000.0, $capped->getCappedCommission());
        $this->assertSame(25000.0, $capped->getAffiliateNetCommission());
        $this->assertSame('tintuctonghop103', $capped->getSubId1());
        $this->assertNull($capped->getCompletedAt()); // checkout_complete_time == 0

        $items = $response->getCheckouts()[0]->getOrders()[0]->getItems();
        $this->assertCount(2, $items);
        $this->assertSame('1879578695', $items[0]->getCheckoutId());
        $this->assertSame(1, $items[0]->getQuantity()); // from `qty`
        $this->assertSame(45000.0, $items[0]->getItemPrice());
        $this->assertSame(182250.0, $items[0]->getActualAmount());
        $this->assertSame(9.0, $items[0]->getPlatformCommissionRate());
        $this->assertSame(16402.5, $items[0]->getItemCommission());
        $this->assertNull($items[0]->getPaidAt());
        $this->assertNull($items[0]->getSettledAt());
        $this->assertSame('1879578695:0_0_1909678782', $items[0]->getLineKey());

        $order = $response->getCheckouts()[0]->getOrders()[0];
        $this->assertNull($order->getOrderSn()); // '' -> null
        $this->assertSame(
            \Carbon\Carbon::createFromTimestamp(1788436999, 'Asia/Ho_Chi_Minh')->toDateTimeString(),
            $order->getCompletedAt(),
        );
    }
}
