<?php

namespace App\Services\PromotionNews\DataTransfer;

use App\Models\PromotionNews;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Normalized representation of a single promotion/news entry, independent of
 * the retailer it came from and of whether it lives in the database or is
 * fetched live from a provider.
 */
final class PromotionNewsData
{
    /**
     * @param  array<string, mixed>|null  $rawData
     */
    public function __construct(
        public readonly string $source,
        public readonly string $category,
        public readonly ?string $sourceId = null,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $imageUrl = null,
        public readonly ?string $mobileImageUrl = null,
        public readonly ?string $landingUrl = null,
        public readonly ?CarbonInterface $startAt = null,
        public readonly ?CarbonInterface $endAt = null,
        public readonly bool $isActive = true,
        public readonly int $sortOrder = 0,
        public readonly ?array $rawData = null,
    ) {}

    public static function fromModel(PromotionNews $model): self
    {
        return new self(
            source: (string) $model->source,
            category: (string) $model->category,
            sourceId: $model->source_id !== null ? (string) $model->source_id : null,
            title: $model->title,
            description: $model->description,
            imageUrl: $model->image_url,
            mobileImageUrl: $model->mobile_image_url,
            landingUrl: $model->landing_url,
            startAt: $model->start_at,
            endAt: $model->end_at,
            isActive: (bool) $model->is_active,
            sortOrder: (int) $model->sort_order,
            rawData: $model->raw_data,
        );
    }

    /**
     * Rehydrate a DTO (used to read cached provider/aggregate payloads).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            source: (string) ($data['source'] ?? ''),
            category: (string) ($data['category'] ?? PromotionNews::CATEGORY_OTHER),
            sourceId: isset($data['source_id']) ? (string) $data['source_id'] : null,
            title: $data['title'] ?? null,
            description: $data['description'] ?? null,
            imageUrl: $data['image_url'] ?? null,
            mobileImageUrl: $data['mobile_image_url'] ?? null,
            landingUrl: $data['landing_url'] ?? null,
            startAt: self::parseDate($data['start_at'] ?? null),
            endAt: self::parseDate($data['end_at'] ?? null),
            isActive: (bool) ($data['is_active'] ?? true),
            sortOrder: (int) ($data['sort_order'] ?? 0),
            rawData: is_array($data['raw_data'] ?? null) ? $data['raw_data'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'category' => $this->category,
            'source_id' => $this->sourceId,
            'title' => $this->title,
            'description' => $this->description,
            'image_url' => $this->imageUrl,
            'mobile_image_url' => $this->mobileImageUrl,
            'landing_url' => $this->landingUrl,
            'start_at' => $this->startAt?->toIso8601String(),
            'end_at' => $this->endAt?->toIso8601String(),
            'is_active' => $this->isActive,
            'sort_order' => $this->sortOrder,
            'raw_data' => $this->rawData,
        ];
    }

    public function isCurrentlyActive(?CarbonInterface $now = null): bool
    {
        $now ??= Carbon::now();

        if (! $this->isActive) {
            return false;
        }

        if ($this->startAt !== null && $this->startAt->gt($now)) {
            return false;
        }

        if ($this->endAt !== null && $this->endAt->lt($now)) {
            return false;
        }

        return true;
    }

    private static function parseDate(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
