<?php

namespace App\Services\Url;

/**
 * Immutable result of resolving a pasted URL to a platform/strategy.
 *
 * `platform` is the human label persisted in `link_requests.platform`
 * (Shopee, ShopeeFood, Lazada, TikTok Shop, Tiki, Khác) so existing UI/DB
 * data keeps the same values. `strategy` is the internal handler key.
 */
final class ResolvedTarget
{
    public const STRATEGY_SHOPEE = 'shopee_direct';

    public const STRATEGY_SHOPEEFOOD = 'shopeefood_deeplink';

    public const STRATEGY_LAZADA = 'lazada_api';

    public const STRATEGY_TIKTOK = 'tiktok_riohub';

    public const STRATEGY_ACCESSTRADE = 'accesstrade_api';

    public const STRATEGY_UNSUPPORTED = 'unsupported';

    public const STRATEGY_INVALID = 'invalid';

    public function __construct(
        public readonly string $inputUrl,
        public readonly ?string $host,
        public readonly bool $matched,
        public readonly string $platform,
        public readonly string $strategy,
        public readonly string $reason,
        public readonly ?string $matchedRule = null,
        public readonly ?string $campaignId = null,
    ) {}

    public function isShopee(): bool
    {
        return $this->strategy === self::STRATEGY_SHOPEE;
    }

    public function isShopeeFood(): bool
    {
        return $this->strategy === self::STRATEGY_SHOPEEFOOD;
    }

    public function isLazada(): bool
    {
        return $this->strategy === self::STRATEGY_LAZADA;
    }

    public function isTikTok(): bool
    {
        return $this->strategy === self::STRATEGY_TIKTOK;
    }

    public function isAccessTrade(): bool
    {
        return $this->strategy === self::STRATEGY_ACCESSTRADE;
    }

    public function isInvalid(): bool
    {
        return $this->strategy === self::STRATEGY_INVALID;
    }

    /**
     * Whether a link-creation handler exists for this target. Unsupported
     * platforms (Tiki, unknown) must never be reported as `completed`.
     */
    public function hasHandler(): bool
    {
        return in_array($this->strategy, [
            self::STRATEGY_SHOPEE,
            self::STRATEGY_SHOPEEFOOD,
            self::STRATEGY_LAZADA,
            self::STRATEGY_TIKTOK,
            self::STRATEGY_ACCESSTRADE,
        ], true);
    }
}
