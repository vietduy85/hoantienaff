<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\CategoryComboItem;
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
 * CashbackQuotaService — "còn có thể chi thêm bao nhiêu để đủ hoàn tiền" của
 * kỳ hiện tại, theo BẬC MONG MUỐN của thẻ.
 *
 * ---------------------------------------------------------------------------
 * CHỈ ĐỌC — KHÔNG BAO GIỜ RECALCULATE
 * ---------------------------------------------------------------------------
 * `CashbackCalculator` là nguồn duy nhất của mọi con số hoàn tiền. Service này
 * đọc `credit_card_transactions.cashback_amount_snapshot` (tiền engine ĐÃ ghi) và
 * cộng lại theo nhóm; nó không gọi calculator, không đụng
 * `CashbackRecordService`, không sửa `policy_tier_category_id`. Nếu projection ở
 * đây khác cashback lịch sử thì LỊCH SỬ là đúng — engine mới là nguồn sự thật.
 *
 * ---------------------------------------------------------------------------
 * BẬC LẤY TỪ `desired_spend`, TUYỆT ĐỐI KHÔNG TỪ CHI TIÊU THỰC TẾ
 * ---------------------------------------------------------------------------
 * Tier theo mục tiêu chi tiêu của thẻ, qua `TierResolverService` — tức là dùng
 * đúng khoảng `[min_total_spend, max_total_spend)` mà engine dùng. Chi tiêu thực
 * tế chỉ được dùng để (a) biết giao dịch nào đã phát sinh trong kỳ và (b) định
 * vị band hiện tại khi walk dải rate; nó KHÔNG bao giờ chọn bậc. Đây là điều
 * khoá theo yêu cầu sản phẩm: quota trả lời "đạt mục tiêu thì còn bao nhiêu",
 * không phải "engine đang kẹt ở đâu".
 *
 * LƯU Ý CẦN BIẾT: engine cashback thật chọn bậc theo TỔNG CHI TIÊU THỰC TẾ của
 * kỳ. Nên khi mục tiêu và chi tiêu thực tế rơi lệch bậc, snapshot của giao dịch
 * trỏ vào rule của bậc KHÁC với bậc quota. Vì vậy phần "đã dùng" ở đây KHÔNG đọc
 * cờ của rule trong snapshot (xem `cashbackUsedFor()`) mà gán theo CATEGORY của
 * rule quota trong bậc đích — theo đúng hợp đồng "quota service chỉ dùng snapshot
 * để biết TIỀN đã phát sinh".
 *
 * ---------------------------------------------------------------------------
 * HAI TẦNG CON SỐ, KHÔNG ĐƯỢC TRỘN
 * ---------------------------------------------------------------------------
 *   - `tier_cashback_*`     = trần CHUNG toàn kỳ của bậc
 *                              (`PolicyTier.max_cashback_per_period`), và "đã
 *                              dùng" của nó CHỈ cộng cashback của các rule
 *                              `is_quota_category`.
 *   - `cashback_*` (rule)   = trần RIÊNG của từng danh mục/combo
 *                              (`max_cashback_per_category_per_period`).
 *   - `cashback_available_for_rule` = MIN của hai tầng trên.
 *
 * Hai tầng dùng hai cột khác nhau nên KHÔNG chia trần chung cho các danh mục:
 * mỗi danh mục báo riêng khả năng của NÓ, dựa trên ngân sách chung còn lại.
 *
 * ---------------------------------------------------------------------------
 * `is_quota_category` — CỜ CẤU HÌNH, ENGINE KHÔNG ĐỌC
 * ---------------------------------------------------------------------------
 * `CashbackCalculator` không biết cột này. Cờ chỉ quyết định rule nào được TÍNH
 * vào quota: nó xuất hiện trong kết quả, và cashback của nó mới được cộng vào
 * `tier_cashback_used`. Rule không tick, và fallback
 * ("📦 Các danh mục còn lại"), không bao giờ vào quota. Lọc fallback ở TẦNG QUERY
 * (`scopeQuotaCategory()`) chứ không chỉ dựa vào cột, nên một rule fallback bị
 * bẩn cờ cũng không lọt.
 *
 * ---------------------------------------------------------------------------
 * SỐ QUERY
 * ---------------------------------------------------------------------------
 * Mọi truy vấn đều gom theo LÔ cho toàn bộ thẻ: version, bậc, rule theo bậc,
 * membership combo, và hai phép cộng theo (kỳ × danh mục). Chỉ có bước resolve
 * version của thẻ CHƯA có kỳ là mỗi thẻ một query — và thẻ có kỳ (trường hợp
 * của trang Tổng quan) thì không tốn query này. Số query là O(số thẻ chưa có kỳ),
 * không phải O(số giao dịch).
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
     * @return array<int, array<string, mixed>>
     */
    public function forCards(Collection $cards, Collection $periods): array
    {
        if ($cards->isEmpty()) {
            return [];
        }

        $periodByCard = $periods->keyBy('user_card_id');

        $versions = $this->resolveVersions($cards, $periodByCard);
        $tiersByVersion = $this->tiersByVersionId($versions);
        $tiersByCard = $this->tiersByCard($cards, $versions, $tiersByVersion);

        $tierIds = collect($tiersByCard)
            ->filter()
            ->map(fn (PolicyTier $tier): int => (int) $tier->id)
            ->unique()
            ->values()
            ->all();

        $rulesByTier = $this->rulesByTier($tierIds);
        $membersByCombo = $this->comboMembers($rulesByTier);
        $totals = $this->periodTotals($periods->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $result = [];

        foreach ($cards as $card) {
            $cardId = (int) $card->id;
            $tier = $tiersByCard[$cardId] ?? null;

            $result[$cardId] = $this->quotaOfCard(
                $periodByCard->get($cardId),
                $tier,
                $tier === null ? collect() : ($rulesByTier[(int) $tier->id] ?? collect()),
                $membersByCombo,
                $totals,
            );
        }

        return $result;
    }

    // =====================================================================
    // Quota của MỘT thẻ
    // =====================================================================

    /**
     * @param  Collection<int, PolicyTierCategory>  $rules  rule đang bật của bậc đích
     * @param  array<int, array<int, int>>  $membersByCombo
     * @param  array{cashback: array<string, string>, spend: array<string, string>}  $totals
     * @return array<string, mixed>
     */
    private function quotaOfCard(
        ?StatementPeriod $period,
        ?PolicyTier $tier,
        Collection $rules,
        array $membersByCombo,
        array $totals,
    ): array {
        $periodId = $period === null ? null : (int) $period->id;

        $quotaRules = $rules
            ->filter(fn (PolicyTierCategory $rule): bool => $rule->isQuotaCategory())
            ->values();

        // Trần CHUNG toàn kỳ của bậc đích. NULL = bậc không đặt trần, xem `Decimal`
        // và ghi chú dưới đây.
        $tierMax = $tier === null || $tier->max_cashback_per_period === null
            ? null
            : Decimal::money($tier->max_cashback_per_period);

        // "Đã dùng" của tầng chung = HỢP các danh mục mà rule quota bao phủ, mỗi
        // giao dịch tính TRÙNG MỘT LẦN. Phải gộp danh mục rồi mới cộng, không cộng
        // từng rule: một danh mục có thể nằm trong nhiều rule quota (nhiều dải rate
        // cùng tick, hoặc vừa là rule danh mục vừa thuộc combo), và cộng vòng lặp
        // sẽ tính một giao dịch nhiều lần — làm sai hạn mức chung.
        $quotaCategoryIds = [];

        foreach ($quotaRules as $rule) {
            foreach ($this->categoryIdsOf($rule, $membersByCombo) as $categoryId) {
                $quotaCategoryIds[$categoryId] = true;
            }
        }

        $tierUsed = $this->sumOf(
            $totals['cashback'],
            $periodId,
            array_map('intval', array_keys($quotaCategoryIds)),
        );

        $tierRemaining = $tierMax === null
            ? null
            : Decimal::clampZero(Decimal::subtract($tierMax, $tierUsed));

        $ruleRows = [];

        foreach ($quotaRules as $rule) {
            $ruleRows[] = $this->quotaOfRule(
                $rule,
                $rules,
                $membersByCombo,
                $totals,
                $periodId,
                $tierMax,
                $tierUsed,
                $tierRemaining,
            );
        }

        return [
            'tier_id' => $tier === null ? null : (int) $tier->id,
            'tier_name' => $tier?->name,

            // Tầng CHUNG.
            'tier_cashback_max' => $tierMax,
            'tier_cashback_used' => $tierUsed,
            'tier_cashback_remaining' => $tierRemaining,
            'has_tier_cashback_max' => $tierMax !== null,
            'is_exhausted' => $tierRemaining !== null && Decimal::compare($tierRemaining, '0.00') <= 0,

            // Tầng RIÊNG, từng rule.
            'rules' => $ruleRows,
            'has_quota_rules' => $ruleRows !== [],

            // -------------------------------------------------------------------------
            // ALIAS GIỮ TƯƠNG THÍCH cho giao diện Tổng quan (Phase 3 chưa được sửa).
            // Dòng "· còn X đ" của thẻ đọc `has_limit` + `remaining`, nên hai khoá này
            // PHẢI trỏ về TẦNG CHUNG. Chúng KHÔNG phải tầng riêng của rule — đừng
            // đọc chúng như `category_cashback_remaining`; hãy đọc `rules[]`.
            // -------------------------------------------------------------------------
            'limit' => $tierMax,
            'used' => $tierUsed,
            'remaining' => $tierRemaining,
            'has_limit' => $tierMax !== null,
        ];
    }

    /**
     * Quota của MỘT rule danh mục / combo được tick quota.
     *
     * @param  Collection<int, PolicyTierCategory>  $siblingRules  mọi rule đang bật của bậc đích
     * @param  array<int, array<int, int>>  $membersByCombo
     * @param  array{cashback: array<string, string>, spend: array<string, string>}  $totals
     * @return array<string, mixed>
     */
    private function quotaOfRule(
        PolicyTierCategory $rule,
        Collection $siblingRules,
        array $membersByCombo,
        array $totals,
        ?int $periodId,
        ?string $tierMax,
        string $tierUsed,
        ?string $tierRemaining,
    ): array {
        $categoryIds = $this->categoryIdsOf($rule, $membersByCombo);

        $used = $this->sumOf($totals['cashback'], $periodId, $categoryIds);
        $scopeSpend = Decimal::clampZero($this->sumOf($totals['spend'], $periodId, $categoryIds));

        // -----------------------------------------------------------------
        // Trần RIÊNG của rule.
        // -----------------------------------------------------------------
        // NULL = KHÔNG CÓ TRẦN RIÊNG (đã chốt), tức rule này chỉ bị chặn bởi ngân
        // sách CHUNG của bậc — không phải "chưa cấu hình xong". Nên hai tầng tách
        // bạch hoàn toàn:
        //
        //   có trần riêng  → available = MIN(trần riêng còn lại, chung còn lại)
        //   không trần riêng → available = chung còn lại
        //
        // `cashback_max`/`cashback_remaining` vẫn là `null` — nhưng lần này null là
        // MỘT PHÁT BIỂU SỰ THẬT ("rule này không có trần riêng"), không phải một
        // ô trống chưa biết. Số liệu user hành động được thì luôn đầy đủ.
        $max = $rule->max_cashback_per_category_per_period === null
            ? null
            : Decimal::money($rule->max_cashback_per_category_per_period);

        $remaining = $max === null
            ? null
            : Decimal::clampZero(Decimal::subtract($max, $used));

        // Suy ra số tiền chi thêm được: bị CHẶN bởi cả trần riêng lẫn ngân sách
        // chung còn lại. Không phân bổ ngân sách chung tuần tự cho các danh mục.
        //
        // `tierRemaining === null` là trường hợp KHÁC: bậc không đặt trần chung, tức
        // không có ngân sách chung nào để vi phạm — coi như vô hạn, giống hệt cách
        // `CashbackCalculator` xử lý `max_cashback_per_period = NULL`. Ở đây null
        // là "không có trần", nên vẫn ra được con số; chỉ khi rule VỪA không có
        // trần riêng VỪA nằm ở bậc không có trần chung thì mới không có trần nào
        // để tính ⇒ `null` là đúng.
        $available = match (true) {
            $tierRemaining === null => $max === null ? null : $remaining,
            $remaining === null => $tierRemaining,
            default => Decimal::min($remaining, $tierRemaining),
        };

        $walk = $available === null
            ? ['spend' => null, 'reachable' => null]
            : $this->walkBands($this->bandChain($siblingRules, $rule), $scopeSpend, $available);

        return [
            'rule_id' => (int) $rule->id,
            'target_scope' => $rule->isComboSpecific() ? 'combo' : 'category',
            'name' => $rule->name,

            'category_id' => $rule->category_id === null ? null : (int) $rule->category_id,
            'category_name' => $rule->category?->name,
            'combo_id' => $rule->combo_id === null ? null : (int) $rule->combo_id,
            'combo_name' => $rule->combo?->name,
            'scope_category_ids' => $categoryIds,

            // Dải rate của chính rule này.
            'cashback_percent' => Decimal::money($rule->cashback_percent),
            'spend_from' => Decimal::money($rule->spend_from),
            'spend_to' => $rule->spend_to === null ? null : Decimal::money($rule->spend_to),

            // Mọi dải của CÙNG phạm vi, theo thứ tự tăng dần — đầu vào của phép
            // walk, để giao diện có thể giải thích con số ước lượng.
            'bands' => $this->bandChain($siblingRules, $rule)
                ->map(fn (PolicyTierCategory $band): array => [
                    'rule_id' => (int) $band->id,
                    'cashback_percent' => Decimal::money($band->cashback_percent),
                    'spend_from' => Decimal::money($band->spend_from),
                    'spend_to' => $band->spend_to === null ? null : Decimal::money($band->spend_to),
                ])
                ->all(),
            'scope_spend' => $scopeSpend,

            // Tầng RIÊNG.
            'cashback_max' => $max,
            'has_cashback_max' => $max !== null,
            'cashback_used' => $used,
            'cashback_remaining' => $remaining,

            // Tầng CHUNG, lặp lại ở từng rule để không phải tra cứu chéo.
            'tier_cashback_max' => $tierMax,
            'tier_cashback_used' => $tierUsed,
            'tier_cashback_remaining' => $tierRemaining,

            // MIN của hai tầng trên.
            'cashback_available_for_rule' => $available,
            'spend_remaining_estimate' => $walk['spend'],
            'is_exhausted' => $available !== null && Decimal::compare($available, '0.00') <= 0,

            // `false` khi đi hết dải mà vẫn chưa tạo đủ cashback: cấu hình có dải
            // đóng kín nên không thể chi thêm để đạt max — con số ước lượng khi
            // đó là SỐ TIỀN ĐÃ BỊ GIỚI HẠN, không phải số tiền cần chi.
            'spend_estimate_is_reachable' => $walk['reachable'],
        ];
    }

    // =====================================================================
    // Suy ra số tiền chi thêm — WALK DẢI RATE
    // =====================================================================

    /**
     * Chi thêm bao nhiêu thì sinh ra đủ `$targetCashback` hoàn tiền?
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO PHẢI WALK TỪNG DẢI
     * ---------------------------------------------------------------------------
     * Một danh mục có nhiều rule, mỗi rule một khoảng `[spend_from, spend_to)` với
     * tỷ lệ riêng. Chi càng nhiều thì dải áp dụng càng dịch lên, nên "chia một
     * lần cho tỷ lệ hiện tại" SAI ngay ở dải thứ hai. Ví dụ còn 250.000 hoàn
     * tiền, dải 0–5tr @5% thì 250.000 nằm trọn trong dải đầu (5tr × 5% = 250.000)
     * ⇒ cần chi 5.000.000; nếu dải đầu chỉ còn 100.000 thì phần dư 150.000 phải
     * đi tiếp vào dải kế tiếp với TỶ LỆ CỦA DẢI ĐÓ.
     *
     * ---------------------------------------------------------------------------
     * BẮT ĐẦU TỪ ĐÂU
     * ---------------------------------------------------------------------------
     * Walk khởi đi tại VỊ TRÍ CHI TIÊU HIỆN TẠI của phạm vi, và mỗi dải chỉ tính
     * phần `[max(spend_from, vị trí hiện tại), spend_to)` — tiền đã chi không
     * sinh thêm hoàn tiền. Vị trí hiện tại là chi tiêu THỰC TẾ của phạm vi, được
     * dùng để định vị dải; nó không bao giờ chọn bậc (xem docblock lớp).
     *
     * ---------------------------------------------------------------------------
     * CÁC CA BIÊN
     * ---------------------------------------------------------------------------
     *   - `spend_to` NULL ⇒ dải mở vô hạn, hết cashback ngay trong dải đó.
     *   - Tỷ lệ 0 ⇒ dải không sinh hoàn tiền, chỉ bước qua. Nếu dải mở vô hạn mà
     *     tỷ lệ 0 thì không bao giờ tạo được hoàn tiền ⇒ `reachable = false`.
     *   - Dải đã đóng kín trước vị trí hiện tại ⇒ bỏ qua, không tính lại.
     *   - Dải bị hở (dải trước kết thúc trước `spend_from` của dải sau) ⇒ khoảng
     *     trống không sinh hoàn tiền, đúng như engine (không rule nào khớp).
     *   - Hết danh sách dải mà vẫn thiếu ⇒ `reachable = false`.
     *
     * @param  Collection<int, PolicyTierCategory>  $bands
     * @return array{spend: string, reachable: bool}
     */
    private function walkBands(Collection $bands, string $currentSpend, string $targetCashback): array
    {
        if (Decimal::compare($targetCashback, '0.00') <= 0) {
            return ['spend' => '0.00', 'reachable' => true];
        }

        $position = Decimal::clampZero($currentSpend);
        $remaining = $targetCashback;
        $spent = '0.00';

        foreach ($bands as $band) {
            if (Decimal::compare($remaining, '0.00') <= 0) {
                break;
            }

            $rate = Decimal::money($band->cashback_percent);
            $from = Decimal::clampZero($band->spend_from);
            $to = $band->spend_to === null ? null : Decimal::money($band->spend_to);

            $start = Decimal::max($from, $position);

            if ($to !== null && Decimal::compare($to, $start) <= 0) {
                continue;
            }

            if (Decimal::compare($rate, '0.00') <= 0) {
                if ($to === null) {
                    return ['spend' => $spent, 'reachable' => false];
                }

                $spent = Decimal::add($spent, Decimal::subtract($to, $start));
                $position = $to;

                continue;
            }

            $cashbackToEnd = $to === null
                ? null
                : Decimal::cashbackForSpend(Decimal::subtract($to, $start), $rate);

            if ($cashbackToEnd === null || Decimal::compare($remaining, $cashbackToEnd) <= 0) {
                $spent = Decimal::add($spent, Decimal::spendForCashback($remaining, $rate));
                $remaining = '0.00';

                break;
            }

            $spent = Decimal::add($spent, Decimal::subtract($to, $start));
            $remaining = Decimal::subtract($remaining, $cashbackToEnd);
            $position = $to;
        }

        return ['spend' => $spent, 'reachable' => Decimal::compare($remaining, '0.00') <= 0];
    }

    /**
     * Mọi dải rate của CÙNG phạm vi với rule này, tăng dần theo ngưỡng.
     *
     * Cùng phạm vi = cùng `category_id` (rule danh mục) hoặc cùng `combo_id`
     * (rule combo). Danh mục nằm trong combo KHÔNG phải dải của rule danh mục đó
     * vì engine tính ngưỡng combo trên TỔNG các danh mục thành viên.
     *
     * @param  Collection<int, PolicyTierCategory>  $rules
     * @return Collection<int, PolicyTierCategory>
     */
    private function bandChain(Collection $rules, PolicyTierCategory $rule): Collection
    {
        return $rules
            ->filter(function (PolicyTierCategory $other) use ($rule): bool {
                if ($rule->isComboSpecific()) {
                    return $other->isComboSpecific() && (int) $other->combo_id === (int) $rule->combo_id;
                }

                return $other->category_id !== null
                    && $other->combo_id === null
                    && (int) $other->category_id === (int) $rule->category_id;
            })
            ->sortBy(fn (PolicyTierCategory $band): array => [
                Decimal::money($band->spend_from),
                (int) $band->id,
            ])
            ->values();
    }

    // =====================================================================
    // Truy vấn
    // =====================================================================

    /**
     * Policy version của từng thẻ.
     *
     * Ưu tiên `policy_id` mà engine đã gắn vào kỳ hiện tại — đó đúng là version
     * đang được dùng để tính kỳ này. Thẻ chưa có kỳ trong DB thì resolve theo hôm
     * nay (`resolvePolicyVersion`), vẫn đọc mà không tạo gì.
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

            $versions[$cardId] = $this->tiers->resolvePolicyVersion($card, $today, strictCurrentPolicy: true);
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
     * Bậc ĐÍCH của từng thẻ — theo `desired_spend`, không theo chi tiêu thực tế.
     *
     * @param  Collection<int, UserCard>  $cards
     * @param  array<int, PolicyVersion|null>  $versions
     * @param  array<int, Collection<int, PolicyTier>>  $tiersByVersion
     * @return array<int, PolicyTier|null>
     */
    private function tiersByCard(Collection $cards, array $versions, array $tiersByVersion): array
    {
        $tiersByCard = [];

        foreach ($cards as $card) {
            $cardId = (int) $card->id;
            $version = $versions[$cardId] ?? null;

            // `desired_spend` NULL ⇒ 0: chưa đặt mục tiêu thì tra bậc phủ 0.
            $desiredSpend = Decimal::money($card->desired_spend);

            $tiersByCard[$cardId] = $version === null
                ? null
                : $this->tiers->resolveTierFromTiers(
                    $tiersByVersion[(int) $version->id] ?? collect(),
                    // Ép `float` ở ĐÂY là hợp đồng của engine: `min_total_spend`/
                    // `max_total_spend` vốn là cột float và `resolveTierFromTiers()`
                    // so sánh trên float. Mọi phép tính TIỀN của quota vẫn chạy qua
                    // `Decimal` (bcmath) — chỉ có bước TRA CỨ NGƯỠNG bậc đi qua float,
                    // và nó không cộng/trừ số tiền nào.
                    (float) $desiredSpend,
                );
        }

        return $tiersByCard;
    }

    /**
     * Rule đang BẬT của mọi bậc đích, gom theo LÔ.
     *
     * Loại fallback ở tầng query (`categorySpecific()`): fallback không phải một
     * mục tiêu chi tiêu nên không thể là quota, và việc loại ở đây giữ cho dải rate
     * của phép walk không bị một dải fallback chen vào.
     *
     * Không dùng `TierResolverService::rulesForTier()`: nó hydrate cho
     * `CashbackCalculator` và CỐ Ý không mang `is_quota_category` (thêm cột đó vào
     * đó sẽ chạm vào đầu vào của engine cashback — ngoài phạm vi Phase 2).
     *
     * @param  array<int, int>  $tierIds
     * @return array<int, Collection<int, PolicyTierCategory>>
     */
    private function rulesByTier(array $tierIds): array
    {
        if ($tierIds === []) {
            return [];
        }

        return PolicyTierCategory::query()
            ->whereIn('tier_id', $tierIds)
            ->enabled()
            ->categorySpecific()
            ->with(['category', 'combo'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('tier_id')
            ->map(fn (Collection $rules): Collection => $rules->values())
            ->all();
    }

    /**
     * Membership combo HIỆN TẠI của các combo có rule trong các bậc đích.
     *
     * Một query cho mọi combo. Đọc thẳng `CategoryComboItem` như
     * `TierResolverService::hydrate()` vì đó là nguồn membership mà engine dùng.
     *
     * @param  array<int, Collection<int, PolicyTierCategory>>  $rulesByTier
     * @return array<int, array<int, int>>
     */
    private function comboMembers(array $rulesByTier): array
    {
        $comboIds = collect($rulesByTier)
            ->flatMap(fn (Collection $rules): Collection => $rules->pluck('combo_id'))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($comboIds->isEmpty()) {
            return [];
        }

        return CategoryComboItem::query()
            ->whereIn('combo_id', $comboIds->all())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['combo_id', 'category_id'])
            ->groupBy('combo_id')
            ->map(fn (Collection $items): array => $items
                ->map(fn (CategoryComboItem $item): int => (int) $item->category_id)
                ->all())
            ->all();
    }

    /**
     * Tổng theo (kỳ × danh mục) cho MỌI kỳ hiện tại, gom trong MỘT query.
     *
     * ---------------------------------------------------------------------------
     * HAI CON SỐ, HAI Ý NGHĨA
     * ---------------------------------------------------------------------------
     *   - `cashback`: SUM(`cashback_amount_snapshot`) — tiền engine ĐÃ ghi. Cột này
     *     là sự thật lịch sử, ta chỉ cộng lại, không tính lại.
     *   - `spend`   : SUM(`amount`) — chi tiêu thực tế, dùng làm VỊ TRÍ HIỆN TẠI
     *     khi walk dải rate. Giao dịch âm (hoàn tiền trả lại) tự bù trừ như
     *     engine đang làm với tổng chi tiêu.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO LOẠI FALLBACK Ở TẦNG NÀY
     * ---------------------------------------------------------------------------
     * Một giao dịch rơi vào fallback VẪN có `category_id` thật và vẫn sinh hoàn
     * tiền. Nếu chỉ lọc theo danh mục thì nó sẽ lọt vào `tier_cashback_used`
     * của một rule quota có cùng danh mục ở bậc đích — trái với hợp đồng "không
     * tính fallback". Nên `cashback` chỉ cộng giao dịch đã gắn một rule KHÔNG
     * phải fallback; giao dịch chưa có snapshot (chưa có policy, không eligible)
     * có tiền bằng 0 nên không ảnh hưởng.
     *
     * @param  array<int, int>  $periodIds
     * @return array{cashback: array<string, string>, spend: array<string, string>}
     */
    private function periodTotals(array $periodIds): array
    {
        if ($periodIds === []) {
            return ['cashback' => [], 'spend' => []];
        }

        $transactions = (new Transaction)->getTable();
        $rules = (new PolicyTierCategory)->getTable();

        $rows = Transaction::query()
            ->leftJoin($rules.' as cc_rule', 'cc_rule.id', '=', $transactions.'.policy_tier_category_id')
            ->whereIn($transactions.'.statement_period_id', $periodIds)
            ->whereNotNull($transactions.'.category_id')
            ->groupBy($transactions.'.statement_period_id', $transactions.'.category_id')
            // MỘT lần gọi `select()` với mảng: gọi hai lần sẽ khiến lần sau GHI ĐÈ
            // lần trước, và `statement_period_id` biến mất khỏi danh sách cột ⇒ mọi
            // khoá tổng hoá thành `0:categoryId` và không khớp giao dịch nào.
            ->select([
                $transactions.'.statement_period_id',
                $transactions.'.category_id',
            ])
            ->selectRaw('SUM('.$transactions.'.amount) as spend')
            ->selectRaw(
                "SUM(CASE WHEN {$transactions}.policy_tier_category_id IS NOT NULL"
                ." AND COALESCE(cc_rule.scope_type, '".PolicyTierCategory::SCOPE_CATEGORY."') <> '"
                .PolicyTierCategory::SCOPE_OTHER."' THEN {$transactions}.cashback_amount_snapshot ELSE 0 END) as cashback"
            )
            ->get();

        $cashback = [];
        $spend = [];

        foreach ($rows as $row) {
            $key = $this->periodCategoryKey((int) $row->statement_period_id, (int) $row->category_id);

            $cashback[$key] = Decimal::money($row->cashback);
            $spend[$key] = Decimal::money($row->spend);
        }

        return ['cashback' => $cashback, 'spend' => $spend];
    }

    // =====================================================================
    // Tiện ích
    // =====================================================================

    /**
     * Danh mục mà rule này bao phủ.
     *
     * Rule danh mục: chính danh mục đó. Rule combo: membership HIỆN TẠI của combo.
     *
     * @param  array<int, array<int, int>>  $membersByCombo
     * @return array<int, int>
     */
    private function categoryIdsOf(PolicyTierCategory $rule, array $membersByCombo): array
    {
        if ($rule->isComboSpecific()) {
            return $membersByCombo[(int) $rule->combo_id] ?? [];
        }

        return $rule->category_id === null ? [] : [(int) $rule->category_id];
    }

    /**
     * Cộng một bảng tổng sẵn gom trên các danh mục của phạm vi.
     *
     * @param  array<string, string>  $table
     * @param  array<int, int>  $categoryIds
     */
    private function sumOf(array $table, ?int $periodId, array $categoryIds): string
    {
        if ($periodId === null || $categoryIds === []) {
            return '0.00';
        }

        $total = '0.00';

        foreach ($categoryIds as $categoryId) {
            $total = Decimal::add($total, $table[$this->periodCategoryKey($periodId, $categoryId)] ?? '0.00');
        }

        return $total;
    }

    private function periodCategoryKey(int $periodId, int $categoryId): string
    {
        return $periodId.':'.$categoryId;
    }
}
