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
     * So sánh hai số: -1 nếu `$a` nhỏ hơn, 0 nếu bằng, 1 nếu lớn hơn.
     *
     * Hàm nền của {@see min()}/{@see max()}: so sánh PHẢI đi qua bcmath. So sánh
     * chuỗi bằng `<` của PHP ép về float, mà float chính là thứ `Decimal` sinh ra
     * để tránh.
     */
    public static function compare(string $a, string $b): int
    {
        self::ensureBcmath();

        return bccomp(self::money($a), self::money($b), 2);
    }

    /**
     * Số nhỏ hơn trong hai số.
     */
    public static function min(string $a, string $b): string
    {
        self::ensureBcmath();

        $left = self::money($a);
        $right = self::money($b);

        return bccomp($left, $right, 2) <= 0 ? $left : $right;
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
     * Hoàn tiền sinh ra từ một khoản chi: `$spend * $percent / 100`.
     *
     * Nhân trước ở scale 8 rồi mới chia 100 để không mất chữ số ở tỷ lệ có phần
     * thập phân (cột `cashback_percent` là `decimal(?,3)`).
     *
     * `$percent` ≤ 0 ⇒ `0.00`. Ở đây tỷ lệ 0 là DỮ LIỆU hợp lệ (rule 0%), và nó
     * khác hẳn {@see spendForCashback()} — nơi tỷ lệ 0 là lỗi vì không chia được.
     */
    public static function cashbackForSpend(string $spend, string $percent): string
    {
        self::ensureBcmath();

        if (bccomp(self::money($percent), '0', 6) <= 0) {
            return '0.00';
        }

        return bcdiv(bcmul(self::money($spend), self::money($percent), 8), '100', 2);
    }

    /**
     * Chi bao nhiêu thì sinh ra đúng `$cashback` hoàn tiền ở tỷ lệ `$percent`.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO LÀM TRÒN LÊN
     * ---------------------------------------------------------------------------
     * Đây là con số "cần chi thêm bao nhiêu". LÀM TRÒN XUỐNG sẽ báo một khoản nhỏ
     * hơn số tiền thật cần chi ⇒ user chi theo con số đó thì vẫn thiếu, và quota
     * không đạt max. Thiếu vài xu ở một ƯỚC LƯỢNG vô hại; hứa hẹn rồi không đạt
     * thì mất niềm tin vào cả con số. Nên luôn làm tròn LÊN.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO KHÔNG DÙNG `bcadd` ĐỂ LÀM TRÒN LÊN
     * ---------------------------------------------------------------------------
     * `bcadd()` BỎ CẦN PHẦN THẬP PHÂN, không làm tròn: `bcadd('3333.33333333',
     * '0.00000001', 2)` = `3333.33`. Nên phải cắt phần nguyên trước, rồi dò phần
     * thập phân còn lại và cộng thêm MỘT đơn vị nhỏ nhất ở `scale`:
     *
     *   100 / 3% = 3333,3333333333 → cắt còn 3333,33 → còn dư ⇒ +0,01 = 3333,34.
     *   1.000.000 / 5% = 20.000.000  → cắt còn 20.000.000,00 → không dư ⇒ giữ nguyên.
     *
     * `$percent` ≤ 0 ⇒ `InvalidArgumentException`. Ở đây tỷ lệ 0 là LỖI, vì "chi bao
     * nhiêu thì sinh ra hoàn tiền" với tỷ lệ 0 không có đáp án — trả `0.00` sẽ báo
     * cho user rằng chi thêm 0 đồng là đủ, tức là nói dối. Khác hẳn
     * {@see cashbackForSpend()}, nơi tỷ lệ 0 chỉ đơn giản là không sinh hoàn tiền.
     */
    public static function spendForCashback(string $cashback, string $percent): string
    {
        self::ensureBcmath();

        if (bccomp(self::money($percent), '0', 6) <= 0) {
            throw new \InvalidArgumentException('Không quy đổi được với tỷ lệ hoàn tiền bằng 0.');
        }

        $scale = 2;
        $work = bcdiv(bcmul(self::money($cashback), '100', 8), self::money($percent), 10);
        $truncated = bcdiv($work, '1', $scale);

        if (bccomp($work, $truncated, 10) > 0) {
            $truncated = bcadd($truncated, '0.01', $scale);
        }

        return $truncated;
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
