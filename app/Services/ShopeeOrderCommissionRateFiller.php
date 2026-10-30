<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tự động điền tỉ lệ hoa hồng (sàn / shop) cho đơn Shopee từ 25/10/2026.
 *
 * AddLiveTag Conversions API chỉ trả số tiền hoa hồng, không trả tỉ lệ, nên
 * tỉ lệ được suy ra theo các tầng bằng chứng (ưu tiên từ trên xuống):
 *   1. snapshot  – link_requests.*_at_create (tỉ lệ lúc user tạo link, khớp user+item+thời gian)
 *   2. cache     – affiliate_cache theo ngày gần ngày đặt đơn
 *   3. product   – ProductDataService (tỉ lệ HIỆN TẠI, chỉ là ước lượng)
 *
 * Mỗi ứng viên được đối chiếu với tổng hoa hồng thực nhận
 * (implied% = total_order_commission / order_amount):
 *   - tổng ứng viên < implied  → loại (mâu thuẫn)
 *   - tổng ≈ implied           → confidence 'exact' (snapshot/cache)
 *   - tổng > implied (≤ 5pp)   → 'estimate' (tỉ lệ niêm yết cao hơn thực nhận)
 *   - tầng product luôn 'estimate'
 * Chỉ 'exact' (và dữ liệu CSV có source NULL) được dùng cho thông báo cào xu.
 *
 * Không bao giờ ghi đè tỉ lệ đã có từ CSV, không đụng tiền/ví/cashback.
 * Cột tỉ lệ của bảng là NOT NULL nên "chưa biết" = 0.00 với source NULL.
 */
class ShopeeOrderCommissionRateFiller
{
    public const FROM = '2026-10-25 00:00:00';

    public const SOURCE_SNAPSHOT = 'snapshot';

    public const SOURCE_CACHE = 'cache';

    public const SOURCE_PRODUCT = 'product-data';

    public const EXACT = 'exact';

    public const ESTIMATE = 'estimate';

    /** Đơn có order_amount = 0: tỉ lệ coi là 0%. */
    public const SOURCE_ZERO_AMOUNT = 'zero-amount';

    /** Sai số cho tỉ lệ lưu 4 chữ số thập phân (cache / product), điểm %. */
    private const TOL_PRECISE = 0.05;

    /** Sai số cho snapshot (lưu làm tròn 2 chữ số dạng phân số), điểm %. */
    private const TOL_SNAPSHOT = 1.0;

    /** Tỉ lệ niêm yết cao hơn thực nhận tối đa bao nhiêu điểm % vẫn chấp nhận làm estimate. */
    private const MAX_OVERSHOOT = 5.0;

    /** Không thử lại dòng chưa giải quyết được trong khoảng này. */
    private const RETRY_HOURS = 12;

    /** Bỏ cuộc với đơn quá cũ. */
    private const GIVE_UP_DAYS = 30;

    private const MAX_PRODUCT_CALLS = 30;

    public function __construct(private readonly ProductDataService $productData)
    {
    }

