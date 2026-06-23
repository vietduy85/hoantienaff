<?php

namespace App\Services\Providers;

use App\Contracts\AffiliateProviderInterface;
use App\Enums\Platform;

class BookingProvider implements AffiliateProviderInterface
{
    public function createLink(string $url): array
    {
        return [
            'success' => true,
            'affiliate_url' => 'https://booking.com/affiliate/' . urlencode($url),
            'platform' => Platform::BOOKING,
            'estimated_cashback' => 35000,
            'message' => 'Link Booking.com đã được tạo thành công.',
        ];
    }

    public function supportedPlatform(): Platform
    {
        return Platform::BOOKING;
    }
}
