<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserShopeeFashionSeenOrder;
use App\Services\ShopeeFashionAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopeeFashionAlertTest extends TestCase
{
    use RefreshDatabase;

    /** Ngày phát sinh đơn mặc định cho test: nằm trong tháng 10/2026. */
    private const OCT_DATE = '2026-10-26 10:00:00';

    /** Ngày phát sinh đơn "mới" sau cửa sổ khởi tạo (tháng 11/2026). */
    private const NOV_DATE = '2026-11-05 09:30:00';

    /** Ngày phát sinh đơn TRƯỚC nền sàn (tháng 9/2026) — phải bị loại. */
    private const SEP_DATE = '2026-09-20 08:00:00';

    private function makeItem(User $user, array $overrides = []): \App\Models\AffiliateOrderItem
    {
        return \App\Models\AffiliateOrderItem::factory()->create(array_merge([
            'user_id' => $user->id,
            'platform' => 'Shopee',
            'shopee_commission_rate' => ShopeeFashionAlertService::FASHION_COMMISSION_RATE,
            'ordered_at' => self::OCT_DATE,
            'order_id' => 'ORD'.fake()->unique()->numerify('#######'),
            'item_id' => (string) fake()->unique()->numerify('##########'),
        ], $overrides));
    }

    private function service(): ShopeeFashionAlertService
    {
        return app(ShopeeFashionAlertService::class);
    }

    // ---------------------------------------------------------------------
    // Truy vấn theo cửa sổ thời gian (C2/C3/C4)
    // ---------------------------------------------------------------------

    public function test_eligible_orders_require_order_date_on_or_after_october_2026(): void
    {
        $user = User::factory()->create();

        $this->makeItem($user, ['order_id' => 'SEP', 'ordered_at' => self::SEP_DATE]);
        $this->makeItem($user, ['order_id' => 'OCT', 'ordered_at' => self::OCT_DATE]);
        $this->makeItem($user, ['order_id' => 'NOV', 'ordered_at' => self::NOV_DATE]);

        // Nền sàn: loại đơn tháng 9, giữ đơn tháng 10 và tháng 11 trở đi.
        $this->assertSame(
            ['NOV', 'OCT'],
            $this->service()->eligibleOrderIds($user)->sort()->values()->all()
        );
    }

    public function test_initial_window_contains_only_october_2026_orders(): void
    {
        $user = User::factory()->create();

        $this->makeItem($user, ['order_id' => 'SEP', 'ordered_at' => self::SEP_DATE]);
        $this->makeItem($user, ['order_id' => 'OCT', 'ordered_at' => self::OCT_DATE]);
        $this->makeItem($user, ['order_id' => 'NOV', 'ordered_at' => self::NOV_DATE]);

        $this->assertSame(
            ['OCT'],
            $this->service()->initialWindowOrderIds($user)->all()
        );
    }

    public function test_post_initialization_window_contains_november_orders(): void
    {
        $user = User::factory()->create();

        $this->makeItem($user, ['order_id' => 'OCT', 'ordered_at' => self::OCT_DATE]);
        $this->makeItem($user, ['order_id' => 'NOV', 'ordered_at' => self::NOV_DATE]);

        $this->assertSame(
            ['NOV'],
            $this->service()->postInitializationOrderIds($user)->all()
        );
    }

    public function test_alert_only_counts_orders_from_october_2026_onwards(): void
    {
        $user = User::factory()->create();

        $this->makeItem($user, ['order_id' => 'SEP1', 'ordered_at' => self::SEP_DATE]);
        $this->makeItem($user, ['order_id' => 'SEP2', 'ordered_at' => '2026-10-24 23:59:59']);
        $this->makeItem($user, ['order_id' => 'OCT', 'ordered_at' => self::OCT_DATE]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Có 1 đơn Shopee thời trang', false);
    }

    public function test_boundary_october_25_counts_but_september_is_excluded(): void
    {
        $user = User::factory()->create();

        // Ngay trước mốc 25/10/2026 00:00:00 (giờ VN) => loại.
        $this->makeItem($user, ['order_id' => 'BEFORE', 'ordered_at' => '2026-10-24 23:59:59']);
        // Đúng mốc 25/10/2026 00:00:00 => tính.
        $this->makeItem($user, ['order_id' => 'AT', 'ordered_at' => '2026-10-25 00:00:00']);

        $this->assertSame(['AT'], $this->eligibleOrderIds($user));

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Có 1 đơn Shopee thời trang', false);
    }

    // ---------------------------------------------------------------------
    // Nội dung / liên kết / nhãn nút trên giao diện (A)
    // ---------------------------------------------------------------------

    public function test_home_shows_alert_counting_distinct_fashion_orders(): void
    {
        $user = User::factory()->create();

        // Hai item trong CÙNG một đơn => chỉ tính 1 đơn.
        $this->makeItem($user, ['order_id' => 'ORDAAA1', 'item_id' => '111']);
        $this->makeItem($user, ['order_id' => 'ORDAAA1', 'item_id' => '222']);
        $this->makeItem($user, ['order_id' => 'ORDBBB2', 'item_id' => '333']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('2 đơn Shopee thời trang', false);
    }

    public function test_alert_body_contains_clickable_shopee_link(): void
    {
        $user = User::factory()->create();
        $this->makeItem($user);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        // href mặc định (URL đích do Admin cấu hình) và URL hiển thị (giữ nguyên).
        $response->assertSee('href="https://shp.ee/622xrhjpx85"', false);
        $response->assertSee('>https://s.shopee.vn/1BFBNN81J</a>', false);
        $response->assertSee('target="_blank"', false);
        $response->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_alert_buttons_use_required_labels(): void
    {
        $user = User::factory()->create();
        $this->makeItem($user);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Tôi đã biết', false);
        $response->assertSee('Tắt chức năng thông báo', false);
    }

    // ---------------------------------------------------------------------
    // Badge đỏ cạnh "Trang chủ" (B)
    // ---------------------------------------------------------------------

    public function test_navbar_badge_shows_unseen_count(): void
    {
        $user = User::factory()->create();
        $this->makeItem($user, ['order_id' => 'ORD1']);
        $this->makeItem($user, ['order_id' => 'ORD2']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('data-testid="shopee-fashion-badge"', false);
    }

    public function test_navbar_badge_hidden_when_no_eligible_orders(): void
    {
        $user = User::factory()->create();
        $this->makeItem($user, ['order_id' => 'SEP', 'ordered_at' => self::SEP_DATE]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('data-testid="shopee-fashion-badge"', false);
    }

    public function test_navbar_badge_hidden_when_disabled(): void
    {
        $user = User::factory()->create(['shopee_fashion_alert_disabled' => true]);
        $this->makeItem($user);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('data-testid="shopee-fashion-badge"', false);
    }

    public function test_navbar_badge_cleared_after_acknowledge(): void
    {
        $user = User::factory()->create();
        $this->makeItem($user, ['order_id' => 'ORD1']);

        $this->actingAs($user)->get('/dashboard')
            ->assertSee('data-testid="shopee-fashion-badge"', false);

        $this->actingAs($user)->postJson(route('shopee-fashion-alert.acknowledge'))->assertOk();

        // Request MỚI đọc lại DB: badge biến mất vì đã xác nhận hết.
        $this->actingAs($user)->get('/dashboard')
            ->assertDontSee('data-testid="shopee-fashion-badge"', false);
    }

    public function test_navbar_badge_reappears_for_new_order(): void
    {
        $user = User::factory()->create();
        $this->makeItem($user, ['order_id' => 'ORD1']);

        $this->actingAs($user)->postJson(route('shopee-fashion-alert.acknowledge'))->assertOk();

        $this->actingAs($user)->get('/dashboard')
            ->assertDontSee('data-testid="shopee-fashion-badge"', false);

        // Đơn mới phát sinh (tháng 11/2026) => badge bật lại, đếm đúng 1.
        $this->makeItem($user, ['order_id' => 'ORDNEW', 'ordered_at' => self::NOV_DATE]);

        $this->actingAs($user)->get('/dashboard')
            ->assertSee('data-testid="shopee-fashion-badge"', false)
            ->assertSee('Có 1 đơn Shopee thời trang', false);
    }

    public function test_navbar_withdraw_badge_still_rendered_alongside(): void
    {
        \Spatie\Permission\Models\Permission::create(['name' => 'withdrawals.view']);

        $user = User::factory()->create();
        $user->givePermissionTo('withdrawals.view');
        $this->makeItem($user, ['order_id' => 'ORD1']);

        \App\Models\WithdrawRequest::factory()->create([
            'user_id' => $user->id,
            'status' => \App\Models\WithdrawRequest::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        // Badge rút tiền và badge cào xu cùng tồn tại, không ghi đè nhau.
        $response->assertSee('data-testid="pending-withdraw-badge"', false);
        $response->assertSee('data-testid="shopee-fashion-badge"', false);
    }

    // ---------------------------------------------------------------------
    // Lọc theo nền tảng / tỷ lệ / người dùng
    // ---------------------------------------------------------------------

    public function test_non_fashion_and_non_shopee_orders_are_ignored(): void
    {
        $user = User::factory()->create();

        $this->makeItem($user, ['shopee_commission_rate' => 2.5]);
        $this->makeItem($user, ['platform' => 'TikTok']);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('cần cào xu')
            ->assertDontSee('data-testid="shopee-fashion-badge"', false);
    }

    public function test_other_users_orders_are_not_counted(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->makeItem($other, ['order_id' => 'ORDOTHER1']);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('cần cào xu');
    }

    public function test_no_alert_when_user_has_disabled_it(): void
    {
        $user = User::factory()->create(['shopee_fashion_alert_disabled' => true]);
        $this->makeItem($user);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('cần cào xu');
    }

    // ---------------------------------------------------------------------
    // Acknowledge / disable (hành vi ghi)
    // ---------------------------------------------------------------------

    public function test_acknowledge_marks_orders_seen_and_reappears_for_new_orders(): void
    {
        $user = User::factory()->create();
        $this->makeItem($user, ['order_id' => 'ORD1']);
        $this->makeItem($user, ['order_id' => 'ORD2']);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Có 2 đơn Shopee thời trang', false);

        $this->actingAs($user)
            ->postJson(route('shopee-fashion-alert.acknowledge'))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(2, UserShopeeFashionSeenOrder::where('user_id', $user->id)->count());

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('cần cào xu');

        // Chỉ đơn phát sinh mới (tháng 11) => tính lại từ 0.
        $this->makeItem($user, ['order_id' => 'ORDNEW', 'ordered_at' => self::NOV_DATE]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('1 đơn Shopee thời trang', false);
    }

    public function test_acknowledge_ignores_pre_floor_orders(): void
    {
        $user = User::factory()->create();
        $this->makeItem($user, ['order_id' => 'SEP', 'ordered_at' => self::SEP_DATE]);
        $this->makeItem($user, ['order_id' => 'OCT']);

        $this->actingAs($user)->postJson(route('shopee-fashion-alert.acknowledge'))->assertOk();

        // Chỉ đơn đủ điều kiện mới được lưu "đã biết".
        $this->assertSame(1, UserShopeeFashionSeenOrder::where('user_id', $user->id)->count());
        $this->assertDatabaseMissing('user_shopee_fashion_seen_orders', [
            'user_id' => $user->id,
            'order_id' => 'SEP',
        ]);
    }

    public function test_acknowledge_is_idempotent(): void
    {
        $user = User::factory()->create();
        $this->makeItem($user, ['order_id' => 'ORD1']);
        $this->makeItem($user, ['order_id' => 'ORD2']);

        $this->actingAs($user)->postJson(route('shopee-fashion-alert.acknowledge'))->assertOk();
        $this->actingAs($user)->postJson(route('shopee-fashion-alert.acknowledge'))->assertOk();

        $this->assertSame(2, UserShopeeFashionSeenOrder::where('user_id', $user->id)->count());
    }

    public function test_acknowledge_does_not_affect_other_users(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->makeItem($userA, ['order_id' => 'ORDA']);
        $this->makeItem($userB, ['order_id' => 'ORDB']);

        $this->actingAs($userA)->postJson(route('shopee-fashion-alert.acknowledge'))->assertOk();

        $this->assertSame(1, UserShopeeFashionSeenOrder::where('user_id', $userA->id)->count());
        $this->assertSame(0, UserShopeeFashionSeenOrder::where('user_id', $userB->id)->count());

        $this->actingAs($userB)->get('/dashboard')
            ->assertOk()
            ->assertSee('1 đơn Shopee thời trang', false)
            ->assertSee('data-testid="shopee-fashion-badge"', false);
    }

    public function test_disable_turns_off_alert_permanently(): void
    {
        $user = User::factory()->create();
        $this->makeItem($user, ['order_id' => 'ORD1']);

        $this->actingAs($user)
            ->postJson(route('shopee-fashion-alert.disable'))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue((bool) $user->fresh()->shopee_fashion_alert_disabled);

        // Kể cả đơn MỚI cũng không bật lại thông báo.
        $this->makeItem($user, ['order_id' => 'ORD2', 'ordered_at' => self::NOV_DATE]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('cần cào xu')
            ->assertDontSee('data-testid="shopee-fashion-badge"', false);
    }

    public function test_guest_cannot_acknowledge_or_disable(): void
    {
        $this->post('/shopee-fashion-alert/acknowledge')->assertRedirect('/login');
        $this->post('/shopee-fashion-alert/disable')->assertRedirect('/login');
    }

    // ---------------------------------------------------------------------
    // Bảo vệ: dùng ReflectionMethod để tránh phụ thuộc nội bộ service
    // ---------------------------------------------------------------------

    /** @return array<int, string> */
    private function eligibleOrderIds(User $user): array
    {
        return $this->service()->eligibleOrderIds($user)->all();
    }
}
