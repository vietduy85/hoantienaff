<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class GuideController extends Controller
{
    protected array $guides = [
        'cam-nang-san-sale' => [
            'title' => 'Cẩm nang săn SALE',
            'icon' => '🎉',
            'badge' => 'Khuyên đọc',
            'badge_color' => 'emerald',
            'read_time' => 5,
            'description' => 'Tổng hợp các kinh nghiệm săn SALE dành cho người mới.',
        ],
        'cach-tao-link-hoan-tien' => [
            'title' => 'Cách tạo Link Hoàn Tiền',
            'icon' => '🔗',
            'badge' => 'Quan trọng',
            'badge_color' => 'blue',
            'read_time' => 3,
            'description' => 'Hướng dẫn chi tiết cách tạo link hoàn tiền cho các sàn thương mại điện tử.',
        ],
        'truong-hop-khong-hoan-tien' => [
            'title' => 'Những trường hợp KHÔNG được hoàn tiền',
            'icon' => '⚠',
            'badge' => 'Quan trọng',
            'badge_color' => 'red',
            'read_time' => 4,
            'description' => 'Các trường hợp không đủ điều kiện hoàn tiền bạn cần biết.',
        ],
        'tra-cuu-don-hang' => [
            'title' => 'Tra cứu đơn hàng',
            'icon' => '📦',
            'badge' => null,
            'badge_color' => null,
            'read_time' => 2,
            'description' => 'Hướng dẫn cách tra cứu trạng thái đơn hàng và tiền hoàn.',
        ],
        'huong-dan-rut-tien' => [
            'title' => 'Hướng dẫn rút tiền',
            'icon' => '💰',
            'badge' => null,
            'badge_color' => null,
            'read_time' => 3,
            'description' => 'Các phương thức rút tiền hoàn về tài khoản của bạn.',
        ],
        'cau-hoi-thuong-gap' => [
            'title' => 'Câu hỏi thường gặp',
            'icon' => '❓',
            'badge' => 'FAQ',
            'badge_color' => 'purple',
            'read_time' => null,
            'description' => 'Giải đáp các thắc mắc phổ biến về dịch vụ hoàn tiền.',
        ],
        'thuat-ngu-affiliate' => [
            'title' => 'Thuật ngữ Affiliate',
            'icon' => '📚',
            'badge' => null,
            'badge_color' => null,
            'read_time' => 4,
            'description' => 'Giải thích các thuật ngữ Affiliate Marketing thông dụng.',
        ],
        'meo-san-flash-sale' => [
            'title' => 'Mẹo săn Flash Sale nâng cao',
            'icon' => '🔥',
            'badge' => 'Nâng cao',
            'badge_color' => 'orange',
            'read_time' => 6,
            'description' => 'Kỹ thuật và mẹo săn flash sale hiệu quả từ chuyên gia.',
        ],
    ];

    public function index()
    {
        return view('guide.index', [
            'guides' => $this->guides,
        ]);
    }

    public function show(string $slug)
    {
        if (!isset($this->guides[$slug])) {
            abort(404);
        }

        $path = resource_path("content/guides/{$slug}.md");

        if (!File::exists($path)) {
            abort(404);
        }

        $markdown = File::get($path);
        $html = Str::markdown($markdown);

        return view('guide.show', [
            'guide' => $this->guides[$slug],
            'slug' => $slug,
            'content' => $html,
        ]);
    }
}
