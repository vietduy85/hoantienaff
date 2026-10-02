<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyVersion;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Support\CreditCard\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * CashbackQuotaService — "quota hoàn tiền còn lại" của một thẻ trong kỳ hiện tại.
 *
 * ---------------------------------------------------------------------------
 * QUOTA LÀ GÌ (đọc từ engine, không tự chế)
 * ---------------------------------------------------------------------------
 * `CashbackCalculator` áp BA lớp cap:
 *
 *   1. `PolicyTierCategory.max_cashback_per_transaction` — trần mỗi giao dịch.
 *   2. `PolicyTierCategory.max_cashback_per_category_per_period` — trần mỗi
 *      danh mục trong kỳ.
 *   3. `PolicyTier.max_cashback_per_period` — trần TOÀN KỲ của bậc.
 *
 * Lớp 3 là "quota": nó là hồ duy nhất CHIA CHUNG cho cả kỳ, nên "còn lại" chỉ có
 * nghĩa với lớp này. Lớp 1 và 2 là trần theo từng lát, không cộng dồn thành một
 * con số — hiển thị chúng như quota sẽ bịa ra một giới hạn mà engine không có.
 *
 * Lớp 3 CHỈ tính cashback của rule có `counts_toward_tier_cap = true`, nên phần
 * "đã dùng" cũng phải lọc đúng cờ đó, nếu không sẽ lệch với engine.
 *
 * ---------------------------------------------------------------------------
 * BẬC ĐƯỢC CHỌN THẾ NÀO
 * ---------------------------------------------------------------------------
 * Tier theo `UserCard.desired_spend` — mức chi tiêu MONG MUỐN của thẻ — qua
 * `TierResolverService`, tức là dùng đúng khoảng `[min, max)` mà engine dùng.
 * Không hard-code tên bậc, không tự so sánh ngưỡng.
 *
 * LƯU Ý CẦN BIẾT: engine cashback thực tế chọn bậc theo TỔNG CHI TIÊU THỰC TẾ
 * của kỳ (`CashbackRecordService::calculatePeriod()`), không theo
 * `desired_spend`. Nên khi mục tiêu mong muốn và chi tiêu thực tế rơi lệch bậc,
 * con số quota ở đây là trần của bậc MONG MUỐN — nó mô tả "đạt mục tiêu thì có bao
 * nhiêu", không phải trần engine đang kẹt. Đây là điều khoá theo yêu cầu sản
 * phẩm; `desired_spend` KHÔNG được dùng để tính lại cashback.
 *
 * ---------------------------------------------------------------------------
 * SỐ QUERY
 * ---------------------------------------------------------------------------
 * Gọi cho N thẻ: tối đa 2 + 2 query cố định (gom version của các kỳ, gom bậc
 * của các version, gom phần "đã dùng" của các thẻ) cộng 1 query mỗi thẻ CHƯA có
 * kỳ để resolve version. Số query là O(số thẻ), KHÔNG phải O(số giao dịch) —
 * không kéo danh sách giao dịch về PHP để cộng tay.
 */
class CashbackQuotaService
{
    public function __construct(
        private readonly TierResolverService $tiers,
    ) {}

    /**
     * Quota của nhiều thẻ, khoá theo `user_card_id`.
     *
     * @param  Collection<int, UserCard>  $cards
     * @param  Collection<int, StatementPeriod>  $periods  kỳ hiện tại, đã lọc `open` + chứa hôm nay
     * @return array<int, array{
     *     tier_id: int|null,
     *     tier_name: string|null,
     *     limit: string|null,
     *     used: string,
     *     remaining: string|null,
     *     has_limit: bool,
     *     is_exhausted: bool
     * }>
     */
    public function forCards(Collection $cards, Collection $periods): array
    {
        if ($cards->isEmpty()) {
            return [];
        }

        $periodByCard = $periods->keyBy('user_card_id');

        $versions = $this->resolveVersions($cards, $periodByCard);
        $tiersByVersion = $this->tiersByVersionId($versions);
        $usedByCard = $this->usedTowardTierCap($cards->pluck('id')->all(), $periods->pluck('id')->all());

        $result = [];

        foreach ($cards as $card) {
            $cardId = (int) $card->id;
            $version = $versions[$cardId] ?? null;
            $used = $usedByCard[$cardId] ?? '0.00';

            // `desired_spend` NULL ⇒ 0: chưa đặt mục tiêu thì tra bậc phủ 0.
            $desiredSpend = Decimal::money($card->desired_spend);

            $tier = $version === null
                ? null
                : $this->tiers->resolveTierFromTiers(
                    $tiersByVersion[(int) $version->id] ?? collect(),
                    // Ép `float` ở ĐÂY là hợp đồng của engine: `min_total_spend`/
                    // `max_total_spend` vốn là cột float và `resolveTierFromTiers()`
                    // so sánh trên float. Mọi phép tính TIỀN của quota vẫn chạy
                    // qua `Decimal` (bcmath) — chỉ có bước TRA CỨ NGƯỠNG bậc đi qua
                    // float, và nó không cộng/trừ số tiền nào.
                    (float) $desiredSpend,
                );

            $limit = $tier?->max_cashback_per_period === null
                ? null
                : Decimal::money($tier->max_cashback_per_period);

            // Không có trần ⇒ không có "còn lại". Không ép về 0 vì 0 sẽ bị hiểu là
            // đã hết quota.
            $remaining = $limit === null ? null : Decimal::clampZero(Decimal::subtract($limit, $used));

            $result[$cardId] = [
                'tier_id' => $tier === null ? null : (int) $tier->id,
                'tier_name' => $tier?->name,
                'limit' => $limit,
                'used' => $used,
                'remaining' => $remaining,
                'has_limit' => $limit !== null,
                'is_exhausted' => $remaining !== null && $remaining === '0.00',
            ];
        }

        return $result;
    }

