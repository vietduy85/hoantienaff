<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cho phép đăng ký tài khoản bằng username KHÔNG có email.
 *
 * Cột `users.email` được tạo ở migration gốc với ràng buộc NOT NULL + UNIQUE.
 * Migration này chỉ đổi cột sang NULLABLE; index UNIQUE hiện có được GIỮ NGUYÊN.
 * Trên MySQL/SQLite/PostgreSQL, một UNIQUE index vẫn cho phép nhiều dòng NULL,
 * nên nhiều tài khoản không email có thể tồn tại song song.
 *
 * KHÔNG thay đổi dữ liệu email của bất kỳ tài khoản hiện có nào.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // CẢNH BÁO: down() chỉ chạy được khi KHÔNG còn tài khoản nào có email NULL.
        // Nếu đã có tài khoản không email, cần xử lý dữ liệu trước khi rollback.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
