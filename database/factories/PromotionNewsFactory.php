<?php

namespace Database\Factories;

use App\Models\PromotionNews;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionNews>
 */
class PromotionNewsFactory extends Factory
{
    protected $model = PromotionNews::class;

    public function definition(): array
    {
        return [
            'source' => 'coop',
            'category' => PromotionNews::CATEGORY_SUPERMARKET,
            'source_id' => null,
            'source_type' => PromotionNews::SOURCE_TYPE_MANUAL,
            'title' => fake()->sentence(4),
            'description' => null,
            'image_url' => 'https://cdn.example.test/promotion-news/'.fake()->uuid().'.jpg',
            'mobile_image_url' => null,
            'landing_url' => 'https://example.test/khuyen-mai/'.fake()->slug(),
            'start_at' => null,
            'end_at' => null,
            'is_active' => true,
            'sort_order' => 0,
            'raw_data' => null,
        ];
    }

    public function auto(): static
    {
        return $this->state(fn () => [
            'source_type' => PromotionNews::SOURCE_TYPE_AUTO,
            'source_id' => (string) fake()->unique()->numberBetween(100000, 999999),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'start_at' => now()->subMonth(),
            'end_at' => now()->subDay(),
        ]);
    }
}
