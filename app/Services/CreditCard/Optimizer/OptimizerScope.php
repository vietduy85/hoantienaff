<?php

namespace App\Services\CreditCard\Optimizer;

/**
 * OptimizerScope — phạm vi thẻ được đưa vào "Tối ưu bộ thẻ hoàn tiền".
 *
 * Mặc định `compare` (thẻ của tôi + thẻ hệ thống) vì dữ liệu live cho thấy
 * mọi thẻ user đều có policy được resolve — đúng quy tắc đã chốt.
 */
final class OptimizerScope
{
    public const OWNED = 'owned';

    public const ALL = 'all';

    public const COMPARE = 'compare';

    /**
     * @return array<string, string> key => nhãn tiếng Việt
     */
    public static function labels(): array
    {
        return [
            self::OWNED => 'Chỉ thẻ của tôi',
            self::ALL => 'Tất cả thẻ hệ thống (thẻ mới)',
            self::COMPARE => 'So sánh: thẻ của tôi + thẻ hệ thống',
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::labels());
    }

    public static function label(string $key): string
    {
        return self::labels()[$key] ?? self::labels()[self::COMPARE];
    }
}