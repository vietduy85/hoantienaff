<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Liên kết "HỒ SƠ MCC dùng chung nhiều template" — TÊN SẢN PHẨM/CHƯƠNG TRÌNH.
 *
 * Một số chương trình ngân hàng dùng CHUNG một tài liệu + danh mục MCC cho NHIỀU
 * HẠNG thẻ, và mỗi hạng là một `policy_template` hệ thống RIÊNG:
 *   - VIB Max Card Visa: Starter / Plus / Pro / Elite;
 *   - VIB One Card Visa: Gold / Platinum / Signature.
 *
 * Trước đây hồ sơ chỉ thuộc MỘT template (`policy_template_id`), nên "Tra cứu thẻ"
 * của các hạng không sở hữu hồ sơ trả về RỖNG dù cùng chương trình.
 *
 * Bảng liên kết này giữ QUAN HỆ DỮ LIỆU TƯỜNG MINH (không nhân bản hồ sơ/dòng/mã/
 * tài liệu): hồ sơ vẫn thuộc template SỞ HỮU qua `policy_template_id` (nguồn chính),
 * bảng này bổ sung các template DÙNG CHUNG thêm. Truy vấn theo template dùng
 * `policy_template_id = X OR link.policy_template_id = X`.
 *
 * Seed liên kết VIB ngay trong migration (idempotent, có guard): chỉ ghi khi template
 * và hồ sơ tương ứng đã tồn tại (DB sản xuất đã có dữ liệu). Trên schema rỗng thì
 * bỏ qua — không tạo bản ghi mồ côi.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    /**
     * Chương trình dùng chung: slug hạng sở hữu hồ sơ → các slug hạng dùng chung.
     *
     * @var array<int, array{owner:string, members:array<int,string>}>
     */
    private const SHARED_PROGRAMS = [
        [
            'owner' => 'vib-max-card-visa-starter',
            'members' => [
                'vib-max-card-visa-starter',
                'vib-max-card-visa-plus',
                'vib-max-card-visa-pro',
                'vib-max-card-visa-elite',
            ],
        ],
        [
            'owner' => 'vib-one-card-visa-gold',
            'members' => [
                'vib-one-card-visa-gold',
                'vib-one-card-visa-platinum',
                'vib-one-card-visa-signature',
            ],
        ],
    ];

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_policy_mcc_profile_template_links', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('policy_mcc_profile_id');
            $table->foreign('policy_mcc_profile_id', 'cc_pmcc_ptl_profile_fk')
                ->references('id')->on('credit_card_policy_mcc_profiles')->cascadeOnDelete();

            $table->unsignedBigInteger('policy_template_id');
            $table->foreign('policy_template_id', 'cc_pmcc_ptl_template_fk')
                ->references('id')->on('credit_card_policy_templates')->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['policy_mcc_profile_id', 'policy_template_id'], 'cc_pmcc_ptl_profile_template_unique');
            $table->index('policy_template_id', 'cc_pmcc_ptl_template_index');
        });

        $this->seedSharedProgramLinks();
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_policy_mcc_profile_template_links');
    }

    /**
     * Ghi liên kết template dùng chung cho từng chương trình (idempotent).
     *
     * Mọi thao tác đều có guard: thiếu template/hồ sơ ⇒ bỏ qua, KHÔNG ném lỗi và
     * KHÔNG tạo bản ghi mồ côi.
     */
    private function seedSharedProgramLinks(): void
    {
        $connection = DB::connection($this->connection);
        $now = now();

        foreach (self::SHARED_PROGRAMS as $program) {
            $ownerTemplateId = $connection->table('credit_card_policy_templates')
                ->where('slug', $program['owner'])
                ->orderBy('id')
                ->value('id');

            if ($ownerTemplateId === null) {
                continue;
            }

            $profileId = $connection->table('credit_card_policy_mcc_profiles')
                ->where('policy_template_id', $ownerTemplateId)
                ->orderBy('id')
                ->value('id');

            if ($profileId === null) {
                continue;
            }

            $memberIds = $connection->table('credit_card_policy_templates')
                ->whereIn('slug', $program['members'])
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $rows = [];

            foreach ($memberIds as $memberId) {
                $rows[] = [
                    'policy_mcc_profile_id' => (int) $profileId,
                    'policy_template_id' => (int) $memberId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                $connection->table('credit_card_policy_mcc_profile_template_links')->insertOrIgnore($rows);
            }
        }
    }
};
