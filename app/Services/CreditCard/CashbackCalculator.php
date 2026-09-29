<?php

namespace App\Services\CreditCard;

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
 *   8. Cap 3: max tổng mỗi kỳ.
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
     * @param  array<int, array<string, mixed>>  $rules
     */
    public function calculate(
        array $rules,
        array $transactions,
        float $minTotalSpend,
        ?float $maxCashbackTotalPerPeriod,
        string $roundingMode = 'round',
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

        // ---- BƯỚC 2: rule cho từng danh mục, CHỌN THEO TỔNG CHI TIÊU CỦA DANH MỤC TRONG KỲ ----
        $ruleByCategory = [];
        foreach ($categoryTotals as $categoryId => $categorySpend) {
            $rule = $this->pickRule($rules, (int) $categoryId, $categorySpend);

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

            $raw = $this->round($amount * ((float) $rule['cashback_percent'] / 100), $roundingMode);

            $cashback = $raw;
            $caps = [];

            // Cap 1 — trần mỗi giao dịch
            $capPerTransaction = $this->nullableFloat($rule['max_cashback_per_transaction'] ?? null);
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

            // Cap 3 — trần tổng mỗi kỳ (nằm ở policy)
            if ($maxCashbackTotalPerPeriod !== null) {
                $remaining = max($maxCashbackTotalPerPeriod - $usedTotal, 0.0);
                $applied = $cashback > $remaining;
                $cashback = min($cashback, $remaining);
                $caps[] = [
                    'type' => 'per_period_total',
                    'limit' => $maxCashbackTotalPerPeriod,
                    'used_before' => round($usedTotal, 2),
                    'remaining' => round($remaining, 2),
                    'applied' => $applied,
                ];
            }

            $cashback = $this->round($cashback, $roundingMode);
            $cashback = max($cashback, 0.0);

            $usedByCategory[(int) $line->categoryId] = ($usedByCategory[(int) $line->categoryId] ?? 0.0) + $cashback;
            $usedTotal += $cashback;

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

        $hasEnabledRule = false;
        $lowestMin = null;

        foreach ($rules as $rule) {
            if ((int) $rule['category_id'] !== $line->categoryId) {
                continue;
            }

            $hasEnabledRule = true;

            // Rule không đặt `min_transaction_amount` ⇒ mức sàn là 0.
            $floor = $this->nullableFloat($rule['min_transaction_amount'] ?? null) ?? 0.0;

            if ($lowestMin === null || $floor < $lowestMin) {
                $lowestMin = $floor;
            }
        }

        if (! $hasEnabledRule) {
            return ['is_eligible' => false, 'reason' => self::REASON_NO_CATEGORY_RULE];
        }

        if ($line->amountAsFloat() < $lowestMin) {
            return ['is_eligible' => false, 'reason' => self::REASON_BELOW_MIN_TRANSACTION];
        }

        return ['is_eligible' => true, 'reason' => null];
    }

    /**
     * Chọn rule chứa khoảng chi tiêu `$categorySpend` — [spend_from, spend_to).
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, mixed>|null
     */
    private function pickRule(array $rules, int $categoryId, float $categorySpend): ?array
    {
        $candidates = [];

        foreach ($rules as $rule) {
            if ((int) $rule['category_id'] !== $categoryId) {
                continue;
            }

            $from = (float) $rule['spend_from'];
            $to = $this->nullableFloat($rule['spend_to'] ?? null);

            if ($categorySpend < $from) {
                continue;
            }

            if ($to !== null && $categorySpend >= $to) {
                continue;
            }

            $candidates[] = $rule;
        }

        if ($candidates === []) {
            return null;
        }

        // Nhiều rule cùng phạm vi ⇒ chọn khoảng hẹp nhất rồi theo id để TẤT ĐỊNH.
        usort($candidates, function (array $a, array $b): int {
            $widthA = $this->nullableFloat($a['spend_to'] ?? null) === null
                ? PHP_FLOAT_MAX
                : ((float) $a['spend_to'] - (float) $a['spend_from']);

            $widthB = $this->nullableFloat($b['spend_to'] ?? null) === null
                ? PHP_FLOAT_MAX
                : ((float) $b['spend_to'] - (float) $b['spend_from']);

            return [$widthA, (int) $a['id']] <=> [$widthB, (int) $b['id']];
        });

        return $candidates[0];
    }

    /**
     * @param  array<int, array{is_eligible: bool, reason: ?string}>  $eligible
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
     * Làm tròn về 2 chữ số thập phân theo mode của policy.
     *
     * Cẩn thận số thực: `55555 * 0.01` không ra đúng `555.55` mà ra
     * `55554.999999999995` khi nhân với 100. Nếu `floor()` thẳng giá trị đó thì
     * hạ người dùng 1 xu mà không có lý do. Vì vậy ta làm sạch nhiễu fp ở mức
     * 1e-6 (thấp hơn 1 xu nhưng cao hơn nhiễu double ~1e-10) trước khi cắt.
     *
     * Giới hạn: với số tiền khổng lồ (>~1e10) độ chính xác double có thể chạm
     * mức 1 xu. Miền nghiệp vụ của module là VNĐ theo kỳ, không vượt mức đó.
     */
    private function round(float $value, string $mode): float
    {
        $scaled = round($value * 100, 6);

        $cents = match ($mode) {
            'floor' => (int) floor($scaled),
            'ceil' => (int) ceil($scaled),
            default => (int) round($scaled, 0, PHP_ROUND_HALF_UP),
        };

        return $cents / 100;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
