<?php

namespace App\Support\CreditCard;

use App\Models\CreditCard\Report;

/**
 * Sắp xếp tăng/giảm của báo cáo chi tiêu (PHẦN C) — thực thi phía server.
 *
 * ---------------------------------------------------------------------------
 * CHỈ SẮP XẾP, KHÔNG TÍNH LẠI SỐ
 * ---------------------------------------------------------------------------
 * Ba tiêu chí `spend` / `cashback` / `percent` được áp lên chính mảng `rows` do
 * `CreditCardReportService` trả về (đã tính tổng bằng SQL + Decimal). Vì trang
 * kết quả và export Excel cùng qua nguồn dữ liệu này nên thứ tự hiển thị và thứ
 * tự trong file luôn khớp nhau.
 *
 * Quy tắc:
 *   - Key/chiều chỉ nhận giá trị trong allowlist; giá trị lạ bị bỏ qua ⇒ không
 *     tin tham số tùy ý từ client.
 *   - `usort` của PHP 8 ổn định: các dòng bằng giá trị giữ đúng thứ tự ban đầu
 *     của service (tie-break).
 *   - Tỷ lệ `null` (chi tiêu bằng 0, trên màn hình là "—") LUÔN nằm cuối, dù
 *     asc hay desc.
 *   - Chọn tiêu chí mới luôn mặc định chiều `asc`.
 *   - Dòng tổng không nằm trong `rows` nên không bao giờ bị kéo vào sắp xếp.
 */
class CreditCardSort
{
    public const KEYS = ['spend', 'cashback', 'percent'];

    public const ASC = 'asc';

    public const DESC = 'desc';

    /** Chỉ nhận 3 tiêu chí hợp lệ; giá trị khác trả `null` (giữ thứ tự gốc). */
    public static function normalizeSort(?string $sort): ?string
    {
        return in_array($sort, self::KEYS, true) ? $sort : null;
    }

    /** Mọi giá trị khác `desc` đều hiểu là `asc`. */
    public static function normalizeDirection(?string $dir): string
    {
        return $dir === self::DESC ? self::DESC : self::ASC;
    }

    /** Tên field chứa giá trị sort trên từng dòng báo cáo, theo chế độ. */
    public static function fieldFor(string $mode, string $sort): string
    {
        if ($mode === Report::TYPE_BY_CATEGORY) {
            return match ($sort) {
                'spend' => 'spend_total',
                'cashback' => 'cashback_total',
                'percent' => 'percent',
            };
        }

        return $sort;
    }

    /**
     * Sắp xếp mảng `rows` báo cáo và trả về mảng mới. Rows được sort trực tiếp
     * (đổi thứ tự từng dòng nguyên vẹn, không tách ô), tổng vẫn là tổng của mọi
     * dòng trước khi sort.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function applyRows(array $rows, string $mode, ?string $sort, string $dir = self::ASC): array
    {
        if ($sort === null || count($rows) < 2) {
            return $rows;
        }

        $field = self::fieldFor($mode, $sort);
        $desc = $dir === self::DESC;

        usort($rows, function (array $a, array $b) use ($field, $desc): int {
            $left = $a[$field] ?? null;
            $right = $b[$field] ?? null;

            // Tỷ lệ "—" (null) luôn về cuối; hai dòng đều null giữ thứ tự ban đầu.
            if ($left === null || $right === null) {
                if ($left === null && $right === null) {
                    return 0;
                }

                return $left === null ? 1 : -1;
            }

            // Giá trị là chuỗi `Decimal` chuẩn ("3000000.00", "7.14") — so theo số.
            $comparison = (float) $left <=> (float) $right;

            return $desc ? -$comparison : $comparison;
        });

        return $rows;
    }
}