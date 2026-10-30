<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Index đơn lẻ bị index tổng hợp bao phủ (tiền tố trái) nên không còn cần. */
    private const REDUNDANT = [
        'link_requests' => ['link_requests_user_id_index', 'link_requests_status_index', 'link_requests_platform_index'],
        'wallet_transactions' => [
            'wallet_transactions_reference_id_index',
            'wallet_transactions_type_index',
            'wallet_transactions_status_index',
            'wallet_transactions_reference_type_reference_id_index',
        ],
    ];

    public function up(): void
    {
        // Tỉ lệ lưu dạng phân số: decimal(5,2) làm tròn 0.025 -> 0.03; mở rộng sang 4 chữ số thập phân.
        Schema::table('link_requests', function (Blueprint $table) {
            $table->decimal('shopee_commission_rate_at_create', 6, 4)->nullable()->change();
            $table->decimal('seller_commission_rate_at_create', 6, 4)->nullable()->change();
        });

        Schema::table('link_requests', function (Blueprint $table) {
            if (! Schema::hasIndex('link_requests', 'link_requests_user_created_idx')) {
                $table->index(['user_id', 'created_at'], 'link_requests_user_created_idx');
            }
            if (! Schema::hasIndex('link_requests', 'link_requests_user_item_created_idx')) {
                $table->index(['user_id', 'item_id', 'created_at'], 'link_requests_user_item_created_idx');
            }
        });

        Schema::table('affiliate_order_items', function (Blueprint $table) {
            if (! Schema::hasIndex('affiliate_order_items', 'aoi_platform_user_ordered_idx')) {
                $table->index(['platform', 'user_id', 'ordered_at'], 'aoi_platform_user_ordered_idx');
            }
        });

        foreach (self::REDUNDANT as $tableName => $indexes) {
            foreach ($indexes as $index) {
                if (Schema::hasIndex($tableName, $index)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($index));
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('link_requests', function (Blueprint $table) {
            $table->index('user_id', 'link_requests_user_id_index');
            $table->index('status', 'link_requests_status_index');
            $table->index('platform', 'link_requests_platform_index');
            $table->dropIndex('link_requests_user_created_idx');
            $table->dropIndex('link_requests_user_item_created_idx');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->index('reference_id', 'wallet_transactions_reference_id_index');
            $table->index('type', 'wallet_transactions_type_index');
            $table->index('status', 'wallet_transactions_status_index');
            $table->index(['reference_type', 'reference_id'], 'wallet_transactions_reference_type_reference_id_index');
        });

        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->dropIndex('aoi_platform_user_ordered_idx');
        });

        // decimal(6,4) -> (5,2) có thể mất độ chính xác nên giữ nguyên khi rollback.
    }
};
