<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the RAW Lazada conversion `status` on each row (Phase 3).
 *
 * Additive, nullable, backward compatible — identical policy to the Phase 1
 * migration: NO backfill, NO rewrite of existing rows, other platforms
 * untouched.
 *
 * Why this exists: the sync layer maps Lazada statuses onto the shared
 * Vietnamese vocabulary (affiliate_status / order_status), but "unknown"
 * statuses must NEVER be finalized by affiliate:lazada-finalize. Only rows
 * whose raw status is documented-confirmed 'fulfilled' / 'delivered' are
 * eligible for the 10-day finalization. This mirrors the TikTok precedent where
 * the raw tt_order_status is kept alongside the mapped status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->string('lazada_raw_status', 100)->nullable()
                ->comment('Raw Lazada conversion status (fulfilled/delivered/returned/rejected/cancelled/...)');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->dropColumn('lazada_raw_status');
        });
    }
};