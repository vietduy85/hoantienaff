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
 * ---------------------------------------------------------------------------
 * HAI GIẢ ĐỊNH MÔ PHỎNG (đều do engine xác nhận con số cuối cùng)
 * ---------------------------------------------------------------------------
 *   1. CHIA GIAO DỊCH: khi policy có trần theo TỪNG giao dịch, cùng tổng chi
 *      nhưng chia thành nhiều giao dịch nhỏ có thể hoàn nhiều hơn.
 *   2. THANH TOÁN TRỰC TUYẾN: một khoản chi ở danh mục gốc có thể được chia một
 *      phần sang danh mục "online" (nếu thẻ có ưu đãi online), tổng KHÔNG đổi.
 *
 * Mỗi danh mục được thử vài biến thể (giữ nguyên / chia nhỏ / chuyển phần sang
 * online); biến thể tốt nhất theo TOÀN scenario được giữ lại. Khi kết quả phụ
 * thuộc một giả định, scenario gắn cờ tương ứng để tầng trình bày nêu rõ.
 *
 * Giao dịch giả định xếp CUỐI (ngày cuối kỳ với id base cố định) như
 * `CardRecommendationService` để cap được phân bổ theo phần CÒN LẠI sau giao
 * dịch thực của kỳ — đúng ngữ nghĩa "thêm khoản chi này vào kỳ hiện tại".
 */
class CardScenarioEvaluator
{
    private const SPLIT_EPSILON = 0.001;

