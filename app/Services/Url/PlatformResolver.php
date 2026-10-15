<?php

namespace App\Services\Url;

use App\Models\AffiliateDetectRule;
use App\Services\Lazada\LazadaUrlParser;
use App\Services\ShopeeFood\ShopeeFoodUrlParser;

/**
 * Unified, hostname-based URL platform resolver.
 *
 * Detection is split into TWO ordered layers:
 *   1. HARD-CODED rules (ShopeeFood → Shopee → Lazada → TikTok → Tiki),
 *      unchanged and always evaluated first.
 *   2. ADMIN-MANAGED overlay ({@see AffiliateDetectRuleRepository}), evaluated
 *      only after every code rule has failed. It is additive, so a URL already
 *      recognised by code keeps exactly its current result; with zero admin
 *      rows (the default) detection is byte-for-byte identical to before.
 *
 * Ordering is significant: ShopeeFood must be checked before Shopee because
 * `spf.shopee.vn` / `shopeefood.shopee.vn` are subdomains of `shopee.vn`.
 */
final class PlatformResolver
{
    public const LABEL_SHOPEE = 'Shopee';

    public const LABEL_SHOPEEFOOD = 'ShopeeFood';

    public const LABEL_LAZADA = 'Lazada';

    public const LABEL_TIKTOK = 'TikTok Shop';

    public const LABEL_TIKI = 'Tiki';

    public const LABEL_ACCESSTRADE = 'AccessTrade';

    public const LABEL_OTHER = 'Khác';

    /** Shopee landing host (subdomains included). */
    private const SHOPEE_HOSTS = ['shopee.vn'];

    /** Shopee short-link hosts (kept in sync with UrlResolverService). */
    private const SHOPEE_SHORT_HOSTS = ['s.shopee.vn', 'vn.shp.ee', 's.shp.ee', 'shope.ee'];

    private const TIKTOK_HOSTS = ['tiktok.com', 'tiktokshop.com'];

    private const TIKI_HOSTS = ['tiki.vn'];

    public function __construct(
        private readonly ?AffiliateDetectRuleRepository $ruleRepository = null,
    ) {}

    public function resolve(string $url): ResolvedTarget
    {
        $url = trim($url);

        if ($url === '') {
            return new ResolvedTarget($url, null, false, self::LABEL_OTHER, ResolvedTarget::STRATEGY_INVALID, 'empty_url');
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return new ResolvedTarget($url, null, false, self::LABEL_OTHER, ResolvedTarget::STRATEGY_INVALID, 'invalid_url');
        }

        $host = strtolower($host);
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        // 1) ShopeeFood — must win over Shopee (spf.shopeefood subdomains).
        if (ShopeeFoodUrlParser::isShopeeFoodHost($host)) {
            return $this->matched($url, $host, self::LABEL_SHOPEEFOOD, ResolvedTarget::STRATEGY_SHOPEEFOOD, 'shopeefood');
        }

        // 2) Shopee (landing + short links).
        if ($this->hostMatchesAny($host, self::SHOPEE_HOSTS)
            || $this->hostMatchesAny($host, self::SHOPEE_SHORT_HOSTS)) {
            return $this->matched($url, $host, self::LABEL_SHOPEE, ResolvedTarget::STRATEGY_SHOPEE, 'shopee');
        }

        // 3) Lazada — reuse the same official-domain list as the API parser.
        if ((new LazadaUrlParser)->isLazadaHost($host)) {
            return $this->matched($url, $host, self::LABEL_LAZADA, ResolvedTarget::STRATEGY_LAZADA, 'lazada');
        }

        // 4) TikTok Shop.
        if ($this->hostMatchesAny($host, self::TIKTOK_HOSTS)) {
            return $this->matched($url, $host, self::LABEL_TIKTOK, ResolvedTarget::STRATEGY_TIKTOK, 'tiktok');
        }

        // 5) Tiki — recognised but no link-creation handler.
        if ($this->hostMatchesAny($host, self::TIKI_HOSTS)) {
            return new ResolvedTarget(
                $url,
                $host,
                true,
                self::LABEL_TIKI,
                ResolvedTarget::STRATEGY_UNSUPPORTED,
                'no_handler',
                'tiki',
            );
        }

        // 6) Admin-managed overlay — runs ONLY after every code rule above, so
        //    an already-recognised URL never reaches it. Empty by default.
        $overlay = $this->matchOverlay($url, $host);

        if ($overlay !== null) {
            return $overlay;
        }

        return new ResolvedTarget(
            $url,
            $host,
            false,
            self::LABEL_OTHER,
            ResolvedTarget::STRATEGY_UNSUPPORTED,
            'unmapped',
        );
    }

    /**
     * Evaluate admin-managed rules. Returns null when no rule matches (or when
     * the repository is not bound, e.g. `new PlatformResolver()` in unit tests).
     */
    private function matchOverlay(string $url, string $host): ?ResolvedTarget
    {
        if ($this->ruleRepository === null) {
            return null;
        }

        $lowerUrl = strtolower($url);

        foreach ($this->ruleRepository->activeRules() as $rule) {
            $pattern = strtolower(trim((string) $rule['pattern']));

            if ($pattern === '') {
                continue;
            }

            $matched = $rule['match_type'] === AffiliateDetectRule::MATCH_SUBSTRING
                ? str_contains($lowerUrl, $pattern)
                : ($host === $pattern || str_ends_with($host, '.'.$pattern));

            if (! $matched) {
                continue;
            }

            $strategy = in_array($rule['strategy'], AffiliateDetectRule::STRATEGIES, true)
                ? $rule['strategy']
                : ResolvedTarget::STRATEGY_UNSUPPORTED;

            $platform = in_array($rule['platform'], AffiliateDetectRule::PLATFORMS, true)
                ? $rule['platform']
                : self::LABEL_OTHER;

            return new ResolvedTarget(
                $url,
                $host,
                true,
                $platform,
                $strategy,
                'db_rule',
                (string) $rule['name'],
                $rule['campaign_id'] !== null ? (string) $rule['campaign_id'] : null,
            );
        }

        return null;
    }

    /**
     * @param  array<int, string>  $domains
     */
    private function hostMatchesAny(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private function matched(string $url, string $host, string $platform, string $strategy, string $rule): ResolvedTarget
    {
        return new ResolvedTarget($url, $host, true, $platform, $strategy, 'ok', $rule);
    }
}
