<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_requests', function (Blueprint $table) {
            $table->decimal('user_estimated_cashback', 15, 2)->nullable()->after('estimated_cashback');
            $table->decimal('cashback_rate', 5, 2)->nullable()->after('user_estimated_cashback');
        });
    }

    public function down(): void
    {
        Schema::table('link_requests', function (Blueprint $table) {
            $table->dropColumn(['user_estimated_cashback', 'cashback_rate']);
        });
    }
};
