<?php

namespace Tests\Feature\PromotionNews;

use App\Models\PromotionNews;
use App\Services\PromotionNews\DataTransfer\PromotionNewsData;
use App\Services\PromotionNews\PromotionNewsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Fixture\PromotionNews\PromotionNewsFixtures;
use Tests\TestCase;

class PromotionNewsIndexPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        Http::fake([
            PromotionNewsFixtures::COOP_URL => Http::response(PromotionNewsFixtures::coopHomepage()),
            PromotionNewsFixtures::BHX_URL => Http::response(PromotionNewsFixtures::bhxHomepage()),
            PromotionNewsFixtures::WINMART_URL => Http::response(PromotionNewsFixtures::winmartHomepage()),
            PromotionNewsFixtures::KINGFOODMART_URL => Http::response(PromotionNewsFixtures::kingfoodmartHomepage()),
        ]);
    }

    public function test_index_lists_news_and_paginates_with_total_on_all_chip(): void
    {
        PromotionNews::factory()->count(15)->create([
            'source' => 'vib',
            'category' => 'credit-card',
            'landing_url' => 'https://example.test/vib',
        ]);

        $response = $this->get('/tin-tuc-khuyen-mai');

        $response->assertOk();
        $response->assertSee('Tin tức khuyến mãi');
        $this->assertSame(12, substr_count($response->getContent(), 'Xem chi tiết'));
        $response->assertSee('page=2', false);

        // Total chip counts every item of the source, not the visible page.
        $response->assertSee('Tất cả (23)', false);
        $response->assertSee('VIB (15)', false);
    }

    public function test_index_shows_source_filter_chips_with_expected_counts(): void
    {
        $response = $this->get('/tin-tuc-khuyen-mai');

        $response->assertOk();
        $response->assertSee('Tất cả (8)', false);
        $response->assertSee('Co.op Online (2)', false);
        $response->assertSee('Bách Hóa Xanh (1)', false);
        $response->assertSee('WinMart (3)', false);
        $response->assertSee('Kingfoodmart (2)', false);

        // Every chip links to its retailer route.
        foreach (['coop', 'bhx', 'winmart', 'kingfoodmart'] as $source) {
            $response->assertSee(route('promotion-news.source', ['source' => $source]), false);
        }
    }

    public function test_index_no_longer_filters_by_category(): void
    {
        $response = $this->get('/tin-tuc-khuyen-mai');

        $response->assertOk();
        $response->assertDontSee('Siêu thị');
        $response->assertDontSee('Thẻ tín dụng');
        $response->assertDontSee('category=', false);
        $response->assertDontSee('(0)');
    }

    public function test_index_all_chip_is_active_on_the_index_page(): void
    {
        $response = $this->get('/tin-tuc-khuyen-mai');

        $response->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'aria-current="page"'));
        $response->assertSeeInOrder([
            route('promotion-news.index'),
            'bg-emerald-600',
            'aria-current="page"',
            'Tất cả (8)',
        ], false);
    }

    public function test_index_derives_counts_from_a_single_manager_all_call(): void
    {
        $items = [
            new PromotionNewsData(source: 'coop', category: 'supermarket', title: 'Coop 1', landingUrl: 'https://example.test/1'),
            new PromotionNewsData(source: 'coop', category: 'supermarket', title: 'Coop 2', landingUrl: 'https://example.test/2'),
            new PromotionNewsData(source: 'bhx', category: 'supermarket', title: 'Bhx 1', landingUrl: 'https://example.test/3'),
        ];

        $this->mock(PromotionNewsManager::class, function ($mock) use ($items): void {
            $mock->shouldReceive('all')->once()->andReturn($items);
            $mock->shouldNotReceive('forSource');
            $mock->shouldNotReceive('categoriesWithNews');
        });

        $response = $this->get('/tin-tuc-khuyen-mai');

        $response->assertOk();
        $response->assertSee('Tất cả (3)', false);
        $response->assertSee('Co.op Online (2)', false);
        $response->assertSee('Bách Hóa Xanh (1)', false);
        $response->assertDontSee('winmart', false);
    }

    public function test_index_fetches_each_provider_exactly_once_for_counts(): void
    {
        $this->get('/tin-tuc-khuyen-mai')->assertOk();

        Http::assertSentCount(4);
    }
}