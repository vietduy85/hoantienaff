<?php

namespace App\Services\PromotionNews;

use App\Models\PromotionNews;

/**
 * Static metadata for promotion/news sources.
 *
 * Unknown sources (e.g. banks, food delivery, marketplaces added later) must
 * degrade gracefully instead of breaking the UI, so no source list is treated
 * as exhaustive.
 */
final class PromotionNewsSources
{
    /**
     * Preferred display order, used to build the homepage representative list.
     *
     * @var array<int, string>
     */
    private const ORDER = [
        'coop',
        'bhx',
        'winmart',
        'kingfoodmart',
    ];

    /**
     * @var array<string, array{label: string, emoji: string, category: string}>
     */
    private const KNOWN = [
        'coop' => ['label' => 'Co.op Online', 'emoji' => '🛒', 'category' => PromotionNews::CATEGORY_SUPERMARKET],
        'bhx' => ['label' => 'Bách Hóa Xanh', 'emoji' => '🥬', 'category' => PromotionNews::CATEGORY_SUPERMARKET],
        'winmart' => ['label' => 'WinMart', 'emoji' => '🏬', 'category' => PromotionNews::CATEGORY_SUPERMARKET],
        'kingfoodmart' => ['label' => 'Kingfoodmart', 'emoji' => '👑', 'category' => PromotionNews::CATEGORY_SUPERMARKET],
        'vib' => ['label' => 'VIB', 'emoji' => '💳', 'category' => PromotionNews::CATEGORY_CREDIT_CARD],
        'grab' => ['label' => 'Grab', 'emoji' => '🚕', 'category' => PromotionNews::CATEGORY_OTHER],
        'grabfood' => ['label' => 'GrabFood', 'emoji' => '🍜', 'category' => PromotionNews::CATEGORY_FOOD],
        'shopeefood' => ['label' => 'ShopeeFood', 'emoji' => '🍲', 'category' => PromotionNews::CATEGORY_FOOD],
        'shopee' => ['label' => 'Shopee', 'emoji' => '🛍️', 'category' => PromotionNews::CATEGORY_OTHER],
        'lazada' => ['label' => 'Lazada', 'emoji' => '🛒', 'category' => PromotionNews::CATEGORY_OTHER],
        'tiktok' => ['label' => 'TikTok Shop', 'emoji' => '🎵', 'category' => PromotionNews::CATEGORY_OTHER],
    ];

    /**
     * @return array<int, string>
     */
    public static function order(): array
    {
        return self::ORDER;
    }

    public static function label(string $source): string
    {
        return self::KNOWN[$source]['label'] ?? self::humanize($source);
    }

    public static function emoji(string $source): string
    {
        return self::KNOWN[$source]['emoji'] ?? '📰';
    }

    public static function defaultCategory(string $source): string
    {
        return self::KNOWN[$source]['category'] ?? PromotionNews::CATEGORY_OTHER;
    }

    private static function humanize(string $source): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $source));
    }
}
