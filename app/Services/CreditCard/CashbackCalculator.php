<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\PolicyTierCategory;

/**
 * CashbackCalculator — business logic cashback THUẦN.
 *
 * QUY TẮC BẤT BIẾN của lớp này:
 *   - KHÔNG truy vấn database.
 *   - KHÔNG ghi database, KHÔNG side-effect ra ngoài.
 *   - KHÔNG gọi service khác.
 *   - Cùng input ⇒ cùng output (deterministic, §16.2).
 *
 * Nhờ vậy có thể unit-test bằng PHPUnit thuần, không cần DB.
 *
 * ---------------------------------------------------------------------------
 * THỨ TỰ TÍNH (retroactive, §10/§24)
 * ---------------------------------------------------------------------------
 *   1. Tính TỔNG eligible spend của cả kỳ.
 *   2. Nếu tổng < `min_total_spend` của policy version ⇒ MỌI giao dịch cashback = 0.
 *   3. Resolve TIER theo tổng eligible spend cuối kỳ (KHÔNG theo tiến trình từng
 *      giao dịch — đó là progressive và bị cấm).
 *   4. Với mỗi danh mục, tính tổng chi tiêu của danh mục đó trong kỳ, rồi
 *      chọn RULE theo khoảng [spend_from, spend_to) chứa tổng đó.
 *   5. cashback thô = amount × percent / 100, làm tròn.
 *   6. Cap 1: max mỗi giao dịch.
 *   7. Cap 2: max mỗi danh mục mỗi kỳ.
 *   8. Cap 3: max tổng mỗi kỳ — nằm ở BẬC đang áp dụng (`maxCashbackPerPeriod`),
 *      không còn nằm ở policy toàn cục (migration chuyển sang `tiers.max_cashback_per_period`).
 *
 * Vì bước 4 dùng TỔNG theo danh mục của cả kỳ (không phụ thuộc thứ tự), toàn bộ
 * phần "chọn mức %" là không thứ tự. Các cap 2/3 là giới hạn dồn, được phân bổ
 * theo thứ tự cố định `transaction_date ASC, id ASC` để luôn cho kết quả giống
 * nhau giữa hai lần chạy.
 */
class CashbackCalculator
{
    public const REASON_NO_CATEGORY = 'no_category';

    public const REASON_NO_CATEGORY_RULE = 'no_category_rule';

    public const REASON_BELOW_MIN_TRANSACTION = 'below_min_transaction_amount';

    public const REASON_BELOW_MINIMUM_SPEND = 'below_minimum_spend';

    public const REASON_NO_TIER = 'no_matching_tier';

