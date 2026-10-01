<?php

use App\Services\CreditCard\PolicyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 14/10 — Chuyển trần hoàn tiền mỗi kỳ từ POLICY về BẬC (tier).
 *
 * Trước đây trần "tổng hoàn tối đa mỗi kỳ" (cap 3) nằm ở cột
 * `credit_card_policies.max_cashback_total_per_period` — MỘT con số cho toàn bộ
 * chính sách, bất kể bậc nào đang áp dụng.
 *
 * Từ phiên bản này, trần mỗi kỳ nằm ở TỪNG BẬC
 * (`credit_card_policy_tiers.max_cashback_per_period`): bậc nào được resolve theo
 * tổng chi tiêu cuối kỳ thì dùng trần của chính bậc đó. Cột cũ giữ NGUYÊN (không
 * drop) để không phá dữ liệu lịch sử và luồng user vẫn tương thích.
 *
 * BACKFILL (idempotent):
 *   - Blueprint hệ thống chỉ có MỘT bậc + còn trần policy ⇒ copy trần vào bậc.
 *   - Blueprint nhiều bậc ⇒ KHÔNG backfill (không biết trần thuộc bậc nào), chỉ
 *     ghi nhận để vận hành xử lý sau.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->table('credit_card_policy_tiers', function (Blueprint $table) {
            $table->decimal('max_cashback_per_period', 16, 2)->nullable()
                ->after('max_total_spend')
                ->comment('Trần hoàn tiền của BẬC này trong một kỳ sao kê (VNĐ); NULL = không giới hạn');
        });

        $report = app(PolicyService::class)->backfillSystemBlueprintCaps();

        Log::info('Backfill max_cashback_per_period (single-tier system blueprints).', [
            'backfilled' => $report['backfilled'],
            'skipped_multi_tier_blueprint_ids' => $report['skipped_multi_tier'],
        ]);
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('credit_card_policy_tiers', function (Blueprint $table) {
            $table->dropColumn('max_cashback_per_period');
        });
    }
};
