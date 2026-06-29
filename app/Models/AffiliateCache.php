<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliateCache extends Model
{
    protected $table = 'affiliate_cache';

    protected $primaryKey = 'item_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'item_id',
        'cache_date',
        'shop_id',
        'product_name',
        'product_price',
        'seller_commission',
        'shopee_commission',
        'estimated_cashback',
        'user_estimated_cashback',
        'cashback_rate',
        'affiliate_url',
        'last_affiliate_created_at',
        'rating',
        'sales',
        'product_image',
        'product_link',
        'shop_name',
        'is_xtra',
        'data_source',
    ];

    protected function casts(): array
    {
        return [
            'cache_date' => 'date:Y-m-d',
            'estimated_cashback' => 'integer',
            'user_estimated_cashback' => 'decimal:2',
            'cashback_rate' => 'decimal:2',
            'is_xtra' => 'boolean',
            'rating' => 'decimal:2',
            'last_affiliate_created_at' => 'datetime',
        ];
    }
}
