<?php

namespace App\Models;

use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    public const EXTENSION_SHORTLINK_ENABLED = 'extension_shortlink_enabled';

    /**
     * Link cào xu Shopee trong thông báo trên trang chủ:
     * - destination_url: URL ĐÍCH (href) — nơi trình duyệt mở khi người dùng
     *   nhấn vào liên kết. Admin thay đổi khi Shopee đổi link.
     * - display_url: URL HIỂN THỊ — chuỗi người dùng thấy trên giao diện.
     * Hai giá trị này KHÁC NHAU và được lưu độc lập.
     */
    public const SHOPEE_FASHION_ALERT_DESTINATION_URL = 'shopee_fashion_alert.destination_url';
    public const SHOPEE_FASHION_ALERT_DISPLAY_URL = 'shopee_fashion_alert.display_url';

    public const SHOPEE_FASHION_ALERT_DESTINATION_URL_DEFAULT = 'https://shp.ee/622xrhjpx85';
    public const SHOPEE_FASHION_ALERT_DISPLAY_URL_DEFAULT = 'https://s.shopee.vn/1BFBNN81J';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function extensionShortlinkEnabled(): bool
    {
        return static::get(self::EXTENSION_SHORTLINK_ENABLED, 'false') === 'true';
    }

    public static function shopeeFashionAlertDestinationUrl(): string
    {
        return (string) static::get(self::SHOPEE_FASHION_ALERT_DESTINATION_URL, self::SHOPEE_FASHION_ALERT_DESTINATION_URL_DEFAULT);
    }

    public static function shopeeFashionAlertDisplayUrl(): string
    {
        return (string) static::get(self::SHOPEE_FASHION_ALERT_DISPLAY_URL, self::SHOPEE_FASHION_ALERT_DISPLAY_URL_DEFAULT);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::cache()->rememberForever("setting.{$key}", function () use ($key, $default) {
            return static::where('key', $key)->value('value') ?? $default;
        });
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        static::cache()->forget("setting.{$key}");
    }

    protected static function cache(): Repository
    {
        return Cache::store();
    }
}
