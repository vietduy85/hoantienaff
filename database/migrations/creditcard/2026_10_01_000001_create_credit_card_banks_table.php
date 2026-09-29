<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1/10 — Bank (ngân hàng phát hành thẻ).
 *
 * Chạy trên connection `creditcard` (database `hoantien_creditcard`).
 * Migration này nằm trong thư mục con `database/migrations/creditcard/`
 * nên KHÔNG bị `php artisan migrate` (main DB) chạy.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_banks', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);
            $table->string('short_name', 50)->nullable();
            $table->string('slug', 150)->unique();
            $table->string('logo')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_banks');
    }
};
