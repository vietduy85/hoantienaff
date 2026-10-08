<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AddLiveTagConversionsClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.addlivetag.page_delay_ms' => 0,
            'services.addlivetag.max_sync_pages' => 5,
        ]);
    }

    private function row(string $sn, int $itemId, string $status = 'completed'): array
    {
        return [
            'order_sn' => $sn,
            'checkout_id' => 'C-' . $sn,
            'purchase_time' => '2026-10-06 10:00:00',
            'complete_time' => 0,
            'click_time' => '2026-10-05 09:00:00',
            'item_url' => 'https://addlivetag.com/product/?item_id=' . $itemId,
            'item_name' => 'Item ' . $itemId,
            'price' => 100000,
            'qty' => 1,
            'order_value' => 100000,
            'commission' => 1000,
            'mcn_fee' => 0,
            'sub_id1' => 'testuser',
            'status_code' => $status,
        ];
    }

    public function test_correct_endpoint_and_headers_and_params(): void
    {
        Http::fakeSequence()
            ->push(['data' => [$this->row('260101ABC123', 123456)]], 200)
            ->push(['data' => []], 200);

        $client = app(\App\Services\AddLiveTag\ConversionsClient::class);
        $res = $client->fetch(from: '2026-10-06', to: '2026-10-06', page: 1, pageSizeOverride: 100);

        Http::assertSentCount(2);
        $first = Http::recorded()[0][0];
        $data = $first->data();
        $url = (string) $first->url();
        $this->assertStringContainsString('https://addlivetag.com/api/v1/conversions.php', $url);
        $this->assertEquals('items', $data['type'] ?? null);
        $this->assertEquals('shopee', $data['source'] ?? null);
        $this->assertEquals('2026-10-06', $data['from'] ?? null);
        $this->assertEquals('2026-10-06', $data['to'] ?? null);
        $this->assertEquals(1, $data['page'] ?? null);
        $this->assertEquals(100, $data['page_size'] ?? null);
        $apiKeyHeader = $first->header('X-API-Key');
        $this->assertNotEmpty($apiKeyHeader);
        $this->assertNotEmpty($apiKeyHeader[0]);
        $this->assertEquals(config('services.addlivetag.api_key'), $apiKeyHeader[0]);

        $second = Http::recorded()[1][0];
        $this->assertEquals(2, $second->data()['page'] ?? null);

        $this->assertCount(1, $res['items']);
        $this->assertFalse($res['truncated']);
        $this->assertEquals('260101ABC123', $res['items'][0]['order_sn']);
    }

    public function test_pagination_until_empty_final_page(): void
    {
        Http::fakeSequence()
            ->push(['data' => [$this->row('O1', 1)]], 200)
            ->push(['data' => [$this->row('O2', 2)]], 200)
            ->push(['data' => []], 200);

        $client = app(\App\Services\AddLiveTag\ConversionsClient::class);
        $res = $client->fetch(pageSizeOverride: 1);

        $this->assertCount(2, $res['items']);
        $this->assertFalse($res['truncated']);
        $this->assertEquals(3, $res['pages_fetched']);
    }

    public function test_max_pages_protection_marks_truncated(): void
    {
        Http::fake(function () {
            return Http::response(['data' => [$this->row('LOOP', 99)]], 200);
        });

        $client = app(\App\Services\AddLiveTag\ConversionsClient::class);
        $res = $client->fetch(pageSizeOverride: 1);

        $this->assertTrue($res['truncated'], 'running past max pages must be flagged truncated');
        $this->assertEquals(5, $res['pages_fetched']);
    }

    public function test_429_is_retried_then_succeeds(): void
    {
        Http::fakeSequence()
            ->push(null, 429)
            ->push(['data' => [$this->row('RETRY', 7)]], 200)
            ->push(['data' => []], 200);

        $client = app(\App\Services\AddLiveTag\ConversionsClient::class);
        $res = $client->fetch(pageSizeOverride: 1);

        $this->assertCount(1, $res['items']);
        $this->assertEquals('RETRY', $res['items'][0]['order_sn']);
    }

    public function test_5xx_is_retried_then_succeeds(): void
    {
        Http::fakeSequence()
            ->push(null, 503)
            ->push(['data' => [$this->row('SERVER', 8)]], 200)
            ->push(['data' => []], 200);

        $client = app(\App\Services\AddLiveTag\ConversionsClient::class);
        $res = $client->fetch(pageSizeOverride: 1);

        $this->assertCount(1, $res['items']);
    }

    public function test_missing_api_key_is_reported_without_exposing_value(): void
    {
        config(['services.addlivetag.api_key' => '']);
        $this->app->forgetInstance(\App\Services\AddLiveTag\ConversionsClient::class);

        Http::fake([
            'https://addlivetag.com/api/v1/conversions.php*' => Http::response(['data' => []], 200),
        ]);

        $client = app(\App\Services\AddLiveTag\ConversionsClient::class);
        $client->fetch();

        Http::assertSent(function ($request) {
            $key = $request->header('X-API-Key')[0] ?? '';
            $this->assertSame('', $key);

            return true;
        });
    }
}
