<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Xuất CSV toàn bộ dữ liệu module Thẻ tín dụng + MANIFEST.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO CẦN
 * ---------------------------------------------------------------------------
 * Module đang ở giữa các giai đoạn thay đổi schema (1B đã đổi UserCard từ
 * `product_id` sang `bank_id`; 1C sẽ mở thêm API và có thể thêm migration).
 * `down()` của migration 1B trả `product_id` về NOT NULL, tức chỉ quay lui
 * được nếu còn giữ được dữ liệu `bank_id` / `name` ở nơi khác.
 *
 * Command này là công cụ CHỈ ĐỌC: không insert/update/delete, không chạy
 * migration, không đụng connection chính.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO `credit_card_user_cards` ĐƯỢC EXPORT RIÊNG
 * ---------------------------------------------------------------------------
 * Bảng thẻ giờ tham chiếu ngân hàng qua `bank_id` (con số). Nếu chỉ export cột
 * `bank_id` thì khi phục hồi vào DB khác (số id có thể khác) sẽ mất liên kết.
 * File riêng của bảng thẻ vì vậy JOIN thêm `credit_card_banks` để có sẵn
 * `bank_slug`, `bank_code_canonical`, `bank_aliases` và `bank_name` — đủ để
 * phục hồi logic mà không cần bảng banks còn nguyên.
 *
 * ---------------------------------------------------------------------------
 * CÁCH DÙNG
 * ---------------------------------------------------------------------------
 *   php artisan credit-card:backup
 *   php artisan credit-card:backup --verify          # export rồi đọc lại kiểm tra
 *   php artisan credit-card:backup --directory=/tmp/cc # ghi ra nơi khác
 */
class CreditCardBackup extends Command
{
    protected $signature = 'credit-card:backup
                            {--directory= : Thư mục đích (mặc định storage/app/backups/credit-card)}
                            {--verify : Sau khi ghi, đọc lại file và đối chiếu số dòng với DB}
                            {--keep= : Chỉ giữ N bản backup gần nhất mỗi bảng (0 = giữ tất cả)}';

    protected $description = 'Xuất CSV dữ liệu module Thẻ tín dụng (connection `creditcard`) kèm MANIFEST — chỉ đọc, không sửa DB';

    private const CONNECTION = 'creditcard';

    /**
     * Các bảng export, theo thứ tự phụ thuộc (parent trước con) để người đọc
     * file thấy quan hệ. Bảng nào không tồn tại sẽ được bỏ qua và ghi vào
     * MANIFEST thay vì làm hỏng cả lần backup.
     *
     * @var list<string>
     */
    private const TABLES = [
        'credit_card_banks',
        'credit_card_categories',
        // `credit_card_products` không nằm trong danh sách 9 bảng bắt buộc, nhưng
        // vẫn export: `user_cards.product_id` trỏ tới nó và Phase 1C chưa có quyết
        // định drop. Bỏ qua thì `migrate --fresh` sẽ xoá mà không có đường phục hồi.
        'credit_card_products',
        'credit_card_policy_templates',
        'credit_card_policies',
        'credit_card_policy_tiers',
        'credit_card_policy_tier_categories',
        'credit_card_user_cards',
        'credit_card_statement_periods',
        'credit_card_transactions',
    ];

