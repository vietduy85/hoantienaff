<?php

/**
 * DỮ LIỆU HOÀN TIỀN THEO DÒNG MCC — trích từ TÀI LIỆU NGÂN HÀNG GỐC.
 *
 * Khoá cấp 1: slug của `credit_card_policy_templates`.
 * Khoá cấp 2 (`rows`): `category_name` của dòng `included` trong hồ sơ MCC tương ứng.
 * Mỗi dòng là danh sách các BẬC hoàn tiền (tier), theo đúng thứ tự trong tài liệu:
 *   - pct        : tỷ lệ hoàn tiền dạng số (10.000 = 10%); null nếu tài liệu chỉ ghi nhãn.
 *   - label      : nhãn tỷ lệ nguyên văn (vd "tới 20%") khi không có số cụ thể.
 *   - min / max  : ngưỡng tổng chi tiêu (VND) của bậc; null nếu tài liệu không nêu.
 *   - cap        : trần hoàn tiền của bậc.
 *   - cap_period : kỳ áp dụng trần (vd "kỳ sao kê", "kỳ TBGD").
 *   - cap_unit   : đơn vị trần ("VND" hoặc "điểm").
 *   - cond       : điều kiện kèm theo của bậc (nguyên văn/rút gọn).
 *   - exc        : ngoại lệ đặc thù của dòng/bậc.
 *
 * `source_doc` : mảnh tên file tài liệu gốc đính kèm hồ sơ (để tra `source_document_id`).
 * `source_page`: trang/mục trích dẫn áp cho toàn bộ dòng nếu tier không tự nêu.
 * `product_name`: TÊN SẢN PHẨM ĐẠI DIỆN khi chương trình dùng CHUNG nhiều hạng thẻ
 *                 (VIB Max Card Visa / VIB One Card Visa). Trình nhập ghi vào
 *                 `credit_card_policy_mcc_profiles.product_name` để "Tra cứu MCC"
 *                 gộp các hạng về một kết quả. Bỏ trống = giữ hành vi cũ.
 *
 * NGUYÊN TẮC: KHÔNG suy diễn. Chỉ ghi tỷ lệ/điều kiện có căn cứ trong tài liệu.
 * Trình nhập: `php artisan credit-card:import-mcc-row-benefits` (idempotent).
 */
