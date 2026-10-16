<?php

namespace Tests\Feature\AccessTrade;

use App\Models\AffiliateDetectRule;
use App\Models\LinkRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccessTrade\AccessTradeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Expected-cashback for AccessTrade links.
 *
 * AccessTrade exposes no per-product commission. The campaign `max_com` is used
 * ONLY when the campaign policy corroborates it as the single rate; otherwise no
 * estimate is shown ("Chưa tính được tiền hoàn dự kiến"), and a missing/failed
 * estimate must never break the already-created link.
 */
class AccessTradeCashbackEstimateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.product_metadata.verify_dns' => false]);

        $this->user = User::factory()->create(['username' => 'at_estimate']);

        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');

        $config = app(AccessTradeConfig::class);
        $config->setEnabled(true);
        $config->setToken('estimate-secret-token');

        AffiliateDetectRule::create([
            'name' => 'CellphoneS',
            'pattern' => 'cellphones.com.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6259155740535091857',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ]);
    }

    public function test_estimate_is_stored_and_returned_when_policy_corroborates_rate(): void
    {
        $this->fakeHappyPath('6%', 'Hoa hồng (Chưa VAT): 6% giá trị đơn hàng.');

        $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://cellphones.com.vn/laptop-lenovo.html',
        ])->assertOk()->assertJson([
            'success' => true,
            'platform' => 'AccessTrade',
            'status' => 'completed',
        ]);

        $link = LinkRequest::latest()->first();

        $this->assertSame(12900000, (int) $link->product_price);
        // 12900000 * 6% = 774000 -> net floor(774000*0.9)=696600 -> user floor(696600*0.5)=348300
        $this->assertSame(774000.0, (float) $link->estimated_cashback);
        $this->assertSame(0.50, (float) $link->cashback_rate);
        $this->assertSame(348300, (int) $link->user_estimated_cashback);

        $poll = $this->actingAs($this->user)
            ->getJson('/api/link-request/'.$link->id)
            ->assertOk();

        $this->assertSame('completed', $poll->json('status'));
        $this->assertSame(774000.0, (float) $poll->json('estimated_cashback'));
        $this->assertSame(348300.0, (float) $poll->json('user_estimated_cashback'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/campaigns')
            && str_contains($request->url(), 'campaign_id=6259155740535091857'));
    }

    public function test_no_estimate_when_policy_contradicts_max_com(): void
    {
        $this->fakeHappyPath('12%', 'Hoa hồng lên đến 5.5% giá trị đơn hàng.');

        $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://cellphones.com.vn/laptop-lenovo.html',
        ])->assertOk();

        $link = LinkRequest::latest()->first();

        $this->assertSame('completed', $link->status);
        $this->assertNull($link->estimated_cashback);
        $this->assertNull($link->user_estimated_cashback);
    }

    public function test_no_estimate_when_campaign_detail_api_fails(): void
    {
        Http::fake([
            'api.accesstrade.vn/v1/product_link/create' => Http::response($this->accesstradePayload(), 200),
            'api.accesstrade.vn/v1/campaigns*' => Http::response('', 500),
            'cellphones.com.vn/*' => Http::response($this->productPage(), 200, ['Content-Type' => 'text/html']),
        ]);

        $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://cellphones.com.vn/laptop-lenovo.html',
        ])->assertOk()->assertJson(['status' => 'completed']);

        $link = LinkRequest::latest()->first();

        $this->assertSame('completed', $link->status);
        $this->assertNull($link->estimated_cashback);
        $this->assertNull($link->user_estimated_cashback);
    }

    public function test_no_estimate_when_campaign_is_not_approved(): void
    {
        $this->fakeHappyPath('6%', 'Hoa hồng 6%', approval: 'unregistered');

        $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://cellphones.com.vn/laptop-lenovo.html',
        ])->assertOk();

        $this->assertNull(LinkRequest::latest()->first()->estimated_cashback);
    }

    private function fakeHappyPath(string $maxCom, string $policy, string $approval = 'successful'): void
    {
        Http::fake([
            'api.accesstrade.vn/v1/product_link/create' => Http::response($this->accesstradePayload(), 200),
            'api.accesstrade.vn/v1/campaigns*' => Http::response([
                'data' => [[
                    'id' => '6259155740535091857',
                    'name' => 'CellphoneS',
                    'merchant' => 'cellphones_cps',
                    'approval' => $approval,
                    'status' => 1,
                    'max_com' => $maxCom,
                    'url' => 'https://cellphones.com.vn',
                    'description' => ['commission_policy' => '<p>'.$policy.'</p>'],
                ]],
                'page' => 1,
                'total_page' => 1,
            ], 200),
            'cellphones.com.vn/*' => Http::response($this->productPage(), 200, ['Content-Type' => 'text/html']),
        ]);
    }

    private function productPage(): string
    {
        return '<!doctype html><html><head>'
            .'<meta property="og:title" content="Laptop Lenovo" />'
            .'<meta property="product:price:amount" content="12900000" />'
            .'</head><body></body></html>';
    }

    /**
     * @return array<string, mixed>
     */
    private function accesstradePayload(): array
    {
        return [
            'success' => true,
            'data' => [
                'error_link' => [],
                'success_link' => [[
                    'aff_link' => 'https://track.accesstrade.vn/aff?x=1',
                    'short_link' => 'https://at.vn/abc',
                ]],
            ],
        ];
    }
}
