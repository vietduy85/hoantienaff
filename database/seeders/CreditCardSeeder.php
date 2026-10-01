<?php

namespace Database\Seeders;

use App\Models\CreditCard\Bank;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Services\CreditCard\CategoryRuleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CreditCardSeeder — dữ liệu khởi tạo cho module Thẻ tín dụng.
 *
 * Chạy trên connection `creditcard` (database `hoantien_creditcard`):
 *   php artisan credit-card:migrate --seed
 *
 * ---------------------------------------------------------------------------
 * IDEMPOTENT
 * ---------------------------------------------------------------------------
 * Chạy lại nhiều lần vẫn cho cùng kết quả, không nhân bản dữ liệu. Bank dùng
 * `updateOrCreate` theo khoá tự nhiên (`slug`). Riêng danh mục hệ thống CHỈ tạo
 * khi còn thiếu và KHÔNG đè lên `name`/`description`/`sort_order`/`is_active`/
 * `is_default` mà admin đã chỉnh qua module "Quản lý danh mục hệ thống" — admin
 * là chủ thật sự của master data, seeder chỉ đảm bảo 19 danh mục gốc có mặt.
 *
 * ---------------------------------------------------------------------------
 * KHÔNG BAO GIỜ ĐỤNG DB CHÍNH
 * ---------------------------------------------------------------------------
 * Seeder này KHÔNG tạo `User` nào. `user_id` trong module là logical reference
 * sang `hoantienaff.users`, không có FK vật lý, nên seeder hoàn toàn không cần
 * (và không được) ghi vào database chính.
 *
 * ---------------------------------------------------------------------------
 * MINH HOẠ ĐÚNG CÁC BUSINESS RULE
 * ---------------------------------------------------------------------------
 * Template mẫu cố tình cấu hình:
 *   - 2 bậc chi tiêu RETROACTIVE (không có `tier_application_mode`)
 *   - category có nhiều mức theo khoảng chi tiêu
 *   - `min_total_spend` nằm ở POLICY, không nằm ở category
 *   - đủ 3 loại cap
 */
class CreditCardSeeder extends Seeder
{
    private const CONNECTION = 'creditcard';

