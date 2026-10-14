<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sửa `note` SAI của blueprint các chính sách hệ thống bị sao chép nhầm tên
 * template nguồn (đợt 2, sau migration 2026_10_14_000010 cho MSB Online Visa).
 *
 * HIỆN TƯỢNG:
 *   Một số template hệ thống có `note` dạng
 *       'Blueprint version 1 của template "TÊN KHÁC".'
 *   trong đó "TÊN KHÁC" KHÔNG phải tên của chính template đang sở hữu blueprint.
 *   Dòng này hiển thị ở màn "Tra cứu thẻ" (qua `CardPolicyLookupService::summarize()`),
 *   gây nhầm lẫn cho người dùng.
 *
 * NGUYÊN NHÂN (đã kiểm chứng trong code):
 *   `PolicyCloneService::createTemplateBlueprint()` sinh `note` theo tên template
 *   ĐÍCH (dòng 358): 'Blueprint version N của template "{name}".'. Nhưng luồng
 *   sao chép chính sách hệ thống `createSystemPolicyFromEditor()` lại CHÉP NGUYÊN
 *   VĂN `note` của blueprint nguồn (dòng 527: `'note' => $blueprint->note`).
 *   Vì vậy template clone mang `note` nhắc tên template nguồn.
 *
 *   Ví dụ: template "MSB Online Visa" được clone từ "MB Ultimate JCB" ⇒ note vẫn
 *   nhắc "MB Ultimate JCB". CHỈ những template tạo bằng luồng clone mới dính lỗi;
 *   các template seed bằng `CreditCardSeeder::seedResearchedTemplates()` có note
 *   đúng ('Chính sách hệ thống {name}.') và KHÔNG bị đụng.
 *
 * PHẠM VI SỬA:
 *   CHỈ 8 template được xác minh bằng dữ liệu DB thật (loại trừ MSB Online Visa đã
 *   sửa ở migration 000010). Định vị theo `scope='system'` + `slug` (KHÔNG hard-code
 *   id). Chỉ thay `note` khi note còn đúng định dạng sinh tự động VÀ tên được nhắc
 *   KHÁC tên template sở hữu ⇒ không đè ghi chú admin đã sửa tay.
 *
 * AN TOÀN / KHÔNG ĐỤNG:
 *   - KHÔNG sửa `status` (clone giữ nguyên trạng thái là hành vi CÓ CHỦ ĐÍCH, có
 *     test khẳng định — xem SystemPolicyCloneTest), `max_cashback_total_per_period`,
 *     `min_total_spend`, `effective_from/to`.
 *   - KHÔNG đụng bảng bậc/rule (`credit_card_policy_tiers`,
 *     `credit_card_policy_tier_categories`) ⇒ KHÔNG đổi tỷ lệ/điều kiện engine.
 *   - Idempotent: sau lần chạy đầu, note không còn khớp định dạng ⇒ tự bỏ qua.
 *   - Bỏ qua an toàn khi template không tồn tại.
 *   - `down()` không khôi phục dữ liệu SAI (ghi chú cũ nhắc nhầm template).
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    /**
     * Danh sách slug template hệ thống đã được xác minh có `note` sai.
     * (MSB Online Visa `msb-online-visa` do migration 000010 xử lý.)
     *
     * @var list<string>
     */
    private const STALE_NOTE_SLUGS = [
        'vpbank-lady-mastercard',            // note nhắc "VPBank StepUp Mastercard"
        'vpbank-z-card-jcb',                 // note nhắc "MB Ultimate JCB"
        'vpbank-super-shopee-platinum-visa', // note nhắc "MB Ultimate JCB"
        'msb-mdigi-mastercard',              // note nhắc "Sacombank Uniq Visa"
        'vpbank-cake-freedom-visa',          // note nhắc "MB Ultimate JCB"
        'lpbank-ultimate-jcb',               // note nhắc "MB Ultimate JCB"
        'bidv-cashback-online-visa',         // note nhắc "VPBank StepUp Mastercard"
        'seabank-sea-easy-visa',             // note nhắc "MB Ultimate JCB"
    ];

    public function up(): void
    {
        $connection = DB::connection($this->connection);

        if (! $connection->getSchemaBuilder()->hasTable('credit_card_policy_templates')) {
            return;
        }

        foreach (self::STALE_NOTE_SLUGS as $slug) {
            $template = $connection->table('credit_card_policy_templates')
                ->where('scope', 'system')
                ->where('slug', $slug)
                ->first();

            if ($template === null) {
                continue;
            }

            $correctNote = 'Chính sách hệ thống '.$template->name.'.';

            $blueprints = $connection->table('credit_card_policies')
                ->where('template_id', $template->id)
                ->whereNull('user_card_id')
                ->get();

            foreach ($blueprints as $blueprint) {
                if (! is_string($blueprint->note)) {
                    continue;
                }

                // Chỉ nhận diện note do hệ thống sinh tự động:
                //   'Blueprint version N của template "Tên".'
                if (preg_match('/^Blueprint version \d+ của template "(.+)"\.$/u', $blueprint->note, $matches) !== 1) {
                    continue;
                }

                // Tên được nhắc TRÙNG tên template sở hữu ⇒ note đã đúng, bỏ qua.
                if ($matches[1] === $template->name) {
                    continue;
                }

                $connection->table('credit_card_policies')
                    ->where('id', $blueprint->id)
                    ->update([
                        'note' => $correctNote,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Không khôi phục ghi chú SAI (nhắc nhầm tên template nguồn).
    }
};
