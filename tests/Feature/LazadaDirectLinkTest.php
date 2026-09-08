<?php

namespace Tests\Feature;

use App\Models\LinkRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LazadaDirectLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private const LAZADA_URL = 'https://www.lazada.vn/products/ao-i123456789-s456.html';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'testuser',
        ]);

        Setting::set('affiliate.dashboard.strategy', 'direct');

        config([
            'cache.default' => 'array',
            'services.lazada.app_key' => '105000',
            'services.lazada.app_secret' => 'secret-secret-secret-secret-secret-32',
            'services.lazada.user_token' => 'ffffffffffffffffffffffffffffffff',
            'services.lazada.base_url' => 'https://api.lazada.vn/rest',
        ]);
    }

    public function test_lazada_link_carries_user_tracking_subids(): void
    {
        Http::fake(function ($request) {
            if (str_contains((string) $request->url(), '/marketing/getlink')) {
                return Http::response([
                    'data' => [
                        'urlBatchGetLinkInfoList' => [[
                            'originalUrl' => self::LAZADA_URL,
                            'productId' => '123456789',
                            'regularPromotionLink' => 'https://c.lazada.vn/t/c.TRACK',
                            'regularCommission' => '10%',
                        ]],
                    ],
                    'success' => true,
                    'error_code' => null,
                    'error_msg' => null,
                ], 200);
            }

            return Http::response(['data' => ['productList' => []]], 200);
        });

        $this->actingAs($this->user)
            ->postJson('/link-requests', ['original_url' => self::LAZADA_URL])
            ->assertOk();

        Http::assertSent(function ($request) {
            $url = (string) $request->url();

            return str_contains($url, '/marketing/getlink')
                && str_contains($url, 'inputType=url')
                && str_contains($url, 'inputValue=' . rawurlencode(self::LAZADA_URL))
                && str_contains($url, 'subId1=' . $this->user->id)
                && str_contains($url, 'subId2=' . $this->user->username);
        });
    }

    public function test_non_lazada_url_is_rejected(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://example.com/products/1?deal=lazada',
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'error' => 'Chỉ hỗ trợ link sản phẩm Lazada. Vui lòng dán đúng link Lazada.',
            'platform' => 'Lazada',
        ]);

        $link = LinkRequest::latest()->first();
        $this->assertEquals('failed', $link->status);
    }

    public function test_product_not_found_is_friendly(): void
    {
        Http::fake(function ($request) {
            if (str_contains((string) $request->url(), '/marketing/getlink')) {
                return Http::response([
                    'data' => [
                        'urlBatchGetLinkInfoList' => [[
                            'originalUrl' => self::LAZADA_URL,
                            'errorInfoList' => [[
                                'inputValue' => self::LAZADA_URL,
                                'errorCode' => '2001',
                                'errorMsg' => 'offer not found',
                            ]],
                        ]],
                    ],
                    'success' => true,
                    'error_code' => null,
                    'error_msg' => null,
                ], 200);
            }

            return Http::response(['success' => true], 200);
        });

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', ['original_url' => self::LAZADA_URL]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'error' => 'Không tìm thấy sản phẩm Lazada cho link này hoặc sản phẩm không có commission.',
        ]);
    }

    public function test_credentials_missing_is_friendly(): void
    {
        config([
            'services.lazada.app_key' => '',
            'services.lazada.app_secret' => '',
            'services.lazada.user_token' => '',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', ['original_url' => self::LAZADA_URL]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'error' => 'Tính năng Lazada chưa sẵn sàng. Vui lòng thử lại sau.',
        ]);

        $link = LinkRequest::latest()->first();
        $this->assertEquals('failed', $link->status);
    }

    public function test_malformed_url_is_rejected_before_api_call(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', ['original_url' => 'not a url']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['original_url']);
    }
}