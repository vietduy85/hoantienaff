<?php

namespace App\Services\Providers;

use App\Contracts\AffiliateProviderInterface;
use App\Enums\Platform;

class LazadaProvider implements AffiliateProviderInterface
{
    public function createLink(string $url): array
    {
        return [
            'success' => true,
            'affiliate_url' => 'https://lazada.vn/affiliate/' . urlencode($url),
            'platform' => Platform::LAZADA,
            'estimated_cashback' => null,
            'message' => 'Link Lazada đã được tạo thành công.',
        ];
    }

    public function supportedPlatform(): Platform
    {
        return Platform::LAZADA;
    }
}