    /**
     * Rule đã resolve (dạng mảng, đã hydrate từ DB) cho một danh mục trong một tier.
     *
     * Mỗi phần tử: [
     *   'id' => int,
     *   'category_id' => int,
     *   'spend_from' => float,
     *   'spend_to' => ?float,
     *   'cashback_percent' => float,
     *   'max_cashback_per_transaction' => ?float,
     *   'max_cashback_per_category_per_period' => ?float,
     *   'min_transaction_amount' => ?float,
     * ]
     *
     * Cap động theo giá trị giao dịch (§23) KHÔNG còn nằm ở rule — truyền qua
     * tham số `$transactionCaps` (tài sản của BẬC, áp cho MỌI rule trong bậc).
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<int, array<string, mixed>>  $transactionCaps  cap động của bậc,
     *                                                             mỗi phần tử ['min_transaction_amount' => float,
     *                                                             'max_transaction_amount' => ?float,
     *                                                             'max_cashback_per_transaction' => float]; rỗng = không dùng.
     * @param  string  $roundingMode  ĐƯỢC GIỮ để tương thích caller, nhưng KHÔNG
     *                                còn dùng: cashback luôn floor xuống đồng.
     */
    public function calculate(
        array $rules,
        array $transactions,
        float $minTotalSpend,
        ?float $maxCashbackPerPeriod,
        string $roundingMode = 'round',
        array $transactionCaps = [],
    ): array {
        $transactions = $this->sortDeterministically($transactions);

        // ---- BƯỚC 1: giao dịch nào "eligible" về mặt rule (không phụ thuộc cap) ----
        $eligible = [];
        $categoryTotals = [];

        foreach ($transactions as $line) {
            $result = $this->evaluate($line, $rules);

            $eligible[$line->id] = $result;

            if ($result['is_eligible']) {
                $categoryTotals[$line->categoryId] = ($categoryTotals[$line->categoryId] ?? 0.0) + $line->amountAsFloat();
            }
        }

        // ---- BƯỚC 2: chọn rule cho từng danh mục ----
        //
        // Thứ tự ưu tiên (mở rộng từ chuỗi 2 tầng cũ, xem `pickRule`):
        //   1) rule DANH MỤC cụ thể, khoảng đo trên tổng chi tiêu của danh mục đó;
        //   2) rule COMBO, khoảng đo trên tổng chi tiêu của CẢ danh mục thành viên;
        //   3) fallback "Các danh mục còn lại" (scope_type=other).
        //
        // Tổng của combo phải gom trước ở bước trên, vì nó là đầu vào để chọn
        // rule combo. Mỗi danh mục chỉ nhận MỘT rule ⇒ kết quả tất định, không
        // phụ thuộc thứ tự duyệt. Fallback 0% VẪN là rule hợp lệ — không coi 0%
        // là "không có rule".
        $comboTotals = $this->comboTotals($rules, $categoryTotals);

        $ruleByCategory = [];
        foreach ($categoryTotals as $categoryId => $categorySpend) {
            $rule = $this->pickRule($rules, (int) $categoryId, $categorySpend)
                ?? $this->pickCombo($rules, (int) $categoryId, $comboTotals)
                ?? $this->pickFallback($rules, $categorySpend);

            if ($rule !== null) {
                $ruleByCategory[(int) $categoryId] = $rule;
            }
        }

        // ---- BƯỚC 3: minimum spend của CARD POLICY ----
        $totalEligibleSpend = array_sum($categoryTotals);

        if ($totalEligibleSpend < $minTotalSpend) {
            return $this->allIneligible($eligible, self::REASON_BELOW_MINIMUM_SPEND, $totalEligibleSpend);
        }

        // ---- BƯỚC 4-8: áp rate + 3 cap theo thứ tự cố định ----
        $results = [];
        $usedByCategory = [];
        $usedTotal = 0.0;

        foreach ($transactions as $line) {
            $evaluated = $eligible[$line->id];

            if (! $evaluated['is_eligible']) {
                $results[] = new CashbackResult(
                    transactionId: $line->id,
                    isEligible: false,
                    cashbackAmount: '0.00',
                    ineligibleReason: $evaluated['reason'],
                );

                continue;
            }

            $rule = $ruleByCategory[(int) $line->categoryId] ?? null;

            if ($rule === null) {
                $results[] = new CashbackResult(
                    transactionId: $line->id,
                    isEligible: false,
                    cashbackAmount: '0.00',
                    ineligibleReason: self::REASON_NO_CATEGORY_RULE,
                );

                continue;
            }

            $amount = $line->amountAsFloat();

            // Kiểm lại `min_transaction_amount` CỦA RULE ĐÃ CHỌN.
            // Bước 1 chỉ áp mức sàn nhỏ nhất nên vẫn còn trường hợp giao dịch lọt
            // qua nhưng thấp hơn min của rule cuối cùng. Không có bước này thì
            // người dùng nhận cashback cho giao dịch dưới ngưỡng của chính rule
            // đang áp dụng.
            $ruleMin = $this->nullableFloat($rule['min_transaction_amount'] ?? null);

            if ($ruleMin !== null && $amount < $ruleMin) {
                $results[] = new CashbackResult(
                    transactionId: $line->id,
                    isEligible: false,
                    cashbackAmount: '0.00',
                    ineligibleReason: self::REASON_BELOW_MIN_TRANSACTION,
                    meta: ['rule_id' => (int) $rule['id'], 'min_transaction_amount' => $ruleMin],
                );

                continue;
            }

            $raw = $this->round($amount * ((float) $rule['cashback_percent'] / 100));

            $cashback = $raw;
            $caps = [];

            // Chỉ rule "tính vào giới hạn hoàn tiền của bậc" tiêu tốn Cap 3.
            $countsTowardCap = (bool) ($rule['counts_toward_tier_cap'] ?? true);

            // Cap 1 — trần mỗi giao dịch.
            // Khi bậc có cap động `$transactionCaps` (§23), cap giao dịch ĐỘNG theo
            // giá trị: giao dịch thuộc khoảng nào thì lấy cap của khoảng đó (thay thế
            // cap cố định của rule); không khớp khoảng nào thì quay về cap cố định.
            $capPerTransaction = $this->pickTransactionCap($rule, $amount, $transactionCaps);
            if ($capPerTransaction !== null) {
                $applied = $cashback > $capPerTransaction;
                $cashback = min($cashback, $capPerTransaction);
                $caps[] = ['type' => 'per_transaction', 'limit' => $capPerTransaction, 'raw' => $raw, 'applied' => $applied];
            }

            // Cap 2 — trần mỗi danh mục mỗi kỳ
            $capPerCategory = $this->nullableFloat($rule['max_cashback_per_category_per_period'] ?? null);
            if ($capPerCategory !== null) {
                $categoryId = (int) $line->categoryId;
                $usedBefore = $usedByCategory[$categoryId] ?? 0.0;
                $remaining = max($capPerCategory - $usedBefore, 0.0);
                $applied = $cashback > $remaining;
                $cashback = min($cashback, $remaining);
                $caps[] = [
                    'type' => 'per_category',
                    'limit' => $capPerCategory,
                    'used_before' => round($usedBefore, 2),
                    'remaining' => round($remaining, 2),
                    'applied' => $applied,
                ];
            }

            // Cap 3 — trần tổng mỗi kỳ (nằm ở BẬC đang áp dụng). Chỉ áp dụng cho
            // rule có `counts_toward_tier_cap = true`; cashback của fallback
            // (counts = false) KHÔNG làm giảm "ngân sách" còn lại của bậc.
            if ($maxCashbackPerPeriod !== null && $countsTowardCap) {
                $remaining = max($maxCashbackPerPeriod - $usedTotal, 0.0);
                $applied = $cashback > $remaining;
                $cashback = min($cashback, $remaining);
                $caps[] = [
                    'type' => 'per_period_total',
                    'limit' => $maxCashbackPerPeriod,
                    'used_before' => round($usedTotal, 2),
                    'remaining' => round($remaining, 2),
                    'applied' => $applied,
                ];
            }

            $cashback = $this->round($cashback);
            $cashback = max($cashback, 0.0);

            $usedByCategory[(int) $line->categoryId] = ($usedByCategory[(int) $line->categoryId] ?? 0.0) + $cashback;

            if ($countsTowardCap) {
                $usedTotal += $cashback;
            }

            $results[] = new CashbackResult(
                transactionId: $line->id,
                isEligible: true,
                cashbackAmount: number_format($cashback, 2, '.', ''),
                cashbackPercent: number_format((float) $rule['cashback_percent'], 3, '.', ''),
                ruleId: (int) $rule['id'],
                meta: ['raw' => $raw, 'caps' => $caps, 'total_eligible_spend' => round($totalEligibleSpend, 2)],
            );
        }

        return $results;
    }

