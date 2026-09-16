<?php

namespace App\Services\AffiliateSearchLinks;

use App\Services\AffiliateSearchLinks\Contracts\AffiliateSearchLinkProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

class AffiliateSearchLinkManager
{
    private array $providers = [];

    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(AffiliateSearchLinkProvider $provider): void
    {
        $this->providers[$provider->platform()] = $provider;
    }

    public function getLinks(string $keyword, ?string $username = null): array
    {
        $links = [];

        foreach ($this->providers as $platform => $provider) {
            try {
                $links[$platform] = $provider->getAffiliateSearchLink($keyword, $username);
            } catch (Throwable $e) {
                Log::warning("[AffiliateSearchLinkManager] {$platform} provider failed", [
                    'keyword' => $keyword,
                    'error'   => $e->getMessage(),
                ]);

                $links[$platform] = [
                    'search_url'    => $provider->buildSearchUrl($keyword),
                    'affiliate_url' => null,
                    'status'        => 'unavailable',
                ];
            }
        }

        return $links;
    }
}
