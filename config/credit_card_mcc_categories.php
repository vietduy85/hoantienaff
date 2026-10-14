<?php

/*
|--------------------------------------------------------------------------
| Danh mục tra cứu (nhóm chuẩn hoá) — tab "Tra cứu danh mục"
|--------------------------------------------------------------------------
|
| Nguồn dữ liệu là TÊN DANH MỤC trong các dòng included của HỒ SƠ MCC
| (`credit_card_policy_mcc_rows`). File này chỉ KHAI BÁO cách gom nhóm + nhãn
| hiển thị; KHÔNG thay đổi tên gốc trong hồ sơ và KHÔNG tạo mã MCC mới.
|
| - `groups`: khoá chuẩn => nhãn hiển thị. Thứ tự khoá = thứ tự ưu tiên khi
|   sắp xếp dropdown (nhóm chuẩn đứng trước, danh mục "giữ nguyên" đứng sau).
| - `aliases`: tên gốc (đã chuẩn hoá: bỏ dấu/hoa-thường/dấu câu/khoảng trắng)
|   => khoá chuẩn, hoặc MẢNG khoá chuẩn khi một dòng mô tả gộp nhiều nhóm.
|
| Quy tắc:
|   - Tên KHÔNG có trong `aliases` sẽ trở thành một lựa chọn RIÊNG (key
|     `raw:<tên-chuẩn-hoá>`), KHÔNG tự gộp — đúng nguyên tắc "không chắc thì để
|     riêng". Nhờ vậy dữ liệu mới tự xuất hiện mà không cần sửa code.
|   - Chỉ gom khi ngữ nghĩa tương đối giống nhau; không gộp khác bản chất chỉ vì
|     có liên quan (vd điều kiện KÊNH giao dịch giữ riêng).
|   - Khoá có thể viết tự nhiên (có dấu) — service tự chuẩn hoá khi so khớp.
*/

return [
    'groups' => [
        'food' => 'Ăn uống',
        'supermarket' => 'Siêu thị',
        'health' => 'Y tế và sức khỏe',
        'beauty' => 'Chăm sóc sắc đẹp',
        'education' => 'Giáo dục',
        'insurance' => 'Bảo hiểm',
        'travel' => 'Du lịch và lưu trú',
        'transport' => 'Di chuyển',
        'shopping' => 'Mua sắm',
        'ecommerce' => 'Thương mại điện tử',
        'online' => 'Chi tiêu trực tuyến',
        'entertainment' => 'Giải trí',
        'cinema' => 'Mua vé xem phim',
        'fitness' => 'Thể thao & Fitness',
        'electronics' => 'Điện máy',
        'digital_products' => 'Sản phẩm số',
        'books' => 'Nhà sách',
        'subscriptions' => 'Gói đăng ký định kỳ',
        'other' => 'Giao dịch còn lại',
    ],

    'aliases' => [
        // Ăn uống
        'Ẩm thực' => 'food',
        'Ăn uống' => 'food',
        'Di chuyển và đặt đồ ăn' => ['transport', 'food'],
        'Di chuyển & đặt đồ ăn' => ['transport', 'food'],

        // Siêu thị
        'Siêu thị' => 'supermarket',
        'Siêu thị, cửa hàng tiện lợi' => 'supermarket',
        'Siêu thị, cửa hàng thực phẩm' => 'supermarket',
        'Siêu thị, cửa hàng bách hóa và dịch vụ taxi, limousine' => ['supermarket', 'transport'],

        // Y tế và sức khỏe
        'Y tế' => 'health',
        'Y tế sức khỏe' => 'health',
        'Sức khỏe' => 'health',
        'Bệnh viện' => 'health',
        'Chăm sóc sức khỏe' => 'health',

        // Sắc đẹp
        'Chăm sóc sắc đẹp' => 'beauty',

        // Giáo dục
        'Giáo dục' => 'education',
        'Nhóm Giáo dục' => 'education',

        // Bảo hiểm
        'Bảo hiểm' => 'insurance',
        'Bảo hiểm trực tuyến' => 'insurance',

        // Du lịch và lưu trú
        'Du lịch' => 'travel',
        'Nhóm Du lịch, khách sạn, hàng không' => 'travel',
        'Vé máy bay, khách sạn, đại lý du lịch (MCC 3000–3836)' => 'travel',

        // Di chuyển
        'Di chuyển' => 'transport',
        'Ứng dụng di chuyển' => 'transport',
        'Grab & di chuyển mặt đất' => 'transport',

        // Mua sắm
        'Mua sắm' => 'shopping',
        'Mua sắm (trung tâm thương mại & chuỗi thời trang)' => 'shopping',

        // Thương mại điện tử / mua sắm online
        'Thương mại điện tử' => 'ecommerce',
        'Mua sắm online – Shopee' => ['shopping', 'ecommerce'],
        'Mua sắm online – TikTok Shop' => ['shopping', 'ecommerce'],
        'Mua sắm online - Shopee, ShopeeFood' => ['shopping', 'ecommerce', 'food'],

        // Chi tiêu trực tuyến
        'Chi tiêu online' => 'online',
        'Giao dịch trực tuyến' => 'online',
        'Chi tiêu trực tuyến khác' => 'online',

        // Giải trí / điện ảnh / fitness
        'Giải trí' => 'entertainment',
        'Giải trí và Fitness' => ['entertainment', 'fitness'],
        'Giải trí & Fitness' => ['entertainment', 'fitness'],
        'Mua vé xem phim online' => ['cinema', 'entertainment'],

        // Điện máy / sản phẩm số / nhà sách / định kỳ
        'Điện máy' => 'electronics',
        'Nhóm Đồ điện tử, điện máy' => 'electronics',
        'Sản phẩm số' => 'digital_products',
        'Nhà sách' => 'books',
        'Gói đăng ký định kỳ (recurring subscriptions)' => 'subscriptions',

        // Giao dịch còn lại
        'Khác' => 'other',
        'Ngành hàng khác' => 'other',
        'Giao dịch chi tiêu còn lại' => 'other',
        'Tất cả giao dịch còn lại' => 'other',

        // Dòng mô tả gộp nhiều nhóm (VPBank Super Shopee) — tách thành các nhóm
        // tương ứng để người dùng tra theo từng danh mục vẫn thấy đúng thẻ.
        'Giao dịch tại Shopee, ShopeeFood' => ['ecommerce', 'food'],
        'Giao dịch ăn uống, siêu thị (Co.op, BigC, Lottemart, Winmart), Grab/Be' => ['food', 'supermarket', 'transport'],

        // LƯU Ý: các danh mục mang tính ĐIỀU KIỆN KÊNH / cơ chế hoàn tiền (không
        // phải ngành hàng) được CỐ Ý để riêng, không gom:
        //   - "Chi tiêu tại TBCNT nước ngoài", "Chi tiêu quốc tế",
        //     "Chi tiêu trực tuyến nước ngoài",
        //   - "Giao dịch có lưu thông tin Thẻ (card-on-file)",
        //   - "Hoàn tiền cố định", "Hoàn tiền linh hoạt (chọn 2 trong 9)",
        //     "Nhóm ưu đãi được chọn (mặc định: Ăn uống)".
    ],
];
