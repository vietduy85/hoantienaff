<?php

namespace App\Services\CreditCard\Optimizer;

use App\Services\CreditCard\CashbackCalculator;
use App\Services\CreditCard\CashbackResult;
use App\Services\CreditCard\SpendQualificationService;
use App\Services\CreditCard\TierResolverService;
use App\Services\CreditCard\TransactionLine;
use App\Support\CreditCard\Decimal;
use Carbon\CarbonImmutable;

/**
 * CardScenarioEvaluator — đánh giá một thẻ với một phân bổ chi tiêu.
 *
 * Đây là tầng DUY NHẤT chạy engine (`CashbackCalculator`) cho từng thẻ trong bộ
 * tối ưu. Toàn bộ luật (tier retroactive, gate min_total_spend, cap giao dịch /
 * danh mục / kỳ, combo danh mục, điều kiện đặc biệt cho thẻ của tôi) đều do
 * engine hiện hành quyết định — lớp này KHÔNG nhân rate, KHÔNG chọn rule, KHÔNG
 * tự tính cap.
 *
 * Giao dịch giả định xếp CUỐI (ngày cuối kỳ với id base cố định) như
 * `CardRecommendationService` để cap được phân bổ theo phần CÒN LẠI sau giao
 * dịch thực của kỳ — đúng ngữ nghĩa "thêm khoản chi này vào kỳ hiện tại".
 */
class CardScenarioEvaluator
{
    public function __construct(
        private readonly TierResolverService $tiers,
        private readonly CashbackCalculator $calculator,
        private readonly SpendQualificationService $qualifications,
    ) {}

    /**
     * Đánh giá thẻ `$scaffold` với phân bổ `$assignment` (categoryId => số tiền).
     *
     * @param  array<int, int>  $assignment
     */
    public function evaluate(CardScaffold $scaffold, array $assignment): CardScenario
    {
        $perCategories = [];

        foreach ($assignment as $categoryId => $amount) {
            $amount = (int) $amount;

            if ($amount > 0) {
                $perCategories[(int) $categoryId] = $amount;
            }
        }

        $hypoIds = [];
        $hypoLines = [];

        foreach ($perCategories as $categoryId => $amount) {
            $id = $this->hypoId($scaffold, (int) $categoryId);
            $hypoIds[(int) $categoryId] = $id;
            $hypoLines[] = new TransactionLine(
                id: $id,
                transactionDate: $this->hypoDate($scaffold),
                categoryId: (int) $categoryId,
                amount: (string) $amount,
            );
        }

        if ($hypoLines === []) {
            return $this->emptyScenario($scaffold);
        }

        $base = [
            'key' => $scaffold->key,
            'is_owned' => $scaffold->isOwned,
            'name' => $scaffold->name,
            'bank_name' => $scaffold->bankName,
            'card_id' => $scaffold->cardId,
            'template_id' => $scaffold->templateId,
        ];

        if ($scaffold->version === null) {
            return $this->scenarioIneligible($scaffold, $base, 'no_policy_version', null, $perCategories);
        }

        $lines = array_merge($scaffold->realLines, $hypoLines);
        $totalEligible = $this->totalEligibleSpend($scaffold, $lines);

        // Retroactive: tổng KHÔNG phụ thuộc bậc, bậc phụ thuộc tổng.
        $tier = $this->tiers->resolveTierFromTiers($scaffold->tiers, $totalEligible);

        if ($tier === null && $totalEligible > 0) {
            return $this->scenarioIneligible($scaffold, $base, 'no_matching_tier', null, $perCategories);
        }

        if ($scaffold->isOwned && ! $this->qualificationPasses($scaffold, $lines)) {
            return $this->scenarioIneligible($scaffold, $base, 'qualification_not_met', $tier, $perCategories);
        }

        $tierId = $tier === null ? -1 : (int) $tier->id;

        $results = $this->calculator->calculate(
            rules: $scaffold->rulesByTier[$tierId] ?? [],
            transactions: $lines,
            minTotalSpend: $scaffold->minTotalSpend,
            maxCashbackPerPeriod: $tier?->max_cashback_per_period === null
                ? null
                : (float) $tier->max_cashback_per_period,
            roundingMode: $scaffold->roundingMode,
            transactionCaps: $scaffold->capsByTier[$tierId] ?? [],
        );

        $total = 0.0;
        $perCategory = [];

        foreach ($perCategories as $categoryId => $amount) {
            $result = $this->resultFor($results, $hypoIds[(int) $categoryId]);
            $cashback = (float) ($result?->cashbackAmount ?? 0.0);
            $total += $cashback;

            $perCategory[(int) $categoryId] = [
                'amount' => Decimal::money((string) $amount),
                'cashback' => Decimal::money($result?->cashbackAmount ?? '0.00'),
                'rate' => $this->rate($result?->cashbackAmount ?? '0.00', $amount),
                'percent' => $result?->cashbackPercent,
                'eligible' => $result?->isEligible ?? false,
                'reason' => $result?->ineligibleReason,
                'caps_applied' => $this->appliedCaps($result),
            ];
        }

        return new CardScenario(
            key: $base['key'],
            isOwned: $base['is_owned'],
            name: $base['name'],
            bankName: $base['bank_name'],
            cardId: $base['card_id'],
            templateId: $base['template_id'],
            totalCashback: Decimal::money(number_format($total, 2, '.', '')),
            perCategory: $perCategory,
            scenarioReason: null,
            tierLabel: $this->tierLabel($tier),
            tierMinTotalSpend: $tier === null
                ? null
                : Decimal::money((string) $tier->min_total_spend),
            minTotalSpendMet: $scaffold->minTotalSpend <= $totalEligible,
            qualificationNotice: $scaffold->qualificationNotice,
            marketAssumption: $scaffold->marketAssumption,
            instanceIndex: $scaffold->instanceIndex,
            productKey: $scaffold->productKey,
        );
    }

