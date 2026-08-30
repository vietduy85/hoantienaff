<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_order_items', function (Blueprint $table) {
            // TikTok affiliate content/link tracking ID (RioHub content_id).
            // Semantic: "content nào sinh ra đơn" — KHÔNG phải checkout id.
            $table->string('content_id', 64)
                ->nullable()
                ->after('checkout_id')
                ->comment('TikTok content/link tracking ID (RioHub content_id)');

            // Timestamp vòng đời đồng bộ riêng cho TikTok, song song với
            // last_shopee_sync_at (không tái sử dụng field mang tên Shopee).
            $table->dateTime('last_tiktok_sync_at')
                ->nullable()
                ->after('last_shopee_sync_at')
                ->comment('Lần cuối đồng bộ từ TikTok/RioHub');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->dropColumn(['content_id', 'last_tiktok_sync_at']);
        });
    }
};