    /**
     * 43 bank CANONICAL từ danh sách 44 DÒNG NGHIỆP VỤ của owner.
     *
     * VÌ SAO 43 MÀ KHÔNG PHẢI 44
     * --------------------------
     * Danh sách có 44 dòng, nhưng #23 "Sài Gòn (SCB)" và #33 "Sacombank (STB)"
     * là CÙNG MỘT pháp nhân: Ngân hàng TMCP Sài Gòn — Sacombank. `STB` là mã
     * chính thức hiện hành, `SCB` là mã viết tắt cũ/sai. Seed theo từng dòng sẽ
     * tạo HAI bản ghi cho một ngân hàng.
     *
     * → Vì vậy: 1 bản ghi canonical `slug = stb`, giữ `aliases = ['scb']`.
     *   `Bank::resolveByCode('scb')` vẫn trả về đúng Sacombank, nên dữ liệu cũ
     *   không hỏng mà database KHÔNG có bank trùng.
     *
     * LƯU Ý CHƯA GỘP (cần owner xác nhận): #5 "Việt Nam Thịnh Vượng (VPB)" và
     * #19 "Ngân hàng TMCP Thịnh vượng và Phát triển (PGB)". Tên pháp lý thứ hai
     * CHÍNH LÀ tên pháp lý của VPBank, nên về mặt kỹ thuật cũng là cùng entity —
     * nhưng owner liệt kê chúng thành hai dòng và không yêu cầu gộp, nên hiện
     * vẫn giữ 2 bản ghi riêng. Sẽ gộp thành alias nếu owner xác nhận.
     *
     * `slug` = mã canonical lowercase (UNIQUE). `name` giữ nguyên văn bản hiển
     * thị đã duyệt. `aliases` = các mã legacy được chấp nhận khi import.
     *
     * @var list<array{slug:string, name:string, short_name:string, aliases?:list<string>}>
     */
    private const BANKS = [
        ['slug' => 'vba', 'name' => 'Nông nghiệp và Phát triển nông thôn', 'short_name' => 'VBA'],
        ['slug' => 'vcb', 'name' => 'Ngoại thương Việt Nam', 'short_name' => 'VCB'],
        ['slug' => 'bidv', 'name' => 'Đầu tư và phát triển', 'short_name' => 'BIDV'],
        ['slug' => 'vietinbank', 'name' => 'Công Thương Việt Nam', 'short_name' => 'VIETINBANK'],
        ['slug' => 'vpb', 'name' => 'Việt Nam Thịnh Vượng', 'short_name' => 'VPB'],
        ['slug' => 'vib', 'name' => 'Quốc tế', 'short_name' => 'VIB'],
        ['slug' => 'eib', 'name' => 'Xuất nhập khẩu', 'short_name' => 'EIB'],
        ['slug' => 'shb', 'name' => 'Sài Gòn Hà Nội', 'short_name' => 'SHB'],
        ['slug' => 'tpb', 'name' => 'Tiên Phong', 'short_name' => 'TPB'],
        ['slug' => 'tcb', 'name' => 'Kỹ Thương', 'short_name' => 'TCB'],
        ['slug' => 'msb', 'name' => 'Hàng hải', 'short_name' => 'MSB'],
        ['slug' => 'lpb', 'name' => 'Ngân hàng Thương mại Cổ phần Lộc Phát Việt Nam', 'short_name' => 'LPB'],
        ['slug' => 'dab', 'name' => 'Đông Á', 'short_name' => 'DAB'],
        ['slug' => 'nasb', 'name' => 'Bắc Á', 'short_name' => 'NASB'],
        ['slug' => 'sgb', 'name' => 'Sài Gòn Công thương', 'short_name' => 'SGB'],
        ['slug' => 'vietbank', 'name' => 'Việt Nam Thương tín', 'short_name' => 'VIETBANK'],
        ['slug' => 'vccb', 'name' => 'BVBank – Ngân hàng TMCP Bản Việt', 'short_name' => 'VCCB'],
        ['slug' => 'klb', 'name' => 'Kiên Long', 'short_name' => 'KLB'],
        ['slug' => 'pgb', 'name' => 'Ngân hàng TMCP Thịnh vượng và Phát triển', 'short_name' => 'PGB'],
        ['slug' => 'pvc', 'name' => 'Đại chúng Việt Nam', 'short_name' => 'PVC'],
        ['slug' => 'acb', 'name' => 'Á Châu', 'short_name' => 'ACB'],
        ['slug' => 'namabank', 'name' => 'Nam Á', 'short_name' => 'NAMABANK'],
        // #23 "Sài Gòn (SCB)" của owner: KHÔNG seed dòng này, đã gộp vào `stb`.
        ['slug' => 'seab', 'name' => 'Đông Nam Á', 'short_name' => 'SEAB'],
        ['slug' => 'ocb', 'name' => 'Phương Đông', 'short_name' => 'OCB'],
        ['slug' => 'vab', 'name' => 'Việt Á', 'short_name' => 'VAB'],
        ['slug' => 'ncb', 'name' => 'Quốc Dân', 'short_name' => 'NCB'],
        ['slug' => 'vid', 'name' => 'Liên doanh VID Public Bank', 'short_name' => 'VID'],
        ['slug' => 'bvb', 'name' => 'Bảo Việt', 'short_name' => 'BVB'],
        ['slug' => 'mbv', 'name' => 'Ngân hàng TNHH MTV Việt Nam Hiện Đại', 'short_name' => 'MBV'],
        ['slug' => 'hdb', 'name' => 'Phát triển nhà TP HCM', 'short_name' => 'HDB'],
        ['slug' => 'gpb', 'name' => 'Dầu khí toàn cầu', 'short_name' => 'GPB'],
        ['slug' => 'stb', 'name' => 'Sacombank', 'short_name' => 'STB', 'aliases' => ['scb']],
        ['slug' => 'abbank', 'name' => 'An Bình', 'short_name' => 'ABBANK'],
        ['slug' => 'hlb', 'name' => 'TNHH MTV Hong Leong VN', 'short_name' => 'HLB'],
        ['slug' => 'shbvn', 'name' => 'MTV Shinhan Việt Nam', 'short_name' => 'SHBVN'],
        ['slug' => 'vrb', 'name' => 'Liên Doanh Việt Nga', 'short_name' => 'VRB'],
        ['slug' => 'cbb', 'name' => 'Xây dựng Việt Nam', 'short_name' => 'CBB'],
        ['slug' => 'uob', 'name' => 'United Overseas Bank Việt Nam', 'short_name' => 'UOB'],
        ['slug' => 'woori', 'name' => 'Woori Việt Nam', 'short_name' => 'Woori'],
        ['slug' => 'ivb', 'name' => 'IndoVina', 'short_name' => 'IVB'],
        ['slug' => 'cakevpb', 'name' => 'Việt Nam Thịnh Vượng CAKE BANK', 'short_name' => 'CAKEVPB'],
        ['slug' => 'ubankvpb', 'name' => 'Việt Nam Thịnh Vượng UBANK', 'short_name' => 'UBANKVPB'],
        ['slug' => 'mb', 'name' => 'Quân đội', 'short_name' => 'MB'],
    ];

