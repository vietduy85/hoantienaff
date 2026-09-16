<?php

namespace App\Services\AffiliateSearchLinks\Providers;

use App\Services\AffiliateSearchLinks\Contracts\AffiliateSearchLinkProvider;
use App\Services\RioHub\RioHubClient;
use App\Services\RioHub\RioHubException;
use App\Services\TikTok\TikTokServiceException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TikTokAffiliateSearchLinkProvider implements AffiliateSearchLinkProvider
{
    private const CACHE_TTL = 300;

    public function __construct(
        private readonly RioHubClient $client,
    ) {}

    public function platform(): string
    {
        return 'tiktok';
    }

    public function buildSearchUrl(string $keyword): string
    {
        return 'https://shop.tiktok.com/vn/search?q=' . urlencode(trim($keyword));
    }

    public function getAffiliateSearchLink(string $keyword, ?string $username = null): array
    {
        $searchUrl = $this->buildSearchUrl($keyword);

        return Cache::remember(
            $this->cacheKey($keyword, $username),
            self::CACHE_TTL,
            fn () => $this->fetchAffiliateLink($keyword, $searchUrl, $username),
        );
    }

    private function fetchAffiliateLink(string $keyword, string $searchUrl, ?string $username): array
    {
        try {
            $response = $this->client->createAffiliateLink($searchUrl, $username);

            $data = $response->getResult();

            $affiliateUrl = $data['affiliate_link'] ?? $data['url'] ?? '';

            if ($affiliateUrl === '') {
                Log::info('[TikTokSearch] RioHub returned empty affiliate_link for search URL', [
                    'keyword'    => $keyword,
                    'search_url' => $searchUrl,
                ]);

                return [
                    'search_url'    => $searchUrl,
                    'affiliate_url' => null,
                    'status'        => 'unavailable',
                ];
            }

            return [
                'search_url'    => $searchUrl,
                'affiliate_url' => $affiliateUrl,
                'status'        => 'ready',
            ];
        } catch (RioHubException|TikTokServiceException $e) {
            Log::warning('[TikTokSearch] affiliate link creation failed for search URL', [
                'keyword'    => $keyword,
                'search_url' => $searchUrl,
                'error'      => $e->getMessage(),
            ]);

            return [
                'search_url'    => $searchUrl,
                'affiliate_url' => null,
                'status'        => 'unavailable',
            ];
        } catch (\Throwable $e) {
            Log::warning('[TikTokSearch] unexpected error for search URL', [
                'keyword'    => $keyword,
                'search_url' => $searchUrl,
                'error'      => $e->getMessage(),
            ]);

            return [
                'search_url'    => $searchUrl,
                'affiliate_url' => null,
                'status'        => 'unavailable',
            ];
        }
    }

    private function cacheKey(string $keyword, ?string $username): string
    {
        $userPart = $username ?? '';

        return 'affiliate_search:tiktok:' . md5(mb_strtolower(trim($keyword)) . '|' . $userPart);
    }
}