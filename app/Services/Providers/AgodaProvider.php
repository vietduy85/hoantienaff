<?php

namespace App\Services\Providers;

use App\Contracts\AffiliateProviderInterface;
use App\Enums\Platform;

class AgodaProvider implements AffiliateProviderInterface
{
    public function createLink(string $url): array
    {
        return [
            'success' => true,
            'affiliate_url' => 'https://agoda.com/affiliate/' . urlencode($url),
            'platform' => Platform::AGODA,
            'estimated_cashback' => null,
            'message' => 'Link Agoda đã được tạo thành công.',
        ];
    }

    public function supportedPlatform(): Platform
    {
        return Platform::AGODA;
    }
}
