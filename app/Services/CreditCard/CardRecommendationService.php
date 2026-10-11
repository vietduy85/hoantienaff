<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyVersion;
use App\Models\CreditCard\SpendQualification;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Support\CreditCard\Decimal;
use Carbon\CarbonImmutable;

/**
 * CardRecommendationService — "Lựa chọn thẻ": gợi ý thẻ hoàn tiền tốt nhất cho
 * MỘT nhu cầu chi tiêu (số tiền + danh mục) mà người dùng nhập.
 *
 * ---------------------------------------------------------------------------
 * CHỈ ĐỌC — KHÔNG GHI DB, KHÔNG SINH KỲ SAO KÊ
 * ---------------------------------------------------------------------------
 * Mọi bước đều là mô phỏng (what-if): không tạo `statement_periods`, không ghi
 * snapshot giao dịch, không `attachPolicyToPeriod` (hàm đó GHI `policy_id` vào
 * kỳ). Policy version của thẻ được resolve qua `PolicyEngineService::resolveFor`
 * — đúng đường đọc read-only mà engine dùng.
 *
 * ---------------------------------------------------------------------------
 * HAI NHÓM KẾT QUẢ
 * ---------------------------------------------------------------------------
 *   - "Thẻ của tôi": các thẻ đang dùng của user. Gom giao dịch THỰC TẾ của KỲ
 *     SAO KÊ HIỆN TẠI (mỗi thẻ một kỳ theo anchor), thêm MỘT giao dịch GIẢ ĐỊNH
 *     (số tiền + danh mục vừa nhập) rồi chạy lại ĐÚNG hàm của engine. Vì engine
 *     là retroactive (bậc phụ thuộc TỔNG chi tiêu cả kỳ), giao dịch giả định
 *     được tính vào tổng — nên kết quả là "nếu thêm giao dịch này, kỳ này được
 *     bao nhiêu cho chính giao dịch đó".
 *   - "Thẻ trên thị trường": các template hệ thống đang bật, chỉ xét giao dịch
 *     giả định đơn lẻ trên blueprint hiện hành của template; loại bỏ thẻ không
 *     hoàn tiền cho nhu cầu này.
 *
 * ---------------------------------------------------------------------------
 * GIAO DỊCH GIẢ ĐỊNH XẾP CUỐI ĐỂ NGỮ NGHĨA "THÊM GIAO DỊCH" ĐÚNG
 * ---------------------------------------------------------------------------
 * Engine phân bổ cap theo thứ tự cố định `transaction_date ASC, id ASC`. Giao
 * dịch giả định đặt ở NGÀY CUỐI KỲ (`period_end`) và id rất lớn (base cố định +
 * card/template id) nên luôn xếp CUỐI — nó tiêu thụ phần cap CÒN LẠI, đúng câu
 * hỏi "thêm giao dịch này thì được thêm bao nhiêu". Nếu xếp giữa, cap sẽ bị
 * chia lại và kết quả không còn là "phần tăng thêm".
 *
 * ---------------------------------------------------------------------------
 * TÁI SỬ DỤNG ENGINE, KHÔNG VIẾT LẠI LUẬT
 * ---------------------------------------------------------------------------
 * Tổng eligible spend của kỳ (đầu vào để resolve bậc) lấy bằng cách chạy
 * `CashbackCalculator` với `allEnabledRulesFor()` + `minTotalSpend = 0` rồi đọc
 * `meta.total_eligible_spend` — cùng ngữ nghĩa với `sumEligibleSpend()` (private)
 * của `CashbackRecordService`, nhưng không sao chép luật. Không nơi nào trong
 * lớp này tự nhân rate hay tự chọn rule.
 *
 * "Điều kiện hoàn tiền đặc biệt" (gate cả kỳ) được đo qua
 * `SpendQualificationService::summaryForPeriod()` trên CHÍNH tập dòng đã gồm
 * giao dịch giả định — what-if: người dùng thấy được "thêm giao dịch này có mở
 * khoá điều kiện không". (Thị trường KHÔNG áp gate này: một giao dịch đơn lẻ
 * không thể đại diện cho điều kiện chi tiêu theo kỳ của template.)
 */
class CardRecommendationService
{
    /**
     * Base id cho giao dịch giả định — lớn hơn mọi id tự tăng của
     * `credit_card_transactions`, nên luôn xếp cuối khi cùng ngày.
     */
    private const HYPOTHETICAL_ID_BASE = 900_000_000_000;

