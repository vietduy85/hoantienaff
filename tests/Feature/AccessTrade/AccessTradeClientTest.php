<?php

namespace Tests\Feature\AccessTrade;

use App\Services\AccessTrade\AccessTradeCampaignService;
use App\Services\AccessTrade\AccessTradeClient;
use App\Services\AccessTrade\AccessTradeConfig;
use App\Services\AccessTrade\AccessTradeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase D — AccessTrade HTTP client + campaign normalisation (mocked, no live
 * calls). Verifies auth header, error translation, user-safe messages and the
 * domain→campaign mapping rules.
 */
class AccessTradeClientTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $value = 'super-secret-token'): void
    {
        app(AccessTradeConfig::class)->setToken($value);
        app(AccessTradeConfig::class)->setEnabled(true);
    }

    public function test_missing_token_throws_config_exception_without_http(): void
    {
        Http::fake();

        app(AccessTradeConfig::class)->setToken(null);
        config(['services.accesstrade.token' => '']);

        try {
            app(AccessTradeClient::class)->get('/v1/campaigns');
            $this->fail('Expected AccessTradeException.');
        } catch (AccessTradeException $e) {
            $this->assertSame('Chưa cấu hình AccessTrade API key.', $e->getUserMessage());
        }

        Http::assertNothingSent();
    }

    public function test_unauthorized_error_is_translated_and_does_not_leak_token(): void
    {
        $this->token('leaky-token-abcdef');

        Http::fake([
            'api.accesstrade.vn/*' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        try {
            app(AccessTradeClient::class)->get('/v1/campaigns');
            $this->fail('Expected AccessTradeException.');
        } catch (AccessTradeException $e) {
            $this->assertSame(401, $e->getHttpStatus());
            $this->assertStringContainsString('không hợp lệ', $e->getUserMessage());
            $this->assertStringNotContainsString('leaky-token-abcdef', $e->getMessage());
            $this->assertStringNotContainsString('leaky-token-abcdef', $e->getUserMessage());
        }
    }

    public function test_authorization_header_uses_token_scheme(): void
    {
        $this->token('header-token');

        Http::fake([
            'api.accesstrade.vn/*' => Http::response(['data' => [], 'total' => 0], 200),
        ]);

        app(AccessTradeClient::class)->get('/v1/campaigns');

        Http::assertSent(fn ($request) => ($request->header('Authorization')[0] ?? null) === 'Token header-token');
    }

    public function test_campaigns_are_normalised_and_merged(): void
    {
        $this->token();

        Http::fake([
            'api.accesstrade.vn/v1/cashback/campaigns*' => Http::response([
                'data' => ['campaigns' => [[
                    'campaign_id' => '77',
                    'name' => 'Merchant A',
                    'merchant' => 'merchant-a.com',
                    'approval' => 'successful',
                    'status' => 1,
                    'min_commission' => 3.5,
                    'max_commission' => 8.0,
                ]]],
            ], 200),
            'api.accesstrade.vn/v1/campaigns*' => Http::response([
                'total' => 1,
                'data' => [[
                    'id' => '77',
                    'name' => 'Merchant A legacy',
                    'approval' => 'successful',
                    'status' => 1,
                    'url' => 'https://www.merchant-a.com',
                ]],
            ], 200),
        ]);

        $campaigns = app(AccessTradeCampaignService::class)->campaigns();

        $this->assertCount(1, $campaigns);
        $this->assertSame('77', $campaigns[0]['campaign_id']);
        $this->assertSame('merchant-a.com', $campaigns[0]['domain']);
        $this->assertSame(3.5, $campaigns[0]['min_commission']);
        $this->assertSame(8.0, $campaigns[0]['max_commission']);
    }

    public function test_for_host_matches_exact_domain_and_subdomain_only(): void
    {
        $this->token();

        Http::fake([
            'api.accesstrade.vn/v1/campaigns*' => Http::response([
                'total' => 1,
                'data' => [[
                    'id' => '88',
                    'name' => 'Merchant B',
                    'approval' => 'successful',
                    'status' => 1,
                    'url' => 'https://merchant-b.com',
                ]],
            ], 200),
            'api.accesstrade.vn/v1/cashback/campaigns*' => Http::response(['data' => ['campaigns' => []]], 200),
        ]);

        $service = app(AccessTradeCampaignService::class);

        $this->assertNotNull($service->forHost('merchant-b.com'));
        $this->assertNotNull($service->forHost('www.merchant-b.com'));
        $this->assertNotNull($service->forHost('shop.merchant-b.com'));
        $this->assertNull($service->forHost('merchant-b.com.evil.com'));
        $this->assertNull($service->forHost('other.com'));
    }

    public function test_test_connection_reports_success_from_real_response(): void
    {
        $this->token();

        Http::fake([
            'api.accesstrade.vn/v1/campaigns*' => Http::response(['total' => 5, 'data' => [['id' => '1']]], 200),
        ]);

        $result = app(AccessTradeCampaignService::class)->testConnection();

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('5', $result['message']);
    }
}
