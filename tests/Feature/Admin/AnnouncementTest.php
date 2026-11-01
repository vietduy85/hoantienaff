<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Role::create(['name' => 'Admin']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'content' => 'Shopee x3 hôm nay, theo dõi Facebook để lấy link {link} nhé',
            'link_text' => 'tại đây',
            'url' => 'https://facebook.com/hoantien',
            'open_new_tab' => '1',
            'icon' => '🔥',
            'color' => 'amber',
            'sort_order' => 0,
            'is_active' => '1',
        ], $overrides);
    }

    public function test_admin_can_create_and_it_shows_on_home_above_card_tip(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.announcements.store'), $this->payload())
            ->assertRedirect(route('admin.announcements.index'));

        $announcement = Announcement::firstOrFail();
        $this->assertTrue($announcement->open_new_tab);

        $html = $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('theo dõi Facebook để lấy link', false)
            ->assertSee(route('announcements.click', $announcement), false)
            ->assertDontSee('{link}', false)
            ->getContent();

        $this->assertLessThan(
            strpos($html, 'data-testid="credit-card-tip"'),
            strpos($html, 'data-testid="announcement"'),
        );
    }

    public function test_home_hides_inactive_expired_and_future_announcements(): void
    {
        Announcement::create($this->payload(['content' => 'TB tắt', 'is_active' => false]));
        Announcement::create($this->payload(['content' => 'TB hết hạn', 'end_at' => now()->subMinute()]));
        Announcement::create($this->payload(['content' => 'TB tương lai', 'start_at' => now()->addHour()]));
        Announcement::create($this->payload(['content' => 'TB đang chạy']));

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertSee('TB đang chạy')
            ->assertDontSee('TB tắt')
            ->assertDontSee('TB hết hạn')
            ->assertDontSee('TB tương lai');
    }

    public function test_home_shows_at_most_three_in_sort_order(): void
    {
        foreach ([5 => 'Năm', 1 => 'Một', 3 => 'Ba', 2 => 'Hai'] as $order => $name) {
            Announcement::create($this->payload(['content' => "TB-$name", 'sort_order' => $order]));
        }

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertSeeInOrder(['TB-Một', 'TB-Hai', 'TB-Ba'])
            ->assertDontSee('TB-Năm');
    }

    public function test_toggle_hides_and_shows(): void
    {
        $announcement = Announcement::create($this->payload());

        $this->actingAs($this->admin)->post(route('admin.announcements.toggle', $announcement));
        $this->assertFalse($announcement->fresh()->is_active);

        $this->actingAs($this->admin)->post(route('admin.announcements.toggle', $announcement));
        $this->assertTrue($announcement->fresh()->is_active);
    }

    public function test_click_counts_and_redirects_without_touching_updated_at(): void
    {
        $announcement = Announcement::create($this->payload());
        $dismissKey = $announcement->dismissKey();
        $this->travel(5)->minutes();

        $this->get(route('announcements.click', $announcement))
            ->assertRedirect('https://facebook.com/hoantien');

        $fresh = $announcement->fresh();
        $this->assertSame(1, $fresh->click_count);
        $this->assertSame($dismissKey, $fresh->dismissKey());
    }

    public function test_announcement_without_url_is_plain_text_and_click_404(): void
    {
        $announcement = Announcement::create($this->payload(['url' => null, 'content' => 'Bảo trì tối nay {link}']));

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertSee('Bảo trì tối nay')
            ->assertDontSee('{link}', false)
            ->assertDontSee(route('announcements.click', $announcement), false);

        $this->get(route('announcements.click', $announcement))->assertNotFound();
    }

    public function test_content_is_escaped(): void
    {
        Announcement::create($this->payload(['content' => '<script>alert(1)</script> {link}']));

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_validation_rejects_bad_url_and_end_before_start(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.announcements.store'), $this->payload([
                'url' => 'javascript:alert(1)',
                'start_at' => '2026-11-05T10:00',
                'end_at' => '2026-11-04T10:00',
            ]))
            ->assertSessionHasErrors(['url', 'end_at']);

        $this->assertSame(0, Announcement::count());
    }

    public function test_regular_user_cannot_manage(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.announcements.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.announcements.store'), $this->payload())->assertForbidden();
    }

    public function test_admin_pages_render(): void
    {
        $announcement = Announcement::create($this->payload());

        $this->actingAs($this->admin)->get(route('admin.announcements.index'))
            ->assertOk()->assertSee('Thông báo trang chủ')->assertSee('data-testid="announcement-row"', false);
        $this->actingAs($this->admin)->get(route('admin.announcements.create'))
            ->assertOk()->assertSee('data-testid="announcement-preview"', false);
        $this->actingAs($this->admin)->get(route('admin.announcements.edit', $announcement))
            ->assertOk();
    }
}
