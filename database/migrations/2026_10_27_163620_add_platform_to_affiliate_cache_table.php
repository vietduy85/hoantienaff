<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('affiliate_cache', function (Blueprint $table) {
            $table->string('platform', 20)->nullable()->after('item_id');
            $table->index(['platform', 'item_id', 'cache_date'], 'idx_affiliate_cache_platform_item_date');
        });

        // Do NOT guess platform for legacy rows. Leave NULL.
        // If needed later, can be backfilled only with evidence.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliate_cache', function (Blueprint $table) {
            $table->dropIndex('idx_affiliate_cache_platform_item_date');
            $table->dropColumn('platform');
        });
    }
};
