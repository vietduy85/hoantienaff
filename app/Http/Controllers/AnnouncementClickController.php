<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;

/** Bấm link trong thông báo trang chủ: đếm lượt rồi chuyển tới URL admin đã đặt. */
class AnnouncementClickController extends Controller
{
    public function __invoke(Announcement $announcement): RedirectResponse
    {
        // Chỉ chuyển tới URL lưu trong DB, không nhận URL từ query → không bị lợi dụng làm open redirect.
        abort_if(blank($announcement->url), 404);

        // toBase(): không chạm updated_at — updated_at nằm trong khoá "đã ẩn" của user.
        Announcement::whereKey($announcement->id)->toBase()->increment('click_count');

        return redirect()->away($announcement->url);
    }
}
