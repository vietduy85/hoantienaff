<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45);
            $table->text('user_agent');
            $table->string('referrer_url')->nullable();
            $table->timestamp('clicked_at');
            $table->boolean('converted')->default(false);
            $table->timestamps();

            $table->index('affiliate_id');
            $table->index('member_id');
            $table->index('clicked_at');
            $table->index('ip_address');
            $table->index('converted');
            $table->index(['campaign_id', 'clicked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clicks');
    }
};
