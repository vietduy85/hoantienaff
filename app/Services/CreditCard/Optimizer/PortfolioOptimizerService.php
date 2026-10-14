<?php

namespace App\Services\CreditCard\Optimizer;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
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

/**
 * PortfolioOptimizerService — "Tối ưu bộ thẻ hoàn tiền".
 *
 * Người dùng nhập chi tiêu theo danh mục/tháng; hệ thống phân bổ các danh mục
 * vào một TỔ HỢP thẻ (cho phép tách một danh mục cho nhiều thẻ) và trả về TỐI ĐA
 * 3 phương án thực khác nhau.
 *
 * ---------------------------------------------------------------------------
 * KỲ MÔ PHỎNG MỚI — KHÔNG PHỤ THUỘC LỊCH SỬ THỰC
 * ---------------------------------------------------------------------------
 * Mỗi lần tối ưu là một kỳ mô phỏng mới: giả định TẤT CẢ thẻ đều chưa phát sinh
 * chi tiêu trong kỳ. Do đó, hạn mức/trần cashback của mọi thẻ được giữ đầy đủ
 * theo chính sách, bậc áp dụng và điều kiện chi tiêu chỉ phụ thuộc vào các khoản
 * phân bổ đang mô phỏng. Dữ liệu giao dịch thực tế của kỳ hiện tại KHÔNG được
 * trộn vào việc tính toán của phương án mô phỏng. Lớp này không ghi DB; mọi giá
 * trị hoàn tiền đều do `CardScenarioEvaluator` (qua `CashbackCalculator`) tính
 * trên các dòng giả định hợp thành tập phân bổ.
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

    /** Số bản mặc định của MỖI sản phẩm thẻ mới trong một phương án. */
    public const DEFAULT_MAX_COPIES = 1;

    /** Giới hạn trên cho số bản cùng sản phẩm (chặn nổ tổ hợp). */
    public const MAX_COPIES_LIMIT = 5;

    /**
     * Số ứng viên tối đa xét cho mỗi danh mục theo GIÁ TRỊ (hoàn khi dồn cả danh
     * mục) — đủ rộng để không bỏ sót thẻ có tổng cả danh mục cao, nhưng vẫn chặn
     * chi phí đánh giá khi có nhiều thẻ.
     */
    private const SHORTLIST_LIMIT = 6;

    /**
     * Số ứng viên tối đa xét THÊM theo TỶ LỆ DANH NGHĨA cao nhất. Thẻ "tỷ lệ cao
     * nhưng trần thấp" (điển hình 20% trần 300k) có thể có giá-trị-cả-danh-mục
     * thấp và bị cắt khỏi top giá trị, nhưng vẫn thắng ở phân bổ BIÊN một phần
     * ngân sách. Xếp hạng theo hai tín hiệu (giá trị + tỷ lệ) rồi hợp nhất để
     * KHÔNG loại ứng viên phối hợp.
     */
    private const SHORTLIST_RATE_LIMIT = 4;

    /**
     * Số bước rót nước (water-filling) cho mỗi danh mục — bước ≈ 5% tổng danh mục
     * (chặn dưới 50k). Chỉ là bước LÙI tối đa; thuật toán còn bám các mốc trần/tier
     * thực tế nên hiếm khi chạm giới hạn này.
     */
    private const WATERFILL_STEPS = 20;

    /**
     * Chặn số điểm mốc (trần/tier) xét cho mỗi ứng viên×danh mục để giữ chi phí
     * đánh giá hữu hạn khi policy nhiều bậc/nhiều rule.
     */
    private const LANDMARK_LIMIT = 8;

    /**
     * Số vòng cải thiện cục bộ liên tiếp tối đa cho một cấu hình (dừng sớm khi
     * không còn cải thiện) — bảo đảm phân bổ ổn định sau khi trần/tier tương tác.
     */
    private const MAX_IMPROVE_PASSES = 3;

    /**
     * Dung sai (đồng) cho phép hy sinh khi chuyển danh mục giữa các thẻ vì lý do
     * tiêu chí — giữ nhỏ (≤ 2‰ tổng) để không bao giờ đánh đổi hoàn tiền đáng kể.
     */
    private const BALANCE_TOLERANCE_RATIO = 0.002;

    /**
     * Dung sai (đồng) khi so sánh phương án "kích hoạt điều kiện đặc biệt" với
     * phương án thường — chỉ nhận khi thực sự cao hơn.
     */
    private const ACTIVATION_EPSILON = 0.001;

    public function __construct(
        private readonly StatementPeriodService $periods,
        private readonly PolicyEngineService $engine,
        private readonly TierResolverService $tiers,
        private readonly CardScenarioEvaluator $evaluator,
        private readonly OnlinePaymentEligibilityResolver $online,
        private readonly SpendingConditionAllocator $conditions,
    ) {}

    /** Chuẩn hoá số bản/sản phẩm về khoảng [1, MAX_COPIES_LIMIT]. */
    public static function clampCopies(int $copies): int
    {
        return max(1, min(self::MAX_COPIES_LIMIT, $copies));
    }

    /**
     * @param  array<int, int>  $amounts  categoryId => chi tiêu dự kiến (VND)
     * @return array<string, mixed>
     */
    public function optimize(
        int $userId,
        string $scope,
        string $criterion,
        array $amounts,
        int $maxCopies = self::DEFAULT_MAX_COPIES,
    ): array {
        $amounts = $this->normalizeAmounts($amounts);
        $maxCopies = self::clampCopies($maxCopies);
        $categoryNames = $this->categoryNames(array_keys($amounts));

        $candidates = $this->buildCandidates($userId, $scope, $maxCopies);
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

        $planCount = count($distinct);
        $bestCashback = 0.0;

        foreach ($distinct as $plan) {
            $bestCashback = max($bestCashback, (float) $plan['total']);
        }

        if (count($distinct) > self::MAX_PLANS) {
            $distinct = array_slice($distinct, 0, self::MAX_PLANS);
        }

        $rendered = [];

        foreach ($distinct as $index => $plan) {
            $rendered[] = $this->renderPlan($criterion, $plan, $index + 1, $categoryNames);
        }

        return [
            'scope' => $scope,
            'criterion' => $criterion,
            'criterion_label' => OptimizationCriterion::labels()[$criterion],
            'total_input' => Decimal::money((string) array_sum($amounts)),
            'total_input_number' => array_sum($amounts),
            'categories' => $this->renderInputCategories($amounts, $categoryNames),
            'plan_count' => $planCount,
            'max_plans' => self::MAX_PLANS,
            'max_copies_per_card' => $maxCopies,
            'best_cashback' => Decimal::money(number_format($bestCashback, 2, '.', '')),
            'plans' => $rendered,
            'notes' => $this->planNotes($candidates, $positiveMap, $amounts, $distinct),
        ];
    }

    /**
     * Tên danh mục theo ID từ NGUỒN CHUẨN (`categories`) — dùng để hiển thị,
     * KHÔNG hardcode tên trong view và không ảnh hưởng phép tính nào.
     *
     * @param  list<int|string>  $categoryIds
     * @return array<int, string>
     */
    private function categoryNames(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return Category::query()
            ->whereIn('id', $categoryIds)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Danh sách danh mục người dùng đã nhập (giữ nguyên thứ tự request) kèm tên
     * hiển thị — nguồn cho khối "Tóm tắt nhu cầu chi tiêu".
     *
     * @param  array<int, int>  $amounts
     * @param  array<int, string>  $categoryNames
     * @return list<array{id: int, name: string, amount: string, amount_number: int}>
     */
    private function renderInputCategories(array $amounts, array $categoryNames): array
    {
        $categories = [];

        foreach ($amounts as $categoryId => $amount) {
            $categoryId = (int) $categoryId;

            $categories[] = [
                'id' => $categoryId,
                'name' => $categoryNames[$categoryId] ?? ('Danh mục #'.$categoryId),
                'amount' => Decimal::money((string) $amount),
                'amount_number' => (int) $amount,
            ];
        }

        return $categories;
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
    private function buildCandidates(int $userId, string $scope, int $maxCopies): array
    {
        $owned = $this->ownedScaffolds($userId);

        if ($scope === OptimizerScope::OWNED) {
            return $owned;
        }

        // Nhóm thẻ đang sở hữu theo sản phẩm (template của policy version) để
        // KHÔNG sinh thêm bản sao "thẻ mới" cho sản phẩm người dùng đã có.
        $ownedByTemplate = [];

        foreach ($owned as $scaffold) {
            if ($scaffold->templateId !== null) {
                $ownedByTemplate[$scaffold->templateId][] = $scaffold;
            }
        }

        $candidates = [];

        if ($scope === OptimizerScope::COMPARE) {
            // Toàn bộ thẻ của người dùng (kể cả sản phẩm không có template hệ thống).
            $candidates = $owned;
        }

        $marketTemplateIds = [];

        $market = $this->marketScaffolds($maxCopies, $ownedByTemplate, $marketTemplateIds);

        if ($scope === OptimizerScope::ALL) {
            // Phạm vi "all" chỉ gồm sản phẩm hệ thống; thẻ đang sở hữu thuộc một
            // sản phẩm hệ thống phải được nhận diện là thẻ của bạn, không phải thẻ mới.
            foreach ($owned as $scaffold) {
                if ($scaffold->templateId !== null
                    && in_array($scaffold->templateId, $marketTemplateIds, true)) {
                    $candidates[$scaffold->key] = $scaffold;
                }
            }
        }

        $this->mergeDistinct($candidates, $market);

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

        $todayIso = $today->toDateString();
        $templateSlugs = PolicyTemplate::query()->pluck('slug', 'id')->all();

        $scaffolds = [];
        $indexByTemplate = [];

        foreach ($cards as $card) {
            $cardId = (int) $card->id;
            $version = $this->engine->resolveFor($card, $today)?->policyVersion;

            if ($version === null) {
                continue;
            }

            [, $end] = $boundaries[$cardId];
            $qualification = SpendQualification::query()
                ->where('policy_version_id', $version->id)
                ->where('enabled', true)
                ->with(['conditions.category', 'conditions.excludedCategories'])
                ->first();

            // Liên kết thẻ sở hữu với sản phẩm qua template của policy version.
            // Nhờ đó thẻ đã có và các bản sao "thẻ mới" của cùng sản phẩm được
            // nhóm chung, và optimizer biết người dùng đã sở hữu sản phẩm nào.
            $templateId = $version->template_id !== null ? (int) $version->template_id : null;
            $productKey = $templateId !== null ? $this->marketKey($templateId) : $this->ownedKey($cardId);
            $templateSlug = $templateId !== null ? ($templateSlugs[$templateId] ?? null) : null;
            $instanceIndex = 1;

            if ($templateId !== null) {
                $indexByTemplate[$templateId] = ($indexByTemplate[$templateId] ?? 0) + 1;
                $instanceIndex = $indexByTemplate[$templateId];
            }

            $scaffolds[$this->ownedKey($cardId)] = new CardScaffold(
                key: $this->ownedKey($cardId),
                isOwned: true,
                name: (string) $card->name,
                bankName: $card->bank?->name,
                cardId: $cardId,
                templateId: $templateId,
                version: $version,
                tiers: $this->tiers->tiersOfVersion($version),
                rulesByTier: $this->rulesByTier($version),
                capsByTier: $this->capsByTier($version),
                allEnabledRules: $this->tiers->allEnabledRulesFor($version),
                periodEnd: $end,
                minTotalSpend: (float) $version->min_total_spend,
                roundingMode: (string) $version->rounding_mode,
                qualification: $qualification,
                qualificationNotice: $qualification === null ? null : 'Thẻ có điều kiện hoàn tiền đặc biệt: kết quả đã xác nhận theo giả định chi tiêu của kỳ.',
                marketAssumption: '',
                idNumber: $cardId,
                instanceIndex: $instanceIndex,
                productKey: $productKey,
                templateSlug: $templateSlug,
            );
        }

        return $scaffolds;
    }

    /**
     * @param  array<int, list<CardScaffold>>  $ownedByTemplate  templateId => thẻ đang sở hữu
     * @param  list<int>  $marketTemplateIds  (out) id các template hệ thống đang hoạt động
     * @return array<string, CardScaffold>
     */
    private function marketScaffolds(int $maxCopies, array $ownedByTemplate, array &$marketTemplateIds): array
    {
        $templates = PolicyTemplate::query()
            ->system()
            ->where('is_active', true)
            ->with('applicableBank')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $scaffolds = [];
        $maxCopies = self::clampCopies($maxCopies);

        foreach ($templates as $template) {
            $version = $template->defaultBlueprint();

            if ($version === null) {
                continue;
            }

            $templateId = (int) $template->id;
            $marketTemplateIds[] = $templateId;

            $hasCondition = SpendQualification::query()
                ->where('policy_version_id', $version->id)
                ->where('enabled', true)
                ->exists();

            $hasConditionNotice = $hasCondition ? 'Thẻ có điều kiện hoàn tiền đặc biệt: kết quả chưa xác nhận bạn đủ điều kiện.' : null;

            // Dữ liệu phụ thuộc version chỉ dựng MỘT LẦN rồi tái dùng cho mọi bản
            // sao của sản phẩm (bản sao chỉ khác danh tính instance).
            $tiers = $this->tiers->tiersOfVersion($version);
            $rulesByTier = $this->rulesByTier($version);
            $capsByTier = $this->capsByTier($version);
            $allRules = $this->tiers->allEnabledRulesFor($version);

            $productKey = $this->marketKey($templateId);

            // Thẻ người dùng đã sở hữu KHÔNG tính là "thẻ mới": chỉ sinh bản sao
            // mới cho phần vượt quá số thẻ đang sở hữu của sản phẩm này.
            $ownedCount = count($ownedByTemplate[$templateId] ?? []);
            $total = max($maxCopies, $ownedCount);

            for ($index = $ownedCount + 1; $index <= $total; $index++) {
                $key = $index === 1 ? $productKey : $productKey.'#'.$index;

                $scaffolds[$key] = new CardScaffold(
                    key: $key,
                    isOwned: false,
                    name: (string) $template->name,
                    bankName: $template->applicableBank?->name,
                    cardId: null,
                    templateId: $templateId,
                    version: $version,
                    tiers: $tiers,
                    rulesByTier: $rulesByTier,
                    capsByTier: $capsByTier,
                    allEnabledRules: $allRules,
                    periodEnd: null,
                    minTotalSpend: (float) $version->min_total_spend,
                    roundingMode: (string) $version->rounding_mode,
                    qualification: null,
                    qualificationNotice: $hasConditionNotice,
                    marketAssumption: 'Mô phỏng thẻ mới chưa có chi tiêu trong kỳ; bậc áp dụng sẽ phụ thuộc tổng chi thực tế khi bạn mở và dùng thẻ.',
                    idNumber: $templateId,
                    instanceIndex: $index,
                    productKey: $productKey,
                    templateSlug: $template->slug,
                );
            }
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

        // Giả định online: một danh mục gốc cũng "có thể dương" nếu thẻ có ưu đãi
        // cho danh mục online mà danh mục gốc đó có thể chuyển sang.
        foreach ($this->online->baseCategoryIds() as $baseId) {
            $target = $this->online->targetForBase($baseId);

            if ($target === null) {
                continue;
            }

            if ($anyAll || isset($cats[$target])) {
                $cats[$baseId] = true;
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
     * @return array<string, array<int, int>>|null cardKey => (categoryId => amount)
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

        $allocation = $this->improveAllocation($config, $candidates, $positiveMap, $singles, $amounts, $allocation);

        // Kích hoạt "điều kiện hoàn tiền đặc biệt": chủ động dồn phần chi tiêu đủ
        // điều kiện sang thẻ đang có điều kiện, rồi cải thiện lại; chỉ nhận nếu
        // tổng hoàn tiền THẬT cao hơn.
        $evalCache = [];
        $best = $allocation;
        $bestTotal = $this->allocationValue($candidates, $best, $evalCache)['total'];

        foreach ($candidates as $scaffold) {
            if ($scaffold->qualification === null) {
                continue;
            }

            $activated = $this->conditions->activate($scaffold, $best, $amounts, $singles);

            if ($activated === null) {
                continue;
            }

            $activated = $this->improveAllocation($config, $candidates, $positiveMap, $singles, $amounts, $activated);

            if ($activated === []) {
                continue;
            }

            $total = $this->allocationValue($candidates, $activated, $evalCache)['total'];

            if ($total > $bestTotal + self::ACTIVATION_EPSILON) {
                $best = $activated;
                $bestTotal = $total;
            }
        }

        return $best;
    }

    /**
     * Vòng cải thiện cục bộ từng danh mục (kể cả thử tách cho hai thẻ) cho tới khi
     * hết cải thiện (chặn số vòng).
     *
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array{any_all: bool, cats: array<int, true>}>  $positiveMap
     * @param  array<string, array<int, array<string, mixed>>>  $singles
     * @param  array<int, int>  $amounts
     * @param  array{order: list<int>, criterion: string, prefer_owned: bool, prefer_market: bool, spread: bool}  $config
     * @param  array<string, array<int, int>>  $allocation
     * @return array<string, array<int, int>>
     */
    private function improveAllocation(
        array $config,
        array $candidates,
        array $positiveMap,
        array $singles,
        array $amounts,
        array $allocation,
    ): array {
        $evalCache = [];

        for ($pass = 0; $pass < self::MAX_IMPROVE_PASSES; $pass++) {
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
        $scoreOf = function (?string $key) use ($singles, $candidates, $heldCounts, $categoryId, $config): float {
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
     * Cải thiện cục bộ một danh mục bằng RÓT NƯỚC THEO LỢI ÍCH BIÊN.
     *
     * Thay vì chỉ thử "giao hết" hoặc tách cố định 25/50/75% cho hai thẻ điểm
     * cao nhất (từng khiến thẻ hoàn biên cao nhưng tổng cả danh mục thấp — ví dụ
     * thẻ 20% trần 300k — bị loại khỏi shortlist), ta chọn một shortlist ≥4 ứng
     * viên rồi chia dần từng phần nhỏ cho ứng viên có LỢI ÍCH BIÊN cao nhất. Mọi
     * mức hoàn vẫn do `CardScenarioEvaluator` quyết định nên trần/quota/bậc được
     * bám tự nhiên: khi một thẻ chạm trần, lợi ích biên về 0 và tiền chảy sang thẻ
     * kế tiếp. Tổng phân bổ mỗi danh mục LUÔN được bảo toàn.
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
        $bestConfig = [
            'metrics' => $currentMetrics,
            'allocation' => $allocation,
        ];

        $shortlist = $this->shortlistFor(
            $config, $candidates, $positiveMap, $singles, $allocation, $categoryId, $holders,
        );

        if ($shortlist === []) {
            return false;
        }

        $tol = max(1000.0, $currentMetrics['total'] * self::BALANCE_TOLERANCE_RATIO);

        // 1) Thử giao TOÀN BỘ danh mục cho từng ứng viên trong shortlist.
        foreach ($shortlist as $targetKey) {
            $moved = $this->withoutCategory($allocation, $holders, $categoryId);
            $moved[$targetKey][$categoryId] = $total;

            $metrics = $this->allocationValue($candidates, $moved, $evalCache);

            if ($this->prefers($config, $metrics, $moved, $bestConfig['metrics'], $bestConfig['allocation'], $tol)) {
                $bestConfig = ['metrics' => $metrics, 'allocation' => $moved];
            }
        }

        // 2) Rót nước theo lợi ích biên (cho phép tách danh mục cho nhiều thẻ).
        if (! $config['focus_on_one'] && count($shortlist) > 1) {
            $split = $this->waterFill(
                $candidates, $singles, $allocation, $evalCache, $categoryId, $total, $shortlist, $holders,
            );

            $metrics = $this->allocationValue($candidates, $split, $evalCache);

            if ($this->prefers($config, $metrics, $split, $bestConfig['metrics'], $bestConfig['allocation'], $tol)) {
                $bestConfig = ['metrics' => $metrics, 'allocation' => $split];
            }
        }

        if ($bestConfig['allocation'] === $allocation) {
            return false;
        }

        $allocation = $bestConfig['allocation'];

        return true;
    }

    /**
     * Shortlist ứng viên cho một danh mục theo NHIỀU tín hiệu, để KHÔNG loại ứng
     * viên phối hợp:
     *
     *   1. Tập "giá trị": thẻ có rule dương cho danh mục, xếp theo điểm tiêu chí
     *      (giá trị hoàn khi DỒN cả danh mục) — bắt thẻ tổng cao.
     *   2. Tập "tỷ lệ": thẻ có TỶ LỆ DANH NGHĨA cao nhất cho danh mục — bắt thẻ
     *      tỷ lệ cao nhưng TRẦN THẤP (giá trị cả danh mục thấp nên dễ bị tập (1)
     *      cắt), vì các thẻ này thắng ở phân bổ BIÊN một phần ngân sách.
     *   3. Thẻ đang giữ danh mục và (khi tiêu chí ưu tiên) thẻ của tôi.
     *
     * Xếp hạng giá trị vẫn là tín hiệu chính; tập tỷ lệ chỉ mở rộng thêm nên
     * không làm mất các phương án hiện có. Tổng ứng viên bị chặn
     * (`SHORTLIST_LIMIT + SHORTLIST_RATE_LIMIT + holders`) để giữ chi phí hữu hạn.
     *
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array{any_all: bool, cats: array<int, true>}>  $positiveMap
     * @param  array<string, array<int, array<string, mixed>>>  $singles
     * @param  array<string, array<int, int>>  $allocation
     * @param  list<string>  $holders
     * @return list<string>
     */
    private function shortlistFor(
        array $config,
        array $candidates,
        array $positiveMap,
        array $singles,
        array $allocation,
        int $categoryId,
        array $holders,
    ): array {
        $held = $this->heldCounts($allocation);
        $byValue = [];
        $byRate = [];
        $zero = ['value' => 0.0, 'tier_min' => null, 'percent' => null];

        foreach ($candidates as $key => $scaffold) {
            $keep = $this->isPositive($positiveMap[$key], $categoryId)
                || in_array($key, $holders, true)
                || ($config['prefer_owned'] && $scaffold->isOwned);

            if (! $keep) {
                continue;
            }

            $single = $singles[$key][$categoryId] ?? $zero;

            $byValue[$key] = $this->preferScore(
                $config['criterion'],
                $key,
                $single,
                $held[$key] ?? 0,
                $scaffold,
            );

            // Tỷ lệ danh nghĩa: ưu tiên thẻ hoàn biên cao (ví dụ 20%) kể cả trần thấp.
            $rate = (float) ($single['percent'] ?? 0.0);
            $byRate[$key] = $rate > 0.0 ? $rate : -1.0;
        }

        arsort($byValue);
        arsort($byRate);

        $keys = array_slice(array_keys($byValue), 0, self::SHORTLIST_LIMIT);

        foreach (array_slice(array_keys($byRate), 0, self::SHORTLIST_RATE_LIMIT) as $rateKey) {
            if (! in_array($rateKey, $keys, true)) {
                $keys[] = $rateKey;
            }
        }

        foreach ($holders as $holderKey) {
            if (! in_array($holderKey, $keys, true)) {
                $keys[] = $holderKey;
            }
        }

        return $keys;
    }

    /**
     * Bỏ danh mục `$categoryId` khỏi các thẻ đang giữ (chuẩn bị giao lại).
     *
     * @param  array<string, array<int, int>>  $allocation
     * @param  list<string>  $holders
     * @return array<string, array<int, int>>
     */
    private function withoutCategory(array $allocation, array $holders, int $categoryId): array
    {
        $clean = $allocation;

        foreach ($holders as $holderKey) {
            unset($clean[$holderKey][$categoryId]);

            if (($clean[$holderKey] ?? []) === []) {
                unset($clean[$holderKey]);
            }
        }

        return $clean;
    }

    /**
     * Rót ngân sách một danh mục theo LỢI ÍCH BIÊN, có BÁM MỐC THỰC TẾ.
     *
     * Với mỗi ứng viên trong shortlist, dựng danh sách "mốc" phân bổ tiềm năng:
     * điểm cần để CHẠM TRẦN danh mục (`cap/rate`), chạm TRẦN KỲ của bậc, chạm
     * NGƯỠNG BẬC (`tier.min_total_spend`), và các mốc 25/50/75%/toàn phần. Sau đó
     * rót dần: mỗi bước cấp cho ứng viên có LỢI ÍCH BIÊN (đồng hoàn tăng thêm trên
     * mỗi đồng chi) cao nhất; bước tối đa ≈ 5% ngân sách nhưng bị "gãy" đúng tại
     * mốc gần nhất để KHÔNG vượt trần. Mọi con số do `CardScenarioEvaluator` quyết
     * định (không tự nhân rate); khi thẻ chạm trần, lợi ích biên về 0 và tiền chảy
     * sang thẻ kế tiếp. Tổng phân bổ LUÔN bảo toàn đúng ngân sách danh mục.
     *
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array<int, array<string, mixed>>>  $singles
     * @param  array<string, array<int, int>>  $allocation
     * @param  array<string, array<string, CardScenario>>  $evalCache
     * @param  list<string>  $shortlist
     * @param  list<string>  $holders
     * @return array<string, array<int, int>>
     */
    private function waterFill(
        array $candidates,
        array $singles,
        array $allocation,
        array &$evalCache,
        int $categoryId,
        int $total,
        array $shortlist,
        array $holders,
    ): array {
        $result = $this->withoutCategory($allocation, $holders, $categoryId);

        $delta = (int) round($total / self::WATERFILL_STEPS);
        $delta = max(50000, $delta);
        $delta = min($delta, $total);

        $base = [];
        $landmarks = [];
        $assigned = [];

        foreach ($shortlist as $key) {
            $base[$key] = $result[$key] ?? [];
            $assigned[$key] = 0;
            $landmarks[$key] = $this->marginalLandmarks(
                $candidates[$key],
                $singles[$key][$categoryId] ?? ['percent' => null],
                $base[$key],
                $categoryId,
                $total,
            );
        }

        $used = 0;
        $guard = 0;
        $guardLimit = self::WATERFILL_STEPS * max(1, count($shortlist)) + 5;

        while ($used < $total && $guard++ < $guardLimit) {
            $bestKey = null;
            $bestChunk = 0;
            $bestRatio = -1.0;
            $bestGain = 0.0;

            foreach ($shortlist as $key) {
                if ($assigned[$key] >= $total) {
                    continue;
                }

                $chunk = $this->nextMarginalChunk($landmarks[$key], $assigned[$key], $delta);
                $chunk = min($chunk, $total - $used);

                if ($chunk <= 0) {
                    continue;
                }

                $currentValue = $this->scenarioValue(
                    $candidates, $evalCache, $key, $base[$key], $categoryId, $assigned[$key],
                );
                $nextValue = $this->scenarioValue(
                    $candidates, $evalCache, $key, $base[$key], $categoryId, $assigned[$key] + $chunk,
                );

                $gain = $nextValue - $currentValue;

                if ($gain <= 0.0) {
                    continue;
                }

                $ratio = $gain / $chunk;

                if ($ratio > $bestRatio + 1e-12
                    || (abs($ratio - $bestRatio) <= 1e-12 && $gain > $bestGain + 1e-9)) {
                    $bestKey = $key;
                    $bestChunk = $chunk;
                    $bestRatio = $ratio;
                    $bestGain = $gain;
                }
            }

            if ($bestKey === null) {
                break;
            }

            $assigned[$bestKey] += $bestChunk;
            $used += $bestChunk;
        }

        // Bảo toàn ĐÚNG ngân sách danh mục: dồn phần dư vào ứng viên cho lợi ích
        // biên cao nhất ở phần dư; nếu mọi ứng viên đều 0 (danh mục không thẻ nào
        // hoàn) vẫn phải cấp để không mất khoản chi — đúng ngữ nghĩa hiện hành.
        if ($used < $total) {
            $remainder = $total - $used;
            $dumpKey = $this->bestRemainderKey(
                $candidates, $evalCache, $shortlist, $base, $assigned, $categoryId, $remainder,
            );

            $assigned[$dumpKey] += $remainder;
        }

        foreach ($assigned as $key => $amount) {
            if ($amount > 0) {
                $result[$key][$categoryId] = $amount;
            }
        }

        return $result;
    }

    /**
     * Bước kế tiếp cho một ứng viên: bám MỐC gần nhất KHÔNG vượt quá
     * `$current + $delta` để không nhảy qua trần; nếu không có mốc nào thì đi đúng
     * một `$delta`.
     *
     * @param  list<int>  $landmarks  danh sách mốc TĂNG DẦN
     */
    private function nextMarginalChunk(array $landmarks, int $current, int $delta): int
    {
        $ceiling = $current + $delta;

        foreach ($landmarks as $landmark) {
            if ($landmark > $current && $landmark <= $ceiling) {
                return $landmark - $current;
            }
        }

        return $delta;
    }

    /**
     * Mốc phân bổ tiềm năng cho MỘT thẻ×danh mục, suy từ policy THẬT (trần danh
     * mục, trần kỳ của bậc, ngưỡng bậc) cộng các mốc 25/50/75%/toàn phần. Mốc chỉ
     * là ĐIỂM KHỞI ĐẦU để tìm kiếm — số hoàn thực vẫn do evaluator xác nhận.
     *
     * @param  array<string, mixed>  $single
     * @param  array<int, int>  $baseCats  danh mục khác thẻ đang giữ (để suy ngưỡng bậc)
     * @return list<int>
     */
    private function marginalLandmarks(CardScaffold $scaffold, array $single, array $baseCats, int $categoryId, int $total): array
    {
        $points = [$total];

        $percent = (float) ($single['percent'] ?? 0.0);
        $rate = $percent / 100.0;

        foreach ($scaffold->allEnabledRules as $rule) {
            if (! $this->ruleMatchesCategory($rule, $categoryId)) {
                continue;
            }

            $pct = (float) ($rule['cashback_percent'] ?? 0.0);
            $capCat = $rule['max_cashback_per_category_per_period'] ?? null;

            if ($pct > 0.0 && $capCat !== null && (float) $capCat > 0.0) {
                $points[] = (int) round((float) $capCat / ($pct / 100.0));
            }
        }

        $baseSpend = (float) array_sum($baseCats);

        foreach ($scaffold->tiers as $tier) {
            $capPeriod = $tier->max_cashback_per_period;

            if ($rate > 0.0 && $capPeriod !== null && (float) $capPeriod > 0.0) {
                $points[] = (int) round((float) $capPeriod / $rate);
            }

            $min = (float) $tier->min_total_spend;

            if ($min > $baseSpend) {
                $points[] = (int) round($min - $baseSpend);
            }
        }

        foreach ([0.25, 0.5, 0.75] as $fraction) {
            $points[] = (int) round($total * $fraction);
        }

        $clean = [];

        foreach ($points as $point) {
            if ($point > 0 && $point <= $total) {
                $clean[$point] = true;
            }
        }

        $list = array_keys($clean);
        sort($list);

        if (count($list) > self::LANDMARK_LIMIT) {
            $list = $this->thinLandmarks($list, self::LANDMARK_LIMIT);
        }

        return $list;
    }

    /**
     * Giữ tối đa `$limit` mốc: ưu tiên mốc NHỎ (thường là điểm chạm trần/kỳ, hữu
     * ích nhất cho phân bổ biên) và LUÔN giữ mốc lớn nhất (toàn phần).
     *
     * @param  list<int>  $points  TĂNG DẦN
     * @return list<int>
     */
    private function thinLandmarks(array $points, int $limit): array
    {
        $last = $points[count($points) - 1];
        $head = array_slice($points, 0, $limit - 1);

        if (! in_array($last, $head, true)) {
            $head[] = $last;
        }

        sort($head);

        return $head;
    }

    /**
     * Ứng viên nhận phần ngân sách dư: lợi ích biên cao nhất; khi mọi ứng viên đều
     * không tăng hoàn, trả ứng viên đầu shortlist để bảo toàn khoản chi.
     *
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array<string, CardScenario>>  $evalCache
     * @param  list<string>  $shortlist
     * @param  array<string, array<int, int>>  $base
     * @param  array<string, int>  $assigned
     */
    private function bestRemainderKey(
        array $candidates,
        array &$evalCache,
        array $shortlist,
        array $base,
        array $assigned,
        int $categoryId,
        int $remainder,
    ): string {
        $best = $shortlist[0];
        $bestGain = -1.0;

        foreach ($shortlist as $key) {
            $current = $this->scenarioValue($candidates, $evalCache, $key, $base[$key], $categoryId, $assigned[$key]);
            $next = $this->scenarioValue($candidates, $evalCache, $key, $base[$key], $categoryId, $assigned[$key] + $remainder);
            $gain = $next - $current;

            if ($gain > $bestGain + 1e-9) {
                $best = $key;
                $bestGain = $gain;
            }
        }

        return $best;
    }

    /**
     * Rule có phủ danh mục `$categoryId` không (theo category_id, membership combo,
     * hoặc scope "other" áp mọi danh mục).
     *
     * @param  array<string, mixed>  $rule
     */
    private function ruleMatchesCategory(array $rule, int $categoryId): bool
    {
        if (($rule['scope_type'] ?? 'category') === 'other') {
            return true;
        }

        $members = $rule['combo_category_ids'] ?? [];

        if ($members !== []) {
            return in_array($categoryId, array_map('intval', $members), true);
        }

        return (int) ($rule['category_id'] ?? 0) === $categoryId;
    }

    /**
     * Tổng hoàn của MỘT thẻ với `$baseCats` + `$categoryId => $amount` (rỗng ⇒ 0).
     * Thẻ không đánh giá được (scenarioReason) coi như 0 để không được chọn.
     *
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<int, int>  $baseCats
     * @param  array<string, array<string, CardScenario>>  $evalCache
     */
    private function scenarioValue(
        array $candidates,
        array &$evalCache,
        string $key,
        array $baseCats,
        int $categoryId,
        int $amount,
    ): float {
        $cats = $baseCats;

        if ($amount > 0) {
            $cats[$categoryId] = $amount;
        }

        if ($cats === []) {
            return 0.0;
        }

        $scenario = $this->cachedScenario($candidates, $evalCache, $key, $cats);

        if ($scenario->scenarioReason !== null) {
            return 0.0;
        }

        return (float) $scenario->totalCashback;
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

            // Đánh giá theo phân bổ THÔ để bậc/min-spend đúng (tổng chi quyết định
            // bậc). Với thẻ Cake Freedom, chỉ CẮT phần HIỂN THỊ về ngân sách hữu
            // dụng (trần danh mục / tỷ lệ) — hoàn tiền đã được evaluator mô phỏng.
            $scenario = $this->cachedScenario($candidates, $evalCache, $key, $cats);

            if ($scenario->scenarioReason !== null) {
                continue;
            }

            $display = CakeFreedomRule::clampAllocation($candidates[$key], $cats);

            if ($display === []) {
                continue;
            }

            $scenarios[$key] = $scenario;
            $clean[$key] = $display;
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

        // Lens đối chứng: tiêu chí "ít thẻ" đối chứng bằng "tối đa hoàn tiền" và
        // ngược lại. Nhờ đó luôn có cả lens GỘP-THẺ (focus một thẻ) lẫn lens
        // TỐI-ĐA-HOÀN-TIỀN (cho phép tách) để hai phương án THỰC KHÁC NHAU có cơ
        // hội xuất hiện thay vì mọi cấu hình hội tụ về cùng một phân bổ.
        $contrast = $criterion === OptimizationCriterion::FEWEST_CARDS
            ? OptimizationCriterion::MAX_CASHBACK
            : OptimizationCriterion::FEWEST_CARDS;

        $base = [
            'prefer_owned' => false,
            'prefer_market' => false,
        ];

        $configs = [
            $this->withOrder($base, $orderAsc, ['criterion' => $criterion]),
            $this->withOrder($base, $orderDesc, ['criterion' => OptimizationCriterion::BALANCE]),
            $this->withOrder($base, $orderAsc, ['criterion' => $contrast]),
        ];

        // `focus_on_one` theo tiêu chí CỦA CHÍNH cấu hình (không mượn tiêu chí đang
        // chọn): chỉ lens "ít thẻ" mới gộp về một thẻ; lens tối đa-hoàn tiền được
        // phép tách danh mục khi việc tách làm tăng hoàn tiền.
        foreach ($configs as $index => $config) {
            $configs[$index]['focus_on_one'] = $config['criterion'] === OptimizationCriterion::FEWEST_CARDS;
        }

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
     * @param  array<int, string>  $categoryNames
     * @return array<string, mixed>
     */
    private function renderPlan(string $criterion, array $plan, int $rank, array $categoryNames): array
    {
        $cards = [];
        $allocatedTotal = 0.0;
        $uncertainTotal = 0.0;
        $ownedCards = 0;
        $newCards = 0;

        // Đếm số bản mỗi sản phẩm trong phương án để gắn nhãn "— Thẻ N" khi trùng.
        $groupCounts = [];

        foreach ($plan['scenarios'] as $scenario) {
            $groupKey = $scenario->groupKey();
            $groupCounts[$groupKey] = ($groupCounts[$groupKey] ?? 0) + 1;
        }

        foreach ($plan['allocation'] as $key => $cats) {
            $scenario = $plan['scenarios'][$key];
            $categories = [];
            $cardAllocated = 0.0;

            $groupKey = $scenario->groupKey();
            $displayName = $scenario->name;

            if (($groupCounts[$groupKey] ?? 1) > 1) {
                $displayName .= ' — Thẻ '.$scenario->instanceIndex;
            }

            foreach ($cats as $categoryId => $amount) {
                if ((int) $amount <= 0) {
                    continue;
                }

                $row = $scenario->perCategory[(int) $categoryId] ?? [];
                $eligible = (bool) ($row['eligible'] ?? false);

                $cardAllocated += (float) $amount;
                $allocatedTotal += (float) $amount;

                // Chi tiêu chưa xác định chắc chắn quyền lợi (không có rule/thẻ
                // không hoàn cho danh mục này) — nêu riêng, không gộp vào hoàn tiền.
                if (! $eligible) {
                    $uncertainTotal += (float) $amount;
                }

                $categories[] = [
                    'category_id' => (int) $categoryId,
                    'category_name' => $categoryNames[(int) $categoryId] ?? ('Danh mục #'.(int) $categoryId),
                    'amount' => Decimal::money((string) $amount),
                    'cashback' => (string) ($row['cashback'] ?? '0.00'),
                    'rate' => (float) ($row['rate'] ?? 0.0),
                    'percent' => $row['percent'] ?? null,
                    'eligible' => $eligible,
                    'reason' => $row['reason'] ?? null,
                    'caps_applied' => $row['caps_applied'] ?? [],
                ];
            }

            if ($scenario->isOwned) {
                $ownedCards++;
            } else {
                $newCards++;
            }

            $cards[] = [
                'key' => $key,
                'is_owned' => $scenario->isOwned,
                'name' => $displayName,
                'instance_index' => $scenario->instanceIndex,
                'product_key' => $scenario->productKey,
                'bank_name' => $scenario->bankName,
                'total_cashback' => $scenario->totalCashback,
                'allocated_total' => Decimal::money((string) (int) $cardAllocated),
                'rate' => $this->cardRate($scenario->totalCashback, $cats),
                'tier_label' => $scenario->tierLabel,
                'min_total_spend_met' => $scenario->minTotalSpendMet,
                'qualification_notice' => $scenario->qualificationNotice,
                'market_assumption' => $scenario->marketAssumption,
                'scenario_reason' => $scenario->scenarioReason,
                'simulated_transactions' => $scenario->simulatedTransactions,
                'depends_on_splitting' => $scenario->dependsOnSplitting,
                'depends_on_online' => $scenario->dependsOnOnline,
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
            'distinct_products' => count($groupCounts),
            'total_cashback' => $totalCashback,
            'total_rate' => $this->rate($plan['total'], $totalInput),
            'total_allocated' => Decimal::money((string) (int) $allocatedTotal),
            'uncertain_total' => Decimal::money((string) (int) $uncertainTotal),
            'owned_cards' => $ownedCards,
            'new_cards' => $newCards,
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

        // Cảnh báo khi phương án dùng nhiều thẻ CÙNG một sản phẩm: mô hình chính
        // sách hiện hành chỉ định nghĩa trần/quota theo TỪNG thẻ (gắn version của
        // thẻ), KHÔNG có trường trần cấp khách hàng/sản phẩm — nên N bản = N×trần.
        // Nếu sản phẩm thực tế giới hạn theo khách hàng, kết quả có thể cao hơn.
        $hasCopies = false;

        foreach ($plans as $plan) {
            $groups = [];

            foreach ($plan['scenarios'] as $scenario) {
                $groupKey = $scenario->groupKey();
                $groups[$groupKey] = ($groups[$groupKey] ?? 0) + 1;
            }

            foreach ($groups as $count) {
                if ($count > 1) {
                    $hasCopies = true;
                    break 2;
                }
            }
        }

        if ($hasCopies) {
            $notes[] = 'Phương án dùng nhiều thẻ CÙNG một sản phẩm: hệ thống áp trần/quota theo TỪNG thẻ như mô hình chính sách hiện hành. Nếu sản phẩm có giới hạn cấp khách hàng/sản phẩm chưa được mô hình hóa, tiền hoàn thực tế có thể thấp hơn.';
        }

        $usesSplitting = false;
        $usesOnline = false;

        foreach ($plans as $plan) {
            foreach ($plan['scenarios'] as $scenario) {
                if ($scenario->dependsOnSplitting) {
                    $usesSplitting = true;
                }

                if ($scenario->dependsOnOnline) {
                    $usesOnline = true;
                }
            }
        }

        if ($usesSplitting) {
            $notes[] = 'Một số thẻ giả định CHIA khoản chi thành nhiều giao dịch để tận dụng trần hoàn tiền theo từng giao dịch; tiền hoàn thực tế có thể thấp hơn nếu bạn không chia giao dịch như mô phỏng.';
        }

        if ($usesOnline) {
            $notes[] = 'Một số thẻ giả định khoản chi được THANH TOÁN TRỰC TUYẾN để hưởng ưu đãi online; tổng chi tiêu vẫn đúng bằng số bạn nhập. Nếu bạn thanh toán tại quầy, tiền hoàn có thể thấp hơn.';
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