    /**
     * Policy version của từng thẻ.
     *
     * Ưu tiên `policy_id` mà engine đã gắn vào kỳ hiện tại — đó đúng là version
     * đang được dùng để tính kỳ này. Thẻ chưa có kỳ trong DB thì resolve theo hôm
     * nay (`resolvePolicyVersion`), vẫn đọc chứ không tạo gì.
     *
     * @param  Collection<int, UserCard>  $cards
     * @param  Collection<int, StatementPeriod>  $periodByCard
     * @return array<int, PolicyVersion|null>
     */
    private function resolveVersions(Collection $cards, Collection $periodByCard): array
    {
        $today = CarbonImmutable::now();

        $attachedIds = $periodByCard
            ->map(fn (StatementPeriod $period): ?int => $period->policy_id === null ? null : (int) $period->policy_id)
            ->filter()
            ->unique()
            ->values();

        // MỘT query cho tất cả version mà các kỳ đã gắn sẵn.
        $attached = $attachedIds->isEmpty()
            ? collect()
            : PolicyVersion::query()->whereIn('id', $attachedIds->all())->get()->keyBy('id');

        $versions = [];

        foreach ($cards as $card) {
            $cardId = (int) $card->id;
            $period = $periodByCard->get($cardId);

            if ($period !== null && $period->policy_id !== null) {
                $versions[$cardId] = $attached->get((int) $period->policy_id);

                continue;
            }

            $versions[$cardId] = $this->tiers->resolvePolicyVersion($card, $today);
        }

        return $versions;
    }

    /**
     * Gom bậc của mọi version trong MỘT query, khoá theo `policy_id`.
     *
     * @param  array<int, PolicyVersion|null>  $versions
     * @return array<int, Collection<int, PolicyTier>>
     */
    private function tiersByVersionId(array $versions): array
    {
        $versionIds = collect($versions)
            ->filter()
            ->map(fn (PolicyVersion $version): int => (int) $version->id)
            ->unique()
            ->values();

        if ($versionIds->isEmpty()) {
            return [];
        }

        return PolicyTier::query()
            ->whereIn('policy_id', $versionIds->all())
            ->get()
            ->groupBy('policy_id')
            ->map(fn (Collection $tiers): Collection => $tiers->values())
            ->all();
    }

    /**
     * Cashback ĐÃ DÙNG trong kỳ hiện tại tính vào trần toàn kỳ.
     *
     * Lọc theo `counts_toward_tier_cap` của rule mà engine đã ghi vào từng giao
     * dịch (`policy_tier_category_id`) — đọc cờ của chính rule, không suy đoán từ
     * tên bậc. Giao dịch không eligible có snapshot 0 nên không ảnh hưởng.
     *
     * @param  array<int, int>  $cardIds
     * @param  array<int, int>  $periodIds
     * @return array<int, string>
     */
    private function usedTowardTierCap(array $cardIds, array $periodIds): array
    {
        if ($cardIds === [] || $periodIds === []) {
            return [];
        }

        $transactions = (new Transaction)->getTable();
        $rules = (new PolicyTierCategory)->getTable();

        $rows = Transaction::query()
            ->join($rules.' as cc_rule', 'cc_rule.id', '=', $transactions.'.policy_tier_category_id')
            ->where('cc_rule.counts_toward_tier_cap', true)
            ->whereIn($transactions.'.user_card_id', $cardIds)
            ->whereIn($transactions.'.statement_period_id', $periodIds)
            ->groupBy($transactions.'.user_card_id')
            ->select($transactions.'.user_card_id')
            ->selectRaw('SUM('.$transactions.'.cashback_amount_snapshot) as used')
            ->get();

        $used = [];

        foreach ($rows as $row) {
            $used[(int) $row->user_card_id] = Decimal::money($row->used);
        }

        return $used;
    }
}
