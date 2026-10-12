<?php

namespace App\Services\CreditCard\Optimizer;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyVersion;
use App\Models\CreditCard\SpendQualification;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Services\CreditCard\PolicyEngineService;
use App\Services\CreditCard\StatementPeriodService;
use App\Services\CreditCard\TierResolverService;
use App\Services\CreditCard\TransactionLine;
use App\Support\CreditCard\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * PortfolioOptimizerService — "Tối ưu bộ thẻ hoàn tiền".
 *
 * Người dùng nhập chi tiêu theo danh mục/tháng; hệ thống phân bổ các danh mục
 * vào một TỔ HỢP thẻ (cho phép tách một danh mục cho nhiều thẻ) và trả về TỐI ĐA
 * 3 phương án thực khác nhau.
 *
 * ---------------------------------------------------------------------------
 * CHỈ ĐỌC + TÁI SỬ DỤNG ENGINE HIỆN HÀNH
 * ---------------------------------------------------------------------------
 * Lớp này KHÔNG ghi DB. Mọi giá trị hoàn tiền đều do `CardScenarioEvaluator`
 * (đi qua đúng `CashbackCalculator`) tính trên các giao dịch GIẢ ĐỊNH hợp thành
 * tập dòng của kỳ — vì vậy cap giao dịch/danh mục/kỳ và tiêu thụ của giao dịch
 * thực trong kỳ hiện tại của thẻ đều được phản ánh, đúng ngữ nghĩa của tab
 * "Lựa chọn thẻ". Không gọi `CashbackQuotaService` riêng (engine đã trừ phần đã
 * dùng trong kỳ qua các dòng thực).
 *
 * ---------------------------------------------------------------------------
 * SINH PHƯƠNG ÁN THEO TIÊU CHÍ ĐANG CHỌN
 * ---------------------------------------------------------------------------
 * Mỗi tiêu chí kéo một bộ "config sinh" (thứ tự phân loại danh mục + định kiến
 * xếp hạng ứng viên + ngưỡng chấp nhận hy sinh nhỏ). Các config đó cho ra các
 * phương án khác nhau; fingerprint so sánh theo phân bổ để CẮT TỐI ĐA 3 phương
 * án DISTINCT, rồi xếp hạng theo ĐÚNG tiêu chí đang chọn.
 */
class PortfolioOptimizerService
{
    public const MAX_PLANS = 3;

    /**
     * Dung sai (đồng) cho phép hy sinh khi chuyển danh mục giữa các thẻ vì lý do
     * tiêu chí — giữ nhỏ (≤ 2‰ tổng) để không bao giờ đánh đổi hoàn tiền đáng kể.
     */
    private const BALANCE_TOLERANCE_RATIO = 0.002;

    public function __construct(
        private readonly StatementPeriodService $periods,
        private readonly PolicyEngineService $engine,
        private readonly TierResolverService $tiers,
        private readonly CardScenarioEvaluator $evaluator,
    ) {}

    /**
     * @param  array<int, int>  $amounts  categoryId => chi tiêu dự kiến (VND)
     * @return array<string, mixed>
     */
    public function optimize(int $userId, string $scope, string $criterion, array $amounts): array
    {
        $amounts = $this->normalizeAmounts($amounts);

        $candidates = $this->buildCandidates($userId, $scope);
        $positiveMap = [];

        foreach ($candidates as $key => $scaffold) {
            $positiveMap[$key] = $this->positiveMap($scaffold);
        }

        $singles = $this->singlesCache($candidates, $positiveMap, $amounts);

        $configs = $this->configsFor($criterion, $amounts);

        $plans = [];

        foreach ($configs as $config) {
            $allocation = $this->run($candidates, $positiveMap, $singles, $amounts, $config);

            if ($allocation === null) {
                continue;
            }

            $plan = $this->planFrom($candidates, $allocation);

            if ($plan !== null) {
                $plans[] = $plan;
            }
        }

        $distinct = $this->distinct($plans);
        $distinct = $this->rank($distinct, $criterion);

        if (count($distinct) > self::MAX_PLANS) {
            $distinct = array_slice($distinct, 0, self::MAX_PLANS);
        }

        $rendered = [];

        foreach ($distinct as $index => $plan) {
            $rendered[] = $this->renderPlan($criterion, $plan, $index + 1);
        }

        return [
            'scope' => $scope,
            'criterion' => $criterion,
            'criterion_label' => OptimizationCriterion::labels()[$criterion],
            'total_input' => Decimal::money((string) array_sum($amounts)),
            'total_input_number' => array_sum($amounts),
            'plans' => $rendered,
            'notes' => $this->planNotes($candidates, $positiveMap, $amounts, $distinct),
        ];
    }

    // ---------------------------------------------------------------------
    // Candidate pool
    // ---------------------------------------------------------------------

    /**
     * Xây danh sách ứng viên theo phạm vi. Chỉ giữ lại thẻ CÓ thể đánh giá được
     * (có policy version) — thẻ không có policy bị loại khỏi tổ hợp.
     *
     * @return array<string, CardScaffold>
     */
    private function buildCandidates(int $userId, string $scope): array
    {
        $candidates = [];

        if ($scope === OptimizerScope::OWNED || $scope === OptimizerScope::COMPARE) {
            foreach ($this->ownedScaffolds($userId) as $scaffold) {
                $candidates[$scaffold->key] = $scaffold;
            }
        }

        if ($scope === OptimizerScope::ALL || $scope === OptimizerScope::COMPARE) {
            $this->mergeDistinct($candidates, $this->marketScaffolds());
        }

        return $candidates;
    }

