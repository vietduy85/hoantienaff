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
 *   - `cashback_available_for_rule` = MIN(trần riêng còn lại, chung còn lại
 *     SAU KHI TRỪ phần dự kiến của tiền đã chi — xem mục dưới).
 *
 * Hai tầng dùng hai cột khác nhau nên KHÔNG chia trần chung cho các danh mục:
 * mỗi danh mục báo riêng khả năng của NÓ, dựa trên ngân sách chung còn lại.
 *
 * ---------------------------------------------------------------------------
 * SỐ TIỀN ĐÃ CHI ĐÃ ĂN VÀO PHÒNG CHƯA
 * ---------------------------------------------------------------------------
 * "Có thể chi thêm" là câu hỏi "còn bao nhiêu TIỀN chi được trước khi HẾT
 * phòng hoàn tiền", nên phải trừ cả cashback mà số tiền ĐÃ CHI tạo ra ở tỷ lệ
 * của bậc đích — không chỉ cashback engine đã trả (`cashback_used`).
 *
 * Lý do: bậc đích theo `desired_spend`, còn engine chạy bậc theo CHI TIÊU THỰC
 * TẾ. Người dùng mới đặt mục tiêu cao thì engine trả 0đ cho giao dịch đã có, và
 * `cashback_used` = 0 dù tiền đã chi vẫn chiếm chỗ trong trần của bậc đích.
 *
 *   3.655.000 × 10% = 365.500đ đã ăn vào trần 400.000đ ⇒ còn 34.500đ hoàn
 *   tiền ⇒ còn chi thêm 34.500/10% = 345.000đ (KHÔNG phải 400.000/10%).
 *
 * Phần bị trừ là `MAX(0, dự kiến − cashback_used)`: trừ vô hạn sẽ double-count
 * cùng một khoản khi engine đã trả đúng bằng tỷ lệ của bậc đích — lúc đó
 * `cashback_used` đã nằm trong `cashback_available_for_rule` và kết quả y hệt
 * trước. Rate lấy của CHÍNH rule này trong bậc đích, không phải max rate của bậc.
 *
 * ---------------------------------------------------------------------------
 * DỰ KIẾN CỦA TIỀN ĐÃ CHI CŨNG ĂN VÀO TẦNG CHUNG
 * ---------------------------------------------------------------------------
 * `cashback_unaccounted_from_spend` của từng rule ở trên chỉ trừ vào phần PHÒNG
 * RIÊNG của chính nó. Nhưng khoản tiền đó cũng sẽ ăn vào trần CHUNG của bậc
 * đích — và khi engine chưa kịp ghi snapshot (giao dịch ineligible vì tổng chi
 * tiêu dưới `min_total_spend`, hoặc policy gắn vào thẻ SAU khi giao dịch đã có),
 * `tier_cashback_used` = 0 dù tiền đã chi. Bấy giờ tầng chung tự khai còn trọn
 * `max`, các dòng quota tha hồ mời "Có thể chi thêm" — trong khi dòng "Cashback
 * dự kiến" ngay phía trên đã in đủ `expected / max` = đã hết. Hai chỗ nói hai
 * sự thật khác nhau.
 *
 * Ví dụ thật (LPBank, mục tiêu 8tr ⇒ bậc đích cap 800.000đ, nhưng engine chạy
 * bậc dưới với min-spend 8tr nên MỌI snapshot = 0): Bảo hiểm 3.900.000 × 15% =
 * 585.000 (bị trần riêng 400.000 chặn), Y tế 3.042.550 × 15% = 456.382,50.
 * Tổng dự kiến 856.382,50 > 800.000 ⇒ bậc đã hết từ lâu, nên cả ba dòng phải
 * `· HẾT QUOTA` với đúng tử số của từng dòng (400k, 456k, 0) — không một dòng
 * được mời chi thêm.
 *
 * Vì vậy tầng chung có thêm MỘT LỚP DỰ KIẾN:
 *
 *   `tier_cashback_projected`     = Σ theo SCOPE của `MAX(0, min(dự kiến,
 *                                    trần riêng) − snapshot)` — dedupe bằng MAX
 *                                    khi nhiều dải rate tick quota cùng một
 *                                    danh mục/combo, cộng dồn sẽ tính một khoản
 *                                    chi nhiều lần.
 *   `tier_cashback_remaining`     = trần chung − snapshot − dự kiến (clamp ≥ 0).
 *   `is_exhausted` (tầng chung)   = đọc con số remaining SAU dự kiến này.
 *
 * `tier_cashback_used` KHÔNG đổi: nó vẫn là tiền engine đã trả — nguồn sự thật
 * lịch sử. Dự kiến chỉ là phần "sắp bị ăn" mà snapshot chưa thấy, tính trên số
 * tiền đã chi thật và rate của BẬC ĐÍCH, tuyệt đối không qua số hiển thị.
 *
 * Tự nó, từng rule nhận `sharedPool = remaining_sau_dự_kiến + projection của
 * CHÍNH scope nó` — cộng lại phần của chính mình vì `room` đã tự trừ
 * `cashback_unaccounted_from_spend` rồi. Nhờ đó scope chưa chi (projection = 0)
 * vẫn thấy trọn phần chưa-dự-án của bậc, còn scope đã chi đủ thì pool về 0.
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
     * @param  Collection<int, StatementPeriod>  $periods  kỳ hiện tại (có thể rỗng đối với thẻ không có record khớp)
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $boundsByCard  ranh giới [start, end] theo anchor từng thẻ
     * @return array<int, array<string, mixed>>
     */
    public function forCards(Collection $cards, Collection $periods, array $boundsByCard = []): array
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
        $totals = $this->periodTotals(
            $periods->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            $boundsByCard,
            collect($tiersByCard)
                ->filter()
                ->mapWithKeys(fn (PolicyTier $tier, int $cardId): array => [$cardId => (int) $tier->id])
                ->all(),
        );

        $result = [];

        foreach ($cards as $card) {
            $cardId = (int) $card->id;
            $tier = $tiersByCard[$cardId] ?? null;

            $result[$cardId] = $this->quotaOfCard(
                $tier,
                $tier === null ? collect() : ($rulesByTier[(int) $tier->id] ?? collect()),
                $membersByCombo,
                $totals,
                $cardId,
            );
        }

        return $result;
    }

    // =====================================================================
    // Quota của MỘT thẻ
    // =====================================================================

    /** Thẻ đang được dựng quota — khoá để tra `$totals` gom theo THẺ (không theo kỳ). */
    private ?int $contextCardId = null;

    private function getContextCardId(): ?int
    {
        return $this->contextCardId;
    }

    /**
     * @param  Collection<int, PolicyTierCategory>  $rules  rule đang bật của bậc đích
     * @param  array<int, array<int, int>>  $membersByCombo
     * @param  array{cashback: array<string, string>, spend: array<string, string>, cashback_target: array<string, string>}  $totals
     * @return array<string, mixed>
     */
    private function quotaOfCard(
        ?PolicyTier $tier,
        Collection $rules,
        array $membersByCombo,
        array $totals,
        ?int $cardId = null,
    ): array {
        $this->contextCardId = $cardId;

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
            $this->getContextCardId(),
            array_map('intval', array_keys($quotaCategoryIds)),
        );

        $tierRemaining = $tierMax === null
            ? null
            : Decimal::clampZero(Decimal::subtract($tierMax, $tierUsed));

        // Dự kiến của TIỀN ĐÃ CHI — phần snapshot chưa phản ánh — cũng phải giữ
        // chỗ ở TẦNG CHUNG, không chỉ ở từng dòng (xem docblock lớp: ví dụ
        // LPBank). `tier_cashback_used` giữ nguyên nghĩa lịch sử; hai con số
        // mới bên dưới là phần "sắp bị ăn" và phần chung còn lại sau đó.
        $projectionByScope = $this->projectionByScope($quotaRules, $membersByCombo, $totals);

        $tierProjected = '0.00';

        foreach ($projectionByScope as $projection) {
            $tierProjected = Decimal::add($tierProjected, $projection);
        }

        $tierRemainingAfterProjection = $tierMax === null
            ? null
            : Decimal::clampZero(Decimal::subtract($tierRemaining, $tierProjected));

        $ruleRows = [];

        foreach ($quotaRules as $rule) {
            $ruleRows[] = $this->quotaOfRule(
                $rule,
                $rules,
                $membersByCombo,
                $totals,
                $tierMax,
                $tierUsed,
                $tierRemainingAfterProjection,
                $tierProjected,
                $projectionByScope[$this->scopeKeyOf($rule)] ?? '0.00',
            );
        }

        $this->contextCardId = null;

        return [
            'tier_id' => $tier === null ? null : (int) $tier->id,
            'tier_name' => $tier?->name,

            // Tầng CHUNG.
            'tier_cashback_max' => $tierMax,
            'tier_cashback_used' => $tierUsed,
            // Phần dự kiến của TIỀN ĐÃ CHI mà snapshot chưa ghi — chỉ để giải
            // thích khoảng cách giữa `used` và `remaining`.
            'tier_cashback_projected' => $tierProjected,
            // TRỪ phần dự kiến trên: đây là phần chung còn lại THẬT SỰ cho chi
            // tiêu TƯƠI LAI, và `is_exhausted` đọc đúng nó.
            'tier_cashback_remaining' => $tierRemainingAfterProjection,
            'has_tier_cashback_max' => $tierMax !== null,
            'is_exhausted' => $tierRemainingAfterProjection !== null && Decimal::compare($tierRemainingAfterProjection, '0.00') <= 0,

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
            'remaining' => $tierRemainingAfterProjection,
            'has_limit' => $tierMax !== null,
        ];
    }

    /**
     * Quota của MỘT rule danh mục / combo được tick quota.
     *
     * @param  Collection<int, PolicyTierCategory>  $siblingRules  mọi rule đang bật của bậc đích
     * @param  array<int, array<int, int>>  $membersByCombo
     * @param  array{cashback: array<string, string>, spend: array<string, string>, cashback_target: array<string, string>}  $totals
     * @param  string  $tierRemainingAfterProjection  tầng chung SAU khi trừ dự kiến của mọi scope
     * @param  string  $tierProjected  tổng dự kiến của mọi scope (chỉ để lặp lại ở payload)
     * @param  string  $scopeProjection  dự kiến của CHÍNH scope rule này (phần sẽ cộng lại vào pool)
     * @return array<string, mixed>
     */
    private function quotaOfRule(
        PolicyTierCategory $rule,
        Collection $siblingRules,
        array $membersByCombo,
        array $totals,
        ?string $tierMax,
        string $tierUsed,
        ?string $tierRemainingAfterProjection,
        string $tierProjected,
        string $scopeProjection,
    ): array {
        $categoryIds = $this->categoryIdsOf($rule, $membersByCombo);

        $used = $this->sumOf($totals['cashback'], $this->getContextCardId(), $categoryIds);
        // Phần snapshot ghi bởi rule thuộc ĐÚNG bậc đích — xem `periodTotals()`.
        // Dùng để biết số ĐÃ LƯU có phải chân lý của bậc đích hay của bậc khác.
        $usedAtTargetTier = $this->sumOf($totals['cashback_target'], $this->getContextCardId(), $categoryIds);
        $scopeSpend = Decimal::clampZero($this->sumOf($totals['spend'], $this->getContextCardId(), $categoryIds));

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
        // `sharedPool === null` là trường hợp KHÁC: bậc không đặt trần chung, tức
        // không có ngân sách chung nào để vi phạm — coi như vô hạn, giống hệt cách
        // `CashbackCalculator` xử lý `max_cashback_per_period = NULL`. Ở đây null
        // là "không có trần", nên vẫn ra được con số; chỉ khi rule VỪA không có
        // trần riêng VỪA nằm ở bậc không có trần chung thì mới không có trần nào
        // để tính ⇒ `null` là đúng.
        //
        // `sharedPool` = tầng chung SAU dự kiến của MỌI scope, CỘNG lại phần dự
        // kiến của CHÍNH scope này: `room` phía dưới đã tự trừ
        // `cashback_unaccounted_from_spend` của rule, nên nếu pool không cộng
        // lại phần của chính nó thì scope này sẽ tự trừ hai lần. Scope khác đã
        // chi (projection > 0) làm pool bé lại — đúng: tiền của họ cũng ăn vào
        // cái chung.
        $sharedPool = $tierRemainingAfterProjection === null
            ? null
            : Decimal::clampZero(Decimal::add($tierRemainingAfterProjection, $scopeProjection));

        $available = match (true) {
            $sharedPool === null => $max === null ? null : $remaining,
            $remaining === null => $sharedPool,
            default => Decimal::min($remaining, $sharedPool),
        };

        // -----------------------------------------------------------------
        // PHÒNG CÒN LẠI THẬT SỰ — trừ cashback của SỐ TIỀN ĐÃ CHI
        // -----------------------------------------------------------------
        // `cashback_used` chỉ ghi những giao dịch engine ĐÃ trả cashback. Nhưng
        // quota trình bày theo BẬC ĐÍCH (`desired_spend`): người dùng mới đặt
        // mục tiêu cao, engine vẫn chạy bậc thấp và trả 0đ cho các giao dịch đã
        // có. Số tiền đó ĐÃ chi làm hao ngân sách của bậc đích, nhưng snapshot
        // không thấy mặt nào.
        //
        // Ví dụ: chi 3.655.000, bậc đích 10% với trần 400.000. `used` = 0 nên
        // `available` = 400.000, và nếu chia thẳng 400.000/10% ra 4.000.000 thì
        // báo user còn chi được cả 4tr — trong khi 365.500 hoàn tiền đã ăn vào
        // trần rồi. Phải còn 34.500 hoàn tiền ⇒ 345.000 chi thêm mới đúng.
        //
        // Rate lấy của CHÍNH rule này trong bậc đích (`cashback_percent`), không
        // lấy max rate của bậc và không lấy rate của bậc engine đang chạy: mỗi
        // quota rule một tỷ lệ riêng, nên phải so từng rule.
        $rate = Decimal::money($rule->cashback_percent);

        $expectedFromSpend = Decimal::isPositive($rate)
            ? Decimal::cashbackForSpend($scopeSpend, $rate)
            : '0.00';

        // Engine đã THẬT SỰ trả cashback cho phạm vi này Ở ĐÚNG BẬC ĐÍCH
        // (`cashback_target` > 0): lúc đó số ĐÃ LƯU là chân lý, không được dự kiến
        // thêm — nếu không, giới hạn theo giao dịch của engine (vd siêu thị
        // 138.300đ bị trần 10.000đ) sẽ bị tính lại thành 27.660đ và dòng quota nói
        // sai số tiền thật.
        //
        // Ngược lại phải dự kiến phần cashback đã ăn vào bậc đích mà snapshot
        // không thấy. Có HAI tình huống, cùng xử lý:
        //   - engine chạy bậc thấp trả 0đ (`used` = 0);
        //   - engine chạy bậc KHÁC bậc đích và đã trả >0đ theo tỷ lệ của CHÍNH bậc
        //     đó (StepUp: bậc 1 @6% ghi 300.000đ, bậc đích @15% ⇒ 700.000đ). Số đã
        //     trả ấy KHÔNG phải "đã dùng" của bậc đích nên phải tính lại theo bậc
        //     đích; nếu không, tử số "đã dùng / max" trộn hai bậc (300k của bậc 1
        //     trên mẫu số 700k của bậc 2).
        // Khi đó `expected - used` là phần chưa phản ánh. Nếu snapshot đến từ ĐÚNG
        // bậc đích thì `cashback_target` > 0 và giữ nguyên kết quả cũ.
        $hasTargetTierCashback = Decimal::isPositive($usedAtTargetTier);

        $unaccounted = $hasTargetTierCashback
            ? '0.00'
            : Decimal::clampZero(Decimal::subtract($expectedFromSpend, $used));

        $room = $available === null
            ? null
            : Decimal::clampZero(Decimal::subtract($available, $unaccounted));

        // -----------------------------------------------------------------
        // SỐ IN RA TRƯỚC DẤU "/" — CHỈ ĐỂ TRÌNH BÀY
        // -----------------------------------------------------------------
        // `cashback_used` giữ NGUYÊN nghĩa gốc: cashback thực tế đã phát sinh từ
        // snapshot, và mọi phép tính quota/lịch sử vẫn dùng đúng con số đó. Sửa
        // nó thành cashback dự kiến là bẻ lách cả hai nghĩa.
        //
        // Nhưng dòng "Quota hoàn tiền" trên Tổng quan đứng trước "/ max" thì phải
        // là cashback MÀ SỐ TIỀN ĐÃ CHI TẠO RA Ở TỶ LỆ CỦA BẬC ĐÍCH. Engine chạy
        // bậc theo CHI TIÊU THỰC TẾ nên có thể đã trả 0đ cho các giao dịch sẵn
        // có; hoặc đã trả >0đ nhưng theo tỷ lệ của bậc KHÁC bậc đích (StepUp:
        // 300.000đ của bậc 1 @6% không được lên mẫu số 700.000đ của bậc 2 @15%).
        // In "0 đ / 400.000 đ" hay "300.000 đ / 700.000 đ" khi tiền đã chi tạo ra
        // 700.000đ hoàn tiền là dòng quota nói dối người dùng.
        //
        // Kẹp theo ĐÚNG trần mà dòng đó đang in, để không bao giờ hiện
        // "410.000 đ / 400.000 đ". Không suy ra mẫu số: dòng in `/ max` khi
        // `has_cashback_max`, in "· còn" khi không có — lấy `min` của hai tầng
        // trần vẫn là trần thật trong cả hai trường hợp.
        $displayCap = match (true) {
            $max !== null && $tierMax !== null => Decimal::min($max, $tierMax),
            default => $max ?? $tierMax,
        };

        // Snapshot đến từ ĐÚNG bậc đích ⇒ nó đã là cashback của bậc đích (kèm trần
        // giao dịch engine đã áp); dùng nguyên số ĐÃ LƯU. Ngược lại dùng dự kiến
        // theo tỷ lệ bậc đích (bị kẹp theo trần phía dưới).
        $usedForDisplay = $hasTargetTierCashback ? $used : $expectedFromSpend;

        if ($displayCap !== null) {
            $usedForDisplay = Decimal::min($usedForDisplay, $displayCap);
        }

        $walk = $room === null
            ? ['spend' => null, 'reachable' => null]
            : $this->walkBands($this->bandChain($siblingRules, $rule), $scopeSpend, $room);

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
            'cashback_percent' => $rate,
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

            // Cashback THỰC TẾ từ snapshot. KHÔNG dùng để in ở Tổng quan, xem
            // `cashback_used_display` ngay dưới.
            'cashback_used' => $used,

            // Số in ra trước "/ max": cashback của số tiền đã chi theo tỷ lệ bậc
            // đích. Chỉ dùng để trình bày — KHÔNG đưa vào phép tính nào.
            'cashback_used_display' => $usedForDisplay,

            'cashback_remaining' => $remaining,

            // Tầng CHUNG, lặp lại ở từng rule để không phải tra cứu chéo.
            'tier_cashback_max' => $tierMax,
            'tier_cashback_used' => $tierUsed,
            'tier_cashback_projected' => $tierProjected,
            'tier_cashback_remaining' => $tierRemainingAfterProjection,

            // MIN của hai tầng trên.
            'cashback_available_for_rule' => $available,

            // Cashback mà số tiền ĐÃ CHI của phạm vi này ăn vào trần, tính bằng
            // tỷ lệ của chính rule trong bậc đích — và phần snapshot chưa tính.
            'cashback_expected_from_spend' => $expectedFromSpend,
            'cashback_unaccounted_from_spend' => $unaccounted,
            'cashback_room_remaining' => $room,

            // Chia CHỈ phòng còn lại trên, chứ không chia cả trần.
            'spend_remaining_estimate' => $walk['spend'],
            'is_exhausted' => $room !== null && Decimal::compare($room, '0.00') <= 0,

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
     * CỘT THỨ BA: CASHBACK ĐÃ GHI Ở ĐÚNG BẬC ĐÍCH
     * ---------------------------------------------------------------------------
     * Engine chọn bậc theo TỔNG CHI TIÊU THỰC TẾ, còn quota nói về bậc đích
     * (`desired_spend`). Khi hai bậc khác nhau, snapshot có thể DƯƠNG nhưng đến
     * từ bậc KHÁC bậc đích — không được coi là "đã dùng" của bậc đích (ví dụ thật
     * StepUp: bậc 1 @6% đã ghi 300.000đ cho một giao dịch, bậc đích @15% thì số
     * ấy phải là 700.000đ). `cashback_target` chỉ cộng snapshot mà rule của nó
     * thuộc CHÍNH bậc đích (`cc_rule.tier_id` = bậc đó); phần còn lại vẫn ở
     * `cashback`. Nhờ đó phân biệt được "snapshot = chân lý của bậc đích" với
     * "snapshot = chân lý của một bậc khác".
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
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $boundsByCard
     * @param  array<int, int>  $targetTierByCard  `user_card_id` ⇒ bậc đích mà quota đã resolve
     * @return array{cashback: array<string, string>, spend: array<string, string>, cashback_target: array<string, string>}
     */
    private function periodTotals(array $periodIds, array $boundsByCard = [], array $targetTierByCard = []): array
    {
        $useBounds = ! empty($boundsByCard);

        if (! $useBounds && $periodIds === []) {
            return ['cashback' => [], 'spend' => [], 'cashback_target' => []];
        }

        $transactions = (new Transaction)->getTable();
        $rules = (new PolicyTierCategory)->getTable();

        $query = Transaction::query()
            ->leftJoin($rules.' as cc_rule', 'cc_rule.id', '=', $transactions.'.policy_tier_category_id')
            ->whereNotNull($transactions.'.category_id');

        if ($useBounds) {
            $query->where(function ($q) use ($boundsByCard, $transactions): void {
                foreach ($boundsByCard as $cardId => [$start, $end]) {
                    $q->orWhere(function ($q) use ($transactions, $cardId, $start, $end): void {
                        $q->where($transactions.'.user_card_id', (int) $cardId)
                            ->whereDate($transactions.'.transaction_date', '>=', $start->toDateString())
                            ->whereDate($transactions.'.transaction_date', '<=', $end->toDateString());
                    });
                }
            });
        } else {
            $query->whereIn($transactions.'.statement_period_id', $periodIds);
        }

        // Cột thứ ba chỉ cần khi có thẻ với bậc đích rõ ràng. CASE gán bậc đích
        // theo thẻ rồi so với `cc_rule.tier_id` — thẻ ngoài danh sách ⇒ NULL,
        // so sánh NULL = tier_id ra NULL (không đếm), đúng.
        $targetTierExpr = null;

        if ($targetTierByCard !== []) {
            $targetTierExpr = 'CASE '.$transactions.'.user_card_id';

            foreach ($targetTierByCard as $cardId => $tierId) {
                $targetTierExpr .= ' WHEN '.(int) $cardId.' THEN '.(int) $tierId;
            }

            $targetTierExpr .= ' ELSE NULL END';
        }

        // Gom theo THẺ + DANH MỤC (không theo `statement_period_id`): "kỳ hiện tại"
        // được xác định bằng ranh giới `transaction_date` suy ra từ anchor của thẻ
        // — cùng định nghĩa với Tổng quan/Báo cáo. Thẻ thiếu bản ghi kỳ khớp ranh
        // giới vẫn ra đúng số vì không còn phụ thuộc bản ghi kỳ.
        $rows = $query
            ->groupBy($transactions.'.user_card_id', $transactions.'.category_id')
            ->select([
                $transactions.'.user_card_id',
                $transactions.'.category_id',
            ])
            ->selectRaw('SUM('.$transactions.'.amount) as spend')
            ->selectRaw(
                "SUM(CASE WHEN {$transactions}.policy_tier_category_id IS NOT NULL"
                ." AND COALESCE(cc_rule.scope_type, '".PolicyTierCategory::SCOPE_CATEGORY."') <> '"
                .PolicyTierCategory::SCOPE_OTHER."' THEN {$transactions}.cashback_amount_snapshot ELSE 0 END) as cashback"
            );

        if ($targetTierExpr !== null) {
            $query->selectRaw(
                "SUM(CASE WHEN {$transactions}.policy_tier_category_id IS NOT NULL"
                ." AND COALESCE(cc_rule.scope_type, '".PolicyTierCategory::SCOPE_CATEGORY."') <> '"
                .PolicyTierCategory::SCOPE_OTHER."'"
                ." AND {$targetTierExpr} = cc_rule.tier_id"
                ." THEN {$transactions}.cashback_amount_snapshot ELSE 0 END) as cashback_target"
            );
        }

        $rows = $query->get();

        $cashback = [];
        $spend = [];
        $cashbackTarget = [];

        foreach ($rows as $row) {
            $key = $this->cardCategoryKey((int) $row->user_card_id, (int) $row->category_id);

            $cashback[$key] = Decimal::money($row->cashback);
            $spend[$key] = Decimal::money($row->spend);

            if ($targetTierExpr !== null) {
                $cashbackTarget[$key] = Decimal::money($row->cashback_target);
            }
        }

        return ['cashback' => $cashback, 'spend' => $spend, 'cashback_target' => $cashbackTarget];
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
     * Khóa dedupe cho bảng dự kiến: một scope = một danh mục hoặc một combo.
     *
     * @return array<string, string>  scope key ⇒ cashback dự kiến chưa snapshot
     */
    private function projectionByScope(Collection $quotaRules, array $membersByCombo, array $totals): array
    {
        $projectionByScope = [];

        foreach ($quotaRules as $rule) {
            $categoryIds = $this->categoryIdsOf($rule, $membersByCombo);
            $used = $this->sumOf($totals['cashback'], $this->getContextCardId(), $categoryIds);
            $usedAtTargetTier = $this->sumOf($totals['cashback_target'], $this->getContextCardId(), $categoryIds);
            $scopeSpend = Decimal::clampZero($this->sumOf($totals['spend'], $this->getContextCardId(), $categoryIds));

            $rate = Decimal::money($rule->cashback_percent);
            $expected = Decimal::isPositive($rate)
                ? Decimal::cashbackForSpend($scopeSpend, $rate)
                : '0.00';

            // Kẹp theo TRẦN RIÊNG của chính rule: cashback dự kiến không bao giờ
            // vượt trần mà dòng quota đang in — cũng là cách một dải rate cao
            // (bị cap chặt) không khống chế projection của dải rate thấp hơn.
            $cap = $rule->max_cashback_per_category_per_period === null
                ? null
                : Decimal::money($rule->max_cashback_per_category_per_period);

            // Engine đã trả thật cho scope này Ở ĐÚNG BẬC ĐÍCH ⇒ phần dự kiến bằng
            // 0: số ĐÃ LƯU đã ăn vào bậc đích, dự kiến thêm nữa là double-count.
            // Snapshot đến từ bậc KHÁC (dù >0) thì KHÔNG tính là đã ăn bậc đích —
            // vẫn dự kiến phần `expected (kẹp trần riêng) − đã lưu`.
            $projection = Decimal::isPositive($usedAtTargetTier)
                ? '0.00'
                : Decimal::clampZero(Decimal::subtract(
                    $cap === null ? $expected : Decimal::min($expected, $cap),
                    $used,
                ));

            // MAX trong scope, không cộng dồn: nhiều dải rate tick quota cùng
            // một danh mục/combo thì mỗi đồng chi tiêu chỉ được dự kiến MỘT lần.
            $key = $this->scopeKeyOf($rule);

            if (! isset($projectionByScope[$key]) || Decimal::compare($projection, $projectionByScope[$key]) > 0) {
                $projectionByScope[$key] = $projection;
            }
        }

        return $projectionByScope;
    }

    private function scopeKeyOf(PolicyTierCategory $rule): string
    {
        return $rule->isComboSpecific()
            ? 'combo:'.(int) $rule->combo_id
            : 'category:'.(int) $rule->category_id;
    }

    private function sumOf(array $table, ?int $cardId, array $categoryIds): string
    {
        if ($cardId === null || $categoryIds === []) {
            return '0.00';
        }

        $total = '0.00';

        foreach ($categoryIds as $categoryId) {
            $total = Decimal::add($total, $table[$this->cardCategoryKey($cardId, $categoryId)] ?? '0.00');
        }

        return $total;
    }

    private function cardCategoryKey(int $cardId, int $categoryId): string
    {
        return $cardId.':'.$categoryId;
    }
}
