<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
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
 *     giả định đơn lẻ trên blueprint MẶC ĐỊNH (cùng nguồn với luồng "thêm thẻ");
 *     trả về MỌI bậc có tỷ lệ dương cho danh mục (không gộp), loại bỏ thẻ không
 *     hoàn tiền cho nhu cầu này. Độc lập hoàn toàn với quota/quá khứ của user.
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

    /**
     * Ngưỡng tỷ lệ hoàn tiền tối thiểu để một BẬC được hiển thị trong gợi ý.
     *
     * Áp cho CẢ "Thẻ của tôi" lẫn "Thẻ trên thị trường": bậc có tỷ lệ thực tế
     * đúng danh mục đang chọn < ngưỡng này bị loại khỏi danh sách; thẻ không còn
     * bậc nào đạt ngưỡng thì không hiển thị. Đây thuần là quy tắc LỌC gợi ý trên
     * giao diện — KHÔNG sửa chính sách/cashback đã lưu. Bậc đúng bằng ngưỡng được
     * GIỮ LẠI.
     *
     * NGOẠI LỆ DUY NHẤT — "Thẻ của tôi" khi cả nhóm KHÔNG còn bậc nào > ngưỡng:
     * chuyển sang chế độ "tối ưu mốc chi tiêu" và KHÔNG lọc theo ngưỡng (vẫn bỏ
     * bậc 0%). Xem {@see self::myCards()}. "Thẻ trên thị trường" KHÔNG bao giờ áp
     * ngoại lệ này.
     */
    private const MIN_DISPLAY_PERCENT = 2.0;

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
     * "Thẻ của tôi" — chọn chế độ hiển thị theo TỶ LỆ HỢP LỆ CAO NHẤT của cả nhóm
     * cho danh mục đang chọn, quyết định TRƯỚC khi lọc theo ngưỡng:
     *
     *  - CHẾ ĐỘ THƯỜNG (có ít nhất một bậc hợp lệ > {@see self::MIN_DISPLAY_PERCENT}):
     *    chỉ hiển thị bậc đạt ngưỡng; thẻ không còn bậc nào đạt ngưỡng bị ẩn. Tiền
     *    hoàn top-level vẫn là THỰC tế theo quota + bậc đang áp dụng.
     *
     *  - CHẾ ĐỘ TỐI ƯU MỐC CHI TIÊU (mọi bậc hợp lệ ≤ ngưỡng): KHÔNG lọc theo
     *    ngưỡng — hiển thị mọi thẻ có chính sách phù hợp danh mục (kể cả tỷ lệ
     *    thấp). Xếp hạng ưu tiên thẻ mà khoản chi giả định được TÍNH vào mốc và giúp
     *    tiến gần bậc kế tiếp (`needs_spend`), nhưng CHỈ khi khoản chi thực sự hợp lệ
     *    và thẻ chưa hết hạn mức; nếu không, sắp theo tiền hoàn → tỷ lệ → tên.
     *
     * Giao dịch giả định chỉ là what-if: KHÔNG được cộng vào chi tiêu thực đã tích
     * luỹ (xem {@see self::spendGoal()}).
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

            $realLines = $linesByCard[$cardId] ?? [];
            $hypoLine = new TransactionLine(
                id: $hypotheticalId,
                transactionDate: $end->toDateString(),
                categoryId: $categoryId,
                amount: $amount,
            );
            $lines = $realLines;
            $lines[] = $hypoLine;

            // KHÔNG lọc ngưỡng ở bước này: cần toàn bộ bậc hợp lệ (tỷ lệ > 0) để
            // quyết định chế độ hiển thị chung cho cả nhóm (xem bên dưới).
            $row = [
                'card_id' => $cardId,
                'card_name' => (string) $card->name,
                'bank_name' => $card->bank?->name,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
            ] + $this->evaluateCard($card, $realLines, $lines, $hypoLine, $end, $amount);

            $row['needs_spend'] = $this->needsSpend($row);

            $rows[] = $row;
        }

        // Tỷ lệ hợp lệ cao nhất của cả nhóm — dùng `max_rate` (bậc cao nhất, KHÔNG
        // lọc ngưỡng) để quyết định chế độ. Chỉ khi KHÔNG còn bậc nào VƯỢT ngưỡng
        // mới bật "chế độ tối ưu mốc chi tiêu".
        $maxRate = 0.0;

        foreach ($rows as $row) {
            $maxRate = max($maxRate, (float) ($row['max_rate'] ?? 0.0));
        }

        $lowRateRegime = $maxRate <= self::MIN_DISPLAY_PERCENT;

        // Áp ngưỡng hiển thị theo chế độ; thẻ không còn bậc nào phù hợp bị ẩn.
        $visible = [];

        foreach ($rows as $row) {
            $tiers = $row['tiers'];

            if (! $lowRateRegime) {
                $tiers = array_values(array_filter(
                    $tiers,
                    fn (array $tier): bool => $tier['cashback_percent'] !== null
                        && (float) $tier['cashback_percent'] >= self::MIN_DISPLAY_PERCENT,
                ));
            }

            if ($tiers === []) {
                continue;
            }

            $row['tiers'] = $tiers;
            $row['visible'] = true;
            $row['low_rate_regime'] = $lowRateRegime;
            $row['max_rate'] = $this->maxRateOf($tiers);
            $row['max_cashback'] = $this->maxCashbackOf($tiers);

            $visible[] = $row;
        }

        $rows = $visible;

        usort($rows, function (array $a, array $b) use ($lowRateRegime): int {
            if (! $lowRateRegime) {
                return [(float) $b['cashback'], (float) $b['rate'], $a['card_name']]
                    <=> [(float) $a['cashback'], (float) $a['rate'], $b['card_name']];
            }

            return [
                (int) $b['eligible'],
                (int) $b['needs_spend'],
                (float) $b['cashback'],
                (float) $b['rate'],
                $a['card_name'],
            ] <=> [
                (int) $a['eligible'],
                (int) $a['needs_spend'],
                (float) $a['cashback'],
                (float) $a['rate'],
                $b['card_name'],
            ];
        });

        return $rows;
    }

    /**
     * "Thẻ trên thị trường" — template hệ thống đang bật, CHỈ hiện nơi có ít nhất
     * một bậc đạt ngưỡng hiển thị ({@see self::MIN_DISPLAY_PERCENT}) cho nhu cầu này.
     *
     * ---------------------------------------------------------------------------
     * ĐỘC LẬP VỚI QUOTA CỦA NGƯỜI DÙNG
     * ---------------------------------------------------------------------------
     * Mỗi thẻ thị trường là một TÌNH HUỐNG GIẢ ĐỊNH: chỉ có MỘT giao dịch giả
     * định, không kèm giao dịch thật nào, nên cap danh mục/kỳ luôn ở trạng thái
     * đầy — đó là giới hạn THEO CHÍNH SÁCH, KHÁC hoàn toàn quota thực còn lại của
     * thẻ người dùng. Vì vậy đây là nơi DUY NHẤT không đọc `max_spend`/quota user.
     *
     * ---------------------------------------------------------------------------
     * LIỆT KÊ ĐỦ CÁC BẬC, KHÔNG GỘP
     * ---------------------------------------------------------------------------
     * Mỗi thẻ trả kèm mảng `tiers` — MỌI bậc đạt ngưỡng hiển thị cho danh mục này,
     * gồm tên bậc, điều kiện chi tiêu, tỷ lệ, tiền hoàn TIỀM NĂNG, hạn mức chính
     * sách, trạng thái (`applied`/`not_met`/`passed`/`unknown`) và số tiền còn thiếu.
     * Bậc đang ÁP DỤNG với số tiền đang xét được đánh dấu `applies = true`; bậc chưa
     * đạt vẫn hiển thị kèm điều kiện còn thiếu, KHÔNG bị nâng thành "đang áp dụng".
     * Các trường top-level (`cashback`, `rate`, `tier_*`) phản ánh BẬC TIỀM NĂNG cao
     * nhất (`max_rate`/`max_cashback`) để xếp hạng; `applies`/`status` cho biết bậc
     * đó đang áp dụng hay chỉ là tiềm năng.
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

        $rows = [];

        foreach ($templates as $template) {
            // Nguồn thị trường = blueprint MẶC ĐỊNH (bản phát hành cho user mới),
            // CÙNG nguồn mà luồng "thêm thẻ" dùng để clone
            // (`PolicyCloneService::attachTemplateToCard`). KHÔNG dùng
            // `currentBlueprint()` — template có thể đã đánh dấu bản mặc định là
            // version đã bị thay thế (superseded) mà vẫn hợp lệ để cấp cho user
            // mới; lọc theo `status = active` sẽ âm thầm loại thẻ khỏi thị trường.
            $version = $template->defaultBlueprint();

            if ($version === null) {
                continue;
            }

            $row = $this->marketRow($template, $version, $amount, $categoryId);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        // Sắp xếp thị trường theo TIỀM NĂNG CHÍNH SÁCH (không phải bậc đang áp dụng):
        //   1) tỷ lệ cao nhất trong MỌI bậc đạt ngưỡng giảm dần;
        //   2) tiền hoàn tiềm năng cao nhất giảm dần;
        //   3) tên tăng dần.
        usort($rows, function (array $a, array $b): int {
            return [$b['max_rate'], (float) $b['max_cashback'], $a['name']]
                <=> [$a['max_rate'], (float) $a['max_cashback'], $b['name']];
        });

        return $rows;
    }

    /**
     * Một thẻ thị trường: liệt kê MỌI bậc ĐẠT NGƯỠNG hiển thị cho danh mục, đánh
     * dấu bậc đang áp dụng vs bậc tiềm năng, rồi trả về bậc TIỀM NĂNG TỐT NHẤT
     * (tỷ lệ cao nhất) ở các trường top-level để xếp hạng/đại diện.
     *
     * @return array<string, mixed>|null null khi thẻ không còn bậc đạt ngưỡng
     */
    private function marketRow(PolicyTemplate $template, PolicyVersion $version, string $amount, int $categoryId): ?array
    {
        $line = new TransactionLine(
            id: self::MARKET_HYPOTHETICAL_ID_BASE + (int) $template->id,
            transactionDate: CarbonImmutable::now()->toDateString(),
            categoryId: $categoryId,
            amount: $amount,
        );

        // Tổng chi của tình huống = chính khoản chi này (không có giao dịch thật).
        $scenarioTotal = $this->totalEligibleSpend($version, [$line]);
        $applicableTier = $this->tiers->resolveTier($version, $scenarioTotal);
        $applicableTierId = $applicableTier === null ? null : (int) $applicableTier->id;

        $tiers = $this->tierList($version, $line, $amount, $scenarioTotal, $applicableTierId);

        if ($tiers === []) {
            return null;
        }

        $best = $this->bestTier($tiers);
        $minTotalSpend = (float) $version->min_total_spend;

        return [
            'template_id' => (int) $template->id,
            'name' => (string) $template->name,
            'tier_name' => $best['name'],
            'tier_label' => $best['label'],
            'cashback' => $best['cashback'],
            'rate' => $best['rate'],
            'cashback_percent' => $best['cashback_percent'],
            'caps_applied' => $best['caps_applied'],
            'applies' => $best['applies'],
            'status' => $best['status'],
            'max_rate' => $this->maxRateOf($tiers),
            'max_cashback' => $this->maxCashbackOf($tiers),
            'scenario_total' => Decimal::money(number_format($scenarioTotal, 2, '.', '')),
            'min_total_spend' => Decimal::money(number_format($minTotalSpend, 2, '.', '')),
            'min_total_spend_met' => $scenarioTotal >= $minTotalSpend,
            'assumption' => $this->marketAssumption($amount),
            'quota_note' => 'Hạn mức hiển thị là giới hạn THEO CHÍNH SÁCH cho một giao dịch giả định — chưa trừ phần bạn đã chi.',
            'qualification_notice' => $this->qualificationNotice($version),
            'tiers' => $tiers,
        ];
    }

    /**
     * Danh sách bậc hợp lệ cho danh mục đang chọn (có rule dương), đã loại bậc
     * dưới `$minPercent`. Dùng chung cho "Thẻ của tôi" và "Thẻ trên thị trường".
     *
     * `$minPercent` mặc định là ngưỡng hiển thị chung; "Thẻ của tôi" truyền 0.0 để
     * lấy TOÀN BỘ bậc dương nhằm quyết định chế độ hiển thị trước khi lọc. Bậc 0%
     * (không hoàn tiền cho danh mục) luôn bị loại dù `$minPercent = 0`.
     *
     * `$scenarioTotal` là tổng chi dùng để xác định bậc ĐANG ÁP DỤNG (đối với thẻ
     * cá nhân là tổng thực + giao dịch đang hỏi; với thị trường chỉ là giao dịch
     * giả định). Tiền hoàn của mỗi bậc luôn là TIỀM NĂNG theo số tiền giao dịch
     * đang nhập và cap của chính sách — KHÔNG trừ quota cá nhân.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tierList(
        PolicyVersion $version,
        TransactionLine $line,
        string $amount,
        float $scenarioTotal,
        ?int $applicableTierId,
        float $minPercent = self::MIN_DISPLAY_PERCENT,
    ): array {
        $rows = [];

        $tiers = $this->tiers->tiersOfVersion($version)
            ->sortBy(fn (PolicyTier $tier): array => [(float) $tier->min_total_spend, (int) $tier->id]);

        foreach ($tiers as $tier) {
            $row = $this->tierRow(
                $version,
                $tier,
                $line,
                $amount,
                $scenarioTotal,
                (int) $tier->id === $applicableTierId,
            );

            // Bậc không có rule DƯƠNG cho danh mục này, hoặc dưới ngưỡng hiển thị ⇒ bỏ.
            if ($row['cashback_percent'] === null
                || (float) $row['cashback_percent'] <= 0.0
                || (float) $row['cashback_percent'] < $minPercent) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Dữ liệu của MỘT bậc: tỷ lệ + tiền hoàn tiềm năng (theo giao dịch đang nhập,
     * cap theo chính sách), trạng thái đủ điều kiện và số tiền còn thiếu.
     *
     * `minTotalSpend = 0` để không ẩn bậc do gate cả kỳ — gate được báo riêng ở
     * `min_total_spend`/`assumption`.
     *
     * @return array<string, mixed>
     */
    private function tierRow(
        PolicyVersion $version,
        PolicyTier $tier,
        TransactionLine $line,
        string $amount,
        float $scenarioTotal,
        bool $applies,
    ): array {
        $results = $this->calculator->calculate(
            rules: $this->tiers->rulesForTier($tier),
            transactions: [$line],
            minTotalSpend: 0.0,
            maxCashbackPerPeriod: $tier->max_cashback_per_period === null
                ? null
                : (float) $tier->max_cashback_per_period,
            roundingMode: (string) $version->rounding_mode,
            transactionCaps: $this->tiers->transactionCapsForTier($tier),
        );

        $result = $this->resultFor($results, $line->id);

        $min = (float) $tier->min_total_spend;
        $max = $tier->max_total_spend === null ? null : (float) $tier->max_total_spend;
        $reached = $scenarioTotal >= $min && ($max === null || $scenarioTotal < $max);

        if ($applies) {
            $status = 'applied';
        } elseif ($scenarioTotal < $min) {
            $status = 'not_met';
        } elseif ($max !== null && $scenarioTotal >= $max) {
            $status = 'passed';
        } else {
            $status = 'unknown';
        }

        return [
            'id' => (int) $tier->id,
            'name' => $tier->name,
            'label' => $this->tierLabel($tier),
            'condition' => $this->tierCondition($tier),
            'min_total_spend' => Decimal::money((string) $tier->min_total_spend),
            'max_total_spend' => $tier->max_total_spend === null
                ? null
                : Decimal::money((string) $tier->max_total_spend),
            'applies' => $applies,
            'reached' => $applies || ($scenarioTotal >= (float) $tier->min_total_spend
                && ($tier->max_total_spend === null || $scenarioTotal < (float) $tier->max_total_spend)),
            'status' => $status,
            'shortfall' => $status === 'not_met'
                ? Decimal::money(number_format(max((float) $tier->min_total_spend - $scenarioTotal, 0.0), 2, '.', ''))
                : null,
            'cashback_percent' => $result?->cashbackPercent,
            'cashback' => Decimal::money($result?->cashbackAmount ?? '0.00'),
            'rate' => $this->rate($result?->cashbackAmount ?? '0.00', $amount),
            'caps' => $this->capDetails($result),
            'caps_applied' => $this->appliedCaps($result),
        ];
    }

    /**
     * Bậc có tỷ lệ cao nhất (tie-break: tiền hoàn tiềm năng cao hơn, rồi id nhỏ
     * hơn). Đại diện "tiềm năng chính sách" của thẻ khi xếp hạng/đại diện.
     *
     * @param  array<int, array<string, mixed>>  $tiers
     * @return array<string, mixed>
     */
    private function bestTier(array $tiers): array
    {
        $best = $tiers[0];

        foreach ($tiers as $tier) {
            $rate = (float) $tier['cashback_percent'];
            $bestRate = (float) $best['cashback_percent'];

            if ($rate > $bestRate
                || ($rate === $bestRate && (float) $tier['cashback'] > (float) $best['cashback'])) {
                $best = $tier;
            }
        }

        return $best;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tiers
     */
    private function maxRateOf(array $tiers): float
    {
        $max = 0.0;

        foreach ($tiers as $tier) {
            $max = max($max, (float) ($tier['cashback_percent'] ?? 0.0));
        }

        return $max;
    }

    /**
     * Tiền hoàn TIỀM NĂNG lớn nhất trong các bậc (đơn vị đồng, dạng chuỗi).
     *
     * @param  array<int, array<string, mixed>>  $tiers
     */
    private function maxCashbackOf(array $tiers): string
    {
        $max = 0.0;

        foreach ($tiers as $tier) {
            $max = max($max, (float) $tier['cashback']);
        }

        return Decimal::money(number_format($max, 2, '.', ''));
    }

    /**
     * Hạn mức chính sách đã tính cho giao dịch (limit + đã chạm hay chưa).
     *
     * @return array<int, array<string, mixed>>
     */
    private function capDetails(?CashbackResult $result): array
    {
        if ($result === null) {
            return [];
        }

        $caps = [];

        foreach ($result->meta['caps'] ?? [] as $cap) {
            $caps[] = [
                'type' => (string) $cap['type'],
                'limit' => Decimal::money(number_format((float) $cap['limit'], 2, '.', '')),
                'applied' => ($cap['applied'] ?? false) === true,
            ];
        }

        return $caps;
    }

    /**
     * Câu điều kiện chi tiêu của bậc để hiển thị (KHÔNG khẳng định người dùng đã
     * đạt — chỉ nêu ngưỡng).
     */
    private function tierCondition(PolicyTier $tier): string
    {
        $min = (float) $tier->min_total_spend;

        if ($min > 0.0) {
            return 'Áp dụng khi tổng chi trong kỳ đạt '.$this->vnd(Decimal::money((string) $tier->min_total_spend)).'.';
        }

        return 'Áp dụng ngay từ đầu kỳ.';
    }

    /**
     * Giả định của tính toán thị trường — nêu rõ để không khẳng định chắc chắn.
     */
    private function marketAssumption(string $amount): string
    {
        return 'Giả định trong kỳ chỉ có khoản chi này ('.$this->vnd($amount).'); bậc áp dụng có thể khác nếu bạn đã chi ở danh mục khác trong kỳ.';
    }

    /**
     * Thông báo nếu policy có điều kiện hoàn tiền đặc biệt (gate cả kỳ) — thị
     * trường KHÔNG áp gate nên chỉ nêu để người dùng biết mà tự cân nhắc.
     */
    private function qualificationNotice(PolicyVersion $version): ?string
    {
        $hasCondition = SpendQualification::query()
            ->where('policy_version_id', $version->id)
            ->where('enabled', true)
            ->exists();

        return $hasCondition
            ? 'Thẻ có điều kiện hoàn tiền đặc biệt: kết quả chưa xác nhận bạn đủ điều kiện.'
            : null;
    }

    /**
     * Chạy engine cho một thẻ với tập dòng đã gồm giao dịch giả định.
     *
     * Thứ tự kiểm tra mô phỏng đúng `CashbackRecordService::calculatePeriod()`:
     *   no_policy_version → no_matching_tier → qualification_not_met → (calculator)
     *   below_minimum_spend / no_category_rule / …
     *
     * `$realLines` là các giao dịch THỰC của kỳ (KHÔNG có giao dịch giả định) —
     * dùng để tính "đang tích luỹ bao nhiêu cho mốc bậc kế tiếp" mà không nhiễm
     * chính khoản chi đang hỏi.
     *
     * @param  array<int, TransactionLine>  $realLines
     * @param  array<int, TransactionLine>  $lines
     * @return array<string, mixed>
     */
    private function evaluateCard(
        UserCard $card,
        array $realLines,
        array $lines,
        TransactionLine $hypoLine,
        CarbonImmutable $date,
        string $amount,
    ): array {
        $hypotheticalId = $hypoLine->id;
        $resolved = $this->engine->resolveFor($card, $date);

        if ($resolved === null) {
            return $this->ineligibleResult(
                Transaction::REASON_NO_POLICY,
                null,
                $this->spendGoal(null, $realLines, (float) $amount),
            ) + $this->tierContext([]);
        }

        $version = $resolved->policyVersion;
        $totalEligible = $this->totalEligibleSpend($version, $lines);
        $tier = $this->tiers->resolveTier($version, $totalEligible);

        // Liệt kê TOÀN BỘ bậc hợp lệ (tỷ lệ > 0) cho danh mục đang chọn — KHÔNG lọc
        // ngưỡng ở đây; `myCards()` sẽ quyết định chế độ hiển thị rồi mới lọc. Áp cho
        // cả thẻ chưa đủ điều kiện/chưa đạt min (vẫn có thể hiển thị dạng tiềm năng).
        $tiers = $this->tierList(
            $version,
            $hypoLine,
            $amount,
            $totalEligible,
            $tier === null ? null : (int) $tier->id,
            0.0,
        );
        $context = $this->tierContext($tiers);

        if ($tier === null && $totalEligible > 0) {
            return $this->ineligibleResult(
                Transaction::REASON_NO_TIER,
                $tier,
                $this->spendGoal($version, $realLines, (float) $amount),
            ) + $context;
        }

        if (! $this->qualificationPasses($version, $lines)) {
            return $this->ineligibleResult(
                Transaction::REASON_QUALIFICATION_NOT_MET,
                $tier,
                $this->spendGoal($version, $realLines, (float) $amount),
            ) + $context;
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
            'tier_label' => $this->tierLabel($tier),
            'cashback' => Decimal::money($result?->cashbackAmount ?? '0.00'),
            'rate' => $this->rate($result?->cashbackAmount ?? '0.00', $amount),
            'eligible' => $result?->isEligible ?? false,
            'reason' => $result?->ineligibleReason,
            'cashback_percent' => $result?->cashbackPercent,
            'caps_applied' => $this->appliedCaps($result),
            'max_spend' => $this->maxSpendAdvice($result, $amount),
            'spend_goal' => $this->spendGoal($version, $realLines, (float) $amount, $result?->isEligible ?? false),
        ] + $context;
    }

    /**
     * Bối cảnh bậc dùng chung để gắn vào kết quả thẻ: danh sách bậc, cờ hiển thị
     * và tỷ lệ/tiền hoàn tiềm năng cao nhất (dùng để xếp hạng).
     *
     * @param  array<int, array<string, mixed>>  $tiers
     * @return array<string, mixed>
     */
    private function tierContext(array $tiers): array
    {
        return [
            'tiers' => $tiers,
            'visible' => $tiers !== [],
            'max_rate' => $this->maxRateOf($tiers),
            'max_cashback' => $this->maxCashbackOf($tiers),
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
    private function ineligibleResult(string $reason, ?PolicyTier $tier = null, ?array $spendGoal = null): array
    {
        return [
            'tier_name' => $tier?->name,
            'tier_label' => $this->tierLabel($tier),
            'cashback' => '0.00',
            'rate' => 0.0,
            'eligible' => false,
            'reason' => $reason,
            'cashback_percent' => null,
            'caps_applied' => [],
            'max_spend' => $this->unavailableMaxSpend(
                'not_applicable',
                'Thẻ chưa áp dụng hoàn tiền cho nhu cầu này.',
            ),
            'spend_goal' => $spendGoal ?? $this->neutralSpendGoal(),
        ];
    }

    /**
     * Nhãn bậc hiển thị cho UI — KHÔNG lặp tiền tố.
     *
     * Tên bậc trong dữ liệu có thể đã là "Bậc 1"/"Bậc 2" (như fixture chuẩn) hoặc
     * chỉ là "Đồng"/"Vàng". Trước đây view luôn ghép thêm "Bậc " nên ra
     * "Bậc Bậc 1"; hàm này chuẩn hoá: nếu tên đã bắt đầu bằng "Bậc"/"Tier" thì giữ
     * nguyên, ngược lại mới thêm tiền tố.
     */
    private function tierLabel(?PolicyTier $tier): ?string
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

    /**
     * Tiêu chí "khoản chi này giúp tiến gần mốc bậc kế tiếp" — chỉ dùng cho chế
     * độ xếp hạng tỷ lệ thấp.
     *
     * Yêu cầu: thẻ đủ điều kiện hoàn tiền cho nhu cầu này, có tỷ lệ dương, còn
     * mốc bậc chưa đạt mà khoản chi được tính vào mốc, và KHÔNG bị chặn bởi hết
     * hạn mức (hết quota thì có đạt mốc cũng không sinh thêm hoàn tiền).
     *
     * @param  array<string, mixed>  $row
     */
    private function needsSpend(array $row): bool
    {
        return ($row['eligible'] ?? false)
            && $row['cashback_percent'] !== null
            && (float) $row['cashback_percent'] > 0.0
            && ($row['spend_goal']['status'] ?? null) === 'short'
            && ($row['max_spend']['reason'] ?? null) !== 'quota_exhausted';
    }

    /**
     * Bậc kế tiếp chưa đạt — bậc có `min_total_spend` lớn hơn chi tiêu đã tích
     * luỹ, nhỏ nhất. `null` khi đã ở bậc cao nhất.
     */
    private function nextTier(PolicyVersion $version, float $accumulated): ?PolicyTier
    {
        return $this->tiers->tiersOfVersion($version)
            ->sortBy(fn (PolicyTier $tier): array => [(float) $tier->min_total_spend, (int) $tier->id])
            ->first(fn (PolicyTier $tier): bool => (float) $tier->min_total_spend > $accumulated);
    }

    /**
     * "Mốc chi tiêu": khoản chi đang hỏi có giúp (hoặc bao xa nữa mới) đạt bậc kế
     * tiếp không.
     *
     * `$counted` = khoản chi giả định có được tính vào tổng eligible spend không
     * (tức kết quả của nó `eligible`). Nếu không được tính, dù có đạt mốc cũng vô
     * nghĩa — trả trạng thái `not_counted` để UI nói rõ.
     *
     * @param  array<int, TransactionLine>  $realLines
     * @return array{status: string, next_tier_label: ?string, remaining: ?string, message: string}
     */
    private function spendGoal(
        ?PolicyVersion $version,
        array $realLines,
        float $amount,
        bool $counted = false,
    ): array {
        if ($version === null) {
            return $this->neutralSpendGoal();
        }

        $accumulated = $this->totalEligibleSpend($version, $realLines);
        $nextTier = $this->nextTier($version, $accumulated);

        if ($nextTier === null) {
            return [
                'status' => 'met',
                'next_tier_label' => null,
                'remaining' => null,
                'message' => 'Đã đạt mốc chi tiêu cao nhất.',
            ];
        }

        $label = $this->tierLabel($nextTier);
        $threshold = (float) $nextTier->min_total_spend;

        if (! $counted) {
            return [
                'status' => 'not_counted',
                'next_tier_label' => $label,
                'remaining' => Decimal::money(max($nextTier->min_total_spend - $accumulated, 0.0)),
                'message' => 'Khoản chi này không được tính vào mốc chi tiêu.',
            ];
        }

        $scenarioTotal = $accumulated + $amount;
        $reached = $scenarioTotal >= $threshold;
        $remaining = $reached ? 0.0 : $threshold - $scenarioTotal;

        return [
            'status' => 'short',
            'next_tier_label' => $label,
            'remaining' => Decimal::money($remaining),
            'message' => $reached
                ? 'Giao dịch này có thể giúp đạt '.$label.'.'
                : 'Còn thiếu '.$this->vnd(Decimal::money($remaining)).' để đạt '.$label.'.',
        ];
    }

    /**
     * @return array{status: string, next_tier_label: ?string, remaining: ?string, message: string}
     */
    private function neutralSpendGoal(): array
    {
        return [
            'status' => 'met',
            'next_tier_label' => null,
            'remaining' => null,
            'message' => 'Không có mốc chi tiêu để xét.',
        ];
    }

    /**
     * "Nên chi tối đa" cho giao dịch giả định.
     *
     * Vì engine phân bổ cap theo thứ tự và giao dịch giả định xếp CUỐI, phần cap
     * còn lại mà nó nhận chính là `remaining` của cap danh mục/kỳ và `limit` của
     * cap mỗi giao dịch (đã có trong `meta['caps']`). Trần hoàn tiền khả dụng =
     * min các cap đó; số chi để "vừa chạm trần mà chưa mất hoàn tiền" chính là
     * {@see Decimal::spendForCashback()} (làm tròn LÊN) trên tỷ lệ đang áp dụng.
     *
     * KHÔNG bịa số: khi không có tỷ lệ (rule 0%), không đủ điều kiện, hoặc không
     * có cap nào ⇒ trả `amount = null` kèm `reason` để UI giải thích, không chia 0.
     *
     * @return array<string, mixed>
     */
    private function maxSpendAdvice(?CashbackResult $result, string $amount): array
    {
        if ($result === null || ! $result->isEligible) {
            return $this->unavailableMaxSpend(
                'not_applicable',
                'Thẻ chưa áp dụng hoàn tiền cho nhu cầu này.',
            );
        }

        $percent = $result->cashbackPercent;

        if ($percent === null || (float) $percent <= 0.0) {
            return $this->unavailableMaxSpend(
                'no_rate',
                'Danh mục này hoàn tiền 0% nên không có mức chi tối đa.',
            );
        }

        $perTransaction = null;
        $categoryRemaining = null;
        $periodRemaining = null;

        foreach ($result->meta['caps'] ?? [] as $cap) {
            $type = (string) ($cap['type'] ?? '');

            if ($type === 'per_transaction') {
                $perTransaction = (float) $cap['limit'];
            } elseif ($type === 'per_category') {
                $categoryRemaining = (float) $cap['remaining'];
            } elseif ($type === 'per_period_total') {
                $periodRemaining = (float) $cap['remaining'];
            }
        }

        // Cap còn lại theo đúng thứ tự engine áp dụng ⇒ min đầu tiên là yếu tố
        // giới hạn (tie-break theo thứ tự mảng).
        $ceilings = array_filter([
            'per_transaction' => $perTransaction,
            'per_category' => $categoryRemaining,
            'per_period_total' => $periodRemaining,
        ], fn (?float $value): bool => $value !== null);

        $base = [
            'amount' => null,
            'limiting_factor' => null,
            'reason' => null,
            'category_quota_remaining' => $categoryRemaining === null ? null : Decimal::money($categoryRemaining),
            'period_quota_remaining' => $periodRemaining === null ? null : Decimal::money($periodRemaining),
            'per_transaction_limit' => $perTransaction === null ? null : Decimal::money($perTransaction),
            'over_limit' => '0.00',
        ];

        if ($ceilings === []) {
            return array_merge($base, [
                'limiting_factor' => 'no_cap',
                'message' => 'Không có giới hạn hoàn tiền cho giao dịch này.',
            ]);
        }

        $ceiling = min($ceilings);
        $base['limiting_factor'] = (string) array_search($ceiling, $ceilings, true);

        if ($ceiling <= 0.0) {
            return array_merge($base, [
                'reason' => 'quota_exhausted',
                'message' => 'Đã hết hạn mức hoàn tiền cho giao dịch này.',
            ]);
        }

        $max = Decimal::spendForCashback(number_format($ceiling, 2, '.', ''), (string) $percent);
        $over = Decimal::clampZero(Decimal::subtract($amount, $max));

        return array_merge($base, [
            'amount' => $max,
            'over_limit' => $over,
            'message' => 'Nên chi tối đa '.$this->vnd($max)
                .' để nhận đủ '.$this->percentLabel((string) $percent).'% hoàn tiền.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailableMaxSpend(string $reason, string $message, ?string $factor = null): array
    {
        return [
            'amount' => null,
            'limiting_factor' => $factor,
            'reason' => $reason,
            'category_quota_remaining' => null,
            'period_quota_remaining' => null,
            'per_transaction_limit' => null,
            'over_limit' => '0.00',
            'message' => $message,
        ];
    }

    /**
     * Định dạng VNĐ cho câu chữ tiếng Việt (không thập phân).
     */
    private function vnd(string $money): string
    {
        return number_format((float) $money, 0, ',', '.').'đ';
    }

    /**
     * Rút gọn tỷ lệ "2.000" → "2"; "1.500" → "1.5".
     */
    private function percentLabel(string $percent): string
    {
        $trimmed = rtrim(rtrim((string) $percent, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
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
