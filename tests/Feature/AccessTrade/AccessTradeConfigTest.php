<?php

namespace Tests\Feature\AccessTrade;

use App\Models\Setting;
use App\Models\User;
use App\Services\AccessTrade\AccessTradeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase C — AccessTrade connection config.
 *  - token stored encrypted, masked for display, never rendered raw;
 *  - connection status is only "connected" after a REAL API response;
 *  - failures are recorded without leaking the token.
 */
class AccessTradeConfigTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::create(['name' => 'Admin']);

        $this->admin = User::factory()->create(['username' => 'admin']);
        $this->admin->assignRole('Admin');
    }

    public function test_token_is_stored_encrypted_and_maskable(): void
    {
        $config = app(AccessTradeConfig::class);
        $config->setToken('plaintext-secret-1234');

        $raw = Setting::where('key', AccessTradeConfig::KEY_TOKEN)->value('value');

        $this->assertNotSame('plaintext-secret-1234', $raw);
        $this->assertSame('plaintext-secret-1234', Crypt::decryptString($raw));

        $this->assertTrue($config->hasToken());
        $this->assertSame('••••••••1234', $config->maskedToken());
    }

    public function test_clearing_token_removes_it(): void
    {
        $config = app(AccessTradeConfig::class);
        $config->setToken('to-be-removed');
        $this->assertTrue($config->hasToken());

        $config->setToken(null);
        $this->assertFalse($config->hasToken());
        $this->assertNull($config->maskedToken());
    }

    public function test_config_page_never_renders_raw_token(): void
    {
        app(AccessTradeConfig::class)->setToken('plaintext-secret-9876');
        app(AccessTradeConfig::class)->setEnabled(true);

        $this->actingAs($this->admin)
            ->get(route('admin.affiliate-config.index'))
            ->assertOk()
            ->assertDontSee('plaintext-secret-9876')
            ->assertSee('••••••••9876', false);
    }

    public function test_unconfigured_state_is_shown_as_not_connected(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.affiliate-config.index'))
            ->assertOk()
            ->assertSee('Chưa kết nối')
            ->assertSee('Chưa cấu hình');
    }

    public function test_connection_check_success_records_status_and_snapshot(): void
    {
        app(AccessTradeConfig::class)->setToken('super-secret-token');
        app(AccessTradeConfig::class)->setEnabled(true);

        Http::fake([
            'api.accesstrade.vn/v1/campaigns*' => Http::response([
                'total' => 1,
                'data' => [[
                    'id' => '77',
                    'name' => 'Merchant A',
                    'approval' => 'successful',
                    'status' => 1,
                    'url' => 'https://merchant-a.com',
                ]],
            ], 200),
            'api.accesstrade.vn/v1/cashback/campaigns*' => Http::response([
                'data' => ['campaigns' => []],
            ], 200),
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.affiliate-config.accesstrade.test'));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $config = app(AccessTradeConfig::class);
        $this->assertTrue($config->lastCheckOk());
        $this->assertNotNull($config->lastCheckAt());
        $this->assertStringNotContainsString('super-secret-token', (string) $config->lastCheckMessage());

        $snapshot = json_decode((string) Setting::get('accesstrade.campaigns_snapshot', '[]'), true);
        $this->assertCount(1, $snapshot);
        $this->assertSame('77', $snapshot[0]['campaign_id']);
        $this->assertSame('merchant-a.com', $snapshot[0]['domain']);
    }

    public function test_connection_check_failure_is_recorded_without_token_leak(): void
    {
        app(AccessTradeConfig::class)->setToken('super-secret-token');
        app(AccessTradeConfig::class)->setEnabled(true);

        Http::fake([
            'api.accesstrade.vn/*' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.affiliate-config.accesstrade.test'));

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $config = app(AccessTradeConfig::class);
        $this->assertFalse($config->lastCheckOk());
        $this->assertStringNotContainsString('super-secret-token', (string) $config->lastCheckMessage());
    }

    public function test_connection_check_requires_enabled_and_token(): void
    {
        Http::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.affiliate-config.accesstrade.test'))
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_member_cannot_change_accesstrade_config(): void
    {
        Role::create(['name' => 'Member']);
        $member = User::factory()->create(['username' => 'member']);
        $member->assignRole('Member');

        $this->actingAs($member)
            ->post(route('admin.affiliate-config.accesstrade.update'), [
                'enabled' => 'true',
                'base_url' => 'https://api.accesstrade.vn',
            ])
            ->assertForbidden();
    }
}