    /**
     * Mã legacy mà danh sách 44 dòng dùng, nhưng KHÔNG phải bank riêng.
     *
     * Dùng để (a) tự kiểm canonical identity trong seeder và (b) chứng minh mọi
     * dòng nghiệp vụ của owner đều được đại diện bởi đúng một bản ghi bank.
     *
     * @var array<string, string> mã legacy => slug canonical
     */
    private const LEGACY_CODES = [
        'scb' => 'stb',
    ];

    /**
     * 19 danh mục CHI TIÊU hệ thống. KHÔNG có cashback ở đây.
     *
     * Cố ý KHÔNG gộp Shopee/Tiki/Lazada/Tiktok vào một mục, đồng thời vẫn giữ mục
     * "Sàn thương mại điện tử" cho giao dịch không thuộc sàn nào cụ thể — nhờ vậy
     * policy có thể đặt tỷ lệ khác nhau cho từng sàn.
     *
     * @var list<array{slug:string, name:string, description:string, sort_order:int, is_default:bool}>
     */
    private const CATEGORIES = [
        ['slug' => 'am-thuc-an-uong', 'name' => 'Ẩm thực & Ăn uống', 'description' => 'Nhà hàng, quán ăn, cà phê, giao đồ ăn.', 'sort_order' => 10, 'is_default' => true],
        ['slug' => 'sieu-thi-tien-loi', 'name' => 'Siêu thị & Cửa hàng tiện lợi', 'description' => 'Siêu thị, cửa hàng thực phẩm, cửa hàng tiện lợi.', 'sort_order' => 20, 'is_default' => true],
        ['slug' => 'mua-sam-truc-tuyen', 'name' => 'Mua sắm trực tuyến', 'description' => 'Mua sắm online chung, website bán hàng.', 'sort_order' => 30, 'is_default' => false],
        ['slug' => 'xang-dau', 'name' => 'Xăng dầu', 'description' => 'Trạm xăng, dầu, bảo dưỡng xe.', 'sort_order' => 40, 'is_default' => false],
        ['slug' => 'goi-xe-di-chuyen', 'name' => 'Ứng dụng gọi xe & Di chuyển', 'description' => 'Grab, taxi, xe ôm công nghệ, phí gửi xe, tàu điện.', 'sort_order' => 50, 'is_default' => false],
        ['slug' => 've-may-bay-du-lich', 'name' => 'Vé máy bay & Du lịch', 'description' => 'Vé máy bay, tour, du lịch.', 'sort_order' => 60, 'is_default' => false],
        ['slug' => 'khach-san-luu-tru', 'name' => 'Khách sạn & Lưu trú', 'description' => 'Khách sạn, homestay, nhà nghỉ.', 'sort_order' => 70, 'is_default' => false],
        ['slug' => 'bao-hiem', 'name' => 'Bảo hiểm', 'description' => 'Bảo hiểm nhân thọ, sức khoẻ, xe cộ, du lịch.', 'sort_order' => 80, 'is_default' => false],
        ['slug' => 'y-te-benh-vien', 'name' => 'Y tế & Bệnh viện', 'description' => 'Bệnh viện, phòng khám, nhà thuốc.', 'sort_order' => 90, 'is_default' => false],
        ['slug' => 'giao-duc-hoc-phi', 'name' => 'Giáo dục & Học phí', 'description' => 'Học phí, khóa học, sách vở.', 'sort_order' => 100, 'is_default' => false],
        ['slug' => 'hoa-don-dien-nuoc-internet', 'name' => 'Hóa đơn điện, nước, internet', 'description' => 'Tiền điện, nước, internet, viễn thông.', 'sort_order' => 110, 'is_default' => false],
        ['slug' => 'chi-tieu-nuoc-ngoai', 'name' => 'Chi tiêu nước ngoài / Ngoại tệ', 'description' => 'Giao dịch quốc tế, ngoại tệ.', 'sort_order' => 120, 'is_default' => false],
        ['slug' => 'ung-dung-so-dich-vu-giai-tri', 'name' => 'Ứng dụng số & Dịch vụ giải trí trực tuyến', 'description' => 'Ứng dụng số, streaming, nhạc, xem phim.', 'sort_order' => 130, 'is_default' => false],
        ['slug' => 'thoi-trang', 'name' => 'Thời trang', 'description' => 'Quần áo, giày dép, phụ kiện.', 'sort_order' => 140, 'is_default' => false],
        ['slug' => 'shopee', 'name' => 'Shopee', 'description' => 'Giao dịch tại sàn Shopee.', 'sort_order' => 150, 'is_default' => false],
        ['slug' => 'tiki', 'name' => 'Tiki', 'description' => 'Giao dịch tại sàn Tiki.', 'sort_order' => 160, 'is_default' => false],
        ['slug' => 'lazada', 'name' => 'Lazada', 'description' => 'Giao dịch tại sàn Lazada.', 'sort_order' => 170, 'is_default' => false],
        ['slug' => 'tiktok', 'name' => 'Tiktok', 'description' => 'Giao dịch tại sàn TikTok Shop.', 'sort_order' => 180, 'is_default' => false],
        ['slug' => 'san-thuong-mai-dien-tu', 'name' => 'Sàn thương mại điện tử', 'description' => 'Giao dịch trên sàn TMĐT không xác định sàn cụ thể.', 'sort_order' => 190, 'is_default' => false],
    ];

