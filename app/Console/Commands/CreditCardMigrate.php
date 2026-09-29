<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Chạy migration riêng cho module Thẻ tín dụng.
 *
 * Module Thẻ tín dụng nằm ở database `hoantien_creditcard` (connection `creditcard`)
 * và migrations nằm ở thư mục con `database/migrations/creditcard/`.
 *
 * Vì `Migrator::getMigrationFiles()` glob KHÔNG đệ quy
 * (`glob($path.'/*_*.php')`), các migration trong thư mục con sẽ KHÔNG bị
 * `php artisan migrate` (main DB) chạy. Command này là đường chạy DUY NHẤT
 * được phép cho schema Thẻ tín dụng.
 *
 * Tương đương thủ công:
 *   php artisan migrate --database=creditcard \
 *       --path=database/migrations/creditcard --realpath
 *
 * Bảng `migrations` của connection `creditcard` nằm trong database
 * `hoantien_creditcard` (khác database), nên hoàn toàn độc lập với
 * bảng `migrations` của `hoantienaff`.
 */
class CreditCardMigrate extends Command
{
    protected $signature = 'credit-card:migrate
                            {--fresh : Xoá toàn bộ schema Thẻ tín dụng rồi migrate lại từ đầu}
                            {--pretend : Chỉ in SQL, không thực thi}
                            {--force : Chạy cả khi môi trường production}
                            {--seed : Chạy luôn CreditCardSeeder sau khi migrate}';

    protected $description = 'Chạy migration cho module Thẻ tín dụng trên connection `creditcard` (database riêng)';

    /**
     * Thư mục chứa migration Thẻ tín dụng (tương đối so với base path).
     */
    private const MIGRATION_PATH = 'database/migrations/creditcard';

    private const CONNECTION = 'creditcard';

    public function handle(): int
    {
        $this->components->info('Module Thẻ tín dụng — connection: '.self::CONNECTION);
        $this->components->twoColumnDetail('Database', $this->databaseName());
        $this->components->twoColumnDetail('Migration path', self::MIGRATION_PATH);
        $this->newLine();

        $command = ($this->option('fresh') ? 'migrate:fresh' : 'migrate');

        $parameters = [
            '--database' => self::CONNECTION,
            '--path' => self::MIGRATION_PATH,
            '--realpath' => false,
        ];

        if ($this->option('pretend')) {
            $parameters['--pretend'] = true;
        } elseif ($this->laravel->isProduction() || $this->option('force')) {
            $parameters['--force'] = true;
        }

        $exitCode = $this->call($command, $parameters);

        if ($exitCode !== self::SUCCESS) {
            $this->components->error('Migration Thẻ tín dụng thất bại.');

            return self::FAILURE;
        }

        if ($this->option('seed') && ! $this->option('pretend')) {
            $this->newLine();
            $this->call('db:seed', [
                '--class' => 'CreditCardSeeder',
                '--database' => self::CONNECTION,
                '--force' => true,
            ]);
        }

        $this->newLine();
        $this->components->info('Hoàn tất. Xem trạng thái: php artisan credit-card:migrate:status');

        return self::SUCCESS;
    }

    private function databaseName(): string
    {
        $name = config('database.connections.'.self::CONNECTION.'.database');

        return is_string($name) ? $name : '(không xác định)';
    }
}
