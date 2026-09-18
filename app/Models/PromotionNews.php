<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PromotionNews extends Model
{
    use HasFactory;

    public const SOURCE_TYPE_AUTO = 'auto';

    public const SOURCE_TYPE_MANUAL = 'manual';

    public const CATEGORY_SUPERMARKET = 'supermarket';

    public const CATEGORY_CREDIT_CARD = 'credit-card';

    public const CATEGORY_FOOD = 'food';

    public const CATEGORY_ELECTRONICS = 'electronics';

    public const CATEGORY_FASHION = 'fashion';

    public const CATEGORY_TRAVEL = 'travel';

    public const CATEGORY_OTHER = 'other';

    protected $table = 'promotion_news';

    protected $fillable = [
        'source',
        'category',
        'source_id',
        'source_type',
        'title',
        'description',
        'image_url',
        'mobile_image_url',
        'landing_url',
        'start_at',
        'end_at',
        'is_active',
        'sort_order',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'raw_data' => 'array',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return [
            self::CATEGORY_SUPERMARKET => 'Siêu thị',
            self::CATEGORY_CREDIT_CARD => 'Thẻ tín dụng',
            self::CATEGORY_FOOD => 'Đồ ăn',
            self::CATEGORY_ELECTRONICS => 'Điện tử',
            self::CATEGORY_FASHION => 'Thời trang',
            self::CATEGORY_TRAVEL => 'Du lịch',
            self::CATEGORY_OTHER => 'Khác',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeCurrent(Builder $query, ?Carbon $now = null): Builder
    {
        $now ??= Carbon::now();

        return $query->active()
            ->where(fn (Builder $q) => $q->whereNull('start_at')->orWhere('start_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $now));
    }

    public function scopeBySource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function isCurrentlyActive(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        if (! $this->is_active) {
            return false;
        }

        if ($this->start_at !== null && $this->start_at->gt($now)) {
            return false;
        }

        if ($this->end_at !== null && $this->end_at->lt($now)) {
            return false;
        }

        return true;
    }
}
