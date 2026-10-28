<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('link_requests', function (Blueprint $table) {
            $table->decimal('shopee_commission_rate_at_create', 5, 2)->nullable()->after('cashback_rate');
            $table->decimal('seller_commission_rate_at_create', 5, 2)->nullable()->after('shopee_commission_rate_at_create');
            $table->string('commission_rate_source', 30)->nullable()->after('seller_commission_rate_at_create');
            $table->timestamp('commission_rate_captured_at')->nullable()->after('commission_rate_source');
            $table->string('commission_rate_status', 20)->nullable()->after('commission_rate_captured_at');
        });
    }
    public function down(): void {
        Schema::table('link_requests', function (Blueprint $table) {
            $table->dropColumn(['shopee_commission_rate_at_create', 'seller_commission_rate_at_create', 'commission_rate_source', 'commission_rate_captured_at', 'commission_rate_status']);
        });
    }
};

