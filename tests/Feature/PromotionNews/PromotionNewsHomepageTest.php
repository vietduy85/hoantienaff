<?php

namespace Tests\Feature\PromotionNews;

use App\Models\User;
use App\Services\PromotionNews\PromotionNewsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Fixture\PromotionNews\PromotionNewsFixtures;
use Tests\TestCase;

class PromotionNewsHomepageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function fakeHomepages(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            PromotionNewsFixtures::COOP_URL => Http::response(PromotionNewsFixtures::coopHomepage()),
            PromotionNewsFixtures::BHX_URL => Http::response(PromotionNewsFixtures::bhxHomepage()),
            PromotionNewsFixtures::WINMART_URL => Http::response(PromotionNewsFixtures::winmartHomepage()),
            PromotionNewsFixtures::KINGFOODMART_URL => Http::response(PromotionNewsFixtures::kingfoodmartHomepage()),
        ]);
    }

    protected function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_guest_homepage_is_ok_and_has_no_promotion_news_section(): void
    {
        Http::fake();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertViewIs('welcome');
        $response->assertDontSee('Tin tức khuyến mãi');
        $response->assertDontSee('promo-card', false);
        Http::assertNothingSent();
    }

    public function test_guest_homepage_does_not_use_promotion_news_manager(): void
    {
        Http::fake();

        $this->mock(PromotionNewsManager::class, function ($mock): void {
            $mock->shouldNotReceive('all');
            $mock->shouldNotReceive('representativeNews');
        });

        $this->get('/')->assertOk();

        Http::assertNothingSent();
    }

    public function test_logged_in_users_are_redirected_to_dashboard(): void
    {
        $this->actingAs($this->verifiedUser())
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }

    public function test_dashboard_loads_without_promotion_news(): void
    {
        $this->mock(PromotionNewsManager::class, function ($mock): void {
            $mock->shouldNotReceive('all');
            $mock->shouldNotReceive('representativeNews');
        });

        $response = $this->actingAs($this->verifiedUser())->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('Tin tức khuyến mãi');
        $response->assertDontSee('promo-card', false);
    }

    public function test_navigation_shows_price_comparison_and_promotion_news_links(): void
    {
        $response = $this->actingAs($this->verifiedUser())->get('/dashboard');

        $response->assertOk();
        $response->assertSee('So sánh giá');
        $response->assertSee('Tin tức KM');
        $response->assertSee(route('price-comparison.index'), false);
        $response->assertSee(route('promotion-news.index'), false);

        // Desktop + mobile navigation both render the link.
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($response->getContent(), route('promotion-news.index'))
        );
    }

    public function test_promotion_news_page_lists_cards_and_shows_navigation_link(): void
    {
        $this->fakeHomepages();

        $response = $this->get('/tin-tuc-khuyen-mai');

        $response->assertOk();
        $response->assertSee('Tin tức khuyến mãi');
        $response->assertSee('Tin tức KM');
        $response->assertSee('Xem chi tiết');
        $response->assertSee(route('promotion-news.index'), false);
    }
}
