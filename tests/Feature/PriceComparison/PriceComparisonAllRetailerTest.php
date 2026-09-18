<?php

namespace Tests\Feature\PriceComparison;

use App\Services\PriceComparison\DataTransfer\AggregatedSearchResult;
use App\Services\PriceComparison\PriceComparisonManager;
use App\Services\PriceComparison\Providers\BachHoaXanhProvider;
use App\Services\PriceComparison\Providers\CoopOnlineProvider;
use App\Services\PriceComparison\Providers\KingfoodmartProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Fixture\BachHoaXanhFixture;
use Tests\Fixture\CoopOnlineFixture;
use Tests\Fixture\KingfoodmartFixture;
use Tests\TestCase;

/**
 * C3: "Tất cả" retailer aggregation view.
 *
 * The page aggregates (without deduplication) listings from every registered
 * catalog provider. This is aggregation/display only — no cross-retailer
 * product matching, no dedup, no provider changes.
 */
class PriceComparisonAllRetailerTest extends TestCase
{
    private const COOP_URL = 'https://discovery.tekoapis.com/api/v1/search';

    private const BHX_URL = 'https://api.bachhoaxanh.com/gw/search/v2/DataSearch';

    private const KFM_URL = 'https://onelife-api.kingfoodmart.com/v1/products/search';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Cache::flush();

