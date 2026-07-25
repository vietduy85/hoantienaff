<?php

namespace App\Services\Providers;

use App\Contracts\AffiliateProviderInterface;
use App\Enums\Platform;
use App\Services\TikTok\DTOs\TikTokAffiliateLinkDTO;
use App\Services\TikTok\TikTokAffiliateService;
use App\Services\TikTok\TikTokServiceException;

class TikTokProvider implements AffiliateProviderInterface
{
    public function __construct(
        private readonly TikTokAffiliateService $affiliateService,
    ) {}

    public function createLink(string $url, ?string $subId = null): array
    {
        try {
            /** @var TikTokAffiliateLinkDTO $dto */
            $dto = $this->affiliateService->createAffiliateLink($url, $subId);

            return [
                'success' => true,
                'affiliate_url' => $dto->getAffiliateUrl(),
                'platform' => Platform::TIKTOK,
                'estimated_cashback' => null,
                'product_id' => $dto->getProductId(),
                'product_name' => $dto->getProductName(),
                'message' => 'Link TikTok Shop đã được tạo thành công.',
            ];
        } catch (TikTokServiceException $e) {
            return [
                'success' => false,
                'affiliate_url' => null,
                'platform' => Platform::TIKTOK,
                'estimated_cashback' => null,
                'message' => $e->getRioHubMessage() ?? $e->getMessage(),
            ];
        }
    }

    public function supportedPlatform(): Platform
    {
        return Platform::TIKTOK;
    }
}