    private const MARKET_HYPOTHETICAL_ID_BASE = 800_000_000_000;

    public function __construct(
        private readonly StatementPeriodService $periods,
        private readonly PolicyEngineService $engine,
        private readonly TierResolverService $tiers,
        private readonly CashbackCalculator $calculator,
        private readonly SpendQualificationService $qualifications,
    ) {}

    /**
     * Đề xuất cho một nhu cầu chi tiêu.
     *
     * @param  string  $amount  số tiền VND (đã qua validate > 0 ở controller)
     * @return array{
     *     scenario: array{amount: string, category_id: int, category_name: string|null},
     *     my_cards: array<int, array<string, mixed>>,
     *     market_cards: array<int, array<string, mixed>>
     * }
     */
    public function recommend(int $userId, string $amount, int $categoryId): array
    {
        $amount = Decimal::money($amount);

        $categoryName = Category::query()
            ->whereKey($categoryId)
            ->value('name');

        return [
            'scenario' => [
                'amount' => $amount,
                'category_id' => $categoryId,
                'category_name' => $categoryName,
            ],
            'my_cards' => $this->myCards($userId, $amount, $categoryId),
            'market_cards' => $this->marketCards($amount, $categoryId),
        ];
    }

    /**
     * "Thẻ của tôi" — mọi thẻ đang dùng, kể cả thẻ không hoàn tiền (kèm lý do).
     *
     * @return array<int, array<string, mixed>>
     */
    private function myCards(int $userId, string $amount, int $categoryId): array
    {
        $today = CarbonImmutable::now();

        $cards = UserCard::query()
            ->ownedBy($userId)
            ->where('status', UserCard::STATUS_ACTIVE)
            ->whereNull('closed_at')
            ->with('bank')
            ->ordered()
            ->get();

        if ($cards->isEmpty()) {
            return [];
        }

        // Mỗi thẻ một bộ ranh giới kỳ hiện tại (derive theo anchor của thẻ).
        $boundsByCard = [];

        foreach ($cards as $card) {
            $boundsByCard[(int) $card->id] = $this->periods->currentBoundaries($card, $today);
        }

        $linesByCard = $this->transactionLinesByCard($boundsByCard);

        $rows = [];

        foreach ($cards as $card) {
            $cardId = (int) $card->id;
            [$start, $end] = $boundsByCard[$cardId];
            $hypotheticalId = self::HYPOTHETICAL_ID_BASE + $cardId;

            $lines = $linesByCard[$cardId] ?? [];
            $lines[] = new TransactionLine(
                id: $hypotheticalId,
                transactionDate: $end->toDateString(),
                categoryId: $categoryId,
                amount: $amount,
            );

            $evaluation = $this->evaluateCard($card, $lines, $hypotheticalId, $end, $amount);

            $rows[] = [
                'card_id' => $cardId,
                'card_name' => (string) $card->name,
                'bank_name' => $card->bank?->name,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
            ] + $evaluation;
        }

        // Sắp xếp: tiền hoàn giảm dần, rồi tỷ lệ giảm dần, rồi tên tăng dần.
        usort($rows, function (array $a, array $b): int {
            return [(float) $b['cashback'], $b['rate'], $a['card_name']]
                <=> [(float) $a['cashback'], $a['rate'], $b['card_name']];
        });

        return $rows;
    }

