<?php

namespace App\Services\PromotionNews\Providers;

use App\Models\PromotionNews;
use App\Services\PromotionNews\DataTransfer\PromotionNewsData;
use App\Services\PromotionNews\Exceptions\PromotionNewsProviderException;

/**
 * Kingfoodmart exposes homepage banners through `__NEXT_DATA__`
 * (`pageProps.data.banner`). Each entry carries desktop/mobile image variants
 * and a campaign subdirectory.
 */
class KingfoodmartPromotionNewsProvider extends AbstractPromotionNewsProvider
{
    protected const BASE = 'https://kingfoodmart.com';

    protected const HOME_URL = 'https://kingfoodmart.com/';

    public function source(): string
    {
        return 'kingfoodmart';
    }

    public function label(): string
    {
        return 'Kingfoodmart';
    }

    public function category(): string
    {
        return PromotionNews::CATEGORY_SUPERMARKET;
    }

    protected function homepage(): string
    {
        return self::HOME_URL;
    }

    public function getNews(): array
    {
        $data = $this->extractNextData($this->fetchHtml());

        if ($data === null) {
            throw new PromotionNewsProviderException('kingfoodmart: could not decode __NEXT_DATA__');
        }

        $banners = $data['props']['pageProps']['data']['banner'] ?? null;

        if (! is_array($banners)) {
            throw new PromotionNewsProviderException('kingfoodmart: missing banner');
        }

        $items = [];

        foreach ($banners as $banner) {
            if (! is_array($banner)) {
                continue;
            }

            $image = $this->imageUrl($banner['homeDesktopBannerImageUrl'] ?? null)
                ?? $this->imageUrl($banner['bannerImageUrl'] ?? null);

            if ($image === null) {
                continue;
            }

            $mobile = $this->imageUrl($banner['homeMobileBannerImageUrl'] ?? null)
                ?? $this->imageUrl($banner['homeAppBannerImageUrl'] ?? null);

            $landing = $this->landingUrl($banner['subdirectory'] ?? null, self::BASE)
                ?? $this->landingUrl($banner['externalLink'] ?? null, self::BASE);

            $items[] = new PromotionNewsData(
                source: $this->source(),
                category: $this->category(),
                sourceId: isset($banner['id']) ? (string) $banner['id'] : null,
                title: $this->stringOrNull($banner['ownerName'] ?? null),
                imageUrl: $image,
                mobileImageUrl: $mobile,
                landingUrl: $landing,
                isActive: ($banner['ownerStatus'] ?? null) === 'running',
                sortOrder: (int) ($banner['position'] ?? 0),
                rawData: $banner,
            );
        }

        return $items;
    }
}
