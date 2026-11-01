<?php

namespace Tests\Feature\Admin;

use App\Models\PushSubscription;
use App\Models\Setting;
use App\Services\Push\WebPushSender;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WithdrawRequest;
use App\Services\Payout\VietQrPayload;
use App\Services\Payout\WithdrawAdminNotifier;
use App\Services\Zalo\ZaloOaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PayoutMobileTest extends TestCase
{
    use RefreshDatabase;

    private const VCB = 'Ngoại thương Việt Nam (VCB)';

    private User $manager;
    private WithdrawRequest $pending;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::create(['name' => 'withdrawals.view']);
        Permission::create(['name' => 'withdrawals.manage']);

        $this->manager = User::factory()->create();
        $this->manager->givePermissionTo(['withdrawals.view', 'withdrawals.manage']);

        $owner = User::factory()->create(['username' => 'khach1', 'wallet_balance' => 150000]);
        $this->pending = WithdrawRequest::factory()->create([
            'running_no' => 'WR20261031000001',
            'user_id' => $owner->id,
            'username' => 'khach1',
            'amount' => 150000,
            'bank_name' => self::VCB,
            'bank_account' => '0123456789',
            'account_name' => 'NGUYEN VAN A',
            'status' => WithdrawRequest::STATUS_PENDING,
        ]);
        WalletTransaction::factory()->create([
            'user_id' => $owner->id,
            'username' => 'khach1',
            'type' => 'withdraw',
            'direction' => 'debit',
            'amount' => 150000,
            'balance_before' => 150000,
            'balance_after' => 150000,
            'reference_type' => 'withdraw_request',
            'reference_id' => $this->pending->id,
            'status' => 'pending',
        ]);
    }

    public function test_vietqr_payload_has_bank_account_amount_and_valid_crc(): void
    {
        $payload = VietQrPayload::forWithdraw($this->pending);

        $this->assertStringContainsString('A000000727', $payload);
        $this->assertStringContainsString('0006970436', $payload);
        $this->assertStringContainsString('01100123456789', $payload);
        $this->assertStringContainsString('5406150000', $payload);
        $this->assertStringContainsString('HOANTIEN WR20261031000001', $payload);
        // Ví dụ chuẩn EMVCo: CRC16-CCITT-FALSE của "123456789" = 29B1.
        $crc = (new \ReflectionMethod(VietQrPayload::class, 'crc16'))->invoke(null, '123456789');
        $this->assertSame('29B1', $crc);
        $this->assertSame(substr($payload, -4), (new \ReflectionMethod(VietQrPayload::class, 'crc16'))->invoke(null, substr($payload, 0, -4)));
    }

    public function test_every_bank_in_list_has_bin(): void
    {
        foreach (config('banks.list') as $bank) {
            $this->assertNotNull(VietQrPayload::binFor($bank), $bank);
        }
    }

    public function test_page_shows_pending_with_qr_and_requires_permission(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.payouts.index'))->assertForbidden();

        $html = $this->actingAs($this->manager)->get(route('admin.payouts.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="payout-card"', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('0123456789', $html);
        $this->assertStringContainsString('HOANTIEN WR20261031000001', $html);
        $this->assertStringContainsString('data-testid="payout-deeplink"', $html);
    }

    public function test_deeplink_prefills_account_amount_and_note(): void
    {
        $url = VietQrPayload::deeplink($this->pending, 'https://x.test/back');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://dl.vietqr.io/pay?', $url);
        $this->assertSame('0123456789@vcb', $q['ba']);
        $this->assertSame('150000', $q['am']);
        $this->assertSame('HOANTIEN WR20261031000001', $q['tn']);
        $this->assertSame(VietQrPayload::APP_PLACEHOLDER, $q['app']);
    }

    public function test_complete_and_reject_from_mobile_page(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.payouts.complete', $this->pending))
            ->assertRedirect(route('admin.payouts.index'));
        $this->assertSame(WithdrawRequest::STATUS_PAID, $this->pending->fresh()->status);

        // Bấm lại / từ chối yêu cầu đã chuyển: báo lỗi gọn, không 500.
        $this->actingAs($this->manager)
            ->post(route('admin.payouts.complete', $this->pending))
            ->assertRedirect(route('admin.payouts.index'))
            ->assertSessionHasErrors('payout');
        $this->actingAs($this->manager)
            ->post(route('admin.payouts.reject', $this->pending), ['note' => 'x'])
            ->assertSessionHasErrors('payout');
    }

    public function test_notifier_sends_zalo_text_with_link(): void
    {
        config(['services.zalo_oa' => ['app_id' => '1', 'secret_key' => 's', 'admin_user_ids' => 'u1, u2']]);
        Setting::set(ZaloOaClient::REFRESH_TOKEN, 'r0');
        Setting::set(ZaloOaClient::ACCESS_TOKEN, 'a0');
        Setting::set(ZaloOaClient::EXPIRES_AT, (string) (time() + 3600));

        Http::fake(['openapi.zalo.me/*' => Http::response(['error' => 0, 'message' => 'Success'])]);

        app(WithdrawAdminNotifier::class)->notifyNew($this->pending);

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->hasHeader('access_token', 'a0')
            && $r['recipient']['user_id'] === 'u1'
            && str_contains($r['message']['text'], '150.000đ')
            && str_contains($r['message']['text'], route('admin.payouts.index')));
    }

    public function test_client_refreshes_expired_token_and_stores_new_pair(): void
    {
        config(['services.zalo_oa' => ['app_id' => '1', 'secret_key' => 's', 'admin_user_ids' => 'u1']]);
        Setting::set(ZaloOaClient::REFRESH_TOKEN, 'r0');
        Setting::set(ZaloOaClient::EXPIRES_AT, '0');

        Http::fake([
            'oauth.zaloapp.com/*' => Http::response(['access_token' => 'a1', 'refresh_token' => 'r1', 'expires_in' => '90000']),
            'openapi.zalo.me/*' => Http::response(['error' => 0]),
        ]);

        app(ZaloOaClient::class)->sendText('u1', 'hi');

        $this->assertSame('a1', Setting::get(ZaloOaClient::ACCESS_TOKEN));
        $this->assertSame('r1', Setting::get(ZaloOaClient::REFRESH_TOKEN));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth.zaloapp.com') && $r['refresh_token'] === 'r0');
    }

    public function test_admin_can_subscribe_and_unsubscribe_device(): void
    {
        $body = ['endpoint' => 'https://web.push.apple.com/abc', 'keys' => ['p256dh' => 'pk', 'auth' => 'au']];

        $this->actingAs(User::factory()->create())->postJson(route('admin.push.subscribe'), $body)->assertForbidden();

        $this->actingAs($this->manager)->postJson(route('admin.push.subscribe'), $body)->assertOk();
        $this->actingAs($this->manager)->postJson(route('admin.push.subscribe'), $body)->assertOk();
        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($this->manager->id, PushSubscription::first()->user_id);

        $this->actingAs($this->manager)->postJson(route('admin.push.unsubscribe'), ['endpoint' => $body['endpoint']])->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_notifier_pushes_to_admin_devices_only(): void
    {
        $member = User::factory()->create();
        foreach ([$this->manager, $member] as $i => $u) {
            PushSubscription::create(['user_id' => $u->id, 'endpoint' => "https://p/{$i}", 'endpoint_hash' => hash('sha256', "https://p/{$i}"), 'public_key' => 'k', 'auth_token' => 'a']);
        }

        $sender = \Mockery::mock(WebPushSender::class);
        $sender->shouldReceive('send')->once()->withArgs(function ($subs, $title, $body, $url) {
            return $subs->pluck('user_id')->all() === [$this->manager->id]
                && str_contains($title, '150.000đ')
                && str_contains($body, 'khach1')
                && $url === route('admin.payouts.index');
        })->andReturn(1);
        $this->app->instance(WebPushSender::class, $sender);

        app(WithdrawAdminNotifier::class)->notifyNew($this->pending);
    }

    public function test_zalo_failure_does_not_break_withdraw(): void
    {
        config(['services.zalo_oa' => ['app_id' => '1', 'secret_key' => 's', 'admin_user_ids' => 'u1']]);
        Setting::set(ZaloOaClient::REFRESH_TOKEN, 'r0');
        Http::fake(['*' => Http::response(['error' => -124, 'error_description' => 'invalid'], 400)]);

        app(WithdrawAdminNotifier::class)->notifyNew($this->pending);

        $this->assertTrue(true);
    }
}
