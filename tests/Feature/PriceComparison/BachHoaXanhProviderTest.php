<?php

namespace Tests\Feature\PriceComparison;

use App\Services\PriceComparison\DataTransfer\ProductSummary;
use App\Services\PriceComparison\DataTransfer\SearchResult;
use App\Services\PriceComparison\Providers\BachHoaXanhProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Fixture\BachHoaXanhFixture;
use Tests\TestCase;

class BachHoaXanhProviderTest extends TestCase
{
    private const SEARCH_URL = 'https://api.bachhoaxanh.com/gw/search/v2/DataSearch';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Cache::flush();

        config([
            'services.bachhoaxanh.base_url'  => 'https://api.bachhoaxanh.com/gw',
            'services.bachhoaxanh.store_id'  => BachHoaXanhFixture::STORE_ID,
            'services.bachhoaxanh.province_id' => null,
            'services.bachhoaxanh.ward_id'   => null,
            'services.bachhoaxanh.user_agent' => 'MozTestAgent/1.0',
        ]);
    }

    private function provider(): BachHoaXanhProvider
    {
        return new BachHoaXanhProvider;
    }

    private function fakeSearch(): void
    {
        Http::fake([
            self::SEARCH_URL => Http::response(BachHoaXanhFixture::searchResponse()),
        ]);
    }

    // 1. source identifier

    public function test_source_identifier_is_bach_hoa_xanh(): void
    {
        $this->assertSame('bach_hoa_xanh', $this->provider()->source());
    }

    public function test_provider_is_configured_when_store_id_present(): void
    {
        $this->assertTrue($this->provider()->isConfigured());
    }

    // 2-5. keyword behaviour

    public function test_search_returns_searchresult_of_productsummaries(): void
    {
        $this->fakeSearch();

        $result = $this->provider()->search('vinamilk');

        $this->assertInstanceOf(SearchResult::class, $result);
        $this->assertInstanceOf(Collection::class, $result->items);
        $this->assertCount(5, $result->items);
        $this->assertInstanceOf(ProductSummary::class, $result->items->first());
    }

    public function test_search_by_keyword_vinamilk(): void
    {
        $this->fakeSearch();

        $result = $this->provider()->search('vinamilk');

        $this->assertSame(99, $result->total);
        $this->assertSame('Vinamilk', $result->items->first()->brand);
    }

    public function test_search_by_keyword_vinamilk_it_duong(): void
    {
        $this->fakeSearch();

        $this->provider()->search('vinamilk ít đường');

        Http::assertSent(fn (Request $request) => $request['keywords'] === 'vinamilk ít đường');
    }

    public function test_search_by_keyword_mi_hao_hao(): void
    {
        $this->fakeSearch();

        $this->provider()->search('mì Hảo Hảo');

        Http::assertSent(fn (Request $request) => $request['keywords'] === 'mì Hảo Hảo');
    }

    public function test_search_by_keyword_nuoc_mam_nam_ngu(): void
    {
        $this->fakeSearch();

        $this->provider()->search('nước mắm Nam Ngư');

        Http::assertSent(fn (Request $request) => $request['keywords'] === 'nước mắm Nam Ngư');
    }

    // 6. ProductSummary mapping

    public function test_maps_product_to_productsummary(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('vinamilk')->items->first();

        $this->assertSame('bach_hoa_xanh', $first->source);
        $this->assertSame('235865', $first->sku);
        $this->assertSame('Lốc 4 hộp sữa tươi tiệt trùng ít đường Vinamilk Green Farm 180ml', $first->name);
    }

    // 7. price

    public function test_maps_price(): void
    {
        $this->fakeSearch();

        $this->assertSame(42000, $this->provider()->search('vinamilk')->items->first()->price);
    }

    // 8. original price

    public function test_maps_original_price(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('vinamilk')->items->first();
        $this->assertSame(42000, $first->originalPrice);

        $haoHao = $this->provider()->search('vinamilk')->items[2];
        $this->assertSame(4600, $haoHao->originalPrice);
        $this->assertSame(3700, $haoHao->price);
    }

    // 9. discount

    public function test_maps_discount_amount_and_percent(): void
    {
        $this->fakeSearch();

        $haoHao = $this->provider()->search('vinamilk')->items[2];

        $this->assertSame(900, $haoHao->discountAmount);
        $this->assertSame(20, $haoHao->discountPercent);
    }

    public function test_discount_amount_is_null_when_no_discount(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('vinamilk')->items->first();

        $this->assertNull($first->discountAmount);
    }

    // 10. stock

    public function test_maps_stock(): void
    {
        $this->fakeSearch();

        $this->assertSame(200, $this->provider()->search('vinamilk')->items->first()->stock);
    }

    // 11. sellable

    public function test_maps_sellable_true(): void
    {
        $this->fakeSearch();

        $this->assertTrue($this->provider()->search('vinamilk')->items->first()->sellable);
    }

    public function test_marks_product_unsellable_when_not_buyable(): void
    {
        $this->fakeSearch();

        $outOfStock = $this->provider()->search('vinamilk')->items[4];

        $this->assertFalse($outOfStock->sellable);
        $this->assertSame(0, $outOfStock->stock);
    }

    // 12. image

    public function test_maps_image_url(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('vinamilk')->items->first();

        $this->assertSame(
            'https://cdnv2.tgdd.vn/mwg-static/bhx/Products/Images/235865/235865.jpg',
            $first->imageUrl,
        );
    }

    // 13. product url

    public function test_maps_product_url(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('vinamilk')->items->first();

        $this->assertSame(
            'https://www.bachhoaxanh.com/sua-tuoi/loc-4-hop-sua-tuoi-tiet-trung-it-duong-vinamilk-green-farm-180ml',
            $first->productUrl,
        );
    }

    public function test_rejects_absolute_or_protocol_relative_product_url(): void
    {
        $products = BachHoaXanhFixture::products();
        $products[0]['url'] = 'https://evil.example.com/phish';
        $products[1]['url'] = '//evil.example.com/phish';
        $products[2]['url'] = 'javascript:alert(1)';
        $products[3]['url'] = 'no-leading-slash';

        Http::fake([
            self::SEARCH_URL => Http::response([
                'code' => 0,
                'data' => ['products' => $products, 'total' => 4, 'pageIndex' => 0, 'pageSize' => 20],
            ]),
        ]);

        $items = $this->provider()->search('x')->items;

        $this->assertNull($items[0]->productUrl);
        $this->assertNull($items[1]->productUrl);
        $this->assertNull($items[2]->productUrl);
        $this->assertNull($items[3]->productUrl);
    }

    public function test_maps_slug_from_product_url(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('vinamilk')->items->first();

        $this->assertSame('loc-4-hop-sua-tuoi-tiet-trung-it-duong-vinamilk-green-farm-180ml', $first->slug);
    }

    // 14. brand

    public function test_maps_brand(): void
    {
        $this->fakeSearch();

        $this->assertSame('Vinamilk', $this->provider()->search('vinamilk')->items->first()->brand);
    }

    // 15. unit

    public function test_maps_unit(): void
    {
        $this->fakeSearch();

        $this->assertSame('Lốc', $this->provider()->search('vinamilk')->items->first()->unit);
    }

    // 16. barcode / productCode

    public function test_maps_barcode_from_product_code(): void
    {
        $this->fakeSearch();

        $this->assertSame('1053141000391', $this->provider()->search('vinamilk')->items->first()->barcode);

        $haoHao = $this->provider()->search('vinamilk')->items[2];
        $this->assertSame('8934563184148', $haoHao->barcode);
    }

    public function test_maps_seller(): void
    {
        $this->fakeSearch();

        $this->assertSame('Bách Hóa Xanh', $this->provider()->search('vinamilk')->items->first()->seller);
    }

    // 17-20. pagination

    public function test_maps_pagination_metadata(): void
    {
        $this->fakeSearch();

        $result = $this->provider()->search('vinamilk', 1, 20);

        $this->assertSame(1, $result->page);
        $this->assertSame(20, $result->perPage);
        $this->assertSame(99, $result->total);
        $this->assertSame(5, $result->totalPages);
    }

    public function test_page_is_converted_to_zero_based_page_index(): void
    {
        $this->fakeSearch();

        $this->provider()->search('vinamilk', 2, 20);

        Http::assertSent(function (Request $request) {
            return $request['keywords'] === 'vinamilk'
                && $request['pageIndex'] === 1
                && $request['pageSize'] === 20
                && $request['storeId'] === 2546;
        });
    }

    public function test_page_size_is_capped_at_50(): void
    {
        $this->fakeSearch();

        $result = $this->provider()->search('vinamilk', 1, 100);

        $this->assertSame(50, $result->perPage);
        $this->assertSame(2, $result->totalPages);

        Http::assertSent(fn (Request $request) => $request['pageSize'] === 50);
    }

    public function test_sends_expected_request_payload_and_headers(): void
    {
        $this->fakeSearch();

        $this->provider()->search('vinamilk', 1, 20);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === self::SEARCH_URL
                && $request['keywords'] === 'vinamilk'
                && $request['pageIndex'] === 0
                && $request['pageSize'] === 20
                && $request['storeId'] === 2546
                && $request->hasHeader('Content-Type', 'application/json')
                && $request->hasHeader('User-Agent', 'MozTestAgent/1.0');
        });
    }

    public function test_only_sends_optional_fields_when_present(): void
    {
        $this->fakeSearch();

        $this->provider()->search('vinamilk', 1, 20);

        Http::assertSent(function (Request $request) {
            return ! array_key_exists('brandIds', $request->data())
                && ! array_key_exists('categoryIds', $request->data())
                && ! array_key_exists('sortStr', $request->data())
                && ! array_key_exists('provinceId', $request->data())
                && ! array_key_exists('wardId', $request->data());
        });
    }

    public function test_forwards_filters_when_provided(): void
    {
        $this->fakeSearch();

        $this->provider()->search('vinamilk', 1, 20, [
            'brand_ids'   => ['vinamilk', 'th'],
            'sort'        => 'PriceAcs',
            'category_ids' => [2386],
        ]);

        Http::assertSent(function (Request $request) {
            return $request['brandIds'] === 'vinamilk,th'
                && $request['categoryIds'] === 2386
                && $request['sortStr'] === 'PriceAcs';
        });
    }

    // 21-25. error handling

    public function test_handles_http_500_returns_empty_result_and_logs(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([self::SEARCH_URL => Http::response(['message' => 'server error'], 500)]);

        $result = $this->provider()->search('vinamilk', 3, 20);

        $this->assertCount(0, $result->items);
        $this->assertSame(0, $result->total);
        $this->assertSame(3, $result->page);

        Event::assertDispatched(MessageLogged::class, function (MessageLogged $event): bool {
            return $event->level === 'warning'
                && str_contains($event->message, 'HTTP error')
                && ($event->context['status'] ?? null) === 500;
        });
    }

    public function test_handles_http_400_returns_empty_result_and_logs(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([self::SEARCH_URL => Http::response(['title' => 'Bad Request'], 400)]);

        $result = $this->provider()->search('vinamilk');

        $this->assertCount(0, $result->items);

        Event::assertDispatched(MessageLogged::class, function (MessageLogged $event): bool {
            return $event->level === 'warning'
                && str_contains($event->message, 'HTTP error')
                && ($event->context['status'] ?? null) === 400;
        });
    }

    public function test_handles_connection_exception_returns_empty_result_and_logs(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([self::SEARCH_URL => fn () => throw new ConnectionException('timed out')]);

        $result = $this->provider()->search('vinamilk');

        $this->assertCount(0, $result->items);

        Event::assertDispatched(MessageLogged::class, function (MessageLogged $event): bool {
            return $event->level === 'warning'
                && str_contains($event->message, 'connection/timeout')
                && ($event->context['message'] ?? null) === 'timed out';
        });
    }

    public function test_handles_malformed_json_returns_empty_result_and_logs(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([self::SEARCH_URL => Http::response('this is not json at all', 200)]);

        $result = $this->provider()->search('vinamilk');

        $this->assertCount(0, $result->items);

        Event::assertDispatched(MessageLogged::class, fn (MessageLogged $event) => str_contains($event->message, 'malformed JSON'));
    }

    public function test_handles_non_zero_api_code_returns_empty_result_and_logs(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([self::SEARCH_URL => Http::response([
            'code' => 99, 'message' => 'Không hợp lệ', 'data' => ['products' => [], 'total' => 0],
        ])]);

        $result = $this->provider()->search('vinamilk');

        $this->assertCount(0, $result->items);

        Event::assertDispatched(MessageLogged::class, fn (MessageLogged $event) => str_contains($event->message, 'non-success code'));
    }

    public function test_handles_missing_data_returns_empty_result_and_logs(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([self::SEARCH_URL => Http::response(['code' => 0])]);

        $result = $this->provider()->search('vinamilk');

        $this->assertCount(0, $result->items);

        Event::assertDispatched(MessageLogged::class, fn (MessageLogged $event) => str_contains($event->message, 'missing data'));
    }

    public function test_empty_keyword_returns_empty_result_without_api_call(): void
    {
        $result = $this->provider()->search('   ');

        $this->assertCount(0, $result->items);
        $this->assertSame(0, $result->total);
        Http::assertNothingSent();
    }

    public function test_skips_broken_products_without_breaking_list(): void
    {
        Http::fake([
            self::SEARCH_URL => Http::response([
                'code' => 0,
                'data' => [
                    'products' => [
                        BachHoaXanhFixture::haoHao(),
                        ['name' => 'Không có id'],
                        'garbage',
                        [],
                    ],
                    'total'      => 4,
                    'pageIndex'  => 0,
                    'pageSize'   => 20,
                ],
            ]),
        ]);

        $result = $this->provider()->search('mì');

        $this->assertCount(1, $result->items);
        $this->assertSame('77619', $result->items->first()->sku);
    }

    // 26. missing store id

    public function test_missing_store_id_returns_empty_result_without_api_call(): void
    {
        Event::fake([MessageLogged::class]);

        config(['services.bachhoaxanh.store_id' => null]);

        $this->assertFalse($this->provider()->isConfigured());

        $result = $this->provider()->search('vinamilk');

        $this->assertCount(0, $result->items);
        $this->assertSame(0, $result->total);
        Http::assertNothingSent();

        Event::assertDispatched(MessageLogged::class, fn (MessageLogged $event) => str_contains($event->message, 'not configured'));
    }

    // 27. cache

    public function test_search_is_cached(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('vinamilk');
        $second = $this->provider()->search('vinamilk');

        $this->assertCount(1, Http::recorded());
        $this->assertEquals($first, $second);
    }

    public function test_cache_key_is_scoped_per_store(): void
    {
        $this->fakeSearch();

        $this->provider()->search('vinamilk');

        config(['services.bachhoaxanh.store_id' => 5757]);

        $this->provider()->search('vinamilk');

        $this->assertCount(2, Http::recorded());
    }

    public function test_errors_are_not_cached(): void
    {
        Http::fake([self::SEARCH_URL => Http::response([], 500)]);

        $this->provider()->search('vinamilk');
        $this->provider()->search('vinamilk');

        $this->assertCount(2, Http::recorded());
    }

    // 28. raw_data is internal only

    public function test_raw_data_is_kept_internal_and_not_exposed_in_to_array(): void
    {
        $this->fakeSearch();

        $product = $this->provider()->search('vinamilk')->items->first();

        $this->assertIsArray($product->rawData);
        $this->assertSame(235865, $product->rawData['id']);

        $array = $product->toArray();
        $this->assertArrayNotHasKey('raw_data', $array);
        $this->assertArrayNotHasKey('rawData', $array);
    }

    // getProduct

    public function test_get_product_returns_matching_product_from_search(): void
    {
        $this->fakeSearch();

        $product = $this->provider()->getProduct('77619');

        $this->assertInstanceOf(ProductSummary::class, $product);
        $this->assertSame('77619', $product->sku);
        $this->assertSame('Mì Hảo Hảo gà vàng gói 74g', $product->name);
        $this->assertSame(3700, $product->price);
    }

    public function test_get_product_returns_null_when_not_found(): void
    {
        $this->fakeSearch();

        $this->assertNull($this->provider()->getProduct('999999999'));
    }

    public function test_get_product_returns_null_when_store_missing(): void
    {
        config(['services.bachhoaxanh.store_id' => null]);

        $this->assertNull($this->provider()->getProduct('77619'));
        Http::assertNothingSent();
    }

    // API endpoint

    public function test_bhx_api_endpoint_returns_normalized_json(): void
    {
        $this->fakeSearch();

        $response = $this->getJson('/api/price-comparison/bhx?keyword=vinamilk');

        $response->assertOk()
            ->assertJsonPath('source', 'bach_hoa_xanh')
            ->assertJsonPath('keyword', 'vinamilk')
            ->assertJsonPath('pagination.page', 1)
            ->assertJsonPath('pagination.per_page', 20)
            ->assertJsonPath('pagination.total', 99)
            ->assertJsonPath('pagination.total_pages', 5)
            ->assertJsonCount(5, 'products')
            ->assertJsonPath('products.0.sku', '235865')
            ->assertJsonPath('products.0.price', 42000)
            ->assertJsonPath('products.0.original_price', 42000)
            ->assertJsonPath('products.0.stock', 200)
            ->assertJsonPath('products.0.sellable', true)
            ->assertJsonPath('products.0.brand', 'Vinamilk')
            ->assertJsonPath('products.0.unit', 'Lốc')
            ->assertJsonPath('products.0.barcode', '1053141000391')
            ->assertJsonPath('products.2.discount_percent', 20)
            ->assertJsonPath('products.2.discount_amount', 900)
            ->assertJsonMissingPath('products.0.raw_data')
            ->assertJsonMissingPath('products.0.rawData');
    }

    public function test_bhx_api_endpoint_requires_keyword(): void
    {
        $this->getJson('/api/price-comparison/bhx')->assertStatus(422);
    }

    public function test_bhx_api_endpoint_returns_empty_products_on_api_error(): void
    {
        Http::fake([self::SEARCH_URL => Http::response([], 500)]);

        $this->getJson('/api/price-comparison/bhx?keyword=vinamilk')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonCount(0, 'products');
    }

    public function test_bhx_api_endpoint_returns_empty_when_store_not_configured(): void
    {
        config(['services.bachhoaxanh.store_id' => null]);

        $this->getJson('/api/price-comparison/bhx?keyword=vinamilk')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonCount(0, 'products');

        Http::assertNothingSent();
    }

    // Page integration

    public function test_bhx_page_renders_real_products(): void
    {
        $this->fakeSearch();

        $this->get('/so-sanh-gia?keyword=vinamilk&retailer=bhx')
            ->assertOk()
            ->assertSee('Lốc 4 hộp sữa tươi tiệt trùng ít đường Vinamilk Green Farm 180ml', escape: false)
            ->assertSee('Mì Hảo Hảo gà vàng gói 74g', escape: false)
            ->assertSee('42.000')
            ->assertSee('BHX')
            ->assertDontSee('raw_data', escape: false)
            ->assertDontSee('productPrices', escape: false);
    }

    public function test_bhx_page_calls_bhx_api(): void
    {
        $this->fakeSearch();

        $this->get('/so-sanh-gia?keyword=vinamilk&retailer=bhx');

        Http::assertSent(fn (Request $request) => $request->url() === self::SEARCH_URL && $request['storeId'] === 2546);
    }

    public function test_bhx_page_without_store_shows_notice_and_does_not_call_api(): void
    {
        config(['services.bachhoaxanh.store_id' => null]);

        $this->get('/so-sanh-gia?keyword=vinamilk&retailer=bhx')
            ->assertOk()
            ->assertSee('chưa được cấu hình cửa hàng', escape: false);

        Http::assertNothingSent();
    }
}
