<?php

namespace Database\Factories;

use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReferralFactory extends Factory
{
    protected $model = Referral::class;

    public function definition(): array
    {
        return [
            'referrer_id' => User::factory(),
            'referred_user_id' => User::factory(),
            'completed_orders' => 0,
            'status' => Referral::STATUS_PENDING,
            'reward_amount' => Referral::REWARD_AMOUNT,
            'rewarded_at' => null,
        ];
    }
}