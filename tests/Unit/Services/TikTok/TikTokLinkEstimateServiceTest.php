<?php

namespace Tests\Unit\Services\TikTok;

use App\Models\LinkRequest;
use App\Models\User;
use App\Services\RioHub\RioHubResponse;
use App\Services\TikTok\DTOs\TikTokOrder;
use App\Services\TikTok\DTOs\TikTokProductDTO;
use App\Services\TikTok\TikTokAffiliateService;
use App\Services\TikTok\TikTokCashbackCalculator;
use App\Services\TikTok\TikTokLinkEstimateService;
use App\Services\TikTok\TikTokProductService;
use App\Services\TikTok\TikTokServiceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TikTokLinkEstimateServiceTest extends TestCase
{
    use RefreshDatabase;
    private TikTokAffiliateService $affiliateService;
    private TikTokProductService $productService;
    private TikTokCashbackCalculator $calculator;
    private TikTokLinkEstimateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->affiliateService = $this->createMock(TikTokAffiliateService::class);
        $this->productService = $this->createMock(TikTokProductService::class);
        $this->calculator = new TikTokCashbackCalculator();
        $this->service = new TikTokLinkEstimateService(
            $this->affiliateService,
            $this->productService,
            $this->calculator,
        );
    }

    private function user(): User
    {
        $username = 'testuser_' . uniqid();

        return User::create([
            'name'     => 'Test User',
            'username' => $username,
            'email'    => $username . '@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    private function link(): LinkRequest
    {
        return LinkRequest::create([
            'user_id' => $this->user()->id,
            'platform' => 'pending',
            'status' => 'pending',
            'original_url' => 'https://tiktok.com/item/pid',
        ]);
    }

    private function stubAffiliateLink(string $url, string $productId): void
    {
        $dto = new \App\Services\TikTok\DTOs\TikTokAffiliateLinkDTO(
            affiliateUrl: $url,
            productId: $productId,
            productName: null,
            originalUrl: null,
            raw: [],
        );

        $this->affiliateService
            ->method('createAffiliateLink')
            ->willReturn($dto);
    }

    private function stubProduct(?TikTokProductDTO $product): void
    {
        $this->productService
            ->method('getProduct')
            ->willReturn($product ?? $this->product('pid', 100000, 2300));
    }

    private function product(string $id, float $price, ?float $ratePct, ?float $ads = null, ?float $observed = null): TikTokProductDTO
    {
        return new TikTokProductDTO(
            productId: $id,
            name: 'Test Product',
            imageUrl: 'https://img.tiktok.com/x.jpg',
            price: $price,
            currency: 'VND',
            commissionRatePct: $ratePct,
            shopAdsCommissionRatePct: $ads,
            observedCommissionRatePct: $observed,
            shopName: 'Test Shop',
            raw: [],
        );
    }

    // ------------------------------------------------------------------
    //  50% / 60% / 70% tiers
    // ------------------------------------------------------------------

    public function test_estimate_applies_50_percent_tier(): void
    {
        $this->stubAffiliateLink('https://riohub.vn/aff/l', 'pid');
        // 10% commission -> 50% tier
        $this->stubProduct($this->product('pid', 100000, 1000));

        $link = $this->link();
        $this->service->create($link, 'https://tiktok.com/item/pid', $this->user());

        $this->assertEquals('completed', $link->status);
        $this->assertEquals('https://riohub.vn/aff/l', $link->affiliate_url);
        $this->assertEquals('TikTok Shop', $link->platform);
        $this->assertEquals('Test Product', $link->product_name);
        $this->assertEquals(100000, $link->product_price);

        // commission = floor(100000 * 10%) = 10000; 50% = 5000 (no 10% cut)
        $this->assertEquals(10000.00, (float) $link->estimated_cashback);
        $this->assertEquals(5000.00, (float) $link->user_estimated_cashback);
        $this->assertEquals(0.50, (float) $link->cashback_rate);
    }

    public function test_estimate_applies_60_percent_tier(): void
    {
        $this->stubAffiliateLink('https://riohub.vn/aff/l', 'pid');
        // 20% commission -> 60% tier
        $this->stubProduct($this->product('pid', 100000, 2000));

        $link = $this->link();
        $this->service->create($link, 'https://tiktok.com/item/pid', $this->user());

        $this->assertEquals(20000.00, (float) $link->estimated_cashback);
        $this->assertEquals(12000.00, (float) $link->user_estimated_cashback); // 20000*0.6
        $this->assertEquals(0.60, (float) $link->cashback_rate);
    }

    public function test_estimate_applies_70_percent_tier(): void
    {
        $this->stubAffiliateLink('https://riohub.vn/aff/l', 'pid');
        // 60% commission -> 70% tier
        $this->stubProduct($this->product('pid', 100000, 6000));

        $link = $this->link();
        $this->service->create($link, 'https://tiktok.com/item/pid', $this->user());

        $this->assertEquals(60000.00, (float) $link->estimated_cashback);
        $this->assertEquals(42000.00, (float) $link->user_estimated_cashback); // 60000*0.7
        $this->assertEquals(0.70, (float) $link->cashback_rate);
    }

    // ------------------------------------------------------------------
    //  Commission rate resolution (observed > base+ads)
    // ------------------------------------------------------------------

    public function test_estimate_uses_observed_commission_rate(): void
    {
        $this->stubAffiliateLink('https://riohub.vn/aff/l', 'pid');
        // observed 25% -> 50% tier (0.25)
        $this->stubProduct($this->product('pid', 100000, 1000, ads: 100, observed: 2500));

        $link = $this->link();
        $this->service->create($link, 'https://tiktok.com/item/pid', $this->user());

        // commission = floor(100000 * 25%) = 25000
        $this->assertEquals(25000.00, (float) $link->estimated_cashback);
        $this->assertEquals(0.60, (float) $link->cashback_rate);
        $this->assertEquals(15000.00, (float) $link->user_estimated_cashback); // 25000*0.6
    }

    public function test_estimate_falls_back_to_commission_plus_ads(): void
    {
        $this->stubAffiliateLink('https://riohub.vn/aff/l', 'pid');
        // base 10% + ads 2% = 12% -> 60% tier (0.12)
        $this->stubProduct($this->product('pid', 100000, 1000, ads: 200));

        $link = $this->link();
        $this->service->create($link, 'https://tiktok.com/item/pid', $this->user());

        $this->assertEquals(12000.00, (float) $link->estimated_cashback);
        $this->assertEquals(0.60, (float) $link->cashback_rate);
        $this->assertEquals(7200.00, (float) $link->user_estimated_cashback); // 12000*0.6
    }

    // ------------------------------------------------------------------
    //  Best-effort + failure paths
    // ------------------------------------------------------------------

    public function test_estimate_without_product_info_still_creates_link(): void
    {
        $this->stubAffiliateLink('https://riohub.vn/aff/l', 'pid');
        $this->productService->method('getProduct')
            ->willThrowException(new TikTokServiceException('boom'));

        $link = $this->link();
        $this->service->create($link, 'https://tiktok.com/item/pid', $this->user());

        $this->assertEquals('completed', $link->status);
        $this->assertEquals('https://riohub.vn/aff/l', $link->affiliate_url);
        $this->assertNull($link->product_name);
        $this->assertNull($link->product_price);
        $this->assertNull($link->estimated_cashback);
    }

    public function test_estimate_throws_when_affiliate_url_empty(): void
    {
        $dto = new \App\Services\TikTok\DTOs\TikTokAffiliateLinkDTO(
            affiliateUrl: '',
            productId: null,
            productName: null,
            originalUrl: null,
            raw: [],
        );

        $this->affiliateService->method('createAffiliateLink')->willReturn($dto);

        $this->expectException(TikTokServiceException::class);

        $this->service->create($this->link(), 'https://tiktok.com/item/pid', $this->user());
    }

    public function test_estimate_does_not_persist_cashback_without_rate(): void
    {
        $this->stubAffiliateLink('https://riohub.vn/aff/l', 'pid');
        $this->stubProduct($this->product('pid', 100000, null));

        $link = $this->link();
        $this->service->create($link, 'https://tiktok.com/item/pid', $this->user());

        $this->assertEquals('https://riohub.vn/aff/l', $link->affiliate_url);
        $this->assertEquals(100000, $link->product_price);
        $this->assertNull($link->estimated_cashback);
        $this->assertNull($link->user_estimated_cashback);
        $this->assertNull($link->cashback_rate);
    }

    // ------------------------------------------------------------------
    //  Estimate must match the canonical wallet credit formula
    // ------------------------------------------------------------------

    public function test_estimate_equals_wallet_credit_for_same_commission_and_tier(): void
    {
        $cases = [
            ['ratePct' => 1000, 'tier' => 0.50],
            ['ratePct' => 1300, 'tier' => 0.60],
            ['ratePct' => 2000, 'tier' => 0.60],
            ['ratePct' => 6000, 'tier' => 0.70],
        ];

        // One stub per case; PHPUnit mock willReturn cannot be re-bound in a loop.
        $this->stubAffiliateLink('https://riohub.vn/aff/l', 'pid');
        $this->productService->method('getProduct')->willReturnOnConsecutiveCalls(
            ...array_map(
                fn ($case) => $this->product('pid', 100000, $case['ratePct']),
                array_values($cases),
            )
        );

        foreach ($cases as $case) {
            $link = $this->link();
            $this->service->create($link, 'https://tiktok.com/item/pid', $this->user());

            $order = TikTokOrder::fromArray([
                'order_id'          => 'ORD-PARITY-' . $case['ratePct'],
                'status'            => 2,
                'settlement_status' => 'SETTLED',
                'commission_gmv'    => 100000,
                'actual_commission' => floor(100000 * $case['ratePct'] / 10000),
            ]);
            $credit = $this->calculator->calculate($order);

            $this->assertSame((float) $credit['cashback_amount'], (float) $link->user_estimated_cashback);
            $this->assertSame($credit['cashback_rate'], (float) $link->cashback_rate);
            $this->assertSame((float) $case['tier'], $credit['cashback_rate']);
        }
    }

    public function test_estimate_13_100_commission_50_percent_gives_6550(): void
    {
        $this->stubAffiliateLink('https://riohub.vn/aff/l', 'pid');
        // 6.55% commission → 50% tier; commission = floor(200000 * 6.55%) = 13100
        $this->stubProduct($this->product('pid', 200000, 655));

        $link = $this->link();
        $this->service->create($link, 'https://tiktok.com/item/pid', $this->user());

        $this->assertEquals(13100.00, (float) $link->estimated_cashback);
        $this->assertEquals(0.50, (float) $link->cashback_rate);
        // floor(13100 × 0.50) — identical to the wallet credit formula
        $this->assertEquals(6550.00, (float) $link->user_estimated_cashback);
    }

    public function test_estimate_uses_same_tier_boundaries_as_wallet_credit(): void
    {
        $this->stubAffiliateLink('https://riohub.vn/aff/l', 'pid');
        $this->productService->method('getProduct')->willReturnOnConsecutiveCalls(
            $this->product('pid', 100000, 1200), // ratio exactly 0.12 -> 60%
            $this->product('pid', 100000, 1190), // ratio just below 0.12 -> 50%
            $this->product('pid', 100000, 5200), // ratio exactly 0.52 -> 70%
            $this->product('pid', 100000, 5190), // ratio just below 0.52 -> 60%
        );

        $cases = [
            ['tier' => 0.60, 'user' => 7200.0],   // floor(12000 × 0.60)
            ['tier' => 0.50, 'user' => 5950.0],   // floor(11900 × 0.50)
            ['tier' => 0.70, 'user' => 36400.0],  // floor(52000 × 0.70)
            ['tier' => 0.60, 'user' => 31140.0],  // floor(51900 × 0.60)
        ];

        foreach ($cases as $case) {
            $link = $this->link();
            $this->service->create($link, 'https://tiktok.com/item/pid', $this->user());

            $this->assertEquals($case['tier'], (float) $link->cashback_rate);
            $this->assertEquals($case['user'], (float) $link->user_estimated_cashback);
        }
    }
}
