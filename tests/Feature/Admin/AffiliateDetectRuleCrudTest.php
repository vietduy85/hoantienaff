<?php

namespace Tests\Feature\Admin;

use App\Models\AffiliateDetectRule;
use App\Models\User;
use App\Services\Url\AffiliateDetectRuleRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase B — CRUD for admin-managed detect rules.
 * Validates permissions, whitelisted vocabularies (no invented platforms),
 * duplicate rejection, hostname normalisation and conflict warnings.
 */
class AffiliateDetectRuleCrudTest extends TestCase
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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'TheFaceShop',
            'pattern' => 'thefaceshopvn.myharavan.com',
            'match_type' => 'hostname',
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '123',
            'priority' => 100,
            'status' => 'active',
            'description' => 'Merchant AccessTrade',
        ], $overrides);
    }

    public function test_admin_can_create_a_rule(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.affiliate-config.rules.store'), $this->validPayload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('affiliate_detect_rules', [
            'pattern' => 'thefaceshopvn.myharavan.com',
            'platform' => 'AccessTrade',
            'source' => 'database',
        ]);
    }

    public function test_hostname_pattern_is_normalised_on_save(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.affiliate-config.rules.store'), $this->validPayload([
                'pattern' => 'HTTPS://WWW.Example.com:443/path?x=1',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('affiliate_detect_rules', ['pattern' => 'example.com']);
    }

    public function test_unknown_platform_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.affiliate-config.rules.store'), $this->validPayload([
                'platform' => 'BrandNewPlatform',
            ]))
            ->assertSessionHasErrors('platform');

        $this->assertDatabaseCount('affiliate_detect_rules', 0);
    }

    public function test_invalid_strategy_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.affiliate-config.rules.store'), $this->validPayload([
                'strategy' => 'shopee_direct',
            ]))
            ->assertSessionHasErrors('strategy');

        $this->assertDatabaseCount('affiliate_detect_rules', 0);
    }

    public function test_duplicate_pattern_and_match_type_is_rejected(): void
    {
        AffiliateDetectRule::create($this->validPayload([
            'source' => 'database',
        ]));

        $this->actingAs($this->admin)
            ->post(route('admin.affiliate-config.rules.store'), $this->validPayload(['name' => 'dup']))
            ->assertSessionHasErrors('pattern');

        $this->assertDatabaseCount('affiliate_detect_rules', 1);
    }

    public function test_overlapping_code_rule_produces_warning(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.affiliate-config.rules.store'), $this->validPayload([
                'name' => 'Overlap shopee',
                'pattern' => 'shopee',
                'match_type' => 'substring',
            ]))
            ->assertRedirect()
            ->assertSessionHas('rule_warnings');
    }

    public function test_admin_can_update_and_delete_a_rule(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.affiliate-config.rules.store'), $this->validPayload());

        $rule = AffiliateDetectRule::firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('admin.affiliate-config.rules.update', $rule), $this->validPayload([
                'name' => 'Renamed',
                'status' => 'disabled',
            ]))
            ->assertRedirect();

        $this->assertSame('Renamed', $rule->fresh()->name);
        $this->assertSame('disabled', $rule->fresh()->status);

        $this->actingAs($this->admin)
            ->delete(route('admin.affiliate-config.rules.destroy', $rule))
            ->assertRedirect();

        $this->assertDatabaseCount('affiliate_detect_rules', 0);
    }

    public function test_created_rule_is_loaded_by_repository_and_removed_on_delete(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.affiliate-config.rules.store'), $this->validPayload());

        $repository = app(AffiliateDetectRuleRepository::class);
        $this->assertCount(1, $repository->activeRules());

        $this->actingAs($this->admin)
            ->delete(route('admin.affiliate-config.rules.destroy', AffiliateDetectRule::firstOrFail()));

        $this->assertCount(0, $repository->activeRules());
    }

    public function test_disabled_rules_are_not_loaded(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.affiliate-config.rules.store'), $this->validPayload([
                'status' => 'disabled',
            ]));

        $this->assertCount(0, app(AffiliateDetectRuleRepository::class)->activeRules());
    }

    public function test_member_cannot_manage_rules(): void
    {
        Role::create(['name' => 'Member']);
        $member = User::factory()->create(['username' => 'member']);
        $member->assignRole('Member');

        $this->actingAs($member)
            ->post(route('admin.affiliate-config.rules.store'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('affiliate_detect_rules', 0);
    }
}
