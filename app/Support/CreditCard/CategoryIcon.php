<?php

namespace App\Support\CreditCard;

use App\Models\CreditCard\Category;

/**
 * Bản đồ biểu tượng (emoji) cho danh mục chi tiêu.
 *
 * Đây thuần là lớp trình bày (presentation): icon KHÔNG được lưu vào DB,
 * chỉ tính trước dựa trên `slug` của danh mục hệ thống hoặc quy ra mặc định
 * cho danh mục do người dùng tạo. Muốn đổi ảnh thì sửa ở đây, không cần
 * migration.
 *
 * Các slug khớp với danh sách 19 danh mục hệ thống trong `CreditCardSeeder`.
 */
final class CategoryIcon
{
    /**
     * @var array<string, string>
     */
    private const BY_SLUG = [
        'am-thuc-an-uong' => '🍜',
        'sieu-thi-tien-loi' => '🛒',
        'mua-sam-truc-tuyen' => '🛍️',
        'xang-dau' => '⛽',
        'goi-xe-di-chuyen' => '🚗',
        've-may-bay-du-lich' => '✈️',
        'khach-san-luu-tru' => '🏨',
        'bao-hiem' => '🛡️',
        'y-te-benh-vien' => '🏥',
        'giao-duc-hoc-phi' => '🎓',
        'hoa-don-dien-nuoc-internet' => '💡',
        'chi-tieu-nuoc-ngoai' => '💱',
        'ung-dung-so-dich-vu-giai-tri' => '🎬',
        'thoi-trang' => '👕',
        'shopee' => '🛍️',
        'tiki' => '📦',
        'lazada' => '📦',
        'tiktok' => '🎵',
        'san-thuong-mai-dien-tu' => '🛒',
    ];

    public const DEFAULT = '🏷️';

    public static function for(?Category $category): string
    {
        if ($category === null) {
            return self::DEFAULT;
        }

        if ($category->isSystem() && isset(self::BY_SLUG[$category->slug])) {
            return self::BY_SLUG[$category->slug];
        }

        return self::DEFAULT;
    }
}
