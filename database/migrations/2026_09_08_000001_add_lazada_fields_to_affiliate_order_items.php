<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lazada support on the shared `affiliate_order_items` table.
 *
 * Identity strategy (documented, does NOT touch Shopee/TikTok/ShopeeFood rows):
 *
 *   Shopee / TikTok  : UNIQUE (platform, order_id, item_id)  -> uk_platform_order_item (unchanged)
 *   ShopeeFood       : UNIQUE (platform, shopee_food_line_key)              (unchanged)
 *   Lazada           : UNIQUE (platform, lazada_line_key)                  (new)
 *
 * Lazada line business key is (orderId, subOrderId, sku) — the sub-order item
 * line. item_id is deliberately left NULL on Lazada rows (same policy as
 * ShopeeFood) because two Lazada lines within ONE order can share the same
 * numeric sku/item while differing by subOrderId; populating item_id would
 * collide with the existing uk_platform_order_item unique index that protects
 * Shopee/TikTok rows.
 *
 * The explicit nullable composite key column `lazada_line_key =
 * CONCAT(orderId, ':', subOrderId, ':', sku)` is populated ONLY for
 * platform='Lazada' rows. NULL values never collide in a unique index, so
 * existing rows from other platforms are completely unaffected while Lazada
 * line identity is fully enforced.
 *
 * This strategy works identically on MySQL (utf8mb4) and SQLite (in-memory tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->string('lazada_line_key', 160)->nullable()
                ->comment('Khóa dòng Lazada: orderId:subOrderId:sku (chỉ điền cho platform=Lazada)');
        });

        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->dateTime('last_lazada_sync_at')->nullable()
                ->comment('Lần cuối đồng bộ từ Lazada');
        });

        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->unique(['platform', 'lazada_line_key'], 'uk_lazada_line_key');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->dropUnique('uk_lazada_line_key');
        });

        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->dropColumn(['lazada_line_key', 'last_lazada_sync_at']);
        });
    }
};