<?php

namespace App\Services\Providers;

use App\Contracts\AffiliateProviderInterface;
use App\Enums\Platform;
use App\Services\AffiliateWorkerClient;

class ShopeeProvider implements AffiliateProviderInterface
{
    public function __construct(
        private AffiliateWorkerClient $worker,
    ) {}

    public function createLink(string $url): array
    {
        $result = $this->worker->createLink($url);

        return [
            'success' => $result['success'] ?? false,
            'affiliate_url' => $result['affiliate_url'] ?? null,
            'platform' => Platform::SHOPEE,
            'estimated_cashback' => $result['estimated_cashback'] ?? 0,
            'message' => $result['success']
                ? 'Link Shopee đã được tạo thành công.'
                : ($result['error'] ?? 'Worker không phản hồi.'),
        ];
    }

    public function supportedPlatform(): Platform
    {
        return Platform::SHOPEE;
    }
}
