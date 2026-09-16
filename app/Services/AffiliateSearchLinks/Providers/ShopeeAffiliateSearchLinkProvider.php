<?php

namespace App\Services\AffiliateSearchLinks\Providers;

use App\Services\AffiliateSearchLinks\Contracts\AffiliateSearchLinkProvider;

class ShopeeAffiliateSearchLinkProvider implements AffiliateSearchLinkProvider
{
    public function platform(): string
    {
        return 'shopee';
    }

    public function buildSearchUrl(string $keyword): string
    {
        return 'https://shopee.vn/search?keyword=' . urlencode(trim($keyword));
    }

    public function getAffiliateSearchLink(string $keyword, ?string $username = null): array
    {
        $searchUrl = $this->buildSearchUrl($keyword);

        return [
            'search_url'   => $searchUrl,
            'affiliate_url' => null,
            'status'       => 'unavailable',
        ];
    }
}
