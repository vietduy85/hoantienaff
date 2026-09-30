<?php

use App\Http\Middleware\CaptureReferral;
use App\Http\Middleware\ForensicObserver;
use App\Support\AppKeyFlightRecorder;
use App\Support\CsrfForensic;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
            CaptureReferral::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'forensic' => ForensicObserver::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // =====================================================================
        // Module Thẻ tín dụng (Phase 1B): dịch exception domain ⇒ HTTP status.
        // =====================================================================
        // Service nghiệp vụ ném `InvalidArgumentException` (dữ liệu/ownership sai)
        // và `LogicException` (vi phạm quy tắc nghiệp vụ). Nếu không ánh xạ, chúng
        // thành HTTP 500 — sai và là lộ chi tiết nội bộ ra ngoài.
        //
        // CHỈ áp dụng cho route `credit-cards.api.*`: các exception này được dùng
        // ở nhiều nơi khác trong app (ShopeeFood, TikTok…) và không được đổi hành vi.
        $exceptions->render(function (InvalidArgumentException $e, Request $request) {
            if (! $request->routeIs('credit-cards.api.*')) {
                return null;
            }

            // 422: request không hợp lệ với người gửi (sai danh mục, thẻ của
            // người khác, số tiền 0…). Không phải 403 vì 403 dành cho "đã xác định
            // được quyền rồi thì bị từ chối" — quyền với thẻ/danh mục/giao dịch do
            // Policy quyết định và trả về trước khi tới đây.
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (LogicException $e, Request $request) {
            if (! $request->routeIs('credit-cards.api.*')) {
                return null;
            }

            // 409 Conflict: dữ liệu hợp lệ nhưng xung đột trạng thái — kỳ đã chốt,
            // thẻ đã đóng, version đã superseded. Không phải 400 vì client không
            // sai, mà trạng thái hiện tại đã không cho phép thao tác.
            return response()->json(['message' => $e->getMessage()], 409);
        });

        // Khi POST /logout gặp lỗi CSRF (TokenMismatchException -> HttpException 419),
        // user KHÔNG được thấy trang "419 PAGE EXPIRED".
        // Thay vào đó: invalidate session + regenerate token + redirect /login.
        // Chỉ áp dụng riêng cho route logout; các route khác giữ nguyên behavior.
        $exceptions->render(function (HttpException $e, Request $request) {
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
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || ! $request->routeIs('link-requests.store') || ! $request->isMethod('POST')) {
                return null;
            }

            if ($request->hasSession()) {
                CsrfForensic::event('csrf_failure', $request, [
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
        $exceptions->reportable(function (MissingAppKeyException $e) {
            AppKeyFlightRecorder::capture($e);

            return null;
        });
    })
    ->create();
