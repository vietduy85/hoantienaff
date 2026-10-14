<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sửa dữ liệu hiển thị SAI của blueprint "MSB Online Visa" trên màn hình Tra cứu thẻ.
 *
 * HIỆN TƯỢNG (điều tra ngày 2026-10-14):
 *   Tra cứu thẻ → "MSB Online Visa" hiển thị:
 *     - Chi tiêu tối thiểu 3.000.000đ  (ĐÚNG)
 *     - "Trần hoàn tiền mỗi kỳ: 800.000 đ"  (SAI — nguồn chính thức là 300.000đ/kỳ)
 *     - Dòng ghi chú: 'Blueprint version 1 của template "MB Ultimate JCB".'  (SAI)
 *
 * NGUYÊN NHÂN:
 *   Template #9 (slug `msb-online-visa`) được tạo bằng luồng "sao chép chính sách
 *   hệ thống" từ template "MB Ultimate JCB" (#3). `PolicyCloneService::
 *   createSystemPolicyFromEditor()` chép NGUYÊN VĂN `note` và
 *   `max_cashback_total_per_period` của blueprint nguồn sang bản clone
 *   (PolicyCloneService.php dòng 521-527). Vì blueprint nguồn MB Ultimate JCB có
 *   trần cấp-policy 800.000đ và note nhắc "MB Ultimate JCB", nên bản sao của MSB
 *   Online Visa thừa hưởng cả hai giá trị sai.
 *
 * PHẠM VI SỬA: CHỈ template "MSB Online Visa" (định vị theo slug + scope, KHÔNG
 * hard-code id). Các template khác cùng dính lỗi sao chép tương tự KHÔNG nằm trong
 * phạm vi migration này (đã ghi nhận trong báo cáo điều tra kèm danh sách).
 *
 * AN TOÀN:
 *   - `note` chỉ đổi KHI còn nhắc tới "MB Ultimate JCB".
 *   - `max_cashback_total_per_period` chỉ đổi KHI ĐANG bằng 800.000 (giá trị sao
 *     chép từ MB) → không đè giá trị admin đã cố ý đặt.
 *   - Chạy lại an toàn (idempotent): điều kiện tự triệt tiêu sau lần chạy đầu.
 *   - Bỏ qua khi template chưa tồn tại (schema test rỗng).
 *   - KHÔNG đụng bảng bậc/rule (`credit_card_policy_tiers`,
 *     `credit_card_policy_tier_categories`) ⇒ KHÔNG đổi tỷ lệ/điều kiện engine.
 *
 * GHI CHÚ KỸ THUẬT: `max_cashback_total_per_period` ở cấp policy là cột LEGACY
 * (trần thật nằm ở TỪNG BẬC — `credit_card_policy_tiers.max_cashback_per_period`,
 * xem `PolicyService::backfillSystemBlueprintCaps()`). Engine cashback KHÔNG đọc
 * cột này; nó chỉ được `CardPolicyLookupService::summarize()` đọc để HIỂN THỊ.
 * Vì vậy sửa cột này chỉ ảnh hưởng phần hiển thị, không ảnh hưởng tính hoàn tiền.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    private const SLUG = 'msb-online-visa';

    /**
     * Giá trị trần bị sao chép nhầm từ blueprint "MB Ultimate JCB". So sánh dạng
     * SỐ vì driver SQLite trả cột decimal về dạng số (800000) còn MySQL trả chuỗi
     * ('800000.00').
     */
    private const STALE_TOTAL_CAP = 800000.0;

    /** Trần hoàn/ kỳ theo nguồn chính thức MSB Visa Online. */
    private const CORRECT_TOTAL_CAP = '300000.00';

    private const STALE_NOTE_FRAGMENT = 'MB Ultimate JCB';

    private const CORRECT_NOTE = 'Chính sách hệ thống MSB Online Visa.';

    public function up(): void
    {
        $connection = DB::connection($this->connection);

        if (! $connection->getSchemaBuilder()->hasTable('credit_card_policy_templates')) {
            return;
        }

        $templateId = $connection->table('credit_card_policy_templates')
            ->where('scope', 'system')
            ->where('slug', self::SLUG)
            ->value('id');

        if ($templateId === null) {
            return;
        }

        $blueprints = $connection->table('credit_card_policies')
            ->where('template_id', $templateId)
            ->whereNull('user_card_id')
            ->get();

        foreach ($blueprints as $blueprint) {
            $updates = [];

            if (is_string($blueprint->note) && str_contains($blueprint->note, self::STALE_NOTE_FRAGMENT)) {
                $updates['note'] = self::CORRECT_NOTE;
            }

            if ($blueprint->max_cashback_total_per_period !== null
                && (float) $blueprint->max_cashback_total_per_period === self::STALE_TOTAL_CAP) {
                $updates['max_cashback_total_per_period'] = self::CORRECT_TOTAL_CAP;
            }

            if ($updates === []) {
                continue;
            }

            $updates['updated_at'] = now();

            $connection->table('credit_card_policies')
                ->where('id', $blueprint->id)
                ->update($updates);
        }
    }

    public function down(): void
    {
        // Không khôi phục dữ liệu SAI (note nhắc "MB Ultimate JCB", trần 800.000).
    }
};
