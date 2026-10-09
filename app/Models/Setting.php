<?php

namespace App\Models;

use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    public const EXTENSION_SHORTLINK_ENABLED = 'extension_shortlink_enabled';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function extensionShortlinkEnabled(): bool
    {
        return static::get(self::EXTENSION_SHORTLINK_ENABLED, 'false') === 'true';
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
