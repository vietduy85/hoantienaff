<?php

namespace Tests\Unit\Services\AffiliateSearchLinks;

use App\Services\AffiliateSearchLinks\AffiliateSearchLinkManager;
use App\Services\AffiliateSearchLinks\Contracts\AffiliateSearchLinkProvider;
use RuntimeException;
use Tests\TestCase;

class AffiliateSearchLinkManagerTest extends TestCase
{
    private function fakeProvider(string $platform, array $result, ?RuntimeException $throw = null): AffiliateSearchLinkProvider
    {
        $provider = $this->createMock(AffiliateSearchLinkProvider::class);
        $provider->method('platform')->willReturn($platform);
        $provider->method('buildSearchUrl')->willReturn($result['search_url']);

        if ($throw !== null) {
            $provider->method('getAffiliateSearchLink')->willThrowException($throw);
        } else {
            $provider->method('getAffiliateSearchLink')->willReturn($result);
        }

        return $provider;
    }

    public function test_get_links_returns_normalized_links_for_all_providers(): void
    {
        $manager = new AffiliateSearchLinkManager([
            $this->fakeProvider('shopee', [
                'search_url' => 'https://shopee.vn/search?keyword=vinamilk',
                'affiliate_url' => null,
                'status' => 'unavailable',
            ]),
            $this->fakeProvider('lazada', [
                'search_url' => 'https://www.lazada.vn/catalog/?q=vinamilk',
                'affiliate_url' => 'https://c.lazada.vn/t/abc',
                'status' => 'ready',
            ]),
        ]);

        $links = $manager->getLinks('vinamilk');

        $this->assertSame('https://c.lazada.vn/t/abc', $links['lazada']['affiliate_url']);
        $this->assertSame('ready', $links['lazada']['status']);
        $this->assertNull($links['shopee']['affiliate_url']);
        $this->assertSame('unavailable', $links['shopee']['status']);
    }

    public function test_one_provider_failure_does_not_affect_others(): void
    {
        $manager = new AffiliateSearchLinkManager([
            $this->fakeProvider('shopee', [
                'search_url' => 'https://shopee.vn/search?keyword=vinamilk',
                'affiliate_url' => null,
                'status' => 'unavailable',
            ], new RuntimeException('extension broke')),
            $this->fakeProvider('lazada', [
                'search_url' => 'https://www.lazada.vn/catalog/?q=vinamilk',
                'affiliate_url' => 'https://c.lazada.vn/t/ok',
                'status' => 'ready',
            ]),
            $this->fakeProvider('tiktok', [
                'search_url' => 'https://shop.tiktok.com/vn/search?q=vinamilk',
                'affiliate_url' => null,
                'status' => 'unavailable',
            ]),
        ]);

        $links = $manager->getLinks('vinamilk');

        $this->assertSame('ready', $links['lazada']['status']);
        $this->assertSame('https://c.lazada.vn/t/ok', $links['lazada']['affiliate_url']);
        $this->assertSame('unavailable', $links['shopee']['status']);
        $this->assertSame('unavailable', $links['tiktok']['status']);
        $this->assertSame('https://shopee.vn/search?keyword=vinamilk', $links['shopee']['search_url']);
    }

    public function test_exception_provider_still_exposes_search_url(): void
    {
        $provider = $this->createMock(AffiliateSearchLinkProvider::class);
        $provider->method('platform')->willReturn('lazada');
        $provider->method('buildSearchUrl')->willReturn('https://www.lazada.vn/catalog/?q=vinamilk');
        $provider->method('getAffiliateSearchLink')->willThrowException(new RuntimeException('boom'));

        $manager = new AffiliateSearchLinkManager([$provider]);

        $links = $manager->getLinks('vinamilk');

        $this->assertSame('https://www.lazada.vn/catalog/?q=vinamilk', $links['lazada']['search_url']);
        $this->assertNull($links['lazada']['affiliate_url']);
        $this->assertSame('unavailable', $links['lazada']['status']);
    }

    public function test_username_is_forwarded_to_providers(): void
    {
        $captured = [];

        $provider = $this->createMock(AffiliateSearchLinkProvider::class);
        $provider->method('platform')->willReturn('shopee');
        $provider->method('buildSearchUrl')->willReturn('https://shopee.vn/search?keyword=vinamilk');
        $provider
            ->method('getAffiliateSearchLink')
            ->willReturnCallback(function (string $keyword, ?string $username) use (&$captured) {
                $captured = [$keyword, $username];

                return ['search_url' => 'x', 'affiliate_url' => null, 'status' => 'unavailable'];
            });

        $manager = new AffiliateSearchLinkManager([$provider]);

        $manager->getLinks('vinamilk', 'tester');

        $this->assertSame(['vinamilk', 'tester'], $captured);
    }
}