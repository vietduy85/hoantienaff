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
        Schema::table('link_requests', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable()->after('is_pinned');
        });
    }

    public function down(): void
    {
        Schema::table('link_requests', function (Blueprint $table) {
            $table->dropColumn('pinned_at');
        });
    }
};
