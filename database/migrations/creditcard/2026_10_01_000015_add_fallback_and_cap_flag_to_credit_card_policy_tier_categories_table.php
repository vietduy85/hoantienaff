<?php

use App\Services\CreditCard\CategoryRuleService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 15/10 — Rule Fallback "📦 Các danh mục còn lại" + cờ tính vào trần Bậc.
 *
 * Mỗi BẬC (tier) bắt buộc có ĐÚNG MỘT rule fallback:
 *   - `scope_type = 'other'`, `category_id = NULL`, `cashback_percent = 0%`
 *   - `counts_toward_tier_cap = false` — không dùng vào trần hoàn của Bậc / kỳ.
 *
 * Rule "danh mục cụ thể" giữ nguyên hành vi cũ:
 *   - `scope_type = 'category'`, `category_id` bắt buộc,
 *   - `counts_toward_tier_cap = true` (mặc định) — dùng vào trần của Bậc.
 *
 * Cột `category_id` được nới thành NULLABLE cho fallback.
 *
 * BACKFILL (idempotent): với mỗi tier CHƯA có rule `other`, tạo đúng một fallback
 * mặc định. KHÔNG sửa bất kỳ rule hiện có (category_id / % / cap / min / spend /
 * is_enabled / note đều giữ nguyên). Chỉ cần chạy lại là bỏ qua.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->table('credit_card_policy_tier_categories', function (Blueprint $table) {
            $table->string('scope_type', 20)->default('category')
                ->after('category_id')
                ->comment('category = danh mục cụ thể; other = fallback "Các danh mục còn lại"');
            $table->boolean('counts_toward_tier_cap')->default(true)
                ->after('scope_type')
                ->comment('Rule này có tính vào trần hoàn của Bậc / kỳ không (false = không); fallback mặc định false');
            $table->foreignId('category_id')->nullable()->change();
        });

        $report = app(CategoryRuleService::class)->backfillFallbackRules();

        Log::info('Backfill fallback rule "các danh mục còn lại" cho tier.', [
            'backfilled_tiers' => $report['backfilled'],
            'existing_fallbacks' => $report['existing'],
            'anomalies' => $report['anomalies'],
            'manual_review_tier_ids' => $report['manual_review'],
        ]);
    }

    public function down(): void
    {
        // Xoá rule fallback trước khi khôi phục `category_id` NOT NULL.
        DB::connection($this->connection)
            ->table('credit_card_policy_tier_categories')
            ->where('scope_type', 'other')
            ->delete();

        Schema::connection($this->connection)->table('credit_card_policy_tier_categories', function (Blueprint $table) {
            $table->foreignId('category_id')->change();
            $table->dropColumn('counts_toward_tier_cap');
            $table->dropColumn('scope_type');
        });
    }
};
