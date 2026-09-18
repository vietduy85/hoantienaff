<?php

namespace Tests\Feature\PromotionNews;

use App\Models\PromotionNews;
use App\Services\PromotionNews\Contracts\PromotionNewsProvider;
use App\Services\PromotionNews\DataTransfer\PromotionNewsData;
use App\Services\PromotionNews\PromotionNewsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class PromotionNewsManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_merges_provider_and_manual_news_and_filters_inactive(): void
    {
        PromotionNews::factory()->create(['source' => 'vib', 'title' => 'Thẻ VIB', 'category' => 'credit-card']);
        PromotionNews::factory()->expired()->create(['source' => 'vib', 'title' => 'Hết hạn']);

        $manager = new PromotionNewsManager([
            $this->fakeProvider('coop', [$this->dto('coop', 'Co.op deal')]),
        ]);

        $titles = array_map(fn (PromotionNewsData $i) => $i->title, $manager->all());

        $this->assertContains('Co.op deal', $titles);
        $this->assertContains('Thẻ VIB', $titles);
        $this->assertNotContains('Hết hạn', $titles);
    }

    public function test_representative_news_limits_one_per_source_and_respects_preferred_order(): void
    {
        $manager = new PromotionNewsManager([
            $this->fakeProvider('winmart', [$this->dto('winmart', 'Win 1'), $this->dto('winmart', 'Win 2')]),
            $this->fakeProvider('coop', [$this->dto('coop', 'Coop 1'), $this->dto('coop', 'Coop 2')]),
            $this->fakeProvider('bhx', [$this->dto('bhx', 'Bhx 1')]),
            $this->fakeProvider('kingfoodmart', [$this->dto('kingfoodmart', 'Kfm 1')]),
            $this->fakeProvider('vib', [$this->dto('vib', 'Vib 1')]),
        ]);

        $representative = $manager->representativeNews(4);

        $this->assertCount(4, $representative);
        $this->assertSame(['coop', 'bhx', 'winmart', 'kingfoodmart'], array_map(fn ($i) => $i->source, $representative));
    }

    public function test_provider_failure_is_isolated(): void
    {
        $counter = new \stdClass;
        $counter->calls = 0;

        $manager = new PromotionNewsManager([
            $this->failingProvider('coop', $counter),
            $this->fakeProvider('bhx', [$this->dto('bhx', 'Bhx deal')]),
        ]);

        $all = $manager->all();

        $this->assertSame(['Bhx deal'], array_map(fn ($i) => $i->title, $all));
        $this->assertSame(1, $counter->calls);
        $this->assertFalse(Cache::has('promotion-news:provider:coop:v1'));
    }

    public function test_provider_results_are_cached_and_reused(): void
    {
        $counter = new \stdClass;
        $counter->calls = 0;

        $manager = new PromotionNewsManager([
            $this->fakeProvider('coop', [$this->dto('coop', 'Coop deal')], $counter),
        ]);

        $manager->all();

        $this->assertSame(1, $counter->calls);
        $this->assertTrue(Cache::has('promotion-news:provider:coop:v1'));
        $this->assertTrue(Cache::has('promotion-news:aggregate:v1'));

        // A fresh manager reads the cached provider payload without refetching.
        Cache::forget('promotion-news:aggregate:v1');

        $second = new PromotionNewsManager([
            $this->fakeProvider('coop', [], $counter),
        ]);

        $second->all();

        $this->assertSame(1, $counter->calls);
    }

    public function test_for_source_sources_and_categories(): void
    {
        $manager = new PromotionNewsManager([
            $this->fakeProvider('coop', [$this->dto('coop', 'Coop deal')]),
            $this->fakeProvider('vib', [$this->dto('vib', 'Vib deal', category: 'credit-card')]),
        ]);

        $this->assertCount(1, $manager->forSource('coop'));
        $this->assertSame(['coop', 'vib'], $manager->sourcesWithNews());
        $this->assertSame(['supermarket', 'credit-card'], $manager->categoriesWithNews());
    }

    private function dto(string $source, string $title, int $sort = 0, string $category = 'supermarket'): PromotionNewsData
    {
        return new PromotionNewsData(
            source: $source,
            category: $category,
            sourceId: $title,
            title: $title,
            imageUrl: 'https://cdn.example.test/'.$source.'.jpg',
            landingUrl: 'https://example.test/'.$source,
            sortOrder: $sort,
        );
    }

    /**
     * @param  array<int, PromotionNewsData>  $items
     */
    private function fakeProvider(string $source, array $items, ?\stdClass $counter = null): PromotionNewsProvider
    {
        return new class($source, $items, $counter) implements PromotionNewsProvider
        {
            public function __construct(
                private string $source,
                private array $items,
                private ?\stdClass $counter,
            ) {}

            public function source(): string
            {
                return $this->source;
            }

            public function label(): string
            {
                return ucfirst($this->source);
            }

            public function category(): string
            {
                return 'supermarket';
            }

            public function getNews(): array
            {
                if ($this->counter !== null) {
                    $this->counter->calls++;
                }

                return $this->items;
            }
        };
    }

    private function failingProvider(string $source, \stdClass $counter): PromotionNewsProvider
    {
        return new class($source, $counter) implements PromotionNewsProvider
        {
            public function __construct(private string $source, private \stdClass $counter) {}

            public function source(): string
            {
                return $this->source;
            }

            public function label(): string
            {
                return ucfirst($this->source);
            }

            public function category(): string
            {
                return 'supermarket';
            }

            public function getNews(): array
            {
                $this->counter->calls++;

                throw new RuntimeException('provider down');
            }
        };
    }
}
