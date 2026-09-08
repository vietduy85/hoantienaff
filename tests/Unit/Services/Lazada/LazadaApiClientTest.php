<?php

namespace Tests\Unit\Services\Lazada;

use App\Services\Lazada\LazadaApiClient;
use App\Services\Lazada\LazadaException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LazadaApiClientTest extends TestCase
{
    private const APP_KEY = '105000';
    private const APP_SECRET = 'secret-secret-secret-secret-secret-32';
    private const USER_TOKEN = 'ffffffffffffffffffffffffffffffff';
    private const BASE_URL = 'https://api.lazada.vn/rest';

    private LazadaApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);

        $this->client = new LazadaApiClient(
            self::APP_KEY,
            self::APP_SECRET,
            self::USER_TOKEN,
            self::BASE_URL,
        );
    }

    public function test_request_builds_signed_url(): void
    {
        Http::fake([
            'https://api.lazada.vn/rest/marketing/getlink*' => Http::response(['success' => true, 'data' => []], 200),
        ]);

        $this->client->request('marketing/getlink', [
            'inputType'  => 'url',
            'inputValue' => 'https://www.lazada.vn/products/x-i1.html?u=1',
            'subId1'     => '42',
            'subId2'     => 'testuser',
        ]);

        Http::assertSent(function (Request $request) {
            parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?? '', $query);

            $this->assertSame(self::APP_KEY, $query['app_key']);
            $this->assertSame('sha256', $query['sign_method']);
            $this->assertSame(self::USER_TOKEN, $query['userToken']);
            $this->assertTrue(isset($query['timestamp']) && is_numeric($query['timestamp']));

            $this->assertSame('url', $query['inputType']);
            $this->assertSame('https://www.lazada.vn/products/x-i1.html?u=1', $query['inputValue']);
            $this->assertSame('42', $query['subId1']);
            $this->assertSame('testuser', $query['subId2']);

            // Recompute the LOP signature the documented way: sorted params
            // (excluding "sign") concatenated key+value, API name PREPENDED,
            // then HMAC-SHA256 with the app secret delivered as UPPERCASE HEX.
            $signed = $query;
            unset($signed['sign']);
            ksort($signed);
            $base = '/marketing/getlink';
            foreach ($signed as $k => $v) {
                $base .= $k . $v;
            }
            $expected = strtoupper(hash_hmac('sha256', $base, self::APP_SECRET));

            $this->assertSame($expected, $query['sign']);
            $this->assertTrue(str_contains((string) $request->url(), '/rest/marketing/getlink?'));

            return true;
        });
    }

    public function test_credentials_missing_throws_friendly_exception(): void
    {
        $client = new LazadaApiClient('', '', '', self::BASE_URL);

        try {
            $client->request('marketing/getlink', ['inputType' => 'url']);
            $this->fail('Expected LazadaException');
        } catch (LazadaException $e) {
            $this->assertSame('Tính năng Lazada chưa sẵn sàng. Vui lòng thử lại sau.', $e->getUserMessage());
        }
    }

    public function test_lop_error_code_throws_without_leaking_credentials(): void
    {
        Http::fake([
            '*' => Http::response([
                'code' => 'InvalidAppKey',
                'type' => 'ISV',
                'message' => 'Invalid AppKey',
                'request_id' => 'abc',
            ], 200),
        ]);

        try {
            $this->client->request('marketing/getlink', ['inputType' => 'url', 'inputValue' => 'https://www.lazada.vn/products/x-i1.html']);
            $this->fail('Expected LazadaException');
        } catch (LazadaException $e) {
            $this->assertSame('Không thể kết nối Lazada lúc này, vui lòng thử lại sau.', $e->getUserMessage());
            $this->assertStringNotContainsString(self::APP_KEY, $e->getMessage());
            $this->assertStringNotContainsString(self::APP_SECRET, $e->getMessage());
            $this->assertStringNotContainsString(self::USER_TOKEN, $e->getMessage());
            $this->assertStringNotContainsString('sign=', $e->getMessage());
        }
    }

    public function test_business_error_mapping_for_product_not_found(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'error_code' => 2001,
                'error_msg' => 'offer not found',
                'errorCount' => 1,
            ], 200),
        ]);

        try {
            $this->client->request('marketing/getlink', ['inputType' => 'url', 'inputValue' => 'https://www.lazada.vn/products/ghost-i1.html']);
            $this->fail('Expected LazadaException');
        } catch (LazadaException $e) {
            $this->assertSame('Không tìm thấy sản phẩm Lazada cho link này hoặc sản phẩm không có commission.', $e->getUserMessage());
        }
    }

    public function test_rate_limit_is_handled(): void
    {
        Http::fake([
            '*' => Http::response([
                'code' => 'FrequencyLimited',
                'type' => 'ISV',
                'message' => 'too many requests',
            ], 200),
        ]);

        try {
            $this->client->request('marketing/getlink', ['inputType' => 'url']);
            $this->fail('Expected LazadaException');
        } catch (LazadaException $e) {
            $this->assertSame('Lazada đang giới hạn số lượng truy vấn, xin vui lòng thử lại sau ít phút.', $e->getUserMessage());
        }
    }

    public function test_network_failure_throws_friendly_without_leaking_credentials(): void
    {
        Http::fake([
            '*' => function ($request) {
                throw new ConnectionException('cURL error 6: Could not resolve host for ' . (string) $request->url());
            },
        ]);

        try {
            $this->client->request('marketing/getlink', ['inputType' => 'url', 'inputValue' => 'https://www.lazada.vn/products/x-i1.html']);
            $this->fail('Expected LazadaException');
        } catch (LazadaException $e) {
            $this->assertSame('Không thể kết nối Lazada lúc này, vui lòng thử lại sau.', $e->getUserMessage());
            $this->assertStringNotContainsString(self::APP_KEY, $e->getMessage());
            $this->assertStringNotContainsString(self::APP_SECRET, $e->getMessage());
            $this->assertStringNotContainsString(self::USER_TOKEN, $e->getMessage());
            $this->assertStringNotContainsString('sign=', $e->getMessage());
            $this->assertStringNotContainsString('http', $e->getMessage());
        }
    }

    public function test_sentinel_block_is_treated_as_rate_limit(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => [
                    'message' => 'SentinelBlockException by lazada-affiliate-open-api from com.lazada.affiliate.openapi.api.ProductOpenService',
                    'class' => 'java.lang.RuntimeException',
                ],
                'code' => '0',
                'request_id' => 'blocked',
            ], 200),
        ]);

        try {
            $this->client->request('marketing/getlink', ['inputType' => 'url', 'inputValue' => 'https://www.lazada.vn/products/x-i1.html']);
            $this->fail('Expected LazadaException');
        } catch (LazadaException $e) {
            $this->assertSame('Lazada đang giới hạn số lượng truy vấn, xin vui lòng thử lại sau ít phút.', $e->getUserMessage());
        }
    }

    public function test_http_500_throws_friendly_error(): void
    {
        Http::fake([
            '*' => Http::response(['message' => 'internal error'], 500),
        ]);

        try {
            $this->client->request('marketing/getlink', ['inputType' => 'url']);
            $this->fail('Expected LazadaException');
        } catch (LazadaException $e) {
            $this->assertSame('Lazada tạm thời không phản hồi, vui lòng thử lại sau.', $e->getUserMessage());
        }
    }

    public function test_non_json_response_throws(): void
    {
        Http::fake([
            '*' => Http::response('<html>proxy error</html>', 200),
        ]);

        try {
            $this->client->request('marketing/getlink', ['inputType' => 'url']);
            $this->fail('Expected LazadaException');
        } catch (LazadaException $e) {
            $this->assertSame('Không thể kết nối Lazada lúc này, vui lòng thử lại sau.', $e->getUserMessage());
        }
    }
}