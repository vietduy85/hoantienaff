<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Backstop: POST /logout gặp TokenMismatchException (CSRF stale/stale tab)
 * phải redirect /login chứ KHÔNG render "419 PAGE EXPIRED".
 *
 * Lưu ý: môi trường phpunit tự bypass CSRF (VerifyCsrfToken::runningUnitTests),
 * nên không thể tái hiện lỗi CSRF thật qua HTTP POST /logout. Vì vậy test trực
 * tiếp "exception rendering path" — cùng code path sản xuất chạy:
 * ExceptionHandler::render(PostLogoutRequest, TokenMismatchException).
 */
class LogoutCsrfBackstopTest extends TestCase
{
    use RefreshDatabase;

    protected function logoutHttpRequest(): \Illuminate\Http\Request
    {
        $request = \Illuminate\Http\Request::create('/logout', 'POST');
        $request->setRouteResolver(fn () => Route::getRoutes()->getByName('logout'));

        $this->app['session']->driver()->start();
        $request->setLaravelSession($this->app['session']->driver());
        $this->app->instance('request', $request);

        return $request;
    }

    public function test_logout_419_redirects_to_login_instead_of_page_expired(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $request = $this->logoutHttpRequest();

        $handler = app(ExceptionHandler::class);
        $response = $handler->render($request, new TokenMismatchException('CSRF token mismatch.'));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('/login', $response->getTargetUrl());
        $this->assertNotEquals(419, $response->getStatusCode());
    }

    public function test_logout_419_invalidates_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $sessionBefore = $this->app['session']->driver()->getId();

        $handler = app(ExceptionHandler::class);
        $handler->render($this->logoutHttpRequest(), new TokenMismatchException('CSRF token mismatch.'));

        // Session bị invalidate => ID mới khác ID cũ.
        $this->assertNotSame($sessionBefore, $this->app['session']->driver()->getId());
    }

    public function test_regular_logout_with_valid_csrf_still_works(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $token = $this->app['session']->token();
        $response = $this->post('/logout', ['_token' => $token]);

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_non_logout_request_419_keeps_existing_behavior(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $request = \Illuminate\Http\Request::create('/some-other-post', 'POST');
        $seg = '/some-other-post';
        $request->setRouteResolver(fn () => Route::name('placeholder')->post($seg, fn () => null));
        $this->app['session']->driver()->start();
        $request->setLaravelSession($this->app['session']->driver());
        $this->app->instance('request', $request);

        $handler = app(ExceptionHandler::class);
        $response = $handler->render($request, new TokenMismatchException('CSRF token mismatch.'));

        // Không phải logout => callback trả null => giữ behavior 419 thông thường
        // (KHÔNG redirect /login, vẫn là response 419 như cũ).
        $this->assertNotInstanceOf(RedirectResponse::class, $response);
        $this->assertNotSame('/login', method_exists($response, 'getTargetUrl') ? $response->getTargetUrl() : null);
    }
}
