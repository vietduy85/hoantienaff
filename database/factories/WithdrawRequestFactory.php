<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class WithdrawRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'running_no' => 'WR' . now()->format('Ymd') . str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'user_id' => User::factory(),
            'username' => $this->faker->userName(),
            'amount' => $this->faker->randomFloat(0, 10000, 500000),
            'bank_name' => $this->faker->randomElement(['BIDV', 'Vietcombank', 'Techcombank', 'MB Bank']),
            'bank_account' => $this->faker->numerify('##########'),
            'account_name' => $this->faker->name(),
            'status' => 'pending',
        ];
    }
}