    /**
     * Thứ tự cố định: transaction_date ASC, rồi id ASC.
     * Đây là điều kiện để phân bổ cap luôn cho kết quả giống nhau.
     *
     * @param  array<int, TransactionLine>  $transactions
     * @return array<int, TransactionLine>
     */
    private function sortDeterministically(array $transactions): array
    {
        usort($transactions, function (TransactionLine $a, TransactionLine $b): int {
            return [$a->transactionDate, $a->id] <=> [$b->transactionDate, $b->id];
        });

        return $transactions;
    }

    /**
     * Giao dịch có đủ điều kiện về mặt "còn rule + đạt min_transaction_amount" chưa.
     *
     * MỘT DANH MỤC CÓ THỂ CÓ NHIỀU RULE khác `min_transaction_amount` (ví dụ
     * rule [0, 5tr) không đặt min, rule [5tr, ∞) yêu cầu >= 1tr). Ở bước này
     * CHƯA biết rule nào sẽ được chọn (rule chọn theo TỔNG chi tiêu danh mục cả
     * kỳ, mà tổng lại phụ thuộc kết quả bước này ⇒ vòng lặp). Nên bước này chỉ
     * áp MỨC SÀN NHỎ NHẤT trong tất cả rule của danh mục:
     *
     *   - nhỏ hơn sàn này ⇒ chắc chắn không rule nào hợp lệ ⇒ loại ngay.
     *   - lớn hơn ⇒ để qua, rồi bước áp rate sẽ kiểm lại với `min_transaction_amount`
     *     CỦA RULE ĐÃ CHỌN (xem vòng lặc chính).
     *
     * Nếu đánh giá theo từng rule riêng lẻ thì một giao dịch có thể bị loại OÁT
     * vì không đạt min của một rule không liên quan, hoặc (nếu dùng `return` sớm)
     * bị loại dù tồn tại rule không đặt min.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array{is_eligible: bool, reason: ?string}
     */
    private function evaluate(TransactionLine $line, array $rules): array
    {
        if ($line->categoryId === null) {
            return ['is_eligible' => false, 'reason' => self::REASON_NO_CATEGORY];
        }

        // Mức sàn nhỏ nhất trong các rule MÀ DANH MỤC NÀY CÓ THỂ KHỚP:
        //   - rule danh mục cụ thể (`minFloor($rules, $categoryId)`),
        //   - rule combo chứa danh mục (`minFloor($rules, null, $categoryId)`),
        //   - fallback "Các danh mục còn lại" (`minFloor($rules, null)`).
        //
        // Danh mục chỉ cần thoả sàn NHỎ NHẤT ở bước này; bước áp rate sẽ kiểm
        // lại với `min_transaction_amount` CỦA RULE ĐÃ CHỌN. Còn nếu không rule
        // nào khớp ở cả ba nhóm thì danh mục không có đường vào cashback.
        $floors = [
            $this->minFloor($rules, (int) $line->categoryId),
            $this->minFloor($rules, null, (int) $line->categoryId),
            $this->minFloor($rules, null),
        ];

        $matched = array_values(array_filter($floors, fn (array $floor): bool => $floor['has']));

        if ($matched === []) {
            return ['is_eligible' => false, 'reason' => self::REASON_NO_CATEGORY_RULE];
        }

        $lowestMin = null;

        foreach ($matched as $floor) {
            $lowestMin = $lowestMin === null ? $floor['floor'] : min($lowestMin, $floor['floor']);
        }

        if ($line->amountAsFloat() < $lowestMin) {
            return ['is_eligible' => false, 'reason' => self::REASON_BELOW_MIN_TRANSACTION];
        }

        return ['is_eligible' => true, 'reason' => null];
    }

