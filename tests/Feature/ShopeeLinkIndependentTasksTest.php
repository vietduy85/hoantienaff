<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\ProductDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tạo affiliate link và lấy thông tin sản phẩm là 2 việc độc lập: việc sau lỗi
 * thì link vẫn hoàn tất, dùng được ngay trong chính response của POST.
 */
class ShopeeLinkIndependentTasksTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://shopee.vn/product/37528463/5926546427';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '17342330566');
    }

    public function test_link_is_completed_in_the_response_even_if_product_data_throws(): void
    {
        $this->mock(ProductDataService::class, function ($mock) {
            $mock->shouldReceive('getByUrl')->andThrow(new \RuntimeException('boom'));
        });

        $response = $this->actingAs(User::factory()->create(['username' => 'indep']))
            ->postJson('/link-requests', ['original_url' => self::URL])
            ->assertOk();

        $response->assertJsonPath('status', 'completed');
        $this->assertStringContainsString('an_redir', (string) $response->json('affiliate_url'));
        $this->assertDatabaseHas('link_requests', ['status' => 'completed', 'item_id' => 5926546427]);
    }

    public function test_link_is_not_left_processing_when_the_background_task_never_runs(): void
    {
        // Mô phỏng job nền bị kill: ngay sau POST (trước khi job chạy) trạng thái đã là completed.
        $this->mock(ProductDataService::class, function ($mock) {
            $mock->shouldReceive('getByUrl')->andReturn(['success' => false]);
        });

        $this->actingAs(User::factory()->create(['username' => 'indep2']))
            ->postJson('/link-requests', ['original_url' => self::URL])
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'completed');

        $this->assertDatabaseMissing('link_requests', ['status' => 'processing']);
    }

    public function test_fast_mode_skips_product_info_for_tiktok_and_lazada(): void
    {
        $tiktok = $this->mock(\App\Services\TikTok\TikTokLinkEstimateService::class);
        $tiktok->shouldReceive("create")->once()->withArgs(fn ($link, $url, $user, $fast) => $fast === true);

        $this->actingAs(User::factory()->create(["username" => "fastall"]))
            ->postJson("/link-requests", ["original_url" => "https://www.tiktok.com/t/ZSabc123/", "fast_mode" => 1])
            ->assertJsonPath("fast_mode", true);

        $lazada = $this->mock(\App\Services\Lazada\LazadaLinkEstimateService::class);
        $lazada->shouldReceive("create")->once()->withArgs(fn ($link, $url, $user, $fast) => $fast === true);

        $this->postJson("/link-requests", ["original_url" => "https://www.lazada.vn/products/x-i123-s456.html", "fast_mode" => 1])
            ->assertJsonPath("fast_mode", true);
    }
}
