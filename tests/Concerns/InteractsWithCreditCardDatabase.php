<?php

namespace Tests\Concerns;

use App\Models\CreditCard\Bank;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyVersion;
use App\Models\CreditCard\Product;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Thiết lập môi trường test cho module Thẻ tín dụng.
 *
 * Vấn đề: `phpunit.xml` ép `DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:`. Nếu
 * connection `creditcard` không được migrate thì mọi model Thẻ tín dụng sẽ
 * báo "no such table" vì sqlite `:memory:` tạo một database RIÊNG cho từng
 * connection.
 *
 * Cách xử lý ở đây:
 *   1. Thêm `creditcard` vào `connectionsToTransact` TRƯỚC `parent::setUp()` để
 *      `RefreshDatabase` cache lại PDO in-memory và rollback transaction cho
 *      cả 2 connection.
 *   2. Migrate schema Thẻ tín dụng vào connection `creditcard` đúng 1 lần mỗi
 *      process (vì PDO in-memory được tái sử dụng qua `RefreshDatabaseState`).
 *   3. Bọc mỗi test trong transaction của CẢ 2 connection nhờ `RefreshDatabase`,
 *      nên dữ liệu test tự rollback — không cần dọn dẹp thủ công.
 */
trait InteractsWithCreditCardDatabase
{
    use RefreshDatabase;

    protected function setUpCreditCardTestCase(): void
    {
        parent::setUp();

        $this->migrateCreditCardDatabaseOnce();
    }

    /**
     * RefreshDatabase chỉ mặc định transaction trên connection chính.
     * Thêm `creditcard` để:
     *   - PDO in-memory của connection này được cache lại giữa các test;
     *   - mỗi test được rollback trên CẢ hai connection.
     *
     * Ghi đè method (không dùng property) vì giá trị được đọc sau khi app đã boot.
     *
     * @return array<int, string>
     */
    protected function connectionsToTransact()
    {
        $default = property_exists($this, 'connectionsToTransact')
            ? $this->connectionsToTransact
            : [config('database.default')];

        return array_values(array_unique(array_merge($default, ['creditcard'])));
    }

    private function migrateCreditCardDatabaseOnce(): void
    {
        $connection = config('database.connections.creditcard.database');

        if ($connection !== ':memory:') {
            return; // Đang chạy trên database thật: schema do `credit-card:migrate` lo.
        }

        if (Schema::connection('creditcard')->hasTable('credit_card_transactions')) {
            return;
        }

        Artisan::call('credit-card:migrate', ['--force' => true]);
    }

    // ---------------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------------

    protected function makeBank(array $attributes = []): Bank
    {
        static $sequence = 0;
        $sequence++;

        return Bank::create(array_merge([
            'name' => 'Ngân hàng Test '.$sequence,
            'slug' => 'ngan-hang-test-'.$sequence,
            'is_active' => true,
        ], $attributes));
    }

    protected function makeProduct(?Bank $bank = null, array $attributes = []): Product
    {
        static $sequence = 0;
        $sequence++;

        return Product::create(array_merge([
            'bank_id' => ($bank ?? $this->makeBank())->id,
            'name' => 'Sản phẩm thẻ Test '.$sequence,
            'slug' => 'san-pham-test-'.$sequence,
            'annual_fee' => 0,
            'is_active' => true,
        ], $attributes));
    }

    protected function makeSystemCategory(array $attributes = []): Category
    {
        static $sequence = 0;
        $sequence++;

        return Category::create(array_merge([
            'scope' => Category::SCOPE_SYSTEM,
            'owner_user_id' => Category::SYSTEM_OWNER_ID,
            'name' => 'Danh mục hệ thống '.$sequence,
            'slug' => 'danh-muc-he-thong-'.$sequence,
            'is_active' => true,
        ], $attributes));
    }

    protected function makeUserCategory(int $userId, array $attributes = []): Category
    {
        static $sequence = 0;
        $sequence++;

        return Category::create(array_merge([
            'scope' => Category::SCOPE_USER,
            'owner_user_id' => $userId,
            'name' => 'Danh mục riêng '.$sequence,
            'slug' => 'danh-muc-rieng-'.$sequence,
            'is_active' => true,
        ], $attributes));
    }

    protected function makeUserCard(int $userId, array $attributes = []): UserCard
    {
        static $sequence = 0;
        $sequence++;

        return UserCard::create(array_merge([
            'user_id' => $userId,
            // Phase 1B: Bank + tên thẻ gợi nhớ, KHÔNG qua Product catalog.
            'bank_id' => $this->makeBank()->id,
            'name' => 'Thẻ của tôi '.$sequence,
            'credit_limit' => 50000000,
            'statement_day' => 15,
            'payment_due_day' => 25,
            'statement_date_basis' => UserCard::BASIS_TRANSACTION_DATE,
            'status' => UserCard::STATUS_ACTIVE,
        ], $attributes));
    }

