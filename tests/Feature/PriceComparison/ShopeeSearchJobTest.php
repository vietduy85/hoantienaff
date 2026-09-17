<?php

namespace Tests\Feature\PriceComparison;

use App\Services\AffiliateSearchLinks\Providers\ShopeeAffiliateSearchLinkProvider;
use App\Models\LinkRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixture\CoopOnlineFixture;
use Tests\TestCase;

class ShopeeSearchJobTest extends TestCase
{
    use RefreshDatabase;

    private string $extensionToken = 'test-extension-token';

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config(['services.affiliate_extension.token' => $this->extensionToken]);

        $this->userA = User::factory()->create(['username' => 'user_a']);
        $this->userB = User::factory()->create(['username' => 'user_b']);
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

    private function fakeCoopSearch(array $overrides = []): void
    {
        Http::fake([
            'https://discovery.tekoapis.com/api/v1/search' => Http::response(
                CoopOnlineFixture::searchResponse($overrides),
            ),
        ]);
    }

    private function search(string $keyword): \Illuminate\Testing\TestResponse
    {
        return $this->get('/so-sanh-gia?keyword=' . rawurlencode($keyword));
    }

    private function expectedOriginalUrl(string $keyword): string
    {
        return (new ShopeeAffiliateSearchLinkProvider())->buildSearchUrl($keyword);
    }

    public function test_guest_search_creates_no_shopee_job_and_shows_fallback(): void
    {
        $this->fakeCoopEmpty();

        $response = $this->search('mì Hảo Hảo');

        $response->assertOk();
        $this->assertDatabaseCount('link_requests', 0);
        $response->assertSee('Xem trên Shopee ↗', escape: false);
        $response->assertDontSee('x-data="pcShopeeCard', escape: false);
    }

    public function test_authenticated_search_creates_shopee_pending_job(): void
    {
        $this->fakeCoopEmpty();

        $this->actingAs($this->userA)->get('/so-sanh-gia?keyword=' . rawurlencode('mì Hảo Hảo'))->assertOk();

        $this->assertDatabaseCount('link_requests', 1);

        $job = LinkRequest::first();

        $this->assertSame($this->userA->id, $job->user_id);
        $this->assertSame('Shopee', $job->platform);
        $this->assertSame('pending', $job->status);
        $this->assertSame($this->expectedOriginalUrl('mì Hảo Hảo'), $job->original_url);
        $this->assertNull($job->affiliate_url);
    }

    public function test_pending_card_is_rendered_with_job_id(): void
    {
        $this->fakeCoopEmpty();

        $response = $this->actingAs($this->userA)->get('/so-sanh-gia?keyword=vinamilk');

        $response->assertOk()
            ->assertSee('template x-if="pending"', escape: false)
            ->assertSee('pcShopeeCard(JSON.parse', escape: false);

        $job = LinkRequest::first();
        $this->assertNotNull($job);
        $response->assertSee((string) $job->id);
    }

    public function test_regardless_of_coop_product_count_shopee_job_runs(): void
    {
        $this->fakeCoopSearch();

        $response = $this->actingAs($this->userA)->get('/so-sanh-gia?keyword=sữa');

        $response->assertOk();
        $this->assertDatabaseCount('link_requests', 1);
        $response->assertSee('x-data="pcShopeeCard', escape: false);
    }

    public function test_same_keyword_search_reuses_existing_job(): void
    {
        $this->fakeCoopEmpty();

        $this->actingAs($this->userA)->get('/so-sanh-gia?keyword=mì')->assertOk();
        $this->actingAs($this->userA)->get('/so-sanh-gia?keyword=mì')->assertOk();

        $this->assertDatabaseCount('link_requests', 1);
    }

    public function test_different_keyword_creates_separate_job(): void
    {
        $this->fakeCoopEmpty();

        $this->actingAs($this->userA)->get('/so-sanh-gia?keyword=mì')->assertOk();
        $this->actingAs($this->userA)->get('/so-sanh-gia?keyword=sữa')->assertOk();

        $this->assertDatabaseCount('link_requests', 2);
        $this->assertNotSame(
            LinkRequest::orderByDesc('id')->first()->id,
            LinkRequest::orderBy('id')->first()->id,
        );
    }

    public function test_completed_job_renders_mua_sam_and_is_reused(): void
    {
        $this->fakeCoopEmpty();

        $this->actingAs($this->userA)->get('/so-sanh-gia?keyword=mì')->assertOk();
        $job = LinkRequest::first();

        $job->update(['status' => 'completed', 'affiliate_url' => 'https://s.shopee.vn/abc123']);

        $response = $this->actingAs($this->userA)->get('/so-sanh-gia?keyword=mì');

        $response->assertOk()
            ->assertSee('https://s.shopee.vn/abc123')
            ->assertSee('Mua sắm ↗', escape: false)
            ->assertDontSee('x-data="pcShopeeCard', escape: false);

        $this->assertDatabaseCount('link_requests', 1);
    }

