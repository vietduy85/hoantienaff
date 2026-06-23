<?php

namespace App\Services\Providers;

use App\Contracts\AffiliateProviderInterface;
use App\Enums\Platform;

class PharmacityProvider implements AffiliateProviderInterface
{
    public function createLink(string $url): array
    {
        return [
            'success' => true,
            'affiliate_url' => 'https://pharmacity.vn/affiliate/' . urlencode($url),
            'platform' => Platform::PHARMACITY,
            'estimated_cashback' => 7000,
            'message' => 'Link Pharmacity đã được tạo thành công.',
        ];
    }

    public function supportedPlatform(): Platform
    {
        return Platform::PHARMACITY;
    }
}
