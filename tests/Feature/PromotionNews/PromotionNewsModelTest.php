<?php

namespace Tests\Feature\PromotionNews;

use App\Models\PromotionNews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionNewsModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_scope_filters_inactive_rows(): void
    {
        PromotionNews::factory()->create(['title' => 'on']);
        PromotionNews::factory()->inactive()->create(['title' => 'off']);

        $titles = PromotionNews::query()->active()->pluck('title')->all();

        $this->assertSame(['on'], $titles);
    }

    public function test_current_scope_filters_expired_and_future_rows(): void
    {
        PromotionNews::factory()->create(['title' => 'now', 'start_at' => now()->subDay(), 'end_at' => now()->addDay()]);
        PromotionNews::factory()->expired()->create(['title' => 'expired']);
        PromotionNews::factory()->create(['title' => 'future', 'start_at' => now()->addDay(), 'end_at' => now()->addDays(2)]);
        PromotionNews::factory()->inactive()->create(['title' => 'inactive']);

        $titles = PromotionNews::query()->current()->pluck('title')->sort()->values()->all();

        $this->assertSame(['now'], $titles);
    }

    public function test_is_currently_active_helper(): void
    {
        $active = PromotionNews::factory()->make();
        $this->assertTrue($active->isCurrentlyActive());

        $inactive = PromotionNews::factory()->make(['is_active' => false]);
        $this->assertFalse($inactive->isCurrentlyActive());

        $expired = PromotionNews::factory()->make(['end_at' => now()->subDay()]);
        $this->assertFalse($expired->isCurrentlyActive());

        $future = PromotionNews::factory()->make(['start_at' => now()->addDay()]);
        $this->assertFalse($future->isCurrentlyActive());
    }

    public function test_cast_types(): void
    {
        $model = PromotionNews::factory()->create([
            'is_active' => 1,
            'sort_order' => '5',
            'raw_data' => ['id' => 1],
        ]);

        $model->refresh();

        $this->assertTrue($model->is_active);
        $this->assertSame(5, $model->sort_order);
        $this->assertSame(['id' => 1], $model->raw_data);
    }
}
