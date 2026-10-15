<?php

namespace App\Services\Url;

use App\Models\AffiliateDetectRule;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Loads the admin-managed detect rules used by {@see PlatformResolver}.
 *
 * Rules are applied as an ADDITIVE overlay, AFTER all hard-coded rules, so the
 * result for any URL already recognised by code is unchanged. The repository
 * is defensive: if the table does not exist yet (fresh install before migrate)
 * it returns an empty list rather than breaking link creation.
 */
class AffiliateDetectRuleRepository
{
    public const CACHE_KEY = 'affiliate_detect_rules.active';

    /**
     * @return array<int, array{name: string, pattern: string, match_type: string, platform: string, strategy: string, campaign_id: ?string}>
     */
    public function activeRules(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, function (): array {
                return AffiliateDetectRule::query()
                    ->active()
                    ->orderBy('priority')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (AffiliateDetectRule $rule): array => [
                        'name' => (string) $rule->name,
                        'pattern' => (string) $rule->pattern,
                        'match_type' => (string) $rule->match_type,
                        'platform' => (string) $rule->platform,
                        'strategy' => (string) $rule->strategy,
                        'campaign_id' => $rule->campaign_id !== null ? (string) $rule->campaign_id : null,
                    ])
                    ->all();
            });
        } catch (Throwable) {
            return [];
        }
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
