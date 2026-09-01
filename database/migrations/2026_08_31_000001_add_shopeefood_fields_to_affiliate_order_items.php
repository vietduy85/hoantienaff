<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ShopeeFood support on the shared `affiliate_order_items` table.
 *
 * Identity strategy (documented, does NOT touch Shopee/TikTok rows):
 *
 *   Shopee / TikTok  : UNIQUE (platform, order_id, item_id)  -> uk_platform_order_item (unchanged)
 *   ShopeeFood       : UNIQUE (platform, shopee_food_line_key)
 *
 * ShopeeFood line business key is (checkout_id, promotion_id). Because item_id
 * may repeat within the same checkout (size/topping/variant), item_id is NOT a
 * valid business key for ShopeeFood and is allowed to be NULL.
 *
 * To keep both identities on one table without a single global unique index
 * that could conflict with existing Shopee/TikTok rows (e.g. two Shopee items
 * in the same checkout may share the same promotion_id, and TikTok stores an
 * empty-string checkout_id), we store an explicit nullable composite key column
 * `shopee_food_line_key = CONCAT(checkout_id, ':', promotion_id)` that is
 * populated ONLY for ShopeeFood rows. NULL values never collide in a unique
 * index, so existing Shopee/TikTok records are completely unaffected while
 * ShopeeFood item identity is fully enforced by (platform, shopee_food_line_key).
 *
 * This strategy works identically on MySQL (utf8mb4) and SQLite (in-memory tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Relax item_id so ShopeeFood can store NULL (item_id is not a valid
        // business key for ShopeeFood lines). Shopee/TikTok rows keep non-null
        // values, so uk_platform_order_item continues to protect them.
        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable()->change();
        });

        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->dateTime('last_shopeefood_sync_at')->nullable()
                ->comment('Lần cuối đồng bộ từ ShopeeFood');

            $table->string('shopee_food_line_key', 120)->nullable()
                ->comment('Khóa dòng ShopeeFood: checkout_id:promotion_id (chỉ điền cho platform=ShopeeFood)');
        });

        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->unique(['platform', 'shopee_food_line_key'], 'uk_shopeefood_item_key');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->dropUnique('uk_shopeefood_item_key');
        });

        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->dropColumn(['last_shopeefood_sync_at', 'shopee_food_line_key']);
        });

        // item_id is intentionally left nullable on rollback if NULL rows exist;
        // restoring NOT NULL would fail. Documented in the migration comment.
        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable()->change();
        });
    }
};
