<?php

namespace App\Support\CreditCard;

/**
 * Decimal — cộng/trừ số tiền của module Credit Card bằng CHUỖI.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO KHÔNG ĐI QUA `float`
 * ---------------------------------------------------------------------------
 * Tiền trong module nằm ở cột `decimal(16,2)` và chạy tới 99.999.999.999.999,99
 * đồng. `float` (IEEE-754 double) chỉ giữ chính xác ~15–16 chữ số có nghĩa, nên
 * cộng dồn nhiều khoản sẽ lệch ở số lớn — sai số tiền thật thì không chấp nhận
 * được. `bcmath` làm đúng trên chuỗi thập phân.
 *
 * ---------------------------------------------------------------------------
 * PHẠM VI
 * ---------------------------------------------------------------------------
 * Chỉ là tiện ích thuần cho các aggregate của module (Tổng quan, quota). KHÔNG
 * thay thế `CashbackCalculator` — engine cashback vẫn là nguồn duy nhất của mọi
 * con số hoàn tiền.
 */
final class Decimal
{
    /**
     * Mô-đun bcmath nạp một lần cho cả tiến trình.
     */
    private static bool $available;

    /**
     * Chuẩn hoá về chuỗi 2 chữ số thập phân.
     *
     * `float` chỉ xuất hiện ở input do sqlite trả về (MySQL trả chuỗi cho cột
     * `decimal`) và ở giá trị truyền vào; chuẩn hoá xong phép tính phía sau là
     * thuần `bcmath`.
     *
     * Giá trị NULL/rỗng ⇒ `0.00` để `SUM()` của cột toàn NULL không sinh ra
     * chuỗi rỗng ở Blade.
     */
    public static function money(mixed $value): string
    {
        self::ensureBcmath();

        if ($value === null || $value === '') {
            return '0.00';
        }

        if (is_float($value)) {
            $value = number_format($value, 2, '.', '');
        }

        return bcadd((string) $value, '0', 2);
    }

    /**
     * `$a + $b`.
     */
    public static function add(string $a, string $b): string
    {
        self::ensureBcmath();

        return bcadd(self::money($a), self::money($b), 2);
    }

    /**
     * `$a - $b`. Giữ dấu: hoàn tiền âm làm tổng kỳ giảm, không được kẹp về 0.
     */
    public static function subtract(string $a, string $b): string
    {
        self::ensureBcmath();

        return bcsub(self::money($a), self::money($b), 2);
    }

    /**
     * Số lớn hơn trong hai số.
     */
    public static function max(string $a, string $b): string
    {
        self::ensureBcmath();

        $left = self::money($a);
        $right = self::money($b);

        return bccomp($left, $right, 2) >= 0 ? $left : $right;
    }

    /**
     * Ẩn số âm về 0 — chỉ dùng cho phần "còn lại", không dùng cho tổng chi tiêu.
     */
    public static function clampZero(string $value): string
    {
        self::ensureBcmath();

        return bccomp(self::money($value), '0', 2) < 0 ? '0.00' : self::money($value);
    }

    /**
     * Số này có lớn hơn 0 không.
     */
    public static function isPositive(string $value): bool
    {
        self::ensureBcmath();

        return bccomp(self::money($value), '0', 2) > 0;
    }

    /**
     * Phần trăm `part / $total * 100`, làm tròn 2 chữ số thập phân.
     *
     * Mẫu số ≤ 0 ⇒ `0.00` (thẻ chưa đặt mục tiêu chi tiêu ⇒ không có tiến độ để
     * hiển thị, không phải lỗi). KHÔNG kẹp trên 100: người dùng chi vượt mục tiêu
     * là chuyện hợp lệ và giao diện tự vẽ vạch mục tiêu ở cuối thanh.
     *
     * Nhân trước 100 rồi mới chia để tỉ lệ giữ được chính xác hơn khi chia trực
     * tiếp hai số có phần thập phân dài.
     */
    public static function percent(string $part, string $total): string
    {
        self::ensureBcmath();

        $denominator = self::money($total);

        if (bccomp($denominator, '0', 2) <= 0) {
            return '0.00';
        }

        return bcdiv(bcmul(self::money($part), '100', 4), $denominator, 2);
    }

    /**
     * `bcmath` là mô-đun chuẩn đi kèm PHP. Nếu thiếu thì báo lỗi ngay thay vì
     * rơi về `float` — im lặng làm lệch tiền thì tệ hơn là lỗi rõ ràng.
     */
    private static function ensureBcmath(): void
    {
        if (isset(self::$available)) {
            return;
        }

        foreach (['bcadd', 'bcsub', 'bcmul', 'bcdiv', 'bccomp'] as $function) {
            if (! function_exists($function)) {
                throw new \RuntimeException('Thiếu mô-đun bcmath — bắt buộc để tính tiền chính xác.');
            }
        }

        self::$available = true;
    }
}
