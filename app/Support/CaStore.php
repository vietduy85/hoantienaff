<?php

namespace App\Support;

/**
 * Kho CA dạng thư mục hash (CApath) cho mọi kết nối HTTPS ra ngoài.
 *
 * Trên Windows/XAMPP, nạp nguyên CA bundle (~120 chứng chỉ) ở MỖI lần bắt tay TLS tốn
 * ~320ms. Với CApath, OpenSSL chỉ đọc đúng CA gốc cần cho chứng chỉ của server
 * (theo hash tên), TLS còn ~40-70ms mà vẫn xác thực đầy đủ.
 *
 * libcurl luôn nạp thêm CURLOPT_CAINFO (mặc định từ php.ini = bundle đầy đủ), nên ta trỏ
 * CAINFO sang `anchor.pem` chỉ chứa 1 CA (cũng có trong bundle) để không nạp lại cả bundle.
 *
 * Dựng bằng: php artisan certs:build-capath (từ storage/app/certs/cacert.pem của curl.se).
 * Chưa dựng / thiếu file => trả về null và mọi thứ chạy với cấu hình mặc định như cũ.
 */
final class CaStore
{
    public static function dir(): string
    {
        return storage_path('app/certs/capath');
    }

    public static function anchor(): string
    {
        return storage_path('app/certs/anchor.pem');
    }

    public static function available(): bool
    {
        return is_dir(self::dir()) && is_file(self::anchor());
    }

    /**
     * Tuỳ chọn cURL thô (cho code dùng curl_init trực tiếp).
     *
     * @return array<int, string>
     */
    public static function curlOptions(): array
    {
        return self::available()
            ? [CURLOPT_CAPATH => self::dir(), CURLOPT_CAINFO => self::anchor()]
            : [];
    }

    /**
     * Tuỳ chọn Guzzle cho Http client của Laravel (verify = thư mục => CURLOPT_CAPATH).
     *
     * @return array<string, mixed>
     */
    public static function guzzleOptions(): array
    {
        return self::available()
            ? ['verify' => self::dir(), 'curl' => [CURLOPT_CAINFO => self::anchor()]]
            : [];
    }
}
