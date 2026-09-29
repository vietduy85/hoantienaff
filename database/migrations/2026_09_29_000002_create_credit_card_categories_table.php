<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_categories', function (Blueprint $table) {
            $table->id();

            // === Identity ===
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->text('description')->nullable();

            // === Status ===
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_categories');
    }
};
