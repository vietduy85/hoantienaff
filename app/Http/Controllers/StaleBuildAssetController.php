<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * File /build/assets/app-<hash>.css|js của lần build trước (đã bị `npm run build` xoá).
 *
 * Apache chỉ chuyển vào Laravel khi file không tồn tại. Trang HTML cũ còn trong trình duyệt/tab
 * (iOS mở lại tab) xin file cũ ⇒ 404 ⇒ trang mất CSS. Chuyển sang file hiện tại cùng tên entry
 * trong manifest để trang vẫn hiện đúng giao diện.
 */
class StaleBuildAssetController extends Controller
{
    public function __invoke(string $file): RedirectResponse
    {
        // Vite đặt tên <entry>-<hash 8 ký tự>.<ext>; hash có thể chứa "-" hoặc "_".
        abort_unless(preg_match('/^(.+)-[A-Za-z0-9_-]{8}\.(css|js)$/', $file, $m), 404);
        [, $entry, $ext] = $m;

        $manifestPath = public_path('build/manifest.json');
        $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;

        foreach ((array) $manifest as $chunk) {
            $current = $chunk['file'] ?? '';

            if (preg_match('/^assets\/'.preg_quote($entry, '/').'-[A-Za-z0-9_-]{8}\.'.$ext.'$/', $current)
                && $current !== 'assets/'.$file) {
                return redirect('/build/'.$current, 302)->header('Cache-Control', 'no-store');
            }
        }

        abort(404);
    }
}
