<?php

namespace App\Services\Providers;

use App\Contracts\AffiliateProviderInterface;
use App\Enums\Platform;

class TravelokaProvider implements AffiliateProviderInterface
{
    public function createLink(string $url): array
    {
        return [
            'success' => true,
            'affiliate_url' => 'https://traveloka.com/affiliate/' . urlencode($url),
            'platform' => Platform::TRAVELOKA,
            'estimated_cashback' => 25000,
            'message' => 'Link Traveloka đã được tạo thành công.',
        ];
    }

    public function supportedPlatform(): Platform
    {
        return Platform::TRAVELOKA;
    }
}
