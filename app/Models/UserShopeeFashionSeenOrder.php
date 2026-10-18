<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Đơn hàng Shopee thuộc danh mục thời trang (hoa hồng 7%) mà user đã xác
 * nhận "đã biết" trong thông báo "cào xu".
 *
 * Bảng chỉ lưu (user_id, order_id) => mỗi lần đồng bộ lại Shopee KHÔNG làm
 * thông báo bật lại cho các đơn đã biết, đồng thời đơn MỚI vẫn xuất hiện.
 */
class UserShopeeFashionSeenOrder extends Model
{
    protected $table = 'user_shopee_fashion_seen_orders';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'order_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
