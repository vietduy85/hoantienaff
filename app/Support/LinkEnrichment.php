<?php

namespace App\Support;

use Closure;

/**
 * Chạy tác vụ "lấy thông tin sản phẩm" của link vừa tạo, tách khỏi response.
 *
 * - LINK_ENRICH_VIA_QUEUE=true: đẩy vào queue riêng `link-enrich`, do Windows service
 *   HoanTienAff-Queue (php artisan queue:work --queue=link-enrich) xử lý. Response trả ngay.
 * - false (mặc định): afterResponse như cũ. Trên Apache + mod_php cách này vẫn giữ
 *   trình duyệt chờ tới khi làm xong, nên chỉ dùng khi chưa có worker.
 */
final class LinkEnrichment
{
    public const QUEUE = 'link-enrich';

    /** Có worker queue để chạy việc nền thật sự tách khỏi response. */
    public static function viaQueue(): bool
    {
        return (bool) config('app.link_enrich_via_queue');
    }

    public static function dispatch(Closure $task): void
    {
        if (self::viaQueue()) {
            dispatch($task)->onQueue(self::QUEUE);

            return;
        }

        dispatch($task)->afterResponse();
    }
}
