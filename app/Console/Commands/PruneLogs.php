<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Chỉ giữ log N ngày gần nhất trong storage/logs (mặc định 7).
 *
 *  - File theo ngày (xxx-YYYY-MM-DD.log): xóa nếu ngày trong tên file cũ hơn mốc.
 *  - laravel.log kiểu "single" (một file dồn mọi ngày): cắt bỏ các entry cũ hơn mốc,
 *    ghi streaming sang file tạm rồi thay thế nên không nạp cả file vào bộ nhớ.
 *  - File khác (.log/.backup không có ngày): xóa nếu lần sửa cuối cũ hơn mốc.
 */
class PruneLogs extends Command
{
    protected $signature = 'logs:prune {--days=7 : Số ngày log giữ lại} {--dry-run : Chỉ báo cáo, không ghi/xóa}';

    protected $description = 'Delete or trim storage/logs entries older than N days (default 7)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry-run');
        $cutoff = CarbonImmutable::now(config('app.timezone'))->subDays($days)->startOfDay();
        $dir = storage_path('logs');

        $deleted = 0;
        $trimmed = 0;
        $freed = 0;

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            $name = basename($path);
            if (! is_file($path) || $name === '.gitignore' || ! preg_match('/\.(log|backup)$/', $name)) {
                continue;
            }

            $size = (int) filesize($path);

            if (preg_match('/(\d{4}-\d{2}-\d{2})\.log$/', $name, $m)) {
                if ($m[1] < $cutoff->toDateString()) {
                    $dry || @unlink($path);
                    $deleted++;
                    $freed += $size;
                    $this->line("delete {$name}");
                }

                continue;
            }

            if ($name === 'laravel.log') {
                $newSize = $this->trimSingleLog($path, $cutoff, $dry);
                if ($newSize !== null && $newSize < $size) {
                    $trimmed++;
                    $freed += $size - $newSize;
                    $this->line("trim {$name}: ".$this->mb($size).' -> '.$this->mb($newSize));
                }

                continue;
            }

            if (filemtime($path) < $cutoff->getTimestamp()) {
                $dry || @unlink($path);
                $deleted++;
                $freed += $size;
                $this->line("delete {$name}");
            }
        }

        $this->info(($dry ? '[dry-run] ' : '')."deleted {$deleted}, trimmed {$trimmed}, freed ".$this->mb($freed));

        return self::SUCCESS;
    }

    /** @return int|null kích thước sau khi cắt (hoặc sẽ cắt khi dry-run), null nếu không xử lý được */
    private function trimSingleLog(string $path, CarbonImmutable $cutoff, bool $dry): ?int
    {
        $in = @fopen($path, 'rb');
        if ($in === false) {
            return null;
        }

        $tmp = $path.'.pruning';
        $out = $dry ? null : @fopen($tmp, 'wb');
        if (! $dry && $out === false) {
            fclose($in);

            return null;
        }

        $cut = $cutoff->format('Y-m-d H:i:s');
        $keep = false;
        $kept = 0;

        while (($line = fgets($in)) !== false) {
            // Dòng mở đầu entry: [YYYY-MM-DD HH:MM:SS]; các dòng khác (stack trace) theo entry trước.
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
                $keep = $m[1] >= $cut;
            }
            if ($keep) {
                $kept += strlen($line);
                if ($out) {
                    fwrite($out, $line);
                }
            }
        }

        fclose($in);

        if ($out) {
            fclose($out);
            if (! @rename($tmp, $path)) {
                // Windows: file đang bị process khác giữ — ghi đè nội dung tại chỗ.
                if (@copy($tmp, $path)) {
                    @unlink($tmp);
                } else {
                    @unlink($tmp);
                    $this->warn('Không thay thế được laravel.log (đang bị khóa), bỏ qua.');

                    return null;
                }
            }
        }

        return $kept;
    }

    private function mb(int $bytes): string
    {
        return number_format($bytes / 1048576, 1).' MB';
    }
}
