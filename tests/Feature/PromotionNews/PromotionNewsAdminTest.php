<?php

namespace Tests\Feature\PromotionNews;

use App\Models\PromotionNews;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PromotionNewsAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::create(['name' => 'Admin']);
        Role::create(['name' => 'Operator']);
        Role::create(['name' => 'Member']);

        $this->admin = User::factory()->create(['username' => 'admin']);
        $this->admin->assignRole('Admin');

        $this->member = User::factory()->create(['username' => 'member']);
        $this->member->assignRole('Member');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'source' => 'vib',
            'category' => 'credit-card',
            'title' => 'Hoàn tiền thẻ VIB',
            'image_url' => 'https://cdn.example.test/vib.jpg',
            'landing_url' => 'https://vib.example.test/uu-dai',
            'is_active' => '1',
            'sort_order' => 3,
        ], $overrides);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.promotion-news.index'))->assertRedirect(route('login'));
    }

    public function test_member_is_forbidden(): void
    {
        $this->actingAs($this->member)
            ->get(route('admin.promotion-news.index'))
            ->assertForbidden();
    }

    public function test_admin_can_view_index_and_create_form(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.promotion-news.index'))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('admin.promotion-news.create'))
            ->assertOk();
    }

    public function test_admin_can_create_a_manual_entry_for_a_new_source(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.promotion-news.store'), $this->payload())
            ->assertRedirect(route('admin.promotion-news.index'));

        $this->assertDatabaseHas('promotion_news', [
            'source' => 'vib',
            'category' => 'credit-card',
            'source_type' => 'manual',
            'source_id' => null,
            'title' => 'Hoàn tiền thẻ VIB',
            'is_active' => true,
        ]);
    }

    public function test_store_validation_rejects_missing_and_unsafe_urls(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.promotion-news.store'), $this->payload(['image_url' => 'javascript:alert(1)']))
            ->assertSessionHasErrors('image_url');

        $this->actingAs($this->admin)
            ->post(route('admin.promotion-news.store'), $this->payload(['image_url' => '']))
            ->assertSessionHasErrors('image_url');

        $this->actingAs($this->admin)
            ->post(route('admin.promotion-news.store'), $this->payload([
                'landing_url' => "https://example.test/x\r\nSet-Cookie: y=1",
            ]))
            ->assertSessionHasErrors('landing_url');

        $this->assertDatabaseCount('promotion_news', 0);
    }

    public function test_admin_can_update_toggle_and_delete_manual_entry(): void
    {
        $manual = PromotionNews::factory()->create(['source' => 'vib', 'title' => 'Cũ']);

        $this->actingAs($this->admin)
            ->put(route('admin.promotion-news.update', $manual), $this->payload(['title' => 'Mới']))
            ->assertRedirect(route('admin.promotion-news.index'));

        $this->assertSame('Mới', $manual->fresh()->title);

        $this->actingAs($this->admin)
            ->post(route('admin.promotion-news.toggle', $manual))
            ->assertRedirect();

        $this->assertFalse($manual->fresh()->is_active);

        $this->actingAs($this->admin)
            ->delete(route('admin.promotion-news.destroy', $manual))
            ->assertRedirect(route('admin.promotion-news.index'));

        $this->assertDatabaseMissing('promotion_news', ['id' => $manual->id]);
    }

    public function test_auto_entries_cannot_be_edited_or_deleted(): void
    {
        $auto = PromotionNews::factory()->auto()->create(['source' => 'coop']);

        $this->actingAs($this->admin)
            ->get(route('admin.promotion-news.edit', $auto))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->put(route('admin.promotion-news.update', $auto), $this->payload())
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post(route('admin.promotion-news.toggle', $auto))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->delete(route('admin.promotion-news.destroy', $auto))
            ->assertForbidden();

        $this->assertDatabaseHas('promotion_news', ['id' => $auto->id]);
    }
}
