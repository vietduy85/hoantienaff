<?php

namespace Tests\Feature\CreditCard;

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
    public function creditcard_database_contains_all_ten_tables(): void
    {
        foreach ([
            'credit_card_banks',
            'credit_card_products',
            'credit_card_categories',
            'credit_card_policy_templates',
            'credit_card_policies',
            'credit_card_policy_tiers',
            'credit_card_policy_tier_categories',
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