    public function test_completed_job_not_reused_across_users(): void
    {
        $this->fakeCoopEmpty();

        $this->actingAs($this->userA)->get('/so-sanh-gia?keyword=mì')->assertOk();
        $jobA = LinkRequest::first();
        $jobA->update(['status' => 'completed', 'affiliate_url' => 'https://s.shopee.vn/userA']);

        $this->actingAs($this->userB)->get('/so-sanh-gia?keyword=mì')->assertOk();

        $this->assertDatabaseCount('link_requests', 2);

        $jobB = LinkRequest::orderByDesc('id')->first();
        $this->assertSame($this->userB->id, $jobB->user_id);
        $this->assertSame('pending', $jobB->status);
        $this->assertNull($jobB->affiliate_url);
    }

    public function test_failed_job_is_not_reused_and_new_job_is_created(): void
    {
        $this->fakeCoopEmpty();

        $this->actingAs($this->userA)->get('/so-sanh-gia?keyword=mì')->assertOk();
        LinkRequest::first()->update(['status' => 'failed', 'affiliate_url' => '']);

        $response = $this->actingAs($this->userA)->get('/so-sanh-gia?keyword=mì');
        $response->assertOk()->assertSee('x-data="pcShopeeCard', escape: false);

        $this->assertDatabaseCount('link_requests', 2);

        $latest = LinkRequest::orderByDesc('id')->first();
        $this->assertSame('pending', $latest->status);
    }

    public function test_extension_loop_completes_job_and_poll_returns_link(): void
    {
        $this->fakeCoopEmpty();

        $this->actingAs($this->userA)->get('/so-sanh-gia?keyword=mì')->assertOk();
        $job = LinkRequest::first();

        $jobs = $this->getJson('/api/extension/jobs?token=' . $this->extensionToken);
        $jobs->assertOk()
            ->assertJsonPath('jobs.0.id', $job->id)
            ->assertJsonPath('jobs.0.original_url', $job->original_url)
            ->assertJsonPath('jobs.0.username', 'user_a');

        $this->assertSame('processing', $job->fresh()->status);

        $result = $this->postJson('/api/extension/results?token=' . $this->extensionToken, [
            'results' => [
                ['id' => $job->id, 'affiliate_url' => 'https://s.shopee.vn/xyz789', 'status' => 'completed'],
            ],
        ]);
        $result->assertOk()->assertJsonPath('ok', true);

        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame('https://s.shopee.vn/xyz789', $job->fresh()->affiliate_url);

        $poll = $this->actingAs($this->userA)->getJson('/api/link-request/' . $job->id);
        $poll->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('affiliate_url', 'https://s.shopee.vn/xyz789')
            ->assertJsonPath('platform', 'Shopee');
    }

    public function test_status_poll_endpoint_returns_pending_without_affiliate_url(): void
    {
        $this->fakeCoopEmpty();

        $this->actingAs($this->userA)->get('/so-sanh-gia?keyword=mì')->assertOk();
        $job = LinkRequest::first();

        $this->actingAs($this->userA)
            ->getJson('/api/link-request/' . $job->id)
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('affiliate_url', null);
    }

    public function test_status_poll_endpoint_rejects_other_users(): void
    {
        $this->fakeCoopEmpty();

        $this->actingAs($this->userA)->get('/so-sanh-gia?keyword=mì')->assertOk();
        $job = LinkRequest::first();

        $this->actingAs($this->userB)
            ->getJson('/api/link-request/' . $job->id)
            ->assertStatus(403);
    }

    public function test_extension_endpoints_reject_bad_token(): void
    {
        $this->fakeCoopEmpty();

        $this->getJson('/api/extension/jobs?token=wrong')->assertStatus(401);

        $this->postJson('/api/extension/results?token=wrong', [
            'results' => [['id' => 1, 'affiliate_url' => 'x', 'status' => 'completed']],
        ])->assertStatus(401);
    }

    public function test_lazada_and_tiktok_fallbacks_unchanged(): void
    {
        $this->fakeCoopEmpty();

        $response = $this->actingAs($this->userA)->get('/so-sanh-gia?keyword=mì');

        $response->assertOk()
            ->assertSee('Xem trên Lazada ↗', escape: false)
            ->assertSee('Xem trên TikTok Shop ↗', escape: false);
    }

    public function test_coop_provider_still_called_on_authenticated_search(): void
    {
        $this->fakeCoopEmpty();

        $this->actingAs($this->userA)->get('/so-sanh-gia?keyword=sữa')->assertOk();

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://discovery.tekoapis.com/api/v1/search'
                && $request['query'] === 'sữa';
        });
    }

    public function test_empty_keyword_creates_no_job(): void
    {
        $this->fakeCoopEmpty();

        $this->actingAs($this->userA)->get('/so-sanh-gia')->assertOk();

        $this->assertDatabaseCount('link_requests', 0);
    }
}