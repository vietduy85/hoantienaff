<?php

namespace Tests\Feature\PriceComparison;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixture\CoopOnlineFixture;
use Tests\TestCase;

class PriceComparisonApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_search_endpoint_returns_normalized_json(): void
    {
        Http::fake([
            'https://discovery.tekoapis.com/api/v1/search' => Http::response(CoopOnlineFixture::searchResponse()),
        ]);

        $response = $this->getJson('/api/price-comparison/coop?keyword=mi+hao+hao');

        $response->assertOk()
            ->assertJsonPath('source', 'coop_online')
            ->assertJsonPath('keyword', 'mi hao hao')
            ->assertJsonPath('pagination.page', 1)
            ->assertJsonPath('pagination.per_page', 20)
            ->assertJsonPath('pagination.total', 36)
            ->assertJsonPath('pagination.total_pages', 2)
            ->assertJsonCount(3, 'products')
            ->assertJsonPath('products.0.sku', '250100313')
            ->assertJsonPath('products.0.name', 'Mì Hảo Hảo vị gà vang thùng 30 x 74g')
            ->assertJsonPath('products.0.barcode', '2000130544250')
            ->assertJsonPath('products.0.brand', 'Hảo Hảo')
            ->assertJsonPath('products.0.price', 122500)
            ->assertJsonPath('products.0.original_price', 131000)
            ->assertJsonPath('products.0.discount_amount', 8500)
            ->assertJsonPath('products.0.discount_percent', 6)
            ->assertJsonPath('products.0.image_url', 'https://lh3.googleusercontent.com/9cvptfwJcjmim6OvSyfE2WTzb6oplDApMf_JGYZcVkrETQMayQ4Kqp7ac5m1NPPDmzDVlfdEf9QAQ4VCoi9GgNzaE_eilUaR')
            ->assertJsonPath('products.0.product_url', 'https://cooponline.vn/products/250100313')
            ->assertJsonPath('products.0.stock', 12)
            ->assertJsonPath('products.0.sellable', true)
            ->assertJsonPath('products.0.unit', 'Thùng');
    }

    public function test_search_endpoint_forwards_page_and_per_page(): void
    {
        Http::fake([
            'https://discovery.tekoapis.com/api/v1/search' => Http::response(CoopOnlineFixture::searchResponse()),
        ]);

        $response = $this->getJson('/api/price-comparison/coop?keyword=m%C3%AC&page=2&per_page=5');

        $response->assertOk()
            ->assertJsonPath('pagination.page', 2)
            ->assertJsonPath('pagination.per_page', 5);

        Http::assertSent(function (Request $request) {
            return $request['pagination']['pageNumber'] === 2
                && $request['pagination']['itemsPerPage'] === 5
                && $request['terminalId'] === 26607;
        });
    }

    public function test_search_endpoint_requires_keyword(): void
    {
        $this->getJson('/api/price-comparison/coop')
            ->assertStatus(422);
    }

    public function test_search_endpoint_returns_empty_products_on_api_error(): void
    {
        Http::fake([
            'https://discovery.tekoapis.com/api/v1/search' => Http::response([], 500),
        ]);

        $this->getJson('/api/price-comparison/coop?keyword=mi')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonCount(0, 'products');
    }
}