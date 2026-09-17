<?php

namespace Tests\Feature\PriceComparison;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixture\CoopOnlineFixture;
use Tests\TestCase;

class PriceComparisonPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function fakeCoopSearch(array $overrides = []): void
    {
        Http::fake([
            'https://discovery.tekoapis.com/api/v1/search' => Http::response(
                CoopOnlineFixture::searchResponse($overrides),
            ),
        ]);
    }

    private function fakeCoopEmpty(): void
    {
        Http::fake([
            'https://discovery.tekoapis.com/api/v1/search' => Http::response([
                'code'       => '0',
                'pagination' => ['totalItems' => 0, 'totalPages' => 0],
                'result'     => ['products' => []],
            ]),
        ]);
    }

    private function fakeCoopWithPrices(array $products): void
    {
        Http::fake([
            'https://discovery.tekoapis.com/api/v1/search' => Http::response([
                'code'       => '0',
                'pagination' => ['totalItems' => count($products), 'totalPages' => 1],
                'result'     => ['products' => $products],
            ]),
        ]);
    }

    public function test_guest_can_access_page(): void
    {
        $this->fakeCoopEmpty();

        $this->get('/so-sanh-gia')->assertOk();
    }

    public function test_page_contains_search_form(): void
    {
        $this->get('/so-sanh-gia')
            ->assertOk()
            ->assertSee('form', escape: false)
            ->assertSee('keyword')
            ->assertSee('Mì Hảo Hảo', escape: false);
    }

    public function test_search_keyword_is_passed_to_provider(): void
    {
        $this->fakeCoopEmpty();

        $this->get('/so-sanh-gia?keyword=sữa+Vinamilk&retailer=coop');

        Http::assertSent(function (Request $request) {
            return $request['query'] === 'sữa Vinamilk';
        });
    }

    public function test_coop_provider_is_called(): void
    {
        $this->fakeCoopEmpty();

        $this->get('/so-sanh-gia?keyword=mi&retailer=coop');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://discovery.tekoapis.com/api/v1/search'
                && $request['terminalId'] === 26607;
        });
    }

    public function test_product_name_is_rendered(): void
    {
        $this->fakeCoopSearch();

        $this->get('/so-sanh-gia?keyword=mì&retailer=coop')
            ->assertOk()
            ->assertSee('Mì Hảo Hảo vị gà vang thùng 30 x 74g', escape: false)
            ->assertSee('Mì Hảo Hảo vị gà vàng gói 74g', escape: false);
    }

    public function test_price_is_formatted_correctly(): void
    {
        $this->fakeCoopSearch();

        $this->get('/so-sanh-gia?keyword=mì&retailer=coop')
            ->assertSee('122.500')
            ->assertSee('3.500');
    }

    public function test_original_price_is_shown_when_different(): void
    {
        $this->fakeCoopSearch();

        $this->get('/so-sanh-gia?keyword=mì&retailer=coop')
            ->assertSee('131.000', escape: false);
    }

    public function test_discount_badge_is_displayed(): void
    {
        $this->fakeCoopSearch();

        $this->get('/so-sanh-gia?keyword=mì&retailer=coop')
            ->assertSee('-6%', escape: false);
    }

    public function test_image_url_is_rendered(): void
    {
        $this->fakeCoopSearch();

        $this->get('/so-sanh-gia?keyword=mì&retailer=coop')
            ->assertSee('lh3.googleusercontent.com', escape: false);
    }

    public function test_product_url_is_rendered(): void
    {
        $this->fakeCoopSearch();

        $this->get('/so-sanh-gia?keyword=mì&retailer=coop')
            ->assertSee('cooponline.vn/products/250100313', escape: false)
            ->assertSee('cooponline.vn/products/250100218', escape: false);
    }

    public function test_product_source_badge_is_rendered(): void
    {
        $this->fakeCoopSearch();

        $response = $this->get('/so-sanh-gia?keyword=mì&retailer=coop');

        $badges = substr_count($response->getContent(), '>Co.op</span>');
        $this->assertGreaterThanOrEqual(2, $badges);
    }

    public function test_sort_price_asc(): void
    {
        $this->fakeCoopWithPrices([
            // expensive first in API
            [
                'productInfo'  => ['sku' => 'A', 'name' => 'Sản phẩm A', 'uomName' => 'Gói'],
                'prices'       => [['latestPrice' => '150000', 'sellPrice' => '150000', 'discountAmount' => '0', 'discountPercent' => 0]],
                'totalAvailable' => 10,
                'status'       => ['sellable' => true],
            ],
            // cheap second in API
            [
                'productInfo'  => ['sku' => 'B', 'name' => 'Sản phẩm B', 'uomName' => 'Chai'],
                'prices'       => [['latestPrice' => '50000', 'sellPrice' => '50000', 'discountAmount' => '0', 'discountPercent' => 0]],
                'totalAvailable' => 20,
                'status'       => ['sellable' => true],
            ],
        ]);

        $response = $this->get('/so-sanh-gia?keyword=test&retailer=coop&sort=price_asc');
        $content = $response->getContent();

        $posB = strpos($content, '50.000');
        $posA = strpos($content, '150.000');
        $this->assertNotFalse($posB);
        $this->assertNotFalse($posA);
        $this->assertLessThan($posA, $posB);
    }

    public function test_sort_price_desc(): void
    {
        $this->fakeCoopWithPrices([
            [
                'productInfo'  => ['sku' => 'A', 'name' => 'Sản phẩm A', 'uomName' => 'Gói'],
                'prices'       => [['latestPrice' => '150000', 'sellPrice' => '150000', 'discountAmount' => '0', 'discountPercent' => 0]],
                'totalAvailable' => 10,
                'status'       => ['sellable' => true],
            ],
            [
                'productInfo'  => ['sku' => 'B', 'name' => 'Sản phẩm B', 'uomName' => 'Chai'],
                'prices'       => [['latestPrice' => '50000', 'sellPrice' => '50000', 'discountAmount' => '0', 'discountPercent' => 0]],
                'totalAvailable' => 20,
                'status'       => ['sellable' => true],
            ],
        ]);

        $response = $this->get('/so-sanh-gia?keyword=test&retailer=coop&sort=price_desc');
        $content = $response->getContent();

        $posA = strpos($content, '150.000');
        $posB = strpos($content, '50.000');
        $this->assertNotFalse($posA);
        $this->assertNotFalse($posB);
        $this->assertLessThan($posB, $posA);
    }

    public function test_empty_result_shows_message(): void
    {
        $this->fakeCoopEmpty();

        $this->get('/so-sanh-gia?keyword=không+có&retailer=coop')
            ->assertOk()
            ->assertSee('Không tìm thấy sản phẩm phù hợp', escape: false);
    }

    public function test_api_error_does_not_crash(): void
    {
        Http::preventStrayRequests();

        $this->app->bind(\App\Services\PriceComparison\PriceComparisonManager::class, fn () => new \App\Services\PriceComparison\PriceComparisonManager());

        $this->get('/so-sanh-gia?keyword=test&retailer=coop')
            ->assertOk()
            ->assertSee('Không thể lấy dữ liệu lúc này', escape: false);
    }

    public function test_raw_data_not_exposed_in_page(): void
    {
        $this->fakeCoopSearch();

        $this->get('/so-sanh-gia?keyword=mì&retailer=coop')
            ->assertDontSee('rawData', escape: false)
            ->assertDontSee('raw_data', escape: false)
            ->assertDontSee('productInfo', escape: false);
    }

    public function test_unsupported_retailer_does_not_fake_products(): void
    {
        $this->get('/so-sanh-gia?keyword=test&retailer=winmart')
            ->assertOk()
            ->assertSee('Nguồn giá này đang được cập nhật', escape: false);

        Http::assertNothingSent();
    }

    public function test_no_keyword_shows_intro(): void
    {
        $this->get('/so-sanh-gia')
            ->assertOk()
            ->assertSee('Khám phá giá tốt hơn', escape: false)
            ->assertSee('So sánh giá sản phẩm tại các siêu thị', escape: false);
    }
}