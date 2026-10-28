<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('affiliate_cache', function (Blueprint $table) {
            $table->decimal('shopee_commission_rate', 6, 4)->nullable()->after('data_source');
            $table->decimal('seller_commission_rate', 6, 4)->nullable()->after('shopee_commission_rate');
            $table->string('commission_rate_source', 30)->nullable()->after('seller_commission_rate');
            $table->string('commission_rate_status', 20)->nullable()->after('commission_rate_source');
            $table->timestamp('commission_rate_checked_at')->nullable()->after('commission_rate_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliate_cache', function (Blueprint $table) {
            $table->dropColumn(['shopee_commission_rate', 'seller_commission_rate', 'commission_rate_source', 'commission_rate_status', 'commission_rate_checked_at']);
        });
    }
};
