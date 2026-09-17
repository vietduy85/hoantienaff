<?php

namespace Tests\Feature\PriceComparison;

use App\Services\PriceComparison\DataTransfer\ProductSummary;
use App\Services\PriceComparison\DataTransfer\SearchResult;
use App\Services\PriceComparison\Providers\KingfoodmartProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Fixture\KingfoodmartFixture;
use Tests\TestCase;

class KingfoodmartProviderTest extends TestCase
{
    private const BASE = 'https://onelife-api.kingfoodmart.com/v1';

    private const SEARCH_URL = self::BASE.'/products/search';

    private const DETAIL_URL = self::BASE.'/products/variants/';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Cache::flush();

        config([
            'services.kingfoodmart.base_url' => self::BASE,
            'services.kingfoodmart.tenant' => 'kingfood',
        ]);
    }

    private function provider(): KingfoodmartProvider
    {
        return new KingfoodmartProvider;
    }

    private function fakeSearch(?array $response = null): void
    {
        Http::fake([
            self::SEARCH_URL.'*' => Http::response($response ?? KingfoodmartFixture::searchResponse()),
        ]);
    }

    private function fakeSearchAndDetail(): void
    {
        Http::fake([
            self::SEARCH_URL.'*' => Http::response(KingfoodmartFixture::searchResponse()),
            self::DETAIL_URL.'*' => Http::response(KingfoodmartFixture::detailResponse()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function query(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function validProducts(): array
    {
        return [
            KingfoodmartFixture::haoHaoKimChi(),
            KingfoodmartFixture::haoHaoThung30(),
        ];
    }

    // 1. source identifier

    public function test_source_identifier_is_kingfoodmart(): void
    {
        $this->assertSame('kingfoodmart', $this->provider()->source());
    }

    // 2. successful search

    public function test_search_returns_searchresult_of_productsummaries(): void
    {
        $this->fakeSearch();

        $result = $this->provider()->search('mì Hảo Hảo');

        $this->assertInstanceOf(SearchResult::class, $result);
        $this->assertInstanceOf(Collection::class, $result->items);
        $this->assertCount(2, $result->items);
        $this->assertInstanceOf(ProductSummary::class, $result->items->first());
    }

    // 3-6. request parameters

    public function test_keyword_is_sent(): void
    {
        $this->fakeSearch();

        $this->provider()->search('mì Hảo Hảo');

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::SEARCH_URL)
            && $this->query($request)['keyword'] === 'mì Hảo Hảo');
    }

    public function test_type_is_sent_as_normal(): void
    {
        $this->fakeSearch();

        $this->provider()->search('mì Hảo Hảo');

        Http::assertSent(fn (Request $request) => ($this->query($request)['type'] ?? null) === 'NORMAL');
    }

    public function test_page_is_sent_one_based(): void
    {
        $this->fakeSearch();

        $this->provider()->search('mì Hảo Hảo', 2, 30);

        Http::assertSent(fn (Request $request) => (int) ($this->query($request)['page'] ?? 0) === 2);
    }

    public function test_limit_is_sent(): void
    {
        $this->fakeSearch();

        $this->provider()->search('mì Hảo Hảo', 1, 30);

        Http::assertSent(fn (Request $request) => (int) ($this->query($request)['limit'] ?? 0) === 30);
    }

    public function test_limit_is_capped_at_100(): void
    {
        $this->fakeSearch();

        $result = $this->provider()->search('mì Hảo Hảo', 1, 500);

        Http::assertSent(fn (Request $request) => (int) ($this->query($request)['limit'] ?? 0) === 100);
        $this->assertLessThanOrEqual(100, $result->perPage);
    }

    // 7-9. pagination

    public function test_maps_pagination_metadata(): void
    {
        $this->fakeSearch();

        $result = $this->provider()->search('mì Hảo Hảo', 1, 30);

        $this->assertSame(1, $result->page);
        $this->assertSame(30, $result->perPage);
        $this->assertSame(137, $result->total);
        $this->assertSame(5, $result->totalPages);
    }

    public function test_total_pages_uses_last_page_field(): void
    {
        $this->fakeSearch(KingfoodmartFixture::searchResponse([
            'pagination' => ['total' => 137, 'currentPage' => 3, 'lastPage' => 5, 'limit' => 30],
        ]));

        $result = $this->provider()->search('mì Hảo Hảo', 3, 30);

        $this->assertSame(3, $result->page);
        $this->assertSame(5, $result->totalPages);
    }

    public function test_total_pages_falls_back_to_ceiling_when_last_page_missing(): void
    {
        Http::fake([
            self::SEARCH_URL.'*' => Http::response([
                'products' => $this->validProducts(),
                'pagination' => ['total' => 45, 'currentPage' => 1, 'limit' => 20],
            ]),
        ]);

        $result = $this->provider()->search('mì Hảo Hảo', 1, 20);

        $this->assertSame(45, $result->total);
        $this->assertSame(3, $result->totalPages);
    }

    public function test_missing_pagination_does_not_throw(): void
    {
        Http::fake([
            self::SEARCH_URL.'*' => Http::response(['products' => $this->validProducts()]),
        ]);

        $result = $this->provider()->search('mì Hảo Hảo', 1, 20);

        $this->assertCount(2, $result->items);
        $this->assertSame(0, $result->total);
        $this->assertSame(0, $result->totalPages);
    }

    // 10-15. product mapping

    public function test_maps_product_to_productsummary(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì Hảo Hảo')->items->first();

        $this->assertSame('kingfoodmart', $first->source);
        $this->assertSame(KingfoodmartFixture::SKU, $first->sku);
        $this->assertSame('Mì Hảo Hảo Acecook hương vị lẩu kim chi Hàn Quốc gói 75g', $first->name);
    }

    public function test_maps_sku_from_first_variant(): void
    {
        $this->fakeSearch();

        $this->assertSame(
            KingfoodmartFixture::SKU,
            $this->provider()->search('mì Hảo Hảo')->items->first()->sku,
        );
    }

    public function test_maps_sku_id_from_pid(): void
    {
        $this->fakeSearch();

        $this->assertSame(
            KingfoodmartFixture::VARIANT_ID,
            $this->provider()->search('mì Hảo Hảo')->items->first()->skuId,
        );
    }

    public function test_maps_sku_falls_back_to_pid_when_variant_has_no_sku(): void
    {
        $product = KingfoodmartFixture::haoHaoKimChi();
        unset($product['variants'][0]['sku']);

        Http::fake([self::SEARCH_URL.'*' => Http::response(['products' => [$product]])]);

        $this->assertSame(
            KingfoodmartFixture::VARIANT_ID,
            $this->provider()->search('mì Hảo Hảo')->items->first()->sku,
        );
    }

    public function test_maps_barcode_from_variant_sku(): void
    {
        $this->fakeSearch();

        $this->assertSame(
            KingfoodmartFixture::SKU,
            $this->provider()->search('mì Hảo Hảo')->items->first()->barcode,
        );
    }

    public function test_maps_price(): void
    {
        $this->fakeSearch();

        $this->assertSame(4700, $this->provider()->search('mì Hảo Hảo')->items->first()->price);
    }

    public function test_maps_original_price(): void
    {
        $this->fakeSearch();

        $this->assertSame(120000, $this->provider()->search('mì Hảo Hảo')->items[1]->originalPrice);
    }

    public function test_maps_discount_amount_and_percent(): void
    {
        $this->fakeSearch();

        $items = $this->provider()->search('mì Hảo Hảo')->items;

        $this->assertSame(0, $items->first()->discountAmount);
        $this->assertSame(0, $items->first()->discountPercent);

        $this->assertSame(21000, $items[1]->discountAmount);
        $this->assertSame(17, $items[1]->discountPercent);
    }

    public function test_discount_amount_is_null_when_prices_missing(): void
    {
        $product = KingfoodmartFixture::haoHaoThung30();
        unset($product['originalPrice']);

        Http::fake([self::SEARCH_URL.'*' => Http::response(['products' => [$product]])]);

        $this->assertNull($this->provider()->search('mì Hảo Hảo')->items->first()->discountAmount);
    }

    // 16-17. image / URL

    public function test_maps_image_url_from_thumbnail(): void
    {
        $this->fakeSearch();

        $this->assertSame(
            'https://img.onelife.vn/rs:fit:300:300:1/hao-hao-kim-chi.webp',
            $this->provider()->search('mì Hảo Hảo')->items->first()->imageUrl,
        );
    }

    public function test_maps_image_url_falls_back_to_first_image(): void
    {
        $product = KingfoodmartFixture::haoHaoKimChi();
        unset($product['thumbnail']);
        $product['images'] = ['https://img.onelife.vn/fallback.webp'];

        Http::fake([self::SEARCH_URL.'*' => Http::response(['products' => [$product]])]);

        $this->assertSame(
            'https://img.onelife.vn/fallback.webp',
            $this->provider()->search('mì Hảo Hảo')->items->first()->imageUrl,
        );
    }

    public function test_maps_product_url(): void
    {
        $this->fakeSearch();

        $this->assertSame(
            'https://kingfoodmart.com/mi-an-lien-1356/mi-hao-hao-huong-vi-lau-kim-chi-han-quoc-acecook-75g-1-goi',
            $this->provider()->search('mì Hảo Hảo')->items->first()->productUrl,
        );
    }

    public function test_product_url_is_null_when_slug_is_not_a_plain_path_segment(): void
    {
        $product = KingfoodmartFixture::haoHaoKimChi();
        $product['slug'] = 'https://evil.example.com/phish';

        $other = KingfoodmartFixture::haoHaoThung30();
        $other['subCate'] = '../../etc';

        Http::fake([self::SEARCH_URL.'*' => Http::response(['products' => [$product, $other]])]);

        $items = $this->provider()->search('mì Hảo Hảo')->items;

        $this->assertNull($items[0]->productUrl);
        $this->assertNull($items[1]->productUrl);
    }

    // 18-20. stock / sellable / unit

    public function test_maps_stock_from_in_stock(): void
    {
        $this->fakeSearch();

        $this->assertSame(7463, $this->provider()->search('mì Hảo Hảo')->items->first()->stock);
    }

    public function test_maps_stock_falls_back_to_variant_stock_item(): void
    {
        $product = KingfoodmartFixture::haoHaoKimChi();
        unset($product['inStock']);

        Http::fake([self::SEARCH_URL.'*' => Http::response(['products' => [$product]])]);

        $this->assertSame(7463, $this->provider()->search('mì Hảo Hảo')->items->first()->stock);
    }

    public function test_maps_sellable_true(): void
    {
        $this->fakeSearch();

        $this->assertTrue($this->provider()->search('mì Hảo Hảo')->items->first()->sellable);
    }

    public function test_marks_product_unsellable_when_flags_are_false(): void
    {
        $product = KingfoodmartFixture::haoHaoKimChi();
        $product['variants'][0]['isOnlineSale'] = false;
        $product['variants'][0]['isSale'] = false;

        Http::fake([self::SEARCH_URL.'*' => Http::response(['products' => [$product]])]);

        $this->assertFalse($this->provider()->search('mì Hảo Hảo')->items->first()->sellable);
    }

    public function test_marks_product_unsellable_when_inactive(): void
    {
        $product = KingfoodmartFixture::haoHaoKimChi();
        $product['isActive'] = false;

        Http::fake([self::SEARCH_URL.'*' => Http::response(['products' => [$product]])]);

        $this->assertFalse($this->provider()->search('mì Hảo Hảo')->items->first()->sellable);
    }

    public function test_maps_unit_from_representative_variant(): void
    {
        $this->fakeSearch();

        $items = $this->provider()->search('mì Hảo Hảo')->items;

        $this->assertSame('1 Gói', $items->first()->unit);
        $this->assertSame('Thùng 30 gói', $items[1]->unit);
    }

    public function test_representative_variant_prefers_a_variant_on_sale(): void
    {
        $this->fakeSearch();

        $second = $this->provider()->search('mì Hảo Hảo')->items[1];

        $this->assertSame('8934563184162', $second->sku);
        $this->assertSame('8934563184162', $second->barcode);
    }

    // 21-23. category / brand / manufacturer / seller

    public function test_maps_category_from_subcate_name(): void
    {
        $this->fakeSearch();

        $this->assertSame('Mì ăn liền', $this->provider()->search('mì Hảo Hảo')->items->first()->category);
    }

    public function test_maps_categories_from_response(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì Hảo Hảo')->items->first();

        $this->assertIsArray($first->categories);
        $this->assertSame('Mì ăn liền', $first->categories[0]['name']);
    }

    public function test_maps_brand_from_brand_detail_when_present(): void
    {
        $product = KingfoodmartFixture::haoHaoKimChi();
        $product['brandDetail'] = ['id' => '1', 'name' => 'Acecook'];

        Http::fake([self::SEARCH_URL.'*' => Http::response(['products' => [$product]])]);

        $this->assertSame('Acecook', $this->provider()->search('mì Hảo Hảo')->items->first()->brand);
    }

    public function test_brand_is_null_when_not_provided(): void
    {
        $this->fakeSearch();

        $this->assertNull($this->provider()->search('mì Hảo Hảo')->items->first()->brand);
    }

    public function test_maps_manufacturer_from_description_json(): void
    {
        $this->fakeSearch();

        $this->assertSame(
            'Acecook Việt Nam',
            $this->provider()->search('mì Hảo Hảo')->items->first()->manufacturer,
        );
    }

    public function test_maps_seller_from_current_seller_with_fallback(): void
    {
        $this->fakeSearch();

        $items = $this->provider()->search('mì Hảo Hảo')->items;

        $this->assertSame('Kingfoodmart', $items->first()->seller);
        $this->assertSame('Kingfoodmart Online', $items[1]->seller);
    }

    public function test_maps_slug(): void
    {
        $this->fakeSearch();

        $this->assertSame(
            'mi-hao-hao-huong-vi-lau-kim-chi-han-quoc-acecook-75g-1-goi',
            $this->provider()->search('mì Hảo Hảo')->items->first()->slug,
        );
    }

    // 24-25. malformed / empty products

    public function test_skips_malformed_products_without_breaking_list(): void
    {
        $this->fakeSearch();

        $result = $this->provider()->search('mì Hảo Hảo');

        $this->assertCount(2, $result->items);
        $this->assertSame(KingfoodmartFixture::SKU, $result->items->first()->sku);
    }

    public function test_empty_products_returns_empty_result(): void
    {
        Http::fake([
            self::SEARCH_URL.'*' => Http::response([
                'products' => [],
                'pagination' => ['total' => 0, 'currentPage' => 1, 'lastPage' => 0, 'limit' => 30],
            ]),
        ]);

        $result = $this->provider()->search('zzzqqqxyz');

        $this->assertCount(0, $result->items);
        $this->assertSame(0, $result->total);
    }

    public function test_empty_keyword_returns_empty_result_without_api_call(): void
    {
        $result = $this->provider()->search('   ');

        $this->assertCount(0, $result->items);
        $this->assertSame(0, $result->total);
        Http::assertNothingSent();
    }

    // 26-30. error handling

    public function test_handles_http_400_returns_empty_result_and_logs(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([self::SEARCH_URL.'*' => Http::response(['message' => 'Bad Request'], 400)]);

        $result = $this->provider()->search('mì Hảo Hảo', 1, 30);

        $this->assertCount(0, $result->items);
        $this->assertSame(0, $result->total);
        $this->assertSame(1, $result->page);

        Event::assertDispatched(MessageLogged::class, function (MessageLogged $event): bool {
            return $event->level === 'warning'
                && str_contains($event->message, 'HTTP error')
                && ($event->context['status'] ?? null) === 400;
        });
    }

    public function test_handles_http_500_returns_empty_result_and_logs(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([self::SEARCH_URL.'*' => Http::response(['message' => 'server error'], 500)]);

        $result = $this->provider()->search('mì Hảo Hảo');

        $this->assertCount(0, $result->items);

        Event::assertDispatched(MessageLogged::class, function (MessageLogged $event): bool {
            return $event->level === 'warning'
                && str_contains($event->message, 'HTTP error')
                && ($event->context['status'] ?? null) === 500;
        });
    }

    public function test_handles_connection_exception_returns_empty_result_and_logs(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([self::SEARCH_URL.'*' => fn () => throw new ConnectionException('timed out')]);

        $result = $this->provider()->search('mì Hảo Hảo');

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

        Http::fake([self::SEARCH_URL.'*' => Http::response('this is not json at all', 200)]);

        $result = $this->provider()->search('mì Hảo Hảo');

        $this->assertCount(0, $result->items);

        Event::assertDispatched(MessageLogged::class, fn (MessageLogged $event) => str_contains($event->message, 'malformed JSON'));
    }

    public function test_handles_invalid_products_structure_and_logs(): void
    {
        Event::fake([MessageLogged::class]);

        Http::fake([
            self::SEARCH_URL.'*' => Http::response(['products' => 'not-an-array', 'pagination' => ['total' => 3]]),
        ]);

        $result = $this->provider()->search('mì Hảo Hảo');

        $this->assertCount(0, $result->items);

        Event::assertDispatched(MessageLogged::class, fn (MessageLogged $event) => str_contains($event->message, 'invalid products'));
    }

    // 31-33. cache

    public function test_search_is_cached(): void
    {
        $this->fakeSearch();

        $first = $this->provider()->search('mì Hảo Hảo');
        $second = $this->provider()->search('mì Hảo Hảo');

        $this->assertCount(1, Http::recorded());
        $this->assertEquals($first, $second);
    }

    public function test_search_cache_key_includes_page_and_per_page(): void
    {
        $this->fakeSearch();

        $this->provider()->search('mì Hảo Hảo', 1, 30);
        $this->provider()->search('mì Hảo Hảo', 2, 30);
        $this->provider()->search('mì Hảo Hảo', 1, 10);

        $this->assertCount(3, Http::recorded());
    }

    public function test_errors_are_not_cached(): void
    {
        Http::fake([self::SEARCH_URL.'*' => Http::response([], 500)]);

        $this->provider()->search('mì Hảo Hảo');
        $this->provider()->search('mì Hảo Hảo');

        $this->assertCount(2, Http::recorded());
    }

    // 34. raw_data is internal only

    public function test_raw_data_is_kept_internal_and_not_exposed_in_to_array(): void
    {
        $this->fakeSearch();

        $product = $this->provider()->search('mì Hảo Hảo')->items->first();

        $this->assertIsArray($product->rawData);
        $this->assertSame(KingfoodmartFixture::VARIANT_ID, $product->rawData['pid']);

        $array = $product->toArray();
        $this->assertArrayNotHasKey('raw_data', $array);
        $this->assertArrayNotHasKey('rawData', $array);
    }

    // 35-37. headers: tenant only, never auth or store code

    public function test_sends_tenant_header(): void
    {
        $this->fakeSearch();

        $this->provider()->search('mì Hảo Hảo');

        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Ol-Tenant', 'kingfood'));
    }

    public function test_does_not_send_authorization_or_cookies(): void
    {
        $this->fakeSearch();

        $this->provider()->search('mì Hảo Hảo');

        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization')
            && ! $request->hasHeader('Cookie'));
    }

    public function test_does_not_send_store_code_header_by_default(): void
    {
        $this->fakeSearch();

        $this->provider()->search('mì Hảo Hảo');

        Http::assertSent(fn (Request $request) => ! $request->hasHeader('X-Ol-Online-Store-Codes'));
    }

    // 38-41. getProduct

    public function test_get_product_by_variant_id(): void
    {
        $this->fakeSearchAndDetail();

        $product = $this->provider()->getProduct(KingfoodmartFixture::VARIANT_ID);

        $this->assertInstanceOf(ProductSummary::class, $product);
        $this->assertSame(KingfoodmartFixture::SKU, $product->sku);
        $this->assertSame('Mì Hảo Hảo Acecook hương vị lẩu kim chi Hàn Quốc gói 75g', $product->name);
        $this->assertSame(4700, $product->price);
    }

    public function test_get_product_returns_null_for_empty_identifier(): void
    {
        $this->assertNull($this->provider()->getProduct('   '));
        Http::assertNothingSent();
    }

    public function test_get_product_resolves_variant_id_from_sku(): void
    {
        Http::fake([
            self::SEARCH_URL.'*' => Http::response(KingfoodmartFixture::searchResponse()),
            self::DETAIL_URL.'*' => function (Request $request) {
                if (str_ends_with($request->url(), KingfoodmartFixture::SKU)) {
                    return Http::response(['message' => 'Bad Request'], 400);
                }

                return Http::response(KingfoodmartFixture::detailResponse());
            },
        ]);

        $product = $this->provider()->getProduct(KingfoodmartFixture::SKU);

        $this->assertInstanceOf(ProductSummary::class, $product);
        $this->assertSame(KingfoodmartFixture::SKU, $product->sku);
    }

    public function test_get_product_returns_null_when_variant_id_cannot_be_resolved(): void
    {
        Http::fake([
            self::SEARCH_URL.'*' => Http::response(['products' => $this->validProducts()]),
            self::DETAIL_URL.'*' => Http::response(['message' => 'Bad Request'], 400),
        ]);

        $this->assertNull($this->provider()->getProduct('0000000000000'));
    }

    public function test_detail_is_cached(): void
    {
        $this->fakeSearchAndDetail();

        $first = $this->provider()->getProduct(KingfoodmartFixture::VARIANT_ID);
        $second = $this->provider()->getProduct(KingfoodmartFixture::VARIANT_ID);

        $this->assertSame(1, Http::recorded()->count());
        $this->assertEquals($first, $second);
    }

    // 42-43. API endpoint + page integration

    public function test_kingfoodmart_api_endpoint_returns_normalized_json(): void
    {
        $this->fakeSearch();

        $response = $this->getJson('/api/price-comparison/kingfoodmart?keyword='.rawurlencode('mì Hảo Hảo'));

        $response->assertOk()
            ->assertJsonPath('source', 'kingfoodmart')
            ->assertJsonPath('keyword', 'mì Hảo Hảo')
            ->assertJsonPath('pagination.total', 137)
            ->assertJsonPath('pagination.total_pages', 5)
            ->assertJsonCount(2, 'products')
            ->assertJsonPath('products.0.sku', KingfoodmartFixture::SKU)
            ->assertJsonPath('products.0.barcode', KingfoodmartFixture::SKU)
            ->assertJsonPath('products.0.price', 4700)
            ->assertJsonPath('products.0.original_price', 4700)
            ->assertJsonPath('products.0.stock', 7463)
            ->assertJsonPath('products.0.sellable', true)
            ->assertJsonPath('products.0.unit', '1 Gói')
            ->assertJsonPath('products.0.product_url', 'https://kingfoodmart.com/mi-an-lien-1356/mi-hao-hao-huong-vi-lau-kim-chi-han-quoc-acecook-75g-1-goi')
            ->assertJsonPath('products.1.discount_percent', 17)
            ->assertJsonPath('products.1.discount_amount', 21000)
            ->assertJsonMissingPath('products.0.raw_data')
            ->assertJsonMissingPath('products.0.rawData');
    }

    public function test_kingfoodmart_api_endpoint_requires_keyword(): void
    {
        $this->getJson('/api/price-comparison/kingfoodmart')->assertStatus(422);
    }

    public function test_kingfoodmart_page_renders_real_products_and_tab(): void
    {
        $this->fakeSearch();

        $this->get('/so-sanh-gia?keyword='.rawurlencode('mì Hảo Hảo').'&retailer=kingfoodmart')
            ->assertOk()
            ->assertSee('Mì Hảo Hảo Acecook hương vị lẩu kim chi Hàn Quốc gói 75g', escape: false)
            ->assertSee('Kingfoodmart', escape: false)
            ->assertSee('4.700', escape: false)
            ->assertDontSee('raw_data', escape: false)
            ->assertDontSee('descriptionJson', escape: false);
    }

    public function test_kingfoodmart_retailer_calls_only_kingfoodmart(): void
    {
        $this->fakeSearch();

        $this->get('/so-sanh-gia?keyword=mi&retailer=kingfoodmart')->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::SEARCH_URL));
    }
}
