<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_news', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50);
            $table->string('category', 50)->default('other');
            $table->string('source_id', 191)->nullable();
            $table->string('source_type', 20)->default('auto');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('image_url')->nullable();
            $table->text('mobile_image_url')->nullable();
            $table->text('landing_url')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('raw_data')->nullable();
            $table->timestamps();

            // MySQL/SQLite allow multiple NULLs in a unique index, so manual
            // rows (source_id = null) never collide with each other while auto
            // rows are deduplicated per source.
            $table->unique(['source', 'source_id']);
            $table->index(['category', 'is_active', 'sort_order'], 'promotion_news_category_active_sort_index');
            $table->index(['source', 'is_active', 'sort_order'], 'promotion_news_source_active_sort_index');
            $table->index(['start_at', 'end_at'], 'promotion_news_window_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_news');
    }
};
