<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileBottomNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_has_bottom_nav_and_no_old_home_link(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="bottom-nav"', $html);
        $this->assertStringContainsString('Đơn &amp; Ví', $html);
        $this->assertStringContainsString('href="'.route('wallet.index').'"', $html);
        $this->assertStringContainsString('href="'.route('credit-cards.index').'"', $html);
        $this->assertStringNotContainsString('Trang chủ', $html);
    }

    public function test_dashboard_sections_order_and_hidden_pinned_links(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('Link Ghim', $html);
        $this->assertStringNotContainsString('Link Hoàn Tiền Gần Đây', $html);

        $tip = strpos($html, 'data-testid="credit-card-tip"');
        $wallet = strpos($html, 'data-testid="wallet-summary"');
        $orders = strpos($html, 'data-testid="dashboard-recent-orders"');
        $guide = strpos($html, 'data-testid="dashboard-guide"');

        $this->assertNotFalse($tip);
        $this->assertTrue($tip < $wallet && $wallet < $orders && $orders < $guide);
        $this->assertStringContainsString('href="'.route('credit-cards.compare').'"', $html);
        $this->assertStringContainsString('href="'.route('wallet.index', ['rut' => 1]).'"', $html);
    }

    public function test_dashboard_lists_only_five_latest_orders(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 7) as $i) {
            AffiliateOrderItem::factory()->create([
                'user_id' => $user->id,
                'platform' => 'Shopee',
                'order_id' => 'ORD'.$i,
                'ordered_at' => now()->subDays(10 - $i),
                'cashback_amount' => 1000 * $i,
            ]);
        }

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('ORD7', $html);
        $this->assertStringContainsString('ORD3', $html);
        $this->assertStringNotContainsString('orders/ORD2', $html);
        $this->assertStringNotContainsString('orders/ORD1', $html);
    }

    public function test_wallet_group_pages_share_tabs(): void
    {
        $user = User::factory()->create();

        foreach (['/wallet', '/orders', '/referrals', '/profile'] as $path) {
            $this->actingAs($user)->get($path)->assertOk()->assertSee('data-testid="wallet-tabs"', false);
        }
    }
}
