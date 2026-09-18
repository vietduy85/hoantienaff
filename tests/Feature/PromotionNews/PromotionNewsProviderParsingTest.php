<?php

namespace Tests\Feature\PromotionNews;

use App\Services\PromotionNews\DataTransfer\PromotionNewsData;
use App\Services\PromotionNews\Exceptions\PromotionNewsProviderException;
use App\Services\PromotionNews\Providers\BhxPromotionNewsProvider;
use App\Services\PromotionNews\Providers\CoopPromotionNewsProvider;
use App\Services\PromotionNews\Providers\KingfoodmartPromotionNewsProvider;
use App\Services\PromotionNews\Providers\WinMartPromotionNewsProvider;
use Illuminate\Support\Facades\Http;
use Tests\Fixture\PromotionNews\PromotionNewsFixtures;
use Tests\TestCase;

class PromotionNewsProviderParsingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_coop_maps_promotion_vouchers_and_ignores_corporate_news(): void
    {
        Http::fake([
            PromotionNewsFixtures::COOP_URL => Http::response(PromotionNewsFixtures::coopHomepage()),
        ]);

        $items = (new CoopPromotionNewsProvider)->getNews();

        $this->assertCount(2, $items);
        $this->assertContainsOnlyInstancesOf(PromotionNewsData::class, $items);

        $first = $items[0];
        $this->assertSame('coop', $first->source);
        $this->assertSame('supermarket', $first->category);
        $this->assertSame('1001', $first->sourceId);
        $this->assertSame('Giảm 30k - Co.op Online', $first->title);
        $this->assertSame('https://lh3.googleusercontent.com/a.jpg', $first->imageUrl);
        $this->assertSame('https://cooponline.vn/giam-30k', $first->landingUrl);
        $this->assertNotNull($first->startAt);
        $this->assertNotNull($first->endAt);

        // Relative productListPath is resolved against the homepage; the
        // duplicated voucher block is de-duplicated by deterministic id.
        $second = $items[1];
        $this->assertSame('Giảm 20% Kinh Đô', $second->title);
        $this->assertSame('https://cooponline.vn/c/khuyen-mai-hot', $second->landingUrl);
        $this->assertSame(24, strlen($second->sourceId));

        // The corporate "Tin tức" slider and items missing image/link are skipped.
        $titles = array_map(fn ($i) => $i->title, $items);
        $this->assertNotContains('Tin doanh nghiệp', $titles);
        $this->assertNotContains('Không có ảnh', $titles);
        $this->assertNotContains('Không có link', $titles);
    }

    public function test_bhx_maps_only_concrete_campaigns(): void
    {
        Http::fake([
            PromotionNewsFixtures::BHX_URL => Http::response(PromotionNewsFixtures::bhxHomepage()),
        ]);

        $items = (new BhxPromotionNewsProvider)->getNews();

        $this->assertCount(1, $items);

        $item = $items[0];
        $this->assertSame('bhx', $item->source);
        $this->assertSame('111', $item->sourceId);
        $this->assertSame('Siêu sale cuối tuần', $item->title);
        $this->assertSame('https://cdnv2.tgdd.vn/a.gif', $item->imageUrl);
        $this->assertSame('https://www.bachhoaxanh.com/thuong-hieu/sieu-sale-cuoi-tuan-ct111', $item->landingUrl);
    }

    public function test_winmart_maps_header_banners_without_internal_titles(): void
    {
        Http::fake([
            PromotionNewsFixtures::WINMART_URL => Http::response(PromotionNewsFixtures::winmartHomepage()),
        ]);

        $items = (new WinMartPromotionNewsProvider)->getNews();

        $this->assertCount(3, $items);

        $this->assertNull($items[0]->title);
        $this->assertSame('wm-1', $items[0]->sourceId);
        $this->assertSame('https://winmart.vn/sieu-sale-thuong-hieu', $items[0]->landingUrl);

        $this->assertSame('Ưu đãi cuối tuần', $items[1]->title);
        $this->assertSame('https://winmart.vn/san-pham-khuyen-mai--c14', $items[1]->landingUrl);

        // Internal name falls back to the customer-facing subTitle.
        $this->assertSame('Tiêu đề hiển thị', $items[2]->title);
    }

    public function test_kingfoodmart_maps_banners_with_mobile_and_link_fallbacks(): void
    {
        Http::fake([
            PromotionNewsFixtures::KINGFOODMART_URL => Http::response(PromotionNewsFixtures::kingfoodmartHomepage()),
        ]);

        $items = (new KingfoodmartPromotionNewsProvider)->getNews();

        $this->assertCount(3, $items);

        $first = $items[0];
        $this->assertSame('kingfoodmart', $first->source);
        $this->assertSame('Hot deal cuối tuần', $first->title);
        $this->assertSame('https://storage.googleapis.com/onelife-public/a.webp', $first->imageUrl);
        $this->assertSame('https://storage.googleapis.com/onelife-public/a-m.webp', $first->mobileImageUrl);
        $this->assertSame('https://kingfoodmart.com/hot-deal/promo/kfm-1', $first->landingUrl);
        $this->assertTrue($first->isActive);
        $this->assertSame(-1000, $first->sortOrder);

        // Paused banner keeps its data but is marked inactive.
        $this->assertFalse($items[1]->isActive);

        // externalLink is used when subdirectory is empty.
        $this->assertSame('https://kingfoodmart.com/uu-dai', $items[2]->landingUrl);
    }

    public function test_provider_throws_on_http_failure(): void
    {
        Http::fake([
            PromotionNewsFixtures::COOP_URL => Http::response('', 500),
        ]);

        $this->expectException(PromotionNewsProviderException::class);

        (new CoopPromotionNewsProvider)->getNews();
    }

    public function test_provider_throws_when_payload_is_missing(): void
    {
        Http::fake([
            PromotionNewsFixtures::COOP_URL => Http::response('<html><body>no data</body></html>'),
        ]);

        $this->expectException(PromotionNewsProviderException::class);

        (new CoopPromotionNewsProvider)->getNews();
    }
}
