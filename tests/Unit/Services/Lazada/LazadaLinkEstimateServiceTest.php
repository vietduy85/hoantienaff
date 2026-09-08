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
        Http::fake([
            '*/marketing/getlink*' => Http::response($this->apiPayload(), 200),
            '*/marketing/product/feed*' => Http::response([
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
            ], 200),
        ]);

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
        $this->assertSame(24.1, (float) $link->cashback_rate);
        $this->assertNull($link->user_estimated_cashback);

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
}