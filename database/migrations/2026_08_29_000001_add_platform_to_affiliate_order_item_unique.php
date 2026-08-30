<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->dropUnique('uk_order_item');
        });

        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->unique(['platform', 'order_id', 'item_id'], 'uk_platform_order_item');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->dropUnique('uk_platform_order_item');
        });

        Schema::table('affiliate_order_items', function (Blueprint $table) {
            $table->unique(['order_id', 'item_id'], 'uk_order_item');
        });
    }
};
