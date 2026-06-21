<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_url', 2048);
            $table->string('platform', 50);
            $table->string('affiliate_url', 2048)->nullable();
            $table->decimal('estimated_cashback', 15, 2)->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'rejected'])->default('pending');
            $table->text('notes')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('platform');
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'is_pinned']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_requests');
    }
};
