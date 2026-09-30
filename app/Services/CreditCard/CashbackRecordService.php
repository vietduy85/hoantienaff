<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\PolicyVersion;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * CashbackRecordService — tầng DB của pipeline tính cashback.
 *
 * Trách nhiệm:
 *   1. Gom giao dịch của kỳ (thứ tự tất định).
 *   2. Xác định giao dịch nào "đủ điều kiện về mặt rule" — KHÔNG phụ thuộc bậc.
 *   3. Cộng tổng eligible spend của cả kỳ.
 *   4. Gọi PolicyEngineService để lấy version + bậc + rules.
 *   5. Gọi CashbackCalculator (hàm thuần) để ra con số.
 *   6. Ghi snapshot về từng giao dịch + tổng kỳ.
 *
 * ---------------------------------------------------------------------------
 * RETROACTIVE: VÌ SAO PHẢI TÍNH LẠI CẢ KỲ
 * ---------------------------------------------------------------------------
 * Bậc phụ thuộc TỔNG chi tiêu của cả kỳ. Thêm một giao dịch cuối kỳ có thể đẩy
 * tổng qua ngưỡng một bậc cao hơn ⇒ cashback của TẤT CẢ giao dịch trước đó cũng
 * đổi. Vì vậy mỗi lần có thay đổi chi tiêu trong kỳ, ta tính lại toàn bộ kỳ,
 * KHÔNG cộng dồn từng giao dịch.
 *
 * Chính vì vậy `calculatePeriod()` luôn TÍNH LẠI từ đầu (idempotent), không
 * cộng thêm vào giá trị cũ — chạy hai lần cho cùng dữ liệu cho ra cùng kết quả.
 *
 * ---------------------------------------------------------------------------
 * THỨ TỰ KIỂM TRA (thứ tự này quyết định `ineligible_reason`)
 * ---------------------------------------------------------------------------
 *   1. Chưa có policy version nào hiệu lực ⇒ no_policy_version
 *   2. Tổng eligible spend < `min_total_spend` của policy ⇒ below_minimum_spend
 *   3. Không bậc nào phủ tổng (lỗi cấu hình khoảng tier bị hở) ⇒ no_matching_tier
 *   4. Giao dịch không có rule / dưới `min_transaction_amount` ⇒ no_category_rule
 *      hoặc below_min_transaction_amount
 *
 * ---------------------------------------------------------------------------
 * KỲ ĐÃ FINALIZE
 * ---------------------------------------------------------------------------
 * Kỳ finalize là bản ghi lịch sử ⇒ KHÔNG tính lại, KHÔNG ghi đè snapshot.
 */
class CashbackRecordService
{
    public function __construct(
        private readonly PolicyEngineService $engine,
        private readonly TierResolverService $tiers,
        private readonly CashbackCalculator $calculator,
    ) {}

