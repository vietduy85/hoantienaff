<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\PolicyTierCategoryTransactionCap;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * §28.1 — Connection tests.
 */
class ConnectionTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();
    }

    #[Test]
    public function creditcard_connection_is_configured_and_separate_from_the_default(): void
    {
        $this->assertArrayHasKey('creditcard', config('database.connections'));
        $this->assertNotSame(config('database.default'), 'creditcard');

        // Không có kết nối nào "mượn" driver của DB khác.
        $this->assertSame(
            config('database.connections.'.config('database.default').'.driver'),
            config('database.connections.creditcard.driver')
        );
    }

    #[Test]
    public function creditcard_connection_reads_its_own_database_name(): void
    {
        // Production/local: DB_CREDITCARD_DATABASE phải tách khỏi DB_DATABASE.
        $name = config('database.connections.creditcard.database');

        $this->assertNotSame('', (string) $name);
        $this->assertNotSame('hoantienaff', $name, 'Credit Card DB không được trùng app DB.');
    }

    #[Test]
    public function creditcard_database_contains_all_credit_card_tables(): void
    {
        foreach ([
            'credit_card_banks',
            'credit_card_products',
            'credit_card_categories',
            'credit_card_policy_templates',
            'credit_card_policies',
            'credit_card_policy_tiers',
            'credit_card_policy_tier_categories',
            'credit_card_policy_tier_category_transaction_caps',
            'credit_card_user_cards',
            'credit_card_statement_periods',
            'credit_card_transactions',
        ] as $table) {
            $this->assertTrue(
                Schema::connection('creditcard')->hasTable($table),
                "Thiếu bảng trên connection creditcard: {$table}"
            );
        }
    }

    #[Test]
    public function transaction_caps_tables_columns_and_fk_are_declared(): void
    {
        $table = 'credit_card_policy_tier_category_transaction_caps';

        $this->assertTrue(Schema::connection('creditcard')->hasTable($table));

        foreach ([
            'id',
            'policy_tier_id',
            'min_transaction_amount',
            'max_transaction_amount',
            'max_cashback_per_transaction',
            'sort_order',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::connection('creditcard')->hasColumn($table, $column),
                "Thiếu cột {$column} trên bảng {$table}"
            );
        }

        $fk = collect(Schema::connection('creditcard')->getForeignKeys($table))
            ->first(fn (array $key): bool => in_array('policy_tier_id', $key['columns'], true));

        $this->assertNotNull($fk, 'Thiếu foreign key policy_tier_id.');
        $this->assertSame(['policy_tier_id'], $fk['columns']);
        $this->assertSame('credit_card_policy_tiers', $fk['foreign_table']);
        $this->assertSame(['id'], $fk['foreign_columns']);
        $this->assertSame('cascade', $fk['on_delete'], 'Xoá bậc phải xoá luôn các giới hạn.');
    }

    #[Test]
    public function deleting_a_tier_cascades_to_its_transaction_caps(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $category = $this->makeSystemCategory();

        $policy = $this->makePolicyForCard($card, [
            ['name' => 'Bậc 1', 'min' => 0, 'max' => null],
        ], [
            ['category_id' => $category->id, 'percent' => '10.000'],
        ]);

        $tier = $policy->tiers()->firstOrFail();

        PolicyTierCategoryTransactionCap::create([
            'policy_tier_id' => $tier->id,
            'min_transaction_amount' => 0,
            'max_transaction_amount' => 500000,
            'max_cashback_per_transaction' => 20000,
            'sort_order' => 1,
        ]);
        PolicyTierCategoryTransactionCap::create([
            'policy_tier_id' => $tier->id,
            'min_transaction_amount' => 500000.01,
            'max_transaction_amount' => null,
            'max_cashback_per_transaction' => 90000,
            'sort_order' => 2,
        ]);

        $this->assertSame(2, PolicyTierCategoryTransactionCap::where('policy_tier_id', $tier->id)->count());

        $tier->delete();

        $this->assertSame(0, PolicyTierCategoryTransactionCap::where('policy_tier_id', $tier->id)->count());
    }

    #[Test]
    public function creditcard_models_write_to_the_creditcard_connection_not_the_main_one(): void
    {
        $bank = $this->makeBank(['name' => 'MB', 'slug' => 'mb']);

        $this->assertSame('creditcard', $bank->getConnectionName());

        // Bản ghi nằm trên DB creditcard, KHÔNG nằm trên DB chính.
        $this->assertSame(1, DB::connection('creditcard')->table('credit_card_banks')->count());
        $this->assertSame(0, DB::connection(config('database.default'))->table('credit_card_banks')->count());
    }

    #[Test]
    public function main_migrate_does_not_see_creditcard_migrations(): void
    {
        $this->assertArrayHasKey('credit-card:migrate', Artisan::all());
        $this->assertArrayHasKey('credit-card:migrate:status', Artisan::all());

        // `migrate:status` chỉ quét database/migrations/*.php (glob KHÔNG đệ quy)
        // ⇒ 10 migration Thẻ tín dụng trong thư mục con KHÔNG được liệt kê.
        $exit = Artisan::call('migrate:status', ['--path' => 'database/migrations', '--no-ansi' => true]);
        $this->assertSame(0, $exit);

        $output = Artisan::output();

        // Migration legacy vẫn nằm ở chuỗi chính.
        $this->assertStringContainsString('2026_09_29_000003_create_credit_cards_table', $output);

        // Migration Thẻ tín dụng mới KHÔNG nằm ở chuỗi chính.
        foreach ([
            '2026_10_01_000001_create_credit_card_banks_table',
            '2026_10_01_000005_create_credit_card_user_cards_table',
            '2026_10_01_000010_create_credit_card_transactions_table',
        ] as $migration) {
            $this->assertStringNotContainsString($migration, $output);
        }
    }

    #[Test]
    public function creditcard_migrate_is_idempotent(): void
    {
        $exit = Artisan::call('credit-card:migrate', ['--force' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Nothing to migrate', Artisan::output());
    }

    #[Test]
    public function main_database_has_no_creditcard_v2_tables(): void
    {
        foreach ([
            'credit_card_products',
            'credit_card_policy_templates',
            'credit_card_policies',
            'credit_card_policy_tiers',
            'credit_card_policy_tier_categories',
            'credit_card_user_cards',
            'credit_card_statement_periods',
            'credit_card_transactions',
        ] as $table) {
            $this->assertFalse(
                Schema::connection(config('database.default'))->hasTable($table),
                "Schema Thẻ tín dụng bị lọt vào DB chính: {$table}"
            );
        }
    }

    #[Test]
    public function creditcard_connection_never_declares_a_cross_database_foreign_key(): void
    {
        $bank = $this->makeBank();
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id, ['product_id' => $this->makeProduct($bank)->id]);

        // `user_id` là logical reference: KHÔNG có khóa ngoại nào trỏ tới bảng users
        // (users nằm ở database khác nên về mặt vật lý không thể có).
        $this->assertFalse(
            Schema::connection('creditcard')->hasColumn('credit_card_user_cards', 'users_id')
        );

        // Xoá user ở DB chính KHÔNG được xoá thẻ ở DB creditcard.
        // Đây là hệ quả đã biết của việc không có cross-DB FK (đã ghi trong docs).
        $user->delete();
        $this->assertNotNull(UserCard::find($card->id), 'Thẻ phải tồn tại độc lập với user ở DB chính.');
    }
}