    public function handle(): int
    {
        $stamp = now()->format('Y-m-d_His');
        $directory = $this->option('directory') ?: storage_path('app/backups/credit-card');

        File::ensureDirectoryExists($directory);

        $this->components->info('Backup module Thẻ tín dụng — chỉ đọc, không sửa dữ liệu.');
        $this->components->twoColumnDetail('Database', (string) config('database.connections.'.self::CONNECTION.'.database'));
        $this->components->twoColumnDetail('Connection', self::CONNECTION);
        $this->components->twoColumnDetail('Thư mục đích', $directory);
        $this->newLine();

        $manifest = [
            'created_at' => now()->toIso8601String(),
            'timezone' => config('app.timezone'),
            'database' => (string) config('database.connections.'.self::CONNECTION.'.database'),
            'connection' => self::CONNECTION,
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'mode' => 'read-only',
            'stamp' => $stamp,
            'tables' => [],
            'skipped' => [],
            'verify' => null,
        ];

        $failed = false;

        foreach (self::TABLES as $table) {
            if (! Schema::connection(self::CONNECTION)->hasTable($table)) {
                $manifest['skipped'][] = ['table' => $table, 'reason' => 'Bảng không tồn tại trên connection creditcard.'];

                $this->components->warn("Bỏ qua {$table} (không tồn tại)");

                continue;
            }

            $result = $table === 'credit_card_user_cards'
                ? $this->exportUserCards($directory, $stamp)
                : $this->exportTable($table, $directory, $stamp);

            $manifest['tables'][] = $result;
            $failed = $failed || $result['status'] !== 'ok';

            $this->components->twoColumnDetail(
                $table,
                sprintf('%d dòng → %s', $result['rows'], $result['file'])
            );
        }

        // Bảng không nằm trong danh sách export bắt buộc nhưng vẫn tồn tại:
        // ghi lại để không ai tưởng là đã backup trọn vẹn.
        $extra = $this->findExtraTables();

        if ($extra !== []) {
            $manifest['not_exported'] = $extra;

            $this->newLine();
            $this->components->warn('Bảng có trong DB nhưng KHÔNG export: '.implode(', ', $extra));
        }

        if ($this->option('verify')) {
            $this->newLine();
            $manifest['verify'] = $this->verify($directory, $stamp, $manifest['tables']);
            $failed = $failed || $manifest['verify']['ok'] === false;
        }

        $manifestPath = $directory.'/MANIFEST_'.$stamp.'.txt';
        File::put($manifestPath, $this->renderManifest($manifest, $directory));
        File::put($directory.'/MANIFEST_LATEST.txt', $this->renderManifest($manifest, $directory));

        $this->newLine();
        $this->components->info("MANIFEST: {$manifestPath}");

        $keep = (int) $this->option('keep');

        if ($keep > 0) {
            $this->prune($directory, $keep);
        }

        if ($failed) {
            $this->newLine();
            $this->components->error('Backup hoàn tất NHƯNG có bảng lỗi — xem MANIFEST.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Hoàn tất. DB không bị thay đổi.');

        return self::SUCCESS;
    }

    /**
     * Export bảng thường: mọi cột của bảng, giá trị nguyên trạng.
     *
     * @return array<string, mixed>
     */
    private function exportTable(string $table, string $directory, string $stamp): array
    {
        $file = $this->fileName($table, $stamp);
        $path = $directory.'/'.$file;

        try {
            $connection = DB::connection(self::CONNECTION);
            $columns = Schema::connection(self::CONNECTION)->getColumnListing($table);

            $rows = 0;
            $handle = fopen($path, 'w');

            if ($handle === false) {
                throw new \RuntimeException("Không mở được file {$path} để ghi.");
            }

            fputcsv($handle, $columns);

            // Chunk để không nạp toàn bộ bảng vào RAM khi dữ liệu lớn dần.
            $connection->table($table)->orderBy('id')->chunk(2000, function ($records) use ($handle, &$rows): void {
                foreach ($records as $record) {
                    $row = [];

                    foreach (array_keys((array) $record) as $column) {
                        $row[] = $this->stringify($record->{$column});
                    }

                    fputcsv($handle, $row);
                    $rows++;
                }
            });

            fclose($handle);

            return [
                'table' => $table,
                'file' => $file,
                'rows' => $rows,
                'columns' => $columns,
                'status' => 'ok',
            ];
        } catch (Throwable $e) {
            $this->components->error("{$table}: ".$e->getMessage());

            return [
                'table' => $table,
                'file' => $file,
                'rows' => 0,
                'columns' => [],
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Export `credit_card_user_cards` kèm thông tin ngân hàng đã resolve.
     *
     * Giữ NGUYÊN toàn bộ cột gốc của bảng, chỉ THÊM cột ngân hàng. Nhờ vậy file
     * vẫn phục hồi được nếu bảng banks còn nguyên, mà vẫn đọc hiểu được nếu bảng
     * banks đã đổi.
     *
     * @return array<string, mixed>
     */
    private function exportUserCards(string $directory, string $stamp): array
    {
        $table = 'credit_card_user_cards';
        $file = $this->fileName($table, $stamp);
        $path = $directory.'/'.$file;

        try {
            $connection = DB::connection(self::CONNECTION);
            $baseColumns = Schema::connection(self::CONNECTION)->getColumnListing($table);

            $bankColumns = [
                'bank_slug' => 'bank.slug',
                'bank_code_canonical' => 'bank.short_name',
                'bank_aliases' => 'bank.aliases',
                'bank_name' => 'bank.name',
                'bank_is_active' => 'bank.is_active',
            ];

            // `bcb` = bảng banks, alias để không đụng tên cột `bank_id`.
            $select = array_map(
                static fn (string $column): string => "uc.{$column}",
                $baseColumns
            );

            foreach ($bankColumns as $alias => $expression) {
                $select[] = "{$expression} as {$alias}";
            }

            $rows = 0;
            $handle = fopen($path, 'w');

            if ($handle === false) {
                throw new \RuntimeException("Không mở được file {$path} để ghi.");
            }

            fputcsv($handle, [...$baseColumns, ...array_keys($bankColumns)]);

            $connection->table($table.' as uc')
                ->leftJoin('credit_card_banks as bank', 'bank.id', '=', 'uc.bank_id')
                ->select($select)
                ->orderBy('uc.id')
                ->chunk(2000, function ($records) use ($handle, $baseColumns, $bankColumns, &$rows): void {
                    foreach ($records as $record) {
                        $row = [];

                        foreach ($baseColumns as $column) {
                            $row[] = $this->stringify($record->{$column});
                        }

                        foreach (array_keys($bankColumns) as $alias) {
                            $row[] = $this->stringify($record->{$alias});
                        }

                        fputcsv($handle, $row);
                        $rows++;
                    }
                });

            fclose($handle);

            return [
                'table' => $table,
                'file' => $file,
                'rows' => $rows,
                'columns' => [...$baseColumns, ...array_keys($bankColumns)],
                'joined_with' => 'credit_card_banks (left join, để phục hồi theo mã ngân hàng thay vì theo id)',
                'status' => 'ok',
            ];
        } catch (Throwable $e) {
            $this->components->error("{$table}: ".$e->getMessage());

            return [
                'table' => $table,
                'file' => $file,
                'rows' => 0,
                'columns' => [],
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Đọc lại file vừa ghi, đếm dòng và so với DB.
     *
     * Cần bước này vì một file CSV hỏng vẫn tồn tại trên đĩa và trông như
     * backup hợp lệ cho tới khi cần dùng — lúc đó mới phát hiện.
     *
     * @param  list<array<string, mixed>>  $expected
     * @return array<string, mixed>
     */
    private function verify(string $directory, string $stamp, array $expected): array
    {
        $this->components->info('Kiểm tra lại file vừa ghi…');

        $results = [];
        $ok = true;

        foreach ($expected as $entry) {
            $path = $directory.'/'.$entry['file'];

            if (! is_file($path)) {
                $results[] = ['file' => $entry['file'], 'ok' => false, 'reason' => 'File không tồn tại.'];
                $ok = false;

                continue;
            }

            $handle = fopen($path, 'r');

            if ($handle === false) {
                $results[] = ['file' => $entry['file'], 'ok' => false, 'reason' => 'Không mở lại được file.'];
                $ok = false;

                continue;
            }

            $header = fgetcsv($handle);
            $dataRows = 0;
            $malformed = 0;
            $nonUtf8 = 0;
            $expectedColumns = is_array($entry['columns'] ?? null) ? count($entry['columns']) : 0;

            while (($row = fgetcsv($handle)) !== false) {
                // Dòng rỗng toàn bộ (dấu xuống dòng cuối) không tính là dữ liệu.
                if ($row === [null] || $row === false) {
                    continue;
                }

                $dataRows++;

                if ($expectedColumns > 0 && count($row) !== $expectedColumns) {
                    $malformed++;
                }

                // Tên ngân hàng / danh mục có dấu. File hỏng encoding vẫn ĐỦ số dòng
                // nên kiểm số dòng không bắt được; phải kiểm charset riêng.
                if (! mb_check_encoding(implode(',', array_map('strval', $row)), 'UTF-8')) {
                    $nonUtf8++;
                }
            }

            fclose($handle);

            $dbRows = DB::connection(self::CONNECTION)->table($entry['table'])->count();

            $entryOk = $dataRows === (int) $entry['rows']
                && $dataRows === $dbRows
                && $malformed === 0
                && $nonUtf8 === 0;
            $ok = $ok && $entryOk;

            $results[] = [
                'file' => $entry['file'],
                'table' => $entry['table'],
                'db_rows' => $dbRows,
                'exported_rows' => (int) $entry['rows'],
                'readback_rows' => $dataRows,
                'columns_in_header' => is_array($header) ? count($header) : 0,
                'malformed_rows' => $malformed,
                'non_utf8_rows' => $nonUtf8,
                'ok' => $entryOk,
            ];

            $this->components->twoColumnDetail(
                $entry['file'],
                sprintf(
                    'DB %d / ghi %d / đọc lại %d %s',
                    $dbRows,
                    (int) $entry['rows'],
                    $dataRows,
                    $entryOk ? '✓' : '✗'
                )
            );
        }

        return ['ok' => $ok, 'results' => $results];
    }

    /**
     * Bảng `credit_card_*` có trong DB nhưng không nằm trong danh sách export.
     *
     * @return list<string>
     */
    private function findExtraTables(): array
    {
        return collect(DB::connection(self::CONNECTION)->select('SHOW TABLES'))
            ->map(fn ($row): string => (string) (array_values((array) $row)[0] ?? ''))
            ->filter(fn (string $name): bool => str_starts_with($name, 'credit_card_')
                && $name !== 'migrations'
                && ! in_array($name, self::TABLES, true))
            ->values()
            ->all();
    }

    /**
     * Giữ lại N bản gần nhất cho mỗi bảng.
     */
    private function prune(string $directory, int $keep): void
    {
        $pattern = $directory.'/credit_card_*_*.csv';
        $files = File::glob($pattern);

        // Nhóm theo tên bảng: credit_card_user_cards_2026-..._....csv
        $grouped = [];

        foreach ($files as $file) {
            $name = basename($file);
            $parts = explode('_', $name);

            // Bỏ 2 mảng cuối (ngày + giờ) để còn lại tên bảng.
            array_splice($parts, -2);
            $grouped[implode('_', $parts)][] = $file;
        }

        foreach ($grouped as $group) {
            rsort($group);

            foreach (array_slice($group, $keep) as $stale) {
                File::delete($stale);
                $this->components->twoColumnDetail('Xoá bản cũ', basename($stale));
            }
        }
    }

    private function fileName(string $table, string $stamp): string
    {
        return "{$table}_{$stamp}.csv";
    }

    /**
     * Chuẩn hoá giá trị ra CSV.
     *
     * NULL ghi thành chuỗi rỗng (chuẩn CSV), bool thành 1/0, mảng thành JSON.
     * Không gọi `date()` ở đây: giá trị đã ra khỏi DB dưới dạng chuỗi sẵn.
     */
    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function renderManifest(array $manifest, string $directory): string
    {
        $lines = [];
        $lines[] = '================================================================';
        $lines[] = '  MODULE THẺ TÍN DỤNG — BACKUP CSV';
        $lines[] = '================================================================';
        $lines[] = 'Thời gian backup : '.$manifest['created_at'].' ('.$manifest['timezone'].')';
        $lines[] = 'Database         : '.$manifest['database'];
        $lines[] = 'Connection       : '.$manifest['connection'];
        $lines[] = 'Laravel          : '.$manifest['laravel_version'];
        $lines[] = 'PHP              : '.$manifest['php_version'];
        $lines[] = 'Chế độ           : '.$manifest['mode'].' (không insert/update/delete, không chạy migration)';
        $lines[] = 'Thư mục          : '.$directory;
        $lines[] = '';
        $lines[] = '----------------------------------------------------------------';
        $lines[] = '  CÁC FILE CSV';
        $lines[] = '----------------------------------------------------------------';

        foreach ($manifest['tables'] as $entry) {
            $lines[] = sprintf('%-40s %6d dòng  [%s]  %s', $entry['table'], $entry['rows'], $entry['status'], $entry['file']);

            if (isset($entry['joined_with'])) {
                $lines[] = str_repeat(' ', 42).'^ '.$entry['joined_with'];
            }

            if (isset($entry['error'])) {
                $lines[] = str_repeat(' ', 42).'^ LỖI: '.$entry['error'];
            }
        }

        $lines[] = '';
        $lines[] = '----------------------------------------------------------------';
        $lines[] = '  CỘT CỦA TỪNG FILE';
        $lines[] = '----------------------------------------------------------------';

        foreach ($manifest['tables'] as $entry) {
            if (! is_array($entry['columns'] ?? null) || $entry['columns'] === []) {
                continue;
            }

            $lines[] = $entry['table'].':';
            $lines[] = '  '.implode(', ', $entry['columns']);
            $lines[] = '';
        }

        if (! empty($manifest['skipped'])) {
            $lines[] = '----------------------------------------------------------------';
            $lines[] = '  BẢNG BỎ QUA';
            $lines[] = '----------------------------------------------------------------';

            foreach ($manifest['skipped'] as $skip) {
                $lines[] = $skip['table'].' — '.$skip['reason'];
            }

            $lines[] = '';
        }

        if (! empty($manifest['not_exported'])) {
            $lines[] = '----------------------------------------------------------------';
            $lines[] = '  BẢNG CÓ TRONG DB NHƯNG KHÔNG EXPORT';
            $lines[] = '----------------------------------------------------------------';
            $lines[] = '  (migrate --fresh sẽ xoá các bảng này — export nếu cần giữ dữ liệu)';
            $lines[] = '  '.implode(', ', $manifest['not_exported']);
            $lines[] = '';
        }

        if (is_array($manifest['verify'])) {
            $lines[] = '----------------------------------------------------------------';
            $lines[] = '  KIỂM TRA ĐỌC LẠI';
            $lines[] = '----------------------------------------------------------------';
            $lines[] = 'Kết quả         : '.($manifest['verify']['ok'] ? 'ĐẠT — số dòng khớp DB' : 'KHÔNG ĐẠT');
            $lines[] = '';

            foreach ($manifest['verify']['results'] as $result) {
                if (isset($result['table'])) {
                    $lines[] = sprintf(
                        '  %-52s DB %-5d ghi %-5d đọc %-5d cột %-3d hỏng %-3d sai-encoding %-3d %s',
                        $result['file'],
                        $result['db_rows'],
                        $result['exported_rows'],
                        $result['readback_rows'],
                        $result['columns_in_header'],
                        $result['malformed_rows'],
                        $result['non_utf8_rows'],
                        $result['ok'] ? 'OK' : 'LỖI'
                    );
                } else {
                    $lines[] = '  '.$result['file'].' — '.$result['reason'];
                }
            }

            $lines[] = '';
        }

        $lines[] = '================================================================';
        $lines[] = '  CÁCH PHỤC HỒI (tham khảo)';
        $lines[] = '================================================================';
        $lines[] = 'Thứ tự nạp lại theo phụ thuộc khoá ngoại:';
        $lines[] = '  1. credit_card_banks';
        $lines[] = '  2. credit_card_categories';
        $lines[] = '  3. credit_card_policy_templates';
        $lines[] = '  4. credit_card_user_cards          (dùng bank_id; file đã kèm bank_slug/bank_name)';
        $lines[] = '  5. credit_card_policies';
        $lines[] = '  6. credit_card_policy_tiers';
        $lines[] = '  7. credit_card_policy_tier_categories';
        $lines[] = '  8. credit_card_statement_periods';
        $lines[] = '  9. credit_card_transactions';
        $lines[] = '';
        $lines[] = 'Bản ghi thẻ: ưu tiên map `bank_slug` → credit_card_banks.slug thay vì giữ';
        $lines[] = 'nguyên `bank_id`, vì id có thể khác giữa các lần seed.';
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }
}