    /**
     * Tính lại cashback cho toàn bộ giao dịch của một kỳ sao kê.
     *
     * @return array{period: StatementPeriod, results: array<int, CashbackResult>, skipped: bool}
     */
    public function calculatePeriod(UserCard $userCard, StatementPeriod $period): array
    {
        if ($period->isFinalized()) {
            return ['period' => $period, 'results' => [], 'skipped' => true];
        }

        if ((int) $period->user_card_id !== (int) $userCard->id) {
            throw new LogicException('Kỳ sao kê không thuộc thẻ này.');
        }

        return DB::connection('creditcard')->transaction(function () use ($userCard, $period): array {
            $version = $this->engine->attachPolicyToPeriod($userCard, $period);

            /** @var Collection<int, Transaction> $transactions */
            $transactions = $this->transactionsOf($period);

            // ---- 1. Chưa có policy version ----
            if ($version === null) {
                $results = $this->writeAllIneligible(
                    $userCard,
                    $transactions,
                    Transaction::REASON_NO_POLICY,
                    version: null,
                    tierId: null,
                );

                $this->writePeriodTotals($period, 0.0, 0.0, [
                    'reason' => Transaction::REASON_NO_POLICY,
                    'application_mode' => 'retroactive',
                    'transaction_count' => $transactions->count(),
                    'eligible_transaction_count' => 0,
                    'statement_date_basis' => $userCard->statement_date_basis,
                ]);

                return ['period' => $period->refresh(), 'results' => $results, 'skipped' => false];
            }

            $lines = $this->toLines($transactions);

            // ---- 2. Eligible spend của CẢ KỲ, KHÔNG phụ thuộc bậc ----
            $totalEligibleSpend = $this->sumEligibleSpend($lines, $version);

            // ---- 3. Bậc theo tổng kỳ ----
            $tier = $this->tiers->resolveTier($version, $totalEligibleSpend);

            // ---- Khoảng tier bị hở: có chi tiêu nhưng không bậc nào phủ ⇒ lỗi cấu hình ----
            if ($tier === null && $totalEligibleSpend > 0) {
                $results = $this->writeAllIneligible(
                    $userCard,
                    $transactions,
                    Transaction::REASON_NO_TIER,
                    version: $version,
                    tierId: null,
                );

                $this->writePeriodTotals($period, $totalEligibleSpend, 0.0, [
                    'policy_version_id' => $version->id,
                    'policy_version_no' => (int) $version->version_no,
                    'reason' => Transaction::REASON_NO_TIER,
                    'application_mode' => 'retroactive',
                    'transaction_count' => $transactions->count(),
                    'eligible_transaction_count' => 0,
                    'total_eligible_spend' => round($totalEligibleSpend, 2),
                    'statement_date_basis' => $userCard->statement_date_basis,
                ]);

                return ['period' => $period->refresh(), 'results' => $results, 'skipped' => false];
            }

            $rules = $this->tiers->rulesForTier($tier);

            // ---- 4-6. Hàm thuần + ghi snapshot ----
            $results = $this->calculator->calculate(
                rules: $rules,
                transactions: $lines,
                minTotalSpend: (float) $version->min_total_spend,
                maxCashbackTotalPerPeriod: $version->max_cashback_total_per_period === null
                    ? null
                    : (float) $version->max_cashback_total_per_period,
                roundingMode: (string) $version->rounding_mode,
            );

            $this->persistResults($userCard, $transactions, $results, $version, $tier?->id, $rules);

            $totalCashback = array_sum(array_map(
                fn (CashbackResult $r): float => $r->cashbackAmountAsFloat(),
                $results
            ));

            $this->writePeriodTotals($period, $totalEligibleSpend, $totalCashback, [
                'policy_version_id' => $version->id,
                'policy_version_no' => (int) $version->version_no,
                'tier_id' => $tier?->id,
                'tier_name' => $tier?->name,
                'min_total_spend' => (float) $version->min_total_spend,
                'max_cashback_total_per_period' => $version->max_cashback_total_per_period === null
                    ? null
                    : (float) $version->max_cashback_total_per_period,
                'rounding_mode' => (string) $version->rounding_mode,
                'application_mode' => 'retroactive',
                'transaction_count' => count($lines),
                'eligible_transaction_count' => count(array_filter(
                    $results,
                    fn (CashbackResult $r): bool => $r->isEligible
                )),
                'statement_date_basis' => $userCard->statement_date_basis,
            ]);

            return ['period' => $period->refresh(), 'results' => $results, 'skipped' => false];
        });
    }

    /**
     * Tính lại toàn bộ kỳ `open` của thẻ.
     *
     * Dùng sau khi: thêm/xoá giao dịch, sửa category, đổi policy, đổi ngày ghi
     * nhận. Kỳ đã finalize bị bỏ qua.
     *
     * @return array<int, StatementPeriod>
     */
    public function recalculateOpenPeriods(UserCard $userCard): array
    {
        $periods = [];

        foreach ($userCard->statementPeriods()->open()->orderBy('period_end')->get() as $period) {
            $this->calculatePeriod($userCard, $period);
            $periods[] = $period->refresh();
        }

        return $periods;
    }

