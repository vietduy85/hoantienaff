<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'running_no' => 'WT' . now()->format('Ymd') . str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'user_id' => User::factory(),
            'username' => $this->faker->userName(),
            'platform' => 'Shopee',
            'type' => 'cashback',
            'direction' => 'credit',
            'amount' => $this->faker->randomFloat(0, 1000, 100000),
            'balance_before' => 0,
            'balance_after' => 0,
            'reference_type' => 'manual',
            'reference_id' => null,
            'description' => $this->faker->sentence(),
            'status' => 'completed',
            'completed_at' => now(),
        ];
    }
}
