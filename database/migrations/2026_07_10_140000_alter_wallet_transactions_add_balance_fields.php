<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->decimal('balance_before', 16, 2)->nullable()->after('amount');
            $table->decimal('balance_after', 16, 2)->nullable()->after('balance_before');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'direction', 'status']);
        });

        Schema::table('withdraw_requests', function (Blueprint $table) {
            $table->index(['user_id', 'status']);
        });

        // Make platform nullable for adjustment transactions (no platform)
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->string('platform', 30)->nullable()->change();
        });

        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE wallet_transactions MODIFY COLUMN type ENUM('cashback', 'withdraw', 'promotion', 'bonus', 'referral', 'adjustment', 'refund') NOT NULL");
            DB::statement("ALTER TABLE withdraw_requests MODIFY COLUMN status ENUM('pending', 'approved', 'paid', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->string('platform', 30)->change();
            $table->dropColumn('balance_before');
            $table->dropColumn('balance_after');
            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['user_id', 'direction', 'status']);
        });

        Schema::table('withdraw_requests', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
        });

        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE wallet_transactions MODIFY COLUMN type ENUM('cashback', 'withdraw', 'refund', 'adjustment') NOT NULL");
            DB::statement("ALTER TABLE withdraw_requests MODIFY COLUMN status ENUM('pending', 'approved', 'paid', 'rejected') NOT NULL DEFAULT 'pending'");
        }
    }
};
