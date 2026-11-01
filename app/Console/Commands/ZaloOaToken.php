<?php

namespace App\Console\Commands;

use App\Services\Zalo\ZaloOaClient;
use Illuminate\Console\Command;
use Throwable;

class ZaloOaToken extends Command
{
    protected $signature = 'zalo-oa:token
        {refresh_token? : Refresh token lấy từ Zalo for Developers (API Explorer) — nạp lần đầu}
        {--test= : Gửi thử một tin tới user_id này sau khi nạp}';

    protected $description = 'Nạp/làm mới token Zalo OA dùng để báo admin khi có yêu cầu rút tiền';

    public function handle(ZaloOaClient $zalo): int
    {
        try {
            $zalo->refresh($this->argument('refresh_token') ?: null);
            $this->info('Đã làm mới và lưu token Zalo OA.');

            if ($userId = $this->option('test')) {
                $zalo->sendText($userId, '✅ Kết nối Zalo OA với hoantien.xyz thành công.');
                $this->info("Đã gửi tin thử tới {$userId}.");
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
