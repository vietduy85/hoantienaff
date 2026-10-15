<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed URL detection rules ("Chuỗi nhận diện URL").
 *
 * IMPORTANT — backward compatibility:
 * These rows are an ADDITIVE overlay that is evaluated AFTER every rule
 * hard-coded in the application (Shopee / ShopeeFood / Lazada / TikTok / Tiki).
 * The code baseline always wins, so an existing recognised URL keeps exactly
 * its current result. With zero rows (the default after migrating) detection
 * is byte-for-byte identical to before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_detect_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('pattern', 255);
            // substring | hostname
            $table->string('match_type', 20)->default('hostname');
            // Shopee | ShopeeFood | Lazada | TikTok Shop | Tiki | Khác | AccessTrade
            $table->string('platform', 50);
            // shopee_direct | shopeefood_deeplink | lazada_api | tiktok_riohub | accesstrade_api | unsupported
            $table->string('strategy', 50)->default('accesstrade_api');
            // Optional pinned AccessTrade campaign for this domain.
            $table->string('campaign_id', 100)->nullable();
            $table->unsignedInteger('priority')->default(100);
            // active | disabled
            $table->string('status', 20)->default('active');
            $table->text('description')->nullable();
            // code | config | database
            $table->string('source', 20)->default('database');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('platform');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_detect_rules');
    }
};
