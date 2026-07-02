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
        Schema::table('users', function (Blueprint $table) {
            $table->string('zalo', 50)->nullable()->after('phone');
            $table->string('bank_account_name', 255)->nullable()->after('zalo');
            $table->string('bank_account_number', 50)->nullable()->after('bank_account_name');
            $table->string('bank_name', 255)->nullable()->after('bank_account_number');
            $table->string('bank_branch', 255)->nullable()->after('bank_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['zalo', 'bank_account_name', 'bank_account_number', 'bank_name', 'bank_branch']);
        });
    }
};
