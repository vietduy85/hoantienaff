<?php

namespace Tests\Feature\PriceComparison;

use App\Services\PriceComparison\DataTransfer\ProductSummary;
use App\Services\PriceComparison\DataTransfer\SearchResult;
use App\Services\PriceComparison\Providers\CoopOnlineProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

use Tests\Fixture\CoopOnlineFixture;
use Tests\TestCase;

class CoopOnlineProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function provider(): CoopOnlineProvider
    {
        return new CoopOnlineProvider;
    }

    private function fakeSearch(): void
    {
        Http::fake([
            'https://discovery.tekoapis.com/api/v1/search' => Http::response(CoopOnlineFixture::searchResponse()),
        ]);
    }

    public function test_source_identifier_is_coop_online(): void
    {
        $this->assertSame('coop_online', $this->provider()->source());
    }

    public function test_search_returns_searchresult_of_productsummaries(): void
    {
        $this->fakeSearch();

        $result = $this->provider()->search('Mì Hảo Hảo');

        $this->assertInstanceOf(SearchResult::class, $result);
        $this->assertInstanceOf(Collection::class, $result->items);
        $this->assertCount(3, $result->items);
        $this->assertInstanceOf(ProductSummary::class, $result->items->first());
    }

    public function test_maps_sku_and_sku_id(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame('250100313', $first->sku);
        $this->assertSame('250100313', $first->skuId);
    }

    public function test_maps_name(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame('Mì Hảo Hảo vị gà vang thùng 30 x 74g', $first->name);
    }

    public function test_maps_barcode(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame('2000130544250', $first->barcode);
    }

    public function test_maps_brand(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame('Hảo Hảo', $first->brand);
    }

    public function test_maps_category(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame('Gia vị, gạo, thực phẩm khô', $first->category);
    }

    public function test_maps_image_url(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame('https://lh3.googleusercontent.com/9cvptfwJcjmim6OvSyfE2WTzb6oplDApMf_JGYZcVkrETQMayQ4Kqp7ac5m1NPPDmzDVlfdEf9QAQ4VCoi9GgNzaE_eilUaR', $first->imageUrl);
    }

    public function test_maps_latest_price_as_price(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame(122500, $first->price);
        $this->assertSame(131000, $first->supplierRetailPrice);
    }

    public function test_maps_sell_price_as_original_price(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame(131000, $first->originalPrice);
    }

    public function test_maps_discount_amount(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame(8500, $first->discountAmount);
    }

    public function test_maps_discount_percent(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame(6, $first->discountPercent);
    }

    public function test_maps_stock(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame(12, $first->stock);
    }

    public function test_maps_sellable(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertTrue($first->sellable);
    }

    public function test_maps_unit(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame('Thùng', $first->unit);
    }

    public function test_maps_verified_product_url(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì')->items->first();

        $this->assertSame('https://cooponline.vn/products/250100313', $first->productUrl);
    }

    public function test_maps_pagination_metadata(): void
    {
        $this->fakeSearch();

        $result = $this->provider()->search('mì', 2, 5);

        $this->assertSame(2, $result->page);
        $this->assertSame(5, $result->perPage);
        $this->assertSame(36, $result->total);
        $this->assertSame(2, $result->totalPages);
    }

    public function test_sends_expected_request_payload(): void
    {
        $this->fakeSearch();

        $this->provider()->search('mì', 2, 5);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://discovery.tekoapis.com/api/v1/search'
                && $request['terminalId'] === 26607
                && $request['query'] === 'mì'
                && $request['pagination']['pageNumber'] === 2
                && $request['pagination']['itemsPerPage'] === 5
                && json_encode($request['filter']) === '{}'
                && json_encode($request['sorting']) === '{}'
                && json_encode($request['block']) === '{}'
                && str_contains($request->body(), '"filter":{}')
                && str_contains($request->body(), '"sorting":{}')
                && str_contains($request->body(), '"block":{}');
        });
    }

    public function test_handles_broken_product_without_breaking_list(): void
    {
        Http::fake([
            'https://discovery.tekoapis.com/api/v1/search' => Http::response([
                'code'       => '0',
                'pagination' => ['totalItems' => 36, 'totalPages' => 2],
                'result'     => [
                    'products' => [
                        CoopOnlineFixture::products()[0],
                        ['productInfo' => ['sku' => 'SWEET-POTATO', 'name' => 'Không giá, không ảnh, không brand']],
                        ['prices' => [['latestPrice' => '1000']]],
                        [],
                    ],
                ],
            ]),
        ]);

        $result = $this->provider()->search('mì');

        $this->assertCount(2, $result->items);

        $broken = $result->items[1];
        $this->assertSame('SWEET-POTATO', $broken->sku);
        $this->assertNull($broken->price);
        $this->assertNull($broken->brand);
        $this->assertNull($broken->imageUrl);
        $this->assertNull($broken->stock);
        $this->assertFalse($broken->sellable);
        $this->assertSame('https://cooponline.vn/products/SWEET-POTATO', $broken->productUrl);
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
            'https://discovery.tekoapis.com/api/v1/search' => Http::response(['message' => 'server error'], 500),
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

    public function test_handles_connection_exception_returns_empty_result_and_logs(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([
            'https://discovery.tekoapis.com/api/v1/search' => fn () => throw new ConnectionException('timed out'),
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
            'https://discovery.tekoapis.com/api/v1/search' => Http::response('this is not json at all', 200),
        ]);

        $result = $this->provider()->search('mì');

        $this->assertCount(0, $result->items);

        Event::assertDispatched(MessageLogged::class, fn (MessageLogged $event) => str_contains($event->message, 'malformed JSON'));
    }

    public function test_handles_missing_result_products_returns_empty_result_and_logs(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([
            'https://discovery.tekoapis.com/api/v1/search' => Http::response([
                'code' => '1', 'message' => 'boom', 'pagination' => ['totalItems' => 0, 'totalPages' => 0],
            ]),
        ]);

        $result = $this->provider()->search('mì');

        $this->assertCount(0, $result->items);
        $this->assertSame(0, $result->total);

        Event::assertDispatched(MessageLogged::class, fn (MessageLogged $event) => str_contains($event->message, 'missing result.products'));
    }

    public function test_handles_empty_result(): void
    {
        Http::fake([
            'https://discovery.tekoapis.com/api/v1/search' => Http::response([
                'code'       => '0',
                'pagination' => ['totalItems' => 0, 'totalPages' => 0],
                'result'     => ['products' => []],
            ]),
        ]);

        $result = $this->provider()->search('không có sản phẩm');

        $this->assertCount(0, $result->items);
        $this->assertSame(0, $result->total);
        $this->assertSame(0, $result->totalPages);
    }

    public function test_get_product_returns_productsummary(): void
    {
        Http::fake([
            'https://discovery.tekoapis.com/api/v1/product*' => Http::response(CoopOnlineFixture::detailResponse()),
        ]);

        $product = $this->provider()->getProduct('250100313');

        $this->assertInstanceOf(ProductSummary::class, $product);
        $this->assertSame('250100313', $product->sku);
        $this->assertSame('Mì Hảo Hảo vị gà vang thùng 30 x 74g', $product->name);
        $this->assertSame(122500, $product->price);
        $this->assertSame(12, $product->stock);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://discovery.tekoapis.com/api/v1/product')
                && $request['sku'] === '250100313'
                && $request['terminalId'] === 26607;
        });
    }

    public function test_get_product_builds_direct_url(): void
    {
        Http::fake([
            'https://discovery.tekoapis.com/api/v1/product*' => Http::response(CoopOnlineFixture::detailResponse()),
        ]);

        $this->assertSame(
            'https://cooponline.vn/products/250100313',
            $this->provider()->getProduct('250100313')->productUrl,
        );
    }

    public function test_get_product_returns_null_when_http_error(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([
            'https://discovery.tekoapis.com/api/v1/product*' => Http::response([], 404),
        ]);

        $this->assertNull($this->provider()->getProduct('000'));

        Event::assertDispatched(MessageLogged::class, function (MessageLogged $event): bool {
            return $event->level === 'warning'
                && str_contains($event->message, 'HTTP error')
                && ($event->context['status'] ?? null) === 404;
        });
    }

    public function test_get_product_returns_null_when_product_not_found_in_response(): void
    {
        Http::fake([
            'https://discovery.tekoapis.com/api/v1/product*' => Http::response([
                'code' => '0', 'message' => 'success', 'result' => [],
            ]),
        ]);

        $this->assertNull($this->provider()->getProduct('250100313'));
    }

    public function test_search_is_cached_for_short_ttl(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì');
        $second = $this->provider()->search('mì');

        $this->assertCount(1, Http::recorded());
        $this->assertEquals($first, $second);
    }

    public function test_get_product_is_cached_for_short_ttl(): void
    {
        Http::fake([
            'https://discovery.tekoapis.com/api/v1/product*' => Http::response(CoopOnlineFixture::detailResponse()),
        ]);

        $this->provider()->getProduct('250100313');
        $this->provider()->getProduct('250100313');

        $this->assertCount(1, Http::recorded());
    }
}