    /**
     * @return array<string, CardScaffold>
     */
    private function ownedScaffolds(int $userId): array
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

        $boundaries = [];

        foreach ($cards as $card) {
            $boundaries[(int) $card->id] = $this->periods->currentBoundaries($card, $today);
        }

        $realLines = $this->transactionLinesByCard($boundaries);
        $todayIso = $today->toDateString();

        $scaffolds = [];

        foreach ($cards as $card) {
            $cardId = (int) $card->id;
            $version = $this->engine->resolveFor($card, $today)?->policyVersion;

            if ($version === null) {
                continue;
            }

            [$start, $end] = $boundaries[$cardId];

            $qualification = SpendQualification::query()
                ->where('policy_version_id', $version->id)
                ->where('enabled', true)
                ->with(['conditions.category', 'conditions.excludedCategories'])
                ->first();

            $scaffolds[$this->ownedKey($cardId)] = new CardScaffold(
                key: $this->ownedKey($cardId),
                isOwned: true,
                name: (string) $card->name,
                bankName: $card->bank?->name,
                cardId: $cardId,
                templateId: null,
                version: $version,
                tiers: $this->tiers->tiersOfVersion($version),
                rulesByTier: $this->rulesByTier($version),
                capsByTier: $this->capsByTier($version),
                allEnabledRules: $this->tiers->allEnabledRulesFor($version),
                realLines: $realLines[$cardId] ?? [],
                periodEnd: $end,
                minTotalSpend: (float) $version->min_total_spend,
                roundingMode: (string) $version->rounding_mode,
                qualification: $qualification,
                qualificationNotice: $qualification === null ? null : 'Thẻ có điều kiện hoàn tiền đặc biệt: kết quả đã xác nhận theo giả định chi tiêu của kỳ.',
                marketAssumption: '',
                idNumber: $cardId,
            );
        }

