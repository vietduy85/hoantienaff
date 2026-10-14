<?php

/*
|--------------------------------------------------------------------------
| Thuộc tính MÔ PHỎNG "thanh toán trực tuyến" (module Tối ưu bộ thẻ)
|--------------------------------------------------------------------------
|
| Thuần MÔ PHỎNG — KHÔNG thêm cột DB, KHÔNG đổi engine hoàn tiền.
|
| Ý tưởng: một khoản chi ở danh mục gốc (vd "Thời trang") hoàn toàn có thể được
| thanh toán TRỰC TUYẾN nên có thể hưởng ưu đãi "giao dịch trực tuyến" của thẻ.
| Khi đó, số tiền người dùng nhập được CHIA giữa một dòng danh mục GỐC và một
| dòng danh mục ONLINE (tổng vẫn bằng số tiền đã nhập — KHÔNG nhân ngân sách);
| bộ tối ưu thử một vài tỷ lệ chia và giữ phương án hoàn tiền cao nhất.
|
| Quy tắc:
|   - `verified`: chỉ khai báo cặp đã XÁC MINH (base slug => target slug).
|   - `unverified`: cặp là GIẢ ĐỊNH mô phỏng, chỉ dùng khi `use_unverified = true`.
|     Mặc định BẬT `use_unverified = false` ⇒ không có tác động production cho tới
|     khi có bằng chứng.
|   - `target` nên là danh mục hệ thống đại diện "trực tuyến" (vd
|     `mua-sam-truc-tuyen`) và phải là thành viên của các combo online liên quan
|     (`vib-online`, `tpb-evo-online`) để dòng online khớp đúng rule.
|   - `base_fractions`: tỷ lệ chia sang dòng ONLINE (0.0 = toàn bộ sang online).
|   - Nếu một danh mục chưa có bằng chứng, để riêng và cập nhật sau — KHÔNG suy diễn.
*/

return [
    'enabled' => true,

    'use_unverified' => false,

    'verified' => [],

    'unverified' => [
        'thoi-trang' => 'mua-sam-truc-tuyen',
        'dien-may' => 'mua-sam-truc-tuyen',
        'nha-sach' => 'mua-sam-truc-tuyen',
        'cham-soc-sac-dep' => 'mua-sam-truc-tuyen',
    ],

    'base_fractions' => [0.0, 0.25, 0.5, 0.75],
];