    /**
     * @return array{scope:int, applied:int, skipped:array<string,int>, rows:list<array<string,mixed>>}
     */
    public function fill(bool $apply = true, ?string $from = null, ?int $limitRows = null): array
    {
        $tz = (string) config('app.timezone', 'Asia/Ho_Chi_Minh');
        $from = $from ?? self::FROM;
        $now = CarbonImmutable::now($tz);

        $query = DB::table('affiliate_order_items')
            ->where('platform', 'Shopee')
            ->where('ordered_at', '>=', $from)
            ->where('ordered_at', '>=', $now->subDays(self::GIVE_UP_DAYS)->format('Y-m-d H:i:s'))
            ->where(fn ($q) => $this->unresolvedScope($q))
            ->where(function ($q) use ($now) {
                $q->whereNull('commission_rate_checked_at')
                    ->orWhere('commission_rate_checked_at', '<', $now->subHours(self::RETRY_HOURS)->format('Y-m-d H:i:s'));
            })
            ->orderBy('id');

        if ($limitRows !== null && $limitRows > 0) {
            $query->limit($limitRows);
        }

        $rows = $query->get();
        $productCache = [];
        $productCalls = 0;
        $skipped = [];
        $applied = 0;
        $report = [];

        foreach ($rows as $row) {
            $orderAmount = (float) $row->order_amount;
            $commission = (float) $row->total_order_commission;

            if ($orderAmount <= 0) {
                $best = ['source' => self::SOURCE_ZERO_AMOUNT, 'confidence' => self::EXACT, 'shopee' => 0.0, 'seller' => 0.0];
                $implied = null;
                $this->commit($row, $best, $implied, $apply, $now, $applied, $report);

                continue;
            }

            // Hoa hồng thực nhận = 0 (đơn hủy...): không có mốc đối chiếu, chỉ lấy ước lượng.
            $implied = $commission > 0 ? $commission / $orderAmount * 100 : null;
            $anchor = $row->clicked_at ?? $row->ordered_at;

            $candidates = [];
            if ($row->user_id !== null) {
                $c = $this->snapshotCandidate((int) $row->user_id, (int) $row->item_id, (string) $anchor);
                if ($c !== null) {
                    $candidates[] = $c;
                }
            }
            $c = $this->cacheCandidate((int) $row->item_id, (string) $row->ordered_at);
            if ($c !== null) {
                $candidates[] = $c;
            }

            $best = $implied !== null ? $this->pickBest($candidates, $implied) : null;

            if ($best === null) {
                $itemId = (int) $row->item_id;
                if (! array_key_exists($itemId, $productCache) && $productCalls < self::MAX_PRODUCT_CALLS) {
                    $productCache[$itemId] = $this->productCandidate($itemId);
                    $productCalls++;
                }
                $pc = $productCache[$itemId] ?? null;
                if ($pc !== null) {
                    $candidates[] = $pc;
                    $best = $implied !== null ? $this->pickBest([$pc], $implied) : null;
                }
            }

            // Mục đích chính là nhận diện đơn thời trang: tỉ lệ không khớp hoa hồng thực nhận
            // vẫn được lưu dạng estimate (ưu tiên snapshot > cache > product).
            if ($best === null && $candidates !== []) {
                $best = $this->pickLoose($candidates);
            }

            if ($best === null) {
                $skipped['no_source_available'] = ($skipped['no_source_available'] ?? 0) + 1;
                $report[] = ['id' => $row->id, 'action' => 'skip', 'reason' => 'no_source_available', 'implied' => $implied === null ? null : round($implied, 2)];
                if ($apply) {
                    $this->touchChecked((int) $row->id, $now);
                }

                continue;
            }

            if ($row->commission_rate_confidence === self::ESTIMATE && $best['confidence'] !== self::EXACT) {
                $skipped['estimate_not_upgraded'] = ($skipped['estimate_not_upgraded'] ?? 0) + 1;
                if ($apply) {
                    $this->touchChecked((int) $row->id, $now);
                }

                continue;
            }

            $this->commit($row, $best, $implied, $apply, $now, $applied, $report);
        }

        if ($apply && $applied > 0) {
            Log::info('[ShopeeRateFiller] filled commission rates', ['applied' => $applied, 'scope' => $rows->count()]);
        }

        return ['scope' => $rows->count(), 'applied' => $applied, 'skipped' => $skipped, 'rows' => $report];
    }

    /** Dòng chưa có tỉ lệ (0/0, không có nguồn), mới chỉ có estimate, hoặc từng là amount 0 nay đã có tiền. */
    private function unresolvedScope($q): void
    {
        $q->where(function ($w) {
            $w->where('shopee_commission_rate', '<=', 0)
                ->where('seller_commission_rate', '<=', 0)
                ->whereNull('commission_rate_source');
        })->orWhere('commission_rate_confidence', self::ESTIMATE)
            ->orWhere(function ($w) {
                $w->where('commission_rate_source', self::SOURCE_ZERO_AMOUNT)->where('order_amount', '>', 0);
            });
    }

    /**
     * Ghi kết quả (hoặc chỉ ghi nhận đề xuất khi dry-run).
     *
     * @param  array{source:string,confidence:string,shopee:float,seller:float}  $best
     * @param  list<array<string,mixed>>  $report
     */
    private function commit(object $row, array $best, ?float $implied, bool $apply, CarbonImmutable $now, int &$applied, array &$report): void
    {
        $entry = [
            'id' => $row->id,
            'order_id' => $row->order_id,
            'action' => $apply ? 'apply' : 'propose',
            'source' => $best['source'],
            'confidence' => $best['confidence'],
            'shopee' => $best['shopee'],
            'seller' => $best['seller'],
            'implied' => $implied === null ? null : round($implied, 2),
        ];

        if ($apply) {
            $affected = DB::table('affiliate_order_items')
                ->where('id', $row->id)
                ->where(fn ($q) => $this->unresolvedScope($q))
                ->update([
                    'shopee_commission_rate' => $best['shopee'],
                    'seller_commission_rate' => $best['seller'],
                    'commission_rate_source' => $best['source'],
                    'commission_rate_confidence' => $best['confidence'],
                    'commission_rate_checked_at' => $now->format('Y-m-d H:i:s'),
                ]);
            if ($affected === 1) {
                $applied++;
            } else {
                $entry['action'] = 'skip';
                $entry['reason'] = 'concurrent_change';
            }
        }

        $report[] = $entry;
    }

    /**
     * Lấy ứng viên đầu tiên hợp lệ (thứ tự snapshot > cache > product) làm estimate.
     *
     * @param  list<array<string,mixed>>  $candidates
     * @return array{source:string,confidence:string,shopee:float,seller:float}|null
     */
    private function pickLoose(array $candidates): ?array
    {
        foreach ($candidates as $c) {
            if ($c['shopee'] < 0 || $c['seller'] < 0 || ($c['shopee'] + $c['seller']) <= 0 || $c['shopee'] > 100 || $c['seller'] > 100) {
                continue;
            }

            return [
                'source' => $c['source'],
                'confidence' => self::ESTIMATE,
                'shopee' => round($c['shopee'], 2),
                'seller' => round($c['seller'], 2),
            ];
        }

        return null;
    }

