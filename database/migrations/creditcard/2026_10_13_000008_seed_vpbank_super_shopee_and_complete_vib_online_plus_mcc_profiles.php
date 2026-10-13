<?php

use App\Models\CreditCard\PolicyMccProfile;
use App\Services\CreditCard\PolicyMccProfileService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Dữ liệu HỒ SƠ MCC bổ sung (additive, idempotent, có guard).
 *
 * Gồm hai việc:
 *   1. Tạo HỒ SƠ MÔ TẢ cho VPBank Super Shopee Platinum Visa (template slug
 *      `vpbank-super-shopee-platinum-visa`) theo "Thể lệ ưu đãi dài hạn dành cho
 *      chủ thẻ tín dụng Super Shopee Platinum", mục 7.1 Hoàn tiền; kèm tài liệu gốc.
 *      VPBank KHÔNG công bố danh sách mã MCC cụ thể ⇒ các dòng included/excluded
 *      chỉ mô tả quyền lợi/loại giao dịch, KHÔNG gắn mã.
 *   2. Hoàn thiện HỒ SƠ RỖNG #26 của VIB Online Plus 2in1 (template slug
 *      `vib-online-plus-2in1`) theo "Chương trình điểm thưởng/hoàn tiền thẻ tín
 *      dụng VIB" (hiệu lực 06/03/2025), mục 2.6: thêm dòng included mô tả theo
 *      KÊNH + dòng excluded (MCC 4900, 4814 + loại giao dịch). KHÔNG tạo hồ sơ mới.
 *
 * AN TOÀN: mọi thao tác đều GUARD theo sự tồn tại của template/hồ sơ/mã master và
 * IDEMPOTENT. Trên schema rỗng (vd test `:memory:`) tất cả bị bỏ qua. KHÔNG xoá/đổi
 * dữ liệu khác, KHÔNG đụng engine cashback. Chỉ dùng mã MCC CÓ trong master.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    private const VPBANK_SLUG = 'vpbank-super-shopee-platinum-visa';

    private const VIB_SLUG = 'vib-online-plus-2in1';

    /** Câu ghi chú bắt buộc cho hồ sơ mô tả không mã. */
    private const VPBANK_MCC_DISCLAIMER = 'VPBank không công bố danh sách mã MCC cụ thể trong tài liệu đã rà soát. Hồ sơ này mô tả quyền lợi, không phải danh sách MCC đã xác minh.';

    /**
     * Dòng included VPBank Super Shopee — mô tả quyền lợi, KHÔNG mã.
     *
     * @var array<int, array{category_name:string, description:?string, note:?string}>
     */
    private const VPBANK_INCLUDED = [
        [
            'category_name' => 'Giao dịch tại Shopee, ShopeeFood',
            'description' => 'Hoàn 10% cho giao dịch tại Shopee, ShopeeFood.',
            'note' => 'Số tiền hoàn tối đa 400.000đ/kỳ sao kê. Điều kiện: tổng doanh số giao dịch của Khách hàng đạt từ 4.000.000đ trong kỳ sao kê xét hoàn tiền. Danh mục căn cứ theo mã MCC do Tổ chức Thẻ Quốc tế Visa quy định — VPBank không công bố danh sách mã cụ thể.',
        ],
        [
            'category_name' => 'Giao dịch ăn uống, siêu thị (Co.op, BigC, Lottemart, Winmart), Grab/Be',
            'description' => 'Hoàn 2% cho giao dịch ăn uống, siêu thị (Co.op, BigC, Lottemart, Winmart) và Grab/Be.',
            'note' => 'Số tiền hoàn tối đa 200.000đ/kỳ sao kê. Điều kiện: tổng doanh số giao dịch của Khách hàng đạt từ 4.000.000đ trong kỳ sao kê xét hoàn tiền. Danh mục căn cứ theo mã MCC do Tổ chức Thẻ Quốc tế Visa quy định — VPBank không công bố danh sách mã cụ thể.',
        ],
        [
            'category_name' => 'Tất cả giao dịch còn lại',
            'description' => 'Hoàn 0,1% cho toàn bộ giao dịch còn lại.',
            'note' => 'Không giới hạn số tiền hoàn.',
        ],
    ];

    /**
     * Dòng excluded VPBank Super Shopee — mô tả theo LOẠI giao dịch, KHÔNG mã.
     *
     * @var array<int, string>
     */
    private const VPBANK_EXCLUDED = [
        'Rút tiền mặt',
        'Phí và lãi',
        'Thuế hàng hóa, dịch vụ',
        'Giao dịch nghi ngờ rút tiền mặt hoặc gian lận',
        'Giao dịch hủy/hoàn lại (charge back)',
        'Chuyển khoản dư nợ',
        'Giao dịch ví điện tử',
        'Mua bán chứng khoán, ngoại hối',
        'Giao dịch qua VPBank NEO',
        'Chuyển tiền trên các trang điện tử',
        'Nạp tiền vào thẻ thanh toán trước',
        'Chuyển đổi ngoại tệ',
        'Thanh toán xăng dầu, hóa đơn điện, gas, nước, dầu sưởi, dịch vụ CNTT và viễn thông',
        'Giao dịch trả góp',
    ];

    /**
     * Dòng included VIB Online Plus 2in1 — mô tả theo KÊNH, KHÔNG mã.
     *
     * @var array<int, array{category_name:string, description:?string, note:?string}>
     */
    private const VIB_INCLUDED = [
        [
            'category_name' => 'Chi tiêu trực tuyến nước ngoài',
            'description' => 'Tích 5% Điểm thưởng cho giao dịch chi tiêu trực tuyến tại ĐVCNT ngoài lãnh thổ Việt Nam và thực hiện bằng ngoại tệ.',
            'note' => 'Điểm thưởng tích lũy tối đa 600.000 điểm/kỳ sao kê. Xác định theo KÊNH giao dịch (trực tuyến + nước ngoài), không theo mã MCC — VIB không công bố danh sách mã cho nhóm này.',
        ],
        [
            'category_name' => 'Chi tiêu trực tuyến khác',
            'description' => 'Tích 3% Điểm thưởng cho các giao dịch chi tiêu trực tuyến còn lại.',
            'note' => 'Điểm thưởng tích lũy tối đa 600.000 điểm/kỳ sao kê. Xác định theo KÊNH giao dịch (trực tuyến), không theo mã MCC — VIB không công bố danh sách mã cho nhóm này.',
        ],
        [
            'category_name' => 'Giao dịch chi tiêu còn lại',
            'description' => 'Tích 0,1% Điểm thưởng cho toàn bộ giá trị giao dịch chi tiêu còn lại trong kỳ (không phải giao dịch thanh toán trực tuyến), gồm cả thanh toán hóa đơn phí bảo hiểm Prudential của Chủ Thẻ cho chính Chủ Thẻ qua MyVIB.',
            'note' => 'Điểm thưởng tích lũy tối đa 600.000 điểm/kỳ sao kê.',
        ],
        [
            'category_name' => 'Giao dịch có lưu thông tin Thẻ (card-on-file)',
            'description' => 'Hoàn tiền 50.000đ/giao dịch lưu thông tin Thẻ tại ĐVCNT trực tuyến: Grab, Netflix, Tiki, Agoda, Booking.com, iTunes, CH Play, Spotify, Traveloka. Áp dụng cho giao dịch từ ngày phát hành thẻ đến trước ngày lập bảng sao kê của kỳ sao kê thứ 03.',
            'note' => 'Số tiền hoàn tối đa 100.000đ/kỳ sao kê và tối đa 300.000đ/Khách hàng. Đây là hoàn tiền tiền mặt, không phải Điểm thưởng.',
        ],
    ];

    /**
     * Dòng excluded VIB Online Plus 2in1 — gồm 2 dòng CÓ mã (4900, 4814) + dòng
     * mô tả theo loại giao dịch (không mã).
     *
     * @var array<int, array{category_name:string, description:?string, note:?string, codes:array<int,string>}>
     */
    private const VIB_EXCLUDED = [
        [
            'category_name' => 'Thanh toán dịch vụ tiện ích điện, gas, dầu sưởi, vệ sinh, nước',
            'description' => 'MCC 4900 — Dịch vụ tiện ích điện, Gas, Dầu sưởi, Vệ sinh, Nước.',
            'note' => 'Loại trừ theo "Chương trình điểm thưởng/hoàn tiền thẻ tín dụng VIB", mục 2.1.',
            'codes' => ['4900'],
        ],
        [
            'category_name' => 'Thanh toán dịch vụ viễn thông',
            'description' => 'MCC 4814 — Dịch vụ viễn thông, bao gồm nhưng không giới hạn thuê bao trả trước và thuê bao cố định.',
            'note' => 'Loại trừ theo "Chương trình điểm thưởng/hoàn tiền thẻ tín dụng VIB", mục 2.1.',
            'codes' => ['4814'],
        ],
        [
            'category_name' => 'Giao dịch không có mã MCC (QR, thanh toán hóa đơn qua MyVIB)',
            'description' => 'Giao dịch không có mã MCC như thanh toán qua mã QR, thanh toán hóa đơn qua Dịch vụ Ngân hàng điện tử/ứng dụng Ngân hàng di động (MyVIB) không thuộc giao dịch chi tiêu.',
            'note' => 'Không gán mã MCC cụ thể: đây là giao dịch KHÔNG có mã MCC (không đồng nhất với MCC 4900/4814).',
            'codes' => [],
        ],
        [
            'category_name' => 'Thuế hàng hóa, dịch vụ',
            'description' => 'Các loại thuế hàng hóa, dịch vụ.',
            'note' => null,
            'codes' => [],
        ],
        [
            'category_name' => 'Phí, lãi và trả góp',
            'description' => 'Số tiền trả góp hàng tháng của Chương Trình Trả Góp, giao dịch điều chỉnh ghi nợ và các loại phí/lãi khác theo Biểu phí dịch vụ và Lãi suất Thẻ.',
            'note' => null,
            'codes' => [],
        ],
        [
            'category_name' => 'Đặt cọc, chuyển nhượng bất động sản',
            'description' => 'Giao dịch đặt cọc, thanh toán liên quan đến việc chuyển nhượng bất động sản (bao gồm quyền sử dụng đất và/hoặc tài sản gắn liền trên đất).',
            'note' => null,
            'codes' => [],
        ],
        [
            'category_name' => 'Đại lý thanh toán hóa đơn tiện ích',
            'description' => 'Giao dịch thanh toán tại các đại lý thanh toán hóa đơn tiện ích bao gồm hóa đơn điện, nước, điện thoại, internet, truyền hình trừ khi có quy định đặc biệt cho từng sản phẩm thẻ.',
            'note' => null,
            'codes' => [],
        ],
    ];

    public function up(): void
    {
        $this->seedVpbankSuperShopeeProfile();
        $this->completeVibOnlinePlusProfile();
    }

    /**
     * Tạo hồ sơ MCC mô tả cho VPBank Super Shopee (chỉ khi template tồn tại và
     * CHƯA có hồ sơ).
     */
    private function seedVpbankSuperShopeeProfile(): void
    {
        $connection = DB::connection($this->connection);

        $templateId = $connection->table('credit_card_policy_templates')
            ->where('slug', self::VPBANK_SLUG)
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

        $included = array_map(fn (array $row): array => [
            'category_name' => $row['category_name'],
            'description' => $row['description'],
            'note' => $row['note'],
            'codes' => [],
        ], self::VPBANK_INCLUDED);

        $excluded = array_map(fn (string $name): array => [
            'category_name' => $name,
            'description' => null,
            'note' => null,
            'codes' => [],
        ], self::VPBANK_EXCLUDED);

        app(PolicyMccProfileService::class)->create([
            'policy_template_id' => (int) $templateId,
            'name' => 'VPBank Super Shopee Platinum Visa - Hồ sơ mô tả quyền lợi (không có danh sách MCC)',
            'note' => self::VPBANK_MCC_DISCLAIMER.' Nguồn: VPBank — "Thể lệ ưu đãi dài hạn dành cho chủ thẻ tín dụng Super Shopee Platinum", mục 7.1 Hoàn tiền. Danh mục chi tiêu căn cứ theo bảng mã danh mục (MCC) do Tổ chức Thẻ Quốc tế Visa quy định.',
            'included' => $included,
            'excluded' => $excluded,
        ], $this->vpbankDocuments(), null);
    }

    /**
     * Hoàn thiện hồ sơ rỗng #26 của VIB Online Plus 2in1 (chỉ khi hồ sơ tồn tại và
     * CHƯA có dòng nào).
     */
    private function completeVibOnlinePlusProfile(): void
    {
        $connection = DB::connection($this->connection);

        $templateId = $connection->table('credit_card_policy_templates')
            ->where('slug', self::VIB_SLUG)
            ->orderBy('id')
            ->value('id');

        if ($templateId === null) {
            return;
        }

        $profile = PolicyMccProfile::query()
            ->where('policy_template_id', $templateId)
            ->orderBy('id')
            ->first();

        if ($profile === null) {
            return;
        }

        $hasRows = $connection->table('credit_card_policy_mcc_rows')
            ->where('policy_mcc_profile_id', $profile->id)
            ->exists();

        if ($hasRows) {
            return;
        }

        // Chỉ dùng mã MCC có trong master; mã thiếu sẽ bị loại khỏi dòng (giữ mô tả).
        $masterCodes = $connection->table('credit_card_mcc_codes')
            ->whereIn('mcc', ['4900', '4814'])
            ->pluck('mcc')
            ->map(fn ($mcc): string => (string) $mcc)
            ->all();

        $included = array_map(fn (array $row): array => [
            'category_name' => $row['category_name'],
            'description' => $row['description'],
            'note' => $row['note'],
            'codes' => [],
        ], self::VIB_INCLUDED);

        $excluded = array_map(function (array $row) use ($masterCodes): array {
            $codes = array_values(array_intersect($row['codes'], $masterCodes));

            return [
                'category_name' => $row['category_name'],
                'description' => $row['description'],
                'note' => $row['note'],
                'codes' => $codes,
            ];
        }, self::VIB_EXCLUDED);

        app(PolicyMccProfileService::class)->update($profile, [
            'note' => 'Giao dịch được xác định theo KÊNH giao dịch (trực tuyến/online, nước ngoài), không theo mã MCC; VIB không công bố danh sách mã MCC cho các tỷ lệ tích lũy Điểm thưởng. Chỉ các giao dịch có MCC 4900/4814 được VIB nêu loại trừ cụ thể. Nguồn: VIB — "Chương trình điểm thưởng/hoàn tiền thẻ tín dụng VIB" (hiệu lực từ 06/03/2025), mục 2.6. Nguồn tham chiếu: https://www.vib.com.vn/vn/the-tin-dung/diem-thuong-hoan-tien (truy cập 12/10/2026); không tải được file PDF gốc nên không đính kèm tài liệu.',
            'included' => $included,
            'excluded' => $excluded,
        ], [], null);
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function vpbankDocuments(): array
    {
        $source = database_path('data/credit_card_documents/vpbank-super-shopee-terms.pdf');

        if (! is_file($source)) {
            return [];
        }

        return [
            new UploadedFile($source, 'vpbank-super-shopee-terms.pdf', 'application/pdf', null, true),
        ];
    }

    public function down(): void
    {
        // Không xoá dữ liệu người dùng đã chỉnh: hạ cấp schema KHÔNG gỡ hồ sơ.
    }
};