    /**
     * "Thẻ trên thị trường" — template hệ thống đang bật, CHỈ hiện nơi có hoàn
     * tiền dương cho nhu cầu này.
     *
     * @return array<int, array<string, mixed>>
     */
    private function marketCards(string $amount, int $categoryId): array
    {
        $templates = PolicyTemplate::query()
            ->system()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($templates->isEmpty()) {
            return [];
        }

        $today = CarbonImmutable::now()->toDateString();
        $rows = [];

        foreach ($templates as $template) {
            $version = $template->currentBlueprint();

            if ($version === null) {
                continue;
            }

            $hypotheticalId = self::MARKET_HYPOTHETICAL_ID_BASE + (int) $template->id;

            $lines = [new TransactionLine(
                id: $hypotheticalId,
                transactionDate: $today,
                categoryId: $categoryId,
                amount: $amount,
            )];

            $totalEligible = $this->totalEligibleSpend($version, $lines);
            $tier = $this->tiers->resolveTier($version, $totalEligible);

            if ($tier === null && $totalEligible > 0) {
                continue;
            }

            $results = $this->calculator->calculate(
                rules: $this->tiers->rulesForTier($tier),
                transactions: $lines,
                minTotalSpend: (float) $version->min_total_spend,
                maxCashbackPerPeriod: $tier?->max_cashback_per_period === null
                    ? null
                    : (float) $tier->max_cashback_per_period,
                roundingMode: (string) $version->rounding_mode,
                transactionCaps: $this->tiers->transactionCapsForTier($tier),
            );

            $result = $this->resultFor($results, $hypotheticalId);

            if ($result === null || ! $result->isEligible || $result->cashbackAmountAsFloat() <= 0.0) {
                continue;
            }

            $rows[] = [
                'template_id' => (int) $template->id,
                'name' => (string) $template->name,
                'tier_name' => $tier?->name,
                'cashback' => Decimal::money($result->cashbackAmount),
                'rate' => $this->rate($result->cashbackAmount, $amount),
                'cashback_percent' => $result->cashbackPercent,
                'caps_applied' => $this->appliedCaps($result),
            ];
        }

        // Sắp xếp thị trường: TỶ LỆ giảm dần TRƯỚC, rồi tiền hoàn giảm dần, rồi
        // tên tăng dần (khác "Thẻ của tôi" — câu hỏi "thẻ nào rate cao nhất").
        usort($rows, function (array $a, array $b): int {
            return [$b['rate'], (float) $b['cashback'], $a['name']]
                <=> [$a['rate'], (float) $a['cashback'], $b['name']];
        });

        return $rows;
    }

    /**
     * Chạy engine cho một thẻ với tập dòng đã gồm giao dịch giả định.
     *
     * Thứ tự kiểm tra mô phỏng đúng `CashbackRecordService::calculatePeriod()`:
     *   no_policy_version → no_matching_tier → qualification_not_met → (calculator)
     *   below_minimum_spend / no_category_rule / …
     *
     * @param  array<int, TransactionLine>  $lines
     * @return array<string, mixed>
     */
    private function evaluateCard(
        UserCard $card,
        array $lines,
        int $hypotheticalId,
        CarbonImmutable $date,
        string $amount,
    ): array {
        $resolved = $this->engine->resolveFor($card, $date);

        if ($resolved === null) {
            return $this->ineligibleResult(Transaction::REASON_NO_POLICY);
        }

        $version = $resolved->policyVersion;
        $totalEligible = $this->totalEligibleSpend($version, $lines);
        $tier = $this->tiers->resolveTier($version, $totalEligible);

        if ($tier === null && $totalEligible > 0) {
            return $this->ineligibleResult(Transaction::REASON_NO_TIER);
        }

        if (! $this->qualificationPasses($version, $lines)) {
            return $this->ineligibleResult(
                Transaction::REASON_QUALIFICATION_NOT_MET,
                $tier?->name,
            );
        }

        $results = $this->calculator->calculate(
            rules: $this->tiers->rulesForTier($tier),
            transactions: $lines,
            minTotalSpend: (float) $version->min_total_spend,
            maxCashbackPerPeriod: $tier?->max_cashback_per_period === null
                ? null
                : (float) $tier->max_cashback_per_period,
            roundingMode: (string) $version->rounding_mode,
            transactionCaps: $this->tiers->transactionCapsForTier($tier),
        );

        $result = $this->resultFor($results, $hypotheticalId);

        return [
            'tier_name' => $tier?->name,
            'cashback' => Decimal::money($result?->cashbackAmount ?? '0.00'),
            'rate' => $this->rate($result?->cashbackAmount ?? '0.00', $amount),
            'eligible' => $result?->isEligible ?? false,
            'reason' => $result?->ineligibleReason,
            'cashback_percent' => $result?->cashbackPercent,
            'caps_applied' => $this->appliedCaps($result),
        ];
    }

    /**
     * Tổng eligible spend của kỳ (đầu vào resolve bậc).
     *
     * Chạy `CashbackCalculator` với `allEnabledRulesFor()` + `minTotalSpend = 0`
     * rồi đọc `meta.total_eligible_spend` — cùng ngữ nghĩa với `sumEligibleSpend()`
     * (private) của `CashbackRecordService` mà KHÔNG sao chép luật. `minTotalSpend
     * = 0` để không short-circuit ở `below_minimum_spend` (khi đó mọi kết quả
     * eligible đều mang `total_eligible_spend`).
     *
     * @param  array<int, TransactionLine>  $lines
     */
    private function totalEligibleSpend(PolicyVersion $version, array $lines): float
    {
        if ($lines === []) {
            return 0.0;
        }

        $results = $this->calculator->calculate(
            rules: $this->tiers->allEnabledRulesFor($version),
            transactions: $lines,
            minTotalSpend: 0.0,
            maxCashbackPerPeriod: null,
        );

        foreach ($results as $result) {
            if (array_key_exists('total_eligible_spend', $result->meta)) {
                return (float) $result->meta['total_eligible_spend'];
            }
        }

        return 0.0;
    }

