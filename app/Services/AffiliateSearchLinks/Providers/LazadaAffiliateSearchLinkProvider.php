<?php

namespace App\Services\AffiliateSearchLinks\Providers;

use App\Services\AffiliateSearchLinks\Contracts\AffiliateSearchLinkProvider;
use App\Services\Lazada\LazadaApiClient;
use App\Services\Lazada\LazadaException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LazadaAffiliateSearchLinkProvider implements AffiliateSearchLinkProvider
{
    private const CACHE_TTL = 300;

    public function __construct(
        private readonly LazadaApiClient $client,
    ) {}

    public function platform(): string
    {
        return 'lazada';
    }

    public function buildSearchUrl(string $keyword): string
    {
        return 'https://www.lazada.vn/catalog/?q=' . urlencode(trim($keyword));
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
            $payload = $this->client->request('marketing/getlink', [
                'inputType'  => 'url',
                'inputValue' => $searchUrl,
                'subId1'     => $username ?? '',
            ]);

            $data = $payload['result']['data'] ?? $payload['data'] ?? [];
            $list = $data['urlBatchGetLinkInfoList'] ?? $data['batchGetLinkInfoList'] ?? [];
            $item = is_array($list) ? (reset($list) ?: null) : null;

            if (!is_array($item)) {
                Log::info('[LazadaSearch] getlink returned no item for search URL', [
                    'keyword'    => $keyword,
                    'search_url' => $searchUrl,
                ]);

                return [
                    'search_url'    => $searchUrl,
                    'affiliate_url' => null,
                    'status'        => 'unavailable',
                ];
            }

            $affiliateUrl = (string) ($item['offerPromotionLink'] ?? $item['regularPromotionLink'] ?? '');

            if ($affiliateUrl === '') {
                Log::info('[LazadaSearch] getlink returned empty promotion link', [
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
        } catch (LazadaException $e) {
            Log::warning('[LazadaSearch] getlink failed for search URL', [
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
            Log::warning('[LazadaSearch] unexpected error for search URL', [
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

        return 'affiliate_search:lazada:' . md5(mb_strtolower(trim($keyword)) . '|' . $userPart);
    }
}