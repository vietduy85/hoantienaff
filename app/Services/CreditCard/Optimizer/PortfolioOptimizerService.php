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

    /**
     * Số biến thể bỏ-qua-từng-nguồn tối đa khi bù ngưỡng tổng chi tối thiểu — giữ
     * chi phí đánh giá hữu hạn mà vẫn tránh được nguồn filler gây hại.
     */
    private const TIER_FILL_SKIPS = 6;

    /**
     * Số vòng bù ngưỡng tổng chi tối thiểu liên tiếp tối đa (dừng sớm khi không
     * còn cải thiện) — cho phép các kích hoạt bậc hai (bù ngưỡng này mở đường bù
     * ngưỡng khác).
     */
    private const TIER_FILL_PASSES = 2;

    /**
     * Số tổ hợp "mức chi danh mục sinh hoàn đặt lên thẻ đích" tối đa dựng cho mỗi
     * thẻ có điều kiện hoàn tiền đặc biệt. Đây là phép TÌM KÍCH HOẠT PHỐI HỢP: đưa
     * đồng thời danh mục SINH HOÀN và chi tiêu NỀN (filler) lên thẻ đích, khác với
     * `SpendingConditionAllocator::activate()` chỉ dồn filler. Chặn để chi phí hữu
     * hạn (mỗi thẻ chỉ có vài danh mục sinh hoàn).
     */
    private const ACTIVATION_COMBO_LIMIT = 36;

    /**
     * Số tổ hợp kích hoạt tốt nhất (theo điểm thô) được đưa vào tinh chỉnh đắt
     * (`improveAllocation` + `expandToTierMinimums`) sau khi đánh giá nhanh.
     */
    private const ACTIVATION_REFINE_LIMIT = 2;

    /**
     * Số vòng của phép "tái cân bằng filler theo ngưỡng": chuyển một danh mục sang
     * thẻ có tỷ lệ hoàn cao hơn rồi BÙ LẠI ngưỡng của thẻ nguồn bằng filler rẻ nhất.
     * Phép này xử lý trường hợp `improveCategory()` không thấy được vì nó đánh giá
     * ngay khi thẻ nguồn còn hụt ngưỡng (tổng tạm giảm nên bị từ chối).
     */
    private const REBALANCE_PASSES = 2;

    public function __construct(
        private readonly StatementPeriodService $periods,
        private readonly PolicyEngineService $engine,
        private readonly TierResolverService $tiers,
        private readonly CardScenarioEvaluator $evaluator,
        private readonly OnlinePaymentEligibilityResolver $online,
        private readonly SpendingConditionAllocator $conditions,
    ) {}

    /**
     * Nhật ký quyết định của lần chạy hiện tại — TÁI TẠO ở đầu mỗi `run()` và
     * gắn vào phương án tương ứng trong `optimize()`. Null khi không trong `run`.
     */
    private ?OptimizationAudit $audit = null;

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
            $result = $this->run($candidates, $positiveMap, $singles, $amounts, $config);

            if ($result === null) {
                continue;
            }

            $plan = $this->planFrom($candidates, $result['allocation']);

            if ($plan !== null) {
                $plan['audit'] = $result['audit']->entries();
                $plan['excluded'] = $this->excludedCandidates($candidates, $plan, $amounts);
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
            $rendered[] = $this->renderPlan($criterion, $plan, $index + 1, $categoryNames, $amounts, $candidates);
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
     * @return array{allocation: array<string, array<int, int>>, audit: OptimizationAudit}|null
     */
    private function run(array $candidates, array $positiveMap, array $singles, array $amounts, array $config): ?array
    {
        if ($candidates === []) {
            return null;
        }

        $this->audit = new OptimizationAudit();
        $this->audit->record([
            'type' => OptimizationAudit::STEP_CANDIDATES,
            'candidate_count' => count($candidates),
            'category_count' => count($amounts),
            'criterion' => $config['criterion'],
            'prefer_owned' => (bool) $config['prefer_owned'],
        ]);

        $allocation = [];

        // Gieo: mỗi danh mục giao NGUYÊN cho ứng viên điểm cao nhất theo tiêu chí.
        foreach ($config['order'] as $categoryId) {
            $amount = (int) $amounts[$categoryId];

            if ($amount <= 0) {
                continue;
            }

            $bestKey = $this->bestSeedKey($config, $candidates, $positiveMap, $singles, $allocation, (int) $categoryId, $amount);

            if ($bestKey === null) {
                $this->audit->record([
                    'type' => OptimizationAudit::STEP_REJECT_CATEGORY,
                    'category_id' => (int) $categoryId,
                    'amount' => $amount,
                    'reason' => 'no_positive_candidate',
                ]);

                continue;
            }

            $allocation[$bestKey][(int) $categoryId] = $amount;
            $this->audit->record([
                'type' => OptimizationAudit::STEP_SEED,
                'category_id' => (int) $categoryId,
                'amount' => $amount,
                'card_key' => $bestKey,
                'card_name' => $candidates[$bestKey]->name,
                'is_owned' => $candidates[$bestKey]->isOwned,
                'single' => $singles[$bestKey][(int) $categoryId] ?? null,
            ]);
        }

        if ($allocation === []) {
            return null;
        }

        $allocation = $this->improveAllocation($config, $candidates, $positiveMap, $singles, $amounts, $allocation);

        // Nạp đủ ngưỡng tổng chi tối thiểu của bậc TRƯỚC khi kích hoạt điều kiện
        // hoàn tiền: điều kiện là GATE, chỉ đáng kích hoạt khi phần chi tiêu nền của
        // thẻ đã ở trạng thái tốt (đã bù ngưỡng). Kích hoạt trên nền còn "thô" khiến
        // các danh mục sinh hoàn bị đẩy về thẻ hoàn cao hơn và thẻ có điều kiện bị bỏ.
        $evalCache = [];
        $allocation = $this->expandToTierMinimums($config, $candidates, $positiveMap, $singles, $amounts, $allocation, $evalCache);

        // Kích hoạt "điều kiện hoàn tiền đặc biệt": chủ động dồn phần chi tiêu đủ
        // điều kiện sang thẻ đang có điều kiện, rồi cải thiện lại; chỉ nhận nếu
        // tổng hoàn tiền THẬT cao hơn.
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

            // KÍCH HOẠT PHỐI HỢP: chỉ dồn filler (như `activate()`) là chưa đủ khi
            // thẻ còn danh mục SINH HOÀN chưa đặt lên chính nó — cần đưa ĐỒNG THỜI
            // chi tiêu nền + danh mục sinh hoàn lên thẻ đích, rồi bù lại ngưỡng của
            // các thẻ nguồn bị hụt. Xem `injectActivationRewards()`.
            $activated = $this->injectActivationRewards(
                $config, $candidates, $positiveMap, $singles, $amounts, $activated, $scaffold->key, $evalCache,
            );

            $activated = $this->improveAllocation($config, $candidates, $positiveMap, $singles, $amounts, $activated);

            if ($activated === []) {
                continue;
            }

            // Bù lại ngưỡng tổng chi tối thiểu của các thẻ nguồn vừa bị rút bớt khi
            // đưa danh mục sinh hoàn sang thẻ đích (ví dụ thẻ gate 8tr bị hụt sau khi
            // chuyển bớt danh mục, phải được nạp lại filler để giữ bậc).
            $activated = $this->expandToTierMinimums($config, $candidates, $positiveMap, $singles, $amounts, $activated, $evalCache);

            if ($activated === []) {
                continue;
            }

            $total = $this->allocationValue($candidates, $activated, $evalCache)['total'];

            if ($total > $bestTotal + self::ACTIVATION_EPSILON) {
                $best = $activated;
                $bestTotal = $total;
            }
        }

        // Kích hoạt NGƯỠNG TỔNG CHI TỐI THIỂU của bậc (`tier.min_total_spend`):
        // bù đủ ngưỡng bằng cách CHUYỂN chi tiêu hoàn thấp/0% từ nơi ít giá trị
        // nhất sang thẻ còn thiếu, rồi cải thiện lại. Chỉ nhận khi tổng hoàn tiền
        // THẬT cao hơn — nhờ đó chi tiêu hoàn thấp chỉ dùng để kích hoạt khi có lợi.
        $best = $this->expandToTierMinimums($config, $candidates, $positiveMap, $singles, $amounts, $best, $evalCache);

        // TÁI CÂN BẰNG FILLER: chuyển danh mục hoàn cao sang thẻ hoàn cao hơn rồi
        // bù ngưỡng thẻ nguồn bằng filler rẻ nhất — bắt được các phối hợp mà bước
        // cải thiện cục bộ bỏ sót (ví dụ Ăn uống đang nằm trên thẻ 4% thay vì 20%).
        $best = $this->rebalanceGatedFiller($config, $candidates, $positiveMap, $singles, $amounts, $best, $evalCache);

        // Rót lại lần cuối: tái cân bằng có thể đã GỘP một danh mục về một thẻ
        // (chỉ xét giao TRỌN danh mục); bước cải thiện cục bộ sau cùng cho phép
        // TÁCH lại danh mục khi các thẻ có trần/quota độc lập và tổng hoàn tăng.
        $best = $this->improveAllocation($config, $candidates, $positiveMap, $singles, $amounts, $best);

        // Danh mục KHÔNG được thẻ nào giữ → giữ lại chưa phân bổ (ghi nhật ký).
        foreach ($amounts as $categoryId => $amount) {
            $allocated = 0;

            foreach ($best as $cats) {
                $allocated += (int) ($cats[(int) $categoryId] ?? 0);
            }

            if ($allocated <= 0) {
                $this->audit->record([
                    'type' => OptimizationAudit::STEP_UNALLOCATED,
                    'category_id' => (int) $categoryId,
                    'amount' => (int) $amount,
                    'reason' => 'không thẻ nào hoàn tiền cho danh mục này.',
                ]);
            }
        }

        $this->audit->record([
            'type' => OptimizationAudit::STEP_FINAL,
            'card_count' => count($best),
            'allocated_total' => array_sum(array_map('array_sum', $best)),
            'total_cashback' => $this->allocationValue($candidates, $best, $evalCache)['total'],
        ]);

        return ['allocation' => $best, 'audit' => $this->audit];
    }

    /**
     * Bù đủ ngưỡng tổng chi tối thiểu cho từng thẻ còn thiếu. Với mỗi thẻ, thử
     * một số phương án "chuyển filler" (tham lam theo lợi ích biên thấp nhất, kèm
     * các biến thể bỏ-qua-từng-nguồn để tránh nguồn gây hại), cải thiện lại toàn
     * bộ rồi chỉ nhận nếu tổng hoàn tiền tăng.
     *
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array{any_all: bool, cats: array<int, true>}>  $positiveMap
     * @param  array<string, array<int, array<string, mixed>>>  $singles
     * @param  array<int, int>  $amounts
     * @param  array<string, array<int, int>>  $allocation
     * @param  array<string, array<string, CardScenario>>  $evalCache
     * @return array<string, array<int, int>>
     */
    private function expandToTierMinimums(
        array $config,
        array $candidates,
        array $positiveMap,
        array $singles,
        array $amounts,
        array $allocation,
        array &$evalCache,
    ): array {
        $best = $allocation;
        $bestTotal = $this->allocationValue($candidates, $best, $evalCache)['total'];

        for ($pass = 0; $pass < self::TIER_FILL_PASSES; $pass++) {
            $improved = false;

            foreach ($candidates as $key => $scaffold) {
                $current = array_sum($best[$key] ?? []);
                $need = null;

                foreach ($scaffold->tiers as $tier) {
                    $min = (float) $tier->min_total_spend;

                    if ($min > $current) {
                        $need = (int) round($min - $current);
                        break;
                    }
                }

                if ($need === null || $need <= 0) {
                    continue;
                }

                $sources = $this->fillerSources($candidates, $positiveMap, $evalCache, $best, $key);

                if ($sources === []) {
                    continue;
                }

                $this->audit?->record([
                    'type' => OptimizationAudit::STEP_TIER_FILL,
                    'card_key' => $key,
                    'card_name' => $scaffold->name,
                    'current' => $current,
                    'need' => $need,
                    'source_count' => count($sources),
                    'pass' => $pass,
                ]);

                $variants = [$this->fillFromSources($best, $key, $need, $sources)];
                $skipLimit = min(count($sources), self::TIER_FILL_SKIPS);

                for ($skip = 0; $skip < $skipLimit; $skip++) {
                    $filtered = $sources;
                    array_splice($filtered, $skip, 1);
                    $variants[] = $this->fillFromSources($best, $key, $need, $filtered);
                }

                foreach ($variants as $working) {
                    if ($working === null) {
                        continue;
                    }

                    $working = $this->improveAllocation($config, $candidates, $positiveMap, $singles, $amounts, $working);
                    $total = $this->allocationValue($candidates, $working, $evalCache)['total'];

                    if ($total > $bestTotal + self::ACTIVATION_EPSILON) {
                        $best = $working;
                        $bestTotal = $total;
                        $improved = true;
                    }
                }
            }

            if (! $improved) {
                break;
            }
        }

        return $best;
    }

    /**
     * Chuyển dần từ các `$sources` (đã xếp lợi ích biên tăng dần) sang thẻ `$key`
     * cho tới khi bù đủ `$need` (giữ nguyên tổng mỗi danh mục). Trả `null` nếu
     * không đủ nguồn để bù đủ ngưỡng.
     *
     * @param  array<string, array<int, int>>  $allocation
     * @param  list<array{key: string, category: int, amount: int, rate: float}>  $sources
     * @return array<string, array<int, int>>|null
     */
    private function fillFromSources(array $allocation, string $key, int $need, array $sources): ?array
    {
        $working = $allocation;
        $moved = 0;

        foreach ($sources as $source) {
            if ($moved >= $need) {
                break;
            }

            $from = $source['key'];
            $cat = $source['category'];
            $have = (int) ($working[$from][$cat] ?? 0);

            if ($have <= 0) {
                continue;
            }

            $max = isset($source['amount']) ? min($have, (int) $source['amount']) : $have;
            $take = min($max, $need - $moved);
            $working[$from][$cat] = $have - $take;

            if ($working[$from][$cat] <= 0) {
                unset($working[$from][$cat]);
            }

            if (($working[$from] ?? []) === []) {
                unset($working[$from]);
            }

            $working[$key][$cat] = (int) ($working[$key][$cat] ?? 0) + $take;
            $moved += $take;
        }

        return $moved >= $need ? $working : null;
    }

    /**
     * Nguồn "filler" để bù ngưỡng: mọi phần phân bổ trên thẻ KHÁC. Xếp theo thứ
     * tự: (1) danh mục thẻ ĐÍCH có rule dương trước (để tiền rót vào hưởng ưu đãi
     * ngay), (2) rồi lợi ích biên hiện tại TĂNG DẦN — nơi hoàn thấp nhất chuyển
     * trước để chi phí cơ hội nhỏ nhất.
     *
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array{any_all: bool, cats: array<int, true>}>  $positiveMap
     * @param  array<string, array<string, CardScenario>>  $evalCache
     * @param  array<string, array<int, int>>  $allocation
     * @return list<array{key: string, category: int, amount: int, rate: float, target_positive: int}>
     */
    private function fillerSources(array $candidates, array $positiveMap, array &$evalCache, array $allocation, string $targetKey): array
    {
        $targetMap = $positiveMap[$targetKey] ?? ['any_all' => false, 'cats' => []];
        $sources = [];

        foreach ($allocation as $from => $cats) {
            if ($from === $targetKey || ($cats ?? []) === []) {
                continue;
            }

            $scenario = $this->cachedScenario($candidates, $evalCache, $from, $cats);

            foreach ($cats as $categoryId => $amount) {
                $amount = (int) $amount;

                if ($amount <= 0) {
                    continue;
                }

                $row = $scenario->perCategory[(int) $categoryId] ?? [];
                $targetPositive = $this->isPositive($targetMap, (int) $categoryId) ? 0 : 1;

                $sources[] = [
                    'key' => $from,
                    'category' => (int) $categoryId,
                    'amount' => $amount,
                    'rate' => (float) ($row['rate'] ?? 0.0),
                    'target_positive' => $targetPositive,
                ];
            }
        }

        usort($sources, fn (array $a, array $b): int => [$a['target_positive'], $a['rate'], -$a['amount'], $a['category']]
            <=> [$b['target_positive'], $b['rate'], -$b['amount'], $b['category']]);

        return $sources;
    }

    /**
     * Nguồn filler CHỈ lấy từ PHẦN DƯ TRÊN NGƯỠNG của mỗi thẻ nguồn (tổng chi của
     * thẻ trừ đi ngưỡng tối thiểu mà nó đang đạt). Nhờ vậy việc bù ngưỡng cho thẻ
     * đích KHÔNG làm thẻ nguồn tụt xuống dưới ngưỡng của chính nó — biến phép đổi
     * chỗ thành cải thiện RÒNG thật. Mỗi nguồn trả kèm `amount` = phần tối đa được
     * rút, đúng bằng phần dư của thẻ nguồn phân bổ dần theo danh mục hoàn thấp trước.
     *
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array{any_all: bool, cats: array<int, true>}>  $positiveMap
     * @param  array<string, array<string, CardScenario>>  $evalCache
     * @param  array<string, array<int, int>>  $allocation
     * @return list<array{key: string, category: int, amount: int, rate: float, target_positive: int}>
     */
    private function surplusFillerSources(array $candidates, array $positiveMap, array &$evalCache, array $allocation, string $targetKey): array
    {
        $targetMap = $positiveMap[$targetKey] ?? ['any_all' => false, 'cats' => []];
        $sources = [];

        foreach ($allocation as $from => $cats) {
            if ($from === $targetKey || ($cats ?? []) === [] || ! isset($candidates[$from])) {
                continue;
            }

            $total = (int) array_sum($cats);
            $surplus = $total - $this->satisfiedGate($candidates[$from], $total);

            if ($surplus <= 0) {
                continue;
            }

            $scenario = $this->cachedScenario($candidates, $evalCache, $from, $cats);

            $rows = [];

            foreach ($cats as $categoryId => $amount) {
                $amount = (int) $amount;

                if ($amount <= 0) {
                    continue;
                }

                $row = $scenario->perCategory[(int) $categoryId] ?? [];

                $rows[] = [
                    'category' => (int) $categoryId,
                    'amount' => $amount,
                    'rate' => (float) ($row['rate'] ?? 0.0),
                    'target_positive' => $this->isPositive($targetMap, (int) $categoryId) ? 0 : 1,
                ];
            }

            // Rút phần dư theo danh mục hoàn thấp nhất trước để tổn thất nhỏ nhất.
            usort($rows, fn (array $a, array $b): int => [$a['rate'], $a['category']]
                <=> [$b['rate'], $b['category']]);

            $remaining = $surplus;

            foreach ($rows as $row) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($row['amount'], $remaining);

                $sources[] = [
                    'key' => $from,
                    'category' => $row['category'],
                    'amount' => $take,
                    'rate' => $row['rate'],
                    'target_positive' => $row['target_positive'],
                    'loss' => $this->categoryRemovalLoss($candidates, $evalCache, $from, $cats, $row['category'], $take),
                ];

                $remaining -= $take;
            }
        }

        usort($sources, fn (array $a, array $b): int => [$a['loss'], $a['target_positive'], $a['rate'], $a['key'], $a['category']]
            <=> [$b['loss'], $b['target_positive'], $b['rate'], $b['key'], $b['category']]);

        return $sources;
    }

    /**
     * KÍCH HOẠT PHỐI HỢP cho MỘT thẻ có "điều kiện hoàn tiền đặc biệt".
     *
     * `SpendingConditionAllocator::activate()` chỉ dồn ĐÚNG phần chi tiêu nền (filler)
     * để đạt điều kiện — nhưng điều kiện là GATE, nên nếu thẻ đích còn danh mục SINH
     * HOÀN chưa đặt lên chính nó thì việc dồn filler là VÔ NGHĨA (đạt điều kiện nhưng
     * vẫn 0 đồng) và còn làm hụt hoàn ở thẻ nguồn. Hàm này đưa ĐỒNG THỜI:
     *   (1) danh mục SINH HOÀN của thẻ đích lên chính nó (mức chi thử theo trần hữu
     *       dụng + các mốc 25/50/75%/toàn phần và lượng từng thẻ nguồn đang giữ), và
     *   (2) giữ nguyên phần chi tiêu nền đã dồn để điều kiện vẫn đạt;
     * rồi BÙ LẠI ngưỡng tổng chi của các thẻ nguồn vừa bị rút bớt (filler khác).
     *
     * Chỉ nhận phương án làm TĂNG tổng hoàn thật (do `CardScenarioEvaluator` chấm),
     * và tinh chỉnh lại bằng chính các bước cục bộ sẵn có.
     *
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array{any_all: bool, cats: array<int, true>}>  $positiveMap
     * @param  array<string, array<int, array<string, mixed>>>  $singles
     * @param  array<int, int>  $amounts
     * @param  array<string, array<int, int>>  $activation  phân bổ ĐÃ đạt điều kiện (filler đã dồn)
     * @param  array<string, array<string, CardScenario>>  $evalCache
     * @return array<string, array<int, int>>
     */
    private function injectActivationRewards(
        array $config,
        array $candidates,
        array $positiveMap,
        array $singles,
        array $amounts,
        array $activation,
        string $targetKey,
        array &$evalCache,
    ): array {
        $scaffold = $candidates[$targetKey] ?? null;

        if ($scaffold === null || $scaffold->qualification === null) {
            return $activation;
        }

        $rewardCats = [];

        foreach ($amounts as $categoryId => $budget) {
            if ((int) $budget > 0 && $this->isPositive($positiveMap[$targetKey], (int) $categoryId)) {
                $rewardCats[] = (int) $categoryId;
            }
        }

        if ($rewardCats === []) {
            return $activation;
        }

        $best = $activation;
        $bestTotal = $this->allocationValue($candidates, $best, $evalCache)['total'];

        $options = [];

        foreach ($rewardCats as $category) {
            $options[$category] = $this->rewardAmountOptions($scaffold, $singles, $amounts, $category, $activation, $targetKey);
        }

        $ranked = [];

        foreach ($this->rewardCombos($options, self::ACTIVATION_COMBO_LIMIT) as $desired) {
            $trial = $activation;

            foreach ($desired as $category => $amount) {
                $this->setTargetHolding($trial, $targetKey, (int) $category, (int) $amount, $candidates, $evalCache);
            }

            $trial = $this->repairDroppedGates(
                $trial, $activation, $candidates, $positiveMap, $singles, $amounts, $targetKey, $rewardCats, $evalCache,
            );

            $total = $this->allocationValue($candidates, $trial, $evalCache)['total'];

            if ($total > $bestTotal + self::ACTIVATION_EPSILON) {
                $ranked[] = ['allocation' => $trial, 'total' => $total];
            }
        }

        if ($ranked === []) {
            return $best;
        }

        usort($ranked, fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        $ranked = array_slice($ranked, 0, self::ACTIVATION_REFINE_LIMIT);

        foreach ($ranked as $entry) {
            $candidate = $this->improveAllocation(
                $config, $candidates, $positiveMap, $singles, $amounts, $entry['allocation'],
            );

            $candidate = $this->expandToTierMinimums(
                $config, $candidates, $positiveMap, $singles, $amounts, $candidate, $evalCache,
            );

            if ($candidate === []) {
                continue;
            }

            $total = $this->allocationValue($candidates, $candidate, $evalCache)['total'];

            if ($total > $bestTotal + self::ACTIVATION_EPSILON) {
                $best = $candidate;
                $bestTotal = $total;

                $this->audit?->record([
                    'type' => OptimizationAudit::STEP_ACTIVATION,
                    'card_key' => $targetKey,
                    'card_name' => $scaffold->name,
                    'before' => $entry['total'],
                    'after' => $total,
                ]);
            }
        }

        return $best;
    }

    /**
     * Các mức chi tiềm năng đặt danh mục SINH HOÀN lên thẻ đích: 0, trần hữu dụng,
     * toàn phần, các mốc 25/50/75%, và lượng từng thẻ nguồn đang giữ (để thử "rút
     * trọn" một nguồn). Luôn nằm trong [0, ngân sách danh mục].
     *
     * @param  array<string, array<int, array<string, mixed>>>  $singles
     * @param  array<int, int>  $amounts
     * @param  array<string, array<int, int>>  $activation
     * @return list<int>
     */
    private function rewardAmountOptions(
        CardScaffold $scaffold,
        array $singles,
        array $amounts,
        int $category,
        array $activation,
        string $targetKey,
    ): array {
        $budget = (int) ($amounts[$category] ?? 0);

        if ($budget <= 0) {
            return [0];
        }

        $options = [0, $budget];
        $cap = $this->usefulCategoryCap($scaffold, $category, $budget);

        if ($cap > 0 && $cap < $budget) {
            $options[] = $cap;
        }

        foreach ([0.25, 0.5, 0.75] as $fraction) {
            $options[] = (int) round($budget * $fraction);
        }

        foreach ($activation as $key => $cats) {
            if ($key === $targetKey) {
                continue;
            }

            $have = (int) ($cats[$category] ?? 0);

            if ($have > 0) {
                $options[] = $have;
            }
        }

        $options = array_values(array_unique(array_filter(
            $options,
            fn (int $value): bool => $value >= 0 && $value <= $budget,
        )));

        sort($options);

        return $options;
    }

    /**
     * Trần chi tiêu HỮU DỤNG của một danh mục trên MỘT thẻ (điểm chạm trần hoàn
     * danh mục, suy từ policy thật): `max_cashback_per_category/rate`. Không có
     * trần ⇒ toàn bộ ngân sách.
     */
    private function usefulCategoryCap(CardScaffold $scaffold, int $category, int $budget): int
    {
        $cap = null;

        foreach ($scaffold->allEnabledRules as $rule) {
            $percent = (float) ($rule['cashback_percent'] ?? 0.0);
            $capCat = $rule['max_cashback_per_category_per_period'] ?? null;

            if ($percent <= 0.0 || $capCat === null || (float) $capCat <= 0.0) {
                continue;
            }

            if (! $this->ruleMatchesCategory($rule, $category)) {
                continue;
            }

            $amount = (int) round((float) $capCat / ($percent / 100.0));
            $cap = $cap === null ? $amount : min($cap, $amount);
        }

        return $cap === null ? $budget : max(0, min($budget, $cap));
    }

    /**
     * Tổ hợp ĐẶT ĐỒNG THỜI nhiều danh mục sinh hoàn lên thẻ đích (tích Descartes
     * các mức ở {@see rewardAmountOptions()}), chặn số lượng để chi phí hữu hạn.
     *
     * @param  array<int, list<int>>  $options
     * @return list<array<int, int>>
     */
    private function rewardCombos(array $options, int $limit): array
    {
        $combos = [[]];

        foreach ($options as $category => $values) {
            $next = [];

            foreach ($combos as $combo) {
                foreach ($values as $value) {
                    $next[] = $combo + [(int) $category => (int) $value];
                }
            }

            $combos = $next;
        }

        if (count($combos) <= $limit) {
            return $combos;
        }

        // Giữ tổ hợp rải đều (gồm cả tổ hợp cuối) để vẫn với tới mức "toàn phần".
        $result = [];
        $count = count($combos);

        for ($i = 0; $i < $limit; $i++) {
            $result[] = $combos[(int) floor($i * ($count - 1) / max(1, $limit - 1))];
        }

        return $result;
    }

    /**
     * Đặt thẻ `$targetKey` giữ đúng `$desired` của `$category` bằng cách RÚT từ
     * các thẻ khác, ưu tiên nơi có TỔN THẤT HOÀN THẬT nhỏ nhất trước (đánh giá
     * bằng chính `CardScenarioEvaluator`, không dùng tỷ lệ danh nghĩa). Không tạo
     * thêm tiền; tổng danh mục bảo toàn.
     *
     * @param  array<string, array<int, int>>  $allocation  cập nhật TẠI CHỖ
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array<string, CardScenario>>  $evalCache
     */
    private function setTargetHolding(
        array &$allocation,
        string $targetKey,
        int $category,
        int $desired,
        array $candidates,
        array &$evalCache,
    ): void {
        $current = (int) ($allocation[$targetKey][$category] ?? 0);
        $need = $desired - $current;

        if ($need <= 0) {
            return;
        }

        $sources = [];

        foreach ($allocation as $key => $cats) {
            if ($key === $targetKey) {
                continue;
            }

            $have = (int) ($cats[$category] ?? 0);

            if ($have <= 0) {
                continue;
            }

            $take = min($have, $need);

            $sources[] = [
                'key' => $key,
                'take' => $take,
                'loss' => $this->categoryRemovalLoss($candidates, $evalCache, $key, $cats, $category, $take),
            ];
        }

        usort($sources, fn (array $a, array $b): int => [$a['loss'], $a['key']] <=> [$b['loss'], $b['key']]);

        foreach ($sources as $source) {
            if ($need <= 0) {
                break;
            }

            $from = $source['key'];
            $take = min((int) ($allocation[$from][$category] ?? 0), $need);

            if ($take <= 0) {
                continue;
            }

            $allocation[$from][$category] = (int) $allocation[$from][$category] - $take;

            if ($allocation[$from][$category] <= 0) {
                unset($allocation[$from][$category]);
            }

            if (($allocation[$from] ?? []) === []) {
                unset($allocation[$from]);
            }

            $allocation[$targetKey][$category] = (int) ($allocation[$targetKey][$category] ?? 0) + $take;
            $need -= $take;
        }
    }

    /**
     * Tổn thất hoàn THẬT khi rút `$take` của `$category` khỏi thẻ `$key` (đánh giá
     * bằng evaluator). Dùng để xếp nguồn rút rẻ nhất trước.
     *
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array<string, CardScenario>>  $evalCache
     * @param  array<int, int>  $cats
     */
    private function categoryRemovalLoss(
        array $candidates,
        array &$evalCache,
        string $key,
        array $cats,
        int $category,
        int $take,
    ): float {
        $beforeValue = $this->scenarioValue2($candidates, $evalCache, $key, $cats);

        $after = $cats;
        $after[$category] = (int) $after[$category] - $take;

        if ($after[$category] <= 0) {
            unset($after[$category]);
        }

        $afterValue = $this->scenarioValue2($candidates, $evalCache, $key, $after);

        return $beforeValue - $afterValue;
    }

    /**
     * Giá trị (tổng hoàn) của một thẻ với ĐÚNG tập danh mục `$cats`; thẻ không
     * đánh giá được ⇒ 0.
     *
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array<string, CardScenario>>  $evalCache
     * @param  array<int, int>  $cats
     */
    private function scenarioValue2(array $candidates, array &$evalCache, string $key, array $cats): float
    {
        if ($cats === []) {
            return 0.0;
        }

        $scenario = $this->cachedScenario($candidates, $evalCache, $key, $cats);

        return $scenario->scenarioReason === null ? (float) $scenario->totalCashback : 0.0;
    }

    /**
     * Bù LẠI ngưỡng tổng chi tối thiểu của các thẻ nguồn vừa bị rút bớt do kích
     * hoạt: nếu thẻ đang đạt một ngưỡng (gate) ở phân bổ `$base` mà ở `$trial` bị
     * tụt dưới ngưỡng đó, nạp filler từ nơi khác — KHÔNG rút lại danh mục sinh hoàn
     * của thẻ đích và không rút từ chính thẻ đích.
     *
     * @param  array<string, array<int, int>>  $trial  cập nhật TẠI CHỖ
     * @param  array<string, array<int, int>>  $base
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array{any_all: bool, cats: array<int, true>}>  $positiveMap
     * @param  array<string, array<int, array<string, mixed>>>  $singles
     * @param  array<int, int>  $amounts
     * @param  list<int>  $rewardCats
     * @param  array<string, array<string, CardScenario>>  $evalCache
     * @return array<string, array<int, int>>
     */
    private function repairDroppedGates(
        array $trial,
        array $base,
        array $candidates,
        array $positiveMap,
        array $singles,
        array $amounts,
        string $targetKey,
        array $rewardCats,
        array &$evalCache,
        bool $surplusOnly = false,
    ): array {
        foreach ($base as $key => $cats) {
            if ($key === $targetKey || ! isset($candidates[$key])) {
                continue;
            }

            $gate = $this->satisfiedGate($candidates[$key], (int) array_sum($cats));

            if ($gate <= 0) {
                continue;
            }

            $nowTotal = (int) array_sum($trial[$key] ?? []);

            if ($nowTotal >= $gate) {
                continue;
            }

            $need = $gate - $nowTotal;
            $sources = $surplusOnly
                ? $this->surplusFillerSources($candidates, $positiveMap, $evalCache, $trial, $key)
                : $this->fillerSources($candidates, $positiveMap, $evalCache, $trial, $key);

            $filtered = array_values(array_filter(
                $sources,
                fn (array $source): bool => $source['key'] !== $targetKey
                    && ! in_array((int) $source['category'], $rewardCats, true),
            ));

            if ($filtered === []) {
                continue;
            }

            $filled = $this->fillFromSources($trial, $key, $need, $filtered);

            if ($filled !== null) {
                $trial = $filled;
            }
        }

        return $trial;
    }

    /**
     * Ngưỡng tổng chi tối thiểu LỚN NHẤT mà thẻ đang đạt với tổng chi `$total`
     * (0 nếu chưa đạt ngưỡng dương nào). Dùng để biết thẻ "mất ngưỡng" nào khi bị
     * rút bớt chi tiêu.
     */
    private function satisfiedGate(CardScaffold $scaffold, int $total): int
    {
        $gate = 0;

        foreach ($scaffold->tiers as $tier) {
            $min = (float) $tier->min_total_spend;

            if ($min > 0.0 && $min <= $total + 0.001) {
                $gate = max($gate, (int) round($min));
            }
        }

        return $gate;
    }

    /**
     * TÁI CÂN BẰNG FILLER THEO NGƯỠNG (tổng quát cho mọi thẻ, mọi danh mục).
     *
     * Với từng danh mục, thử GIAO TRỌN danh mục cho một ứng viên khác rồi BÙ LẠI
     * ngưỡng tổng chi của những thẻ nguồn vừa bị rút xuống dưới ngưỡng bằng filler
     * RẺ NHẤT (qua {@see repairDroppedGates()}). Nhờ vậy thuật toán nhìn thấy lợi
     * ích RÒNG của việc "chuyển khoản chi hoàn cao sang thẻ hoàn cao hơn + lấp chỗ
     * trống bằng khoản hoàn thấp", điều mà {@see improveCategory()} bỏ sót vì nó
     * đánh giá ngay lúc thẻ nguồn còn hụt ngưỡng.
     *
     * Chỉ nhận khi tổng hoàn THẬT (evaluator) tăng.
     *
     * @param  array{order: list<int>, criterion: string, prefer_owned: bool, prefer_market: bool, spread: bool}  $config
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array{any_all: bool, cats: array<int, true>}>  $positiveMap
     * @param  array<string, array<int, array<string, mixed>>>  $singles
     * @param  array<int, int>  $amounts
     * @param  array<string, array<int, int>>  $allocation
     * @param  array<string, array<string, CardScenario>>  $evalCache
     * @return array<string, array<int, int>>
     */
    private function rebalanceGatedFiller(
        array $config,
        array $candidates,
        array $positiveMap,
        array $singles,
        array $amounts,
        array $allocation,
        array &$evalCache,
    ): array {
        $best = $allocation;
        $bestTotal = $this->allocationValue($candidates, $best, $evalCache)['total'];

        for ($pass = 0; $pass < self::REBALANCE_PASSES; $pass++) {
            $improved = false;

            foreach ($config['order'] as $categoryId) {
                $categoryId = (int) $categoryId;
                $total = (int) ($amounts[$categoryId] ?? 0);

                if ($total <= 0) {
                    continue;
                }

                $holders = [];

                foreach ($best as $key => $cats) {
                    if (((int) ($cats[$categoryId] ?? 0)) > 0) {
                        $holders[] = $key;
                    }
                }

                $shortlist = $this->shortlistFor(
                    $config, $candidates, $positiveMap, $singles, $best, $categoryId, $holders,
                );

                foreach ($shortlist as $targetKey) {
                    if (count($holders) === 1 && in_array($targetKey, $holders, true)) {
                        continue;
                    }

                    $moved = $this->withoutCategory($best, $holders, $categoryId);
                    $moved[$targetKey][$categoryId] = (int) ($moved[$targetKey][$categoryId] ?? 0) + $total;

                    $rewardCats = $this->positiveCategoryList($positiveMap[$targetKey] ?? []);
                    $rewardCats[] = $categoryId;

                    $moved = $this->repairDroppedGates(
                        $moved, $best, $candidates, $positiveMap, $singles, $amounts, $targetKey, $rewardCats, $evalCache, true,
                    );

                    $totalAfter = $this->allocationValue($candidates, $moved, $evalCache)['total'];

                    if ($totalAfter > $bestTotal + self::ACTIVATION_EPSILON) {
                        $beforeHolders = $this->categoryHolders($best, $categoryId);
                        $afterHolders = $this->categoryHolders($moved, $categoryId);

                        if (array_keys($beforeHolders) !== array_keys($afterHolders)) {
                            $this->audit?->record([
                                'type' => OptimizationAudit::STEP_REALLOCATE,
                                'category_id' => $categoryId,
                                'total' => $total,
                                'before' => $beforeHolders,
                                'after' => $afterHolders,
                            ]);
                        }

                        $best = $moved;
                        $bestTotal = $totalAfter;
                        $improved = true;

                        break;
                    }
                }
            }

            if (! $improved) {
                break;
            }
        }

        return $best;
    }

    /**
     * Danh sách danh mục SINH HOÀN (positive) của một ứng viên — dùng để bảo vệ
     * không rút chúng khi bù ngưỡng thẻ đích.
     *
     * @param  array{any_all?: bool, cats?: array<int, true>}  $map
     * @return list<int>
     */
    private function positiveCategoryList(array $map): array
    {
        return array_map('intval', array_keys($map['cats'] ?? []));
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
                return -INF;
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
        $bestScore = -INF;
        $hasPositive = false;

        foreach ($candidates as $key => $scaffold) {
            $positive = $this->isPositive($positiveMap[$key], $categoryId);

            if ($positive) {
                $hasPositive = true;
            }

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

        // Danh mục không có thẻ nào hoàn > 0% (và không ưu tiên thẻ sở hữu): để
        // nguyên CHƯA PHÂN BỔ thay vì dồn cả danh mục vào một thẻ hoàn 0%.
        if (! $hasPositive && ! $config['prefer_owned']) {
            return null;
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

        $beforeHolders = $this->categoryHolders($allocation, $categoryId);
        $allocation = $bestConfig['allocation'];
        $afterHolders = $this->categoryHolders($allocation, $categoryId);

        // Chỉ ghi khi TẬP THẺ GIỮ danh mục đổi (ý nghĩa: chuyển danh mục sang
        // thẻ khác) — bỏ qua các điều chỉnh số tiền nhỏ trong cùng một thẻ để
        // nhật ký không bị nhiễu bởi các vòng rót nước nội bộ.
        if (array_keys($beforeHolders) !== array_keys($afterHolders)) {
            $this->audit?->record([
                'type' => OptimizationAudit::STEP_REALLOCATE,
                'category_id' => $categoryId,
                'total' => $total,
                'before' => $beforeHolders,
                'after' => $afterHolders,
            ]);
        }

        return true;
    }

    /**
     * Danh sách thẻ đang giữ một danh mục kèm số tiền, sắp theo thứ tự key.
     *
     * @param  array<string, array<int, int>>  $allocation
     * @return array<string, int>
     */
    private function categoryHolders(array $allocation, int $categoryId): array
    {
        $holders = [];

        foreach ($allocation as $key => $cats) {
            $amount = (int) ($cats[$categoryId] ?? 0);

            if ($amount > 0) {
                $holders[$key] = $amount;
            }
        }

        return $holders;
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

                $chunk = $this->nextMarginalChunk(
                    $landmarks[$key]['points'],
                    $landmarks[$key]['mins'],
                    $assigned[$key],
                    $delta,
                    $total - $used,
                );
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

        // Chỉ gán phần ngân sách dư khi nó THỰC SỰ làm tăng hoàn tiền (thường là
        // để chạm ngưỡng tối thiểu/điều kiện của một thẻ). Nếu mọi ứng viên đều
        // 0%, GIỮ LẠI phần dư như "chưa phân bổ" thay vì dồn vào thẻ hoàn thấp.
        if ($used < $total) {
            $remainder = $total - $used;
            $dump = $this->bestRemainderKey(
                $candidates, $evalCache, $shortlist, $base, $assigned, $categoryId, $remainder,
            );

            if ($dump['gain'] > self::ACTIVATION_EPSILON) {
                $assigned[$dump['key']] += $remainder;
            }
        }

        foreach ($assigned as $key => $amount) {
            if ($amount > 0) {
                $result[$key][$categoryId] = $amount;
            }
        }

        return $result;
    }

    /**
     * Bước kế tiếp cho một ứng viên. Ưu tiên NHẢY THẲNG tới ngưỡng tổng chi tối
     * thiểu (tier `min_total_spend`) gần nhất nếu còn dưới ngưỡng — vì chi tiêu
     * dưới ngưỡng KHÔNG sinh hoàn nên phải nhảy qua, ngay cả khi khoảng nhảy lớn
     * hơn `$delta`. Khi đã ở trên ngưỡng (hoặc không có ngưỡng) thì bám MỐC gần
     * nhất KHÔNG vượt quá `$current + $delta` để không nhảy qua trần; nếu không có
     * mốc nào thì đi đúng một `$delta`.
     *
     * @param  list<int>  $landmarks  danh sách mốc TĂNG DẦN
     * @param  list<int>  $mins  danh sách ngưỡng tối thiểu TĂNG DẦN
     */
    private function nextMarginalChunk(array $landmarks, array $mins, int $current, int $delta, int $available): int
    {
        foreach ($mins as $min) {
            if ($min > $current) {
                $need = $min - $current;

                return $need <= $available ? $need : $delta;
            }
        }

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
     * Trả về:
     *   - `points`: các mốc chung (cắt dần theo `$delta`) để không nhảy qua trần.
     *   - `mins`: các NGƯỠNG tổng chi tối thiểu của bậc (đã trừ phần danh mục khác
     *     thẻ đang giữ) mà bộ rót nước phải NHẢY thẳng tới khi còn dưới ngưỡng.
     *
     * @param  array<string, mixed>  $single
     * @param  array<int, int>  $baseCats  danh mục khác thẻ đang giữ (để suy ngưỡng bậc)
     * @return array{points: list<int>, mins: list<int>}
     */
    private function marginalLandmarks(CardScaffold $scaffold, array $single, array $baseCats, int $categoryId, int $total): array
    {
        $points = [$total];
        $mins = [];

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
                $need = (int) round($min - $baseSpend);
                $points[] = $need;

                if ($need > 0 && $need <= $total) {
                    $mins[$need] = true;
                }
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

        $minList = array_keys($mins);
        sort($minList);

        return ['points' => $list, 'mins' => $minList];
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
     * Ứng viên nhận phần ngân sách dư: lợi ích biên cao nhất, kèm mức tăng hoàn
     * tiền tương ứng. Khi mọi ứng viên đều không tăng hoàn (gain 0) thì trả về
     * ứng viên đầu shortlist với gain = 0 — để bên gọi biết mà GIỮ LẠI khoản dư.
     *
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, array<string, CardScenario>>  $evalCache
     * @param  list<string>  $shortlist
     * @param  array<string, array<int, int>>  $base
     * @param  array<string, int>  $assigned
     * @return array{key: string, gain: float}
     */
    private function bestRemainderKey(
        array $candidates,
        array &$evalCache,
        array $shortlist,
        array $base,
        array $assigned,
        int $categoryId,
        int $remainder,
    ): array {
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

        return ['key' => $best, 'gain' => max(0.0, $bestGain)];
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

        if (abs($delta) > 1e-9) {
            return $delta > 0.0;
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

            // Đánh giá theo phân bổ THÔ (tổng chi quyết định bậc/min-spend). Phân
            // bổ HIỂN THỊ phải đúng bằng phân bổ đã được evaluator chấm điểm —
            // KHÔNG cắt bớt riêng cho hiển thị (nếu cắt, phần bị ẩn trông như
            // "chưa phân bổ" dù evaluator đã dùng nó để đạt điều kiện).
            $scenario = $this->cachedScenario($candidates, $evalCache, $key, $cats);

            if ($scenario->scenarioReason !== null) {
                continue;
            }

            // Bỏ phần chi tiêu CHỈ hưởng ưu đãi "các danh mục còn lại" (fallback)
            // mà không phục vụ kích hoạt ngưỡng tổng chi tối thiểu: phần dư đó để
            // CHƯA PHÂN BỔ thay vì dồn vào thẻ hoàn thấp cho "hết ngân sách".
            $pruned = $this->pruneUnneededFallback(
                $candidates[$key],
                $cats,
                $scenario->tierMinTotalSpend === null ? null : (float) $scenario->tierMinTotalSpend,
            );

            if ($this->serializeAllocation($pruned) !== $this->serializeAllocation($cats)) {
                $scenario = $this->cachedScenario($candidates, $evalCache, $key, $pruned);

                if ($scenario->scenarioReason !== null) {
                    continue;
                }
            }

            if ($pruned === []) {
                continue;
            }

            $scenarios[$key] = $scenario;
            $clean[$key] = $pruned;
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
     * Giới hạn phần chi tiêu CÒN SINH THÊM HOÀN trên một danh mục của thẻ:
     *   - null  ⇒ không suy được/không giới hạn (giữ nguyên phần đã gán).
     *   - 0.0   ⇒ danh mục chỉ hưởng ưu đãi "các danh mục còn lại" (fallback):
     *             không mang lại lợi ích trực tiếp, chỉ hữu ích để kích hoạt.
     *   - > 0   ⇒ trần hữu dụng (chỉ với Cake: trần danh mục / tỷ lệ).
     *
     * Với thẻ KHÔNG phải Cake, phân bổ được giữ nguyên như hành vi hiện hành
     * (trần danh mục chỉ chặn HOÀN TIỀN, không cắt phần hiển thị).
     */
    private function cashbackCapacity(CardScaffold $scaffold, int $categoryId): ?float
    {
        $scope = $this->ruleScopeForCategory($scaffold, $categoryId);

        if ($scope === 'other') {
            return 0.0;
        }

        if ($scope === 'specific' && CakeFreedomRule::appliesTo($scaffold)) {
            return CakeFreedomRule::budgetCapForCategory($scaffold, $categoryId);
        }

        return null;
    }

    /**
     * Cắt phần chi tiêu VƯỢT khả năng sinh hoàn và KHÔNG phục vụ kích hoạt ngưỡng
     * tổng chi tối thiểu của thẻ. Phần dư đó trả về ngân sách CHƯA PHÂN BỔ.
     *
     * Quy tắc (không dùng ngưỡng % cứng):
     *   - Danh mục có capacity null ⇒ giữ nguyên.
     *   - Danh mục có capacity > 0 (Cake) ⇒ giữ `min(số gán, capacity)`; phần vượt
     *     chỉ được giữ nếu cần để đạt ngưỡng kích hoạt.
     *   - Danh mục chỉ có fallback (capacity 0) ⇒ chỉ giữ phần cần cho ngưỡng.
     *
     * @param  array<int, int>  $cats
     * @return array<int, int>
     */
    private function pruneUnneededFallback(CardScaffold $scaffold, array $cats, ?float $tierMin): array
    {
        $useful = 0.0;
        $excess = [];

        foreach ($cats as $categoryId => $amount) {
            $amount = (int) $amount;

            if ($amount <= 0) {
                unset($cats[$categoryId]);

                continue;
            }

            $capacity = $this->cashbackCapacity($scaffold, (int) $categoryId);

            if ($capacity === null) {
                $useful += $amount;

                continue;
            }

            $cap = (int) min($amount, (int) round($capacity));
            $cats[$categoryId] = $cap;
            $useful += $cap;

            if ($amount > $cap) {
                $excess[(int) $categoryId] = $amount - $cap;
            }
        }

        if ($excess === []) {
            return array_filter($cats, fn (int $amount): bool => $amount > 0);
        }

        $required = (int) max(0.0, (float) ($tierMin ?? 0.0) - $useful);

        foreach ($excess as $categoryId => $extra) {
            if ($required <= 0) {
                break;
            }

            $take = min($extra, $required);
            $cats[$categoryId] += $take;
            $required -= $take;
        }

        return array_filter($cats, fn (int $amount): bool => $amount > 0);
    }

    /**
     * Vai trò rule của một danh mục trên thẻ: `specific` (danh mục/cụm cụ thể),
     * `other` (chỉ fallback "các danh mục còn lại"), hoặc `none` (không rule).
     */
    private function ruleScopeForCategory(CardScaffold $scaffold, int $categoryId): string
    {
        $hasOther = false;

        foreach ($scaffold->allEnabledRules as $rule) {
            if ((float) ($rule['cashback_percent'] ?? 0.0) <= 0.0) {
                continue;
            }

            if (($rule['scope_type'] ?? 'category') === 'other') {
                $hasOther = true;

                continue;
            }

            $members = $rule['combo_category_ids'] ?? [];

            if ($members !== []) {
                if (in_array($categoryId, array_map('intval', $members), true)) {
                    return 'specific';
                }

                continue;
            }

            if ((int) ($rule['category_id'] ?? 0) === $categoryId) {
                return 'specific';
            }
        }

        return $hasOther ? 'other' : 'none';
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
     * @param  array<int, int>  $amounts
     * @param  array<string, CardScaffold>  $candidates
     * @return array<string, mixed>
     */
    private function renderPlan(string $criterion, array $plan, int $rank, array $categoryNames, array $amounts, array $candidates): array
    {
        $cards = [];
        $allocatedTotal = 0.0;
        $uncertainTotal = 0.0;
        $ownedCards = 0;
        $newCards = 0;

        // catId => list các phần phân bổ của danh mục đó vào từng thẻ.
        $splitsByCategory = [];
        // "Chi tiêu hoàn thấp" dùng để kích hoạt điều kiện/ngưỡng của thẻ (nhóm A).
        $activationRows = [];

        // Đếm số bản mỗi sản phẩm trong phương án để gắn nhãn "— Thẻ N" khi trùng.
        $groupCounts = [];

        foreach ($plan['scenarios'] as $scenario) {
            $groupKey = $scenario->groupKey();
            $groupCounts[$groupKey] = ($groupCounts[$groupKey] ?? 0) + 1;
        }

        foreach ($plan['allocation'] as $key => $cats) {
            $scenario = $plan['scenarios'][$key];
            $scaffold = $candidates[$key] ?? null;
            $categories = [];
            $cardAllocated = 0.0;

            $groupKey = $scenario->groupKey();
            $displayName = $scenario->name;

            if (($groupCounts[$groupKey] ?? 1) > 1) {
                $displayName .= ' — Thẻ '.$scenario->instanceIndex;
            }

            $cardSpend = (float) array_sum($cats);
            $eligibleTotal = (float) ($scenario->totalEligibleSpend ?? $cardSpend);
            $activationExcess = 0.0;

            // Ngưỡng chi tiêu tối thiểu (theo policy live) để mở ưu đãi của thẻ.
            $requiredMin = $scaffold === null ? 0.0 : $this->activationThreshold($scaffold);

            if ($scaffold === null) {
                [$nextTierLabel, $nextTierMin] = [null, null];
            } else {
                [$nextTierLabel, $nextTierMin] = $this->nextTierInfo($scaffold, $eligibleTotal);
            }

            $conditionShortfall = max(0.0, $requiredMin - $eligibleTotal);

            if ($requiredMin <= 0.0) {
                $conditionStatus = 'no_condition';
            } elseif ($eligibleTotal + 0.001 >= $requiredMin) {
                $conditionStatus = 'met';
            } else {
                $conditionStatus = 'not_met';
            }

            // Ngưỡng dùng cho nhóm "chi tiêu kích hoạt": nếu thẻ có ngưỡng kích
            // hoạt rõ ràng thì dùng nó; nếu thẻ vốn sinh hoàn từ 0 nhưng cần đạt
            // một BẬC (tier) để tăng tỷ lệ thì lấy mức min của bậc hiện tại.
            $activationRowThreshold = $requiredMin > 0.0
                ? $requiredMin
                : (float) ($scenario->tierMinTotalSpend ?? 0.0);
            $activationRowShortfall = max(0.0, $activationRowThreshold - $eligibleTotal);
            $activationRowMet = $activationRowThreshold <= 0.0
                || $eligibleTotal + 0.001 >= $activationRowThreshold;

            // "Hoàn tiền nếu đạt điều kiện": khi chưa đạt, thử đánh giá thêm phần
            // còn thiếu vào danh mục có tỷ lệ cao nhất của thẻ để ước tính.
            $expectedCashback = (string) $scenario->totalCashback;

            if ($conditionStatus === 'not_met' && $scaffold !== null && $conditionShortfall > 0.0) {
                $estimated = $this->estimateCashbackWhenReached($scaffold, $cats, $scenario, (int) ceil($conditionShortfall));

                if ($estimated !== null) {
                    $expectedCashback = $estimated;
                }
            }

            foreach ($cats as $categoryId => $amount) {
                if ((int) $amount <= 0) {
                    continue;
                }

                $row = $scenario->perCategory[(int) $categoryId] ?? [];
                $eligible = (bool) ($row['eligible'] ?? false);
                $cashback = (string) ($row['cashback'] ?? '0.00');
                $categoryName = $categoryNames[(int) $categoryId] ?? ('Danh mục #'.(int) $categoryId);

                $cardAllocated += (float) $amount;
                $allocatedTotal += (float) $amount;

                // Chi tiêu chưa xác định chắc chắn quyền lợi (không có rule/thẻ
                // không hoàn cho danh mục này) — nêu riêng, không gộp vào hoàn tiền.
                if (! $eligible) {
                    $uncertainTotal += (float) $amount;
                }

                $categories[] = [
                    'category_id' => (int) $categoryId,
                    'category_name' => $categoryName,
                    'amount' => Decimal::money((string) $amount),
                    'cashback' => $cashback,
                    'rate' => (float) ($row['rate'] ?? 0.0),
                    'percent' => $row['percent'] ?? null,
                    'eligible' => $eligible,
                    'reason' => $row['reason'] ?? null,
                    'caps_applied' => $row['caps_applied'] ?? [],
                ];

                // Sổ phân bổ theo danh mục (dùng cho bảng "Phân bổ theo danh mục").
                $splitsByCategory[(int) $categoryId][] = [
                    'card_key' => $key,
                    'card_name' => $displayName,
                    'is_owned' => $scenario->isOwned,
                    'amount' => Decimal::money((string) (int) $amount),
                    'cashback' => $cashback,
                    'eligible' => $eligible,
                ];

                // Nhóm A — chi tiêu KHÔNG sinh hoàn trực tiếp trên thẻ, dùng để
                // chạm ngưỡng tổng chi tối thiểu (kích hoạt ưu đãi). Gồm cả khoản
                // chỉ hưởng ưu đãi "các danh mục còn lại" (fallback) khi thẻ có
                // ngưỡng, và khoản hoàn 0% nói chung.
                $scopeRole = $scaffold === null ? 'none' : $this->ruleScopeForCategory($scaffold, (int) $categoryId);

                // Phần vượt trần hoàn (ví dụ Cake quá 1.000.000/danh mục) vẫn được
                // giữ nếu cần để đạt ngưỡng kích hoạt — nêu minh bạch, KHÔNG ẩn.
                if ($scaffold !== null) {
                    $capacity = $this->cashbackCapacity($scaffold, (int) $categoryId);

                    if ($capacity !== null && $capacity > 0.0 && (float) $amount > $capacity) {
                        $activationExcess += (float) $amount - $capacity;
                    }
                }

                if ($scopeRole === 'other' || (float) $cashback <= 0.0) {
                    $activationRows[] = [
                        'category_id' => (int) $categoryId,
                        'category_name' => $categoryName,
                        'amount' => Decimal::money((string) (int) $amount),
                        'card_key' => $key,
                        'card_name' => $displayName,
                        'is_owned' => $scenario->isOwned,
                        'direct_percent' => $row['percent'] === null ? 0.0 : (float) $row['percent'],
                        'condition' => $activationRowThreshold > 0.0
                            ? ($scenario->tierLabel ?? ('Tổng chi tối thiểu '.Decimal::money((string) (int) round($activationRowThreshold))))
                            : null,
                        'shortfall' => Decimal::money((string) (int) round($activationRowShortfall)),
                        'met' => $activationRowMet,
                        'extra_cashback' => (string) $scenario->totalCashback,
                    ];
                }
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
                'spending_condition' => $this->spendingCondition(
                    $scaffold,
                    $displayName,
                    $scenario,
                    $cardSpend,
                    $eligibleTotal,
                    $requiredMin,
                    $conditionShortfall,
                    $conditionStatus,
                    $nextTierLabel,
                    $nextTierMin,
                    $expectedCashback,
                    $activationExcess,
                ),
                'categories' => $categories,
            ];
        }

        usort($cards, fn (array $a, array $b): int => [(float) $b['total_cashback'], (int) $b['is_owned']]
            <=> [(float) $a['total_cashback'], (int) $a['is_owned']]);

        // Bảng phân bổ theo danh mục (giữ thứ tự danh mục người dùng nhập).
        $categoryBreakdown = [];
        // Nhóm B — ngân sách danh mục CHƯA được gán vào thẻ nào.
        $unallocatedRows = [];
        $unallocatedTotal = 0.0;

        foreach ($amounts as $categoryId => $budget) {
            $categoryId = (int) $categoryId;
            $budget = (float) $budget;
            $splits = $splitsByCategory[$categoryId] ?? [];
            $allocated = 0.0;
            $cashback = 0.0;

            foreach ($splits as $split) {
                $allocated += (float) $split['amount'];
                $cashback += (float) $split['cashback'];
            }

            $unallocated = max(0.0, $budget - $allocated);
            $categoryName = $categoryNames[$categoryId] ?? ('Danh mục #'.$categoryId);

            $categoryBreakdown[] = [
                'category_id' => $categoryId,
                'category_name' => $categoryName,
                'budget' => Decimal::money((string) (int) $budget),
                'allocated' => Decimal::money((string) (int) $allocated),
                'unallocated' => Decimal::money((string) (int) $unallocated),
                'cashback' => Decimal::money(number_format($cashback, 2, '.', '')),
                'rate' => $this->rate($cashback, $allocated),
                'splits' => $splits,
            ];

            if ($unallocated > 0.5) {
                $unallocatedTotal += $unallocated;

                $unallocatedRows[] = [
                    'category_id' => $categoryId,
                    'category_name' => $categoryName,
                    'amount' => Decimal::money((string) (int) $unallocated),
                    'expected_cashback' => '0.00',
                    'reason' => $splits === []
                        ? 'Không có thẻ nào hoàn tiền cho danh mục này.'
                        : 'Phần vượt trần/quota của các thẻ hoàn tiền hiện có.',
                ];
            }
        }

        $totalCashback = Decimal::money(number_format((float) $plan['total'], 2, '.', ''));
        $totalInput = (float) array_sum(array_merge(...array_values($plan['allocation'])));

        return [
            'rank' => $rank,
            'cards_count' => $plan['count'],
            'distinct_products' => count($groupCounts),
            'total_cashback' => $totalCashback,
            'total_rate' => $this->rate($plan['total'], $totalInput),
            'total_allocated' => Decimal::money((string) (int) $allocatedTotal),
            'unallocated_total' => Decimal::money((string) (int) $unallocatedTotal),
            'uncertain_total' => Decimal::money((string) (int) $uncertainTotal),
            'owned_cards' => $ownedCards,
            'new_cards' => $newCards,
            'criterion' => $criterion,
            'category_breakdown' => $categoryBreakdown,
            'low_rate_spend' => [
                'activation' => $activationRows,
                'unallocated' => $unallocatedRows,
            ],
            'cards' => $cards,
            'excluded_candidates' => $plan['excluded'] ?? [],
            'audit' => $this->renderAudit($plan['audit'] ?? [], $categoryNames, $candidates),
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

    /**
     * Ngưỡng chi tiêu ĐỦ ĐIỀU KIỆN tối thiểu để mở ưu đãi hoàn tiền của thẻ,
     * theo policy live: lấy mức cao nhất giữa vạch min-spend của version và
     * `min_total_spend` của bậc THẤP NHẤT có rule hoàn tiền dương (bậc kích hoạt).
     * Trả 0 khi thẻ không có điều kiện tối thiểu.
     */
    private function activationThreshold(CardScaffold $scaffold): float
    {
        $threshold = max(0.0, $scaffold->minTotalSpend);
        $lowest = null;

        foreach ($scaffold->tiers as $tier) {
            if (! $this->tierHasPositiveRule($scaffold, (int) $tier->id)) {
                continue;
            }

            $tierMin = (float) $tier->min_total_spend;

            // Bậc ĐẦU TIÊN sinh hoàn: nếu có bậc hoàn tiền ngay từ mức 0 thì thẻ
            // không có ngưỡng kích hoạt riêng (ngoài min-spend của version).
            if ($tierMin <= 0.0) {
                $lowest = 0.0;

                break;
            }

            $lowest = $lowest === null ? $tierMin : min($lowest, $tierMin);
        }

        if ($lowest !== null) {
            $threshold = max($threshold, $lowest);
        }

        return $threshold;
    }

    private function tierHasPositiveRule(CardScaffold $scaffold, int $tierId): bool
    {
        foreach ($scaffold->rulesByTier[$tierId] ?? [] as $rule) {
            if ((float) ($rule['cashback_percent'] ?? 0.0) > 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bậc kế tiếp (mức min lớn hơn chi tiêu đủ điều kiện hiện tại) + nhãn bậc
     * hiện tại. Dùng cho mục "điều kiện chi tiêu và trạng thái kích hoạt".
     *
     * @return array{0: ?string, 1: ?float}
     */
    private function nextTierInfo(CardScaffold $scaffold, float $eligibleTotal): array
    {
        $next = null;

        foreach ($scaffold->tiers as $tier) {
            $tierMin = (float) $tier->min_total_spend;

            if ($tierMin <= $eligibleTotal + 0.001) {
                continue;
            }

            if ($next === null || $tierMin < $next) {
                $next = $tierMin;
            }
        }

        return [$next === null ? null : $this->tierLabelForThreshold($scaffold, $next), $next];
    }

    private function tierLabelForThreshold(CardScaffold $scaffold, float $min): ?string
    {
        foreach ($scaffold->tiers as $tier) {
            if (abs((float) $tier->min_total_spend - $min) < 0.001) {
                return $tier->name;
            }
        }

        return null;
    }

    /**
     * Ước tính hoàn tiền của thẻ nếu ĐẠT ngưỡng: cộng phần còn thiếu vào danh mục
     * có tỷ lệ cao nhất đang có trên thẻ, rồi đánh giá lại. Trả null nếu không ước
     * tính được (thẻ không có danh mục hợp lệ).
     *
     * @param  array<int, int>  $cats
     */
    private function estimateCashbackWhenReached(
        CardScaffold $scaffold,
        array $cats,
        CardScenario $scenario,
        int $shortfall,
    ): ?string {
        if ($shortfall <= 0) {
            return null;
        }

        $bestCat = null;
        $bestPercent = -1.0;

        foreach ($cats as $categoryId => $amount) {
            $percent = (float) ($scenario->perCategory[(int) $categoryId]['percent'] ?? 0.0);

            if ($percent > $bestPercent) {
                $bestPercent = $percent;
                $bestCat = (int) $categoryId;
            }
        }

        if ($bestCat === null) {
            return null;
        }

        $bumped = $cats;
        $bumped[$bestCat] = (int) $bumped[$bestCat] + $shortfall;

        $estimated = $this->evaluator->evaluate($scaffold, $bumped);

        return $estimated->scenarioReason === null ? (string) $estimated->totalCashback : null;
    }

    /**
     * Một dòng điều kiện chi tiêu: ngưỡng, thực tế, còn thiếu/vượt, trạng thái.
     *
     * @return array<string, mixed>
     */
    private function conditionRow(
        string $type,
        string $label,
        float $required,
        float $actual,
        bool $met,
        ?string $note = null,
        ?string $activatesTier = null,
        ?float $activatesTierMin = null,
        ?string $status = null,
        ?string $statusLabel = null,
    ): array {
        $status ??= $met ? 'met' : 'not_met';

        return [
            'type' => $type,
            'label' => $label,
            'required' => Decimal::money((string) (int) round($required)),
            'actual' => Decimal::money((string) (int) round($actual)),
            'shortfall' => Decimal::money((string) (int) round(max(0.0, $required - $actual))),
            'excess' => Decimal::money((string) (int) round(max(0.0, $actual - $required))),
            'met' => $met,
            'status' => $status,
            'status_label' => $statusLabel ?? ($met ? 'Đã đạt' : 'Chưa đạt'),
            'note' => $note,
            'activates_tier' => $activatesTier,
            'activates_tier_min' => $activatesTierMin === null
                ? null
                : Decimal::money((string) (int) round($activatesTierMin)),
        ];
    }

    /**
     * Nhãn của bậc hoàn tiền mà thẻ sẽ nằm trong khi đạt tổng chi $total.
     * Dùng để hiển thị "bậc hoàn được kích hoạt khi đạt điều kiện".
     */
    private function tierLabelAtTotal(CardScaffold $scaffold, float $total): ?string
    {
        $bestMin = null;
        $bestLabel = null;

        foreach ($scaffold->tiers as $tier) {
            $min = (float) $tier->min_total_spend;

            if ($total + 0.001 < $min) {
                continue;
            }

            if ($tier->max_total_spend !== null && $total >= (float) $tier->max_total_spend) {
                continue;
            }

            if ($bestMin === null || $min > $bestMin) {
                $bestMin = $min;
                $bestLabel = $tier->name;
            }
        }

        return $bestLabel;
    }

    /**
     * Danh sách thẻ ỨNG VIÊN có "điều kiện hoàn tiền đặc biệt" nhưng KHÔNG xuất
     * hiện trong phương án (thường vì chưa đạt điều kiện). Mỗi dòng nêu từng điều
     * kiện còn thiếu và số tiền còn thiếu — nguồn cho mục "thẻ bị loại vì chưa
     * đạt điều kiện" (§3) và cho nhật ký (§6). Đo trên phân bổ 0 (thẻ không được
     * dùng trong phương án) nên phản ánh đúng trạng thái của phương án này.
     *
     * @param  array<string, CardScaffold>  $candidates
     * @param  array<string, mixed>  $plan
     * @param  array<int, int>  $amounts
     * @return list<array<string, mixed>>
     */
    private function excludedCandidates(array $candidates, array $plan, array $amounts): array
    {
        $present = $plan['allocation'] ?? [];
        $rows = [];

        foreach ($candidates as $key => $scaffold) {
            if (isset($present[$key]) || $scaffold->qualification === null) {
                continue;
            }

            $summary = $this->evaluator->probeQualificationSummary($scaffold);

            if ($summary === null || ($summary['conditions'] ?? []) === []) {
                continue;
            }

            $conditions = [];
            $unmet = false;

            foreach ($summary['conditions'] as $condition) {
                $met = (bool) ($condition['met'] ?? false);
                $unmet = $unmet || ! $met;
                $conditions[] = $this->conditionRow(
                    type: ($condition['type'] ?? 'category') === 'other' ? 'special_other' : 'special_category',
                    label: (string) ($condition['label'] ?? 'Điều kiện đặc biệt'),
                    required: (float) ($condition['min_spend'] ?? 0.0),
                    actual: (float) ($condition['actual_spend'] ?? 0.0),
                    met: $met,
                    note: 'Đo trên chi tiêu thực tế của kỳ giả lập; thẻ không được phân bổ nên bằng 0.',
                );
            }

            if (! $unmet) {
                continue;
            }

            $rows[] = [
                'card_key' => $key,
                'card_name' => $scaffold->name,
                'bank_name' => $scaffold->bankName,
                'is_owned' => $scaffold->isOwned,
                'status' => 'not_met',
                'status_label' => 'Chưa đạt',
                'reason' => 'Không được phân bổ vào thẻ này trong phương án nên chưa đạt điều kiện hoàn tiền đặc biệt.',
                'conditions' => $conditions,
            ];
        }

        return $rows;
    }

    /**
     * Gắn thông điệp người-đọc-được cho từng bước nhật ký, dùng tên danh mục và
     * tên thẻ — KHÔNG đổi dữ liệu gốc của bước nào. Nguồn hiển thị cho khối
     * "Nhật ký lựa chọn thẻ và phân bổ".
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<int, string>  $categoryNames
     * @param  array<string, CardScaffold>  $candidates
     * @return list<array<string, mixed>>
     */
    private function renderAudit(array $entries, array $categoryNames, array $candidates): array
    {
        // Gộp "reallocate": giữ bước CUỐI cùng cho mỗi danh mục (các vòng rót nước
        // nội bộ sinh rất nhiều bước trung gian) — nhật ký nêu đích đến CUỐI cùng.
        $lastRealloc = [];

        foreach ($entries as $i => $entry) {
            if (($entry['type'] ?? '') === OptimizationAudit::STEP_REALLOCATE) {
                $lastRealloc[(int) ($entry['category_id'] ?? -1)] = $i;
            }
        }

        $rendered = [];

        foreach ($entries as $i => $entry) {
            if (($entry['type'] ?? '') === OptimizationAudit::STEP_REALLOCATE
                && ($lastRealloc[(int) ($entry['category_id'] ?? -1)] ?? null) !== $i) {
                continue;
            }

            $entry['message'] = $this->auditMessage($entry, $categoryNames, $candidates);
            $rendered[] = $entry;
        }

        return $rendered;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<int, string>  $categoryNames
     * @param  array<string, CardScaffold>  $candidates
     */
    private function auditMessage(array $entry, array $categoryNames, array $candidates): string
    {
        $type = (string) ($entry['type'] ?? '');
        $category = $this->auditCategoryLabel($entry['category_id'] ?? null, $categoryNames);
        $cardName = static fn (?string $key): string => $key === null
            ? '—'
            : (string) ($candidates[$key]->name ?? $key);
        $holders = function (array $holders) use ($candidates): string {
            $parts = [];

            foreach ($holders as $key => $amount) {
                $name = (string) ($candidates[$key]->name ?? $key);
                $parts[] = $name.' '.Decimal::money((string) (int) $amount).'đ';
            }

            return $parts === [] ? 'không thẻ nào' : implode(', ', $parts);
        };

        return match ($type) {
            OptimizationAudit::STEP_CANDIDATES => 'Bắt đầu: xét '.($entry['candidate_count'] ?? 0).' thẻ ứng viên cho '.($entry['category_count'] ?? 0).' danh mục (tiêu chí '.($entry['criterion'] ?? '').').',

            OptimizationAudit::STEP_SEED => 'Gieo '.$category.' ('.Decimal::money((string) (int) ($entry['amount'] ?? 0)).'đ) vào '.$cardName($entry['card_key'] ?? null)
                .($this->auditSinglePercent($entry) !== null ? ' — tỷ lệ danh nghĩa '.$this->auditSinglePercent($entry).'% cho cả danh mục.' : '.'),

            OptimizationAudit::STEP_REJECT_CATEGORY => $category.' ('.Decimal::money((string) (int) ($entry['amount'] ?? 0)).'đ): không có thẻ hoàn > 0% → để CHƯA PHÂN BỔ.',

            OptimizationAudit::STEP_REALLOCATE => 'Chuyển '.$category.' từ ['.$holders($entry['before'] ?? []).'] sang ['.$holders($entry['after'] ?? []).'].',

            OptimizationAudit::STEP_TIER_FILL => 'Bù ngưỡng tổng chi cho '.$cardName($entry['card_key'] ?? null).': hiện '.Decimal::money((string) (int) ($entry['current'] ?? 0)).'đ, còn thiếu '.Decimal::money((string) (int) ($entry['need'] ?? 0)).'đ — xét '.($entry['source_count'] ?? 0).' nguồn chi tiêu hoàn thấp.',

            OptimizationAudit::STEP_ACTIVATION => 'Kích hoạt điều kiện hoàn tiền cho '.$cardName($entry['card_key'] ?? null)
                .' bằng cách đưa đồng thời chi tiêu nền + danh mục sinh hoàn lên thẻ: tổng hoàn '
                .Decimal::money(number_format((float) ($entry['before'] ?? 0.0), 2, '.', '')).'đ → '
                .Decimal::money(number_format((float) ($entry['after'] ?? 0.0), 2, '.', '')).'đ.',

            OptimizationAudit::STEP_CONDITION_UNMET => 'Điều kiện chưa đạt ở '.$cardName($entry['card_key'] ?? null).' — '.($entry['reason'] ?? ''),

            OptimizationAudit::STEP_UNALLOCATED => $category.': giữ '.Decimal::money((string) (int) ($entry['amount'] ?? 0)).'đ chưa phân bổ — '.($entry['reason'] ?? ''),

            OptimizationAudit::STEP_FINAL => 'Phương án cuối: '.($entry['card_count'] ?? 0).' thẻ, phân bổ '.Decimal::money((string) (int) ($entry['allocated_total'] ?? 0)).'đ, tổng hoàn '.Decimal::money(number_format((float) ($entry['total_cashback'] ?? 0.0), 2, '.', '')).'đ.',

            default => $type,
        };
    }

    /**
     * @param  array<int, string>  $categoryNames
     */
    private function auditCategoryLabel(mixed $categoryId, array $categoryNames): string
    {
        if ($categoryId === null) {
            return 'Danh mục';
        }

        $id = (int) $categoryId;

        return $categoryNames[$id] ?? ('Danh mục #'.$id);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function auditSinglePercent(array $entry): ?float
    {
        $percent = $entry['single']['percent'] ?? null;

        return $percent === null ? null : (float) $percent;
    }

    /**
     * Khối "Điều kiện chi tiêu và trạng thái kích hoạt" của một thẻ.
     *
     * @return array<string, mixed>
     */
    private function spendingCondition(
        ?CardScaffold $scaffold,
        string $displayName,
        CardScenario $scenario,
        float $cardSpend,
        float $eligibleTotal,
        float $requiredMin,
        float $shortfall,
        string $status,
        ?string $nextTierLabel,
        ?float $nextTierMin,
        string $expectedCashback,
        float $activationExcess,
    ): array {
        $notes = [];

        if ($scaffold !== null && CakeFreedomRule::appliesTo($scaffold) && $requiredMin > 0.0) {
            $notes[] = 'Cần tối thiểu '.Decimal::money((string) (int) round($requiredMin)).'đ chi tiêu đủ điều kiện trong kỳ giả lập để kích hoạt hoàn tiền theo chính sách đang áp dụng.';
        }

        if ($activationExcess > 0.5) {
            $notes[] = 'Trong tổng chi trên thẻ có '.Decimal::money((string) (int) round($activationExcess)).'đ vượt trần hoàn mỗi danh mục nhưng được GIỮ để đạt ngưỡng kích hoạt (không sinh thêm hoàn).';
        }

        if ($scenario->qualificationNotice !== null) {
            $notes[] = $scenario->qualificationNotice;
        }

        // Trạng thái TỪNG điều kiện (không chỉ trạng thái tổng): ngưỡng bậc/chương
        // trình + các điều kiện hoàn tiền đặc biệt (đo trên chi tiêu thực tế kỳ).
        $conditions = [];

        if ($requiredMin > 0.0) {
            $activatesTier = $scaffold === null ? null : $this->tierLabelAtTotal($scaffold, $requiredMin);

            $conditions[] = $this->conditionRow(
                type: 'tier_min',
                label: 'Tổng chi tiêu tối thiểu theo chương trình'
                    .($scenario->tierLabel !== null ? ' ('.$scenario->tierLabel.')' : ''),
                required: $requiredMin,
                actual: $eligibleTotal,
                met: $status === 'met',
                note: 'Ngưỡng mở ưu đãi hoàn tiền của thẻ theo chính sách đang áp dụng.',
                activatesTier: $activatesTier,
                activatesTierMin: $requiredMin,
            );
        }

        foreach (($scenario->qualificationSummary['conditions'] ?? []) as $condition) {
            $met = (bool) ($condition['met'] ?? false);
            $conditions[] = $this->conditionRow(
                type: ($condition['type'] ?? 'category') === 'other' ? 'special_other' : 'special_category',
                label: (string) ($condition['label'] ?? 'Điều kiện đặc biệt'),
                required: (float) ($condition['min_spend'] ?? 0.0),
                actual: (float) ($condition['actual_spend'] ?? 0.0),
                met: $met,
                note: 'Điều kiện hoàn tiền đặc biệt (đo trên chi tiêu thực tế của kỳ giả lập).',
                activatesTier: $scaffold === null ? null : $this->tierLabelAtTotal($scaffold, $eligibleTotal),
            );
        }

        // Thẻ không có điều kiện nào (không ngưỡng bậc, không điều kiện đặc biệt):
        // vẫn phải hiện một dòng trung tính thay vì bỏ trống khối điều kiện.
        if ($conditions === []) {
            $conditions[] = $this->conditionRow(
                type: 'none',
                label: 'Không có điều kiện chi tiêu tối thiểu riêng',
                required: 0.0,
                actual: $eligibleTotal,
                met: true,
                note: 'Thẻ sinh hoàn tiền ngay từ mức chi tiêu 0đ; không có ngưỡng kích hoạt riêng theo chính sách đang áp dụng.',
                status: 'none',
                statusLabel: 'Không áp dụng',
            );
        }

        return [
            'card_key' => $scenario->key,
            'card_name' => $displayName,
            'is_owned' => $scenario->isOwned,
            'version_min_spend' => $scaffold === null || $scaffold->minTotalSpend <= 0.0
                ? null
                : Decimal::money((string) (int) round($scaffold->minTotalSpend)),
            'required_min_spend' => $requiredMin <= 0.0
                ? null
                : Decimal::money((string) (int) round($requiredMin)),
            'allocated_total' => Decimal::money((string) (int) round($cardSpend)),
            'eligible_total' => Decimal::money((string) (int) round($eligibleTotal)),
            'kept_above_cap' => Decimal::money((string) (int) round($activationExcess)),
            'shortfall' => Decimal::money((string) (int) round($shortfall)),
            'status' => $status,
            'status_label' => match ($status) {
                'met' => 'Đã đạt',
                'not_met' => 'Chưa đạt',
                default => 'Không có điều kiện',
            },
            'tier_label' => $scenario->tierLabel,
            'tier_min' => $scenario->tierMinTotalSpend,
            'next_tier_label' => $nextTierLabel,
            'next_tier_min' => $nextTierMin === null
                ? null
                : Decimal::money((string) (int) round($nextTierMin)),
            'expected_cashback' => $expectedCashback,
            'current_cashback' => (string) $scenario->totalCashback,
            'conditions' => $conditions,
            'notes' => $notes,
        ];
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
