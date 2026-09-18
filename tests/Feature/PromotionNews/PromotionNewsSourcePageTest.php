<?php

namespace Tests\Feature\PromotionNews;

use App\Models\PromotionNews;
use App\Services\PromotionNews\PromotionNewsSources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Fixture\PromotionNews\PromotionNewsFixtures;
use Tests\TestCase;

class PromotionNewsSourcePageTest extends TestCase
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

    public function test_source_page_shows_all_news_of_that_source(): void
    {
        PromotionNews::factory()->create([
            'source' => 'coop',
            'title' => 'Tin thủ công Co.op',
            'landing_url' => 'https://example.test/manual-coop',
        ]);

        $response = $this->get('/tin-tuc-khuyen-mai/coop');

        $response->assertOk();
        $response->assertSee('Tin khuyến mãi Co.op Online');
        $response->assertSee('Tin thủ công Co.op');

        // Both auto provider entries and manual entries are present, and the
        // provider landing URL is linked.
        $response->assertSee('Giảm 30k - Co.op Online');
        $response->assertSee('https://cooponline.vn/giam-30k', false);

        // The retailer filter is visible with the correct total and the
        // current source highlighted.
        $response->assertSee('Tất cả (9)', false);
        $response->assertSee('Co.op Online (3)', false);
    }

    public function test_source_page_marks_the_active_source_chip(): void
    {
        $expected = [
            'coop' => 2,
            'bhx' => 1,
            'winmart' => 3,
            'kingfoodmart' => 2,
        ];

        foreach ($expected as $source => $count) {
            $response = $this->get('/tin-tuc-khuyen-mai/'.$source);

            $response->assertOk();
            $this->assertSame(1, substr_count($response->getContent(), 'aria-current="page"'));

            $label = PromotionNewsSources::label($source);

            $response->assertSeeInOrder([
                route('promotion-news.source', ['source' => $source]),
                'bg-emerald-600',
                'aria-current="page"',
                $label.' ('.$count.')',
            ], false);

            $response->assertSee('Tất cả (8)', false);
        }
    }

    public function test_each_known_source_route_resolves(): void
    {
        foreach (['bhx', 'winmart', 'kingfoodmart'] as $source) {
            $this->get('/tin-tuc-khuyen-mai/'.$source)->assertOk();
        }
    }

    public function test_unknown_source_returns_404(): void
    {
        $this->get('/tin-tuc-khuyen-mai/khong-ton-tai')->assertNotFound();
    }
}
