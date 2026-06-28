<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id')->nullable()->after('item_id')->index();
            $table->string('product_link')->nullable()->after('product_price');
        });
    }

    public function down(): void
    {
        Schema::table('link_requests', function (Blueprint $table) {
            $table->dropColumn(['shop_id', 'product_link']);
        });
    }
};
