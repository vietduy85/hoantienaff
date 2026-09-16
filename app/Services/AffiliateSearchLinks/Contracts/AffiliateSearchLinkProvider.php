<?php

namespace App\Services\AffiliateSearchLinks\Contracts;

interface AffiliateSearchLinkProvider
{
    public function platform(): string;

    public function buildSearchUrl(string $keyword): string;

    /**
     * Attempt to generate an affiliate link for a search/catalog URL.
     *
     * @return array{search_url: string, affiliate_url: ?string, status: string}
     */
    public function getAffiliateSearchLink(string $keyword, ?string $username = null): array;
}
