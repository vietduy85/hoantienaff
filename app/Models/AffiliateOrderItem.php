<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AffiliateOrderItem extends Model
{
    use HasFactory;

    public const STATUS_COMPLETED = 'Hoàn thành';

    protected $fillable = [
        // Shopee fields
        'order_id',
        'order_status',
        'checkout_id',
        'content_id',
        'ordered_at',
        'completed_at',
        'clicked_at',
        'shop_name',
        'shop_id',
        'shop_type',
        'item_id',
        'item_name',
        'model_id',
        'product_type',
        'promotion_id',
        'category_l1',
        'category_l2',
        'category_l3',
        'item_price',
        'quantity',
        'order_amount',
        'refund_amount',
        'commission_type',
        'campaign_partner',
        'shopee_commission_rate',
        'shopee_commission',
        'seller_commission_rate',
        'xtra_commission',
        'total_product_commission',
        'order_commission_shopee',
        'order_commission_seller',
        'total_order_commission',
        'mcn_name',
        'mcn_contract_code',
        'mcn_management_fee_rate',
        'mcn_management_fee',
        'agreed_commission_rate',
        'net_commission',
        'affiliate_status',
        'product_note',
        'attribute_type',
        'buyer_status',
        'sub_id1',
        'sub_id2',
        'sub_id3',
        'sub_id4',
        'sub_id5',
        'channel',
        // System fields
        'platform',
        'user_id',
        'username',
        'cashback_rate',
        'cashback_amount',
        'import_batch',
        'source_file',
        'first_imported_at',
        'last_shopee_sync_at',
        'last_tiktok_sync_at',
        'last_shopeefood_sync_at',
        'shopee_food_line_key',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'completed_at' => 'datetime',
            'clicked_at' => 'datetime',
            'item_price' => 'decimal:2',
            'order_amount' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'shopee_commission_rate' => 'decimal:2',
            'shopee_commission' => 'decimal:2',
            'seller_commission_rate' => 'decimal:2',
            'xtra_commission' => 'decimal:2',
            'total_product_commission' => 'decimal:2',
            'order_commission_shopee' => 'decimal:2',
            'order_commission_seller' => 'decimal:2',
            'total_order_commission' => 'decimal:2',
            'mcn_management_fee_rate' => 'decimal:2',
            'mcn_management_fee' => 'decimal:2',
            'agreed_commission_rate' => 'decimal:2',
            'net_commission' => 'decimal:2',
            'cashback_rate' => 'decimal:2',
            'cashback_amount' => 'decimal:2',
            'first_imported_at' => 'datetime',
            'last_shopee_sync_at' => 'datetime',
            'last_tiktok_sync_at' => 'datetime',
            'last_shopeefood_sync_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
