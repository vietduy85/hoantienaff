<?php

namespace Tests\Feature\PriceComparison;

use App\Services\AffiliateSearchLinks\Contracts\AffiliateSearchLinkProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AffiliateSearchLinksApiTest extends TestCase
{
    private const SEARCH_URL_SHOPEE  = 'https://shopee.vn/search?keyword=test';
    private const SEARCH_URL_LAZADA  = 'https://www.lazada.vn/catalog/?q=test';
    private const SEARCH_URL_TIKTOK  = 'https://shop.tiktok.com/vn/search?q=test';

    private function fakeSuccessProviders(): void
    {
        $fakeShopee = $this->createMock(AffiliateSearchLinkProvider::class);
        $fakeShopee->method('platform')->willReturn('shopee');
        $fakeShopee->method('buildSearchUrl')->willReturnCallback(
            fn (string $keyword) => 'https://shopee.vn/search?keyword=' . urlencode(trim($keyword)),
        );
        $fakeShopee->method('getAffiliateSearchLink')->willReturnCallback(function (string $keyword) {
            $searchUrl = 'https://shopee.vn/search?keyword=' . urlencode(trim($keyword));

            return [
                'search_url'    => $searchUrl,
                'affiliate_url' => null,
                'status'        => 'unavailable',
            ];
        });

        $fakeLazada = $this->createMock(AffiliateSearchLinkProvider::class);
        $fakeLazada->method('platform')->willReturn('lazada');
        $fakeLazada->method('buildSearchUrl')->willReturnCallback(
            fn (string $keyword) => 'https://www.lazada.vn/catalog/?q=' . urlencode(trim($keyword)),
        );
        $fakeLazada->method('getAffiliateSearchLink')->willReturnCallback(function (string $keyword) {
            $searchUrl = 'https://www.lazada.vn/catalog/?q=' . urlencode(trim($keyword));

            return [
                'search_url'    => $searchUrl,
                'affiliate_url' => 'https://c.lazada.vn/t/c.ABC?subId1=tester',
                'status'        => 'ready',
            ];
        });

        $fakeTiktok = $this->createMock(AffiliateSearchLinkProvider::class);
        $fakeTiktok->method('platform')->willReturn('tiktok');
        $fakeTiktok->method('buildSearchUrl')->willReturnCallback(
            fn (string $keyword) => 'https://shop.tiktok.com/vn/search?q=' . urlencode(trim($keyword)),
        );
        $fakeTiktok->method('getAffiliateSearchLink')->willReturnCallback(function (string $keyword) {
            $searchUrl = 'https://shop.tiktok.com/vn/search?q=' . urlencode(trim($keyword));

            return [
                'search_url'    => $searchUrl,
                'affiliate_url' => 'https://riohub.vn/aff/xyz',
                'status'        => 'ready',
            ];
        });

        $this->app->bind(\App\Services\AffiliateSearchLinks\AffiliateSearchLinkManager::class, function () use ($fakeShopee, $fakeLazada, $fakeTiktok) {
            return new \App\Services\AffiliateSearchLinks\AffiliateSearchLinkManager([
                $fakeShopee,
                $fakeLazada,
                $fakeTiktok,
            ]);
        });
    }

    private function fakeUnavailableProvider(string $platform): void
    {
        $fake = $this->createMock(AffiliateSearchLinkProvider::class);
        $fake->method('platform')->willReturn($platform);
        $fake->method('buildSearchUrl')->willReturn("https://example.com/{$platform}/search?q=test");
        $fake->method('getAffiliateSearchLink')->willReturn([
            'search_url'    => "https://example.com/{$platform}/search?q=test",
            'affiliate_url' => null,
            'status'        => 'unavailable',
        ]);

        $this->app->bind(\App\Services\AffiliateSearchLinks\AffiliateSearchLinkManager::class, function () use ($fake) {
            return new \App\Services\AffiliateSearchLinks\AffiliateSearchLinkManager([$fake]);
        });
    }

    private function fakeBrokenProvider(): void
    {
        $fake = $this->createMock(AffiliateSearchLinkProvider::class);
        $fake->method('platform')->willReturn('shopee');
        $fake->method('buildSearchUrl')->willReturn('https://shopee.vn/search?keyword=test');
        $fake->method('getAffiliateSearchLink')->willThrowException(new \RuntimeException('boom'));

        $this->app->bind(\App\Services\AffiliateSearchLinks\AffiliateSearchLinkManager::class, function () use ($fake) {
            return new \App\Services\AffiliateSearchLinks\AffiliateSearchLinkManager([$fake]);
        });
    }

    public function test_endpoint_returns_200_with_keyword_and_links(): void
    {
        $this->fakeSuccessProviders();

        $response = $this->getJson('/api/price-comparison/affiliate-search-links?keyword=vinamilk');

        $response->assertOk()
            ->assertJsonPath('keyword', 'vinamilk')
            ->assertJsonCount(3, 'links')
            ->assertJsonStructure([
                'keyword',
                'links' => [
                    'shopee' => ['search_url', 'affiliate_url', 'status'],
                    'lazada' => ['search_url', 'affiliate_url', 'status'],
                    'tiktok' => ['search_url', 'affiliate_url', 'status'],
                ],
            ]);
    }

    public function test_lazada_ready_link_in_response(): void
    {
        $this->fakeSuccessProviders();

        $this->getJson('/api/price-comparison/affiliate-search-links?keyword=test')
            ->assertOk()
            ->assertJsonPath('links.lazada.affiliate_url', 'https://c.lazada.vn/t/c.ABC?subId1=tester')
            ->assertJsonPath('links.lazada.status', 'ready')
            ->assertJsonPath('links.lazada.search_url', self::SEARCH_URL_LAZADA);
    }

    public function test_shopee_returns_unavailable(): void
    {
        $this->fakeSuccessProviders();

        $this->getJson('/api/price-comparison/affiliate-search-links?keyword=test')
            ->assertOk()
            ->assertJsonPath('links.shopee.affiliate_url', null)
            ->assertJsonPath('links.shopee.status', 'unavailable')
            ->assertJsonPath('links.shopee.search_url', self::SEARCH_URL_SHOPEE);
    }

    public function test_tiktok_unavailable_returns_search_url_only(): void
    {
        $this->fakeUnavailableProvider('tiktok');

        $this->getJson('/api/price-comparison/affiliate-search-links?keyword=test')
            ->assertOk()
            ->assertJsonPath('links.tiktok.affiliate_url', null)
            ->assertJsonPath('links.tiktok.status', 'unavailable')
            ->assertJsonCount(1, 'links');
    }

    public function test_broken_provider_still_returns_200_with_unavailable(): void
    {
        $this->fakeBrokenProvider();

        $response = $this->getJson('/api/price-comparison/affiliate-search-links?keyword=test');

        $response->assertOk()
            ->assertJsonPath('links.shopee.affiliate_url', null)
            ->assertJsonPath('links.shopee.status', 'unavailable')
            ->assertJsonPath('keyword', 'test');
    }

    public function test_keyword_is_url_encoded_in_search_url(): void
    {
        $this->fakeSuccessProviders();

        $response = $this->getJson('/api/price-comparison/affiliate-search-links?keyword=n%C6%B0%E1%BB%9Bc+m%E1%BA%AFm');

        $response->assertOk()
            ->assertJsonPath('keyword', 'nước mắm')
            ->assertJsonPath('links.shopee.search_url', 'https://shopee.vn/search?keyword=' . urlencode('nước mắm'))
            ->assertJsonPath('links.lazada.search_url', 'https://www.lazada.vn/catalog/?q=' . urlencode('nước mắm'))
            ->assertJsonPath('links.tiktok.search_url', 'https://shop.tiktok.com/vn/search?q=' . urlencode('nước mắm'));
    }

    public function test_missing_keyword_returns_422(): void
    {
        $this->getJson('/api/price-comparison/affiliate-search-links')
            ->assertStatus(422);
    }

    public function test_empty_keyword_returns_422(): void
    {
        $this->getJson('/api/price-comparison/affiliate-search-links?keyword=')
            ->assertStatus(422);
    }

    public function test_anonymous_user_gets_no_tracking_in_lazada_link(): void
    {
        $capturedSubId1 = null;

        $fakeLazada = $this->createMock(AffiliateSearchLinkProvider::class);
        $fakeLazada->method('platform')->willReturn('lazada');
        $fakeLazada->method('buildSearchUrl')->willReturn(self::SEARCH_URL_LAZADA);
        $fakeLazada->method('getAffiliateSearchLink')->willReturnCallback(
            function (string $keyword, ?string $username) use (&$capturedSubId1) {
                $capturedSubId1 = $username;

                return [
                    'search_url'    => self::SEARCH_URL_LAZADA,
                    'affiliate_url' => 'https://c.lazada.vn/t/c.ANON',
                    'status'        => 'ready',
                ];
            }
        );

        $this->app->bind(\App\Services\AffiliateSearchLinks\AffiliateSearchLinkManager::class, function () use ($fakeLazada) {
            return new \App\Services\AffiliateSearchLinks\AffiliateSearchLinkManager([$fakeLazada]);
        });

        $this->getJson('/api/price-comparison/affiliate-search-links?keyword=test')
            ->assertOk()
            ->assertJsonPath('links.lazada.affiliate_url', 'https://c.lazada.vn/t/c.ANON');

        $this->assertNull($capturedSubId1);
    }

    public function test_one_platform_unavailable_does_not_affect_others(): void
    {
        $brokenLazada = $this->createMock(AffiliateSearchLinkProvider::class);
        $brokenLazada->method('platform')->willReturn('lazada');
        $brokenLazada->method('buildSearchUrl')->willReturn(self::SEARCH_URL_LAZADA);
        $brokenLazada->method('getAffiliateSearchLink')->willReturn([
            'search_url'    => self::SEARCH_URL_LAZADA,
            'affiliate_url' => null,
            'status'        => 'unavailable',
        ]);

        $okTiktok = $this->createMock(AffiliateSearchLinkProvider::class);
        $okTiktok->method('platform')->willReturn('tiktok');
        $okTiktok->method('buildSearchUrl')->willReturn(self::SEARCH_URL_TIKTOK);
        $okTiktok->method('getAffiliateSearchLink')->willReturn([
            'search_url'    => self::SEARCH_URL_TIKTOK,
            'affiliate_url' => 'https://riohub.vn/aff/ok',
            'status'        => 'ready',
        ]);

        $this->app->bind(\App\Services\AffiliateSearchLinks\AffiliateSearchLinkManager::class, function () use ($brokenLazada, $okTiktok) {
            return new \App\Services\AffiliateSearchLinks\AffiliateSearchLinkManager([$brokenLazada, $okTiktok]);
        });

        $this->getJson('/api/price-comparison/affiliate-search-links?keyword=test')
            ->assertOk()
            ->assertJsonPath('links.lazada.status', 'unavailable')
            ->assertJsonPath('links.tiktok.status', 'ready')
            ->assertJsonPath('links.tiktok.affiliate_url', 'https://riohub.vn/aff/ok');
    }

    public function test_credentials_never_exposed_in_response(): void
    {
        $this->fakeSuccessProviders();

        $response = $this->getJson('/api/price-comparison/affiliate-search-links?keyword=test');
        $content = $response->getContent();

        $this->assertStringNotContainsString('secret', strtolower($content));
        $this->assertStringNotContainsString('app_key', $content);
        $this->assertStringNotContainsString('api_key', $content);
        $this->assertStringNotContainsString('user_token', $content);
        $this->assertStringNotContainsString('105000', $content);
    }
}