    /**
     * "Điều kiện hoàn tiền đặc biệt" của version có ĐẠT trên tập dòng đã gồm
     * giao dịch giả định không. Không có qualification / bị tắt / không điều kiện
     * nào bật ⇒ đạt (no-op, giống engine).
     *
     * @param  array<int, TransactionLine>  $lines
     */
    private function qualificationPasses(PolicyVersion $version, array $lines): bool
    {
        $qualification = SpendQualification::query()
            ->where('policy_version_id', $version->id)
            ->with(['conditions.category', 'conditions.excludedCategories'])
            ->first();

        if ($qualification === null) {
            return true;
        }

        $summary = $this->qualifications->summaryForPeriod($qualification, collect($lines));

        if ($summary === null) {
            return true;
        }

        foreach ($summary['conditions'] as $condition) {
            if (($condition['met'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * Giao dịch THỰC TẾ của kỳ hiện tại từng thẻ, dựng sẵn `TransactionLine` —
     * MỘT query cho mọi thẻ (không N+1), giữ đúng thứ tự `chronological()` mà
     * engine dùng khi phân bổ cap.
     *
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $boundsByCard
     * @return array<int, array<int, TransactionLine>> khoá = `user_card_id`
     */
    private function transactionLinesByCard(array $boundsByCard): array
    {
        if ($boundsByCard === []) {
            return [];
        }

        $table = (new Transaction)->getTable();

        $rows = Transaction::query()
            ->where(function ($query) use ($boundsByCard, $table): void {
                foreach ($boundsByCard as $cardId => [$start, $end]) {
                    $query->orWhere(function ($query) use ($table, $cardId, $start, $end): void {
                        $query->where($table.'.user_card_id', $cardId)
                            ->whereDate($table.'.transaction_date', '>=', $start->toDateString())
                            ->whereDate($table.'.transaction_date', '<=', $end->toDateString());
                    });
                }
            })
            ->chronological()
            ->get(['id', 'user_card_id', 'category_id', 'amount', 'transaction_date']);

        $lines = [];

        foreach ($rows as $row) {
            $cardId = (int) $row->user_card_id;

            $lines[$cardId][] = new TransactionLine(
                id: (int) $row->id,
                transactionDate: $row->transaction_date->toDateString(),
                categoryId: $row->category_id === null ? null : (int) $row->category_id,
                amount: (string) $row->amount,
            );
        }

        return $lines;
    }

    /**
     * @param  array<int, CashbackResult>  $results
     */
    private function resultFor(array $results, int $transactionId): ?CashbackResult
    {
        foreach ($results as $result) {
            if ($result->transactionId === $transactionId) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function ineligibleResult(string $reason, ?string $tierName = null): array
    {
        return [
            'tier_name' => $tierName,
            'cashback' => '0.00',
            'rate' => 0.0,
            'eligible' => false,
            'reason' => $reason,
            'cashback_percent' => null,
            'caps_applied' => [],
        ];
    }

    /**
     * Tỷ lệ hoàn tiền THỰC TẾ = tiền hoàn / số tiền nhu cầu (đã trừ cap), không
     * phải rate danh nghĩa của rule.
     */
    private function rate(string $cashback, string $amount): float
    {
        $amountFloat = (float) $amount;

        if ($amountFloat <= 0.0) {
            return 0.0;
        }

        return round((float) $cashback / $amountFloat * 100, 3);
    }

    /**
     * Các loại cap ĐÃ chạm trên giao dịch giả định (để UI giải thích vì sao tiền
     * hoàn thấp hơn công thức danh nghĩa).
     *
     * @return array<int, string>
     */
    private function appliedCaps(?CashbackResult $result): array
    {
        if ($result === null) {
            return [];
        }

        $applied = [];

        foreach ($result->meta['caps'] ?? [] as $cap) {
            if (($cap['applied'] ?? false) === true) {
                $applied[] = (string) $cap['type'];
            }
        }

        return $applied;
    }
}
