<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 12)->unique()->nullable()->after('remember_token');
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete()->after('referral_code');
            $table->decimal('wallet_balance', 15, 2)->default(0)->after('referred_by');
            $table->decimal('total_earned', 15, 2)->default(0)->after('wallet_balance');
            $table->decimal('total_withdrawn', 15, 2)->default(0)->after('total_earned');
            $table->string('phone', 20)->nullable()->after('total_withdrawn');
            $table->string('avatar')->nullable()->after('phone');
            $table->enum('status', ['active', 'suspended'])->default('active')->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['referred_by']);
            $table->dropColumn([
                'referral_code',
                'referred_by',
                'wallet_balance',
                'total_earned',
                'total_withdrawn',
                'phone',
                'avatar',
                'status',
            ]);
        });
    }
};
