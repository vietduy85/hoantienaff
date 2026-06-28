<?php

namespace App\Services\Providers;

use App\Contracts\AffiliateProviderInterface;
use App\Enums\Platform;

class TikTokProvider implements AffiliateProviderInterface
{
    public function createLink(string $url): array
    {
        return [
            'success' => true,
            'affiliate_url' => 'https://tiktok.com/affiliate/' . urlencode($url),
            'platform' => Platform::TIKTOK,
            'estimated_cashback' => null,
            'message' => 'Link TikTok Shop đã được tạo thành công.',
        ];
    }

    public function supportedPlatform(): Platform
    {
        return Platform::TIKTOK;
    }
}
