<?php

namespace App\Services\PromotionNews\Providers;

use App\Models\PromotionNews;
use App\Services\PromotionNews\DataTransfer\PromotionNewsData;
use App\Services\PromotionNews\Exceptions\PromotionNewsProviderException;

/**
 * Co.op Online (cooponline.vn) stores every homepage block inside the
 * `props.pageProps.pbContent` map of its Pages Router `__NEXT_DATA__`.
 *
 * Promotions live in `omni_voucherlist` blocks ("E-voucher khuyến mãi",
 * "SĂN VOUCHER GIỜ VÀNG", ...). The `omni_bannerslider_2` "Tin tức" block is
 * corporate news, so it is intentionally ignored: only entries that carry a
 * voucher image, a destination link and a machine-readable campaign id (or a
 * deterministic id derived from them) become cards.
 */
class CoopPromotionNewsProvider extends AbstractPromotionNewsProvider
{
    protected const BASE = 'https://cooponline.vn/';

    protected const VOUCHER_BLOCK_TYPE = 'omni_voucherlist';

    public function source(): string
    {
        return 'coop';
    }

    public function label(): string
    {
        return 'Co.op Online';
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
            throw new PromotionNewsProviderException('coop: could not decode __NEXT_DATA__');
        }

        $blocks = $data['props']['pageProps']['pbContent'] ?? null;

        if (! is_array($blocks)) {
            throw new PromotionNewsProviderException('coop: missing pbContent');
        }

        $items = [];
        $seen = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $customAttributes = $block['customAttributes'] ?? null;

            if (! is_array($customAttributes)) {
                continue;
            }

            foreach ($customAttributes as $type => $list) {
                if ($type !== self::VOUCHER_BLOCK_TYPE || ! is_array($list)) {
                    continue;
                }

                $listStart = $this->parseDate($list['startTime'] ?? null);
                $listEnd = $this->parseDate($list['endTime'] ?? null);

                foreach ($list['items'] ?? [] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $image = $this->imageUrl($item['imageUrl'] ?? null);

                    if ($image === null) {
                        continue;
                    }

                    $landing = $this->landingUrl($item['productListPath'] ?? null, self::BASE)
                        ?? $this->landingUrl($item['textLinkUrl'] ?? null, self::BASE);

                    if ($landing === null) {
                        continue;
                    }

                    $visibility = is_array($item['timeVisibility'] ?? null) ? $item['timeVisibility'] : [];

                    $sourceId = $this->voucherId($item, $landing);

                    if (isset($seen[$sourceId])) {
                        continue;
                    }

                    $seen[$sourceId] = true;

                    $items[] = new PromotionNewsData(
                        source: $this->source(),
                        category: $this->category(),
                        sourceId: $sourceId,
                        title: $this->stringOrNull($item['title'] ?? null),
                        description: $this->stringOrNull($item['description'] ?? null)
                            ?? $this->stringOrNull($item['descriptionExtra'] ?? null),
                        imageUrl: $image,
                        landingUrl: $landing,
                        startAt: $this->parseDate($visibility['startTime'] ?? null) ?? $listStart,
                        endAt: $this->parseDate($visibility['endTime'] ?? null) ?? $listEnd,
                        isActive: true,
                        rawData: $item,
                    );
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function voucherId(array $item, string $landing): string
    {
        $campaignId = $item['campaignId'] ?? null;

        if (is_int($campaignId) || (is_string($campaignId) && $campaignId !== '')) {
            return (string) $campaignId;
        }

        return $this->deterministicId(
            $this->source(),
            $landing,
            (string) ($item['title'] ?? '')
        );
    }
}
