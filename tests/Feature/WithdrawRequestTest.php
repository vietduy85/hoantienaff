<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WithdrawRequest;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WithdrawRequestTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WalletService::class);

        $this->user = User::factory()->create([
            'wallet_balance' => 100000,
            'username' => 'testuser',
            'bank_name' => 'BIDV',
            'bank_account_number' => '1234567890',
            'bank_account_name' => 'NGUYEN VAN A',
        ]);
    }

    public function test_fails_when_bank_info_missing(): void
    {
        $user = User::factory()->create([
            'wallet_balance' => 100000,
            'username' => 'nobank',
            'bank_name' => null,
            'bank_account_number' => null,
            'bank_account_name' => null,
        ]);

        $this->expectException(ValidationException::class);

        $this->service->createWithdrawRequest($user, 50000);
    }

    public function test_fails_when_amount_below_minimum(): void
    {
        $response = $this->actingAs($this->user)
            ->from(route('wallet.index'))
            ->post(route('wallet.withdraw'), ['amount' => 5000]);

        $response->assertSessionHasErrors('amount');
        $response->assertRedirect(route('wallet.index'));
        $this->assertDatabaseCount('withdraw_requests', 0);
    }

    public function test_fails_when_amount_exceeds_available_balance(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->createWithdrawRequest($this->user, 150000);
    }

    public function test_fails_when_pending_request_exists(): void
    {
        WithdrawRequest::factory()->create([
            'user_id' => $this->user->id,
            'amount' => 30000,
            'status' => WithdrawRequest::STATUS_PENDING,
        ]);

        $this->expectException(ValidationException::class);

        $this->service->createWithdrawRequest($this->user, 50000);
    }

    public function test_creates_withdraw_request_successfully(): void
    {
        $request = $this->service->createWithdrawRequest($this->user, 50000);

        $this->assertNotNull($request);
        $this->assertSame('pending', $request->status);
        $this->assertSame(50000.0, (float) $request->amount);
        $this->assertSame($this->user->id, $request->user_id);
        $this->assertSame($this->user->username, $request->username);
        $this->assertSame($this->user->bank_name, $request->bank_name);
        $this->assertSame($this->user->bank_account_number, $request->bank_account);
        $this->assertSame($this->user->bank_account_name, $request->account_name);
    }

    public function test_running_no_is_generated(): void
    {
        $request = $this->service->createWithdrawRequest($this->user, 50000);

        $this->assertNotNull($request->running_no);
        $this->assertStringStartsWith('WR', $request->running_no);
        $this->assertStringStartsWith('WR' . now()->format('Ymd'), $request->running_no);

        $user2 = User::factory()->create([
            'wallet_balance' => 50000,
            'username' => 'testuser2',
            'bank_name' => 'BIDV',
            'bank_account_number' => '0987654321',
            'bank_account_name' => 'TRAN VAN B',
        ]);

        $request2 = $this->service->createWithdrawRequest($user2, 30000);

        $this->assertNotNull($request2->running_no);
        $this->assertNotSame($request->running_no, $request2->running_no);
    }

    public function test_wallet_balance_unchanged_after_create(): void
    {
        $balanceBefore = (float) $this->user->fresh()->wallet_balance;

        $this->service->createWithdrawRequest($this->user, 50000);

        $this->assertSame($balanceBefore, (float) $this->user->fresh()->wallet_balance);
    }

    public function test_index_shows_completed_withdraw_in_transactions(): void
    {
        Permission::create(['name' => 'withdrawals.manage']);

        $request = $this->service->createWithdrawRequest($this->user, 50000);

        $this->user->wallet_balance = 100000;
        $this->user->save();

        $admin = User::factory()->create(['username' => 'admin']);
        $admin->givePermissionTo('withdrawals.manage');

        $this->service->completeWithdraw($request, $admin);

        $response = $this->actingAs($this->user)
            ->get(route('wallet.index'));

        $response->assertSee('50.000');
        $response->assertSee('WT');
    }

    public function test_does_not_require_platform(): void
    {
        $request = WithdrawRequest::create([
            'running_no' => 'WR' . now()->format('Ymd') . '9999',
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'amount' => 50000,
            'bank_name' => $this->user->bank_name,
            'bank_account' => $this->user->bank_account_number,
            'account_name' => $this->user->bank_account_name,
            'status' => WithdrawRequest::STATUS_PENDING,
        ]);

        $this->assertNotNull($request);
        $this->assertDatabaseHas('withdraw_requests', ['id' => $request->id]);
    }
}
