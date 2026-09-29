<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Xem trạng thái migration riêng của module Thẻ tín dụng.
 *
 * Cần thiết vì `php artisan migrate:status` (mặc định) chỉ nhìn thư mục
 * `database/migrations/*.php` (không đệ quy) và chỉ connection chính —
 * nên sẽ KHÔNG hiển thị trạng thái của schema Thẻ tín dụng.
 */
class CreditCardMigrateStatus extends Command
{
    protected $signature = 'credit-card:migrate:status
                            {--pending : Chỉ liệt kê migration chưa chạy}';

    protected $description = 'Xem trạng thái migration của module Thẻ tín dụng (connection `creditcard`)';

    private const MIGRATION_PATH = 'database/migrations/creditcard';

    private const CONNECTION = 'creditcard';

    public function handle(): int
    {
        $this->components->info('Module Thẻ tín dụng — connection: '.self::CONNECTION);
        $this->components->twoColumnDetail('Database', (string) config('database.connections.'.self::CONNECTION.'.database'));
        $this->components->twoColumnDetail('Migration path', self::MIGRATION_PATH);
        $this->newLine();

        $exitCode = $this->call('migrate:status', [
            '--database' => self::CONNECTION,
            '--path' => self::MIGRATION_PATH,
            '--pending' => (bool) $this->option('pending'),
        ]);

        return $exitCode === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }
}
