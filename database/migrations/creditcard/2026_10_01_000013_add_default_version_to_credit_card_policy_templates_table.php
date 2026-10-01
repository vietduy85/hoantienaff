<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 000013 — DEFAULT VERSION cho System Policy Template.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO CÓ MIGRATION NÀY
 * ---------------------------------------------------------------------------
 * Khi admin tạo thêm version mới cho một chính sách hệ thống, "current/latest"
 * và "version mà user MỚI nhận được khi clone" không nhất thiết trùng nhau:
 * admin có thể vẫn muốn user mới nhận version cũ (vd 10% đang chạy) đến khi
 * version mới (8%) được quyết định hạ mức.
 *
 * `default_version_id` trỏ tới bản ghi `credit_card_policies` (blueprint) được
 * dùng làm NGUỒN khi user clone chính sách — thay cho khái niệm "latest" trước
 * đây. Khi chưa đặt default, hệ thống tự fallback về current/latest blueprint.
 *
 * Chỉ 1 cột scalar ⇒ luôn chỉ có MỘT default, không cần unique index phức tạp.
 * FK nullOnDelete: nếu blueprint bị xoá hợp lệ, default tự trở về fallback.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->table('credit_card_policy_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('default_version_id')->nullable()->after('is_active')
                ->comment('Blueprint mặc định (credit_card_policies.user_card_id = NULL) làm nguồn clone cho user mới');

            $table->foreign('default_version_id')
                ->references('id')
                ->on('credit_card_policies')
                ->nullOnDelete();

            $table->index('default_version_id', 'credit_card_templates_default_version_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('credit_card_policy_templates', function (Blueprint $table) {
            $table->dropForeign(['default_version_id']);
            $table->dropIndex('credit_card_templates_default_version_index');
            $table->dropColumn('default_version_id');
        });
    }
};
