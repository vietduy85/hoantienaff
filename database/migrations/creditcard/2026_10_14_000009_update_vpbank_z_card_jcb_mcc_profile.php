<?php

use App\Models\CreditCard\PolicyMccProfile;
use App\Models\CreditCard\PolicyMccProfileRow;
use App\Services\CreditCard\PolicyMccProfileService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Cập nhật HỒ SƠ MCC của "VPBank Z Card JCB" theo THỂ LỆ MỚI.
 *
 * Nguồn: VPBank — "Ưu đãi dài hạn dành cho chủ thẻ tín dụng VPBank Z JCB",
 * hiệu lực từ 27/08/2026 đến hết 31/12/2027.
 *   - 9.2.2: hoàn 15% cho 3 nhóm — Ăn uống; Giải trí & Fitness; Di chuyển &
 *     đặt đồ ăn. Mỗi nhóm tối đa 500.000 VNĐ/kỳ sao kê; TỔNG hoàn tối đa cả 3
 *     nhóm 1.000.000 VNĐ/kỳ sao kê. Ngành hàng khác: 0%.
 *   - Bảng 1 - Phụ đính: mã MCC từng nhóm (11 mã).
 *   - 9.2.2 + các mục liên quan: danh sách giao dịch KHÔNG hợp lệ (loại trừ).
 *
 * Việc cập nhật thay đổi CẢ mã MCC lẫn tỷ lệ/trần nên KHÔNG thể chỉ sửa tại
 * chỗ: tái tạo lại toàn bộ dòng của hồ sơ theo thể lệ mới (thay vì để sót dữ
 * liệu cũ 10%/4 triệu trái thể lệ). Bậc hoàn tiền theo dòng được nạp lại bằng
 * `php artisan credit-card:import-mcc-row-benefits` (từ file dữ liệu tĩnh), nên
 * các bậc cũ bị xoá theo dòng cũ là điều mong đợi.
 *
 * AN TOÀN / IDEMPOTENT:
 *   - Định vị theo SLUG template + hồ sơ đầu tiên (KHÔNG hard-code id).
 *   - Bỏ qua nếu template/hồ sơ chưa tồn tại (vd schema test rỗng).
 *   - Bỏ qua nếu hồ sơ ĐÃ được cập nhật (dòng "Ăn uống" đã có MCC 5462).
 *   - Chỉ dùng mã MCC CÓ trong master; loại trừ là mô tả theo LOẠI giao dịch,
 *     KHÔNG gán mã (đúng như tài liệu). KHÔNG đụng engine cashback/blueprint.
 *   - Thay tài liệu đính kèm: gỡ bản cũ (đã hết hiệu lực) và gắn bản mới, tránh
 *     để giao diện hiển thị song song bản cũ như thể là bản mới nhất.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    private const SLUG = 'vpbank-z-card-jcb';

    /** Bản thể lệ cũ đã bị thay thế (2025). */
    private const OLD_DOC_FRAGMENT = 'vpbank_zcard';

    /** Bản thể lệ mới (2026) — lấy từ `database/data/credit_card_documents`. */
    private const NEW_DOC_FILE = 'vpbank-z-card-jcb-uu-dai-dai-han-2026.pdf';

    private const NEW_DOC_SOURCE_URL = 'https://www.vpbank.com.vn/-/media/vpbank-latest/1retail/cards/credit-cards/The-le-uu-dai-dai-han-danh-cho-chu-the-tin-dung-VPBank-Z-JCB-Web.pdf';

    /**
     * @var array<int, array{category_name:string, description:?string, note:?string, codes:array<int,string>}>
     */
    private const INCLUDED = [
        [
            'category_name' => 'Ăn uống',
            'description' => 'Hoàn 15% cho giao dịch thuộc ngành hàng Ăn uống.',
            'note' => 'Số tiền hoàn tối đa 500.000 VNĐ/kỳ sao kê. Danh mục mã ngành (MCC): 5811, 5812, 5813, 5921, 5462.',
            'codes' => ['5811', '5812', '5813', '5921', '5462'],
        ],
        [
            'category_name' => 'Giải trí & Fitness',
            'description' => 'Hoàn 15% cho giao dịch thuộc ngành hàng Giải trí & Fitness (bao gồm trò chơi điện tử (game), xem phim, âm nhạc trực tuyến…).',
            'note' => 'Số tiền hoàn tối đa 500.000 VNĐ/kỳ sao kê. Danh mục mã ngành (MCC): 5734, 5735, 7832, 7997.',
            'codes' => ['5734', '5735', '7832', '7997'],
        ],
        [
            'category_name' => 'Di chuyển & đặt đồ ăn',
            'description' => 'Hoàn 15% cho giao dịch thuộc ngành hàng Di chuyển & đặt đồ ăn (bao gồm nền tảng gọi xe & giao đồ ăn như Be, Grab, ShopeeFood…).',
            'note' => 'Số tiền hoàn tối đa 500.000 VNĐ/kỳ sao kê. Danh mục mã ngành (MCC): 4121, 5814.',
            'codes' => ['4121', '5814'],
        ],
        [
            'category_name' => 'Ngành hàng khác',
            'description' => 'Các ngành hàng không thuộc 3 nhóm ưu đãi: hoàn 0%.',
            'note' => null,
            'codes' => [],
        ],
    ];

    /**
     * Giao dịch KHÔNG hợp lệ (loại trừ) — mô tả theo loại giao dịch, KHÔNG mã.
     *
     * @var array<int, string>
     */
    private const EXCLUDED = [
        'Rút tiền mặt',
        'Phí và lãi (bao gồm nhưng không giới hạn phí thường niên, phí rút tiền mặt, lãi suất, số tiền thanh toán dư nợ thẻ tín dụng, phí trả chậm…)',
        'Thuế hàng hóa, dịch vụ',
        'Giao dịch nghi ngờ rút tiền mặt hoặc gian lận',
        'Giao dịch hủy/hoàn lại (charge back)',
        'Chuyển khoản dư nợ',
        'Giao dịch ví điện tử (bao gồm nhưng không giới hạn VTC Pay, Zalo Pay, Ngan Luong Online, Ngan Luong - Alepay, Vi Momo, VN Pay)',
        'Giao dịch liên quan đến hoạt động mua bán chứng khoán, ngoại hối',
        'Giao dịch qua VPBank NEO',
        'Giao dịch liên quan đến hoạt động chuyển tiền trên các trang điện tử (bao gồm nhưng không giới hạn Paypal, SKR skrill.com)',
        'Giao dịch liên quan đến hoạt động nạp tiền vào thẻ thanh toán trước',
        'Giao dịch chuyển đổi ngoại tệ (bao gồm nhưng không giới hạn các giao dịch trên Forex.com)',
        'Các giao dịch khác do Ngân hàng quy định tùy từng thời điểm',
    ];

    public function up(): void
    {
        $connection = DB::connection($this->connection);

        if (! $connection->getSchemaBuilder()->hasTable('credit_card_policy_mcc_profiles')) {
            return;
        }

        $templateId = $connection->table('credit_card_policy_templates')
            ->where('slug', self::SLUG)
            ->orderBy('id')
            ->value('id');

        if ($templateId === null) {
            return;
        }

        $profile = PolicyMccProfile::query()
            ->where('policy_template_id', (int) $templateId)
            ->orderBy('id')
            ->first();

        if ($profile === null) {
            return;
        }

        // Idempotent: dòng "Ăn uống" đã có MCC 5462 ⇒ thể lệ 2026 đã áp dụng.
        $alreadyUpdated = $connection->table('credit_card_policy_mcc_row_codes as rc')
            ->join('credit_card_policy_mcc_rows as r', 'r.id', '=', 'rc.policy_mcc_row_id')
            ->where('r.policy_mcc_profile_id', $profile->id)
            ->where('r.row_type', PolicyMccProfileRow::TYPE_INCLUDED)
            ->where('r.category_name', 'Ăn uống')
            ->where('rc.mcc', '5462')
            ->exists();

        if ($alreadyUpdated) {
            return;
        }

        $service = app(PolicyMccProfileService::class);

        // Gỡ tài liệu cũ (đã hết hiệu lực) trước khi gắn bản mới.
        foreach ($profile->documents()->get() as $document) {
            if (! str_contains((string) $document->original_name, self::NEW_DOC_FILE)) {
                $service->deleteDocument($document);
            }
        }

        $excluded = array_map(fn (string $name): array => [
            'category_name' => $name,
            'description' => null,
            'note' => null,
            'codes' => [],
        ], self::EXCLUDED);

        $service->update($profile, [
            'note' => $this->note(),
            'included' => self::INCLUDED,
            'excluded' => $excluded,
        ], $this->documents(), null);
    }

    private function note(): string
    {
        return implode(' ', [
            'Thể lệ "Ưu đãi dài hạn dành cho chủ thẻ tín dụng VPBank Z JCB" — hiệu lực từ 27/08/2026 đến hết 31/12/2027.',
            'Hoàn 15% cho 3 nhóm: Ăn uống; Giải trí & Fitness; Di chuyển & đặt đồ ăn — mỗi nhóm tối đa 500.000 VNĐ/kỳ sao kê;',
            'TỔNG hạn mức hoàn tiền cả 3 nhóm tối đa 1.000.000 VNĐ/kỳ sao kê. Ngành hàng khác: 0%.',
            'Danh mục mã ngành (MCC) theo Bảng 1 - Phụ đính của thể lệ.',
            'Lưu ý: tài liệu ghi 5734 = "Record Shops" và 5735 = "Record Stores"; danh mục MCC chuẩn của hệ thống hiển thị 5734 = "Computer Software Stores" và 5735 = "Record Stores" — cùng tồn tại hai mã, khác nhãn mô tả.',
            'Hạn mức TỔNG 1.000.000 VNĐ/kỳ sao kê (trần chung của cả 3 nhóm) CHƯA có trường riêng ở cấp hồ sơ MCC nên được ghi tại đây và trong điều kiện từng nhóm; cấp dòng vẫn lưu trần 500.000 VNĐ/nhóm.',
            'Nguồn: VPBank — "'.$this->sourceTitle().'", '.self::NEW_DOC_SOURCE_URL.' (truy cập 2026-10-14).',
        ]);
    }

    private function sourceTitle(): string
    {
        return 'Thể lệ ưu đãi dài hạn dành cho chủ thẻ tín dụng VPBank Z JCB';
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function documents(): array
    {
        $source = database_path('data/credit_card_documents/'.self::NEW_DOC_FILE);

        if (! is_file($source)) {
            return [];
        }

        return [
            new UploadedFile($source, self::NEW_DOC_FILE, 'application/pdf', null, true),
        ];
    }

    public function down(): void
    {
        // Không tự ý hạ cấp dữ liệu người dùng đã chỉnh: hạ cấp schema KHÔNG gỡ
        // nội dung hồ sơ.
    }
};
