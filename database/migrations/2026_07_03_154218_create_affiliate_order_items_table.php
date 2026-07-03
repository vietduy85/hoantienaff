<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_order_items', function (Blueprint $table) {
            $table->id();

            // === Fields from Shopee Affiliate Commission Report Export ===

            // -- Order & Checkout --
            $table->string('order_id', 50)->comment('Shopee order ID (ID đơn hàng)');
            $table->string('order_status', 50)->comment('Trạng thái đặt hàng');
            $table->string('checkout_id', 30)->comment('Checkout ID');

            // -- Timestamps from Shopee --
            $table->dateTime('ordered_at')->nullable()->comment('Thời Gian Đặt Hàng');
            $table->dateTime('completed_at')->nullable()->comment('Thời gian hoàn thành');
            $table->dateTime('clicked_at')->nullable()->comment('Thời gian Click');

            // -- Shop --
            $table->string('shop_name', 200)->comment('Tên Shop');
            $table->unsignedBigInteger('shop_id')->comment('Shop ID');
            $table->string('shop_type', 50)->nullable()->comment('Loại Shop (Shopee Mall, Preferred, etc.)');

            // -- Item --
            $table->unsignedBigInteger('item_id')->comment('Item ID');
            $table->string('item_name', 500)->comment('Tên Item');
            $table->unsignedBigInteger('model_id')->comment('ID Model (SKU)');
            $table->string('product_type', 50)->nullable()->comment('Loại sản phẩm (Normal Product, etc.)');
            $table->string('promotion_id', 50)->nullable()->comment('Promotion ID');

            // -- Categories --
            $table->string('category_l1', 100)->nullable()->comment('L1 Danh mục toàn cầu');
            $table->string('category_l2', 100)->nullable()->comment('L2 Danh mục toàn cầu');
            $table->string('category_l3', 100)->nullable()->comment('L3 Danh mục toàn cầu');

            // -- Pricing & Quantity --
            $table->decimal('item_price', 16, 2)->comment('Giá (₫)');
            $table->integer('quantity')->comment('Số lượng');
            $table->decimal('order_amount', 16, 2)->comment('Giá trị đơn hàng (₫)');
            $table->decimal('refund_amount', 16, 2)->default(0)->comment('Số tiền hoàn trả (₫)');

            // -- Commission Type --
            $table->string('commission_type', 30)->comment('Loại Hoa hồng (Shopee Comm / XTRA Comm)');
            $table->string('campaign_partner', 200)->nullable()->comment('Đối tác chiến dịch');

            // -- Commission Rates & Amounts --
            $table->decimal('shopee_commission_rate', 5, 2)->comment('Tỷ lệ hoa hồng Shopee (%)');
            $table->decimal('shopee_commission', 16, 2)->comment('Hoa hồng Shopee trên sản phẩm (₫)');
            $table->decimal('seller_commission_rate', 5, 2)->comment('Tỷ lệ hoa hồng người bán (%)');
            $table->decimal('xtra_commission', 16, 2)->default(0)->comment('Hoa hồng Xtra trên sản phẩm (₫)');
            $table->decimal('total_product_commission', 16, 2)->comment('Tổng hoa hồng sản phẩm (₫)');
            $table->decimal('order_commission_shopee', 16, 2)->comment('Hoa hồng đơn hàng từ Shopee (₫)');
            $table->decimal('order_commission_seller', 16, 2)->comment('Hoa hồng đơn hàng từ Người bán (₫)');
            $table->decimal('total_order_commission', 16, 2)->comment('Tổng hoa hồng đơn hàng (₫)');

            // -- MCN --
            $table->string('mcn_name', 200)->nullable()->comment('Tên MNC/MCN đã liên kết');
            $table->string('mcn_contract_code', 100)->nullable()->comment('Mã hợp đồng MCN');
            $table->decimal('mcn_management_fee_rate', 5, 2)->default(0)->comment('Mức phí quản lý MCN (%)');
            $table->decimal('mcn_management_fee', 16, 2)->default(0)->comment('Phí quản lý MCN (₫)');

            // -- Net Commission --
            $table->decimal('agreed_commission_rate', 5, 2)->comment('Mức hoa hồng tiếp thị liên kết theo thỏa thuận (%)');
            $table->decimal('net_commission', 16, 2)->comment('Hoa hồng ròng tiếp thị liên kết (₫)');

            // -- Status & Notes --
            $table->string('affiliate_status', 50)->comment('Trạng thái sản phẩm liên kết');
            $table->text('product_note')->nullable()->comment('Ghi chú sản phẩm');
            $table->string('attribute_type', 100)->nullable()->comment('Loại thuộc tính');
            $table->string('buyer_status', 50)->nullable()->comment('Trạng thái người mua');

            // -- Sub IDs & Channel --
            $table->string('sub_id1', 100)->nullable()->comment('Sub ID 1');
            $table->string('sub_id2', 100)->nullable()->comment('Sub ID 2');
            $table->string('sub_id3', 100)->nullable()->comment('Sub ID 3');
            $table->string('sub_id4', 100)->nullable()->comment('Sub ID 4');
            $table->string('sub_id5', 100)->nullable()->comment('Sub ID 5');
            $table->string('channel', 50)->nullable()->comment('Kênh (Facebook, Websites, etc.)');

            // === System Fields ===

            // Platform: hỗ trợ đa nền tảng sau này (Shopee, Lazada, TikTok Shop)
            $table->string('platform', 30)->default('Shopee');

            // User mapping: sub_id1 chứa username → lookup users.username → ghi user_id
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('username', 100)->nullable();
            $table->index('username');

            // Cashback tính từ net_commission theo business rule
            $table->decimal('cashback_rate', 5, 2)->nullable()->comment('Tỷ lệ cashback (50/60/70)');
            $table->decimal('cashback_amount', 16, 2)->nullable()->comment('Tiền user thực nhận');

            // Import tracking
            $table->string('import_batch', 20)->comment('Mã lần import (Ymd_His)');
            $table->string('source_file', 255)->nullable()->comment('Tên file CSV gốc');
            $table->dateTime('first_imported_at')->nullable()->comment('Lần đầu xuất hiện trong hệ thống');
            $table->dateTime('last_shopee_sync_at')->nullable()->comment('Lần cuối đồng bộ từ Shopee');
            $table->dateTime('locked_at')->nullable()->comment('Thời điểm khóa (đơn hoàn thành, không sync nữa)');

            $table->timestamps();

            // === Indexes ===
            $table->unique(['order_id', 'item_id'], 'uk_order_item');
            $table->index('user_id');
            $table->index('platform');
            $table->index('order_status');
            $table->index('ordered_at');
            $table->index('sub_id1');
            $table->index('import_batch');
            $table->index('locked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_order_items');
    }
};
