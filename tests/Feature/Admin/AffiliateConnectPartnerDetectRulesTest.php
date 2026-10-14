<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tab "Connect Partner AFF" — mục "Chuỗi nhận diện URL".
 * Kiểm tra nội dung detect rules hiển thị khớp với code thực tế và không rò rỉ
 * dữ liệu nhạy cảm, không phát sinh HTTP request.
 */
class AffiliateConnectPartnerDetectRulesTest extends TestCase
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

    private function page()
    {
        return $this->actingAs($this->admin)->get(route('admin.affiliate-config.index'));
    }

    public function test_partner_tab_shows_detect_rules_section_header(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('Chuỗi nhận diện URL');
    }

    public function test_detect_rules_show_both_match_types(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('Substring (toàn URL)')
            ->assertSee('Hostname (exact/subdomain)');
    }

    public function test_shopee_rules_include_substring_and_hostname_sources(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('shp.ee')
            ->assertSee('s.shopee.vn, vn.shp.ee, s.shp.ee, shope.ee')
            ->assertSee('UrlResolverService::isShortLink()');
    }

    public function test_lazada_hostname_rules_are_listed(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('lazada.sg')
            ->assertSee('LazadaUrlParser::DOMAINS');
    }

    public function test_shopeefood_rules_list_all_real_hosts(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('shopeefood.vn')
            ->assertSee('spf.shopee.vn')
            ->assertSee('shopeefood.shopee.vn')
            ->assertSee('shopeefood.shopee.vnnow-food');
    }

    public function test_shopeefood_ordering_warning_is_shown(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('BẮT BUỘC kiểm tra ShopeeFood TRƯỚC Shopee');
    }

    public function test_accesstrade_has_no_fake_detect_rules(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('Chưa cấu hình domain merchant/campaign');
    }

    public function test_diagnostic_provider_factory_rule_is_labelled_not_live(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('ProviderFactory::detectPlatform()')
            ->assertSee('Chẩn đoán — không phải luồng thật');
    }

    public function test_rendering_detect_rules_makes_no_http_call(): void
    {
        Http::fake();

        $this->page()->assertOk();

        Http::assertNothingSent();
    }
}
