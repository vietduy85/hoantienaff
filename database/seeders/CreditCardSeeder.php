<?php

namespace Database\Seeders;

use App\Models\CreditCard\Bank;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\CategoryComboItem;
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
            $banks = $this->seedBanks();
            $categories = $this->seedCategories();
            $this->seedBuiltinTemplate($categories);
            $this->seedShinhanSupremeTemplate($categories);
            $combos = $this->seedVibCombos($categories);
            $this->seedVibMaxCardVisaTemplates($banks, $categories, $combos);
            $oneCardCombos = $this->seedVibOneCardCombos($categories);
            $this->seedVibOneCardTemplates($banks, $categories, $oneCardCombos);
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
     * Chính sách hệ thống "Shinhan Supreme Visa" — theo PDF hoàn tiền
     * "Shinhan Supreme Card_VN".
     *
     * Cấu trúc (RETROACTIVE theo TỔNG chi tiêu hợp lệ của kỳ sao kê):
     *   - Bậc 1  [0, 3 triệu):   MỌI danh mục 0,3%. Bậc này CHỈ có fallback,
     *                             KHÔNG có rule danh mục nào ⇒ danh mục đặc biệt
     *                             tự động về 0,3% theo engine khi chi tiêu dưới
     *                             3 triệu. Không trần hoàn tiền.
     *   - Bậc 2  [3, 15 triệu):  TMĐT 12%, Giáo dục 12%, Y tế 6%, Bảo hiểm 6%,
     *                             còn lại 0,3%; trần TOÀN BẬC 300.000đ/kỳ.
     *   - Bậc 3  [≥ 15 triệu):   cùng tỷ lệ Bậc 2; trần TOÀN BẬC 1.000.000đ/kỳ.
     *
     * Trần toàn bậc do engine giữ ở `credit_card_policy_tiers.max_cashback_per_period`
     * (Cap 3), áp cho MỌI rule có `counts_toward_tier_cap = true`. Fallback 0,3%
     * CỦA SHINHAN cũng được seed với cờ `true` để nó TIÊU THỤ ngân sách trần của
     * bậc giống hệt danh mục đặc biệt — đúng nghĩa "hoàn tiền tối đa của bậc"
     * (PDF). Các đường clone/copy rule đều giữ nguyên cờ này (xem
     * `PolicyCloneService::copyChildren`, `CategoryRuleService::cloneRuleTo`).
     *
     * Ánh xạ danh mục (chỉ dùng danh mục hệ thống CÓ SẴN):
     *   - "Thương mại điện tử" → `san-thuong-mai-dien-tu`
     *     (Sàn thương mại điện tử — danh mục "hệ thống" cho TMĐT không thuộc
     *     sàn cụ thể nào; sàn cụ thể Shopee/Tiki/... vẫn do admin own).
     *   - "Giáo dục & Học phí" → `giao-duc-hoc-phi`.
     *   - "Bệnh viện/Y tế"     → `y-te-benh-vien`.
     *   - "Bảo hiểm"           → `bao-hiem`.
     *   - "Điện máy" / "Nhà sách": KHÔNG có danh mục hệ thống tương ứng ⇒ KHÔNG
     *     gán tỷ lệ (giữ fallback 0,3%), không gán lệch sang danh mục gần nghĩa.
     *   - Netflix/Spotify/ChatGPT 50% (trần 50.000đ/kỳ): engine KHÔNG khớp theo
     *     tên merchant ⇒ KHÔNG cấu hình ở đây. Ưu đãi sinh nhật / về thẻ / trả
     *     góp 0% không phải hoàn tiền định kỳ ⇒ KHÔNG cấu hình. Cả hai phần này
     *     được liệt kê trong báo cáo như "chưa hỗ trợ cấu hình".
     *
     * @param  array<string, Category>  $categories
     */
    private function seedShinhanSupremeTemplate(array $categories): void
    {
        $template = PolicyTemplate::updateOrCreate(
            [
                'scope' => PolicyTemplate::SCOPE_SYSTEM,
                'owner_user_id' => PolicyTemplate::SYSTEM_OWNER_ID,
                'slug' => 'shinhan-supreme-visa',
            ],
            [
                'name' => 'Shinhan Supreme Visa',
                'description' => 'Hoàn tiền 3 bậc theo tổng chi tiêu kỳ sao kê: TMĐT & Giáo dục 12%, Y tế & Bảo hiểm 6%, còn lại 0,3%.',
                'is_builtin' => true,
                'is_active' => true,
                'sort_order' => 2,
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
            'name' => 'Shinhan Supreme Visa',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            // Không có điều kiện chi tiêu tối thiểu (Bậc 1 phủ từ 0đ).
            'min_total_spend' => 0,
            // Không dùng cột legacy: trần toàn bậc nằm ở TỪNG BẬC (Cap 3).
            'max_cashback_total_per_period' => null,
            'rounding_mode' => 'round',
            'note' => 'Chính sách hệ thống Shinhan Supreme Visa.',
        ]);
        $blueprint->forceFill(['root_policy_id' => $blueprint->id])->save();

        $tiersData = [
            ['name' => 'Bậc 1 – Dưới 3 triệu', 'min' => 0, 'max' => '3000000.00', 'cap' => null],
            ['name' => 'Bậc 2 – Từ 3 đến 15 triệu', 'min' => '3000000.00', 'max' => '15000000.00', 'cap' => '300000.00'],
            ['name' => 'Bậc 3 – Trên 15 triệu', 'min' => '15000000.00', 'max' => null, 'cap' => '1000000.00'],
        ];

        $rulesService = app(CategoryRuleService::class);
        $tiers = [];

        foreach ($tiersData as $index => $data) {
            $tier = PolicyTier::create([
                'policy_id' => $blueprint->id,
                'name' => $data['name'],
                'sort_order' => $index + 1,
                'min_total_spend' => $data['min'],
                'max_total_spend' => $data['max'],
                'max_cashback_per_period' => $data['cap'],
            ]);

            // Fallback 0,3% cho MỌI bậc, TÍNH VÀO trần toàn bậc (cờ true).
            $rulesService->ensureSingleFallback($tier, [
                'cashback_percent' => '0.300',
                'counts_toward_tier_cap' => true,
            ]);

            $tiers[] = $tier;
        }

        // Bậc 1: CHỈ fallback — danh mục đặc biệt rơi về 0,3% khi tổng dưới 3tr.

        // Bậc 2 & 3: cùng bộ tỷ lệ đặc biệt (PDF: cùng tỷ lệ, khác trần).
        foreach ([1, 2] as $index) {
            $this->seedShinhanRules($tiers[$index], $categories, [
                ['slug' => 'san-thuong-mai-dien-tu', 'percent' => '12.000'],
                ['slug' => 'giao-duc-hoc-phi', 'percent' => '12.000'],
                ['slug' => 'y-te-benh-vien', 'percent' => '6.000'],
                ['slug' => 'bao-hiem', 'percent' => '6.000'],
            ]);
        }

        // Thứ tự hiển thị chuẩn của TỪNG BẬC: danh mục cụ thể trước, fallback
        // "📦 Các danh mục còn lại" cuối cùng. `ensureSingleFallback` phía trên tạo
        // fallback TRƯỚC khi có rule ⇒ nếu không chuẩn hoá cuối hàm, fallback sẽ
        // chen giữa danh sách (sort_order = 1).
        foreach ($tiers as $tier) {
            $rulesService->normaliseRuleOrder($tier);
        }
    }

    /**
     * Rule danh mục của Shinhan: một mức duy nhất "từ 0, không trần giá trị hóa
     * đơn", cashback TÍNH vào trần toàn bậc.
     *
     * @param  array<string, Category>  $categories
     * @param  array<int, array{slug: string, percent: string}>  $rules
     */
    private function seedShinhanRules(PolicyTier $tier, array $categories, array $rules): void
    {
        $sortOrder = 0;

        foreach ($rules as $rule) {
            PolicyTierCategory::updateOrCreate(
                [
                    'tier_id' => $tier->id,
                    'category_id' => $categories[$rule['slug']]->id,
                    'spend_from' => 0,
                ],
                [
                    'spend_to' => null,
                    'counts_toward_tier_cap' => true,
                    'cashback_percent' => $rule['percent'],
                    'max_cashback_per_transaction' => null,
                    'max_cashback_per_category_per_period' => null,
                    'min_transaction_amount' => null,
                    'is_enabled' => true,
                    'sort_order' => $sortOrder++,
                ]
            );
        }
    }

    // =====================================================================
    // VIB Max Card Visa — 4 chính sách hệ thống (Starter / Plus / Pro / Elite)
    // =====================================================================

    /**
     * Combo "bộ danh mục" của VIB Max Card Visa.
     *
     * Sau chuẩn hoá, chính sách chỉ còn 3 NHÓM NHIỀU DANH MỤC cần gom combo
     * (Mua sắm / Du lịch / Online Transactions). Hai nhóm chỉ có ĐÚNG MỘT danh
     * mục — Ẩm thực (`am-thuc-an-uong`) và Giải trí
     * (`ung-dung-so-dich-vu-giai-tri`) — được chính sách trỏ THẲNG bằng
     * `category_id` (xem `seedVibMaxCardVisaTemplates`), không bọc combo
     * một-phần-tử nữa. Rule của chính sách trỏ combo bằng `combo_id` — một rule,
     * nhiều danh mục (migration 000020).
     *
     * Membership (ánh xạ sang danh mục hệ thống CÓ SẴN, chỉ bảo thủ):
     *   - Mua sắm   : siêu thị, thời trang, cửa hàng tiện lợi, điện máy,
     *                 nhà sách, chăm sóc sắc đẹp.
     *   - Du lịch   : vé máy bay & du lịch, khách sạn & lưu trú, chi tiêu
     *                 nước ngoài / ngoại tệ.
     *   - Online    : mua sắm trực tuyến, sàn TMĐT, Shopee, Tiki, Lazada, Tiktok.
     *
     * Các combo rời nhau hoàn toàn (một danh mục chỉ thuộc MỘT nhóm) nên engine
     * không bao giờ khớp một giao dịch vào hai rule combo trong cùng bậc. Danh
     * mục ngoài các nhóm rơi về fallback 0,1% của chính sách.
     *
     * Seeder CHỈ THÊM membership, KHÔNG gỡ: admin có thể đã bổ sung danh mục
     * qua module quản lý combo — bộ seed chỉ đảm bảo bộ gốc có mặt.
     *
     * @param  array<string, Category>  $categories  (slug ⇒ Category)
     * @return array<string, CategoryCombo> (combo slug ⇒ CategoryCombo)
     */
    private function seedVibCombos(array $categories): array
    {
        $combos = [];

        $definition = [
            [
                'slug' => 'vib-shopping',
                'name' => 'Mua sắm — Shopping',
                'description' => 'Nhóm "Mua sắm" của VIB Max Card Visa: siêu thị, thời trang, cửa hàng tiện lợi, điện máy, nhà sách, chăm sóc sắc đẹp.',
                'sort_order' => 10,
                'category_slugs' => ['sieu-thi-tien-loi', 'thoi-trang', 'cua-hang-tien-loi', 'dien-may', 'nha-sach', 'cham-soc-sac-dep'],
            ],
            [
                'slug' => 'vib-travel',
                'name' => 'Du lịch — Travel',
                'description' => 'Nhóm "Du lịch" của VIB Max Card Visa: vé máy bay, khách sạn & lưu trú, chi tiêu nước ngoài.',
                'sort_order' => 20,
                'category_slugs' => ['ve-may-bay-du-lich', 'khach-san-luu-tru', 'chi-tieu-nuoc-ngoai'],
            ],
            [
                'slug' => 'vib-online',
                'name' => 'Giao dịch trực tuyến — Online Transactions',
                'description' => 'Nhóm "Giao dịch trực tuyến" của VIB Max Card Visa: mua sắm trực tuyến, sàn TMĐT, Shopee, Tiki, Lazada, Tiktok.',
                'sort_order' => 50,
                'category_slugs' => ['mua-sam-truc-tuyen', 'san-thuong-mai-dien-tu', 'shopee', 'tiki', 'lazada', 'tiktok'],
            ],
        ];

        foreach ($definition as $row) {
            $slug = $row['slug'];
            $categorySlugs = $row['category_slugs'];
            unset($row['slug'], $row['category_slugs']);

            $combo = CategoryCombo::updateOrCreate(
                [
                    'scope' => CategoryCombo::SCOPE_SYSTEM,
                    'owner_user_id' => CategoryCombo::SYSTEM_OWNER_ID,
                    'slug' => $slug,
                ],
                $row
            );

            $existingIds = $combo->items()->pluck('category_id')->all();
            $already = array_flip(array_map('intval', $existingIds));
            $sortOrder = count($existingIds);

            foreach ($categorySlugs as $categorySlug) {
                // Danh mục đã biết từ seeder hoặc được admin tạo trước (vd.
                // "Điện máy", "Nhà sách"...) — đọc theo slug để DB live có đủ,
                // DB test (mới) chỉ có bộ danh mục gốc ⇒ bỏ qua những cái thiếu.
                $category = $categories[$categorySlug] ?? Category::query()
                    ->where('scope', Category::SCOPE_SYSTEM)
                    ->where('owner_user_id', Category::SYSTEM_OWNER_ID)
                    ->where('slug', $categorySlug)
                    ->first();

                if ($category === null || isset($already[(int) $category->id])) {
                    continue;
                }

                CategoryComboItem::create([
                    'combo_id' => $combo->id,
                    'category_id' => $category->id,
                    'sort_order' => $sortOrder++,
                ]);
            }

            $combos[$slug] = $combo;
        }

        return $combos;
    }

    /**
     * 4 chính sách hệ thống VIB Max Card Visa (Starter / Plus / Pro / Elite).
     *
     * Mỗi chính sách MỘT bậc chi tiêu duy nhất, toàn bộ chi tiêu hợp lệ:
     *   - 5 mục tiêu ở TỶ LỆ ƯU ĐÃI CAO của hạng thẻ: 3 nhóm nhiều danh mục trỏ
     *     combo (Mua sắm / Du lịch / Online) + 2 danh mục trực tiếp (Ẩm thực,
     *     Giải trí) sau chuẩn hoá;
     *   - mọi danh mục còn lại ở TỶ LỆ NỀN 0,1%;
     *   - trần hoàn tiền/THÁNG = hạn mức riêng theo hạng (Cap 3: trần TOÀN BẬC).
     *
     * Starter 0,5% / Plus 5% / Pro 8% / Elite 10%; trần 500k / 500k / 1M / 1,5M.
     *
     * Cờ `counts_toward_tier_cap = true` cho CẢ rule nhóm lẫn fallback: đồng nào
     * hoàn cũng tiêu thụ ngân sách trần của bậc — đúng nghĩa "hoàn tối đa/kỳ".
     *
     * GIAN GIỚI (báo cáo X.7): chính sách gốc cho CHỌN MỘT nhóm ưu tiên, engine
     * không có khái niệm "1 nhóm được chọn" per-card ⇒ 5 mục tiêu đều nhận tỷ lệ
     * cao. Trần theo danh mục (Cap 2) và trần giao dịch đặc biệt 10k/kỳ: nguồn
     * chưa có số liệu / engine chưa có khái niệm ⇒ KHÔNG cấu hình (ghi trong
     * báo cáo).
     *
     * @param  array<string, Bank>  $banks  (slug ⇒ Bank)
     * @param  array<string, Category>  $categories
     * @param  array<string, CategoryCombo>  $combos
     */
    private function seedVibMaxCardVisaTemplates(array $banks, array $categories, array $combos): void
    {
        $vib = $banks['vib'];

        $plans = [
            ['slug' => 'vib-max-card-visa-starter', 'label' => 'Starter', 'sort_order' => 30, 'percent' => '0.500', 'cap' => '500000.00', 'description' => 'VIB Max Card Visa Starter: các nhóm mua sắm, du lịch, ẩm thực, giải trí, giao dịch trực tuyến được hoàn 0,5%, các danh mục còn lại 0,1%; tối đa 500.000đ/tháng.'],
            ['slug' => 'vib-max-card-visa-plus', 'label' => 'Plus', 'sort_order' => 31, 'percent' => '5.000', 'cap' => '500000.00', 'description' => 'VIB Max Card Visa Plus: các nhóm mua sắm, du lịch, ẩm thực, giải trí, giao dịch trực tuyến được hoàn 5%, các danh mục còn lại 0,1%; tối đa 500.000đ/tháng.'],
            ['slug' => 'vib-max-card-visa-pro', 'label' => 'Pro', 'sort_order' => 32, 'percent' => '8.000', 'cap' => '1000000.00', 'description' => 'VIB Max Card Visa Pro: các nhóm mua sắm, du lịch, ẩm thực, giải trí, giao dịch trực tuyến được hoàn 8%, các danh mục còn lại 0,1%; tối đa 1.000.000đ/tháng.'],
            ['slug' => 'vib-max-card-visa-elite', 'label' => 'Elite', 'sort_order' => 33, 'percent' => '10.000', 'cap' => '1500000.00', 'description' => 'VIB Max Card Visa Elite: các nhóm mua sắm, du lịch, ẩm thực, giải trí, giao dịch trực tuyến được hoàn 10%, các danh mục còn lại 0,1%; tối đa 1.500.000đ/tháng.'],
        ];

        foreach ($plans as $plan) {
            $name = 'VIB Max Card Visa - '.$plan['label'];

            $template = PolicyTemplate::updateOrCreate(
                [
                    'scope' => PolicyTemplate::SCOPE_SYSTEM,
                    'owner_user_id' => PolicyTemplate::SYSTEM_OWNER_ID,
                    'slug' => $plan['slug'],
                ],
                [
                    'name' => $name,
                    'description' => $plan['description'],
                    'is_builtin' => true,
                    'is_active' => true,
                    'sort_order' => $plan['sort_order'],
                    // Chỉ áp dụng cho thẻ VIB có tên chứa "max card visa" (logical
                    // reference bank id — không FK, bank không bị khoá theo vòng đời).
                    'applicable_bank_id' => (int) $vib->id,
                    'applicable_product_match' => 'max card visa',
                ]
            );

            // Đã seed trước đó ⇒ giữ nguyên blueprint để không phá cấu hình đã chỉnh.
            if ($template->blueprint()->exists()) {
                continue;
            }

            $blueprint = Policy::create([
                'user_card_id' => null,
                'template_id' => $template->id,
                'version_no' => 1,
                'status' => Policy::STATUS_ACTIVE,
                'name' => $name,
                'effective_from' => now()->toDateString(),
                'effective_to' => null,
                // Không điều kiện chi tiêu tối thiểu (bậc phủ từ 0đ).
                'min_total_spend' => 0,
                // Trần toàn kỳ nằm ở TỪNG BẬC (Cap 3), cột policy legacy bỏ trống.
                'max_cashback_total_per_period' => null,
                'rounding_mode' => 'round',
                'note' => 'Chính sách hệ thống '.$name.'.',
            ]);
            $blueprint->forceFill(['root_policy_id' => $blueprint->id])->save();

            $tier = PolicyTier::create([
                'policy_id' => $blueprint->id,
                'name' => 'Toàn bộ chi tiêu hợp lệ',
                'sort_order' => 1,
                'min_total_spend' => 0,
                'max_total_spend' => null,
                // Cap 3 — trần hoàn tiền TOÀN KỲ của hạng thẻ.
                'max_cashback_per_period' => $plan['cap'],
            ]);

            // Fallback 0,1% cho MỌI danh mục ngoài 5 nhóm. TÍNH vào trần toàn bậc
            // (cờ true) — giống cách Shinhan seed fallback: đồng hoàn nào cũng
            // tiêu thụ ngân sách trần, đúng nghĩa "hoàn tối đa/kỳ" của chính sách.
            $rulesService = app(CategoryRuleService::class);
            $rulesService->ensureSingleFallback($tier, [
                'cashback_percent' => '0.100',
                'counts_toward_tier_cap' => true,
            ]);

            // 5 mục tiêu chi tiêu — mỗi mục tiêu MỘT rule ở tỷ lệ ưu đãi cao:
            //   3 nhóm nhiều danh mục gom bằng combo (Mua sắm / Du lịch / Online);
            //   2 nhóm một danh mục trỏ THẲNG bằng `category_id` (Ẩm thực, Giải
            //   trí) — đã chuẩn hoá khỏi combo một-phần-tử.
            $sortOrder = 0;

            $targets = [
                ['combo_id' => $combos['vib-shopping']->id],
                ['combo_id' => $combos['vib-travel']->id],
                ['combo_id' => $combos['vib-online']->id],
                ['category_id' => $categories['am-thuc-an-uong']->id],
                ['category_id' => $categories['ung-dung-so-dich-vu-giai-tri']->id],
            ];

            foreach ($targets as $target) {
                PolicyTierCategory::updateOrCreate(
                    [
                        'tier_id' => $tier->id,
                        'combo_id' => $target['combo_id'] ?? null,
                        'category_id' => $target['category_id'] ?? null,
                        'spend_from' => 0,
                    ],
                    [
                        'spend_to' => null,
                        'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                        'counts_toward_tier_cap' => true,
                        'cashback_percent' => $plan['percent'],
                        'max_cashback_per_transaction' => null,
                        'max_cashback_per_category_per_period' => null,
                        'min_transaction_amount' => null,
                        'is_enabled' => true,
                        'sort_order' => $sortOrder++,
                    ]
                );
            }

            // Thứ tự hiển thị chuẩn: danh mục cụ thể → combo → fallback cuối.
            // Fallback được tạo TRƯỚC ở trên (sort_order gốc = 1) nên phải chuẩn
            // hoá ở cuối.
            $rulesService->normaliseRuleOrder($tier);
        }
    }

    // =====================================================================
    // VIB One Card Visa — 3 chính sách hệ thống (Gold / Platinum / Signature)
    // =====================================================================

    /**
     * Combo "kho DMCT" nhiều danh mục của VIB One Card (PDF mục 2.12).
     *
     * Đặc trưng One Card khác Max Card Visa: KHÔNG phải bộ danh mục cố định —
     * khách hàng quyền CHỌN tối đa N DMCT theo hạng (Gold 01 / Platinum 02 /
     * Signature 03), các DMCT đã chọn cùng nhận tỷ lệ ưu đãi theo hạng, mọi chi
     * tiêu không nằm trong DMCT đã chọn rơi về nền 0,1%. Vì vậy:
     *   - 2 combo dưới là "kho danh mục nhiều phần tử để chọn" — blueprint của
     *     từng hạng CHỈ gắn rule cho các DMCT theo cấu hình khởi tạo (xem
     *     `seedVibOneCardTemplates`);
     *   - admin chuyển DMCT cho một thẻ = clone policy rồi đổi membership của bộ
     *     DMCT (thêm/bớt rule combo), không phải sửa chung trong seeder.
     *
     * Ánh xạ sang danh mục hệ thống CÓ SẴN (chỉ bảo thủ, không sinh danh mục mới),
     * giống cách `seedVibCombos` của Max Card Visa:
     *   - Mua sắm          : siêu thị, thời trang, điện máy, cửa hàng tiện lợi,
     *                          nhà sách, chăm sóc sắc đẹp (nhóm MCC mua sắm chung).
     *   - Du lịch          : vé máy bay & du lịch, khách sạn & lưu trú
     *                          (nhóm MCC 3000-3999 + 4511 + 4722 trong PDF).
     *
     * Ba DMCT chỉ có ĐÚNG MỘT danh mục — Giáo dục (`giao-duc-hoc-phi`), Bảo hiểm
     * (`bao-hiem`), Chi tiêu nước ngoài (`chi-tieu-nuoc-ngoai`) — sau chuẩn hoá
     * được chính sách trỏ THẲNG bằng `category_id`, không bọc combo một-phần-tử
     * nữa. "Chi tiêu nước ngoài" trong PDF xác định theo kênh POS + ngoại tệ chứ
     * KHÔNG theo MCC ⇒ danh mục hệ thống `chi-tieu-nuoc-ngoai` là phép xấp xỉ gần
     * nhất; cùng giới hạn như báo cáo Max Card.
     *
     * Các combo rời nhau hoàn toàn (một danh mục chỉ thuộc MỘT nhóm) nên trong
     * cùng một bậc không bao giờ có hai rule combo cùng khớp một giao dịch.
     *
     * Seeder CHỈ THÊM membership, KHÔNG gỡ — admin có thể đã bổ sung danh mục
     * qua module quản lý combo (đồng bộ cách `seedVibCombos`).
     *
     * @param  array<string, Category>  $categories  (slug ⇒ Category)
     * @return array<string, CategoryCombo> (combo slug ⇒ CategoryCombo)
     */
    private function seedVibOneCardCombos(array $categories): array
    {
        $combos = [];

        $definition = [
            [
                'slug' => 'vib-one-card-mua-sam',
                'name' => 'Mua sắm — Shopping',
                'description' => 'DMCT "Mua sắm" của VIB One Card (PDF mục 2.12): siêu thị, thời trang, điện máy, cửa hàng tiện lợi, nhà sách, chăm sóc sắc đẹp.',
                'sort_order' => 60,
                'category_slugs' => ['sieu-thi-tien-loi', 'thoi-trang', 'dien-may', 'cua-hang-tien-loi', 'nha-sach', 'cham-soc-sac-dep'],
            ],
            [
                'slug' => 'vib-one-card-du-lich',
                'name' => 'Du lịch — Travel',
                'description' => 'DMCT "Du lịch" của VIB One Card (PDF mục 2.12): vé máy bay, khách sạn & lưu trú.',
                'sort_order' => 70,
                'category_slugs' => ['ve-may-bay-du-lich', 'khach-san-luu-tru'],
            ],
        ];

        foreach ($definition as $row) {
            $slug = $row['slug'];
            $categorySlugs = $row['category_slugs'];
            unset($row['slug'], $row['category_slugs']);

            $combo = CategoryCombo::updateOrCreate(
                [
                    'scope' => CategoryCombo::SCOPE_SYSTEM,
                    'owner_user_id' => CategoryCombo::SYSTEM_OWNER_ID,
                    'slug' => $slug,
                ],
                $row
            );

            $existingIds = $combo->items()->pluck('category_id')->all();
            $already = array_flip(array_map('intval', $existingIds));
            $sortOrder = count($existingIds);

            foreach ($categorySlugs as $categorySlug) {
                // Danh mục đã biết từ seeder hoặc được admin tạo trước — đọc theo
                // slug để DB live có đủ, DB test (mới) chỉ có bộ danh mục gốc ⇒
                // bỏ qua những cái thiếu (đồng bộ cách seedVibCombos).
                $category = $categories[$categorySlug] ?? Category::query()
                    ->where('scope', Category::SCOPE_SYSTEM)
                    ->where('owner_user_id', Category::SYSTEM_OWNER_ID)
                    ->where('slug', $categorySlug)
                    ->first();

                if ($category === null || isset($already[(int) $category->id])) {
                    continue;
                }

                CategoryComboItem::create([
                    'combo_id' => $combo->id,
                    'category_id' => $category->id,
                    'sort_order' => $sortOrder++,
                ]);
            }

            $combos[$slug] = $combo;
        }

        return $combos;
    }

    /**
     * 3 chính sách hệ thống VIB One Card Visa (Gold / Platinum / Signature).
     *
     * Trạng thái của One Card (PDF mục 2.12 "Ưu đãi 1 — Điểm thưởng"):
     *   - khách hàng được quyền đăng ký tối đa N DMCT theo hạng: Gold 01,
     *     Platinum 02, Signature 03; mọi DMCT đã đăng ký cùng nhận tỷ lệ theo
     *     hạng (Gold 3% / Platinum 6% / Signature 9%);
     *   - chi tiêu KHÔNG nằm trong DMCT đã đăng ký: nền 0,1% mọi hạng;
     *   - trần Điểm thưởng/kỳ sao kê: Gold 500k / Platinum 1M / Signature 1,5M;
     *   - đổi DMCT: 01 lượt/kỳ, hiệu lực ngay — không truy thu điểm cũ;
     *   - đăng ký/đổi qua website hoặc MyVIB.
     *
     * Blueprint của mỗi hạng = các rule DMCT theo CẤU HÌNH KHỞI TẠO (Gold: Mua
     * sắm; Platinum: Mua sắm, Bảo hiểm; Signature: Mua sắm, Giáo dục, Bảo hiểm) +
     * fallback 0,1%. Mua sắm là nhóm nhiều danh mục nên trỏ combo; Bảo hiểm /
     * Giáo dục chỉ một danh mục nên trỏ THẲNG `category_id` (đã chuẩn hoá). DMCT
     * không nằm trong cấu hình khởi tạo không có rule trong blueprint ⇒ được tính
     * nền 0,1% cho tới khi admin/thẻ đổi DMCT.
     *
     * GIAN GIỚI (báo cáo): trần riêng "Giao dịch đặc biệt" 10.000 điểm/kỳ và
     * khái niệm "DMCT được ĐĂNG KÝ / ĐƯỢC CHỌN" (tối đa N per thẻ) không có cơ
     * chế trong engine — chỉ có trần TOÀN BẬC (`max_cashback_per_period`) và rule
     * danh mục/combo cố định trong blueprint. KHÔNG cấu hình trần đặc biệt, ghi
     * nhận trong báo cáo (đồng bộ với Max Card Visa báo cáo X.7).
     *
     * Cờ `counts_toward_tier_cap = true` cho CẢ rule DMCT lẫn fallback: đồng nào
     * hoàn cũng tiêu thụ ngân sách trần của bậc — đúng nghĩa "hoàn tối đa/kỳ".
     *
     * @param  array<string, Bank>  $banks  (slug ⇒ Bank)
     * @param  array<string, Category>  $categories  (slug ⇒ Category)
     * @param  array<string, CategoryCombo>  $combos
     */
    private function seedVibOneCardTemplates(array $banks, array $categories, array $combos): void
    {
        $vib = $banks['vib'];

        $plans = [
            [
                'slug' => 'vib-one-card-visa-gold',
                'label' => 'Gold',
                'sort_order' => 40,
                'percent' => '3.000',
                'cap' => '500000.00',
                'default_targets' => [['combo' => 'vib-one-card-mua-sam']],
                'description' => 'VIB One Card Visa Gold: chi tiêu tại DMCT đã đăng ký (mặc định: Mua sắm) được hoàn 3%, ngoài DMCT đăng ký 0,1%; tối đa 500.000đ/kỳ sao kê. Đăng ký tối đa 1 DMCT, đổi 1 lần/kỳ.',
            ],
            [
                'slug' => 'vib-one-card-visa-platinum',
                'label' => 'Platinum',
                'sort_order' => 41,
                'percent' => '6.000',
                'cap' => '1000000.00',
                'default_targets' => [['combo' => 'vib-one-card-mua-sam'], ['category' => 'bao-hiem']],
                'description' => 'VIB One Card Visa Platinum: chi tiêu tại DMCT đã đăng ký (mặc định: Mua sắm, Bảo hiểm) được hoàn 6%, ngoài DMCT đăng ký 0,1%; tối đa 1.000.000đ/kỳ sao kê. Đăng ký tối đa 2 DMCT, đổi 1 lần/kỳ.',
            ],
            [
                'slug' => 'vib-one-card-visa-signature',
                'label' => 'Signature',
                'sort_order' => 42,
                'percent' => '9.000',
                'cap' => '1500000.00',
                'default_targets' => [['combo' => 'vib-one-card-mua-sam'], ['category' => 'giao-duc-hoc-phi'], ['category' => 'bao-hiem']],
                'description' => 'VIB One Card Visa Signature: chi tiêu tại DMCT đã đăng ký (mặc định: Mua sắm, Giáo dục, Bảo hiểm) được hoàn 9%, ngoài DMCT đăng ký 0,1%; tối đa 1.500.000đ/kỳ sao kê. Đăng ký tối đa 3 DMCT, đổi 1 lần/kỳ.',
            ],
        ];

        foreach ($plans as $plan) {
            $name = 'VIB One Card '.$plan['label'];

            $template = PolicyTemplate::updateOrCreate(
                [
                    'scope' => PolicyTemplate::SCOPE_SYSTEM,
                    'owner_user_id' => PolicyTemplate::SYSTEM_OWNER_ID,
                    'slug' => $plan['slug'],
                ],
                [
                    'name' => $name,
                    'description' => $plan['description'],
                    'is_builtin' => true,
                    'is_active' => true,
                    'sort_order' => $plan['sort_order'],
                    // Chỉ áp dụng cho thẻ VIB có tên chứa "one card" (logical
                    // reference bank id — không FK, bank không bị khoá theo vòng
                    // đời). One Card Visa / One Card Mastercard dùng chung chương
                    // trình (PDF chỉ ghi "Thẻ tín dụng VIB One Card") nên bỏ qua
                    // hậu tố thương hiệu trong từ khoá.
                    'applicable_bank_id' => (int) $vib->id,
                    'applicable_product_match' => 'one card',
                ]
            );

            // Đã seed trước đó ⇒ giữ nguyên blueprint để không phá cấu hình đã chỉnh.
            if ($template->blueprint()->exists()) {
                continue;
            }

            $blueprint = Policy::create([
                'user_card_id' => null,
                'template_id' => $template->id,
                'version_no' => 1,
                'status' => Policy::STATUS_ACTIVE,
                'name' => $name,
                'effective_from' => now()->toDateString(),
                'effective_to' => null,
                // Không điều kiện chi tiêu tối thiểu (bậc phủ từ 0đ).
                'min_total_spend' => 0,
                // Trần toàn kỳ nằm ở TỪNG BẬC (Cap toàn bậc), cột policy legacy bỏ trống.
                'max_cashback_total_per_period' => null,
                'rounding_mode' => 'round',
                'note' => 'Chính sách hệ thống '.$name.' ('.$plan['label'].').',
            ]);
            $blueprint->forceFill(['root_policy_id' => $blueprint->id])->save();

            $tier = PolicyTier::create([
                'policy_id' => $blueprint->id,
                'name' => 'Toàn bộ chi tiêu hợp lệ',
                'sort_order' => 1,
                'min_total_spend' => 0,
                'max_total_spend' => null,
                // Cap toàn bậc — trần Điểm thưởng/kỳ sao kê của hạng thẻ.
                'max_cashback_per_period' => $plan['cap'],
            ]);

            // Fallback 0,1% cho chi tiêu ngoài DMCT đã đăng ký. TÍNH vào trần toàn
            // bậc (cờ true) — đồng bộ cách seed Max Card / Shinhan: đồng hoàn nào
            // cũng tiêu thụ ngân sách trần.
            $rulesService = app(CategoryRuleService::class);
            $rulesService->ensureSingleFallback($tier, [
                'cashback_percent' => '0.100',
                'counts_toward_tier_cap' => true,
            ]);

            // Chỉ DMCT trong CẤU HÌNH KHỞI TẠO có rule ở tỷ lệ ưu đãi; DMCT còn
            // lại trong "kho" không có rule ở blueprint ⇒ rơi về nền 0,1% cho tới
            // khi được đăng ký (đổi DMCT = đổi bộ rule của bản sao thẻ). Nhóm nhiều
            // danh mục (Mua sắm) trỏ combo; DMCT một danh mục (Bảo hiểm, Giáo dục)
            // trỏ THẲNG `category_id`.
            $sortOrder = 0;

            foreach ($plan['default_targets'] as $target) {
                $comboId = isset($target['combo']) ? $combos[$target['combo']]->id : null;
                $categoryId = isset($target['category']) ? $categories[$target['category']]->id : null;

                PolicyTierCategory::updateOrCreate(
                    [
                        'tier_id' => $tier->id,
                        'combo_id' => $comboId,
                        'category_id' => $categoryId,
                        'spend_from' => 0,
                    ],
                    [
                        'spend_to' => null,
                        'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                        'counts_toward_tier_cap' => true,
                        'cashback_percent' => $plan['percent'],
                        'max_cashback_per_transaction' => null,
                        'max_cashback_per_category_per_period' => null,
                        'min_transaction_amount' => null,
                        'is_enabled' => true,
                        'sort_order' => $sortOrder++,
                    ]
                );
            }

            // Thứ tự hiển thị chuẩn: danh mục cụ thể → combo → fallback cuối.
            // Fallback được tạo TRƯỚC (sort_order gốc = 1) nên phải chuẩn hoá ở cuối.
            $rulesService->normaliseRuleOrder($tier);
        }
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
