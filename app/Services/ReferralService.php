<?php

namespace App\Services;

use App\Models\AffiliateOrderItem;
use App\Models\Referral;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class ReferralService
{
    public function __construct(private readonly WalletService $walletService) {}

    public function referralLink(User $user): string
    {
        return rtrim((string) config('app.url'), '/').'/?ref='.strtolower((string) $user->username);
    }

    public function attachReferrer(User $referredUser, ?string $refUsername): ?Referral
    {
        $refUsername = is_string($refUsername) ? strtolower(trim($refUsername)) : '';

        if ($refUsername === '' || ! preg_match('/^[a-z0-9_]{1,30}$/', $refUsername)) {
            return null;
        }

        $referrer = User::where('username', $refUsername)->first();

        if ($referrer === null) {
            return null;
        }

        return $this->createReferral($referrer, $referredUser);
    }

    public function createReferral(User $referrer, User $referredUser): ?Referral
    {
        if ($referrer->id === $referredUser->id) {
            return null;
        }

        if (Referral::where('referred_user_id', $referredUser->id)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($referrer, $referredUser) {
            $existing = Referral::where('referred_user_id', $referredUser->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return null;
            }

            $referral = Referral::create([
                'referrer_id' => $referrer->id,
                'referred_user_id' => $referredUser->id,
                'completed_orders' => $this->completedOrderCount($referredUser->id),
                'status' => Referral::STATUS_PENDING,
                'reward_amount' => Referral::REWARD_AMOUNT,
            ]);

            if ($referredUser->referred_by === null) {
                $referredUser->update(['referred_by' => $referrer->id]);
            }

            return $referral;
        });
    }

    public function processCompletedOrder(int $referredUserId): void
    {
        DB::transaction(function () use ($referredUserId) {
            $referral = Referral::where('referred_user_id', $referredUserId)
                ->lockForUpdate()
                ->first();

            if ($referral === null || $referral->isRewarded()) {
                return;
            }

            $count = $this->completedOrderCount($referredUserId);

            if ((int) $referral->completed_orders !== $count) {
                $referral->update(['completed_orders' => $count]);
            }

            if ($count >= Referral::REQUIRED_COMPLETED_ORDERS) {
                $this->completeReferral($referral);
            }
        });
    }

    public function completeReferral(Referral $referral): ?Referral
    {
        if ($referral->isRewarded()) {
            return $referral;
        }

        $transaction = $this->walletService->creditReferral($referral);

        if ($transaction === null) {
            return null;
        }

        $referral->update([
            'status' => Referral::STATUS_COMPLETED,
            'rewarded_at' => now(),
        ]);

        return $referral->fresh();
    }

    /**
     * Số đơn hợp lệ của một user:
     * - order_status và affiliate_status đều là "Hoàn thành" (canonical)
     * - chưa bị đảo (reversed_at = null)
     * - đã có cashback thực sự được ghi vào ví (completed WalletTransaction)
     * - đếm distinct order_id (1 đơn nhiều item chỉ tính 1)
     */
    public function completedOrderCount(int $userId): int
    {
        return AffiliateOrderItem::query()
            ->where('user_id', $userId)
            ->where('order_status', AffiliateOrderItem::STATUS_COMPLETED)
            ->where('affiliate_status', AffiliateOrderItem::STATUS_COMPLETED)
            ->whereNull('reversed_at')
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('wallet_transactions')
                    ->whereColumn('wallet_transactions.reference_id', 'affiliate_order_items.id')
                    ->where('wallet_transactions.reference_type', 'affiliate_order_item')
                    ->where('wallet_transactions.type', WalletTransaction::TYPE_CASHBACK)
                    ->where('wallet_transactions.status', WalletTransaction::STATUS_COMPLETED);
            })
            ->distinct()
            ->count('order_id');
    }
}