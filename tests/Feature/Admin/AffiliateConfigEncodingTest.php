<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression test for the Vietnamese text on the admin affiliate-config page.
 *
 * The controller previously stored double-encoded UTF-8 (mojibake), which made
 * labels such as "Đã cấu hình" render as garbled characters. The file must stay
 * valid UTF-8 and every status label must be exact.
 */
class AffiliateConfigEncodingTest extends TestCase
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

        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');
    }

    public function test_controller_file_is_valid_utf8_without_mojibake(): void
    {
        $path = app_path('Http/Controllers/Admin/AffiliateConfigController.php');
        $contents = (string) file_get_contents($path);

        $this->assertTrue(mb_check_encoding($contents, 'UTF-8'), 'Controller must be valid UTF-8.');

        foreach (['Đã cấu hình', 'Thiếu cấu hình', 'Đã kết nối', 'Chưa kết nối', 'Đã lưu cấu hình.'] as $label) {
            $this->assertStringContainsString($label, $contents, "Missing correct label: {$label}");
        }

        // Classic double-encoded Vietnamese markers (e.g. "Ä‘á»") must be gone.
        foreach (['Ä‘Ã£', 'Ã¡Â»', 'Ä‘Ã¡'] as $mojibake) {
            $this->assertStringNotContainsString($mojibake, $contents, "Mojibake found: {$mojibake}");
        }
    }

    public function test_config_page_renders_valid_utf8_status_labels(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.affiliate-config.index'));

        $response->assertOk();
        $this->assertTrue(mb_check_encoding($response->getContent(), 'UTF-8'));

        $response->assertSee('Đã cấu hình');   // Shopee has an affiliate id
        $response->assertSee('Chưa kết nối'); // AccessTrade not configured
    }

    public function test_update_flash_message_is_correct_utf8(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.affiliate-config.update'), [
                'extension_enabled' => 'false',
                'dashboard_strategy' => 'direct',
                'admin_strategy' => 'direct',
                'affiliate_id' => '12345',
                'resolve' => 'false',
            ])
            ->assertRedirect(route('admin.affiliate-config.index'))
            ->assertSessionHas('success', 'Đã lưu cấu hình.');
    }
}
