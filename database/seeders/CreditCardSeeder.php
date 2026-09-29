<?php

namespace Database\Seeders;

use App\Models\CreditCard\Bank;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * CreditCardSeeder — dữ liệu khởi tạo cho module Thẻ tín dụng.
 *
 * Chạy trên connection `creditcard` (database `hoantien_creditcard`):
 *   php artisan credit-card:migrate --seed
 *
 * ---------------------------------------------------------------------------
 * IDEMPOTENT
 * ---------------------------------------------------------------------------
 * Dùng `updateOrCreate` theo khoá tự nhiên (`slug`) nên chạy lại nhiều lần vẫn
 * cho cùng kết quả, không nhân bản dữ liệu.
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
     * @var list<array{slug:string, name:string, short_name:?string}>
     */
    private const BANKS = [
        ['slug' => 'mb', 'name' => 'Ngân hàng TMCP Quân đội (MB)', 'short_name' => 'MB'],
        ['slug' => 'techcombank', 'name' => 'Ngân hàng TMCP Kỹ thương Việt Nam (Techcombank)', 'short_name' => 'TCB'],
        ['slug' => 'vietinbank', 'name' => 'Ngân hàng TMCP Công nghiệp và Thương mại VN (VietinBank)', 'short_name' => 'CTG'],
        ['slug' => 'vpbank', 'name' => 'Ngân hàng TMCP VPBank', 'short_name' => 'VPBank'],
        ['slug' => 'acb', 'name' => 'Ngân hàng TMCP Á Châu (ACB)', 'short_name' => 'ACB'],
    ];

    /**
     * @var array<string, list<array{slug:string, name:string, annual_fee:string}>>
     */
    private const PRODUCTS = [
        'mb' => [
            ['slug' => 'mb-jcb-ultimate', 'name' => 'MB JCB Ultimate', 'annual_fee' => '990000.00'],
        ],
        'techcombank' => [
            ['slug' => 'tcb-private', 'name' => 'Techcombank Private', 'annual_fee' => '0.00'],
        ],
        'vietinbank' => [
            ['slug' => 'vinfin platinum', 'name' => 'VietinBank VinFin Platinum', 'annual_fee' => '0.00'],
        ],
        'vpbank' => [
            ['slug' => 'vpbank-signature', 'name' => 'VPBank Signature', 'annual_fee' => '990000.00'],
        ],
        'acb' => [
            ['slug' => 'acb-platinum', 'name' => 'ACB Platinum', 'annual_fee' => '1100000.00'],
        ],
    ];

    /**
     * Danh mục CHI TIÊU (không phải danh mục loại thẻ). Không có cashback ở đây.
     *
     * @var list<array{slug:string, name:string, description:string, sort_order:int, is_default:bool}>
     */
    private const CATEGORIES = [
        ['slug' => 'online-shopping', 'name' => 'Mua sắm online', 'description' => 'Shopee, Lazada, Tiki, website thương mại điện tử.', 'sort_order' => 10, 'is_default' => true],
        ['slug' => 'dining', 'name' => 'Ăn uống', 'description' => 'Nhà hàng, quán ăn, cà phê, giao đồ ăn.', 'sort_order' => 20, 'is_default' => true],
        ['slug' => 'transport', 'name' => 'Đi lại & Xăng', 'description' => 'Xăng, dầu, taxi, Grab, phí gửi xe.', 'sort_order' => 30, 'is_default' => false],
        ['slug' => 'travel', 'name' => 'Du lịch & Nghỉ dưỡng', 'description' => 'Vé máy bay, khách sạn, tour.', 'sort_order' => 40, 'is_default' => false],
        ['slug' => 'groceries', 'name' => 'Thực phẩm & Siêu thị', 'description' => 'Siêu thị, cửa hàng thực phẩm.', 'sort_order' => 50, 'is_default' => false],
        ['slug' => 'entertainment', 'name' => 'Giải trí', 'description' => 'Rạp phim, streaming, sự kiện.', 'sort_order' => 60, 'is_default' => false],
        ['slug' => 'education', 'name' => 'Học vụ', 'description' => 'Học phí, khóa học, sách.', 'sort_order' => 70, 'is_default' => false],
        ['slug' => 'other', 'name' => 'Chi tiêu khác', 'description' => 'Các giao dịch chưa phân loại.', 'sort_order' => 999, 'is_default' => false],
    ];

    public function run(): void
    {
        DB::connection(self::CONNECTION)->transaction(function (): void {
            $banks = $this->seedBanks();
            $this->seedProducts($banks);
            $categories = $this->seedCategories();
            $this->seedBuiltinTemplate($categories);
        });
    }

    /**
     * @return array<string, Bank>
     */
    private function seedBanks(): array
    {
        $banks = [];

        foreach (self::BANKS as $row) {
            $banks[$row['slug']] = Bank::updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'short_name' => $row['short_name'],
                    'is_active' => true,
                ]
            );
        }

        return $banks;
    }

    /**
     * @param  array<string, Bank>  $banks
     */
    private function seedProducts(array $banks): void
    {
        foreach (self::PRODUCTS as $bankSlug => $rows) {
            $bank = $banks[$bankSlug];

            foreach ($rows as $row) {
                Product::updateOrCreate(
                    ['slug' => $row['slug']],
                    [
                        'bank_id' => $bank->id,
                        'name' => $row['name'],
                        'annual_fee' => $row['annual_fee'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    /**
     * @return array<string, Category>
     */
    private function seedCategories(): array
    {
        $categories = [];

        foreach (self::CATEGORIES as $row) {
            $categories[$row['slug']] = Category::updateOrCreate(
                ['scope' => Category::SCOPE_SYSTEM, 'owner_user_id' => Category::SYSTEM_OWNER_ID, 'slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'is_active' => true,
                    'is_default' => $row['is_default'],
                    'sort_order' => $row['sort_order'],
                ]
            );
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

        $this->seedRules($basic, $categories, [
            ['slug' => 'online-shopping', 'from' => 0, 'to' => null, 'percent' => '2.000', 'cap_tx' => '100000.00', 'cap_cat' => '1000000.00'],
            ['slug' => 'dining', 'from' => 0, 'to' => null, 'percent' => '1.500', 'cap_tx' => '50000.00', 'cap_cat' => '500000.00'],
            ['slug' => 'transport', 'from' => 0, 'to' => null, 'percent' => '1.000', 'cap_tx' => null, 'cap_cat' => null],
            ['slug' => 'travel', 'from' => 0, 'to' => null, 'percent' => '3.000', 'cap_tx' => '500000.00', 'cap_cat' => '2000000.00'],
            ['slug' => 'groceries', 'from' => 0, 'to' => null, 'percent' => '1.000', 'cap_tx' => null, 'cap_cat' => null],
            ['slug' => 'entertainment', 'from' => 0, 'to' => null, 'percent' => '1.000', 'cap_tx' => null, 'cap_cat' => null],
        ]);

        // Bậc nâng cao: minh hoạ MỘT danh mục có nhiều mức theo khoảng chi tiêu.
        $this->seedRules($advanced, $categories, [
            ['slug' => 'online-shopping', 'from' => 0, 'to' => '5000000.00', 'percent' => '4.000', 'cap_tx' => '200000.00', 'cap_cat' => '2000000.00'],
            ['slug' => 'online-shopping', 'from' => '5000000.00', 'to' => null, 'percent' => '6.000', 'cap_tx' => '300000.00', 'cap_cat' => '3000000.00'],
            ['slug' => 'dining', 'from' => 0, 'to' => null, 'percent' => '2.000', 'cap_tx' => '50000.00', 'cap_cat' => '800000.00'],
            ['slug' => 'transport', 'from' => 0, 'to' => null, 'percent' => '2.000', 'cap_tx' => null, 'cap_cat' => null],
            ['slug' => 'travel', 'from' => 0, 'to' => null, 'percent' => '5.000', 'cap_tx' => '800000.00', 'cap_cat' => '3000000.00'],
            ['slug' => 'groceries', 'from' => 0, 'to' => null, 'percent' => '1.500', 'cap_tx' => null, 'cap_cat' => null],
            ['slug' => 'entertainment', 'from' => 0, 'to' => null, 'percent' => '2.000', 'cap_tx' => null, 'cap_cat' => null],
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