    /**
     * (có rule khớp hay không, mức sàn `min_transaction_amount` nhỏ nhất) cho các
     * rule của một danh mục — hoặc của fallback khi `$categoryId = null`.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array{has: bool, floor: ?float}
     */
    private function minFloor(array $rules, ?int $categoryId, ?int $memberOfCombo = null): array
    {
        $has = false;
        $lowestMin = null;

        foreach ($rules as $rule) {
            $matches = match (true) {
                // Rule combo: khớp nếu danh mục nằm trong membership của combo.
                ($rule['combo_id'] ?? null) !== null => $memberOfCombo !== null
                    && $this->comboContains($rule, $memberOfCombo),
                // Rule danh mục cụ thể.
                $categoryId !== null => array_key_exists('category_id', $rule)
                    && (int) $rule['category_id'] === $categoryId,
                // Fallback.
                default => ($rule['scope_type'] ?? PolicyTierCategory::SCOPE_CATEGORY)
                    === PolicyTierCategory::SCOPE_OTHER,
            };

            if (! $matches) {
                continue;
            }

            $has = true;

            // Rule không đặt `min_transaction_amount` ⇒ mức sàn là 0.
            $floor = $this->nullableFloat($rule['min_transaction_amount'] ?? null) ?? 0.0;

            if ($lowestMin === null || $floor < $lowestMin) {
                $lowestMin = $floor;
            }
        }

        return ['has' => $has, 'floor' => $lowestMin];
    }

