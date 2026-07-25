<?php

namespace Tests\Unit\Services\RioHub;

use App\Services\RioHub\RioHubClient;
use App\Services\RioHub\RioHubException;
use App\Services\RioHub\RioHubResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RioHubClientTest extends TestCase
{
    private RioHubClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.riohub.base_url'         => 'https://riohub.vn/api/v1',
            'services.riohub.api_key'          => 'test-api-key',
            'services.riohub.creator_username' => 'testuser',
        ]);

        $this->client = new RioHubClient();
    }

    // ------------------------------------------------------------------
    //  createAffiliateLink — success
    // ------------------------------------------------------------------

    public function test_create_affiliate_link_success(): void
    {
        $payload = [
            'success' => true,
            'data'    => [
                'affiliate_url' => 'https://riohub.vn/aff/abc123',
                'product_id'    => '12345',
            ],
        ];

        Http::fake([
            'https://riohub.vn/api/v1/partner/tiktok/affiliate/links' => Http::response($payload, 200),
        ]);

        $response = $this->client->createAffiliateLink('https://example.com/product/1');

        $this->assertInstanceOf(RioHubResponse::class, $response);
        $this->assertTrue($response->isOk());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('https://riohub.vn/aff/abc123', $response->get('data.affiliate_url'));
        $this->assertEquals(['affiliate_url' => 'https://riohub.vn/aff/abc123', 'product_id' => '12345'], $response->getResult());

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://riohub.vn/api/v1/partner/tiktok/affiliate/links'
                && $request->method() === 'POST'
                && $request->hasHeader('X-Riohub-Api-Key', 'test-api-key')
                && $request->data()['product_url'] === 'https://example.com/product/1'
                && $request->data()['creator_username'] === 'testuser'
                && $request->data()['sub_id'] === 'testuser';
        });
    }

    public function test_create_affiliate_link_uses_custom_sub_id(): void
    {
        Http::fake([
            'https://riohub.vn/api/v1/partner/tiktok/affiliate/links' => Http::response(['success' => true, 'data' => []], 200),
        ]);

        $this->client->createAffiliateLink('https://example.com/product/1', 'custom-sub');

        Http::assertSent(function (Request $request) {
            return $request->data()['sub_id'] === 'custom-sub'
                && $request->data()['creator_username'] === 'testuser';
        });
    }

    // ------------------------------------------------------------------
    //  getProduct — success
    // ------------------------------------------------------------------

    public function test_get_product_success(): void
    {
        $payload = [
            'data' => [
                'id'    => '12345',
                'name'  => 'Test Product',
                'price' => 100000,
            ],
        ];

        Http::fake([
            'https://riohub.vn/api/v1/partner/tiktok/affiliate/products*' => Http::response($payload, 200),
        ]);

        $response = $this->client->getProduct('12345');

        $this->assertInstanceOf(RioHubResponse::class, $response);
        $this->assertTrue($response->isOk());
        $this->assertEquals('Test Product', $response->get('data.name'));

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/partner/tiktok/affiliate/products')
                && $request->method() === 'GET'
                && str_contains($request->url(), 'creator_username=testuser')
                && str_contains($request->url(), 'product_id=12345');
        });
    }

    // ------------------------------------------------------------------
    //  getOrders — success
    // ------------------------------------------------------------------

    public function test_get_orders_success(): void
    {
        $payload = [
            'data' => [
                ['order_id' => 'ORD-001', 'status' => 'completed'],
                ['order_id' => 'ORD-002', 'status' => 'pending'],
            ],
        ];

        Http::fake([
            'https://riohub.vn/api/v1/partner/tiktok/affiliate/orders*' => Http::response($payload, 200),
        ]);

        $response = $this->client->getOrders(['status' => 'completed']);

        $this->assertInstanceOf(RioHubResponse::class, $response);
        $this->assertTrue($response->isOk());
        $this->assertCount(2, $response->getResult());

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/partner/tiktok/affiliate/orders')
                && str_contains($request->url(), 'creator_username=testuser')
                && str_contains($request->url(), 'status=completed');
        });
    }

    public function test_get_orders_no_filters(): void
    {
        Http::fake([
            'https://riohub.vn/api/v1/partner/tiktok/affiliate/orders*' => Http::response(['data' => []], 200),
        ]);

        $this->client->getOrders();

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/partner/tiktok/affiliate/orders')
                && str_contains($request->url(), 'creator_username=testuser');
        });
    }

    // ------------------------------------------------------------------
    //  Error responses — all throw RioHubException
    // ------------------------------------------------------------------

    public function test_throws_401(): void
    {
        $this->expectException(RioHubException::class);
        $this->expectExceptionMessage('RioHub API returned HTTP 401');

        Http::fake([
            'https://riohub.vn/api/v1/*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $this->client->createAffiliateLink('https://example.com/product/1');
    }

    public function test_throws_401_with_riohub_message(): void
    {
        try {
            Http::fake([
                'https://riohub.vn/api/v1/*' => Http::response(['message' => 'Invalid API key'], 401),
            ]);

            $this->client->createAffiliateLink('https://example.com/product/1');

            $this->fail('Expected RioHubException was not thrown');
        } catch (RioHubException $e) {
            $this->assertEquals(401, $e->getStatusCode());
            $this->assertEquals('Invalid API key', $e->getRioHubMessage());
            $this->assertStringContainsString('[createAffiliateLink]', $e->getMessage());
        }
    }

    public function test_throws_403(): void
    {
        $this->expectException(RioHubException::class);
        $this->expectExceptionMessage('HTTP 403');

        Http::fake([
            'https://riohub.vn/api/v1/*' => Http::response(['error' => 'Forbidden'], 403),
        ]);

        $this->client->getProduct('12345');
    }

    public function test_throws_404(): void
    {
        $this->expectException(RioHubException::class);
        $this->expectExceptionMessage('HTTP 404');

        Http::fake([
            'https://riohub.vn/api/v1/*' => Http::response(['message' => 'Not found'], 404),
        ]);

        $this->client->getProduct('99999');
    }

    public function test_throws_422(): void
    {
        $this->expectException(RioHubException::class);
        $this->expectExceptionMessage('HTTP 422');

        Http::fake([
            'https://riohub.vn/api/v1/*' => Http::response(['message' => 'Validation failed'], 422),
        ]);

        $this->client->createAffiliateLink('not-a-url');
    }

    public function test_throws_500(): void
    {
        $this->expectException(RioHubException::class);
        $this->expectExceptionMessage('HTTP 500');

        Http::fake([
            'https://riohub.vn/api/v1/*' => Http::response(['message' => 'Internal error'], 500),
        ]);

        $this->client->getOrders();
    }

    public function test_throws_429_after_retry_exhaustion(): void
    {
        $this->expectException(RioHubException::class);
        $this->expectExceptionMessage('HTTP 429');

        Http::fake([
            'https://riohub.vn/api/v1/*' => Http::response(['message' => 'Rate limited'], 429, ['Retry-After' => '0']),
        ]);

        $this->client->setMaxRetries(0)->createAffiliateLink('https://example.com/product/1');
    }

    // ------------------------------------------------------------------
    //  429 with Retry-After
    // ------------------------------------------------------------------

    public function test_429_retries_after_header_seconds(): void
    {
        $callCount = 0;

        Http::fake(function ($request) use (&$callCount) {
            $callCount++;

            if ($callCount === 1) {
                return Http::response(['message' => 'Rate limited'], 429, ['Retry-After' => '0']);
            }

            return Http::response([
                'success' => true,
                'data' => ['affiliate_url' => 'https://riohub.vn/aff/retry-ok'],
            ], 200);
        });

        $response = $this->client->createAffiliateLink('https://example.com/product/1');

        $this->assertTrue($response->isOk());
        $this->assertEquals('https://riohub.vn/aff/retry-ok', $response->get('data.affiliate_url'));
        $this->assertEquals(2, $callCount);
    }

    public function test_429_without_retry_after_throws_immediately(): void
    {
        $callCount = 0;

        Http::fake(function () use (&$callCount) {
            $callCount++;
            return Http::response(['message' => 'Rate limited'], 429);
        });

        try {
            $this->client->createAffiliateLink('https://example.com/product/1');
            $this->fail('Expected RioHubException');
        } catch (RioHubException $e) {
            $this->assertEquals(429, $e->getStatusCode());
            $this->assertEquals(1, $callCount);
        }
    }

    // ------------------------------------------------------------------
    //  Config
    // ------------------------------------------------------------------

    public function test_client_reads_config(): void
    {
        $this->assertEquals('https://riohub.vn/api/v1', $this->client->getBaseUrl());
        $this->assertEquals('testuser', $this->client->getCreatorUsername());
    }

    public function test_client_throws_when_config_missing(): void
    {
        config(['services.riohub.base_url' => '']);

        $client = new RioHubClient();

        $this->assertEquals('', $client->getBaseUrl());
    }

    // ------------------------------------------------------------------
    //  RioHubResponse
    // ------------------------------------------------------------------

    public function test_response_is_ok(): void
    {
        $response = new RioHubResponse(200, ['key' => 'value']);
        $this->assertTrue($response->isOk());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('value', $response->get('key'));
    }

    public function test_response_is_not_ok(): void
    {
        $response = new RioHubResponse(300, []);
        $this->assertFalse($response->isOk());
    }

    public function test_response_get_default(): void
    {
        $response = new RioHubResponse(200, []);
        $this->assertEquals('fallback', $response->get('missing', 'fallback'));
    }

    public function test_response_get_result(): void
    {
        $response = new RioHubResponse(200, ['data' => ['id' => 1]]);
        $this->assertEquals(['id' => 1], $response->getResult());
    }

    public function test_response_get_result_empty_when_no_data_key(): void
    {
        $response = new RioHubResponse(200, ['something' => 'else']);
        $this->assertEquals([], $response->getResult());
    }
}
