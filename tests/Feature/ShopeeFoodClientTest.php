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
            'status' => 'ok',
            'data' => array_merge([
                'total_count' => 1,
                'page'        => 1,
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

    public function test_json_status_ok_is_parsed(): void
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

    public function test_status_ok_with_raw_html_is_expired_session(): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response([
                'status' => 'ok',
                'raw'    => '<!DOCTYPE html><html><head><title>Login</title></head><body></body></html>',
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

    public function test_status_not_ok_throws(): void
    {
        Http::fake([
            'data.addlivetag.com/*' => Http::response([
                'status' => 'error',
                'msg'    => 'something',
            ]),
        ]);

        $this->expectException(ShopeeFoodException::class);
        $this->expectExceptionMessage('status không hợp lệ');
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
                'page'        => 1,
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
}
