<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('campaign_categories')->nullOnDelete();
            $table->enum('type', ['store', 'product']);
            $table->string('name', 200);
            $table->string('slug', 255)->unique();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->enum('cashback_type', ['percentage', 'fixed']);
            $table->decimal('cashback_value', 10, 2);
            $table->enum('commission_type', ['percentage', 'fixed']);
            $table->decimal('commission_value', 10, 2);
            $table->decimal('affiliate_share', 5, 2)->default(40.00);
            $table->string('url');
            $table->string('tracking_url')->nullable();
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->integer('sort_order')->default(0);
            $table->enum('status', ['draft', 'active', 'paused', 'expired'])->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index('merchant_id');
            $table->index('category_id');
            $table->index('status');
            $table->index('type');
            $table->index('is_featured');
            $table->index('is_verified');
            $table->index('sort_order');
            $table->index(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
