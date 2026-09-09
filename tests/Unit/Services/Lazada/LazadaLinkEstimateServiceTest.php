<?php

namespace Tests\Unit\Services\Lazada;

use App\Models\LinkRequest;
use App\Models\User;
use App\Services\Lazada\LazadaException;
use App\Services\Lazada\LazadaLinkEstimateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LazadaLinkEstimateServiceTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_URL = 'https://www.lazada.vn/products/ao-so-mi-nam-i123456789-s456.html?x=1';
    private const PROMO_LINK = 'https://c.lazada.vn/t/c.ABCDE?subId1=1&subId2=testuser';

    private LazadaLinkEstimateService $service;

    /** @var array<int, array> FIFO queue of getlink payloads (Laravel Http::fake merges, it never replaces stubs). */
    private array $linkQueue = [];

    /** @var array<int, array> FIFO queue of product feed payloads. */
    private array $feedQueue = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'services.lazada.app_key' => '105000',
            'services.lazada.app_secret' => 'secret-secret-secret-secret-secret-32',
            'services.lazada.user_token' => 'ffffffffffffffffffffffffffffffff',
            'services.lazada.base_url' => 'https://api.lazada.vn/rest',
        ]);

        $this->service = app(LazadaLinkEstimateService::class);

        Http::fake([
            '*/marketing/getlink*' => function () {
                return Http::response(array_shift($this->linkQueue) ?? $this->apiPayload(), 200);
            },
            '*/marketing/product/feed*' => function () {
                return Http::response(array_shift($this->feedQueue) ?? [
                    'data' => ['productList' => []],
                    'success' => true,
                ], 200);
            },
        ]);
    }

    private function apiPayload(): array
    {
        return [
            'data' => [
                'urlBatchGetLinkInfoList' => [[
                    'originalUrl' => self::PRODUCT_URL,
                    'productId' => '123456789',
                    'productName' => null,
                    'regularPromotionLink' => self::PROMO_LINK,
                    'regularCommission' => '24.1%',
                ]],
            ],
            'success' => true,
            'error_code' => null,
            'error_msg' => null,
        ];
    }

    public function test_create_persists_link_and_product_preview(): void
    {
        $this->linkQueue[] = $this->apiPayload();
        $this->feedQueue[] = [
            'data' => [
                'productList' => [[
                    'productId' => '123456789',
                    'productName' => 'Áo sơ mi nam cotton',
                    'pictures' => 'https://img.lazada.vn/p1.jpg',
                    'discountPrice' => '250000',
                    'currency' => 'VND',
                    'totalCommissionRate' => '24.1',
                    'totalCommissionAmount' => '60250',
                ]],
            ],
            'success' => true,
        ];

        $user = User::factory()->create(['username' => 'testuser']);
        $link = LinkRequest::create([
            'user_id' => $user->id,
            'original_url' => self::PRODUCT_URL,
            'platform' => 'Lazada',
            'status' => 'completed',
        ]);

        $this->service->create($link, self::PRODUCT_URL, $user);

        $link->refresh();

        $this->assertSame('completed', $link->status);
        $this->assertSame('Lazada', $link->platform);
        $this->assertSame('lazada-api', $link->data_source);
        $this->assertSame(self::PROMO_LINK, $link->affiliate_url);
        $this->assertSame('Áo sơ mi nam cotton', $link->product_name);
        $this->assertSame('https://img.lazada.vn/p1.jpg', $link->product_image);
        $this->assertSame(123456789, (int) $link->item_id);
        $this->assertSame(250000, (int) $link->product_price);
        $this->assertSame(60250.0, (float) $link->estimated_cashback);
        // ratio 60250/250000 = 0.241 -> tier 60% -> floor(60250 × 0.60) = 36150
        $this->assertSame(0.60, (float) $link->cashback_rate);
        $this->assertSame(36150.0, (float) $link->user_estimated_cashback);

        Http::assertSent(function ($request) use ($user) {
            $url = (string) $request->url();

            return str_contains($url, '/marketing/getlink')
                && str_contains($url, 'inputType=url')
                && str_contains($url, 'inputValue=' . rawurlencode(self::PRODUCT_URL))
                && str_contains($url, 'subId1=' . $user->id)
                && str_contains($url, 'subId2=' . $user->username);
        });
    }

    public function test_invalid_non_lazada_url_rejected(): void
    {
        $user = User::factory()->create(['username' => 'testuser']);
        $link = LinkRequest::create([
            'user_id' => $user->id,
            'original_url' => 'https://example.com/products/1',
            'platform' => 'Lazada',
            'status' => 'completed',
        ]);

        try {
            $this->service->create($link, 'https://example.com/products/1', $user);
            $this->fail('Expected LazadaException');
        } catch (LazadaException $e) {
            $this->assertSame('Chỉ hỗ trợ link sản phẩm Lazada. Vui lòng dán đúng link Lazada.', $e->getUserMessage());
        }
    }

    public function test_credentials_missing_throws(): void
    {
        config([
            'services.lazada.app_key' => '',
            'services.lazada.app_secret' => '',
            'services.lazada.user_token' => '',
        ]);

        $user = User::factory()->create(['username' => 'testuser']);
        $link = LinkRequest::create([
            'user_id' => $user->id,
            'original_url' => self::PRODUCT_URL,
            'platform' => 'Lazada',
            'status' => 'completed',
        ]);

        $service = app(LazadaLinkEstimateService::class);

        try {
            $service->create($link, self::PRODUCT_URL, $user);
            $this->fail('Expected LazadaException');
        } catch (LazadaException $e) {
            $this->assertSame('Tính năng Lazada chưa sẵn sàng. Vui lòng thử lại sau.', $e->getUserMessage());
        }
    }

    // ------------------------------------------------------------------
    //  User cashback must follow the LazadaCashbackCalculator tier rule
    // ------------------------------------------------------------------

    private function createLinkWithPayload(array $item, array $product, string $productUrl = self::PRODUCT_URL): LinkRequest
    {
        $this->linkQueue[] = [
            'data' => [
                'urlBatchGetLinkInfoList' => [[
                    'originalUrl' => $productUrl,
                    'productId' => $item['productId'],
                    'productName' => null,
                    'regularPromotionLink' => self::PROMO_LINK,
                    'regularCommission' => $item['regularCommission'] ?? '10%',
                ]],
            ],
            'success' => true,
            'error_code' => null,
            'error_msg' => null,
        ];
        $this->feedQueue[] = [
            'data' => [
                'productList' => [$product],
            ],
            'success' => true,
        ];

        $user = User::factory()->create(['username' => 'testuser_' . uniqid()]);
        $link = LinkRequest::create([
            'user_id' => $user->id,
            'original_url' => $productUrl,
            'platform' => 'Lazada',
            'status' => 'completed',
        ]);

        $this->service->create($link, $productUrl, $user);
        $link->refresh();

        return $link;
    }

    public function test_audit_example_665000_by_14_percent_estimates_55860_user_cashback(): void
    {
        $link = $this->createLinkWithPayload(
            ['productId' => '123456789', 'regularCommission' => '14%'],
            [
                'productId' => '123456789',
                'discountPrice' => '665000',
                'totalCommissionRate' => '14',
                'totalCommissionAmount' => '93100',
            ],
        );

        // 93100/665000 = 0.14 -> tier 60% -> floor(93100 × 0.60) = 55860
        $this->assertSame(665000, (int) $link->product_price);
        $this->assertSame(93100.0, (float) $link->estimated_cashback);
        $this->assertSame(0.60, (float) $link->cashback_rate);
        $this->assertSame(55860.0, (float) $link->user_estimated_cashback);
    }

    public function test_estimate_tier_boundaries_match_credit_logic(): void
    {
        // ratio exactly 0.52 -> 70% -> floor(52000 × 0.70) = 36400
        $link = $this->createLinkWithPayload(
            ['productId' => '1', 'regularCommission' => '52%'],
            ['productId' => '1', 'discountPrice' => '100000', 'totalCommissionRate' => '52', 'totalCommissionAmount' => '52000'],
        );
        $this->assertSame(0.70, (float) $link->cashback_rate);
        $this->assertSame(36400.0, (float) $link->user_estimated_cashback);

        // ratio just below 0.52 -> 60% -> floor(51000 × 0.60) = 30600
        $link = $this->createLinkWithPayload(
            ['productId' => '2', 'regularCommission' => '51%'],
            ['productId' => '2', 'discountPrice' => '100000', 'totalCommissionRate' => '51', 'totalCommissionAmount' => '51000'],
        );
        $this->assertSame(0.60, (float) $link->cashback_rate);
        $this->assertSame(30600.0, (float) $link->user_estimated_cashback);

        // ratio exactly 0.12 -> 60% -> floor(12000 × 0.60) = 7200
        $link = $this->createLinkWithPayload(
            ['productId' => '3', 'regularCommission' => '12%'],
            ['productId' => '3', 'discountPrice' => '100000', 'totalCommissionRate' => '12', 'totalCommissionAmount' => '12000'],
        );
        $this->assertSame(0.60, (float) $link->cashback_rate);
        $this->assertSame(7200.0, (float) $link->user_estimated_cashback);

        // ratio just below 0.12 -> 50% -> floor(11000 × 0.50) = 5500
        $link = $this->createLinkWithPayload(
            ['productId' => '4', 'regularCommission' => '11%'],
            ['productId' => '4', 'discountPrice' => '100000', 'totalCommissionRate' => '11', 'totalCommissionAmount' => '11000'],
        );
        $this->assertSame(0.50, (float) $link->cashback_rate);
        $this->assertSame(5500.0, (float) $link->user_estimated_cashback);
    }

    public function test_user_estimated_cashback_not_null_when_estimate_succeeds(): void
    {
        // No discountPrice in the feed -> product price unknown -> conservative
        // 50% tier, but user_estimated_cashback is still persisted (not null).
        $link = $this->createLinkWithPayload(
            ['productId' => '123456789', 'regularCommission' => '30%'],
            [
                'productId' => '123456789',
                'totalCommissionRate' => '30',
                'totalCommissionAmount' => '3000',
            ],
        );

        $this->assertNull($link->product_price);
        $this->assertSame(3000.0, (float) $link->estimated_cashback);
        $this->assertSame(0.50, (float) $link->cashback_rate);
        $this->assertNotNull($link->user_estimated_cashback);
        $this->assertSame(1500.0, (float) $link->user_estimated_cashback);
    }
}