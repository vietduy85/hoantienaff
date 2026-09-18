<?php

namespace Tests\Feature\PriceComparison;

use App\Services\PriceComparison\DataTransfer\ProductSummary;
use App\Services\PriceComparison\DataTransfer\SearchResult;
use App\Services\PriceComparison\Providers\WinMartProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Fixture\WinMartFixture;
use Tests\TestCase;

class WinMartProviderTest extends TestCase
{
    private const SEARCH_URL = 'https://api-crownx.winmart.vn/ss/api/v2/public/winmart/item-search';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function provider(): WinMartProvider
    {
        return new WinMartProvider;
    }

    private function fakeSearch(array $overrides = []): void
    {
        Http::fake([
            self::SEARCH_URL => Http::response(WinMartFixture::searchResponse($overrides)),
        ]);
    }

    public function test_source_identifier_is_winmart(): void
    {
        $this->assertSame('winmart', $this->provider()->source());
    }

    public function test_search_returns_searchresult_of_productsummaries(): void
    {
        $this->fakeSearch();

        $result = $this->provider()->search('mì Hảo Hảo');

        $this->assertInstanceOf(SearchResult::class, $result);
        $this->assertInstanceOf(Collection::class, $result->items);
        $this->assertCount(4, $result->items);
        $this->assertInstanceOf(ProductSummary::class, $result->items->first());
    }