    public function run(): void
    {
        DB::connection(self::CONNECTION)->transaction(function (): void {
            // KHÔNG seed `credit_card_products` nữa (Phase 1B): luồng chính là
            // Bank + tên thẻ gợi nhớ. Bảng vẫn còn nguyên trong DB, chỉ không
            // được đẩy dữ liệu vào nữa — xem migration 000011.
            $this->seedBanks();
            $categories = $this->seedCategories();
            $this->seedBuiltinTemplate($categories);
        });
    }

    /**
     * @return array<string, Bank>
     */
    private function seedBanks(): array
    {
        $this->assertNoDuplicateCanonicalIdentity();

        $banks = [];

        foreach (self::BANKS as $row) {
            $banks[$row['slug']] = Bank::updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'short_name' => $row['short_name'],
                    'is_active' => true,
                    'aliases' => $row['aliases'] ?? [],
                ]
            );
        }

        $this->deactivateNonCanonicalBanks(array_keys($banks));

        return $banks;
    }

    /**
     * Chặn lỗi "hai bank cùng một pháp nhân" NGAY TRƯỚC khi ghi DB.
     *
     * Bắt buộc phải fail loudly: nếu sau này owner thêm lại dòng `scb`, seeder
     * phải báo lỗi chứ không âm thầm tạo bank trùng.
     */
    private function assertNoDuplicateCanonicalIdentity(): void
    {
        $seen = [
            'slug' => [],
            'short_name' => [],
            'alias' => [],
        ];

        foreach (self::BANKS as $row) {
            $slug = strtolower($row['slug']);
            $short = strtolower($row['short_name']);

            foreach (['slug' => $slug, 'short_name' => $short] as $field => $value) {
                if (isset($seen[$field][$value])) {
                    throw new RuntimeException(sprintf(
                        'Bank master trùng %s "%s": "%s" và "%s". Một pháp nhân chỉ được có MỘT bản ghi canonical.',
                        $field,
                        $value,
                        $seen[$field][$value],
                        $row['name']
                    ));
                }

                $seen[$field][$value] = $row['name'];
            }

            foreach ($row['aliases'] ?? [] as $alias) {
                $alias = strtolower($alias);

                // Alias phải là mã CỒ (không được là slug của bank khác) và
                // không được trùng bất kỳ mã canonical nào.
                if (isset($seen['slug'][$alias])) {
                    throw new RuntimeException(sprintf(
                        'Alias "%s" của bank "%s" trùng canonical slug của bank "%s".',
                        $alias,
                        $row['name'],
                        $seen['slug'][$alias]
                    ));
                }

                if (isset($seen['alias'][$alias])) {
                    throw new RuntimeException(sprintf(
                        'Alias "%s" bị dùng cho cả "%s" và "%s".',
                        $alias,
                        $seen['alias'][$alias],
                        $row['name']
                    ));
                }

                $seen['alias'][$alias] = $row['name'];
            }
        }

        // Mọi mã legacy phải trỏ tới một canonical bank thật.
        foreach (self::LEGACY_CODES as $legacy => $canonical) {
            $slugs = array_column(self::BANKS, 'slug');

            if (! in_array($canonical, $slugs, true)) {
                throw new RuntimeException(sprintf(
                    'Mã legacy "%s" trỏ tới canonical "%s" không tồn tại trong bank master.',
                    $legacy,
                    $canonical
                ));
            }
        }
    }

    /**
     * Tắt các bank không còn thuộc danh sách canonical (ví dụ fixture Phase 1A
     * như `techcombank`, hay dòng `scb` trước khi được gộp).
     *
     * CHỈ set `is_active = false`, KHÔNG xoá: `credit_card_user_cards.bank_id` là
     * NOT NULL + FK, và xoá bank sẽ phá dữ liệu thẻ của user.
     */
    private function deactivateNonCanonicalBanks(array $canonicalSlugs): void
    {
        Bank::query()
            ->whereNotIn('slug', $canonicalSlugs)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    /**
     * @return array<string, Category>
     */
    private function seedCategories(): array
    {
        $categories = [];

        foreach (self::CATEGORIES as $row) {
            $category = Category::query()
                ->where('scope', Category::SCOPE_SYSTEM)
                ->where('owner_user_id', Category::SYSTEM_OWNER_ID)
                ->where('slug', $row['slug'])
                ->first();

            // CHỈ tạo khi còn thiếu. KHÔNG overwrite `name`/`description`/
            // `sort_order`/`is_active`/`is_default` mà admin đã chỉnh qua module
            // "Quản lý danh mục hệ thống".
            if ($category === null) {
                $category = Category::create([
                    'scope' => Category::SCOPE_SYSTEM,
                    'owner_user_id' => Category::SYSTEM_OWNER_ID,
                    'slug' => $row['slug'],
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'is_active' => true,
                    'is_default' => $row['is_default'],
                    'sort_order' => $row['sort_order'],
                ]);
            }

            $categories[$row['slug']] = $category;
        }

        return $categories;
    }

    /**
     * Template mẫu: "Cashback phổ thông" — minh hoạ đủ cấu trúc policy thật.
     *
     * @param  array<string, Category>  $categories
     */
    private function seedBuiltinTemplate(array $categories): void
    {
        $template = PolicyTemplate::updateOrCreate(
            [
                'scope' => PolicyTemplate::SCOPE_SYSTEM,
                'owner_user_id' => PolicyTemplate::SYSTEM_OWNER_ID,
                'slug' => 'cashback-pho-thong',
            ],
            [
                'name' => 'Cashback phổ thông',
                'description' => 'Template khởi đầu: 2 bậc chi tiêu, cashback theo danh mục, có trần.',
                'is_builtin' => true,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        $blueprint = $template->blueprint()->first();

        if ($blueprint !== null) {
            // Đã seed trước đó ⇒ giữ nguyên để không phá cấu hình đã chỉnh.
            return;
        }

        $blueprint = Policy::create([
            'user_card_id' => null,
            'template_id' => $template->id,
            'version_no' => 1,
            'status' => Policy::STATUS_ACTIVE,
            'name' => 'Cashback phổ thông',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            // Minimum spend thuộc POLICY, KHÔNG thuộc từng danh mục.
            'min_total_spend' => '1000000.00',
            // Cap 3/3 — trần tổng mỗi kỳ.
            'max_cashback_total_per_period' => '5000000.00',
            'rounding_mode' => 'round',
            'note' => 'Template hệ thống, dùng làm mẫu cho thẻ mới.',
        ]);
        $blueprint->forceFill(['root_policy_id' => $blueprint->id])->save();

        $basic = PolicyTier::create([
            'policy_id' => $blueprint->id,
            'name' => 'Chi tiêu cơ bản',
            'sort_order' => 1,
            'min_total_spend' => 0,
            'max_total_spend' => '5000000.00',
        ]);

        $advanced = PolicyTier::create([
            'policy_id' => $blueprint->id,
            'name' => 'Chi tiêu nâng cao',
            'sort_order' => 2,
            'min_total_spend' => '5000000.00',
            'max_total_spend' => null,
        ]);

        // Mỗi bậc bắt buộc có đúng một fallback "📦 Các danh mục còn lại"
        // (category_id = NULL, 0%, không tính vào trần của bậc).
        $rulesService = app(CategoryRuleService::class);
        $rulesService->ensureSingleFallback($basic);
        $rulesService->ensureSingleFallback($advanced);

        $this->seedRules($basic, $categories, [
            ['slug' => 'mua-sam-truc-tuyen', 'from' => 0, 'to' => null, 'percent' => '2.000', 'cap_tx' => '100000.00', 'cap_cat' => '1000000.00'],
            ['slug' => 'am-thuc-an-uong', 'from' => 0, 'to' => null, 'percent' => '1.500', 'cap_tx' => '50000.00', 'cap_cat' => '500000.00'],
            ['slug' => 'goi-xe-di-chuyen', 'from' => 0, 'to' => null, 'percent' => '1.000', 'cap_tx' => null, 'cap_cat' => null],
            ['slug' => 've-may-bay-du-lich', 'from' => 0, 'to' => null, 'percent' => '3.000', 'cap_tx' => '500000.00', 'cap_cat' => '2000000.00'],
            ['slug' => 'sieu-thi-tien-loi', 'from' => 0, 'to' => null, 'percent' => '1.000', 'cap_tx' => null, 'cap_cat' => null],
            ['slug' => 'ung-dung-so-dich-vu-giai-tri', 'from' => 0, 'to' => null, 'percent' => '1.000', 'cap_tx' => null, 'cap_cat' => null],
            ['slug' => 'shopee', 'from' => 0, 'to' => null, 'percent' => '2.500', 'cap_tx' => '100000.00', 'cap_cat' => '1000000.00'],
        ]);

        // Bậc nâng cao: minh hoạ MỘT danh mục có nhiều mức theo khoảng chi tiêu.
        $this->seedRules($advanced, $categories, [
            ['slug' => 'mua-sam-truc-tuyen', 'from' => 0, 'to' => '5000000.00', 'percent' => '4.000', 'cap_tx' => '200000.00', 'cap_cat' => '2000000.00'],
            ['slug' => 'mua-sam-truc-tuyen', 'from' => '5000000.00', 'to' => null, 'percent' => '6.000', 'cap_tx' => '300000.00', 'cap_cat' => '3000000.00'],
            ['slug' => 'am-thuc-an-uong', 'from' => 0, 'to' => null, 'percent' => '2.000', 'cap_tx' => '50000.00', 'cap_cat' => '800000.00'],
            ['slug' => 'goi-xe-di-chuyen', 'from' => 0, 'to' => null, 'percent' => '2.000', 'cap_tx' => null, 'cap_cat' => null],
            ['slug' => 've-may-bay-du-lich', 'from' => 0, 'to' => null, 'percent' => '5.000', 'cap_tx' => '800000.00', 'cap_cat' => '3000000.00'],
            ['slug' => 'sieu-thi-tien-loi', 'from' => 0, 'to' => null, 'percent' => '1.500', 'cap_tx' => null, 'cap_cat' => null],
            ['slug' => 'ung-dung-so-dich-vu-giai-tri', 'from' => 0, 'to' => null, 'percent' => '2.000', 'cap_tx' => null, 'cap_cat' => null],
            ['slug' => 'shopee', 'from' => 0, 'to' => null, 'percent' => '4.000', 'cap_tx' => '200000.00', 'cap_cat' => '2500000.00'],
        ]);
    }

    /**
     * @param  array<string, Category>  $categories
     * @param  array<int, array{slug:string, from:string, to:?string, percent:string, cap_tx:?string, cap_cat:?string}>  $rules
     */
    private function seedRules(PolicyTier $tier, array $categories, array $rules): void
    {
        $sortOrder = 0;

        foreach ($rules as $rule) {
            PolicyTierCategory::updateOrCreate(
                [
                    'tier_id' => $tier->id,
                    'category_id' => $categories[$rule['slug']]->id,
                    'spend_from' => $rule['from'],
                ],
                [
                    'spend_to' => $rule['to'],
                    'cashback_percent' => $rule['percent'],
                    'max_cashback_per_transaction' => $rule['cap_tx'],
                    'max_cashback_per_category_per_period' => $rule['cap_cat'],
                    'min_transaction_amount' => null,
                    'is_enabled' => true,
                    'sort_order' => $sortOrder++,
                ]
            );
        }
    }
}
