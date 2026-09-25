<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsrfTokenEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_returns_current_session_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/csrf-token');

        $response->assertOk();
        $response->assertJsonStructure(['token']);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_returns_token_matching_session_token(): void
    {
        $user = User::factory()->create();

        // Force a known token into the session, the endpoint must return EXACTLY
        // the session's current token (never generate/regenerate).
        $this->actingAs($user)->withSession(['_token' => 'forced-token-xyz']);
        $response = $this->getJson('/csrf-token');

        $response->assertOk();
        $this->assertSame('forced-token-xyz', $response->json('token'));
    }

    public function test_response_is_not_cached(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson('/csrf-token');

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }

    public function test_unauthenticated_is_rejected(): void
    {
        // Guest (no valid session) => auth middleware => 401, not a fake token.
        $response = $this->getJson('/csrf-token');

        $response->assertUnauthorized();
        $this->assertNull($response->json('token'));
    }

    public function test_get_is_not_blocked_by_csrf_middleware(): void
    {
        // GET routes are not CSRF-protected; even with a stale/wrong token a GET
        // /csrf-token must still reach the handler for the current session.
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withHeader('X-CSRF-TOKEN', 'definitely-stale-token')
            ->getJson('/csrf-token');

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_does_not_rotate_session_or_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['_token' => 'before-token']);
        session()->save();
        $sessionIdBefore = session()->getId();
        $tokenBefore = session()->token();

        // Truyền ĐÚNG session cookie để request dùng ĐÚNG session đang test:
        // - json()/getJson() không gửi cookie trừ khi withCredentials() được bật.
        // - withoutMiddleware(EncryptCookies): bỏ bộ mã hóa/giải mã cookie,
        //   nếu không cookie raw sẽ bị DecryptException và StartSession tạo id MỚI.
        // - withUnencryptedCookie: gửi session id dạng raw, StartSession setId()
        //   giữ nguyên id; nếu id không hợp lệ nó mới generate id khác.
        $response = $this
            ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
            ->withCredentials()
            ->withUnencryptedCookie(session()->getName(), $sessionIdBefore)
            ->getJson('/csrf-token');

        $response->assertOk();
        $this->assertSame('before-token', $response->json('token'));
        $this->assertSame($tokenBefore, session()->token());
        $this->assertSame($sessionIdBefore, session()->getId());
    }
}