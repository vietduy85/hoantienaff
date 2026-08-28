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
        Schema::table('link_requests', function (Blueprint $table) {
            $table->string('product_link', 2048)->nullable()->change();
            $table->string('product_image', 2048)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('link_requests', function (Blueprint $table) {
            $table->string('product_link', 255)->nullable()->change();
            $table->string('product_image', 255)->nullable()->change();
        });
    }
};
