<?php

namespace App\Console\Commands;

use App\Support\CaStore;
use Illuminate\Console\Command;

/**
 * Dựng thư mục CApath (<subject-hash>.<n>) + anchor.pem từ CA bundle của curl.se.
 * Xem App\Support\CaStore. Chạy lại mỗi khi cập nhật storage/app/certs/cacert.pem.
 */
class BuildCaPath extends Command
{
    protected $signature = 'certs:build-capath {--bundle= : Đường dẫn CA bundle (mặc định storage/app/certs/cacert.pem)}';

    protected $description = 'Build hashed CA directory used by all outgoing HTTPS calls (fast TLS on Windows)';

    public function handle(): int
    {
        $bundle = $this->option('bundle') ?: storage_path('app/certs/cacert.pem');

        if (! is_file($bundle)) {
            $this->error("Không thấy CA bundle: {$bundle}");

            return self::FAILURE;
        }

        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', (string) file_get_contents($bundle), $m);

        if (count($m[0]) < 50) {
            $this->error('CA bundle có quá ít chứng chỉ ('.count($m[0]).'), bỏ qua.');

            return self::FAILURE;
        }

        // Dựng vào thư mục tạm rồi đổi tên: không lúc nào thư mục đang dùng bị rỗng / dở dang.
        $dir = CaStore::dir();
        $tmp = $dir.'.tmp';
        $this->wipe($tmp);
        mkdir($tmp, 0755, true);

        $count = [];
        $written = 0;
        foreach ($m[0] as $pem) {
            $info = openssl_x509_parse($pem);
            if ($info === false || empty($info['hash'])) {
                continue;
            }
            $n = $count[$info['hash']] = ($count[$info['hash']] ?? -1) + 1;
            file_put_contents("{$tmp}/{$info['hash']}.{$n}", $pem."\n");
            $written++;
        }

        file_put_contents(CaStore::anchor(), $m[0][0]."\n");

        $old = $dir.'.old';
        $this->wipe($old);
        if (is_dir($dir)) {
            rename($dir, $old);
        }
        rename($tmp, $dir);
        $this->wipe($old);

        $this->info("CApath: {$written} chứng chỉ -> {$dir}");

        return self::SUCCESS;
    }

    private function wipe(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