    private function touchChecked(int $id, CarbonImmutable $now): void
    {
        DB::table('affiliate_order_items')->where('id', $id)->update([
            'commission_rate_checked_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Chọn ứng viên khớp nhất với implied%; hòa thì ưu tiên thứ tự đầu vào.
     *
     * @param  list<array<string,mixed>>  $candidates
     * @return array{source:string,confidence:string,shopee:float,seller:float}|null
     */
    private function pickBest(array $candidates, float $implied): ?array
    {
        $best = null;
        $bestGap = null;

        foreach ($candidates as $c) {
            $shopee = $c['shopee'];
            $seller = $c['seller'];
            if ($shopee < 0 || $seller < 0 || ($shopee + $seller) <= 0 || $shopee > 100 || $seller > 100) {
                continue;
            }

            $gap = ($shopee + $seller) - $implied;
            if ($gap < -$c['tol']) {
                continue;
            }

            if (abs($gap) <= $c['tol']) {
                $confidence = $c['source'] === self::SOURCE_PRODUCT ? self::ESTIMATE : self::EXACT;
            } elseif ($gap <= self::MAX_OVERSHOOT) {
                $confidence = self::ESTIMATE;
            } else {
                continue;
            }

            if ($bestGap === null || abs($gap) < $bestGap) {
                $bestGap = abs($gap);
                $best = [
                    'source' => $c['source'],
                    'confidence' => $confidence,
                    'shopee' => round($shopee, 2),
                    'seller' => round($seller, 2),
                ];
            }
        }

        return $best;
    }

    /** @return array<string,mixed>|null */
    private function snapshotCandidate(int $userId, int $itemId, string $anchor): ?array
    {
        $lr = DB::table('link_requests')
            ->where('user_id', $userId)
            ->where('item_id', $itemId)
            ->where('platform', 'Shopee')
            ->whereNotNull('shopee_commission_rate_at_create')
            ->whereNotNull('commission_rate_source')
            ->whereNotIn('commission_rate_source', ['none', 'fallback'])
            ->where('commission_rate_status', '!=', 'unverified')
            ->where('created_at', '<=', CarbonImmutable::parse($anchor)->addHour()->format('Y-m-d H:i:s'))
            ->where('created_at', '>=', CarbonImmutable::parse($anchor)->subDays(7)->format('Y-m-d H:i:s'))
            ->orderByDesc('created_at')
            ->first(['shopee_commission_rate_at_create as sr', 'seller_commission_rate_at_create as se']);

        if ($lr === null) {
            return null;
        }

        return [
            'source' => self::SOURCE_SNAPSHOT,
            'shopee' => (float) $lr->sr * 100,
            'seller' => (float) ($lr->se ?? 0) * 100,
            'tol' => self::TOL_SNAPSHOT,
        ];
    }

    /** @return array<string,mixed>|null */
    private function cacheCandidate(int $itemId, string $orderedAt): ?array
    {
        $date = CarbonImmutable::parse($orderedAt);

        $cache = DB::table('affiliate_cache')
            ->where('item_id', $itemId)
            ->whereIn('platform', ['Shopee', 'shopee'])
            ->whereNotNull('shopee_commission_rate')
            ->whereNotNull('commission_rate_source')
            ->whereNotIn('commission_rate_source', ['none', 'fallback'])
            ->where('commission_rate_status', '!=', 'unverified')
            ->whereDate('cache_date', '<=', $date->toDateString())
            ->whereDate('cache_date', '>=', $date->subDays(7)->toDateString())
            ->orderByDesc('cache_date')
            ->first(['shopee_commission_rate as sr', 'seller_commission_rate as se']);

        if ($cache === null) {
            return null;
        }

        return [
            'source' => self::SOURCE_CACHE,
            'shopee' => (float) $cache->sr * 100,
            'seller' => (float) ($cache->se ?? 0) * 100,
            'tol' => self::TOL_PRECISE,
        ];
    }

    /** @return array<string,mixed>|null */
    private function productCandidate(int $itemId): ?array
    {
        try {
            $result = $this->productData->getByUrl('https://shopee.vn/product/0/'.$itemId);
        } catch (\Throwable) {
            return null;
        }

        if (! ($result['success'] ?? false)) {
            return null;
        }

        $shopee = $result['shopee_commission_rate'] ?? null;
        $seller = $result['seller_commission_rate'] ?? null;
        if ($shopee === null && $seller === null) {
            return null;
        }

        return [
            'source' => self::SOURCE_PRODUCT,
            'shopee' => (float) ($shopee ?? 0) * 100,
            'seller' => (float) ($seller ?? 0) * 100,
            'tol' => self::TOL_PRECISE,
        ];
    }
}
