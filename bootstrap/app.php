<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Khi POST /logout gặp lỗi CSRF (TokenMismatchException -> HttpException 419),
        // user KHÔNG được thấy trang "419 PAGE EXPIRED".
        // Thay vào đó: invalidate session + regenerate token + redirect /login.
        // Chỉ áp dụng riêng cho route logout; các route khác giữ nguyên behavior.
        $exceptions->render(function (Symfony\Component\HttpKernel\Exception\HttpException $e, Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 419 || ! $request->routeIs('logout') || ! $request->isMethod('POST')) {
                return null;
            }

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect('/login');
        });

        // DIAGNOSTIC ONLY (AppKey Flight Recorder):
        // when MissingAppKeyException is reported, capture the exact state of
        // .env / environment / config WITHOUT changing how Laravel loads APP_KEY
        // and WITHOUT modifying the root cause. Returns null so the normal
        // reporting/logging flow is unchanged.
        $exceptions->reportable(function (Illuminate\Encryption\MissingAppKeyException $e) {
            \App\Support\AppKeyFlightRecorder::capture($e);

            return null;
        });
    })->create();
