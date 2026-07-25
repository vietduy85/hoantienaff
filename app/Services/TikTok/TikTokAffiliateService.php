<?php

namespace App\Services\TikTok;

use App\Services\RioHub\RioHubClient;
use App\Services\RioHub\RioHubException;
use App\Services\TikTok\DTOs\TikTokAffiliateLinkDTO;

class TikTokAffiliateService
{
    public function __construct(
        private readonly RioHubClient $client,
    ) {}

    /**
     * Create a TikTok affiliate link via RioHub.
     *
     * @param  string  $productUrl  The TikTok product URL to monetize.
     * @param  string|null  $subId  Optional sub-id override.
     *
     * @throws TikTokServiceException On API errors.
     */
    public function createAffiliateLink(string $productUrl, ?string $subId = null): TikTokAffiliateLinkDTO
    {
        $this->validateUrl($productUrl);

        try {
            $response = $this->client->createAffiliateLink($productUrl, $subId);
        } catch (RioHubException $e) {
            throw TikTokServiceException::fromRioHubException($e, 'createAffiliateLink');
        }

        $data = $response->getResult();

        if (empty($data['affiliate_url']) && empty($data['url'])) {
            throw new TikTokServiceException(
                '[createAffiliateLink] RioHub returned empty affiliate_url',
                0,
            );
        }

        return TikTokAffiliateLinkDTO::fromRioHubResponse($data, originalUrl: $productUrl);
    }

    private function validateUrl(string $url): void
    {
        if (trim($url) === '') {
            throw new TikTokServiceException('[createAffiliateLink] URL cannot be empty');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new TikTokServiceException("[createAffiliateLink] Invalid URL: {$url}");
        }
    }
}
