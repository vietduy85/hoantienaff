<?php

namespace App\Services\PromotionNews\Providers;

use App\Models\PromotionNews;
use App\Services\PromotionNews\DataTransfer\PromotionNewsData;
use App\Services\PromotionNews\Exceptions\PromotionNewsProviderException;

/**
 * WinMart exposes homepage header banners through `__NEXT_DATA__`
 * (`pageProps.dataHome.headerBanner`).
 *
 * Banner `name` values are frequently internal campaign codes (e.g.
 * "CLEAR BW_Home banner_17 - 23/09"), so the title falls back to null instead
 * of showing an internal code to customers.
 */
class WinMartPromotionNewsProvider extends AbstractPromotionNewsProvider
{
    protected const BASE = 'https://winmart.vn/';

    protected const INTERNAL_NAME_PATTERN = '/\bBW[_ ]|home banner|sub banner|^\s*sub\b|\bbanner\b|\bads\b/i';

    public function source(): string
    {
        return 'winmart';
    }

    public function label(): string
    {
        return 'WinMart';
    }

    public function category(): string
    {
        return PromotionNews::CATEGORY_SUPERMARKET;
    }

    protected function homepage(): string
    {
        return self::BASE;
    }

    public function getNews(): array
    {
        $data = $this->extractNextData($this->fetchHtml());

        if ($data === null) {
            throw new PromotionNewsProviderException('winmart: could not decode __NEXT_DATA__');
        }

        $banners = $data['props']['pageProps']['dataHome']['headerBanner'] ?? null;

        if (! is_array($banners)) {
            throw new PromotionNewsProviderException('winmart: missing headerBanner');
        }

        $items = [];

        foreach ($banners as $banner) {
            if (! is_array($banner)) {
                continue;
            }

            $image = $this->imageUrl($banner['imageUrl'] ?? null);

            if ($image === null) {
                continue;
            }

            $landing = $this->landingUrl($banner['url'] ?? null, self::BASE);

            $items[] = new PromotionNewsData(
                source: $this->source(),
                category: $this->category(),
                sourceId: isset($banner['id']) ? (string) $banner['id'] : null,
                title: $this->customerFacingTitle($banner),
                imageUrl: $image,
                landingUrl: $landing,
                isActive: true,
                rawData: $banner,
            );
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $banner
     */
    private function customerFacingTitle(array $banner): ?string
    {
        foreach (['subTitle', 'name'] as $key) {
            $value = $this->stringOrNull($banner[$key] ?? null);

            if ($value !== null && ! $this->looksInternal($value)) {
                return $value;
            }
        }

        return null;
    }

    private function looksInternal(string $value): bool
    {
        return (bool) preg_match(self::INTERNAL_NAME_PATTERN, $value);
    }
}
