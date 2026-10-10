<?php

namespace App\Support\CreditCard;

/**
 * CreditCardPercentFormatter — nguồn DUY NHẤT định dạng TỶ LỆ PHẦN TRĂM của
 * module Thẻ tín dụng.
 *
 * ---------------------------------------------------------------------------
 * TÁCH KHỎI FORMATTER TIỀN
 * ---------------------------------------------------------------------------
 * Tỷ lệ không phải tiền: nó KHÔNG kèm hậu tố `đ`/`nghìn` và KHÔNG phụ thuộc
 * `money_unit` của user. Nếu dùng chung `credit-card.money` thì tỷ lệ sẽ đội
 * luôn hậu tố tiền và sai đơn vị hiển thị. Vì vậy có formatter riêng.
 *
 * ---------------------------------------------------------------------------
 * QUY TẮC
 * ---------------------------------------------------------------------------
 *   - `null` / `''` ⇒ `—` (không có mẫu số để chia, ví dụ chi tiêu bằng 0).
 *   - Ngược lại: chuẩn hoá 2 chữ số thập phân, dấu thập phân `,` theo vi-VN,
 *     rồi gắn `%`: `7.14` ⇒ `7,14%`, `0.00` ⇒ `0,00%`.
 *
 * Số đầu vào là chuỗi Decimal do service sinh (đã tính trên VND thô), không đi
 * qua `float` để tránh sai số hiển thị.
 */
final class CreditCardPercentFormatter
{
    /** Ký tự hiển thị khi không có tỷ lệ (chi tiêu bằng 0). */
    public const EMPTY = '—';

    /**
     * @param  mixed  $value  Tỷ lệ dạng chuỗi Decimal (ví dụ `'7.14'`) hoặc null.
     */
    public static function text(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::EMPTY;
        }

        return str_replace('.', ',', Decimal::money($value)).'%';
    }
}
