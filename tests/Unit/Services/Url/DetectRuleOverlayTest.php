<?php

namespace Tests\Unit\Services\Url;

use App\Services\Url\AffiliateDetectRuleRepository;
use App\Services\Url\PlatformResolver;
use App\Services\Url\ResolvedTarget;
use Tests\TestCase;

/**
 * Admin-managed detect rules are an ADDITIVE overlay evaluated AFTER all
 * hard-coded rules. These tests prove:
 *   - the overlay can only affect URLs code does not recognise;
 *   - code-recognised URLs are byte-for-byte unchanged even when an overlapping
 *     admin rule exists;
 *   - an empty overlay behaves exactly like `new PlatformResolver()`.
 *
 * No DB, no HTTP — a stub repository feeds rules directly.
 */
class DetectRuleOverlayTest extends TestCase
{
    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function resolverWith(array $rules): PlatformResolver
    {
        $repository = new class($rules) extends AffiliateDetectRuleRepository
        {
            /** @param array<int, array<string, mixed>> $rules */
            public function __construct(private readonly array $rules) {}

            public function activeRules(): array
            {
                return $this->rules;
            }
        };

        return new PlatformResolver($repository);
    }

    public function test_empty_overlay_is_identical_to_no_repository(): void
    {
        $withEmpty = $this->resolverWith([]);
        $without = new PlatformResolver;

        foreach ([
            'https://shopee.vn/product/1/2',
            'https://shopeefood.vn/now-food/shop/1',
            'https://www.lazada.vn/products/x',
            'https://tiktok.com/item/1',
            'https://tiki.vn/p/1',
            'https://unknown-merchant.com/p/1',
        ] as $url) {
            $a = $withEmpty->resolve($url);
            $b = $without->resolve($url);

            $this->assertSame($b->platform, $a->platform, $url);
            $this->assertSame($b->strategy, $a->strategy, $url);
            $this->assertSame($b->matched, $a->matched, $url);
        }
    }

    public function test_overlay_maps_unrecognised_domain_to_accesstrade(): void
    {
        $resolver = $this->resolverWith([
            [
                'name' => 'TheFaceShop',
                'pattern' => 'thefaceshopvn.myharavan.com',
                'match_type' => 'hostname',
                'platform' => 'AccessTrade',
                'strategy' => 'accesstrade_api',
                'campaign_id' => '123',
            ],
        ]);

        $target = $resolver->resolve('https://thefaceshopvn.myharavan.com/products/abc-i.1.2');

        $this->assertTrue($target->matched);
        $this->assertTrue($target->isAccessTrade());
        $this->assertSame('AccessTrade', $target->platform);
        $this->assertSame('123', $target->campaignId);
        $this->assertSame('db_rule', $target->reason);
        $this->assertTrue($target->hasHandler());
    }

    public function test_overlay_never_changes_a_code_recognised_url(): void
    {
        // A broad substring rule "shopee" that, if it ran first or alone, would
        // try to hijack Shopee. Because code rules run first it must NOT apply.
        $resolver = $this->resolverWith([
            [
                'name' => 'Hijack shopee',
                'pattern' => 'shopee',
                'match_type' => 'substring',
                'platform' => 'AccessTrade',
                'strategy' => 'accesstrade_api',
                'campaign_id' => '999',
            ],
            [
                'name' => 'Hijack shopeefood',
                'pattern' => 'shopeefood.vn',
                'match_type' => 'hostname',
                'platform' => 'AccessTrade',
                'strategy' => 'accesstrade_api',
                'campaign_id' => '998',
            ],
        ]);

        $shopee = $resolver->resolve('https://shopee.vn/product/1/2');
        $this->assertTrue($shopee->isShopee());
        $this->assertFalse($shopee->isAccessTrade());

        $shopeeFood = $resolver->resolve('https://shopeefood.vn/now-food/shop/1');
        $this->assertTrue($shopeeFood->isShopeeFood());
        $this->assertFalse($shopeeFood->isAccessTrade());

        // A spoof host that code rejects is now captured by the overlay — this
        // is the intended additive behaviour (previously unsupported).
        $spoof = $resolver->resolve('https://shopee.vn.example.com/x');
        $this->assertTrue($spoof->isAccessTrade());
    }

    public function test_unknown_strategy_falls_back_to_unsupported(): void
    {
        $resolver = $this->resolverWith([
            [
                'name' => 'bad strategy',
                'pattern' => 'example-merchant.com',
                'match_type' => 'hostname',
                'platform' => 'AccessTrade',
                'strategy' => 'totally_made_up',
                'campaign_id' => null,
            ],
        ]);

        $target = $resolver->resolve('https://example-merchant.com/x');

        $this->assertTrue($target->matched);
        $this->assertSame(ResolvedTarget::STRATEGY_UNSUPPORTED, $target->strategy);
        $this->assertFalse($target->hasHandler());
    }

    public function test_first_matching_rule_wins_in_priority_order(): void
    {
        $resolver = $this->resolverWith([
            [
                'name' => 'first',
                'pattern' => 'example-merchant.com',
                'match_type' => 'hostname',
                'platform' => 'AccessTrade',
                'strategy' => 'accesstrade_api',
                'campaign_id' => '111',
            ],
            [
                'name' => 'second',
                'pattern' => 'example-merchant.com',
                'match_type' => 'hostname',
                'platform' => 'AccessTrade',
                'strategy' => 'accesstrade_api',
                'campaign_id' => '222',
            ],
        ]);

        $target = $resolver->resolve('https://example-merchant.com/x');
        $this->assertSame('111', $target->campaignId);
    }
}
