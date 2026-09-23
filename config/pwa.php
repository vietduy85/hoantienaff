<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PWA / Service Worker
    |--------------------------------------------------------------------------
    |
    | Công tắc tạm thời bật/tắt PWA và Service Worker trên toàn site.
    |
    | 'enabled' => true  : BẬT (như hiện tại): browser register /sw.js,
    |                       trang load manifest + meta PWA.
    | 'enabled' => false : TẮT (A/B test): browser KHÔNG register SW mới,
    |                       KHÔNG load manifest/meta PWA, và chương trình sẽ
    |                       unregister Service Worker cũ (nếu có) trên origin.
    |
    | Không xoá bất kỳ file PWA nào; chỉ là cờ bật/tắt ở tầng config.
    | Có thể override qua biến môi trường PWA_ENABLED (true/false) trong .env.
    |
    */

    'enabled' => env('PWA_ENABLED', false),

];