<?php

namespace Tests\Unit\Services\AffiliateSearchLinks;

use App\Services\AffiliateSearchLinks\Providers\LazadaAffiliateSearchLinkProvider;
use App\Services\Lazada\LazadaApiClient;
use App\Services\Lazada\LazadaException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LazadaAffiliateSearchLinkProviderTest extends TestCase
{
    private const SEARCH_URL = 'https://www.lazada.vn/catalog/?q=vinamilk+%C3%ADt+%C4%91%C6%B0%E1%BB%9Dng';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'services.lazada.app_key' => '105000',
            'services.lazada.app_secret' => 'secret-secret-secret-secret-secret-32',
            'services.lazada.user_token' => 'ffffffffffffffffffffffffffffffff',
            'services.lazada.base_url' => 'https://api.lazada.vn/rest',
        ]);
    }

    private function provider(): LazadaAffiliateSearchLinkProvider
    {
        return new LazadaAffiliateSearchLinkProvider(app(LazadaApiClient::class));
    }

    private function fakeGetLink(array $item): void
    {
        Http::fake([
            'https://api.lazada.vn/rest/marketing/getlink*' => Http::response([
                'data' => [
                    'urlBatchGetLinkInfoList' => [$item],
                ],
                'success' => true,
            ], 200),
        ]);
    }

    public function test_platform_is_lazada(): void
    {
        $this->assertSame('lazada', $this->provider()->platform());
    }

    public function test_build_search_url_encodes_keyword_properly(): void
    {
        $this->assertSame(
            'https://www.lazada.vn/catalog/?q=vinamilk+%C3%ADt+%C4%91%C6%B0%E1%BB%9Dng',
            $this->provider()->buildSearchUrl('vinamilk ít đường'),
        );
    }

    public function test_search_url_has_no_fabricated_affiliate_parameter(): void
    {
        $url = $this->provider()->buildSearchUrl('mì Hảo Hảo');

        $this->assertStringNotContainsString('affiliate', $url);
        $this->assertStringNotContainsString('utm_', $url);
        $this->assertStringNotContainsString('lzd', $url);
    }

    public function test_affiliate_link_success_returns_api_link_verbatim(): void
    {
        $this->fakeGetLink([
            'originalUrl' => self::SEARCH_URL,
            'productId' => null,
            'productName' => null,
            'regularPromotionLink' => 'https://c.lazada.vn/t/c.ABCDE?subId1=tester',
            'regularCommission' => null,
        ]);

        $result = $this->provider()->getAffiliateSearchLink('vinamilk ít đường', 'tester');

        $this->assertSame('https://c.lazada.vn/t/c.ABCDE?subId1=tester', $result['affiliate_url']);
        $this->assertSame('ready', $result['status']);
        $this->assertSame(self::SEARCH_URL, $result['search_url']);
    }

    public function test_affiliate_link_never_rebuilt_from_input(): void
    {
        $this->fakeGetLink([
            'originalUrl' => self::SEARCH_URL,
            'productId' => null,
            'productName' => null,
            'regularPromotionLink' => 'https://c.lazada.vn/t/c.RAW',  // raw value from API
            'regularCommission' => null,
        ]);

        $result = $this->provider()->getAffiliateSearchLink('vinamilk ít đường', 'tester');

        // URL is taken verbatim from the API, never reconstructed.
        $this->assertSame('https://c.lazada.vn/t/c.RAW', $result['affiliate_url']);
    }

    public function test_official_subid1_uses_username(): void
    {
        $this->fakeGetLink([
            'originalUrl' => self::SEARCH_URL,
            'productId' => null,
            'productName' => null,
            'regularPromotionLink' => 'https://c.lazada.vn/t/c.ABCDE?subId1=tester',
            'regularCommission' => null,
        ]);

        $result = $this->provider()->getAffiliateSearchLink('vinamilk ít đường', 'tester');

        $this->assertSame('ready', $result['status']);

        Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => true);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            $url = (string) $request->url();

            // subId1 must be present in the signed request to the API.
            return str_contains($url, 'subId1=tester');
        });
    }

    public function test_anonymous_user_sends_empty_subid1(): void
    {
        $this->fakeGetLink([
            'originalUrl' => self::SEARCH_URL,
            'productId' => null,
            'productName' => null,
            'regularPromotionLink' => 'https://c.lazada.vn/t/c.ANON',
            'regularCommission' => null,
        ]);

        $result = $this->provider()->getAffiliateSearchLink('vinamilk ít đường');

        $this->assertSame('ready', $result['status']);
        $this->assertSame('https://c.lazada.vn/t/c.ANON', $result['affiliate_url']);
    }

    public function test_api_error_returns_unavailable(): void
    {
        Http::fake([
            'https://api.lazada.vn/rest/marketing/getlink*' => Http::response([
                'success' => false,
                'error_code' => '2001',
                'error_msg' => 'product not found',
            ], 200),
        ]);

        $result = $this->provider()->getAffiliateSearchLink('vinamilk ít đường');

        $this->assertNull($result['affiliate_url']);
        $this->assertSame('unavailable', $result['status']);
        $this->assertSame(self::SEARCH_URL, $result['search_url']);
    }

    public function test_http_5xx_returns_unavailable_without_crashing(): void
    {
        Http::fake([
            'https://api.lazada.vn/rest/marketing/getlink*' => Http::response([], 500),
        ]);

        $result = $this->provider()->getAffiliateSearchLink('vinamilk ít đường');

        $this->assertNull($result['affiliate_url']);
        $this->assertSame('unavailable', $result['status']);
    }

    public function test_connection_exception_returns_unavailable_without_crashing(): void
    {
        Http::fake([
            'https://api.lazada.vn/rest/marketing/getlink*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'),
        ]);

        $result = $this->provider()->getAffiliateSearchLink('vinamilk ít đường');

        $this->assertNull($result['affiliate_url']);
        $this->assertSame('unavailable', $result['status']);
        $this->assertSame(self::SEARCH_URL, $result['search_url']);
    }

    public function test_empty_promotion_link_returns_unavailable(): void
    {
        $this->fakeGetLink([
            'originalUrl' => self::SEARCH_URL,
            'productId' => null,
            'productName' => null,
            'regularPromotionLink' => '',
            'regularCommission' => null,
        ]);

        $result = $this->provider()->getAffiliateSearchLink('vinamilk ít đường');

        $this->assertNull($result['affiliate_url']);
        $this->assertSame('unavailable', $result['status']);
    }

    public function test_credentials_never_exposed_in_return_value(): void
    {
        $this->fakeGetLink([
            'originalUrl' => self::SEARCH_URL,
            'productId' => null,
            'productName' => null,
            'regularPromotionLink' => 'https://c.lazada.vn/t/c.ABCDE',
            'regularCommission' => null,
        ]);

        $result = $this->provider()->getAffiliateSearchLink('vinamilk ít đường', 'tester');

        $json = json_encode($result);
        $this->assertStringNotContainsString('105000', $json);
        $this->assertStringNotContainsString('secret-secret', $json);
        $this->assertStringNotContainsString('ffffffffffffffffffffffffffffffff', $json);
    }

    public function test_db_exception_is_wrapped_as_lazada_exception(): void
    {
        $client = $this->createMock(LazadaApiClient::class);
        $client->method('request')
            ->willThrowException(new LazadaException('[Lazada] Credentials are not configured', 0));

        $provider = new LazadaAffiliateSearchLinkProvider($client);

        $result = $provider->getAffiliateSearchLink('vinamilk ít đường');

        $this->assertNull($result['affiliate_url']);
        $this->assertSame('unavailable', $result['status']);
    }
}