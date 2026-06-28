<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable()->after('id')->index();
            $table->string('product_name')->nullable()->after('item_id');
            $table->unsignedBigInteger('product_price')->nullable()->after('product_name');
            $table->unsignedBigInteger('seller_commission')->nullable()->after('estimated_cashback');
            $table->unsignedBigInteger('shopee_commission')->nullable()->after('seller_commission');
            $table->decimal('rating', 3, 2)->nullable()->after('shopee_commission');
            $table->boolean('is_xtra')->default(false)->after('rating');
            $table->string('product_image')->nullable()->after('is_xtra');
            $table->string('shop_name')->nullable()->after('product_image');
            $table->unsignedInteger('sales')->nullable()->after('shop_name');
            $table->string('data_source')->nullable()->after('sales');
        });
    }

    public function down(): void
    {
        Schema::table('link_requests', function (Blueprint $table) {
            $table->dropColumn([
                'item_id',
                'product_name',
                'product_price',
                'seller_commission',
                'shopee_commission',
                'rating',
                'is_xtra',
                'product_image',
                'shop_name',
                'sales',
                'data_source',
            ]);
        });
    }
};
