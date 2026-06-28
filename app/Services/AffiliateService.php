<?php

namespace App\Services;

use RuntimeException;

class AffiliateService
{
    public function __construct(
        private ProviderFactory $providerFactory,
    ) {}

    public function generateLink(string $url): array
    {
        try {
            $provider = $this->providerFactory->getProvider($url);

            return $provider->createLink($url);
        } catch (RuntimeException $e) {
            return [
                'success' => false,
                'affiliate_url' => null,
                'platform' => $this->providerFactory->detectPlatform($url),
                'estimated_cashback' => null,
                'message' => $e->getMessage(),
            ];
        }
    }
}
