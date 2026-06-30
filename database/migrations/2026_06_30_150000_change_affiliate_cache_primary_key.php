<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE affiliate_cache DROP PRIMARY KEY, ADD PRIMARY KEY (item_id, cache_date)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE affiliate_cache DROP PRIMARY KEY, ADD PRIMARY KEY (item_id)');
    }
};