        config([
            'services.bachhoaxanh.base_url' => 'https://api.bachhoaxanh.com/gw',
            'services.bachhoaxanh.store_id' => BachHoaXanhFixture::STORE_ID,
            'services.bachhoaxanh.province_id' => null,
            'services.bachhoaxanh.ward_id' => null,
            'services.bachhoaxanh.user_agent' => 'MozTestAgent/1.0',
        ]);
    }

    private function manager(): PriceComparisonManager
    {
        return new PriceComparisonManager([
            new CoopOnlineProvider,
            new BachHoaXanhProvider,
        ]);
    }

    private function managerWithKingfoodmart(): PriceComparisonManager
    {
        return new PriceComparisonManager([
            new CoopOnlineProvider,
            new BachHoaXanhProvider,
            new KingfoodmartProvider,
        ]);
    }

    private function fakeBoth(array $coopOverrides = [], array $bhxOverrides = []): void
    {
        Http::fake([
            self::COOP_URL => Http::response(CoopOnlineFixture::searchResponse($coopOverrides)),
            self::BHX_URL => Http::response(BachHoaXanhFixture::searchResponse($bhxOverrides)),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     */
    private function coopResponseWith(array $products, int $total): array
    {
        return [
            'code' => '0',
            'pagination' => ['totalItems' => $total, 'totalPages' => max(1, (int) ceil($total / count($products)))],
            'result' => ['products' => $products],
        ];
    }

    private function coopProduct(string $sku, string $name, int $price): array
    {
        return [
            'productInfo' => ['sku' => $sku, 'name' => $name],
            'prices' => [['latestPrice' => (string) $price, 'sellPrice' => (string) $price, 'discountAmount' => '0', 'discountPercent' => 0]],
            'totalAvailable' => 5,
            'status' => ['sellable' => true],
        ];
    }

    private function bhxProduct(string $sku, string $name, int $price): array
    {
        return [
            'id' => (int) $sku,
            'name' => $name,
            'productPrices' => [
                ['price' => $price, 'sysPrice' => $price, 'discountPercent' => 0, 'quantity' => 5, 'status' => 1, 'isCanBuy' => true],
            ],
        ];
    }

    // ---------------------------------------------------------------- manager

    public function test_search_source_queries_a_single_provider(): void
    {
        $this->fakeBoth();

        $result = $this->manager()->searchSource('coop_online', 'mi', 1, 20);

        $this->assertSame(36, $result->total);
        $this->assertSame('coop_online', $result->items->first()->source);
    }

    public function test_search_all_returns_aggregated_result(): void
    {
        $this->fakeBoth();

        $result = $this->manager()->searchAll('mi');

        $this->assertInstanceOf(AggregatedSearchResult::class, $result);
        $this->assertSame(8, $result->total);
        $this->assertSame(['coop_online' => 36, 'bach_hoa_xanh' => 99], $result->sourceTotals);
        $this->assertSame(['coop_online' => 3, 'bach_hoa_xanh' => 5], $result->sourceItemCounts);
    }

    public function test_search_all_hits_every_registered_provider(): void
    {
        $this->fakeBoth();

        $this->manager()->searchAll('mi');

        Http::assertSent(fn (Request $request) => $request->url() === self::COOP_URL);
        Http::assertSent(fn (Request $request) => $request->url() === self::BHX_URL);
        Http::assertSentCount(2);
    }

    public function test_search_all_relevance_interleaves_providers(): void
    {
        $this->fakeBoth();

        $result = $this->manager()->searchAll('mi');

        $this->assertSame('250100313', $result->items[0]->sku);
        $this->assertSame('coop_online', $result->items[0]->source);
        $this->assertSame('235865', $result->items[1]->sku);
        $this->assertSame('bach_hoa_xanh', $result->items[1]->source);
        $this->assertSame('250100218', $result->items[2]->sku);
        $this->assertSame('coop_online', $result->items[2]->source);
    }

    public function test_search_all_price_asc_sorts_across_providers(): void
    {
        $this->fakeBoth();

        $result = $this->manager()->searchAll('mi', 1, 20, 'price_asc');

        $prices = $result->items->map(fn ($p) => $p->price)->all();

        $this->assertSame($prices, $this->sorted($prices, false));
    }

    public function test_search_all_price_desc_sorts_across_providers(): void
    {
        $this->fakeBoth();

        $result = $this->manager()->searchAll('mi', 1, 20, 'price_desc');

        $prices = $result->items->map(fn ($p) => $p->price)->all();

        $this->assertSame($prices, $this->sorted($prices, true));
    }

    public function test_search_all_does_not_deduplicate_across_providers(): void
    {
        Http::fake([
            self::COOP_URL => Http::response($this->coopResponseWith(
                [$this->coopProduct('777', 'Mì Hảo Hảo gà vàng gói 74g', 3700)],
                1,
            )),
            self::BHX_URL => Http::response([
                'code' => 0,
                'data' => ['products' => [$this->bhxProduct('777', 'Mì Hảo Hảo gà vàng gói 74g', 3700)], 'total' => 1],
            ]),
        ]);

        $result = $this->manager()->searchAll('mì');

        $this->assertCount(2, $result->items);
        $this->assertSame('Mì Hảo Hảo gà vàng gói 74g', $result->items[0]->name);
        $this->assertSame('Mì Hảo Hảo gà vàng gói 74g', $result->items[1]->name);
        $this->assertNotSame($result->items[0]->source, $result->items[1]->source);
    }

    public function test_search_all_pagination_has_no_duplicates_or_missing(): void
    {
        $this->fakePagination(60, 60);

        $manager = $this->manager();
        $first = $manager->searchAll('mi', 1, 20);

        $this->assertSame(120, $first->total);
        $this->assertCount(20, $first->items);

        $skus = $first->items->map(fn ($p) => $p->source.':'.$p->sku)->all();

        for ($page = 2; $page <= $first->totalPages; $page++) {
            foreach ($manager->searchAll('mi', $page, 20)->items as $item) {
                $skus[] = $item->source.':'.$item->sku;
            }
        }

        $this->assertSame(120, count($skus));
        $this->assertSame(120, count(array_unique($skus)));
    }

    public function test_search_all_isolates_coop_failure(): void
    {
        Http::fake([
            self::COOP_URL => Http::response([], 500),
            self::BHX_URL => Http::response(BachHoaXanhFixture::searchResponse()),
        ]);

        $result = $this->manager()->searchAll('mi');

        $this->assertSame(5, $result->total);
        $this->assertSame(0, $result->sourceItemCounts['coop_online']);
        $this->assertSame(5, $result->sourceItemCounts['bach_hoa_xanh']);
        $this->assertSame('bach_hoa_xanh', $result->items->first()->source);
    }

    public function test_search_all_isolates_bhx_failure(): void
    {
        Http::fake([
            self::COOP_URL => Http::response(CoopOnlineFixture::searchResponse()),
            self::BHX_URL => Http::response([], 500),
        ]);

        $result = $this->manager()->searchAll('mi');

        $this->assertSame(3, $result->total);
        $this->assertSame(0, $result->sourceItemCounts['bach_hoa_xanh']);
        $this->assertSame('coop_online', $result->items->first()->source);
    }

    public function test_search_all_both_fail_returns_empty_without_throwing(): void
    {
        Http::fake([
            self::COOP_URL => Http::response([], 500),
            self::BHX_URL => Http::response([], 500),
        ]);

        $result = $this->manager()->searchAll('mi');

        $this->assertInstanceOf(AggregatedSearchResult::class, $result);
        $this->assertSame(0, $result->total);
        $this->assertCount(0, $result->items);
    }

    public function test_search_all_accepts_source_subset(): void
    {
        $this->fakeBoth();

        $result = $this->manager()->searchAll('mi', 1, 20, 'relevance', ['bach_hoa_xanh']);

        $this->assertSame(5, $result->total);
        $this->assertArrayHasKey('bach_hoa_xanh', $result->sourceTotals);
        $this->assertArrayNotHasKey('coop_online', $result->sourceTotals);
    }

    public function test_search_all_to_array_does_not_expose_raw_data(): void
    {
        $this->fakeBoth();

        $result = $this->manager()->searchAll('mi');

        foreach ($result->toArray()['products'] as $product) {
            $this->assertArrayNotHasKey('raw_data', $product);
            $this->assertArrayNotHasKey('rawData', $product);
        }
    }

    // ------------------------------------------------- Phase 1 promotion filter

    public function test_search_all_counts_promotions_in_fetched_dataset(): void
    {
        $this->fakeBoth();

        $result = $this->manager()->searchAll('mi');

        $this->assertSame(1, $result->promotionsCount);
    }

    public function test_search_all_promotions_only_keeps_promo_products(): void
    {
        $this->fakeBoth();

        $result = $this->manager()->searchAll('mi', 1, 20, 'relevance', null, true);

        $this->assertCount(1, $result->items);
        $this->assertSame(1, $result->total);
        $this->assertTrue($result->items->first()->hasPromotion());
        $this->assertSame('bach_hoa_xanh', $result->items->first()->source);
        $this->assertSame('Mì Hảo Hảo gà vàng gói 74g', $result->items->first()->name);
    }

    public function test_search_all_promotions_only_recomputes_pagination(): void
    {
        $products = [
            $this->bhxProduct('1', 'BHX promo A', 1000),
            $this->bhxProduct('2', 'BHX promo B', 2000),
            $this->bhxProduct('3', 'BHX promo C', 3000),
            $this->bhxProduct('4', 'BHX plain A', 4000),
            $this->bhxProduct('5', 'BHX promo D', 5000),
        ];
        $products[0]['promotionText'] = 'MUA 2 TẶNG 1';
        $products[1]['promotionText'] = 'MUA 5 TẶNG 3';
        $products[2]['promotionText'] = 'GIÁ SỐC';
        $products[4]['promotionText'] = 'MUA 1 TẶNG 1';

        Http::fake([
            self::COOP_URL => Http::response($this->coopResponseWith(
                [$this->coopProduct('777', 'Co.op không promo', 5000)],
                1,
            )),
            self::BHX_URL => Http::response([
                'code' => 0,
                'data' => ['products' => $products, 'total' => 5],
            ]),
        ]);

        $manager = $this->manager();

        $page1 = $manager->searchAll('mi', 1, 2, 'relevance', null, true);
        $page2 = $manager->searchAll('mi', 2, 2, 'relevance', null, true);

        $this->assertSame(4, $page1->total);
        $this->assertSame(2, $page1->totalPages);
        $this->assertCount(2, $page1->items);
        $this->assertCount(2, $page2->items);
        $this->assertTrue($page1->items->every(fn ($p) => $p->hasPromotion()));
        $this->assertTrue($page2->items->every(fn ($p) => $p->hasPromotion()));
        $this->assertFalse($page1->items->contains(fn ($p) => $p->name === 'BHX plain A'));
    }

    public function test_search_all_promotions_only_does_not_overwrite_counts_cache(): void
    {
        $this->fakeBoth();

        $manager = $this->manager();

        $manager->searchAll('mi');
        $manager->searchAll('mi', 1, 20, 'relevance', null, true);

        $this->assertSame(
            ['total' => 8, 'sources' => ['coop_online' => 36, 'bach_hoa_xanh' => 99], 'promotions' => 1],
            $manager->aggregatedCounts('mi'),
        );
    }

    public function test_non_promo_sources_have_null_promotions_in_merged_items(): void
    {
        $this->fakeBoth();

        $result = $this->manager()->searchAll('mi');

        foreach ($result->items as $item) {
            if ($item->source !== 'bach_hoa_xanh') {
                $this->assertNull($item->promotions);
                $this->assertFalse($item->hasPromotion());
            }
        }
    }

    public function test_search_all_promotions_count_includes_kingfoodmart(): void
    {
        $this->fakeAllThreeWithKfmPromotions();

        $result = $this->managerWithKingfoodmart()->searchAll('mi');

        // 1 BHX promo + 2 Kingfoodmart products carrying promotions.
        $this->assertSame(3, $result->promotionsCount);
    }

    public function test_search_all_promotions_only_keeps_kingfoodmart_promo_products(): void
    {
        $this->fakeAllThreeWithKfmPromotions();

        $result = $this->managerWithKingfoodmart()->searchAll('mi', 1, 20, 'relevance', null, true);

        $this->assertTrue($result->items->every(fn ($product) => $product->hasPromotion()));
        $this->assertTrue($result->items->contains(
            fn ($product) => $product->source === 'kingfoodmart'
                && $product->name === 'Mì Hảo Hảo Acecook hương vị lẩu kim chi Hàn Quốc gói 75g',
        ));
    }

    public function test_aggregated_counts_promotions_include_kingfoodmart(): void
    {
        $this->fakeAllThreeWithKfmPromotions();

        $manager = $this->managerWithKingfoodmart();
        $manager->searchAll('mi');

        $counts = $manager->aggregatedCounts('mi');

        $this->assertIsArray($counts);
        $this->assertSame(3, $counts['promotions']);
    }

    // ------------------------------------------------------------------- API

    public function test_api_all_returns_merged_sources(): void
    {
        $this->fakeBoth();

        $response = $this->get('/api/price-comparison?keyword=mi')
            ->assertOk()
            ->assertJsonPath('source', 'all')
            ->assertJsonPath('keyword', 'mi')
            ->assertJsonPath('pagination.total', 8);

        // The container-managed manager now also contains Kingfoodmart and
        // WinMart. Their requests are not faked here and are isolated to an
        // empty source.
        $response->assertJsonCount(4, 'sources');
        $response->assertJsonPath('sources.0.source', 'coop_online');
        $response->assertJsonPath('sources.1.source', 'bach_hoa_xanh');
        $response->assertJsonPath('sources.2.source', 'kingfoodmart');
        $response->assertJsonPath('sources.3.source', 'winmart');
        $response->assertJsonPath('sources.0.total', 36);
        $response->assertJsonPath('sources.1.total', 99);
        $response->assertJsonPath('sources.2.total', 0);
        $response->assertJsonPath('sources.3.total', 0);
        $response->assertJsonCount(8, 'products');
        $response->assertJsonPath('products.5.promotions.0.title', 'MUA 5 TẶNG 1');
        $response->assertJsonPath('products.0.promotions', null);
    }

    public function test_api_all_validates_keyword(): void
    {
        $this->fakeBoth();

        $this->getJson('/api/price-comparison')
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------- page

    public function test_page_defaults_to_all_retailer(): void
    {
        $this->fakeBoth();

        $this->get('/so-sanh-gia?keyword=mi')
            ->assertOk()
            ->assertSee('Tất cả', escape: false)
            ->assertSee('từ 2 siêu thị', escape: false);
    }

    public function test_page_all_shows_both_source_badges(): void
    {
        $this->fakeBoth();

        $content = $this->get('/so-sanh-gia?keyword=mi')->getContent();

        $this->assertGreaterThanOrEqual(2, substr_count($content, '>Co.op</span>'));
        $this->assertGreaterThanOrEqual(2, substr_count($content, '>BHX</span>'));
    }

    public function test_page_all_does_not_deduplicate_same_product(): void
    {
        Http::fake([
            self::COOP_URL => Http::response($this->coopResponseWith(
                [$this->coopProduct('777', 'Mì Hảo Hảo gà vàng gói 74g', 3700)],
                1,
            )),
            self::BHX_URL => Http::response([
                'code' => 0,
                'data' => ['products' => [$this->bhxProduct('777', 'Mì Hảo Hảo gà vàng gói 74g', 3700)], 'total' => 1],
            ]),
        ]);

        $content = $this->get('/so-sanh-gia?keyword=mì')->getContent();

        $this->assertSame(2, substr_count($content, 'Mì Hảo Hảo gà vàng gói 74g'));
    }

    public function test_page_coop_retailer_uses_coop_only(): void
    {
        $this->fakeBoth();

        $this->get('/so-sanh-gia?keyword=mi&retailer=coop')
            ->assertOk()
            ->assertSee('Mì Hảo Hảo vị gà vang thùng 30 x 74g', escape: false);

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::BHX_URL);
    }

    public function test_page_bhx_retailer_uses_bhx_only(): void
    {
        $this->fakeBoth();

        $this->get('/so-sanh-gia?keyword=mi&retailer=bhx')
            ->assertOk()
            ->assertSee('Mì Hảo Hảo gà vàng gói 74g', escape: false);

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::COOP_URL);
    }

    public function test_page_sort_links_preserve_retailer(): void
    {
        $this->fakeBoth();

        $content = $this->get('/so-sanh-gia?keyword=mi&retailer=coop&sort=relevance')->getContent();

        $this->assertStringContainsString('retailer=coop&amp;sort=price_asc', $content);
        $this->assertStringContainsString('retailer=coop&amp;sort=price_desc', $content);
    }

    // ------------------------------------------------------------ C4 tab counts

    public function test_aggregated_counts_cache_returns_totals(): void
    {
        $this->fakeBoth();
        $manager = $this->manager();

        $manager->searchAll('mi');

        $this->assertSame(
            ['total' => 8, 'sources' => ['coop_online' => 36, 'bach_hoa_xanh' => 99], 'promotions' => 1],
            $manager->aggregatedCounts('mi'),
        );
    }

    public function test_aggregated_counts_returns_null_without_prior_search(): void
    {
        $this->assertNull($this->manager()->aggregatedCounts('mi'));
    }

    public function test_page_all_shows_counts_on_every_retailer_tab(): void
    {
        $this->fakePagination(36, 25);

        $content = $this->get('/so-sanh-gia?keyword=mi')->getContent();

        $this->assertStringContainsString('Tất cả (61)', $content);
        $this->assertStringContainsString('Co.op (36)', $content);
        $this->assertStringContainsString('BHX (25)', $content);
    }

    public function test_page_counts_survive_switching_to_coop_tab(): void
    {
        $this->fakePagination(36, 25);

        $this->get('/so-sanh-gia?keyword=mi')->assertOk();
        $content = $this->get('/so-sanh-gia?keyword=mi&retailer=coop')->getContent();

        $this->assertStringContainsString('Tất cả (61)', $content);
        $this->assertStringContainsString('Co.op (36)', $content);
        $this->assertStringContainsString('BHX (25)', $content);

        Http::assertSentCount(3);
    }

    public function test_page_counts_survive_switching_to_bhx_tab(): void
    {
        $this->fakePagination(36, 25);

        $this->get('/so-sanh-gia?keyword=mi')->assertOk();
        $content = $this->get('/so-sanh-gia?keyword=mi&retailer=bhx')->getContent();

        $this->assertStringContainsString('Tất cả (61)', $content);
        $this->assertStringContainsString('Co.op (36)', $content);
        $this->assertStringContainsString('BHX (25)', $content);

        Http::assertSentCount(3);
    }

    public function test_page_counts_reflect_provider_totals_not_page_items(): void
    {
        $this->fakePagination(36, 25);

        $content = $this->get('/so-sanh-gia?keyword=mi')->getContent();

        $this->assertStringContainsString('Tất cả (61)', $content);
        $this->assertStringNotContainsString('Tất cả (20)', $content);
    }

    public function test_page_unsupported_retailers_do_not_show_zero_count(): void
    {
        $this->fakePagination(36, 25);

        $content = $this->get('/so-sanh-gia?keyword=mi')->getContent();

        $this->assertStringContainsString('WinMart', $content);
        $this->assertStringNotContainsString('WinMart (0)', $content);
        $this->assertStringNotContainsString('Điện Máy Xanh (0)', $content);
        $this->assertStringNotContainsString(' (0)', $content);
    }

    public function test_page_provider_failure_does_not_show_zero_count(): void
    {
        Http::fake([
            self::COOP_URL => function (Request $request) {
                $page = (int) ($request['pagination']['pageNumber'] ?? 1);
                $per = (int) ($request['pagination']['itemsPerPage'] ?? 50);
                $products = [];

                for ($i = ($page - 1) * $per; $i < min($page * $per, 36); $i++) {
                    $products[] = $this->coopProduct('C'.$i, 'Co.op sản phẩm '.$i, 1000 + $i);
                }

                return Http::response([
                    'code' => '0',
                    'pagination' => ['totalItems' => 36, 'totalPages' => (int) ceil(36 / $per)],
                    'result' => ['products' => $products],
                ]);
            },
            self::BHX_URL => Http::response([], 500),
        ]);

        $content = $this->get('/so-sanh-gia?keyword=mi')->getContent();

        $this->assertStringContainsString('Tất cả (36)', $content);
        $this->assertStringContainsString('Co.op (36)', $content);
        $this->assertStringNotContainsString('BHX (0)', $content);
    }

    public function test_page_counts_stay_constant_across_pagination(): void
    {
        $this->fakePagination(36, 25);

        $page1 = $this->get('/so-sanh-gia?keyword=mi')->getContent();
        $page2 = $this->get('/so-sanh-gia?keyword=mi&page=2')->getContent();

        foreach (['Tất cả (61)', 'Co.op (36)', 'BHX (25)'] as $count) {
            $this->assertStringContainsString($count, $page1);
            $this->assertStringContainsString($count, $page2);
        }
    }

    // ------------------------------------------------- C6 Kingfoodmart source

    public function test_manager_registry_discovers_all_four_providers(): void
    {
        $manager = $this->app->make(PriceComparisonManager::class);

        $this->assertSame(
            ['coop_online', 'bach_hoa_xanh', 'kingfoodmart', 'winmart'],
            array_keys($manager->all()),
        );
    }

    public function test_search_all_with_kingfoodmart_merges_three_sources(): void
    {
        $this->fakeAllThree(36, 25, 10);

        $result = $this->managerWithKingfoodmart()->searchAll('mi');

        $this->assertSame(71, $result->total);
        $this->assertSame(
            ['coop_online' => 36, 'bach_hoa_xanh' => 25, 'kingfoodmart' => 10],
            $result->sourceTotals,
        );
        $this->assertSame(
            ['coop_online' => 36, 'bach_hoa_xanh' => 25, 'kingfoodmart' => 10],
            $result->sourceItemCounts,
        );
    }

    public function test_search_all_includes_kingfoodmart_items(): void
    {
        $this->fakeAllThree(3, 5, 2);

        $result = $this->managerWithKingfoodmart()->searchAll('mi');

        $this->assertTrue($result->items->contains(fn ($p) => $p->source === 'kingfoodmart'));
    }

    public function test_search_all_price_asc_includes_kingfoodmart(): void
    {
        $this->fakeAllThree(3, 5, 2);

        $result = $this->managerWithKingfoodmart()->searchAll('mi', 1, 20, 'price_asc');

        $prices = $result->items->map(fn ($p) => $p->price)->all();
        $this->assertSame($prices, $this->sorted($prices, false));
        $this->assertSame('kingfoodmart', $result->items->last()->source);
    }

    public function test_search_all_isolates_kingfoodmart_failure(): void
    {
        Http::fake([
            self::COOP_URL => Http::response(CoopOnlineFixture::searchResponse()),
            self::BHX_URL => Http::response(BachHoaXanhFixture::searchResponse()),
            self::KFM_URL.'*' => Http::response([], 500),
        ]);

        $result = $this->managerWithKingfoodmart()->searchAll('mi');

        $this->assertSame(8, $result->total);
        $this->assertSame(0, $result->sourceItemCounts['kingfoodmart']);
        $this->assertSame('coop_online', $result->items->first()->source);
    }

    public function test_page_all_shows_kingfoodmart_badge_and_source_count(): void
    {
        $this->fakeAllThree(3, 5, 2);

        $content = $this->get('/so-sanh-gia?keyword=mi')->getContent();

        $this->assertStringContainsString('>Kingfoodmart</span>', $content);
        $this->assertStringContainsString('từ 3 siêu thị', $content);
    }

    public function test_page_counts_include_kingfoodmart(): void
    {
        $this->fakeAllThree(36, 25, 10);

        $content = $this->get('/so-sanh-gia?keyword=mi')->getContent();

        $this->assertStringContainsString('Tất cả (71)', $content);
        $this->assertStringContainsString('Co.op (36)', $content);
        $this->assertStringContainsString('BHX (25)', $content);
        $this->assertStringContainsString('Kingfoodmart (10)', $content);
        $this->assertStringNotContainsString('WinMart (0)', $content);
    }

    public function test_page_kingfoodmart_retailer_uses_only_kingfoodmart(): void
    {
        $this->fakeAllThree(3, 5, 2);

        $this->get('/so-sanh-gia?keyword=mi&retailer=kingfoodmart')
            ->assertOk()
            ->assertSee('KFM sản phẩm 0', escape: false);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::KFM_URL));
        Http::assertNotSent(fn (Request $request) => $request->url() === self::COOP_URL);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::BHX_URL);
    }

    // ---------------------------------------------------------------- helpers

    private function fakeAllThree(int $coopTotal, int $bhxTotal, int $kfmTotal): void
    {
        Http::fake([
            self::COOP_URL => function (Request $request) use ($coopTotal) {
                $page = (int) ($request['pagination']['pageNumber'] ?? 1);
                $per = (int) ($request['pagination']['itemsPerPage'] ?? 50);
                $products = [];

                for ($i = ($page - 1) * $per; $i < min($page * $per, $coopTotal); $i++) {
                    $products[] = $this->coopProduct('C'.$i, 'Co.op sản phẩm '.$i, 1000 + $i);
                }

                return Http::response([
                    'code' => '0',
                    'pagination' => ['totalItems' => $coopTotal, 'totalPages' => (int) ceil($coopTotal / $per)],
                    'result' => ['products' => $products],
                ]);
            },
            self::BHX_URL => function (Request $request) use ($bhxTotal) {
                $pageIndex = (int) ($request['pageIndex'] ?? 0);
                $per = (int) ($request['pageSize'] ?? 50);
                $products = [];

                for ($i = $pageIndex * $per; $i < min(($pageIndex + 1) * $per, $bhxTotal); $i++) {
                    $products[] = $this->bhxProduct((string) (3000 + $i), 'BHX sản phẩm '.$i, 2000 + $i);
                }

                return Http::response([
                    'code' => 0,
                    'data' => ['products' => $products, 'total' => $bhxTotal],
                ]);
            },
            self::KFM_URL.'*' => function (Request $request) use ($kfmTotal) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);
                $per = (int) ($query['limit'] ?? 50);
                $products = [];

                for ($i = ($page - 1) * $per; $i < min($page * $per, $kfmTotal); $i++) {
                    $products[] = $this->kfmProduct((string) (5000 + $i), 'KFM sản phẩm '.$i, 3000 + $i);
                }

                return Http::response([
                    'pagination' => [
                        'total' => $kfmTotal,
                        'currentPage' => $page,
                        'lastPage' => (int) ceil($kfmTotal / $per),
                        'limit' => $per,
                    ],
                    'products' => $products,
                ]);
            },
        ]);
    }

    private function fakeAllThreeWithKfmPromotions(): void
    {
        Http::fake([
            self::COOP_URL => Http::response(CoopOnlineFixture::searchResponse()),
            self::BHX_URL => Http::response(BachHoaXanhFixture::searchResponse()),
            self::KFM_URL.'*' => Http::response(KingfoodmartFixture::searchResponse()),
        ]);
    }

    private function kfmProduct(string $sku, string $name, int $price): array
    {
        return [
            'pid' => 'v'.$sku,
            'name' => $name,
            'slug' => 'sp-'.$sku,
            'subCate' => 'danh-muc',
            'subCateName' => 'Danh mục',
            'discountPrice' => $price,
            'originalPrice' => $price,
            'discountPercent' => 0,
            'inStock' => 5,
            'isActive' => true,
            'thumbnail' => 'https://img.onelife.vn/'.$sku.'.webp',
            'variants' => [
                [
                    'id' => 'v'.$sku,
                    'sku' => $sku,
                    'isOnlineSale' => true,
                    'isSale' => true,
                    'stockItem' => ['quantity' => 5],
                    'unit' => ['name' => 'Gói'],
                ],
            ],
        ];
    }

    /**
     * Null prices sort last in both directions, matching comparePrice().
     *
     * @return array<int, int|float|null>
     */
    private function sorted(array $values, bool $desc): array
    {
        $withPrices = array_values(array_filter($values, fn ($v) => $v !== null));
        $nullCount = count($values) - count($withPrices);

        sort($withPrices);

        if ($desc) {
            $withPrices = array_reverse($withPrices);
        }

        return array_merge($withPrices, array_fill(0, $nullCount, null));
    }

    private function fakePagination(int $coopTotal, int $bhxTotal): void
    {
        Http::fake([
            self::COOP_URL => function (Request $request) use ($coopTotal) {
                $page = (int) ($request['pagination']['pageNumber'] ?? 1);
                $per = (int) ($request['pagination']['itemsPerPage'] ?? 50);
                $products = [];

                for ($i = ($page - 1) * $per; $i < min($page * $per, $coopTotal); $i++) {
                    $products[] = $this->coopProduct('C'.$i, 'Co.op sản phẩm '.$i, 1000 + $i);
                }

                return Http::response([
                    'code' => '0',
                    'pagination' => ['totalItems' => $coopTotal, 'totalPages' => (int) ceil($coopTotal / $per)],
                    'result' => ['products' => $products],
                ]);
            },
            self::BHX_URL => function (Request $request) use ($bhxTotal) {
                $pageIndex = (int) ($request['pageIndex'] ?? 0);
                $per = (int) ($request['pageSize'] ?? 50);
                $products = [];

                for ($i = $pageIndex * $per; $i < min(($pageIndex + 1) * $per, $bhxTotal); $i++) {
                    $products[] = $this->bhxProduct((string) (3000 + $i), 'BHX sản phẩm '.$i, 2000 + $i);
                }

                return Http::response([
                    'code' => 0,
                    'data' => ['products' => $products, 'total' => $bhxTotal],
                ]);
            },
        ]);
    }
}