    /**
     * Tạo một policy version hoàn chỉnh (root + tiers + rules).
     *
     * @param  array<int, array{name?:string, min:float, max:float|null, cap_period?:string|null}>  $tiers
     * @param  array<int, array{category_id:int, percent:string, spend_from?:float, spend_to?:float, cap_tx?:float|null, cap_cat?:float|null, counts_cap?:bool}>  $rules
     */
    protected function makePolicyForCard(UserCard $userCard, array $tiers, array $rules, array $attributes = []): Policy
    {
        $policy = Policy::create(array_merge([
            'user_card_id' => $userCard->id,
            'version_no' => 1,
            'status' => Policy::STATUS_ACTIVE,
            'name' => 'Policy '.$userCard->name,
            'effective_from' => '2000-01-01',
            'effective_to' => null,
            'min_total_spend' => 0,
            'rounding_mode' => 'round',
        ], $attributes));

        // Root trỏ về chính nó ⇒ UNIQUE (root_policy_id, version_no) có hiệu lực.
        $policy->forceFill(['root_policy_id' => $policy->id])->save();

        foreach (array_values($tiers) as $index => $tier) {
            $tierModel = PolicyTier::create([
                'policy_id' => $policy->id,
                'name' => $tier['name'] ?? 'Tier '.($index + 1),
                'sort_order' => $index,
                'min_total_spend' => $tier['min'],
                'max_total_spend' => $tier['max'] ?? null,
                // Lớp cap thứ ba của engine: trần TOÀN KỲ của bậc — đây là "quota".
                'max_cashback_per_period' => $tier['cap_period'] ?? null,
            ]);

            foreach ($rules as $rule) {
                if (isset($rule['only_tier']) && $rule['only_tier'] !== $index) {
                    continue;
                }

                PolicyTierCategory::create([
                    'tier_id' => $tierModel->id,
                    'category_id' => $rule['category_id'],
                    'name' => $rule['name'] ?? null,
                    'sort_order' => $rule['sort_order'] ?? 0,
                    'spend_from' => $rule['spend_from'] ?? 0,
                    'spend_to' => $rule['spend_to'] ?? null,
                    'cashback_percent' => $rule['percent'],
                    'max_cashback_per_transaction' => $rule['cap_tx'] ?? null,
                    'max_cashback_per_category_per_period' => $rule['cap_cat'] ?? null,
                    'min_transaction_amount' => $rule['min_tx'] ?? null,
                    'is_enabled' => $rule['is_enabled'] ?? true,
                    // Lớp cap toàn kỳ CHỈ ăn cashback của rule bật cờ này, nên
                    // "đã dùng" cũng phải lọc đúng cờ.
                    'counts_toward_tier_cap' => $rule['counts_cap'] ?? true,
                ]);
            }
        }

        $userCard->forceFill(['current_policy_id' => $policy->id])->save();

        return $policy->refresh();
    }

    protected function makeSystemTemplate(array $attributes = []): PolicyTemplate
    {
        static $sequence = 0;
        $sequence++;

        return PolicyTemplate::create(array_merge([
            'scope' => PolicyTemplate::SCOPE_SYSTEM,
            'owner_user_id' => PolicyTemplate::SYSTEM_OWNER_ID,
            'name' => 'System template '.$sequence,
            'slug' => 'system-template-'.$sequence,
            'is_builtin' => true,
            'is_active' => true,
        ], $attributes));
    }

    protected function makeStatementPeriod(UserCard $userCard, array $attributes = []): StatementPeriod
    {
        return StatementPeriod::create(array_merge([
            'user_card_id' => $userCard->id,
            'period_start' => '2026-08-16',
            'period_end' => '2026-09-15',
            'statement_date' => '2026-09-15',
            'payment_due_date' => '2026-09-25',
            'status' => StatementPeriod::STATUS_OPEN,
        ], $attributes));
    }

    protected function makePolicyVersion(Policy $root, array $attributes = []): PolicyVersion
    {
        return PolicyVersion::create(array_merge([
            'user_card_id' => $root->user_card_id,
            'template_id' => $root->template_id,
            'root_policy_id' => $root->root_policy_id ?? $root->id,
            'version_no' => ((int) PolicyVersion::where('root_policy_id', $root->root_policy_id ?? $root->id)->max('version_no')) + 1,
            'status' => Policy::STATUS_ACTIVE,
            'name' => $root->name,
            'effective_from' => '2026-10-01',
            'effective_to' => null,
            'min_total_spend' => 0,
            'rounding_mode' => 'round',
        ], $attributes));
    }
}
