<?php

namespace Tests\Feature\Admin;

use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementReferralsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::create(['name' => 'users.view']);

        Role::create(['name' => 'Admin']);

        $this->admin = User::factory()->create(['username' => 'admin']);
        $this->admin->assignRole('Admin');
        $this->admin->givePermissionTo('users.view');
    }

    private function user(string $username, ?int $referredBy = null): User
    {
        return User::factory()->create([
            'username' => $username,
            'referred_by' => $referredBy,
        ]);
    }

    private function addReferrals(User $referrer, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Referral::factory()->create([
                'referrer_id' => $referrer->id,
                'referred_user_id' => $this->user("referee_{$referrer->id}_{$i}")->id,
                'status' => Referral::STATUS_PENDING,
            ]);
        }
    }

    private function index(array $params = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.users.index', $params));
    }

    private function cardHtml(string $html, User $user): string
    {
        $pattern = '/<a href="[^"]*\/users\/' . $user->id . '" class="block bg-white rounded-2xl[^"]*">.*?<\/a>/s';
        preg_match($pattern, $html, $matches);

        if (! isset($matches[0])) {
            $this->fail("Không thấy card của user id {$user->id}");
        }

        return $matches[0];
    }

    /** TEST 1: User A có 3 referrals → card hiển thị "Đã giới thiệu: 3 người" */
    #[Test]
    public function card_shows_referred_count_three(): void
    {
        $a = $this->user('refA');
        $this->addReferrals($a, 3);

        $html = $this->index()->assertOk()->getContent();
        $card = $this->cardHtml($html, $a);

        $this->assertSame(3, Referral::where('referrer_id', $a->id)->count());
        $this->assertStringContainsString('Đã giới thiệu', $card);
        $this->assertStringContainsString('3 người', $card);
    }

    /** TEST 2: User B không có referral → card hiển thị "Đã giới thiệu: 0 người" */
    #[Test]
    public function card_shows_zero_referred_when_no_referrals(): void
    {
        $b = $this->user('refB');

        $html = $this->index()->assertOk()->getContent();
        $card = $this->cardHtml($html, $b);

        $this->assertSame(0, Referral::where('referrer_id', $b->id)->count());
        $this->assertStringContainsString('Đã giới thiệu', $card);
        $this->assertStringContainsString('0 người', $card);
    }

    /** TEST 3: User C được User A giới thiệu → C hiển thị "Người giới thiệu: refA" */
    #[Test]
    public function card_shows_referrer_username(): void
    {
        $a = $this->user('refA');
        $c = $this->user('refC', $a->id);

        $html = $this->index()->assertOk()->getContent();
        $card = $this->cardHtml($html, $c);

        $this->assertSame($a->id, $c->referred_by);
        $this->assertStringContainsString('Người giới thiệu', $card);
        $this->assertStringContainsString('refA', $card);
        $this->assertStringNotContainsString('Người giới thiệu' . $a->id, $card);
    }

    /** TEST 4: User D không có referrer → hiển thị "Không có" */
    #[Test]
    public function card_shows_no_referrer_placeholder(): void
    {
        $d = $this->user('refD');

        $html = $this->index()->assertOk()->getContent();
        $card = $this->cardHtml($html, $d);

        $this->assertNull($d->referred_by);
        $this->assertStringContainsString('Người giới thiệu', $card);
        $this->assertStringContainsString('Không có', $card);
    }

    /** TEST 5: Sort "Nhiều người giới thiệu nhất" → user nhiều referrals ở trước */
    #[Test]
    public function sort_referrals_desc_puts_most_inviting_first(): void
    {
        $a = $this->user('refA');
        $this->addReferrals($a, 3);
        $b = $this->user('refB');
        $this->addReferrals($b, 1);

        $this->index(['sort' => 'referrals_desc'])
            ->assertOk()
            ->assertSeeInOrder(['refA', 'refB']);
    }

    /** TEST 6: Sort "Ít người giới thiệu nhất" → user ít referrals ở trước */
    #[Test]
    public function sort_referrals_asc_puts_least_inviting_first(): void
    {
        $a = $this->user('refA');
        $this->addReferrals($a, 3);
        $b = $this->user('refB');
        $this->addReferrals($b, 1);

        $this->index(['sort' => 'referrals_asc'])
            ->assertOk()
            ->assertSeeInOrder(['refB', 'refA']);
    }

    /** TEST 7: Search + sort kết hợp vẫn đúng */
    #[Test]
    public function search_and_sort_work_together(): void
    {
        $a1 = $this->user('alice1');
        $this->addReferrals($a1, 2);
        $a2 = $this->user('alice2');
        $this->addReferrals($a2, 1);
        $this->user('bob_x7');

        $this->index(['search' => 'alice', 'sort' => 'referrals_desc'])
            ->assertOk()
            ->assertSeeInOrder(['alice1', 'alice2'])
            ->assertDontSee('bob_x7');
    }

    /** TEST 8: Pagination + sort không bị sai thứ tự, giữ query parameter */
    #[Test]
    public function pagination_keeps_sort_order_and_query_string(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $referrer = $this->user("r{$i}");
            Referral::factory()->create([
                'referrer_id' => $referrer->id,
                'referred_user_id' => $this->user("rr{$i}")->id,
                'status' => Referral::STATUS_PENDING,
            ]);
        }

        $this->index(['sort' => 'referrals_desc', 'page' => 2])
            ->assertOk()
            ->assertSeeInOrder(['r5', 'r4', 'r3', 'r2', 'r1'])
            ->assertSee('sort=referrals_desc');
    }
}