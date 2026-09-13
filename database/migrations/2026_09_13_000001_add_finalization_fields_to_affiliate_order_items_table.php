<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finalization (LOCK) support on the shared `affiliate_order_items` table.
 *
 * Phase 1 of the lifecycle finalization work. This migration ONLY adds
 * nullable, backward-compatible columns. It does NOT:
 *
 *   - update/backfill any existing row (historical orders untouched)
 *   - change Shopee / TikTok / Lazada / ShopeeFood behavior
 *   - remove or repurpose `locked_at` (kept for backward compatibility)
 *
 * Semantics (filled in by Phases 2 & 3):
 *
 *   delivered_at          datetime nullable   RAW Lazada `deliveredTime` (Asia/Ho_Chi_Minh).
 *                                             NULL => NEVER finalize, no fallback.
 *   finalized_at          datetime nullable   Timestamp the order LOCKED (cashback frozen).
 *   finalize_gate         string  nullable    Which rule produced the lock:
 *                                             'tiktok_settled' | 'lazada_delivered_10d'
 *   final_cashback_amount decimal  nullable   Frozen cashback actually credited at LOCK.
 *   reversed_at           datetime nullable   Timestamp a valid platform reversal was applied.
 *   tt_order_status       int      nullable   Raw RioHub tt_order_status (TikTok): 100/103/104.
 *   settled_at            datetime nullable   Raw RioHub `settled_at` (informational; may be NULL
 *                                             on valid SETTLED orders, so NEVER used as the anchor).
 *
 * Historical protection: a row whose cashback was already credited BEFORE this
 * lifecycle is identified by the existing completed `cashback` WalletTransaction
 * (reference_type = 'affiliate_order_item'), NOT by these columns. These columns
 * stay NULL on all historical rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->dateTime('delivered_at')->nullable()
                ->comment('Thời điểm giao hàng RAW deliveredTime (Lazada) — NULL thì không finalize');

            $table->dateTime('finalized_at')->nullable()
                ->comment('Thời điểm chốt (LOCK): cashback coi như frozen');

            $table->string('finalize_gate', 40)->nullable()
                ->comment('Rule dùng để LOCK: tiktok_settled | lazada_delivered_10d');

            $table->decimal('final_cashback_amount', 16, 2)->nullable()
                ->comment('Cashback đã đóng băng = số tiền thực credit lúc LOCK');

            $table->dateTime('reversed_at')->nullable()
                ->comment('Thời điểm reversal hợp lệ (returned/cancelled sau LOCK)');

            $table->unsignedSmallInteger('tt_order_status')->nullable()
                ->comment('Raw tt_order_status RioHub (100/103/104) — TikTok');

            $table->dateTime('settled_at')->nullable()
                ->comment('Raw settled_at RioHub (informational; có thể NULL trên SETTLED hợp lệ)');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->dropColumn([
                'delivered_at',
                'finalized_at',
                'finalize_gate',
                'final_cashback_amount',
                'reversed_at',
                'tt_order_status',
                'settled_at',
            ]);
        });
    }
};