    /**
     * Trường hợp version rỗng / chưa có policy → trả scenario rỗng (0 đồng).
     */
    private function emptyScenario(CardScaffold $scaffold): CardScenario
    {
        return new CardScenario(
            key: $scaffold->key,
            isOwned: $scaffold->isOwned,
            name: $scaffold->name,
            bankName: $scaffold->bankName,
            cardId: $scaffold->cardId,
            templateId: $scaffold->templateId,
            totalCashback: '0.00',
            perCategory: [],
            scenarioReason: null,
            tierLabel: null,
            tierMinTotalSpend: null,
            minTotalSpendMet: false,
            qualificationNotice: $scaffold->qualificationNotice,
            marketAssumption: $scaffold->marketAssumption,
            instanceIndex: $scaffold->instanceIndex,
            productKey: $scaffold->productKey,
        );
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<int, int>  $perCategories
     */
    private function scenarioIneligible(
        CardScaffold $scaffold,
        array $base,
        string $reason,
        $tier,
        array $perCategories,
    ): CardScenario {
        $perCategory = [];

        foreach ($perCategories as $categoryId => $amount) {
            $perCategory[(int) $categoryId] = [
                'amount' => Decimal::money((string) $amount),
                'cashback' => '0.00',
                'rate' => 0.0,
                'percent' => null,
                'eligible' => false,
                'reason' => $reason,
                'caps_applied' => [],
            ];
        }

        return new CardScenario(
            key: $base['key'],
            isOwned: $base['is_owned'],
            name: $base['name'],
            bankName: $base['bank_name'],
            cardId: $base['card_id'],
            templateId: $base['template_id'],
            totalCashback: '0.00',
            perCategory: $perCategory,
            scenarioReason: $reason,
            tierLabel: $this->tierLabel($tier),
            tierMinTotalSpend: $tier === null
                ? null
                : Decimal::money((string) $tier->min_total_spend),
            minTotalSpendMet: false,
            qualificationNotice: $scaffold->qualificationNotice,
            marketAssumption: $scaffold->marketAssumption,
            instanceIndex: $scaffold->instanceIndex,
            productKey: $scaffold->productKey,
        );
    }

    /**
     * Tổng eligible spend của tập dòng (đầu vào resolve bậc), đúng ngữ nghĩa
     * `CardRecommendationService::totalEligibleSpend()` nhưng tái sử dụng memo
     * `allEnabledRules` của scaffold — không query DB.
     *
     * @param  list<TransactionLine>  $lines
     */
    private function totalEligibleSpend(CardScaffold $scaffold, array $lines): float
    {
        if ($lines === []) {
            return 0.0;
        }

        $results = $this->calculator->calculate(
            rules: $scaffold->allEnabledRules,
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

    private function hypoId(CardScaffold $scaffold, int $categoryId): int
    {
        return CardScaffold::hypoIdBase($scaffold->isOwned) + $scaffold->idNumber * 1000 + $categoryId;
    }

    private function hypoDate(CardScaffold $scaffold): string
    {
        $date = $scaffold->isOwned && $scaffold->periodEnd !== null
            ? $scaffold->periodEnd
            : CarbonImmutable::now();

        return $date->toDateString();
    }

    /**
     * @param  list<TransactionLine>  $lines
     */
    private function qualificationPasses(CardScaffold $scaffold, array $lines): bool
    {
        if ($scaffold->qualification === null) {
            return true;
        }

        $summary = $this->qualifications->summaryForPeriod($scaffold->qualification, collect($lines));

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

    private function tierLabel($tier): ?string
    {
        if ($tier === null) {
            return null;
        }

        $name = trim((string) $tier->name);

        if ($name === '') {
            return null;
        }

        if (preg_match('/^(bậc|tier)\b/iu', $name) === 1) {
            return $name;
        }

        return 'Bậc '.$name;
    }

    private function rate(string $cashback, int $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        return round((float) $cashback / $amount * 100, 3);
    }

    /**
     * @return list<string>
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