<?php

namespace App\Services\Providers;

use App\Contracts\AffiliateProviderInterface;
use App\Enums\Platform;

class LongChauProvider implements AffiliateProviderInterface
{
    public function createLink(string $url): array
    {
        return [
            'success' => true,
            'affiliate_url' => 'https://nhathuoclongchau.com.vn/affiliate/' . urlencode($url),
            'platform' => Platform::LONG_CHAU,
            'estimated_cashback' => 8000,
            'message' => 'Link Nhà Thuốc Long Châu đã được tạo thành công.',
        ];
    }

    public function supportedPlatform(): Platform
    {
        return Platform::LONG_CHAU;
    }
}