        return $scaffolds;
    }

    /**
     * @return array<string, CardScaffold>
     */
    private function marketScaffolds(): array
    {
        $templates = PolicyTemplate::query()
            ->system()
            ->where('is_active', true)
            ->with('applicableBank')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $scaffolds = [];

        foreach ($templates as $template) {
            $version = $template->defaultBlueprint();

            if ($version === null) {
                continue;
            }

            $hasCondition = SpendQualification::query()
                ->where('policy_version_id', $version->id)
                ->where('enabled', true)
                ->exists();

            $now = CarbonImmutable::now();

            $scaffolds[$this->marketKey((int) $template->id)] = new CardScaffold(
                key: $this->marketKey((int) $template->id),
                isOwned: false,
                name: (string) $template->name,
                bankName: $template->applicableBank?->name,
                cardId: null,
                templateId: (int) $template->id,
                version: $version,
                tiers: $this->tiers->tiersOfVersion($version),
                rulesByTier: $this->rulesByTier($version),
                capsByTier: $this->capsByTier($version),
                allEnabledRules: $this->tiers->allEnabledRulesFor($version),
                realLines: [],
                periodEnd: null,
                minTotalSpend: (float) $version->min_total_spend,
                roundingMode: (string) $version->rounding_mode,
                qualification: null,
                qualificationNotice: $hasCondition ? 'Thẻ có điều kiện hoàn tiền đặc biệt: kết quả chưa xác nhận bạn đủ điều kiện.' : null,
                marketAssumption: 'Mô phỏng thẻ mới chưa có chi tiêu trong kỳ; bậc áp dụng sẽ phụ thuộc tổng chi thực tế khi bạn mở và dùng thẻ.',
                idNumber: (int) $template->id,
            );
        }

        return $scaffolds;
    }

    /**
     * @param  array<string, CardScaffold>  $target
     * @param  array<string, CardScaffold>  $source
     */
    private function mergeDistinct(array &$target, array $source): void
    {
        foreach ($source as $key => $scaffold) {
            $target[$key] = $scaffold;
        }
    }

    /**
     * Rule của từng bậc + cap giao dịch, gom sẵn để evaluator không query.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function rulesByTier(PolicyVersion $version): array
    {
        $map = [];

        foreach ($this->tiers->tiersOfVersion($version) as $tier) {
            $map[(int) $tier->id] = $this->tiers->rulesForTier($tier);
        }

        return $map;
    }

    /**
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function capsByTier(PolicyVersion $version): array
    {
        $map = [];

        foreach ($this->tiers->tiersOfVersion($version) as $tier) {
            $map[(int) $tier->id] = $this->tiers->transactionCapsForTier($tier);
        }

        return $map;
    }

    /**
     * Giao dịch THỰC của kỳ hiện tại từng thẻ (MỘT query cho mọi thẻ, thứ tự
     * `chronological()` đúng như engine phân bổ cap).
     *
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $boundsByCard
     * @return array<int, list<TransactionLine>>
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
     * Bản đồ "danh mục nào thẻ này có MỘT rule dương nào đó" — dùng để bỏ qua
     * đánh giá các cặp thẻ×danh mục hiển nhiên bằng 0.
     *
     * @return array{any_all: bool, cats: array<int, true>}
     */
    private function positiveMap(CardScaffold $scaffold): array
    {
        $cats = [];
        $anyAll = false;

        foreach ($scaffold->allEnabledRules as $rule) {
            if ((float) $rule['cashback_percent'] <= 0.0) {
                continue;
            }

            if (($rule['scope_type'] ?? 'category') === 'other') {
                $anyAll = true;
                continue;
            }

            $members = $rule['combo_category_ids'] ?? [];

            if ($members !== []) {
                foreach ($members as $memberId) {
                    $cats[(int) $memberId] = true;
                }

                continue;
            }

            if ((int) $rule['category_id'] > 0) {
                $cats[(int) $rule['category_id']] = true;
            }
        }

        return ['any_all' => $anyAll, 'cats' => $cats];
    }

    private function isPositive(array $map, int $categoryId): bool
    {
        return $map['any_all'] || isset($map['cats'][$categoryId]);
    }

    // ---------------------------------------------------------------------
    // Single-category cache
    // ---------------------------------------------------------------------

    /**
     * Cache "giao danh mục này nguyên số tiền cho thẻ này được bao nhiêu" — dùng
     * để xếp hạng ứng viên khi gieo phân bổ ban đầu. Giá trị có bậc theo quy tắc
     * retroactive của engine ngay từ bước này (thực chất là một lần
     * `evaluate()` với đúng tập dòng của thẻ).
     *
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array{any_all: bool, cats: array<int, true>}>  $positiveMap
     * @param  array<int, int>  $amounts
     * @return array<string, array<int, array{value: float, tier_min: float|null, percent: float|null}>>
     */
    private function singlesCache(array $candidates, array $positiveMap, array $amounts): array
    {
        $singles = [];

        foreach ($candidates as $key => $scaffold) {
            $singles[$key] = [];

            foreach ($amounts as $categoryId => $amount) {
                if (! $this->isPositive($positiveMap[$key], (int) $categoryId)) {
                    $singles[$key][(int) $categoryId] = [
                        'value' => 0.0,
                        'tier_min' => null,
                        'percent' => null,
                    ];

                    continue;
                }

                $scenario = $this->evaluator->evaluate($scaffold, [(int) $categoryId => (int) $amount]);
                $row = $scenario->perCategory[(int) $categoryId] ?? null;

                $singles[$key][(int) $categoryId] = [
                    'value' => (float) ($row['cashback'] ?? 0.0),
                    'tier_min' => $scenario->tierMinTotalSpend === null
                        ? null
                        : (float) $scenario->tierMinTotalSpend,
                    'percent' => $row['percent'] === null ? null : (float) $row['percent'],
                ];
            }
        }

        return $singles;
    }

    // ---------------------------------------------------------------------
    // Plan search
    // ---------------------------------------------------------------------

    /**
     * Chạy MỘT cấu hình sinh: gieo phân bổ theo thứ tự danh mục + định kiến,
     * rồi cải thiện cục bộ từng danh mục (kể cả thử tách cho hai thẻ).
     *
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array{any_all: bool, cats: array<int, true>}>  $positiveMap
     * @param  array<string, array<int, array<string, mixed>>>  $singles
     * @param  array<int, int>  $amounts
     * @param  array{order: list<int>, criterion: string, prefer_owned: bool, prefer_market: bool, spread: bool}  $config
     * @return array<string, array<int, int>>|null  cardKey => (categoryId => amount)
     */
    private function run(array $candidates, array $positiveMap, array $singles, array $amounts, array $config): ?array
    {
        if ($candidates === []) {
            return null;
        }

        $allocation = [];

        // Gieo: mỗi danh mục giao NGUYÊN cho ứng viên điểm cao nhất theo tiêu chí.
        foreach ($config['order'] as $categoryId) {
            $amount = (int) $amounts[$categoryId];

            if ($amount <= 0) {
                continue;
            }

            $bestKey = $this->bestSeedKey($config, $candidates, $positiveMap, $singles, $allocation, (int) $categoryId, $amount);

            if ($bestKey === null) {
                continue;
            }

            $allocation[$bestKey][(int) $categoryId] = $amount;
        }

        if ($allocation === []) {
            return null;
        }

        // Cải thiện cục bộ: lặp lại theo thứ tự danh mục, mỗi lượt thử
        // chuyển/tách cho từng danh mục.
        $evalCache = [];

        for ($pass = 0; $pass < 2; $pass++) {
            $improved = false;

            foreach ($config['order'] as $categoryId) {
                $changed = $this->improveCategory(
                    config: $config,
                    candidates: $candidates,
                    positiveMap: $positiveMap,
                    singles: $singles,
                    amounts: $amounts,
                    allocation: $allocation,
                    evalCache: $evalCache,
                    categoryId: (int) $categoryId,
                );

                $improved = $improved || $changed;
            }

            if (! $improved) {
                break;
            }
        }

        return $allocation;
    }

    /**
     * @param  array<string, array<int, int>>  $allocation
     */
    private function bestSeedKey(
        array $config,
        array $candidates,
        array $positiveMap,
        array $singles,
        array $allocation,
        int $categoryId,
        int $amount,
    ): ?string {
        $heldCounts = $this->heldCounts($allocation);
        $scoreOf = function (?string $key) use ($singles, $candidates, $heldCounts, $categoryId, $amount, $config): float {
            if ($key === null) {
                return PHP_FLOAT_MIN;
            }

            return $this->preferScore(
                $config['criterion'],
                $key,
                $singles[$key][$categoryId] ?? ['value' => 0.0, 'tier_min' => null, 'percent' => null],
                $heldCounts[$key] ?? 0,
                $candidates[$key],
            );
        };

        $best = null;
        $bestScore = PHP_FLOAT_MIN;

        foreach ($candidates as $key => $scaffold) {
            $positive = $this->isPositive($positiveMap[$key], $categoryId);

            if ($config['prefer_owned'] && $scaffold->isOwned && ! $positive) {
                // "Thẻ của tôi trước": thẻ riêng có thể nhận từng danh mục dù 0%.
                $score = $scoreOf($key) - 100.0;
            } else {
                $score = $scoreOf($key);
            }

            if ($score > $bestScore) {
                $best = $key;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Số danh mục đang giữ của từng thẻ.
     *
     * @param  array<string, array<int, int>>  $allocation
     * @return array<string, int>
     */
    private function heldCounts(array $allocation): array
    {
        $counts = [];

        foreach ($allocation as $key => $cats) {
            $counts[$key] = count(array_filter($cats, fn ($amount): bool => (int) $amount > 0));
        }

        return $counts;
    }

    /**
     * Điểm ưu tiên của một ứng viên cho một danh mục — giá trị hoàn tiền cộng
     * định kiến theo tiêu chí (định kiến chỉ đóng vai trò thứ tự gieo/tie-break).
     *
     * @param  array<string, mixed>  $single
     */
    private function preferScore(
        string $criterion,
        string $key,
        array $single,
        int $catsHeld,
        CardScaffold $scaffold,
    ): float {
        $value = (float) $single['value'];

        switch ($criterion) {
            case OptimizationCriterion::FEWEST_CARDS:
                // Thưởng khi danh mục mới gom vào thẻ đang dùng (giảm số thẻ).
                return $value + ($catsHeld > 0 ? 0.02 * $value : 0.0);

            case OptimizationCriterion::MY_CARDS:
                if ($scaffold->isOwned) {
                    return $value + 0.10 * $value;
                }

                return $value - 0.15 * $value;

            case OptimizationCriterion::EASY_CONDITIONS:
                $tierMin = $single['tier_min'];

                if ($tierMin !== null && $tierMin <= 0.0) {
                    return $value + 0.08 * $value;
                }

                return $value;

            case OptimizationCriterion::NEW_CARDS:
                if (! $scaffold->isOwned) {
                    return $value + 0.10 * $value;
                }

                return $value - 0.05 * $value;

            case OptimizationCriterion::BALANCE:
            default:
                return $value;
        }
    }

    /**
     * Cải thiện cục bộ một danh mục: thử chuyển hết sang từng ứng viên đáng giá
     * và thử tách cho hai thẻ, giữ phương án điểm cao (ưu tiên hoàn tiền, chỉ hy
     * sinh trong dung sai vì tiêu chí).
     *
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array{any_all: bool, cats: array<int, true>}>  $positiveMap
     * @param  array<string, array<int, array<string, mixed>>>  $singles
     * @param  array<int, int>  $amounts
     * @param  array<string, array<int, int>>  $allocation  cập nhật TẠI CHỖ khi tìm được phương án tốt hơn
     * @param  array<string, array<string, CardScenario>>  $evalCache
     */
    private function improveCategory(
        array $config,
        array $candidates,
        array $positiveMap,
        array $singles,
        array $amounts,
        array &$allocation,
        array &$evalCache,
        int $categoryId,
    ): bool {
        $total = (int) ($amounts[$categoryId] ?? 0);

        if ($total <= 0) {
            return false;
        }

        $holders = [];

        foreach ($allocation as $key => $cats) {
            if (((int) ($cats[$categoryId] ?? 0)) > 0) {
                $holders[] = $key;
            }
        }

        $currentMetrics = $this->allocationValue($candidates, $allocation, $evalCache);
        $currentValue = $currentMetrics['total'];

        // Quan sát hiện tại (giữ nguyên) làm mốc.
        $bestConfig = [
            'metrics' => $currentMetrics,
            'allocation' => $allocation,
        ];

        $candidatesByScore = $this->candidateKeysByScore(
            $config, $candidates, $positiveMap, $singles, $allocation, $categoryId, $total,
        );

        // Giới hạn số ứng viên thử (3 điểm cao nhất + các thẻ đang giữ) để giữ chi
        // phí đánh giá ổn định khi có nhiều thẻ trong phạm vi.
        $shortlisted = array_slice($candidatesByScore, 0, 3, true);

        foreach ($holders as $holderKey) {
            if (isset($shortlisted[$holderKey])) {
                continue;
            }

            $shortlisted[$holderKey] = $shortlisted[$holderKey] ?? -1.0;
        }

        $tol = max(1000.0, $currentValue * self::BALANCE_TOLERANCE_RATIO);

        // 1) Thử giao toàn bộ cho từng ứng viên.
        foreach ($shortlisted as $targetKey => $weight) {
            $moved = $allocation;

            foreach ($holders as $holderKey) {
                unset($moved[$holderKey][$categoryId]);

                if (($moved[$holderKey] ?? []) === []) {
                    unset($moved[$holderKey]);
                }
            }

            $moved[$targetKey][$categoryId] = $total;

            $metrics = $this->allocationValue($candidates, $moved, $evalCache);

            if ($this->prefers($config, $metrics, $moved, $bestConfig['metrics'], $bestConfig['allocation'], $tol)) {
                $bestConfig = ['metrics' => $metrics, 'allocation' => $moved];
            }
        }

        // 2) Nếu tiêu chí cho phép tách, thử tách danh mục cho HAI thẻ.
        $shouldTrySplit = ! $config['focus_on_one'] && count($shortlisted) > 1;

        if ($shouldTrySplit) {
            $keys = array_keys($shortlisted);
            $first = $keys[0];
            $second = $keys[1] ?? null;

            foreach (self::splitPortions($total) as $portion) {
                $split = $allocation;

                foreach ($holders as $holderKey) {
                    unset($split[$holderKey][$categoryId]);

                    if (($split[$holderKey] ?? []) === []) {
                        unset($split[$holderKey]);
                    }
                }

                $a = $total - $portion;
                $b = $portion;

                if ($a > 0) {
                    $split[$first][$categoryId] = $a;
                }

                if ($second !== null && $b > 0) {
                    $split[$second][$categoryId] = $b;
                } elseif ($b > 0) {
                    $split[$first][$categoryId] = $a + $b;
                }

                $metrics = $this->allocationValue($candidates, $split, $evalCache);

                if ($this->prefers($config, $metrics, $split, $bestConfig['metrics'], $bestConfig['allocation'], $tol)) {
                    $bestConfig = ['metrics' => $metrics, 'allocation' => $split];
                }
            }
        }

        if ($bestConfig['allocation'] === $allocation) {
            return false;
        }

        $allocation = $bestConfig['allocation'];

        return true;
    }

    /**
     * @param  array<string, int>  $amounts
     * @return list<int>
     */
    private function splitPortions(int $total): array
    {
        $portions = [];

        foreach ([0.25, 0.5, 0.75] as $ratio) {
            $portion = (int) round($total * $ratio);

            if ($portion > 0 && $portion < $total) {
                $portions[] = $portion;
            }
        }

        return array_values(array_unique($portions));
    }

    /**
     * Ứng viên đáng thử cho một danh mục, theo điểm ưu tiên giảm dần — gồm các
     * thẻ có rule dương, cộng thẻ của tôi/đang giữ để không bỏ lỡ hiệu ứng bậc.
     *
     * @return array<string, float> key => điểm
     */
    private function candidateKeysByScore(
        array $config,
        array $candidates,
        array $positiveMap,
        array $singles,
        array $allocation,
        int $categoryId,
        int $amount,
    ): array {
        $held = $this->heldCounts($allocation);
        $ranked = [];

        foreach ($candidates as $key => $scaffold) {
            $single = $singles[$key][$categoryId] ?? null;

            if (! $this->isPositive($positiveMap[$key], $categoryId) && $single === null) {
                continue;
            }

            $score = $this->preferScore(
                $config['criterion'],
                $key,
                $single ?? ['value' => 0.0, 'tier_min' => null, 'percent' => null],
                $held[$key] ?? 0,
                $scaffold,
            );

            $ranked[$key] = $score;
        }

        arsort($ranked);

        return $ranked;
    }

    /**
     * Chọn phương án cấu hình tốt hơn theo tiêu chí. Hoàn tiền là ưu tiên chính;
     * chỉ chấp nhận hy sinh trong dung sai `$tol` khi đổi được đặc trưng tiêu chí.
     *
     * @param  array{total: float, count: int, easy: float, owned: float, market: float, spread: float}  $aMetrics
     * @param  array{total: float, count: int, easy: float, owned: float, market: float, spread: float}  $bMetrics
     */
    private function prefers(array $config, array $aMetrics, array $allocA, array $bMetrics, array $allocB, float $tol): bool
    {
        $delta = $aMetrics['total'] - $bMetrics['total'];
        $criterion = $config['criterion'];

        if ($delta > 0.001) {
            return true;
        }

        if ($delta < -$tol) {
            return false;
        }

        // Hòa hoặc hy sinh trong dung sai: so đặc trưng tiêu chí.
        switch ($criterion) {
            case OptimizationCriterion::FEWEST_CARDS:
                if ($aMetrics['count'] !== $bMetrics['count']) {
                    return $aMetrics['count'] < $bMetrics['count'];
                }

                break;

            case OptimizationCriterion::MY_CARDS:
                $aRatio = $aMetrics['total'] > 0.0 ? $aMetrics['owned'] / $aMetrics['total'] : 0.0;
                $bRatio = $bMetrics['total'] > 0.0 ? $bMetrics['owned'] / $bMetrics['total'] : 0.0;

                if (abs($aRatio - $bRatio) > 0.01) {
                    return $aRatio > $bRatio;
                }

                break;

            case OptimizationCriterion::EASY_CONDITIONS:
                if (abs($aMetrics['easy'] - $bMetrics['easy']) > 0.001) {
                    return $aMetrics['easy'] < $bMetrics['easy'];
                }

                break;

            case OptimizationCriterion::NEW_CARDS:
                $aRatio = $aMetrics['total'] > 0.0 ? $aMetrics['market'] / $aMetrics['total'] : 0.0;
                $bRatio = $bMetrics['total'] > 0.0 ? $bMetrics['market'] / $bMetrics['total'] : 0.0;

                if (abs($aRatio - $bRatio) > 0.01) {
                    return $aRatio > $bRatio;
                }

                break;

            case OptimizationCriterion::BALANCE:
            default:
                if (abs($aMetrics['spread'] - $bMetrics['spread']) > 0.001) {
                    return $aMetrics['spread'] > $bMetrics['spread'];
                }

                break;
        }

        return $aMetrics['total'] > $bMetrics['total'];
    }

    /**
     * Bộ chỉ số của toàn bộ phân bổ (thẻ độc lập nên cộng đơn giản), dựa trên
     * kết quả thật của `CardScenarioEvaluator` (cache theo từng thẻ×phân bổ).
     *
     * @param  array<string, array<int, int>>  $allocation
     * @param  array<string, array<string, CardScenario>>  $evalCache
     * @return array{total: float, count: int, easy: float, owned: float, market: float, spread: float}
     */
    private function allocationValue(array $candidates, array $allocation, array &$evalCache): array
    {
        $total = 0.0;
        $count = 0;
        $easy = 0.0;
        $owned = 0.0;
        $market = 0.0;
        $cardTotals = [];

        foreach ($allocation as $key => $cats) {
            if ($cats === [] || $key === null) {
                continue;
            }

            $scenario = $this->cachedScenario($candidates, $evalCache, $key, $cats);
            $total += (float) $scenario->totalCashback;
            $count++;
            $cardTotals[] = (float) array_sum($cats);
            $easy += $scenario->tierMinTotalSpend === null ? 0.0 : (float) $scenario->tierMinTotalSpend;

            if ($scenario->isOwned) {
                $owned += (float) $scenario->totalCashback;
            } else {
                $market += (float) $scenario->totalCashback;
            }
        }

        $spread = $this->spreadFrom($cardTotals);

        return [
            'total' => $total,
            'count' => $count,
            'easy' => $easy,
            'owned' => $owned,
            'market' => $market,
            'spread' => $spread,
        ];
    }

    /**
     * Điểm "dàn đều": càng nhiều thẻ dùng với tổng chi gần nhau, điểm càng cao.
     *
     * @param  list<float>  $cardTotals
     */
    private function spreadFrom(array $cardTotals): float
    {
        if (count($cardTotals) <= 1) {
            return 0.0;
        }

        $mean = array_sum($cardTotals) / count($cardTotals);

        if ($mean <= 0.0) {
            return 0.0;
        }

        $variance = 0.0;

        foreach ($cardTotals as $value) {
            $variance += ($value - $mean) ** 2;
        }

        return 1.0 / (1.0 + sqrt($variance / count($cardTotals)) / $mean);
    }

    /**
     * @param  array<int, int>  $cats
     * @param  array<string, array<string, CardScenario>>  $evalCache
     */
    private function cachedScenario(array $candidates, array &$evalCache, string $key, array $cats): CardScenario
    {
        $cacheKey = $this->serializeAllocation($cats);

        if (isset($evalCache[$key][$cacheKey])) {
            return $evalCache[$key][$cacheKey];
        }

        $scenario = $this->evaluator->evaluate($candidates[$key], $cats);
        $evalCache[$key][$cacheKey] = $scenario;

        return $scenario;
    }

    /**
     * @param  array<int, int>  $cats
     */
    private function serializeAllocation(array $cats): string
    {
        ksort($cats);

        $parts = [];

        foreach ($cats as $categoryId => $amount) {
            if ((int) $amount > 0) {
                $parts[] = $categoryId.':'.$amount;
            }
        }

        return implode('|', $parts);
    }

    // ---------------------------------------------------------------------
    // Plan assembly
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array<int, int>>  $allocation
     * @return array{allocation: array<string, array<int, int>>, scenarios: array<string, CardScenario>, total: float, count: int, owned_total: float, market_total: float, min_spend_score: float, fingerprint: string}|null
     */
    private function planFrom(array $candidates, array $allocation): ?array
    {
        $evalCache = [];
        $scenarios = [];
        $total = 0.0;
        $ownedTotal = 0.0;
        $marketTotal = 0.0;
        $minSpendScore = 0.0;
        $cardCount = 0;
        $clean = [];

        foreach ($allocation as $key => $cats) {
            if (($cats ?? []) === []) {
                continue;
            }

            $scenario = $this->cachedScenario($candidates, $evalCache, $key, $cats);

            if ($scenario->scenarioReason !== null) {
                continue;
            }

            $scenarios[$key] = $scenario;
            $clean[$key] = $cats;
            $total += (float) $scenario->totalCashback;
            $minSpendScore += $scenario->tierMinTotalSpend === null
                ? 0.0
                : (float) $scenario->tierMinTotalSpend;
            $cardCount++;

            if ($scenario->isOwned) {
                $ownedTotal += (float) $scenario->totalCashback;
            } else {
                $marketTotal += (float) $scenario->totalCashback;
            }
        }

        if ($clean === []) {
            return null;
        }

        return [
            'allocation' => $clean,
            'scenarios' => $scenarios,
            'total' => $total,
            'count' => $cardCount,
            'owned_total' => $ownedTotal,
            'market_total' => $marketTotal,
            'min_spend_score' => $minSpendScore,
            'fingerprint' => $this->fingerprint($clean),
        ];
    }

    /**
     * @param  array<string, array<int, int>>  $allocation
     */
    private function fingerprint(array $allocation): string
    {
        $keys = array_keys($allocation);
        sort($keys);

        $parts = [];

        foreach ($keys as $key) {
            $cats = $allocation[$key];
            ksort($cats);
            $parts[] = $key.'('.implode(',', array_map(
                fn ($catId, $amount): string => $catId.'x'.$amount,
                array_keys($cats),
                $cats,
            )).')';
        }

        return implode(';', $parts);
    }

    /**
     * Loại bỏ phương án trùng theo fingerprint.
     *
     * @param  array<int, array<string, mixed>>  $plans
     * @return array<int, array<string, mixed>>
     */
    private function distinct(array $plans): array
    {
        $seen = [];
        $result = [];

        foreach ($plans as $plan) {
            $fp = $plan['fingerprint'];

            if (isset($seen[$fp])) {
                continue;
            }

            $seen[$fp] = true;
            $result[] = $plan;
        }

        return $result;
    }

    /**
     * Xếp hạng các phương án theo tiêu chí đang chọn (khác nhau giữa tiêu chí).
     *
     * @param  array<int, array<string, mixed>>  $plans
     * @return array<int, array<string, mixed>>
     */
    private function rank(array $plans, string $criterion): array
    {
        usort($plans, function (array $a, array $b) use ($criterion): int {
            switch ($criterion) {
                case OptimizationCriterion::FEWEST_CARDS:
                    return [$a['count'], -(float) $a['total']]
                        <=> [$b['count'], -(float) $b['total']];

                case OptimizationCriterion::EASY_CONDITIONS:
                    return [$a['min_spend_score'], -(float) $a['total']]
                        <=> [$b['min_spend_score'], -(float) $b['total']];

                case OptimizationCriterion::MY_CARDS:
                    $aOwnedRatio = $a['total'] > 0.0 ? $a['owned_total'] / $a['total'] : 0.0;
                    $bOwnedRatio = $b['total'] > 0.0 ? $b['owned_total'] / $b['total'] : 0.0;

                    return [-(float) $a['total'], -$aOwnedRatio, $a['count']]
                        <=> [-(float) $b['total'], -$bOwnedRatio, $b['count']];

                case OptimizationCriterion::NEW_CARDS:
                    $aMarketRatio = $a['total'] > 0.0 ? $a['market_total'] / $a['total'] : 0.0;
                    $bMarketRatio = $b['total'] > 0.0 ? $b['market_total'] / $b['total'] : 0.0;

                    return [-(float) $a['total'], -$aMarketRatio, $a['count']]
                        <=> [-(float) $b['total'], -$bMarketRatio, $b['count']];

                case OptimizationCriterion::BALANCE:
                case OptimizationCriterion::MAX_CASHBACK:
                default:
                    return [-(float) $a['total'], $a['count']] <=> [-(float) $b['total'], $b['count']];
            }
        });

        return $plans;
    }

    // ---------------------------------------------------------------------
    // Config & rendering
    // ---------------------------------------------------------------------

    /**
* Bộ cấu hình sinh theo tiêu chí đang chọn. Các config khác nhau cho ra các
     * phương án khác nhau — dùng làm nguồn cho bước cắt "3 phương án thực khác".
     *
     * @param  array<int, int>  $amounts
     * @return list<array{order: list<int>, criterion: string, prefer_owned: bool, prefer_market: bool, focus_on_one: bool}>
     */
    private function configsFor(string $criterion, array $amounts): array
    {
        $orderAsc = array_keys($amounts);
        sort($orderAsc);

        $orderDesc = $amounts;
        arsort($orderDesc);
        $orderDesc = array_keys($orderDesc);

        $orderIdAsc = array_keys($amounts);
        sort($orderIdAsc);

        $base = [
            'criterion' => $criterion,
            'prefer_owned' => false,
            'prefer_market' => false,
            'focus_on_one' => $criterion === OptimizationCriterion::FEWEST_CARDS
                || $criterion === OptimizationCriterion::MAX_CASHBACK,
        ];

        $configs = [
            $this->withOrder($base, $orderAsc, ['criterion' => $criterion]),
            $this->withOrder($base, $orderDesc, ['criterion' => OptimizationCriterion::BALANCE]),
        ];

        $thirdCriterion = match ($criterion) {
            OptimizationCriterion::MY_CARDS => OptimizationCriterion::MY_CARDS,
            OptimizationCriterion::NEW_CARDS => OptimizationCriterion::NEW_CARDS,
            OptimizationCriterion::EASY_CONDITIONS => OptimizationCriterion::EASY_CONDITIONS,
            OptimizationCriterion::FEWEST_CARDS => OptimizationCriterion::FEWEST_CARDS,
            default => OptimizationCriterion::MAX_CASHBACK,
        };

        $configs[] = $this->withOrder($base, $orderIdAsc, ['criterion' => $thirdCriterion]);

        if ($criterion === OptimizationCriterion::MY_CARDS) {
            $configs[0]['prefer_owned'] = true;
            $configs[2]['prefer_owned'] = true;
        }

        return $configs;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  list<int>  $order
     * @param  array<string, string>  $overrides
     * @return array{order: list<int>, criterion: string, prefer_owned: bool, prefer_market: bool, focus_on_one: bool}
     */
    private function withOrder(array $base, array $order, array $overrides): array
    {
        return array_merge($base, $overrides, ['order' => $order]);
    }

    /**
     * @param  array<string, array<int, int>>  $amounts
     */
    private function normalizeAmounts(array $amounts): array
    {
        $clean = [];

        foreach ($amounts as $categoryId => $amount) {
            $amount = (int) $amount;

            if ($amount > 0) {
                $clean[(int) $categoryId] = $amount;
            }
        }

        return $clean;
    }

    // ---------------------------------------------------------------------
    // Render
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function renderPlan(string $criterion, array $plan, int $rank): array
    {
        $cards = [];

        foreach ($plan['allocation'] as $key => $cats) {
            $scenario = $plan['scenarios'][$key];
            $categories = [];

            foreach ($cats as $categoryId => $amount) {
                if ((int) $amount <= 0) {
                    continue;
                }

                $row = $scenario->perCategory[(int) $categoryId] ?? [];

                $categories[] = [
                    'category_id' => (int) $categoryId,
                    'amount' => Decimal::money((string) $amount),
                    'cashback' => (string) ($row['cashback'] ?? '0.00'),
                    'rate' => (float) ($row['rate'] ?? 0.0),
                    'percent' => $row['percent'] ?? null,
                    'eligible' => (bool) ($row['eligible'] ?? false),
                    'reason' => $row['reason'] ?? null,
                    'caps_applied' => $row['caps_applied'] ?? [],
                ];
            }

            $cards[] = [
                'key' => $key,
                'is_owned' => $scenario->isOwned,
                'name' => $scenario->name,
                'bank_name' => $scenario->bankName,
                'total_cashback' => $scenario->totalCashback,
                'rate' => $this->cardRate($scenario->totalCashback, $cats),
                'tier_label' => $scenario->tierLabel,
                'min_total_spend_met' => $scenario->minTotalSpendMet,
                'qualification_notice' => $scenario->qualificationNotice,
                'market_assumption' => $scenario->marketAssumption,
                'scenario_reason' => $scenario->scenarioReason,
                'categories' => $categories,
            ];
        }

        usort($cards, fn (array $a, array $b): int => [(float) $b['total_cashback'], (int) $b['is_owned']]
            <=> [(float) $a['total_cashback'], (int) $a['is_owned']]);

        $totalCashback = Decimal::money(number_format((float) $plan['total'], 2, '.', ''));
        $totalInput = (float) array_sum(array_merge(...array_values($plan['allocation'])));

        return [
            'rank' => $rank,
            'cards_count' => $plan['count'],
            'total_cashback' => $totalCashback,
            'total_rate' => $this->rate($plan['total'], $totalInput),
            'criterion' => $criterion,
            'cards' => $cards,
        ];
    }

    /**
     * @param  array<int, int>  $cats
     */
    private function cardRate(string $cashback, array $cats): float
    {
        $totalInput = array_sum($cats);

        return $this->rate((float) $cashback, $totalInput);
    }

    private function rate(float $cashback, float $amount): float
    {
        if ($amount <= 0.0) {
            return 0.0;
        }

        return round($cashback / $amount * 100, 3);
    }

    /**
     * Ghi chú chung cho tập phương án: danh mục chưa có thẻ nào hoàn tiền, và lời
     * từ chối trách nhiệm về điều kiện mở thẻ mới.
     *
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array{any_all: bool, cats: array<int, true>}>  $positiveMap
     * @param  array<int, int>  $amounts
     * @param  array<int, array<string, mixed>>  $plans
     * @return list<string>
     */
    private function planNotes(array $candidates, array $positiveMap, array $amounts, array $plans): array
    {
        $notes = [];

        $covered = [];

        foreach ($plans as $plan) {
            foreach ($plan['allocation'] as $cats) {
                foreach ($cats as $categoryId => $amount) {
                    if ((int) $amount > 0) {
                        $covered[(int) $categoryId] = true;
                    }
                }
            }
        }

        foreach ($amounts as $categoryId => $amount) {
            if (! isset($covered[(int) $categoryId])) {
                $notes[] = 'Danh mục chưa có thẻ hoàn tiền trong các phương án đã chọn.';
            }
        }

        $notes[] = 'Phương án là ước tính theo chính sách hoàn tiền hiện hành; chưa xác minh điều kiện mở thẻ mới (hồ sơ, phí) và các điều kiện đặc biệt khác.';

        return $notes;
    }

    private function ownedKey(int $cardId): string
    {
        return 'owned:'.$cardId;
    }

    private function marketKey(int $templateId): string
    {
        return 'market:'.$templateId;
    }
}