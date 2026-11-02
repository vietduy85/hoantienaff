<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trang HTML: cấm trình duyệt lưu lại (no-store).
 *
 * Mặc định Laravel gửi "no-cache, private" — trình duyệt vẫn được LƯU trang, chỉ cần hỏi lại server.
 * iOS (Safari/Chrome) khi mở lại tab cũ hiện thẳng bản đã lưu, mà bản đó trỏ tới
 * /build/assets/app-<hash>.css của lần build trước (đã bị xoá) ⇒ trang mất CSS, phải F5.
 * Trang cũ còn mang CSRF token cũ ⇒ dễ dính 419.
 */
class NoStoreHtml
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
