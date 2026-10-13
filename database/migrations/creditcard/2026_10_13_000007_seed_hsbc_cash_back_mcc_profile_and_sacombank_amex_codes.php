<?php

use App\Models\CreditCard\PolicyMccProfileRow;
use App\Services\CreditCard\PolicyMccProfileService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Dữ liệu HỒ SƠ MCC bổ sung (additive, idempotent, có guard).
 *
 * Gồm ba việc:
 *   1. Tạo HỒ SƠ MCC cho HSBC Cash Back (template slug `hsbc-cash-back`, policy #67)
 *      theo "Điều khoản và Điều kiện của Thẻ tín dụng HSBC Cash Back" (hiệu lực
 *      06/03/2026), Phụ lục — Tỷ lệ và hạn mức hoàn tiền; kèm tài liệu gốc.
 *   2. Sửa mô tả template HSBC (bản cũ ghi sai "chưa công bố mã MCC").
 *   3. Bổ sung 3 mã MCC đã xác minh (4511, 4722, 7011) vào dòng included của
 *      hồ sơ Sacombank Platinum American Express (chỉ mã CÓ trong master).
 *
 * AN TOÀN: mọi thao tác đều GUARD theo sự tồn tại của template/hồ sơ/mã master và
 * IDEMPOTENT. Trên schema rỗng (vd test `:memory:`) tất cả bị bỏ qua. KHÔNG xoá/đổi
 * dữ liệu khác, KHÔNG đụng engine cashback.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    /**
     * Danh mục hoàn tiền HSBC Cash Back — trích Phụ lục tài liệu gốc.
     *
     * CHỈ dùng mã MCC có trong master. Dòng "Khác" cố ý KHÔNG liệt kê mã (tài liệu
     * không nêu) — chỉ là dòng mô tả mức 0,3% mặc định.
     *
     * @var array<int, array{category_name:string, description:?string, note:?string, codes:array<int,string>}>
     */
    private const HSBC_INCLUDED = [
        [
            'category_name' => 'Siêu thị, cửa hàng bách hóa và dịch vụ taxi, limousine',
            'description' => '6% cho giao dịch Siêu thị, cửa hàng bách hóa (kèm dịch vụ taxi, limousine). Hoàn thêm 2% (tổng cộng 8%) khi Chủ Thẻ có Tài Khoản Nhận Lương và Giao Dịch Lương Chuyển Khoản Hợp Lệ từ 10.000.000đ/tháng.',
            'note' => 'Trần hoàn tiền 200.000đ/tháng (theo tháng dương lịch).',
            'codes' => ['5411', '4121'],
        ],
        [
            'category_name' => 'Giáo dục',
            'description' => 'Hoàn 1% cho giao dịch thuộc danh mục Giáo dục.',
            'note' => 'Không giới hạn số tiền hoàn.',
            'codes' => ['8211', '8220', '8241', '8244', '8249', '8299', '8351'],
        ],
        [
            'category_name' => 'Chăm sóc sức khỏe',
            'description' => 'Hoàn 1% cho giao dịch thuộc danh mục Chăm sóc sức khỏe.',
            'note' => 'Không giới hạn số tiền hoàn.',
            'codes' => ['5047', '5122', '5912', '8011', '8021', '8041', '8042', '8043', '8049', '8050', '8062', '8071', '8099'],
        ],
        [
            'category_name' => 'Khác',
            'description' => 'Mọi Giao Dịch Hợp Lệ khác không thuộc các danh mục nêu trên: hoàn 0,3%. Tài liệu không liệt kê mã MCC cho nhóm này.',
            'note' => null,
            'codes' => [],
        ],
    ];

    /**
     * Giao dịch KHÔNG được hoàn tiền — trích khoản 2 tài liệu gốc. Tài liệu mô tả
     * theo LOẠI giao dịch (không nêu mã MCC) nên các dòng này KHÔNG gắn mã.
     *
     * @var array<int, array{category_name:string, description:?string}>
     */
    private const HSBC_EXCLUDED = [
        ['category_name' => 'Rút tiền mặt', 'description' => 'Giao dịch rút tiền mặt.'],
        ['category_name' => 'Phí và lãi', 'description' => 'Bao gồm lãi suất, phí chậm thanh toán và phí thường niên.'],
        ['category_name' => 'Trả góp', 'description' => 'Chương trình Trả Góp Ưu Đãi và Chương trình Mua Hàng Trả Góp.'],
        ['category_name' => 'Chuyển khoản số dư', 'description' => 'Giao dịch chuyển khoản số dư.'],
        ['category_name' => 'Mua bán chứng khoán, ngoại hối', 'description' => 'Giao dịch liên quan mua bán chứng khoán, ngoại hối.'],
        ['category_name' => 'Chuyển tiền trên các trang điện tử', 'description' => 'Ví dụ Paypal, Skrill.'],
        ['category_name' => 'Nạp tiền vào thẻ thanh toán trước', 'description' => 'Giao dịch nạp tiền vào thẻ thanh toán trước.'],
        ['category_name' => 'Giao dịch tương tự tiền mặt (quasi-cash)', 'description' => 'Bao gồm lệnh chuyển tiền, séc du lịch, game, đánh cược, xổ số.'],
        ['category_name' => 'Chuyển đổi ngoại tệ', 'description' => 'Ví dụ giao dịch trên Forex.com.'],
        ['category_name' => 'Ví điện tử', 'description' => 'Bao gồm VTC Pay, ZaloPay, Ngân Lượng/Alepay, MoMo, VNPay.'],
        ['category_name' => 'Thanh toán hóa đơn trực tuyến qua HSBC', 'description' => 'Thanh toán hóa đơn trên HSBC Online Banking và ứng dụng HSBC Mobile Banking.'],
    ];

    /** Template slug HSBC Cash Back. */
    private const HSBC_SLUG = 'hsbc-cash-back';

    /** Slug template Sacombank Platinum American Express. */
    private const SACOMBANK_SLUG = 'sacombank-platinum-amex';

    /** Mã MCC bổ sung cho dòng included của Sacombank Amex (đã xác minh, có trong master). */
    private const SACOMBANK_EXTRA_CODES = ['4511', '4722', '7011'];

    public function up(): void
    {
        $this->seedHsbcProfile();
        $this->fixHsbcTemplateDescription();
        $this->addSacombankAmexCodes();
    }

    /**
     * Tạo hồ sơ MCC HSBC Cash Back (chỉ khi template tồn tại và CHƯA có hồ sơ).
     */
    private function seedHsbcProfile(): void
    {
        $connection = DB::connection($this->connection);

        $templateId = $connection->table('credit_card_policy_templates')
            ->where('slug', self::HSBC_SLUG)
            ->orderBy('id')
            ->value('id');

        if ($templateId === null) {
            return;
        }

        $already = $connection->table('credit_card_policy_mcc_profiles')
            ->where('policy_template_id', $templateId)
            ->exists();

        if ($already) {
            return;
        }

        // Mọi mã included phải tồn tại trong master, nếu không thì bỏ qua toàn bộ
        // (tránh tạo hồ sơ dở dang / lỗi không xác định).
        $codes = [];

        foreach (self::HSBC_INCLUDED as $row) {
            foreach ($row['codes'] as $code) {
                $codes[] = $code;
            }
        }

        $existing = $connection->table('credit_card_mcc_codes')
            ->whereIn('mcc', $codes)
            ->pluck('mcc')
            ->map(fn ($mcc): string => (string) $mcc)
            ->all();

        if (count(array_unique($existing)) !== count(array_unique($codes))) {
            return;
        }

        $included = array_map(fn (array $row): array => [
            'category_name' => $row['category_name'],
            'description' => $row['description'],
            'note' => $row['note'],
            'codes' => $row['codes'],
        ], self::HSBC_INCLUDED);

        $excluded = array_map(fn (array $row): array => [
            'category_name' => $row['category_name'],
            'description' => $row['description'],
            'note' => null,
            'codes' => [],
        ], self::HSBC_EXCLUDED);

        $documents = $this->hsbcDocuments();

        app(PolicyMccProfileService::class)->create([
            'policy_template_id' => (int) $templateId,
            'name' => 'HSBC Cash Back - Danh mục hoàn tiền (MCC)',
            'note' => 'Nguồn: HSBC — "Điều khoản và Điều kiện của Thẻ tín dụng HSBC Cash Back" (hiệu lực từ 06/03/2026), Phụ lục — Tỷ lệ và hạn mức hoàn tiền. Mã ngành hàng (MCC) do tổ chức phát hành thẻ quy định.',
            'included' => $included,
            'excluded' => $excluded,
        ], $documents, null);
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function hsbcDocuments(): array
    {
        $source = database_path('data/credit_card_documents/hsbc-cash-back-terms-vn.pdf');

        if (! is_file($source)) {
            return [];
        }

        return [
            new UploadedFile($source, 'hsbc-cash-back-terms-vn.pdf', 'application/pdf', null, true),
        ];
    }

    /**
     * Cập nhật mô tả template HSBC — bản cũ ghi sai "HSBC chưa công bố mã MCC".
     */
    private function fixHsbcTemplateDescription(): void
    {
        DB::connection($this->connection)
            ->table('credit_card_policy_templates')
            ->where('slug', self::HSBC_SLUG)
            ->update([
                'description' => 'HSBC Cash Back: Siêu thị, cửa hàng bách hóa (MCC 5411, 4121) 6% — hoàn thêm 2% (tổng 8%) khi có Tài khoản Nhận Lương và Giao dịch Lương Chuyển Khoản Hợp Lệ từ 10.000.000đ/tháng, trần 200.000đ/tháng; Giáo dục (8211, 8220, 8241, 8244, 8249, 8299, 8351) 1% và Chăm sóc sức khỏe (5047, 5122, 5912, 8011, 8021, 8041, 8042, 8043, 8049, 8050, 8062, 8071, 8099) 1%, không giới hạn; giao dịch khác 0,3%.',
                'updated_at' => now(),
            ]);
    }

    /**
     * Bổ sung mã MCC đã xác minh vào dòng included "Vé máy bay, khách sạn, đại lý
     * du lịch" của hồ sơ Sacombank Amex (idempotent — bỏ mã đã có).
     */
    private function addSacombankAmexCodes(): void
    {
        $connection = DB::connection($this->connection);

        $templateId = $connection->table('credit_card_policy_templates')
            ->where('slug', self::SACOMBANK_SLUG)
            ->orderBy('id')
            ->value('id');

        if ($templateId === null) {
            return;
        }

        $profileId = $connection->table('credit_card_policy_mcc_profiles')
            ->where('policy_template_id', $templateId)
            ->orderBy('id')
            ->value('id');

        if ($profileId === null) {
            return;
        }

        $rowId = $connection->table('credit_card_policy_mcc_rows')
            ->where('policy_mcc_profile_id', $profileId)
            ->where('row_type', PolicyMccProfileRow::TYPE_INCLUDED)
            ->where('category_name', 'like', 'Vé máy bay%')
            ->orderBy('id')
            ->value('id');

        if ($rowId === null) {
            return;
        }

        $masterIds = $connection->table('credit_card_mcc_codes')
            ->whereIn('mcc', self::SACOMBANK_EXTRA_CODES)
            ->pluck('id', 'mcc')
            ->all();

        $now = now();
        $rows = [];

        foreach (self::SACOMBANK_EXTRA_CODES as $code) {
            if (! isset($masterIds[$code])) {
                continue;
            }

            $rows[] = [
                'policy_mcc_row_id' => (int) $rowId,
                'mcc_code_id' => (int) $masterIds[$code],
                'mcc' => $code,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            $connection->table('credit_card_policy_mcc_row_codes')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        // Không xoá dữ liệu người dùng đã chỉnh: hạ cấp schema KHÔNG gỡ hồ sơ.
        // (Chỉ rollback thủ công khi thật cần, có chủ đích.)
    }
};