    public function __construct(
        private readonly TierResolverService $tiers,
        private readonly CashbackCalculator $calculator,
        private readonly SpendQualificationService $qualifications,
        private readonly TransactionSplitter $splitter,
        private readonly OnlinePaymentEligibilityResolver $online,
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

        if ($perCategories === []) {
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

        // Thẻ Cake Freedom dùng quy tắc mô phỏng đơn giản (không dò chia nhỏ giao
        // dịch). Mọi sản phẩm khác giữ nguyên đường đánh giá hiện hành.
        if (CakeFreedomRule::appliesTo($scaffold)) {
            return $this->evaluateCakeFreedom($scaffold, $base, $perCategories);
        }

        $baseline = $this->evaluateSpecs($scaffold, $base, $perCategories, $this->singleSpecs($perCategories), false, false);

        if ($baseline->scenarioReason !== null) {
            return $baseline;
        }

        $chosen = [];
        $dependsOnSplitting = false;
        $dependsOnOnline = false;
        $bestTotal = (float) $baseline->totalCashback;

        foreach ($perCategories as $categoryId => $amount) {
            $categoryId = (int) $categoryId;
            $bestVariant = null;
            $variantSplit = false;
            $variantOnline = false;

            foreach ($this->variantsFor($scaffold, $categoryId, $amount) as $variant) {
                $specs = $this->specsWithOverride($perCategories, $categoryId, $variant['specs']);
                $scenario = $this->evaluateSpecs(
                    $scaffold,
                    $base,
                    $perCategories,
                    $specs,
                    $variant['splitting'],
                    $variant['online'],
                );

                if ($scenario->scenarioReason !== null) {
                    continue;
                }

                if ((float) $scenario->totalCashback > $bestTotal + self::SPLIT_EPSILON) {
                    $bestTotal = (float) $scenario->totalCashback;
                    $bestVariant = $variant['specs'];
                    $variantSplit = $variant['splitting'];
                    $variantOnline = $variant['online'];
                }
            }

            if ($bestVariant !== null) {
                $chosen[$categoryId] = $bestVariant;
                $dependsOnSplitting = $dependsOnSplitting || $variantSplit;
                $dependsOnOnline = $dependsOnOnline || $variantOnline;
            }
        }

        if ($chosen === []) {
            return $baseline;
        }

        $specs = $this->singleSpecs($perCategories);

        foreach ($chosen as $categoryId => $override) {
            $specs = $this->specsWithOverride($perCategories, (int) $categoryId, $override);
        }

        $combined = $this->evaluateSpecs($scaffold, $base, $perCategories, $specs, $dependsOnSplitting, $dependsOnOnline);

        if ($combined->scenarioReason !== null
            || (float) $combined->totalCashback <= (float) $baseline->totalCashback + self::SPLIT_EPSILON) {
            return $baseline;
        }

        return $combined;
    }

    /**
     * Đánh giá thẻ Cake Freedom theo quy tắc mô phỏng ĐƠN GIẢN: mỗi danh mục đủ
     * điều kiện chỉ được phân bổ tối đa `trần danh mục / tỷ lệ`, và hoàn mô phỏng
     * = min(tỷ lệ × chi, trần danh mục). KHÔNG dò tổ hợp chia nhỏ giao dịch.
     *
     * Bậc vẫn resolve theo tổng chi THÔ (tổng chi quyết định bậc); tỷ lệ/trần lấy
     * từ rule của BẬC đã resolve. Ngân sách mô phỏng chính là hệ quả của rule thật
     * (200.000 / 20% = 1.000.000), không hardcode.
     *
     * @param  array<string, mixed>  $base
     * @param  array<int, int>  $perCategories
     */
    private function evaluateCakeFreedom(CardScaffold $scaffold, array $base, array $perCategories): CardScenario
    {
        $hypoLines = [];
        $counter = 0;

        foreach ($perCategories as $categoryId => $amount) {
            $amount = (int) $amount;

            if ($amount <= 0) {
                continue;
            }

            $hypoLines[] = new TransactionLine(
                id: $this->hypoId($scaffold, $counter++),
                transactionDate: $this->hypoDate($scaffold),
                categoryId: (int) $categoryId,
                amount: (string) $amount,
            );
        }

        if ($hypoLines === []) {
            return $this->emptyScenario($scaffold);
        }

        $lines = array_merge($scaffold->realLines, $hypoLines);
        $totalEligible = $this->totalEligibleSpend($scaffold, $lines);
        $tier = $this->tiers->resolveTierFromTiers($scaffold->tiers, $totalEligible);

        if ($tier === null && $totalEligible > 0) {
            return $this->scenarioIneligible($scaffold, $base, 'no_matching_tier', null, $perCategories);
        }

        if ($scaffold->isOwned && ! $this->qualificationPasses($scaffold, $lines)) {
            return $this->scenarioIneligible($scaffold, $base, 'qualification_not_met', $tier, $perCategories);
        }

        $tierId = $tier === null ? -1 : (int) $tier->id;
        $tierRules = $scaffold->rulesByTier[$tierId] ?? [];

        $perCategory = [];
        $total = 0.0;

        foreach ($perCategories as $categoryId => $rawAmount) {
            $categoryId = (int) $categoryId;
            $rawAmount = (int) $rawAmount;
            $rule = $this->cakeRuleFor($tierRules, $categoryId);

            if ($rule === null) {
                $perCategory[$categoryId] = [
                    'amount' => Decimal::money((string) $rawAmount),
                    'cashback' => '0.00',
                    'rate' => 0.0,
                    'percent' => null,
                    'eligible' => false,
                    'reason' => 'category_not_eligible',
                    'caps_applied' => [],
                ];

                continue;
            }

            $percent = (float) $rule['cashback_percent'];
            $capCat = $rule['max_cashback_per_category_per_period'] ?? null;
            $capCat = $capCat === null ? null : (float) $capCat;
            $budget = CakeFreedomRule::categoryBudgetCap([$rule]);
            $modeled = $budget === null ? $rawAmount : (int) min($rawAmount, (int) round($budget));
            $cashback = CakeFreedomRule::categoryCashback($percent, $capCat, (float) $modeled);
            $total += $cashback;

            $perCategory[$categoryId] = [
                'amount' => Decimal::money((string) $modeled),
                'cashback' => Decimal::money(number_format($cashback, 2, '.', '')),
                'rate' => $this->rate(number_format($cashback, 2, '.', ''), $modeled),
                'percent' => $percent,
                'eligible' => true,
                'reason' => null,
                'caps_applied' => $this->cakeAppliedCaps($percent, $capCat, (float) $modeled),
            ];
        }

        $periodCap = $tier?->max_cashback_per_period === null
            ? null
            : (float) $tier->max_cashback_per_period;

        if ($periodCap !== null && $periodCap > 0.0 && $total > $periodCap) {
            [$perCategory, $total] = $this->scaleToPeriodCap($perCategory, $total, $periodCap);
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
            simulatedTransactions: count($hypoLines),
            dependsOnSplitting: false,
            dependsOnOnline: false,
        );
    }

    /**
     * Rule hoàn tiền DƯƠNG khớp một danh mục trong tập rule của bậc. Bỏ qua rule
     * `other` vì ưu đãi Cake gắn với danh mục cụ thể.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, mixed>|null
     */
    private function cakeRuleFor(array $rules, int $categoryId): ?array
    {
        foreach ($rules as $rule) {
            if ((float) ($rule['cashback_percent'] ?? 0.0) <= 0.0) {
                continue;
            }

            if (($rule['scope_type'] ?? 'category') === 'other') {
                continue;
            }

            $members = $rule['combo_category_ids'] ?? [];

            if ($members !== []) {
                if (in_array($categoryId, array_map('intval', $members), true)) {
                    return $rule;
                }

                continue;
            }

            if ((int) ($rule['category_id'] ?? 0) === $categoryId) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function cakeAppliedCaps(float $percent, ?float $capCat, float $modeled): array
    {
        if ($capCat === null || $capCat <= 0.0) {
            return [];
        }

        return $modeled * ($percent / 100.0) > $capCat + self::SPLIT_EPSILON
            ? ['category']
            : [];
    }

    /**
     * Co giãn hoàn tiền các danh mục theo tỷ lệ để tổng khớp trần kỳ.
     *
     * @param  array<int, array<string, mixed>>  $perCategory
     * @return array{0: array<int, array<string, mixed>>, 1: float}
     */
    private function scaleToPeriodCap(array $perCategory, float $total, float $periodCap): array
    {
        $factor = $periodCap / $total;
        $scaled = 0.0;

        foreach ($perCategory as $categoryId => $detail) {
            $cashback = (float) $detail['cashback'] * $factor;
            $scaled += $cashback;

            $perCategory[$categoryId]['cashback'] = Decimal::money(number_format($cashback, 2, '.', ''));
            $perCategory[$categoryId]['rate'] = $this->rate(number_format($cashback, 2, '.', ''), (int) $detail['amount']);
        }

        return [$perCategory, $scaled];
    }

    /**
     * Các biến thể dòng cho MỘT danh mục (giữ nguyên không nằm trong đây vì nó là
     * baseline). Bao gồm chia nhỏ (theo trần giao dịch) và chuyển phần sang online.
     *
     * @return list<array{specs: list<array{category_id:int,original:int,amount:int}>,splitting:bool,online:bool}>
     */
    private function variantsFor(CardScaffold $scaffold, int $categoryId, int $amount): array
    {
        $variants = [];

        if ($amount <= 0) {
            return $variants;
        }

        if ($this->splitter->hasPotential($scaffold) && $this->splitter->supportsCategory($scaffold, $categoryId)) {
            foreach ($this->splitter->candidateChunkSizes($scaffold, $categoryId, $amount) as $size) {
                if ($size >= $amount) {
                    continue;
                }

                $specs = [];

                foreach ($this->splitter->splitInto($amount, $size) as $chunk) {
                    $specs[] = ['category_id' => $categoryId, 'original' => $categoryId, 'amount' => $chunk];
                }

                $variants[] = ['specs' => $specs, 'splitting' => true, 'online' => false];
            }
        }

        $onlineId = $this->online->targetForBase($categoryId);

        if ($onlineId !== null && $this->online->coversCategory($scaffold, $onlineId)) {
            foreach ($this->online->baseFractions() as $fraction) {
                $baseAmount = (int) floor($amount * $fraction);
                $onlineAmount = $amount - $baseAmount;

                if ($onlineAmount <= 0 || $baseAmount >= $amount) {
                    continue;
                }

                $specs = [];

                if ($baseAmount > 0) {
                    $specs[] = ['category_id' => $categoryId, 'original' => $categoryId, 'amount' => $baseAmount];
                }

                $specs[] = ['category_id' => $onlineId, 'original' => $categoryId, 'amount' => $onlineAmount];

                $variants[] = ['specs' => $specs, 'splitting' => false, 'online' => true];
            }
        }

        return $variants;
    }

    /**
     * @param  array<int, int>  $perCategories
     * @return list<array{category_id:int,original:int,amount:int}>
     */
    private function singleSpecs(array $perCategories): array
    {
        $specs = [];

        foreach ($perCategories as $categoryId => $amount) {
            $specs[] = ['category_id' => (int) $categoryId, 'original' => (int) $categoryId, 'amount' => (int) $amount];
        }

        return $specs;
    }

    /**
     * @param  array<int, int>  $perCategories
     * @param  list<array{category_id:int,original:int,amount:int}>  $override
     * @return list<array{category_id:int,original:int,amount:int}>
     */
    private function specsWithOverride(array $perCategories, int $categoryId, array $override): array
    {
        $specs = [];

        foreach ($perCategories as $id => $amount) {
            $id = (int) $id;

            if ($id === $categoryId) {
                foreach ($override as $spec) {
                    $specs[] = $spec;
                }

                continue;
            }

            $specs[] = ['category_id' => $id, 'original' => $id, 'amount' => (int) $amount];
        }

        return $specs;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<int, int>  $perCategories
     * @param  list<array{category_id:int,original:int,amount:int}>  $specs
     */
    private function evaluateSpecs(
        CardScaffold $scaffold,
        array $base,
        array $perCategories,
        array $specs,
        bool $dependsOnSplitting,
        bool $dependsOnOnline,
    ): CardScenario {
        $hypoLines = [];
        $counter = 0;

        foreach ($specs as $spec) {
            $amount = (int) $spec['amount'];

            if ($amount <= 0) {
                continue;
            }

            $hypoLines[] = [
                'line' => new TransactionLine(
                    id: $this->hypoId($scaffold, $counter++),
                    transactionDate: $this->hypoDate($scaffold),
                    categoryId: (int) $spec['category_id'],
                    amount: (string) $amount,
                ),
                'original' => (int) $spec['original'],
            ];
        }

        if ($hypoLines === []) {
            return $this->emptyScenario($scaffold);
        }

        $lines = array_merge($scaffold->realLines, array_map(fn (array $entry): TransactionLine => $entry['line'], $hypoLines));
        $totalEligible = $this->totalEligibleSpend($scaffold, $lines);

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
            $categoryId = (int) $categoryId;
            $sum = 0.0;
            $eligible = false;
            $reason = null;
            $percent = null;
            $caps = [];

            foreach ($hypoLines as $entry) {
                if ($entry['original'] !== $categoryId) {
                    continue;
                }

                $result = $this->resultFor($results, $entry['line']->id);
                $sum += (float) ($result?->cashbackAmount ?? 0.0);

                if ($result?->isEligible) {
                    $eligible = true;
                } else {
                    $reason ??= $result?->ineligibleReason;
                }

                $percent ??= $result?->cashbackPercent;

                foreach ($this->appliedCaps($result) as $capType) {
                    $caps[$capType] = true;
                }
            }

            $cashback = number_format($sum, 2, '.', '');
            $total += $sum;

            $perCategory[$categoryId] = [
                'amount' => Decimal::money((string) $amount),
                'cashback' => Decimal::money($cashback),
                'rate' => $this->rate($cashback, $amount),
                'percent' => $percent,
                'eligible' => $eligible,
                'reason' => $reason,
                'caps_applied' => array_keys($caps),
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
            simulatedTransactions: count($hypoLines),
            dependsOnSplitting: $dependsOnSplitting,
            dependsOnOnline: $dependsOnOnline,
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

    private function hypoId(CardScaffold $scaffold, int $lineIndex): int
    {
        return CardScaffold::hypoIdBase($scaffold->isOwned) + $scaffold->idNumber * 100000 + $lineIndex;
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