    /**
     * Tính lại một giao dịch vừa nhập, kèm việc đảm bảo nó đã gắn vào một kỳ.
     *
     * Chặn giao dịch của thẻ khác: nếu không, một giao dịch thuộc thẻ B có thể bị
     * ghi cashback vào kỳ của thẻ A và làm sai tổng của thẻ A.
     */
    public function calculateTransaction(UserCard $userCard, Transaction $transaction): Transaction
    {
        if ((int) $transaction->user_card_id !== (int) $userCard->id) {
            throw new \InvalidArgumentException('Giao dịch không thuộc thẻ tín dụng này.');
        }

        if ($transaction->statement_period_id === null) {
            $period = app(StatementPeriodService::class)->resolveForTransaction($userCard, $transaction);
            $transaction->forceFill(['statement_period_id' => $period->id])->save();
        } else {
            $period = $transaction->statementPeriod;
        }

        if ($period !== null) {
            $this->calculatePeriod($userCard, $period);
        }

        return $transaction->refresh();
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<int, CashbackResult>  $results
     */
    private function persistResults(
        UserCard $userCard,
        Collection $transactions,
        array $results,
        PolicyVersion $version,
        ?int $tierId,
        array $rules,
    ): void {
        $byId = $transactions->keyBy('id');
        $ruleById = collect($rules)->keyBy('id');

        foreach ($results as $result) {
            /** @var Transaction|null $transaction */
            $transaction = $byId->get($result->transactionId);

            if ($transaction === null) {
                continue;
            }

            $rule = $result->ruleId !== null ? $ruleById->get($result->ruleId) : null;

            $transaction->forceFill([
                'policy_version_id' => $version->id,
                // Bậc dùng để ĐÁNH GIÁ kỳ này (kể cả giao dịch không cashback) —
                // giữ lại để sau này giải thích "vì sao rate là X".
                'policy_tier_id' => $tierId,
                'policy_tier_category_id' => $rule !== null ? (int) $rule['id'] : null,
                'cashback_percent_snapshot' => $result->cashbackPercent,
                'cashback_amount_snapshot' => $result->cashbackAmount,
                'is_eligible' => $result->isEligible,
                'ineligible_reason' => $result->ineligibleReason,
                'calc_basis' => $transaction->basisDate($userCard->statement_date_basis)->toDateString(),
                'calc_meta' => $result->meta === [] ? null : $result->meta,
                'calculated_at' => $result->isEligible ? now() : null,
            ])->save();
        }
    }

    /**
     * Ghi toàn bộ giao dịch của kỳ ở trạng thái "không cashback" với một lý do.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array<int, CashbackResult>
     */
    private function writeAllIneligible(
        UserCard $userCard,
        Collection $transactions,
        string $reason,
        ?PolicyVersion $version,
        ?int $tierId,
    ): array {
        $results = [];

        foreach ($transactions as $transaction) {
            $transaction->forceFill([
                'policy_version_id' => $version?->id,
                'policy_tier_id' => $tierId,
                'policy_tier_category_id' => null,
                'cashback_percent_snapshot' => null,
                'cashback_amount_snapshot' => '0.00',
                'is_eligible' => false,
                'ineligible_reason' => $reason,
                'calc_basis' => $transaction->basisDate($userCard->statement_date_basis)->toDateString(),
                'calc_meta' => null,
                'calculated_at' => null,
            ])->save();

            $results[] = new CashbackResult(
                transactionId: (int) $transaction->id,
                isEligible: false,
                cashbackAmount: '0.00',
                ineligibleReason: $reason,
            );
        }

        return $results;
    }

    /**
     * Giao dịch của kỳ, CHƯA xoá mềm, thứ tự tất định.
     *
     * @return Collection<int, Transaction>
     */
    private function transactionsOf(StatementPeriod $period): Collection
    {
        return Transaction::query()
            ->where('statement_period_id', $period->id)
            ->chronological()
            ->get();
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array<int, TransactionLine>
     */
    private function toLines(Collection $transactions): array
    {
        return $transactions
            ->map(fn (Transaction $t): TransactionLine => new TransactionLine(
                id: (int) $t->id,
                transactionDate: $t->transaction_date->toDateString(),
                categoryId: $t->category_id === null ? null : (int) $t->category_id,
                amount: (string) $t->amount,
            ))
            ->all();
    }

    /**
     * Tổng eligible spend của kỳ.
     *
     * Một giao dịch được tính vào tổng khi CÓ ÍT NHẤT một rule `is_enabled` trong
     * BẤT KỲ bậc nào của version, VÀ số tiền đạt `min_transaction_amount` của
     * rule đó.
     *
     * Cố tình xét rule của MỌI bậc chứ không chỉ bậc "đúng": bậc là kết quả của
     * tổng này. Nếu lấy rule theo bậc trước rồi mới cộng tổng thì thành vòng
     * lặp tổng → bậc → tổng, và kết quả sẽ phụ thuộc thứ tự xử lý.
     *
     * @param  array<int, TransactionLine>  $lines
     */
    private function sumEligibleSpend(array $lines, PolicyVersion $version): float
    {
        $rulesByCategory = collect($this->tiers->allEnabledRulesFor($version))
            ->groupBy('category_id');

        $total = 0.0;

        foreach ($lines as $line) {
            if ($line->categoryId === null) {
                continue;
            }

            $categoryRules = $rulesByCategory->get($line->categoryId);

            if ($categoryRules === null || $categoryRules->isEmpty()) {
                continue;
            }

            foreach ($categoryRules as $rule) {
                $min = $rule['min_transaction_amount'];

                if ($min === null || $line->amountAsFloat() >= (float) $min) {
                    $total += $line->amountAsFloat();

                    break;
                }
            }
        }

        return $total;
    }

    private function writePeriodTotals(
        StatementPeriod $period,
        float $totalEligibleSpend,
        float $totalCashback,
        array $meta,
    ): void {
        $effectiveRate = $totalEligibleSpend > 0
            ? round($totalCashback / $totalEligibleSpend * 100, 3)
            : 0.0;

        $period->forceFill([
            'total_eligible_spend' => number_format($totalEligibleSpend, 2, '.', ''),
            'total_cashback' => number_format($totalCashback, 2, '.', ''),
            'effective_cashback_rate' => number_format($effectiveRate, 3, '.', ''),
            'calculation_meta' => $meta,
        ])->save();
    }
}
