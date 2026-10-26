<?php
require 'vendor/autoload.php';
\ = require_once 'bootstrap/app.php';
\ = \->make(Illuminate\Contracts\Console\Kernel::class);
\->bootstrap();
\ = new App\Http\Controllers\Admin\OrderSyncController(app(App\Services\TikTok\TikTokOrderSyncService::class), app(App\Services\ShopeeFood\ShopeeFoodOrderSyncService::class), app(App\Services\Lazada\LazadaOrderSyncService::class), app(App\Services\AddLiveTag\ShopeeApiSyncService::class), app(App\Services\Backup\BackupStatusService::class));
\ = new ReflectionMethod(\, 'normalizeShopeeFoodCookies');
\->setAccessible(true);
\ = file_get_contents('C:\\Users\\Administrator\\Downloads\\affiliate.shopee.vn.json');
\ = json_decode(\, true);
\ = \->invoke(\, \);
echo strlen(\);

