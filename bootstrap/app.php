<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Env;

// Disable Laravel's PutenvAdapter so that Laravel loads environment purely from
// the Dotenv/Repository (via $_SERVER / $_ENV) and does NOT let a stale
// OS/PHP-process `getenv('APP_KEY')` shadow the real APP_KEY from .env.
// Must run before the HTTP/Console kernel bootstraps LoadEnvironmentVariables
// (kernel bootstrappers[0]); bootstrap/app.php is evaluated before handleRequest.
Env::disablePutenv();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            \App\Http\Middleware\CaptureReferral::class,
        ]);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'forensic' => \App\Http\Middleware\ForensicObserver::class,
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

        // FORENSIC ONLY (Root Cause A + Root Cause B): ghi chính xác trạng thái
        // request token / session token / session id vào đúng thời điểm 419 xảy ra
        // trên POST /link-requests. KHÔNG thay đổi behavior (chỉ thêm một observer,
        // trả về null để luồng render 419 mặc định tiếp tục).
        $exceptions->render(function (Symfony\Component\HttpKernel\Exception\HttpException $e, Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 419 || ! $request->routeIs('link-requests.store') || ! $request->isMethod('POST')) {
                return null;
            }

            if ($request->hasSession()) {
                \App\Support\CsrfForensic::event('csrf_failure', $request, [
                    'page_instance_id' => (string) $request->header('X-Forensic-Page-Id', ''),
                    't2_flow_id' => (string) $request->header('X-Forensic-T2-Flow-Id', ''),
                    'response_status' => 419,
                ]);
            }

            return null;
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
    })
    ->create();
