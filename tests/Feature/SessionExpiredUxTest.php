<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\TikTok\TikTokAffiliateService;
use App\Services\TikTok\TikTokServiceException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

class SessionExpiredUxTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'ux-test',
        ]);

        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');
    }

    // 1. Authenticated normal POST still works (behavior unchanged)
    public function test_authenticated_normal_post_still_works(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://lazada.vn/product/123',
            ]);

        $response->assertStatus(200);
    }

    // 2. Expired session (unauthenticated XHR) -> 401
    public function test_expired_session_is_recognized_as_401(): void
    {
        $response = $this->postJson('/link-requests', [
            'original_url' => 'https://lazada.vn/product/123',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    // 3. CSRF expired -> 419 JSON for XHR
    public function test_csrf_token_mismatch_is_recognized_as_419(): void
    {
        $request = Request::create('/link-requests', 'POST', [], [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
            'HTTP_ACCEPT'          => 'application/json',
        ]);

        $response = app(ExceptionHandler::class)->render(
            $request,
            new TokenMismatchException('CSRF token mismatch.')
        );

        $this->assertSame(419, $response->getStatusCode());
        $this->assertSame(
            'CSRF token mismatch.',
            json_decode($response->getContent(), true)['message'] ?? null
        );
    }

    // 4. Validation error -> 422, NOT session expired
    public function test_validation_error_is_not_session_expired(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'not-a-valid-url',
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors']);
    }

    // 5. Server error -> 500, NOT session expired
    public function test_server_error_is_not_session_expired(): void
    {
        $request = Request::create('/link-requests', 'POST', [], [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
            'HTTP_ACCEPT'          => 'application/json',
        ]);

        $response = app(ExceptionHandler::class)->render(
            $request,
            new \RuntimeException('boom')
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertNotSame(401, $response->getStatusCode());
        $this->assertNotSame(419, $response->getStatusCode());
    }

    // 6. Affiliate/business error -> 422 success:false, NOT session expired
    public function test_affiliate_business_error_is_not_session_expired(): void
    {
        $mock = $this->createMock(TikTokAffiliateService::class);
        $mock->method('createAffiliateLink')->willThrowException(
            new TikTokServiceException('API error', 500, 'Internal error')
        );
        $this->app->instance(TikTokAffiliateService::class, $mock);

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://tiktok.com/item/fail',
            ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

    // 7. Logout when session expired -> login redirect (no Page Expired UX)
    public function test_logout_when_session_expired_redirects_to_login(): void
    {
        $response = $this->post('/logout');

        $response->assertRedirect(route('login'));
    }

    // 7b. Production reality: token mismatch on logout renders 419 (frontend intercepts)
    public function test_logout_with_token_mismatch_renders_419(): void
    {
        $request = Request::create('/logout', 'POST');

        $response = app(ExceptionHandler::class)->render(
            $request,
            new TokenMismatchException('CSRF token mismatch.')
        );

        $this->assertSame(419, $response->getStatusCode());
    }
}