    /**
     * Danh mục này có thuộc combo của rule không?
     *
     * @param  array<string, mixed>  $rule
     */
    private function comboContains(array $rule, int $categoryId): bool
    {
        $members = $rule['combo_category_ids'] ?? null;

        if (! is_array($members) || $members === []) {
            return false;
        }

        foreach ($members as $member) {
            if ((int) $member === $categoryId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tổng chi tiêu eligible của TỪNG COMBO trong kỳ.
     *
     * Khoảng `[spend_from, spend_to)` của rule combo đo trên tổng của TOÀN BỘ danh
     * mục thành viên (đã chốt ở audit): một giao dịch 100k ở danh mục A và một
     * giao dịch 100k ở danh mục B thuộc cùng combo ⇒ tổng combo 200k. Cùng nguyên
     * tắc retroactive như rule danh mục và tier.
     *
     * Chỉ tính danh mục CÓ THẬT trong `$categoryTotals` (tức đã eligible về mặt
     * rule ở bước 1). Danh mục không eligible không vào tổng của bất kỳ combo nào.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<int|string, float>  $categoryTotals
     * @return array<int, float> khoá = combo_id
     */
    private function comboTotals(array $rules, array $categoryTotals): array
    {
        $totals = [];

        foreach ($rules as $rule) {
            $comboId = $rule['combo_id'] ?? null;

            if ($comboId === null) {
                continue;
            }

            $comboId = (int) $comboId;

            // Combo chưa có tổng hoặc đang tính dở → gom từ đầu danh sách thành viên.
            $members = $rule['combo_category_ids'] ?? [];

            if (is_array($members) && $members !== []) {
                $sum = 0.0;

                foreach ($members as $categoryId) {
                    $sum += $categoryTotals[(int) $categoryId] ?? 0.0;
                }

                $totals[$comboId] = $sum;
            } else {
                // Combo rỗng (không còn thành viên) vẫn phải có mục để `pickCombo`
                // không khớp nhầm sang rule của combo khác.
                $totals[$comboId] ??= 0.0;
            }
        }

        return $totals;
    }

    /**
     * Chọn rule danh mục cụ thể (category_id khớp) chứa khoảng chi tiêu
     * `$categorySpend`.
     *
     * Rule COMBO có `category_id = NULL` nên `(int) null = 0` không bao giờ khớp
     * id danh mục hợp lệ — không cần lọc riêng. Vẫn dùng `array_key_exists` để không
     * phụ thuộc vào việc caller có truyền khoá `category_id` hay không.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, mixed>|null
     */
    private function pickRule(array $rules, int $categoryId, float $categorySpend): ?array
    {
        $candidates = [];

        foreach ($rules as $rule) {
            if (! array_key_exists('category_id', $rule)) {
                continue;
            }

            if ((int) $rule['category_id'] !== $categoryId) {
                continue;
            }

            $candidates[] = $rule;
        }

        return $this->pickByBand($candidates, $categorySpend);
    }

    /**
     * Chọn rule COMBO mà danh mục này là thành viên, chứa khoảng chi tiêu của combo.
     *
     * Gọi SAU `pickRule()` ⇒ ưu tiên rule danh mục hơn rule combo (đã chốt ở
     * audit). `CategoryRuleService` đã chặn hai combo rule trong cùng bậc chia sẻ
     * danh mục, nên ở đây mỗi danh mục chỉ có tối đa một combo khớp ⇒ kết quả tất
     * định. Nếu dữ liệu bẩn vẫn có nhiều combo khớp, `pickByBand` phá hợp nhất
     * (khoảng hẹp nhất, tie-break id) để không phụ thuộc thứ tự duyệt.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<int, float>  $comboTotals
     * @return array<string, mixed>|null
     */
    private function pickCombo(array $rules, int $categoryId, array $comboTotals): ?array
    {
        // Gom theo combo TRƯỚC, vì mỗi combo có tổng chi tiêu riêng. Nếu gộp tất cả
        // rule combo vào một nhóm rồi mới so khoảng thì một rule của combo khác sẽ
        // bị đối chiếu với tổng sai combo.
        $byCombo = [];

        foreach ($rules as $rule) {
            $comboId = $rule['combo_id'] ?? null;

            if ($comboId === null || ! array_key_exists((int) $comboId, $comboTotals)) {
                continue;
            }

            $members = $rule['combo_category_ids'] ?? [];

            if (! is_array($members) || ! in_array($categoryId, array_map('intval', $members), true)) {
                continue;
            }

            $byCombo[(int) $comboId][] = $rule;
        }

        // `CategoryRuleService` bảo đảm mỗi bậc chỉ có tối đa một combo rule chứa
        // danh mục này, nên vòng lặp thường chạy đúng một lần. Nhưng dữ liệu bẩn
        // vẫn có thể vi phạm, nên ta lấy rule khớp nhất trong TỪNG combo rồi mới
        // chọn tổng thể ⇒ không phụ thuộc thứ tự duyệt.
        $best = null;

        foreach ($byCombo as $comboId => $comboRules) {
            $winner = $this->pickByBand($comboRules, $comboTotals[$comboId]);

            if ($winner === null) {
                continue;
            }

            if ($best === null || $this->isNarrower($winner, $best)) {
                $best = $winner;
            }
        }

        return $best;
    }

    /**
     * Rule `$candidate` có khoảng chi tiêu hẹp hơn (và thắng tie-break id) so với
     * `$current` không? Cùng thứ tự phá hợp nhất với `pickByBand`.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $current
     */
    private function isNarrower(array $candidate, array $current): bool
    {
        $candidateTo = $this->nullableFloat($candidate['spend_to'] ?? null);
        $currentTo = $this->nullableFloat($current['spend_to'] ?? null);

        $candidateWidth = $candidateTo === null
            ? PHP_FLOAT_MAX
            : ($candidateTo - (float) $candidate['spend_from']);
        $currentWidth = $currentTo === null
            ? PHP_FLOAT_MAX
            : ($currentTo - (float) $current['spend_from']);

        $comparison = [$candidateWidth, (int) $candidate['id']]
            <=> [$currentWidth, (int) $current['id']];

        return $comparison < 0;
    }

    /**
     * Chọn fallback "Các danh mục còn lại" (scope_type=other) chứa khoảng chi tiêu
     * `$categorySpend`. Fallback 0% VẪN được chọn như một rule hợp lệ.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, mixed>|null
     */
    private function pickFallback(array $rules, float $categorySpend): ?array
    {
        $candidates = [];

        foreach ($rules as $rule) {
            if (($rule['scope_type'] ?? PolicyTierCategory::SCOPE_CATEGORY) !== PolicyTierCategory::SCOPE_OTHER) {
                continue;
            }

            $candidates[] = $rule;
        }

        return $this->pickByBand($candidates, $categorySpend);
    }

    /**
     * Từ nhóm rule đã lọc, chọn rule có khoảng [spend_from, spend_to) chứa
     * `$categorySpend`.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    private function pickByBand(array $candidates, float $categorySpend): ?array
    {
        $matched = [];

        foreach ($candidates as $rule) {
            $from = (float) $rule['spend_from'];
            $to = $this->nullableFloat($rule['spend_to'] ?? null);

            if ($categorySpend < $from) {
                continue;
            }

            if ($to !== null && $categorySpend >= $to) {
                continue;
            }

            $matched[] = $rule;
        }

        if ($matched === []) {
            return null;
        }

        // Nhiều rule cùng phạm vi ⇒ chọn khoảng hẹp nhất rồi theo id để TẤT ĐỊNH.
        usort($matched, function (array $a, array $b): int {
            $widthA = $this->nullableFloat($a['spend_to'] ?? null) === null
                ? PHP_FLOAT_MAX
                : ((float) $a['spend_to'] - (float) $a['spend_from']);

            $widthB = $this->nullableFloat($b['spend_to'] ?? null) === null
                ? PHP_FLOAT_MAX
                : ((float) $b['spend_to'] - (float) $b['spend_from']);

            return [$widthA, (int) $a['id']] <=> [$widthB, (int) $b['id']];
        });

        return $matched[0];
    }

    /**
     * Cap mỗi giao dịch — ĐỘNG theo giá trị giao dịch nếu BẬC có `transactionCaps`.
     *
     * Mỗi điều kiện là một khoảng [min, max] ĐÓNG ở cả hai đầu
     * (`amount >= min AND (max = NULL OR amount <= max)`), đã được service sắp xếp
     * theo `sort_order` (min tăng dần) khi lưu. Giao dịch thuộc khoảng nào thì dùng
     * cap của khoảng đó — nó THAY THẾ `max_cashback_per_transaction` cố định.
     * Không khớp khoảng nào (hoặc bậc không có khoảng nào) ⇒ cap cố định như cũ.
     * Cap áp cho MỌI rule trong bậc — fallback 0% của bậc cũng chịu chung cap này.
     *
     * @param  array<string, mixed>  $rule
     * @param  array<int, array<string, mixed>>  $transactionCaps
     */
    private function pickTransactionCap(array $rule, float $amount, array $transactionCaps): ?float
    {
        $static = $this->nullableFloat($rule['max_cashback_per_transaction'] ?? null);

        foreach ($transactionCaps as $cap) {
            $min = (float) $cap['min_transaction_amount'];
            $max = $cap['max_transaction_amount'] === null ? null : (float) $cap['max_transaction_amount'];

            if ($amount >= $min && ($max === null || $amount <= $max)) {
                return (float) $cap['max_cashback_per_transaction'];
            }
        }

        return $static;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int, CashbackResult>
     */
    private function allIneligible(array $eligible, string $reason, float $totalEligibleSpend): array
    {
        $results = [];

        foreach ($eligible as $transactionId => $evaluated) {
            $results[] = new CashbackResult(
                transactionId: $transactionId,
                isEligible: false,
                cashbackAmount: '0.00',
                ineligibleReason: $evaluated['is_eligible'] ? $reason : $evaluated['reason'],
                meta: ['total_eligible_spend' => round($totalEligibleSpend, 2), 'min_total_spend_exceeded' => false],
            );
        }

        return $results;
    }

    /**
     * Làm tròn xuống đến ĐỒNG (số nguyên, không còn lẻ xu).
     *
     * Quy tắc cố định: cashback cuối cùng LUÔN floor về đơn vị đồng, không phụ
     * thuộc `roundingMode`. Cột `rounding_mode` vẫn còn trong DB nhưng không được
     * dùng để tính — tránh hai nguồn đúng sai. Không cho phép round/ceil (làm tròn
     * LÊN có thể trả người dùng nhiều hơn giá trị chính xác).
     *
     * Cẩn thận số thực: `55555 * 0.01` không ra đúng `555.55` mà ra
     * `55554.999999999995`. Nếu `floor()` thẳng giá trị đó thì hạ người dùng 1 đồng
     * mà không có lý do. Vì vậy ta làm sạch nhiễu fp ở mức 1e-6 (thấp hơn 1 đồng
     * nhưng cao hơn nhiễu double ~1e-10) rồi mới cắt.
     *
     * Giới hạn: với số tiền khổng lồ (>~1e10) độ chính xác double có thể chạm
     * mức 1 đồng. Miền nghiệp vụ của module là VNĐ theo kỳ, không vượt mức đó.
     */
    private function round(float $value): float
    {
        return floor(round($value, 6));
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