    public function test_sends_expected_request_payload(): void
    {
        $this->fakeSearch();

        $this->provider()->search('mì Hảo Hảo', 2, 5);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === self::SEARCH_URL
                && $request['keyword'] === 'mì Hảo Hảo'
                && $request['storeGroupCode'] === '1998'
                && $request['storeNo'] === '1535'
                && $request['applicationType'] === 'Winmart'
                && $request['pageNumber'] === 2
                && $request['pageSize'] === 5;
        });
    }

    public function test_page_size_is_capped_at_one_hundred(): void
    {
        $this->fakeSearch();

        $this->provider()->search('mì', 1, 200);

        Http::assertSent(fn (Request $request) => $request['pageSize'] === 100);
    }

    public function test_maps_sku_and_sku_id(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame('10008453G1', $first->sku);
        $this->assertSame('6aab0b97a5c85c542c4e545f', $first->skuId);
    }

    public function test_maps_name_from_description(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame('Mì Vị Mì Hảo Hảo Mì gà vàng 74g', $first->name);
    }

    public function test_maps_brand(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame('HẢO HẢO', $first->brand);
    }

    public function test_maps_category_from_mch5_name(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame('Mì ăn liền', $first->category);
    }

    public function test_maps_sale_price_as_price(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame(4700, $first->price);
    }

    public function test_maps_origin_price_as_original_price(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame(4700, $first->originalPrice);
    }

    public function test_maps_discount_rate_as_discount_percent(): void
    {
        $this->fakeSearch();

        $thung = $this->provider()->search('mì')->items[1];

        $this->assertSame(5, $thung->discountPercent);
    }

    public function test_computes_discount_amount_from_price_delta(): void
    {
        $this->fakeSearch();

        $thung = $this->provider()->search('mì')->items[1];

        $this->assertSame(6700, $thung->discountAmount);
    }

    public function test_no_discount_when_sale_equals_origin(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertNull($first->discountAmount);
        $this->assertSame(0, $first->discountPercent);
    }

    public function test_maps_image_url(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame(
            'https://s3-hcmc02.higiocloud.vn/images/2024/11/10008453-20241119120220.jpg',
            $first->imageUrl,
        );
    }

    public function test_builds_product_url_with_store_query(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame(
            'https://www.winmart.vn/products/hao-hao-mi-vi-mi-ga-vang-74g--s10008453?storeGroupCode=1998&storeCode=1535',
            $first->productUrl,
        );
    }

    public function test_maps_stock_from_warehouse(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame(386, $first->stock);
    }

    public function test_sellable_requires_published_and_positive_stock(): void
    {
        $this->fakeSearch();

        $items = $this->provider()->search('mì')->items;

        $this->assertTrue($items[0]->sellable);
        $this->assertTrue($items[1]->sellable);
        $this->assertFalse($items[2]->sellable);
        $this->assertFalse($items[3]->sellable);
    }

    public function test_keeps_each_uom_as_separate_item(): void
    {
        $this->fakeSearch();

        $skus = $this->provider()->search('mì')->items
            ->map(fn ($p) => $p->sku)
            ->all();

        $this->assertContains('10008453G1', $skus);
        $this->assertContains('10008453T', $skus);
    }

    public function test_maps_unit_from_uom_name(): void
    {
        $this->fakeSearch();

        $items = $this->provider()->search('mì')->items;

        $this->assertSame('Gói', $items[0]->unit);
        $this->assertSame('Thùng', $items[1]->unit);
    }

    public function test_maps_slug_from_seo_name(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame('hao-hao-mi-vi-mi-ga-vang-74g--s10008453', $first->slug);
    }

    public function test_maps_seller_to_winmart(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame('WinMart', $first->seller);
    }

    public function test_search_item_has_no_barcode(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertNull($first->barcode);
    }

    public function test_maps_pagination_metadata(): void
    {
        $this->fakeSearch([
            'paging' => ['pageNumber' => 2, 'pageSize' => 5, 'totalPages' => 50],
        ]);

        $result = $this->provider()->search('mì', 2, 5);

        $this->assertSame(2, $result->page);
        $this->assertSame(5, $result->perPage);
        $this->assertSame(249, $result->total);
        $this->assertSame(50, $result->totalPages);
    }

    public function test_normalizes_keyword_before_sending(): void
    {
        $this->fakeSearch();

        $this->provider()->search('  mì   Hảo  Hảo  ');

        Http::assertSent(fn (Request $request) => $request['keyword'] === 'mì Hảo Hảo');
    }

    public function test_refuses_unsafe_product_page_segments(): void
    {
        Http::fake([
            self::SEARCH_URL => Http::response(WinMartFixture::searchResponse([
                'data' => [
                    array_replace(WinMartFixture::goi(), [
                        'id' => 'x-y-z',
                        'sku' => 'EVIL-SKU',
                        'seoName' => 'https://evil.example/x?q=1',
                    ]),
                ],
                'paging' => ['totalCount' => 1, 'pageSize' => 20, 'totalPages' => 1],
            ])),
        ]);

        $first = $this->provider()->search('mì')->items->first();

        $this->assertNotNull($first);
        $this->assertNull($first->productUrl);
    }

    public function test_empty_keyword_returns_empty_result_without_api_call(): void
    {
        Http::preventStrayRequests();

        $result = $this->provider()->search('   ');

        $this->assertCount(0, $result->items);
        $this->assertSame(0, $result->total);
        $this->assertSame(0, $result->totalPages);
    }

    public function test_handles_http_error_returns_empty_result_and_logs(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([
            self::SEARCH_URL => Http::response(['message' => 'server error'], 500),
        ]);

        $result = $this->provider()->search('mì', 3, 20);

        $this->assertCount(0, $result->items);
        $this->assertSame(0, $result->total);
        $this->assertSame(3, $result->page);

        Event::assertDispatched(MessageLogged::class, function (MessageLogged $event): bool {
            return $event->level === 'warning'
                && str_contains($event->message, 'HTTP error')
                && ($event->context['status'] ?? null) === 500;
        });
    }

    public function test_handles_validation_error_returns_empty_result_and_logs(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([
            self::SEARCH_URL => Http::response([
                'message' => ['pageSize must be less than or equal to 100'],
                'developerMessage' => null,
            ], 400),
        ]);

        $result = $this->provider()->search('mì');

        $this->assertCount(0, $result->items);
        $this->assertSame(0, $result->total);

        Event::assertDispatched(MessageLogged::class, fn (MessageLogged $event) => str_contains($event->message, 'HTTP error') && ($event->context['status'] ?? null) === 400);
    }

    public function test_handles_connection_exception_returns_empty_result_and_logs(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([
            self::SEARCH_URL => fn () => throw new ConnectionException('timed out'),
        ]);

        $result = $this->provider()->search('mì');

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

        Http::fake([
            self::SEARCH_URL => Http::response('this is not json at all', 200),
        ]);

        $result = $this->provider()->search('mì');

        $this->assertCount(0, $result->items);

        Event::assertDispatched(MessageLogged::class, fn (MessageLogged $event) => str_contains($event->message, 'malformed JSON'));
    }

    public function test_handles_missing_data_returns_empty_result_and_logs(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([
            self::SEARCH_URL => Http::response([
                'message' => null,
                'paging' => ['totalCount' => 0, 'pageSize' => 20, 'totalPages' => 0],
            ]),
        ]);

        $result = $this->provider()->search('mì');

        $this->assertCount(0, $result->items);
        $this->assertSame(0, $result->total);

        Event::assertDispatched(MessageLogged::class, fn (MessageLogged $event) => str_contains($event->message, 'missing data'));
    }

    public function test_skips_broken_items_without_breaking_list(): void
    {
        $this->fakeSearch();

        $items = $this->provider()->search('mì')->items;

        $this->assertCount(4, $items);
        $this->assertSame('10008453G1', $items[0]->sku);
        $this->assertSame('10008451G1', $items->last()->sku);
    }

    // -------------------------------------------------------- caching / store

    public function test_search_is_cached_for_short_ttl(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì');
        $second = $this->provider()->search('mì');

        $this->assertCount(1, Http::recorded());
        $this->assertEquals($first, $second);
    }

    public function test_cache_key_isolates_different_stores(): void
    {
        $this->fakeSearch();

        $default = $this->provider();
        $default->search('mì');

        config(['services.winmart.store_no' => '4421']);

        $this->provider()->search('mì');

        $this->assertCount(2, Http::recorded());
    }

    public function test_cache_key_isolates_different_store_groups(): void
    {
        $this->fakeSearch();

        $this->provider()->search('mì');

        config(['services.winmart.store_group_code' => '1994']);

        $this->provider()->search('mì');

        $this->assertCount(2, Http::recorded());
    }

    // ---------------------------------------------------------------- getProduct

    public function test_get_product_returns_exact_sku_row(): void
    {
        $this->fakeSearch();

        $product = $this->provider()->getProduct('10008453G1');

        $this->assertInstanceOf(ProductSummary::class, $product);
        $this->assertSame('10008453G1', $product->sku);
        $this->assertSame('Mì Vị Mì Hảo Hảo Mì gà vàng 74g', $product->name);
        $this->assertSame(4700, $product->price);
        $this->assertSame(386, $product->stock);
        $this->assertTrue($product->sellable);
    }

    public function test_get_product_prefers_smallest_unit_for_item_no(): void
    {
        $this->fakeSearch();

        $product = $this->provider()->getProduct('10008453');

        $this->assertSame('10008453G1', $product->sku);
        $this->assertSame(1, $product->rawData['quantityPerUnit']);
    }

    public function test_get_product_falls_back_to_item_no_when_sku_has_no_results(): void
    {
        Http::fake([
            self::SEARCH_URL => function (Request $request) {
                $keyword = $request['keyword'];

                if ($keyword === '10008453G1') {
                    return Http::response([
                        'message' => null,
                        'data' => [],
                        'paging' => ['totalCount' => 0, 'pageSize' => 100, 'totalPages' => 0],
                    ]);
                }

                return Http::response(WinMartFixture::searchResponse());
            },
        ]);

        $product = $this->provider()->getProduct('10008453G1');

        $this->assertSame('10008453G1', $product->sku);
        $this->assertSame(4700, $product->price);
    }

    public function test_get_product_returns_null_when_item_no_unknown(): void
    {
        Http::fake([
            self::SEARCH_URL => Http::response([
                'message' => null,
                'data' => [],
                'paging' => ['totalCount' => 0, 'pageSize' => 100, 'totalPages' => 0],
            ]),
        ]);

        $this->assertNull($this->provider()->getProduct('99999999'));
    }

    public function test_get_product_returns_null_when_http_error(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([
            self::SEARCH_URL => Http::response([], 500),
        ]);

        $this->assertNull($this->provider()->getProduct('10008453'));

        Event::assertDispatched(MessageLogged::class, function (MessageLogged $event): bool {
            return $event->level === 'warning'
                && str_contains($event->message, 'HTTP error')
                && ($event->context['status'] ?? null) === 500;
        });
    }

    public function test_get_product_returns_null_for_empty_identifier(): void
    {
        $this->assertNull($this->provider()->getProduct('  '));

        Http::assertNothingSent();
    }

    public function test_get_product_is_cached_for_short_ttl(): void
    {
        $this->fakeSearch();

        $this->provider()->getProduct('10008453G1');
        $this->provider()->getProduct('10008453G1');

        $this->assertCount(1, Http::recorded());
    }

    // promotions stay out of scope for WinMart

    public function test_promotions_remain_null(): void
    {
        $this->fakeSearch();

        $product = $this->provider()->search('mì')->items->first();

        $this->assertNull($product->promotions);
        $this->assertFalse($product->hasPromotion());
        $this->assertNull($product->toArray()['promotions']);
    }
}
