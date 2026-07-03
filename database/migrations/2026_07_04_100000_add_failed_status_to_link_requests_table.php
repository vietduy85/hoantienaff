<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE link_requests MODIFY COLUMN status ENUM('pending', 'processing', 'completed', 'rejected', 'failed') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE link_requests MODIFY COLUMN status ENUM('pending', 'processing', 'completed', 'rejected') NOT NULL DEFAULT 'pending'");
    }
};
