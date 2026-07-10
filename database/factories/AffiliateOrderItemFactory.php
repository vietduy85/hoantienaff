<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AffiliateOrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => $this->faker->unique()->numerify('ORD#######'),
            'order_status' => 'completed',
            'checkout_id' => $this->faker->numerify('##########'),
            'ordered_at' => $this->faker->dateTimeBetween('-30 days'),
            'shop_name' => $this->faker->company(),
            'shop_id' => $this->faker->numerify('########'),
            'item_id' => $this->faker->numerify('##########'),
            'item_name' => $this->faker->words(3, true),
            'model_id' => $this->faker->numerify('##########'),
            'item_price' => $this->faker->randomFloat(0, 50000, 5000000),
            'quantity' => $this->faker->numberBetween(1, 5),
            'order_amount' => $this->faker->randomFloat(0, 50000, 5000000),
            'commission_type' => 'Shopee Comm',
            'shopee_commission_rate' => $this->faker->randomFloat(2, 1, 20),
            'shopee_commission' => $this->faker->randomFloat(0, 1000, 50000),
            'seller_commission_rate' => $this->faker->randomFloat(2, 1, 10),
            'total_product_commission' => $this->faker->randomFloat(0, 1000, 100000),
            'order_commission_shopee' => $this->faker->randomFloat(0, 1000, 50000),
            'order_commission_seller' => $this->faker->randomFloat(0, 1000, 50000),
            'total_order_commission' => $this->faker->randomFloat(0, 2000, 100000),
            'agreed_commission_rate' => $this->faker->randomFloat(2, 5, 30),
            'net_commission' => $this->faker->randomFloat(0, 1000, 100000),
            'affiliate_status' => 'Hoàn thành',
            'import_batch' => now()->format('Ymd_His'),
            'platform' => 'Shopee',
            'user_id' => User::factory(),
            'username' => $this->faker->userName(),
            'cashback_rate' => 0.50,
            'cashback_amount' => $this->faker->randomFloat(0, 1000, 100000),
            'sub_id1' => $this->faker->userName(),
        ];
    }
}
