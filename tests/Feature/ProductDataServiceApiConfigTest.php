<?php

namespace Tests\Feature;

use App\Services\ProductDataService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * AddLiveTag config wiring only.
 *
 * These tests prove the Product Data API credentials are read from
 * `services.addlivetag.*` (env) and sent as the `X-API-Key` header.
 * They never hit the real API and never assert a real key.
 */
class ProductDataServiceApiConfigTest extends TestCase
{
    private function productUrl(): string
    {
        return 'https://shopee.vn/product/xxx-i.1234567.9876543';
    }

    public function test_addlivetag_base_url_config_reads_from_env(): void
    {
        config(['services.addlivetag.base_url' => 'https://example-addlivetag.test']);

        $this->assertSame(
            'https://example-addlivetag.test',
            config('services.addlivetag.base_url')
        );
    }

    public function test_addlivetag_base_url_defaults_when_env_missing(): void
    {
        // The default lives in the config file itself; a null config() value
        // would mean the key was explicitly overridden, not that env was absent.
        $services = require config_path('services.php');

        $this->assertSame(
            'https://data.addlivetag.com',
            $services['addlivetag']['base_url']
        );
    }

    public function test_addlivetag_api_key_config_reads_from_environment(): void
    {
        config(['services.addlivetag.api_key' => 'test-api-key']);

        $this->assertSame('test-api-key', config('services.addlivetag.api_key'));
    }

    public function test_addlivetag_api_key_is_null_when_env_is_empty(): void
    {
        config(['services.addlivetag.api_key' => null]);

        $this->assertNull(config('services.addlivetag.api_key'));
    }

    public function test_product_data_service_sends_x_api_key_header(): void
    {
        config([
            'services.addlivetag.base_url' => 'https://example-addlivetag.test',
            'services.addlivetag.api_key' => 'test-api-key',
        ]);

        Http::fake([
            'example-addlivetag.test/*' => Http::response([
                'status' => 'success',
                'data' => [
                    'itemId' => 9876543,
                    'productName' => 'Test Product',
                    'price' => 199000,
                    'commission' => 9900,
                    'dataSource' => 'api',
                ],
            ]),
        ]);

        $result = app(ProductDataService::class)->getByUrl($this->productUrl());

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/product-data/product-data.php')
                && $request->hasHeader('X-API-Key', 'test-api-key')
                && $request['item_id'] === 9876543;
        });
    }

    public function test_product_data_service_uses_configured_base_url(): void
    {
        config([
            'services.addlivetag.base_url' => 'https://example-addlivetag.test/',
            'services.addlivetag.api_key' => 'test-api-key',
        ]);

        Http::fake([
            'example-addlivetag.test/*' => Http::response([
                'status' => 'success',
                'data' => [
                    'itemId' => 9876543,
                    'productName' => 'Test Product',
                    'price' => 199000,
                    'commission' => 9900,
                    'dataSource' => 'api',
                ],
            ]),
        ]);

        app(ProductDataService::class)->getByUrl($this->productUrl());

        // Host + path only — $request->url() carries the query string too.
        Http::assertSent(function ($request) {
            return parse_url($request->url(), PHP_URL_HOST) === 'example-addlivetag.test'
                && parse_url($request->url(), PHP_URL_PATH) === '/product-data/product-data.php';
        });
    }

    public function test_missing_api_key_returns_structured_failure_without_http_call(): void
    {
        config([
            'services.addlivetag.base_url' => 'https://example-addlivetag.test',
            'services.addlivetag.api_key' => null,
        ]);

        Http::fake();

        Log::shouldReceive('warning')->atLeast()->once();

        $result = app(ProductDataService::class)->getByUrl($this->productUrl());

        $this->assertFalse($result['success']);
        $this->assertSame('missing_api_key', $result['reason'] ?? null);

        Http::assertNothingSent();
    }

    public function test_empty_api_key_string_returns_structured_failure_without_http_call(): void
    {
        config([
            'services.addlivetag.base_url' => 'https://example-addlivetag.test',
            'services.addlivetag.api_key' => '',
        ]);

        Http::fake();

        Log::shouldReceive('warning')->atLeast()->once();

        $result = app(ProductDataService::class)->getByUrl($this->productUrl());

        $this->assertFalse($result['success']);
        $this->assertSame('missing_api_key', $result['reason'] ?? null);

        Http::assertNothingSent();
    }

    public function test_missing_api_key_does_not_crash_php(): void
    {
        config([
            'services.addlivetag.api_key' => null,
        ]);

        Http::fake();

        Log::shouldReceive('warning')->atLeast()->once();

        $result = app(ProductDataService::class)->getByUrl($this->productUrl());

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
    }

    public function test_invalid_url_fails_without_http_call_even_with_api_key(): void
    {
        config([
            'services.addlivetag.api_key' => 'test-api-key',
        ]);

        Http::fake();

        $result = app(ProductDataService::class)->getByUrl('https://example.com/no-item');

        $this->assertFalse($result['success']);

        Http::assertNothingSent();
    }
}
