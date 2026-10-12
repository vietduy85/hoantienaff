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
        'source_doc' => 'vpbank_zcard',
        'source_page' => '9.2',
        'rows' => [
            'Ăn uống' => [
                [
                    'pct' => 10,
                    'min' => 4000000,
                    'cap' => 200000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Tổng chi tiêu đạt từ 4 triệu VND trong kỳ sao kê xét hoàn tiền',
                ],
            ],
            'Giải trí và Fitness' => [
                [
                    'pct' => 10,
                    'min' => 4000000,
                    'cap' => 200000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Tổng chi tiêu đạt từ 4 triệu VND trong kỳ sao kê xét hoàn tiền',
                ],
            ],
            'Di chuyển và đặt đồ ăn' => [
                [
                    'pct' => 10,
                    'min' => 4000000,
                    'cap' => 100000,
                    'cap_period' => 'kỳ sao kê',
                    'cap_unit' => 'VND',
                    'cond' => 'Tổng chi tiêu đạt từ 4 triệu VND trong kỳ sao kê xét hoàn tiền',
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
];
