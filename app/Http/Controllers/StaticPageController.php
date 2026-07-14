<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Route;

class StaticPageController extends Controller
{
    private array $pages = [
        'about' => [
            'slug' => 'about',
            'title' => 'Giới thiệu về Hoàn Tiền Aff - Nền tảng hoàn tiền affiliate',
            'description' => 'Hoàn Tiền Aff giúp bạn tiết kiệm hơn khi mua sắm online. Tìm hiểu cách hoạt động, nền tảng được hỗ trợ và cam kết minh bạch của chúng tôi.',
            'view' => 'pages.about',
        ],
        'contact' => [
            'slug' => 'contact',
            'title' => 'Liên hệ Hoàn Tiền Aff - Hỗ trợ khách hàng',
            'description' => 'Liên hệ với Hoàn Tiền Aff qua email, Zalo hoặc Facebook. Đội ngũ hỗ trợ sẵn sàng giúp đỡ bạn từ 8:00 - 22:00, Thứ Hai - Thứ Bảy.',
            'view' => 'pages.contact',
        ],
        'faq' => [
            'slug' => 'faq',
            'title' => 'Câu hỏi thường gặp - Hoàn Tiền Aff',
            'description' => 'Giải đáp các thắc mắc phổ biến về hoàn tiền affiliate, rút tiền, theo dõi đơn hàng và các nền tảng được hỗ trợ tại Hoàn Tiền Aff.',
            'view' => 'pages.faq',
        ],
        'privacy-policy' => [
            'slug' => 'privacy-policy',
            'title' => 'Chính sách bảo mật - Hoàn Tiền Aff',
            'description' => 'Chính sách bảo mật thông tin khách hàng tại Hoàn Tiền Aff. Cam kết bảo vệ dữ liệu cá nhân, cookie và affiliate tracking.',
            'view' => 'pages.privacy',
        ],
        'terms-of-service' => [
            'slug' => 'terms-of-service',
            'title' => 'Điều khoản sử dụng - Hoàn Tiền Aff',
            'description' => 'Điều khoản sử dụng dịch vụ Hoàn Tiền Aff. Quyền và nghĩa vụ của người dùng, chính sách affiliate và thanh toán.',
            'view' => 'pages.terms',
        ],
        'refund-policy' => [
            'slug' => 'refund-policy',
            'title' => 'Chính sách hoàn tiền - Hoàn Tiền Aff',
            'description' => 'Chính sách hoàn tiền affiliate tại Hoàn Tiền Aff. Tỷ lệ hoàn theo nền tảng, điều kiện nhận tiền và quy trình rút tiền.',
            'view' => 'pages.refund',
        ],
        'how-it-works' => [
            'slug' => 'how-it-works',
            'title' => 'Cách hoạt động - Hoàn Tiền Aff',
            'description' => 'Hướng dẫn từng bước cách sử dụng Hoàn Tiền Aff: từ đăng nhập, tạo link, mua sắm đến nhận tiền hoàn và rút tiền.',
            'view' => 'pages.how_it_works',
        ],
        'cookie-policy' => [
            'slug' => 'cookie-policy',
            'title' => 'Chính sách Cookie - Hoàn Tiền Aff',
            'description' => 'Chính sách sử dụng cookie tại Hoàn Tiền Aff. Cookie session, affiliate tracking, analytics và quyền kiểm soát của bạn.',
            'view' => 'pages.cookie_policy',
        ],
    ];

    public function show()
    {
        $slug = request()->route('slug');

        if (!isset($this->pages[$slug])) {
            abort(404);
        }

        $page = $this->pages[$slug];

        return view($page['view'], [
            'pageTitle' => $page['title'],
            'pageDescription' => $page['description'],
            'canonical' => url($page['slug']),
        ]);
    }
}
