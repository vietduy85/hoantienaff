<?php

namespace App\Services\PromotionNews\Providers;

use App\Models\PromotionNews;
use App\Services\PromotionNews\DataTransfer\PromotionNewsData;
use App\Services\PromotionNews\Exceptions\PromotionNewsProviderException;

/**
 * Bách Hóa Xanh uses the App Router: homepage data is streamed through
 * `self.__next_f.push([1, "..."])` chunks. The promotion strip lives in
 * `topCategoriesInit.categories` with path `Promotion` / `SpecialOffer` /
 * `SisV2`.
 *
 * Only concrete campaign entries (`thuong-hieu/...`) become cards; generic hub
 * links such as "Xem tất cả" / "Lấy ngay" are skipped so no misleading card is
 * created.
 */
class BhxPromotionNewsProvider extends AbstractPromotionNewsProvider
{
    protected const BASE = 'https://www.bachhoaxanh.com/';

    /**
     * @var array<int, string>
     */
    private const PROMOTION_PATHS = ['Promotion', 'SpecialOffer', 'SisV2'];

    public function source(): string
    {
        return 'bhx';
    }

    public function label(): string
    {
        return 'Bách Hóa Xanh';
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
        $flight = $this->decodeFlightData($this->fetchHtml());

        if ($flight === null) {
            throw new PromotionNewsProviderException('bhx: could not decode flight data');
        }

        $topCategories = $this->extractBalancedJsonAfter($flight, '"topCategoriesInit":');

        if ($topCategories === null) {
            throw new PromotionNewsProviderException('bhx: missing topCategoriesInit');
        }

        $categories = $topCategories['categories'] ?? null;

        if (! is_array($categories)) {
            throw new PromotionNewsProviderException('bhx: missing categories');
        }

        $items = [];

        foreach ($categories as $category) {
            if (! is_array($category) || ! $this->isPromotionCampaign($category)) {
                continue;
            }

            $image = $this->imageUrl($category['icon'] ?? null);

            if ($image === null) {
                continue;
            }

            $items[] = new PromotionNewsData(
                source: $this->source(),
                category: $this->category(),
                sourceId: isset($category['id']) ? (string) $category['id'] : null,
                title: $this->stringOrNull($category['name'] ?? null),
                imageUrl: $image,
                landingUrl: $this->landingUrl($category['url'] ?? null, self::BASE),
                isActive: true,
                rawData: $category,
            );
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function isPromotionCampaign(array $category): bool
    {
        if (! in_array($category['path'] ?? null, self::PROMOTION_PATHS, true)) {
            return false;
        }

        $url = $this->stringOrNull($category['url'] ?? null);

        return $url !== null && str_starts_with($url, 'thuong-hieu/');
    }
}
