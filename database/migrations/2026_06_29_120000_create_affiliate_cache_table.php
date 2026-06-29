<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_cache', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->primary();
            $table->date('cache_date');
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('product_name')->nullable();
            $table->unsignedBigInteger('product_price')->nullable();
            $table->unsignedBigInteger('seller_commission')->nullable();
            $table->unsignedBigInteger('shopee_commission')->nullable();
            $table->unsignedBigInteger('estimated_cashback')->nullable();
            $table->decimal('user_estimated_cashback', 15, 2)->nullable();
            $table->decimal('cashback_rate', 15, 2)->nullable();
            $table->string('affiliate_url', 2048)->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('sales')->nullable();
            $table->string('product_image')->nullable();
            $table->string('product_link')->nullable();
            $table->string('shop_name')->nullable();
            $table->boolean('is_xtra')->default(false);
            $table->string('data_source')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_cache');
    }
};
