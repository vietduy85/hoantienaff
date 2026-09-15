<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('completed_orders')->default(0);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('reward_amount')->default(20000);
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();

            $table->index('referrer_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};