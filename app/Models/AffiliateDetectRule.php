<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-managed URL detection rule ("Chuỗi nhận diện URL").
 *
 * @property int $id
 * @property string $name
 * @property string $pattern
 * @property string $match_type
 * @property string $platform
 * @property string $strategy
 * @property string|null $campaign_id
 * @property int $priority
 * @property string $status
 * @property string|null $description
 * @property string $source
 * @property int|null $created_by
 */
class AffiliateDetectRule extends Model
{
    /** Match semantics. Same vocabulary as the read-only code rules shown in the UI. */
    public const MATCH_SUBSTRING = 'substring';

    public const MATCH_HOSTNAME = 'hostname';

    /** Sources shown in the UI. DB-managed rules are always `database`. */
    public const SOURCE_DATABASE = 'database';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    /**
     * Platforms an admin rule may target. Deliberately the SAME vocabulary as
     * the resolver / `link_requests.platform` so we never invent a platform
     * from free text input.
     *
     * @var array<int, string>
     */
    public const PLATFORMS = [
        'AccessTrade',
        'Khác',
        'Shopee',
        'ShopeeFood',
        'Lazada',
        'TikTok Shop',
        'Tiki',
    ];

    /**
     * Strategies an admin rule may apply. Restricted to methods that DO NOT
     * hijack an existing platform flow (Shopee/Lazada/TikTok/ShopeeFood keep
     * their hard-coded handling). An admin rule can only route a previously
     * unrecognised URL to AccessTrade, or explicitly mark it unsupported.
     *
     * @var array<int, string>
     */
    public const STRATEGIES = [
        'accesstrade_api',
        'unsupported',
    ];

    protected $fillable = [
        'name',
        'pattern',
        'match_type',
        'platform',
        'strategy',
        'campaign_id',
        'priority',
        'status',
        'description',
        'source',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
