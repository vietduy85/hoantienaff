<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Provenance of the Shopee/seller commission rates on an order item.
     * Rows whose rates came from the CSV import keep source/confidence NULL
     * (treated as trusted). Rows filled automatically carry the source
     * (snapshot|cache|product-data) and a confidence (exact|estimate).
     */
    public function up(): void
    {
        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->string('commission_rate_source', 30)->nullable()->after('seller_commission_rate');
            $table->string('commission_rate_confidence', 12)->nullable()->after('commission_rate_source');
            $table->timestamp('commission_rate_checked_at')->nullable()->after('commission_rate_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->dropColumn(['commission_rate_source', 'commission_rate_confidence', 'commission_rate_checked_at']);
        });
    }
};
