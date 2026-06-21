<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('click_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('affiliate_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('order_id', 100)->nullable();
            $table->decimal('order_amount', 15, 2);
            $table->decimal('cashback_amount', 15, 2);
            $table->decimal('commission_amount', 15, 2);
            $table->decimal('affiliate_commission', 15, 2)->default(0);
            $table->enum('status', ['pending', 'approved', 'confirmed', 'cancelled', 'refunded'])->default('pending');
            $table->timestamp('confirmed_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('click_id');
            $table->index('campaign_id');
            $table->index('member_id');
            $table->index('affiliate_id');
            $table->index('order_id');
            $table->index('status');
            $table->index('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