return [

    // =====================================================================
    // #1 — LPBank JCB Ultimate "Gói Chăm sóc toàn diện"
    // =====================================================================
    'lpbank-ultimate-jcb' => [
        'source_doc' => 'Điều khoản và thể lệ chương trình hoàn tiền theo tính năng TTD JCB Ultimate',
        'source_page' => '1',
        'rows' => [
            'Y tế' => [
                [
                    'pct' => 15,
                    'min' => 8000000,
                    'cap' => 800000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Tổng chi tiêu trong kỳ sao kê đạt từ 8.000.000đ trở lên',
                ],
            ],
            'Giáo dục' => [
                [
                    'pct' => 15,
                    'min' => 8000000,
                    'cap' => 800000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Tổng chi tiêu trong kỳ sao kê đạt từ 8.000.000đ trở lên',
                ],
            ],
            'Bảo hiểm' => [
                [
                    'pct' => 15,
                    'min' => 8000000,
                    'cap' => 800000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Tổng chi tiêu trong kỳ sao kê đạt từ 8.000.000đ trở lên',
                    'exc' => 'Riêng lĩnh vực Bảo hiểm tối đa 400.000đ/kỳ sao kê (trong tổng hạn mức 800.000đ)',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #2 — VIB One Card (Gold/Platinum/Signature), mục 2.12
    // =====================================================================
    'vib-one-card-visa-gold' => [
        'source_doc' => 'Chuong_trinh_diem_thuong_hoan_tien_the_tin_dung_VIB',
        'source_page' => '2.12',
        'product_name' => 'VIB One Card Visa',
        'rows' => [
            'Mua sắm' => [
                [
                    'pct' => 9,
                    'cap' => 1500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Hạng Signature — chi tiêu thuộc Danh mục Chi Tiêu đã đăng ký (tối đa 03 danh mục)',
                ],
                [
                    'pct' => 6,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Hạng Platinum — chi tiêu thuộc Danh mục Chi Tiêu đã đăng ký (tối đa 02 danh mục)',
                ],
                [
                    'pct' => 3,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Hạng Gold — chi tiêu thuộc Danh mục Chi Tiêu đã đăng ký (01 danh mục)',
                ],
                [
                    'pct' => 0.1,
                    'cond' => 'Giao dịch không thuộc Danh mục Chi Tiêu đã đăng ký',
                ],
            ],
            'Du lịch' => [
                [
                    'pct' => 9,
                    'cap' => 1500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Hạng Signature — chi tiêu thuộc Danh mục Chi Tiêu đã đăng ký (tối đa 03 danh mục)',
                ],
                [
                    'pct' => 6,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Hạng Platinum — chi tiêu thuộc Danh mục Chi Tiêu đã đăng ký (tối đa 02 danh mục)',
                ],
                [
                    'pct' => 3,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Hạng Gold — chi tiêu thuộc Danh mục Chi Tiêu đã đăng ký (01 danh mục)',
                ],
                [
                    'pct' => 0.1,
                    'cond' => 'Giao dịch không thuộc Danh mục Chi Tiêu đã đăng ký',
                ],
            ],
            'Giáo dục' => [
                [
                    'pct' => 9,
                    'cap' => 1500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Hạng Signature — chi tiêu thuộc Danh mục Chi Tiêu đã đăng ký (tối đa 03 danh mục)',
                ],
                [
                    'pct' => 6,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Hạng Platinum — chi tiêu thuộc Danh mục Chi Tiêu đã đăng ký (tối đa 02 danh mục)',
                ],
                [
                    'pct' => 3,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Hạng Gold — chi tiêu thuộc Danh mục Chi Tiêu đã đăng ký (01 danh mục)',
                ],
                [
                    'pct' => 0.1,
                    'cond' => 'Giao dịch không thuộc Danh mục Chi Tiêu đã đăng ký',
                ],
            ],
            'Bảo hiểm' => [
                [
                    'pct' => 9,
                    'cap' => 1500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Hạng Signature — chi tiêu thuộc Danh mục Chi Tiêu đã đăng ký (tối đa 03 danh mục)',
                ],
                [
                    'pct' => 6,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Hạng Platinum — chi tiêu thuộc Danh mục Chi Tiêu đã đăng ký (tối đa 02 danh mục)',
                ],
                [
                    'pct' => 3,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Hạng Gold — chi tiêu thuộc Danh mục Chi Tiêu đã đăng ký (01 danh mục)',
                ],
                [
                    'pct' => 0.1,
                    'cond' => 'Giao dịch không thuộc Danh mục Chi Tiêu đã đăng ký',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #3 — Sacombank Visa Uniq Platinum
    // =====================================================================
    'sacombank-uniq-visa' => [
        'source_doc' => 'sacombank_uniq_visa',
        'source_page' => '1',
        'rows' => [
            'Siêu thị, cửa hàng tiện lợi' => [
                [
                    'pct' => 50,
                    'cap' => 300000,
                    'cap_period' => 'kỳ TBGD',
                    'cap_unit' => 'VND',
                    'cond' => 'Giao dịch đầu tiên của lĩnh vực trong vòng 30 ngày kể từ ngày kích hoạt thẻ',
                ],
                [
                    'pct' => 20,
                    'cap' => 300000,
                    'cap_period' => 'kỳ TBGD',
                    'cap_unit' => 'VND',
                    'cond' => 'Các giao dịch còn lại của lĩnh vực',
                ],
            ],
            'Ứng dụng di chuyển' => [
                [
                    'pct' => 50,
                    'cap' => 300000,
                    'cap_period' => 'kỳ TBGD',
                    'cap_unit' => 'VND',
                    'cond' => 'Giao dịch đầu tiên của lĩnh vực trong vòng 30 ngày kể từ ngày kích hoạt thẻ',
                ],
                [
                    'pct' => 20,
                    'cap' => 300000,
                    'cap_period' => 'kỳ TBGD',
                    'cap_unit' => 'VND',
                    'cond' => 'Các giao dịch còn lại của lĩnh vực',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #4 — MB JCB Ultimate
    // =====================================================================
    'mb-ultimate-jcb' => [
        'source_doc' => 'mb_ultimate_jcb',
        'source_page' => '1',
        'rows' => [
            'Y tế' => [
                [
                    'pct' => 10,
                    'cap' => 800000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Chi tiêu tại các lĩnh vực Bảo hiểm, Y tế, Giáo dục',
                ],
            ],
            'Giáo dục' => [
                [
                    'pct' => 10,
                    'cap' => 800000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Chi tiêu tại các lĩnh vực Bảo hiểm, Y tế, Giáo dục',
                ],
            ],
            'Bảo hiểm' => [
                [
                    'pct' => 10,
                    'cap' => 800000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Chi tiêu tại các lĩnh vực Bảo hiểm, Y tế, Giáo dục',
                    'exc' => 'Riêng lĩnh vực Bảo hiểm tối đa 400.000đ/kỳ sao kê (trong tổng hạn mức 800.000đ)',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #5 — VPBank StepUp Mastercard
    // =====================================================================
    'vpbank-stepup-mastercars' => [
        'source_doc' => 'vpbank_stepup',
        'source_page' => '3',
        'rows' => [
            'Chi tiêu online' => [
                [
                    'pct' => 15,
                    'min' => 100000000,
                    'cap' => 1500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Nhóm 1: tổng doanh số giao dịch từ 100 triệu đồng/kỳ sao kê trở lên',
                ],
                [
                    'pct' => 15,
                    'min' => 10000000,
                    'max' => 100000000,
                    'cap' => 700000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Nhóm 2: tổng doanh số từ 10 triệu đến dưới 100 triệu đồng/kỳ sao kê',
                ],
                [
                    'pct' => 6,
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Nhóm 3: tổng doanh số dưới 10 triệu đồng/kỳ sao kê',
                ],
            ],
            'Ăn uống' => [
                [
                    'pct' => 4,
                    'min' => 100000000,
                    'cap' => 200000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Nhóm 1: tổng doanh số giao dịch từ 100 triệu đồng/kỳ sao kê trở lên',
                ],
                [
                    'pct' => 4,
                    'min' => 10000000,
                    'max' => 100000000,
                    'cap' => 200000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Nhóm 2: tổng doanh số từ 10 triệu đến dưới 100 triệu đồng/kỳ sao kê',
                ],
                [
                    'pct' => 2,
                    'cap' => 100000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Nhóm 3: tổng doanh số dưới 10 triệu đồng/kỳ sao kê',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #6 — VPBank Lady Mastercard
    // =====================================================================
    'vpbank-lady-mastercard' => [
        'source_doc' => 'vpbank_lady',
        'source_page' => '3',
        'rows' => [
            'Bảo hiểm trực tuyến' => [
                [
                    'pct' => 15,
                    'min' => 15000000,
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Nhóm 1: tổng doanh số giao dịch từ 15 triệu đồng/kỳ sao kê trở lên',
                ],
                [
                    'pct' => 7.5,
                    'cap' => 150000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Nhóm 2: tổng doanh số dưới 15 triệu đồng/kỳ sao kê',
                ],
            ],
            'Giáo dục' => [
                [
                    'pct' => 15,
                    'min' => 15000000,
                    'cap' => 200000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Nhóm 1: tổng doanh số giao dịch từ 15 triệu đồng/kỳ sao kê trở lên',
                ],
                [
                    'pct' => 7.5,
                    'cap' => 100000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Nhóm 2: tổng doanh số dưới 15 triệu đồng/kỳ sao kê',
                ],
            ],
            'Chăm sóc sắc đẹp' => [
                [
                    'pct' => 10,
                    'min' => 15000000,
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Nhóm 1: tổng doanh số giao dịch từ 15 triệu đồng/kỳ sao kê trở lên',
                ],
                [
                    'pct' => 5,
                    'cap' => 150000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Nhóm 2: tổng doanh số dưới 15 triệu đồng/kỳ sao kê',
                ],
            ],
            'Y tế' => [
                [
                    'pct' => 10,
                    'min' => 15000000,
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Nhóm 1: tổng doanh số giao dịch từ 15 triệu đồng/kỳ sao kê trở lên',
                ],
                [
                    'pct' => 5,
                    'cap' => 150000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Nhóm 2: tổng doanh số dưới 15 triệu đồng/kỳ sao kê',
                ],
            ],
            'Siêu thị' => [
                [
                    'pct' => 5,
                    'min' => 15000000,
                    'cap' => 200000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Nhóm 1: tổng doanh số giao dịch từ 15 triệu đồng/kỳ sao kê trở lên',
                ],
                [
                    'pct' => 2.5,
                    'cap' => 100000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Nhóm 2: tổng doanh số dưới 15 triệu đồng/kỳ sao kê',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #7 — VPBank Z Card JCB
    // =====================================================================
    'vpbank-z-card-jcb' => [
        'source_doc' => 'vpbank-z-card-jcb-uu-dai-dai-han-2026',
        'source_page' => '9.2',
        'rows' => [
            'Ăn uống' => [
                [
                    'pct' => 15,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Tổng số tiền hoàn tối đa trong kỳ sao kê cho cả 3 nhóm ưu đãi: 1.000.000 VNĐ.',
                ],
            ],
            'Giải trí & Fitness' => [
                [
                    'pct' => 15,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Bao gồm trò chơi điện tử (game), xem phim, âm nhạc trực tuyến… và các MCC tại Bảng 1. Tổng số tiền hoàn tối đa trong kỳ sao kê cho cả 3 nhóm ưu đãi: 1.000.000 VNĐ.',
                ],
            ],
            'Di chuyển & đặt đồ ăn' => [
                [
                    'pct' => 15,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Bao gồm nền tảng gọi xe & giao đồ ăn như Be, Grab, ShopeeFood… và các MCC tại Bảng 1. Tổng số tiền hoàn tối đa trong kỳ sao kê cho cả 3 nhóm ưu đãi: 1.000.000 VNĐ.',
                ],
            ],
            'Ngành hàng khác' => [
                [
                    'pct' => 0,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Các ngành hàng không thuộc 3 nhóm ưu đãi: hoàn 0%.',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #8 — MSB mDigi Mastercard
    // =====================================================================
    'msb-mdigi-mastercard' => [
        'source_doc' => 'msb_mdigi',
        'source_page' => '3',
        'rows' => [
            'Ăn uống' => [
                [
                    'label' => 'tới 20%',
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Chọn 01 trong 03 Danh mục hoàn tiền (Ăn uống / Du lịch / Sản phẩm số)',
                ],
            ],
            'Du lịch' => [
                [
                    'label' => 'tới 20%',
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Chọn 01 trong 03 Danh mục hoàn tiền (Ăn uống / Du lịch / Sản phẩm số)',
                ],
            ],
            'Sản phẩm số' => [
                [
                    'label' => 'tới 20%',
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Chọn 01 trong 03 Danh mục hoàn tiền (Ăn uống / Du lịch / Sản phẩm số)',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #9 — MSB Visa Online
    // =====================================================================
    'msb-online-visa' => [
        'source_doc' => 'msb_online_visa',
        'source_page' => '3',
        'rows' => [
            'Thương mại điện tử' => [
                [
                    'pct' => 10,
                    'min' => 3000000,
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Tổng doanh số chi tiêu (thẻ chính + thẻ phụ) đạt từ 3.000.000đ/kỳ sao kê trở lên',
                ],
            ],
            'Mua vé xem phim online' => [
                [
                    'pct' => 10,
                    'min' => 3000000,
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Tổng doanh số chi tiêu (thẻ chính + thẻ phụ) đạt từ 3.000.000đ/kỳ sao kê trở lên',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #10 — VPBank Cake Freedom Visa
    // =====================================================================
    'vpbank-cake-freedom-visa' => [
        'source_doc' => 'vpbank_cake_freedom',
        'source_page' => '1',
        'rows' => [
            'Hoàn tiền cố định' => [
                [
                    'pct' => 20,
                    'min' => 5000000,
                    'cap' => 200000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Tổng chi tiêu thanh quyết toán từ 5.000.000đ/kỳ sao kê trở lên; trần 200.000đ/danh mục/kỳ',
                    'exc' => 'Tối đa 50.000đ/giao dịch (từ 200.000đ) và 10.000đ/giao dịch (dưới 200.000đ); tổng hoàn tối đa 1.000.000đ/khách hàng/kỳ; Siêu thị (MCC 5411) hoàn tối đa 01 giao dịch/ngày',
                ],
            ],
            'Hoàn tiền linh hoạt (chọn 2 trong 9)' => [
                [
                    'pct' => 20,
                    'min' => 5000000,
                    'cap' => 200000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Tổng chi tiêu thanh quyết toán từ 5.000.000đ/kỳ sao kê trở lên; trần 200.000đ/danh mục/kỳ; khách hàng chọn 02 trong 09 danh mục mỗi kỳ',
                    'exc' => 'Tối đa 50.000đ/giao dịch (từ 200.000đ) và 10.000đ/giao dịch (dưới 200.000đ); tổng hoàn tối đa 1.000.000đ/khách hàng/kỳ; Siêu thị (MCC 5411) hoàn tối đa 01 giao dịch/ngày',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #13 — Shinhan Supreme Visa
    // =====================================================================
    'shinhan-supreme-visa' => [
        'source_doc' => 'shinhan_supreme',
        'source_page' => '2',
        'rows' => [
            'Thương mại điện tử' => [
                [
                    'pct' => 12,
                    'min' => 15000000,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Tổng chi tiêu kỳ sao kê từ 15.000.000đ trở lên',
                ],
                [
                    'pct' => 12,
                    'min' => 3000000,
                    'max' => 15000000,
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Tổng chi tiêu kỳ sao kê từ 3.000.000đ đến dưới 15.000.000đ',
                ],
                [
                    'pct' => 0.3,
                    'max' => 3000000,
                    'cond' => 'Tổng chi tiêu kỳ sao kê dưới 3.000.000đ — chuyển sang Điểm Cơ Bản',
                ],
            ],
            'Điện máy' => [
                [
                    'pct' => 12,
                    'min' => 15000000,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Tổng chi tiêu kỳ sao kê từ 15.000.000đ trở lên',
                ],
                [
                    'pct' => 12,
                    'min' => 3000000,
                    'max' => 15000000,
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Tổng chi tiêu kỳ sao kê từ 3.000.000đ đến dưới 15.000.000đ',
                ],
                [
                    'pct' => 0.3,
                    'max' => 3000000,
                    'cond' => 'Tổng chi tiêu kỳ sao kê dưới 3.000.000đ — chuyển sang Điểm Cơ Bản',
                ],
            ],
            'Giáo dục' => [
                [
                    'pct' => 12,
                    'min' => 15000000,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Tổng chi tiêu kỳ sao kê từ 15.000.000đ trở lên',
                ],
                [
                    'pct' => 12,
                    'min' => 3000000,
                    'max' => 15000000,
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Tổng chi tiêu kỳ sao kê từ 3.000.000đ đến dưới 15.000.000đ',
                ],
                [
                    'pct' => 0.3,
                    'max' => 3000000,
                    'cond' => 'Tổng chi tiêu kỳ sao kê dưới 3.000.000đ — chuyển sang Điểm Cơ Bản',
                ],
            ],
            'Nhà sách' => [
                [
                    'pct' => 12,
                    'min' => 15000000,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Tổng chi tiêu kỳ sao kê từ 15.000.000đ trở lên',
                ],
                [
                    'pct' => 12,
                    'min' => 3000000,
                    'max' => 15000000,
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Tổng chi tiêu kỳ sao kê từ 3.000.000đ đến dưới 15.000.000đ',
                ],
                [
                    'pct' => 0.3,
                    'max' => 3000000,
                    'cond' => 'Tổng chi tiêu kỳ sao kê dưới 3.000.000đ — chuyển sang Điểm Cơ Bản',
                ],
            ],
            'Bệnh viện' => [
                [
                    'pct' => 6,
                    'min' => 15000000,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Tổng chi tiêu kỳ sao kê từ 15.000.000đ trở lên',
                ],
                [
                    'pct' => 6,
                    'min' => 3000000,
                    'max' => 15000000,
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Tổng chi tiêu kỳ sao kê từ 3.000.000đ đến dưới 15.000.000đ',
                ],
                [
                    'pct' => 0.3,
                    'max' => 3000000,
                    'cond' => 'Tổng chi tiêu kỳ sao kê dưới 3.000.000đ — chuyển sang Điểm Cơ Bản',
                ],
            ],
            'Bảo hiểm' => [
                [
                    'pct' => 6,
                    'min' => 15000000,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Tổng chi tiêu kỳ sao kê từ 15.000.000đ trở lên',
                ],
                [
                    'pct' => 6,
                    'min' => 3000000,
                    'max' => 15000000,
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Tổng chi tiêu kỳ sao kê từ 3.000.000đ đến dưới 15.000.000đ',
                ],
                [
                    'pct' => 0.3,
                    'max' => 3000000,
                    'cond' => 'Tổng chi tiêu kỳ sao kê dưới 3.000.000đ — chuyển sang Điểm Cơ Bản',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #14 — VIB Max Card Visa (dùng chung 4 hạng)
    // =====================================================================
    'vib-max-card-visa-starter' => [
        'source_doc' => 'vib_max_card',
        'source_page' => '2',
        'product_name' => 'VIB Max Card Visa',
        'rows' => [
            'Mua sắm' => [
                [
                    'pct' => 10,
                    'cap' => 1500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Elite — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký (chọn 01 trong 05 nhóm)',
                ],
                [
                    'pct' => 8,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Pro — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
                [
                    'pct' => 5,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Plus — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
                [
                    'pct' => 0.5,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Starter — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
                [
                    'pct' => 0.1,
                    'cond' => 'Giao dịch không thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
            ],
            'Du lịch' => [
                [
                    'pct' => 10,
                    'cap' => 1500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Elite — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký (chọn 01 trong 05 nhóm)',
                ],
                [
                    'pct' => 8,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Pro — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
                [
                    'pct' => 5,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Plus — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
                [
                    'pct' => 0.5,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Starter — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
                [
                    'pct' => 0.1,
                    'cond' => 'Giao dịch không thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
            ],
            'Ẩm thực' => [
                [
                    'pct' => 10,
                    'cap' => 1500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Elite — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký (chọn 01 trong 05 nhóm)',
                ],
                [
                    'pct' => 8,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Pro — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
                [
                    'pct' => 5,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Plus — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
                [
                    'pct' => 0.5,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Starter — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
                [
                    'pct' => 0.1,
                    'cond' => 'Giao dịch không thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
            ],
            'Giải trí' => [
                [
                    'pct' => 10,
                    'cap' => 1500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Elite — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký (chọn 01 trong 05 nhóm)',
                ],
                [
                    'pct' => 8,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Pro — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
                [
                    'pct' => 5,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Plus — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
                [
                    'pct' => 0.5,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Starter — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
                [
                    'pct' => 0.1,
                    'cond' => 'Giao dịch không thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
            ],
            'Giao dịch trực tuyến' => [
                [
                    'pct' => 10,
                    'cap' => 1500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Elite — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký (chọn 01 trong 05 nhóm)',
                ],
                [
                    'pct' => 8,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Pro — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
                [
                    'pct' => 5,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Plus — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
                [
                    'pct' => 0.5,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Gói Starter — chi tiêu thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                ],
                [
                    'pct' => 0.1,
                    'cond' => 'Giao dịch không thuộc Nhóm Danh Mục Chi Tiêu đã đăng ký',
                    'exc' => 'Không áp dụng cho giao dịch MCC 6300 và MCC 7399',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #13 — Sacombank Visa Bạch Kim Hoàn Tiền
    // =====================================================================
    'sacombank-visa-platinum-cashback' => [
        'source_doc' => 'sacombank_platinum_cashback',
        'source_page' => '1',
        'rows' => [
            'Siêu thị, cửa hàng tiện lợi' => [
                [
                    'pct' => 6.8,
                    'cap' => 680000,
                    'cap_period' => 'kỳ TBGD',
                    'cap_unit' => 'VND',
                    'cond' => 'Giao dịch từ 100.000đ trở lên; tiền hoàn tối thiểu 10.000đ/kỳ; trần chung nhóm siêu thị + di chuyển 680.000đ/kỳ TBGD',
                ],
            ],
            'Ứng dụng di chuyển' => [
                [
                    'pct' => 16.8,
                    'cap' => 680000,
                    'cap_period' => 'kỳ TBGD',
                    'cap_unit' => 'VND',
                    'cond' => 'Giao dịch từ 100.000đ trở lên; tiền hoàn tối thiểu 10.000đ/kỳ; trần chung nhóm siêu thị + di chuyển 680.000đ/kỳ TBGD',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #14 — Sacombank Platinum American Express
    // =====================================================================
    'sacombank-platinum-amex' => [
        'source_doc' => 'sacombank_amex_tnc',
        'source_page' => '1',
        'rows' => [
            'Vé máy bay, khách sạn, đại lý du lịch (MCC 3000–3836)' => [
                [
                    'pct' => 20,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ TBGD',
                    'cap_unit' => 'VND',
                    'cond' => 'Giao dịch vé máy bay/khách sạn/đại lý du lịch; tiền hoàn tối thiểu 10.000đ, tối đa 1.000.000đ/kỳ TBGD/chủ thẻ (~12.000.000đ/năm)',
                ],
            ],
            'Chi tiêu tại TBCNT nước ngoài' => [
                [
                    'pct' => 10,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ TBGD',
                    'cap_unit' => 'VND',
                    'cond' => 'Giao dịch tại đơn vị chấp nhận thẻ nước ngoài; giao dịch trực tuyến nước ngoài 5%; giao dịch khác 0,5%',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #31 — HSBC Cash Back (Điều khoản & Điều kiện, hiệu lực 06/03/2026)
    // =====================================================================
    'hsbc-cash-back' => [
        'source_doc' => 'hsbc-cash-back-terms-vn',
        'source_page' => '6',
        'rows' => [
            'Siêu thị, cửa hàng bách hóa và dịch vụ taxi, limousine' => [
                [
                    'pct' => 6,
                    'cap' => 200000,
                    'cap_period' => 'tháng',
                    'cap_unit' => 'VND',
                    'cond' => '6% cho giao dịch Siêu thị, cửa hàng bách hóa (kèm dịch vụ taxi, limousine). Hoàn thêm 2% (tổng cộng 8%) khi Chủ Thẻ có Tài Khoản Nhận Lương và Giao Dịch Lương Chuyển Khoản Hợp Lệ từ 10.000.000đ/tháng. Trần hoàn tiền 200.000đ/tháng (theo tháng dương lịch).',
                ],
            ],
            'Giáo dục' => [
                [
                    'pct' => 1,
                    'cond' => 'Hoàn 1% cho giao dịch thuộc danh mục Giáo dục; không giới hạn số tiền hoàn.',
                ],
            ],
            'Chăm sóc sức khỏe' => [
                [
                    'pct' => 1,
                    'cond' => 'Hoàn 1% cho giao dịch thuộc danh mục Chăm sóc sức khỏe; không giới hạn số tiền hoàn.',
                ],
            ],
            'Khác' => [
                [
                    'pct' => 0.3,
                    'cond' => 'Mọi Giao Dịch Hợp Lệ khác không thuộc các danh mục nêu trên: hoàn 0,3%. Tài liệu không liệt kê mã MCC cho nhóm này.',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #15 — MB JCB Platinum
    // =====================================================================
    'mb-jcb-platinum' => [
        'source_doc' => 'mb_jcb_platinum',
        'source_page' => '1',
        'rows' => [
            'Mua sắm online – TikTok Shop' => [
                [
                    'pct' => 10,
                    'min' => 2000000,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Doanh số TikTok Shop trong kỳ sao kê ≥ 2.000.000đ; MCC 5399; tổng hoàn tối đa 500.000đ/kỳ cho cả Shopee + TikTok Shop',
                ],
            ],
            'Mua sắm online – Shopee' => [
                [
                    'pct' => 10,
                    'min' => 2000000,
                    'cap' => 500000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Doanh số Shopee trong kỳ sao kê ≥ 2.000.000đ; giao dịch có merchant_name chứa "SHOPEE"; tổng hoàn tối đa 500.000đ/kỳ cho cả Shopee + TikTok Shop',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #16 — UOB One
    // =====================================================================
    'uob-one' => [
        'source_doc' => 'uob_one_cashback',
        'source_page' => '4',
        'rows' => [
            'Grab & di chuyển mặt đất' => [
                [
                    'pct' => 10,
                    'cap' => 600000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Trần chung 600.000 điểm/kỳ sao kê cho các nhóm ưu đãi (Grab & di chuyển, gói đăng ký, mua sắm, bảo hiểm)',
                ],
            ],
            'Gói đăng ký định kỳ (recurring subscriptions)' => [
                [
                    'pct' => 5,
                    'cap' => 600000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Trần chung 600.000 điểm/kỳ sao kê cho các nhóm ưu đãi',
                ],
            ],
            'Bảo hiểm' => [
                [
                    'pct' => 3,
                    'cap' => 600000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Trần chung 600.000 điểm/kỳ sao kê cho các nhóm ưu đãi',
                ],
            ],
            'Mua sắm (trung tâm thương mại & chuỗi thời trang)' => [
                [
                    'pct' => 2,
                    'cap' => 600000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Trần chung 600.000 điểm/kỳ sao kê cho các nhóm ưu đãi',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #17 — VPBank Flex Mastercard (VPBank Loyalty, Phụ lục 03)
    // =====================================================================
    'vpbank-flex' => [
        'source_doc' => 'vpbank_loyalty_q1_2026',
        'source_page' => '7',
        'rows' => [
            'Nhóm ưu đãi được chọn (mặc định: Ăn uống)' => [
                [
                    'pct' => 15,
                    'min' => 2000000,
                    'cap' => 1000000,
                    'cap_period' => 'kỳ tích điểm',
                    'cap_unit' => 'điểm',
                    'cond' => 'Đầu BIN 52039939 (Credit): tổng chi tiêu hợp lệ ≥ 2.000.000đ/kỳ; tích 15% giá trị chi tiêu tại ngành hàng thuộc gói ưu đãi đã chọn (Phụ lục 03); giới hạn tích điểm 1.000.000đ/kỳ',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #18 — Shinhan 365 Cashback Platinum
    // =====================================================================
    'shinhan-365-cashback-platinum' => [
        'source_doc' => 'shinhan_365_tnc',
        'source_page' => '1',
        'rows' => [
            'Siêu thị, cửa hàng thực phẩm' => [
                [
                    'pct' => 10,
                    'cap' => 400000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Hoàn tiền Đặc Biệt 1; chỉ áp dụng chi tiêu quẹt POS, không áp dụng trực tuyến; tổng chi tiêu kỳ sao kê ≥ 3.000.000đ',
                ],
                [
                    'pct' => 12,
                    'cap' => 400000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Hoàn tiền Đặc Biệt 1 khi đồng thời là chủ thẻ ghi nợ Shinhan Be-SAFE; chỉ áp dụng quẹt POS; tổng chi tiêu kỳ sao kê ≥ 3.000.000đ',
                ],
            ],
            'Chi tiêu quốc tế' => [
                [
                    'pct' => 3,
                    'cap' => 200000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Hoàn tiền Đặc Biệt 2; giao dịch thanh toán bằng ngoại tệ ở nước ngoài; chỉ áp dụng quẹt POS, không áp dụng trực tuyến',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #19 — Shinhan MyLife Visa Bạch Kim
    // =====================================================================
    'shinhan-mylife-visa-bach-kim' => [
        'source_doc' => 'shinhan_mylife_tnc',
        'source_page' => '1',
        'rows' => [
            'Di chuyển' => [
                [
                    'pct' => 10,
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Điểm Đặc Biệt 10%; tối đa 300.000 điểm/danh mục, 1.200.000 điểm/kỳ sao kê; tổng chi tiêu kỳ sao kê ≥ 3.000.000đ',
                ],
            ],
            'Siêu thị, cửa hàng tiện lợi' => [
                [
                    'pct' => 10,
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Điểm Đặc Biệt 10%; tối đa 300.000 điểm/danh mục, 1.200.000 điểm/kỳ sao kê; tổng chi tiêu kỳ sao kê ≥ 3.000.000đ',
                ],
            ],
            'Ăn uống' => [
                [
                    'pct' => 10,
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Điểm Đặc Biệt 10%; tối đa 300.000 điểm/danh mục, 1.200.000 điểm/kỳ sao kê; tổng chi tiêu kỳ sao kê ≥ 3.000.000đ',
                ],
            ],
            'Giải trí' => [
                [
                    'pct' => 10,
                    'cap' => 300000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Điểm Đặc Biệt 10%; tối đa 300.000 điểm/danh mục, 1.200.000 điểm/kỳ sao kê; tổng chi tiêu kỳ sao kê ≥ 3.000.000đ',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #20 — Thẻ Cake 2in1 – Gói Premium
    // =====================================================================
    'cake-2in1-premium' => [
        'source_doc' => 'cake_premium_mcc',
        'source_page' => '1.2',
        'rows' => [
            'Nhóm Giáo dục' => [
                [
                    'pct' => 20,
                    'cap' => 2000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Cơ chế Premium; Tier 1: chi tiêu ≥ 20.000.000đ/kỳ, không yêu cầu CASA; trần 200.000đ/giao dịch; tổng hoàn tối đa 2.000.000đ/kỳ',
                ],
                [
                    'pct' => 20,
                    'cap' => 5000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Cơ chế Premium; Tier 2: chi tiêu ≥ 20.000.000đ/kỳ và CASA bình quân ≥ 200.000.000đ; trần 200.000đ/giao dịch; tổng hoàn tối đa 5.000.000đ/kỳ',
                ],
                [
                    'pct' => 20,
                    'cap' => 10000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Cơ chế Premium; Tier 3: chi tiêu ≥ 20.000.000đ/kỳ và CASA bình quân ≥ 500.000.000đ; trần 200.000đ/giao dịch; tổng hoàn tối đa 10.000.000đ/kỳ',
                ],
            ],
            'Nhóm Đồ điện tử, điện máy' => [
                [
                    'pct' => 20,
                    'cap' => 2000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Cơ chế Premium; Tier 1: chi tiêu ≥ 20.000.000đ/kỳ, không yêu cầu CASA; trần 200.000đ/giao dịch; tổng hoàn tối đa 2.000.000đ/kỳ',
                ],
                [
                    'pct' => 20,
                    'cap' => 5000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Cơ chế Premium; Tier 2: chi tiêu ≥ 20.000.000đ/kỳ và CASA bình quân ≥ 200.000.000đ; trần 200.000đ/giao dịch; tổng hoàn tối đa 5.000.000đ/kỳ',
                ],
                [
                    'pct' => 20,
                    'cap' => 10000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Cơ chế Premium; Tier 3: chi tiêu ≥ 20.000.000đ/kỳ và CASA bình quân ≥ 500.000.000đ; trần 200.000đ/giao dịch; tổng hoàn tối đa 10.000.000đ/kỳ',
                ],
            ],
            'Nhóm Du lịch, khách sạn, hàng không' => [
                [
                    'pct' => 20,
                    'cap' => 2000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Cơ chế Premium; Tier 1: chi tiêu ≥ 20.000.000đ/kỳ, không yêu cầu CASA; trần 200.000đ/giao dịch; tổng hoàn tối đa 2.000.000đ/kỳ',
                ],
                [
                    'pct' => 20,
                    'cap' => 5000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Cơ chế Premium; Tier 2: chi tiêu ≥ 20.000.000đ/kỳ và CASA bình quân ≥ 200.000.000đ; trần 200.000đ/giao dịch; tổng hoàn tối đa 5.000.000đ/kỳ',
                ],
                [
                    'pct' => 20,
                    'cap' => 10000000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Cơ chế Premium; Tier 3: chi tiêu ≥ 20.000.000đ/kỳ và CASA bình quân ≥ 500.000.000đ; trần 200.000đ/giao dịch; tổng hoàn tối đa 10.000.000đ/kỳ',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #21 — VPBank S Rewards Mastercard (tích Điểm THƯỞNG; 1 điểm ≈ 1đ)
    // =====================================================================
    'vpbank-s-rewards-mastercard' => [
        'source_doc' => 'vpbank_s_rewards',
        'source_page' => 'Bảng 1 & Bảng 2 (phụ đính)',
        'rows' => [
            'Ăn uống' => [
                [
                    'pct' => 4,
                    'min' => 5000000,
                    'cap' => 2400000,
                    'cap_period' => 'kỳ TBGD',
                    'cap_unit' => 'điểm',
                    'cond' => 'Bậc 2: tổng chi tiêu kỳ TBGD ≥ 5.000.000đ; nhóm 4% (Ăn uống) — MCC 5811, 5812, 5813, 5921; trần chung 2.400.000 điểm/kỳ TBGD cho nhóm 12% + 4%',
                ],
            ],
            'Siêu thị' => [
                [
                    'pct' => 4,
                    'min' => 5000000,
                    'cap' => 2400000,
                    'cap_period' => 'kỳ TBGD',
                    'cap_unit' => 'điểm',
                    'cond' => 'Bậc 2: tổng chi tiêu kỳ TBGD ≥ 5.000.000đ; nhóm 4% (Siêu thị) — MCC 5411; trần chung 2.400.000 điểm/kỳ TBGD cho nhóm 12% + 4%',
                ],
            ],
            'Di chuyển' => [
                [
                    'pct' => 4,
                    'min' => 5000000,
                    'cap' => 2400000,
                    'cap_period' => 'kỳ TBGD',
                    'cap_unit' => 'điểm',
                    'cond' => 'Bậc 2: tổng chi tiêu kỳ TBGD ≥ 5.000.000đ; nhóm 4% (Di chuyển) — MCC 4121, 5814; trần chung 2.400.000 điểm/kỳ TBGD cho nhóm 12% + 4%',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #22 — BIDV Visa Cashback 360 (tích điểm hoàn tiền; 1 điểm hoàn ≈ 1đ)
    // =====================================================================
    'bidv-visa-cashback-360' => [
        'source_doc' => 'bidv_membership_rewards_2026',
        'source_page' => '6.3',
        'rows' => [
            'Siêu thị' => [
                [
                    'pct' => 2,
                    'min' => 3000000,
                    'cap' => 300000,
                    'cap_period' => 'tháng',
                    'cap_unit' => 'điểm',
                    'cond' => 'Tổng DSGD ghi nhận trong tháng tại siêu thị (MCC 5411) từ 0 đến 10 triệu đ → 20 điểm/1.000đ; trần 300.000 điểm/tháng; tổng doanh số giao dịch ≥ 3.000.000đ/tháng',
                ],
                [
                    'pct' => 10,
                    'min' => 3000000,
                    'cap' => 300000,
                    'cap_period' => 'tháng',
                    'cap_unit' => 'điểm',
                    'cond' => 'Tổng DSGD ghi nhận trong tháng tại siêu thị (MCC 5411) từ trên 10 triệu đ → 100 điểm/1.000đ; trần 300.000 điểm/tháng; tổng doanh số giao dịch ≥ 3.000.000đ/tháng',
                ],
            ],
            'Y tế sức khỏe' => [
                [
                    'pct' => 2,
                    'min' => 3000000,
                    'cap' => 300000,
                    'cap_period' => 'tháng',
                    'cap_unit' => 'điểm',
                    'cond' => 'Lĩnh vực y tế sức khỏe (MCC 7298, 8062, 8050, 8099, 8021, 8042, 8071): 20 điểm/1.000đ; trần 300.000 điểm/tháng; tổng doanh số giao dịch ≥ 3.000.000đ/tháng',
                ],
            ],
            'Giáo dục' => [
                [
                    'pct' => 2,
                    'min' => 3000000,
                    'cap' => 300000,
                    'cap_period' => 'tháng',
                    'cap_unit' => 'điểm',
                    'cond' => 'Lĩnh vực giáo dục (MCC 8244, 8351, 8220, 8211, 8299, 8249, 8241): 20 điểm/1.000đ; trần 300.000 điểm/tháng; tổng doanh số giao dịch ≥ 3.000.000đ/tháng',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #23 — OCB JCB Platinum (Phụ lục A — Danh mục MCC hoàn tiền)
    // =====================================================================
    'ocb-jcb-platinum' => [
        'source_doc' => 'ocb_jcb_platinum_update19',
        'source_page' => 'Phụ lục A',
        'rows' => [
            'Sức khỏe' => [
                [
                    'pct' => 7,
                    'cap' => 150000,
                    'cap_period' => 'kỳ',
                    'cap_unit' => 'VND',
                    'cond' => 'Bậc tiêu < 30.000.000đ/kỳ: 7% mỗi nhóm; trần 150.000đ/nhóm; tổng hoàn tối đa 700.000đ/kỳ; điều kiện ≥ 7 giao dịch hợp lệ/kỳ',
                ],
                [
                    'pct' => 15,
                    'min' => 30000000,
                    'cap' => 300000,
                    'cap_period' => 'kỳ',
                    'cap_unit' => 'VND',
                    'cond' => 'Bậc tiêu ≥ 30.000.000đ/kỳ: 15% mỗi nhóm; trần 300.000đ/nhóm; tổng hoàn tối đa 1.500.000đ/kỳ; điều kiện ≥ 7 giao dịch hợp lệ/kỳ',
                ],
            ],
            'Bảo hiểm' => [
                [
                    'pct' => 7,
                    'cap' => 150000,
                    'cap_period' => 'kỳ',
                    'cap_unit' => 'VND',
                    'cond' => 'Bậc tiêu < 30.000.000đ/kỳ: 7% mỗi nhóm; trần 150.000đ/nhóm; tổng hoàn tối đa 700.000đ/kỳ; điều kiện ≥ 7 giao dịch hợp lệ/kỳ',
                ],
                [
                    'pct' => 15,
                    'min' => 30000000,
                    'cap' => 300000,
                    'cap_period' => 'kỳ',
                    'cap_unit' => 'VND',
                    'cond' => 'Bậc tiêu ≥ 30.000.000đ/kỳ: 15% mỗi nhóm; trần 300.000đ/nhóm; tổng hoàn tối đa 1.500.000đ/kỳ; điều kiện ≥ 7 giao dịch hợp lệ/kỳ',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #7 — VPBank Super Shopee Platinum Visa ("Thể lệ ưu đãi dài hạn")
    // HỒ SƠ MÔ TẢ: VPBank KHÔNG công bố danh sách mã MCC cụ thể; các dòng
    // included chỉ mô tả quyền lợi theo danh mục/ĐVCNT.
    // =====================================================================
    'vpbank-super-shopee-platinum-visa' => [
        'source_doc' => 'vpbank-super-shopee-terms',
        'source_page' => '7.1',
        'rows' => [
            'Giao dịch tại Shopee, ShopeeFood' => [
                [
                    'pct' => 10,
                    'min' => 4000000,
                    'cap' => 400000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Tổng doanh số giao dịch của Khách hàng đạt từ 4.000.000đ trong kỳ sao kê xét hoàn tiền; số tiền hoàn tối đa 400.000đ/kỳ sao kê.',
                ],
            ],
            'Giao dịch ăn uống, siêu thị (Co.op, BigC, Lottemart, Winmart), Grab/Be' => [
                [
                    'pct' => 2,
                    'min' => 4000000,
                    'cap' => 200000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Tổng doanh số giao dịch của Khách hàng đạt từ 4.000.000đ trong kỳ sao kê xét hoàn tiền; số tiền hoàn tối đa 200.000đ/kỳ sao kê.',
                ],
            ],
            'Tất cả giao dịch còn lại' => [
                [
                    'pct' => 0.1,
                    'cond' => 'Hoàn 0,1% cho toàn bộ giao dịch còn lại; không giới hạn số tiền hoàn.',
                ],
            ],
        ],
    ],

    // =====================================================================
    // #34 — VIB Online Plus 2in1 (Chương trình điểm thưởng/hoàn tiền VIB, mục 2.6)
    // HỒ SƠ MÔ TẢ theo KÊNH giao dịch; VIB công bố loại trừ MCC 4900, 4814.
    // =====================================================================
    'vib-online-plus-2in1' => [
        'source_page' => '2.6',
        'rows' => [
            'Chi tiêu trực tuyến nước ngoài' => [
                [
                    'pct' => 5,
                    'cap' => 600000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Giao dịch chi tiêu trực tuyến tại ĐVCNT ngoài lãnh thổ Việt Nam và thực hiện bằng ngoại tệ; điểm thưởng tối đa 600.000 điểm/kỳ sao kê.',
                ],
            ],
            'Chi tiêu trực tuyến khác' => [
                [
                    'pct' => 3,
                    'cap' => 600000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'điểm',
                    'cond' => 'Giao dịch chi tiêu trực tuyến còn lại; điểm thưởng tối đa 600.000 điểm/kỳ sao kê.',
                ],
            ],
            'Giao dịch chi tiêu còn lại' => [
                [
                    'pct' => 0.1,
                    'cond' => 'Toàn bộ giá trị giao dịch chi tiêu còn lại trong kỳ (không phải giao dịch thanh toán trực tuyến), gồm cả thanh toán hóa đơn phí bảo hiểm Prudential của Chủ Thẻ cho chính Chủ Thẻ qua MyVIB; tối đa 600.000 điểm/kỳ sao kê.',
                ],
            ],
            'Giao dịch có lưu thông tin Thẻ (card-on-file)' => [
                [
                    'cap' => 100000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Hoàn tiền 50.000đ/giao dịch lưu thông tin Thẻ tại ĐVCNT trực tuyến (Grab, Netflix, Tiki, Agoda, Booking.com, iTunes, CH Play, Spotify, Traveloka). Giao dịch từ ngày phát hành thẻ đến trước ngày lập bảng sao kê của kỳ sao kê thứ 03.',
                    'exc' => 'Số tiền hoàn tối đa 100.000đ/kỳ sao kê và tối đa 300.000đ/Khách hàng. Đây là hoàn tiền, không phải điểm thưởng.',
                ],
            ],
        ],
    ],
];
