<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CsrfForensic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TEMPORARY controlled-test endpoint suite (T2 V2 CSRF self-recovery proof).
 * Remove together with routes/web.php route + Debug\T2TestRotateSessionController
 * + config/app.php 't2_test_enabled' key (Phase 8).
 */
class T2TestRotateSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        config(['app.t2_test_enabled' => true]);

        $response = $this->postJson('/__t2-test/rotate-session');

        $response->assertUnauthorized();
    }

    public function test_get_method_is_not_allowed(): void
    {
        config(['app.t2_test_enabled' => true]);

        $user = User::factory()->create(['id' => 5]);

        $response = $this
            ->actingAs($user)
            ->withCredentials()
            ->withUnencryptedCookie(session()->getName(), session()->getId())
            ->getJson('/__t2-test/rotate-session');

        $response->assertStatus(405);
    }

    public function test_flag_disabled_returns_404(): void
    {
        config(['app.t2_test_enabled' => false]);

        $user = User::factory()->create(['id' => 5]);

        $response = $this
            ->actingAs($user)
            ->withCredentials()
            ->withUnencryptedCookie(session()->getName(), session()->getId())
            ->postJson('/__t2-test/rotate-session');

        $response->assertNotFound();
    }

    public function test_user_other_than_5_is_rejected(): void
    {
        config(['app.t2_test_enabled' => true]);

        $user = User::factory()->create(['id' => 1]);

        $response = $this
            ->actingAs($user)
            ->withCredentials()
            ->withUnencryptedCookie(session()->getName(), session()->getId())
            ->postJson('/__t2-test/rotate-session');

        $response->assertForbidden();
    }

    public function test_rotates_session_id_and_token_for_user_5(): void
    {
        config(['app.t2_test_enabled' => true]);

        $user = User::factory()->create(['id' => 5]);

        $this->actingAs($user)->withSession(['_token' => 'before-token']);
        session()->save();

        $sessionIdBefore = session()->getId();
        $tokenBefore = session()->token();
        $this->assertSame('before-token', $tokenBefore);

        $response = $this
            ->withCredentials()
            ->withUnencryptedCookie(session()->getName(), $sessionIdBefore)
            ->postJson('/__t2-test/rotate-session');

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'session_before_fp' => CsrfForensic::fp($sessionIdBefore),
            'token_before_fp' => CsrfForensic::fp($tokenBefore),
        ]);

        $sessionIdAfter = session()->getId();
        $tokenAfter = session()->token();

        $this->assertNotSame($sessionIdBefore, $sessionIdAfter);
        $this->assertNotSame($tokenBefore, $tokenAfter);
        $this->assertSame(CsrfForensic::fp($sessionIdAfter), $response->json('session_after_fp'));
        $this->assertSame(CsrfForensic::fp($tokenAfter), $response->json('token_after_fp'));
        $this->assertSame(5, (int) $user->getAuthIdentifier());
    }

    public function test_rotation_preserves_authentication(): void
    {
        config(['app.t2_test_enabled' => true]);

        $user = User::factory()->create(['id' => 5]);

        $this->actingAs($user)->withSession(['_token' => 'before-token']);
        session()->save();
        $sessionIdBefore = session()->getId();

        $response = $this
            ->withCredentials()
            ->withUnencryptedCookie(session()->getName(), $sessionIdBefore)
            ->postJson('/__t2-test/rotate-session');

        $response->assertOk();

        $this->assertTrue($this->app['auth']->guard('web')->check());
        $this->assertSame(5, (int) $this->app['auth']->guard('web')->id());
    }

    public function test_response_never_leaks_raw_session_or_token(): void
    {
        config(['app.t2_test_enabled' => true]);

        $user = User::factory()->create(['id' => 5]);

        $this->actingAs($user)->withSession(['_token' => 'before-token']);
        session()->save();
        $sessionIdBefore = session()->getId();

        $response = $this
            ->withCredentials()
            ->withUnencryptedCookie(session()->getName(), $sessionIdBefore)
            ->postJson('/__t2-test/rotate-session');

        $response->assertOk();
        $response->assertJsonStructure([
            'ok',
            'message',
            'session_before_fp',
            'session_after_fp',
            'token_before_fp',
            'token_after_fp',
        ]);
        $this->assertCount(6, json_decode($response->getContent(), true));

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString($sessionIdBefore, $content);
        $this->assertStringNotContainsString('before-token', $content);
        $this->assertStringNotContainsString($user->email, $content);
        $this->assertStringNotContainsString(session()->getId(), $content);
        $this->assertStringNotContainsString(session()->token(), $content);
    }

    public function test_other_user_valid_token_does_not_rotate(): void
    {
        config(['app.t2_test_enabled' => true]);

        $user = User::factory()->create(['id' => 9]);

        $this->actingAs($user)->withSession(['_token' => 'before-token']);
        session()->save();

        $sessionIdBefore = session()->getId();
        $tokenBefore = session()->token();
        $this->assertSame('before-token', $tokenBefore);

        $response = $this
            ->withCredentials()
            ->withUnencryptedCookie(session()->getName(), $sessionIdBefore)
            ->postJson('/__t2-test/rotate-session');

        $response->assertForbidden();
        $this->assertSame($sessionIdBefore, session()->getId());
        $this->assertSame($tokenBefore, session()->token());
    }
}