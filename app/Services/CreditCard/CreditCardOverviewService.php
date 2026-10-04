<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Support\CreditCard\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * CreditCardOverviewService — số liệu cho trang Tổng quan.
 *
 * ---------------------------------------------------------------------------
 * CHỈ ĐỌC, KHÔNG CÓ BUSINESS LOGIC
 * ---------------------------------------------------------------------------
 * Service này chỉ TỔNG HỢP, không tính cashback và không quyết định nghiệp vụ:
 *
 *   - `total_spend`       = SUM(`credit_card_transactions.amount`) của KỲ SAO KẾ
 *                           HIỆN TẢI. Không lấy từ hạn mức, không lấy từ
 *                           `desired_spend`, không lấy từ cashback.
 *   - `expected_cashback` = SUM(`credit_card_statement_periods.total_cashback`).
 *
 * `total_cashback` là SỐ ĐÃ DO ENGINE GHI, không phải công thức mới:
 * `CashbackRecordService::calculatePeriod()` → `writePeriodTotals()` chạy mỗi
 * kỳ còn `open`, nên đọc cột này chính là đọc kết quả của
 * Policy → Version → Tier → Rule (kèm cap). Ở đây tuyệt đối không viết lại
 * `amount * rate`, không chọn rule, không tính cap — như vậy Tổng quan KHÔNG
 * BAO GIỜ lệch với lịch sử giao dịch, và việc mở trang không recalculate gì.
 *
 * Số tiền qua {@see Decimal} (chuỗi + `bcmath`), không đi qua `float` — xem
 * docblock lớp đó về lý do.
 *
 * ---------------------------------------------------------------------------
 * PHẠM VI THỜI GIAN = KỲ SAO KẾ HIỆN TẠI
 * ---------------------------------------------------------------------------
 * "Kỳ hiện tại" = kỳ `open` mà hôm nay nằm trong `[period_start, period_end]`.
 * Đây đúng nghĩa module đang dùng (`StatementPeriod::contains()`), nên không
 * phải tự chế ra "30 ngày gần nhất" cũng không cần ép về tháng lịch. Thẻ có
 * `statement_day` khác nhau thì mỗi thẻ một kỳ — ta gộp TẤT CẢ các kỳ hiện
 * tại, không chỉ thẻ đầu tiên.
 *
 * ---------------------------------------------------------------------------
 * TOÀN BỘ TRUY VẤN SCOPE THEO `user_id`
 * ---------------------------------------------------------------------------
 * `credit_card_transactions` không cột `user_id` (giao dịch thuộc thẻ, thẻ
 * thuộc user) nên mọi aggregate đi qua tập `user_card_id` của chính user đang
 * đăng nhập. Không có đường nào đọc được dữ liệu của user khác.
 *
 * ---------------------------------------------------------------------------
 * AGGREGATE Ở TẦNG DB, KHÔNG N+1
 * ---------------------------------------------------------------------------
 * Tổng quan 4 chỉ số: mỗi thẻ một kỳ hiện tại nhưng số kỳ là con số nhỏ, và chỉ
 * `id` được lấy xuống để dựng `WHERE ... IN (...)` cho hai query `SUM` còn lại.
 *
 * Số liệu từng thẻ ({@see perCard()}): mọi `SUM` gom theo `user_card_id` bằng
 * `GROUP BY` trong MỘT query cho tất cả thẻ — không lặp query theo từng thẻ và
 * không kéo danh sách giao dịch về PHP để cộng tay. Quota do
 * {@see CashbackQuotaService} lo và cũng gom theo lô.
 */
class CreditCardOverviewService
{
    public function __construct(
        private readonly CashbackQuotaService $quotas,
        private readonly TierResolverService $tiers,
    ) {}

    /**
     * Mọi thứ trang Tổng quan cần, quét DB MỘT lần.
     *
     * `summary` đúng 4 chỉ số và KHÔNG kèm model — dùng thẳng cho JSON response mà
     * không kéo `current_periods` (Eloquent Collection kèm mọi thuộc tính kỳ) vào
     * payload mà trình duyệt không dùng đến.
     *
     * @return array{
     *     summary: array{total_cards: int, total_credit_limit: string, total_spend: string, expected_cashback: string},
     *     current_periods: Collection<int, StatementPeriod>,
     *     cards: array<int, array<string, mixed>>
     * }
     */
    public function forPage(int $userId): array
    {
        [$cards, $currentPeriods] = $this->scope($userId);

        // Quota gom một lần rồi chia ra cho cả `summary` lẫn `cards`: "Cashback dự
        // kiến" ở CẢ HAI chỗ đều dùng BẬC ĐÍCH mà quota đã resolve, nên gọi
        // `forCards()` hai lần vừa tốn truy vấn vừa dễ lệch số giữa hai chỗ.
        $quotas = $this->quotas->forCards($cards, $currentPeriods);
        $perCard = $this->perCardFrom($cards, $currentPeriods, $quotas);

        return [
            'summary' => $this->metrics($cards, $currentPeriods, $perCard),
            'current_periods' => $currentPeriods,
            'cards' => $perCard,
        ];
    }

    /**
     * Số liệu TỪNG THẺ cho danh sách thẻ trên trang Tổng quan, khoá theo
     * `user_card_id`. Nhận sẵn phạm vi đã lấy ở {@see scope()}.
     *
     * ---------------------------------------------------------------------------
     * BA NHÓM SỐ, BA NGUỒN KHÁC NHAU
     * ---------------------------------------------------------------------------
     *   - `spent`   = SUM giao dịch của kỳ hiện tại (đồng nguồn `total_spend`,
     *                chỉ gom theo thẻ thay vì gộp tất cả).
     *   - `cashback`= `StatementPeriod.total_cashback` mà engine đã ghi. KHÔNG
     *                cộng lại từ giao dịch ở đây — đó là việc của
     *                `CashbackRecordService`. Đây là tiền THỰC TẾ.
     *   - `expected_cashback` = theo BẬC ĐÍCH (quyết định bởi `desired_spend`) nhưng
     *                nhân với CHI TIÊU THỰC TẾ: `spent` → rate của bậc đích →
     *                tiền, kẹp theo trần của bậc đích. Đây là con số "dự kiến"
     *                mà Tổng quan hiển thị.
     *   - `quota`   = {@see CashbackQuotaService} (trần toàn kỳ của bậc theo
     *                `desired_spend`).
     *
     * `desired_spend` là MỤC TIÊU của user, không phải chi tiêu — nó chỉ làm mẫu
     * số của thanh tiến độ. Tiến độ vượt 100% vẫn trả về như số thật, giao diện tự
     * vẽ vạch mục tiêu ở cuối thanh.
     *
     *
     * @param  Collection<int, UserCard>  $cards
     * @param  Collection<int, StatementPeriod>  $currentPeriods
     * @return array<int, array{
     *     desired_spend: string,
     *     spent: string,
     *     cashback: string,
     *     progress_percent: string,
     *     has_goal: bool,
     *     has_period: bool,
     *     quota: array<string, mixed>|null,
     *     eligible_spend: string,
     *     minimum_spend: string|null,
     *     has_minimum: bool,
     *     meets_minimum: bool,
     *     minimum_percent: string|null
     * }>
     */
    private function perCardFrom(Collection $cards, Collection $currentPeriods, array $quotas): array
    {
        if ($cards->isEmpty()) {
            return [];
        }

        $spentByCard = $this->spentByCard(
            // `pluck()` thay vì `modelKeys()`: `$cards` là `Support\Collection`
            // (không phải Eloquent) nên `modelKeys()` không tồn tại.
            $cards->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            $currentPeriods->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        );

        $periodByCard = $currentPeriods->keyBy('user_card_id');

        $result = [];

        foreach ($cards as $card) {
            $cardId = (int) $card->id;
            $period = $periodByCard->get($cardId);

            $spent = $spentByCard[$cardId] ?? '0.00';
            $desiredSpend = Decimal::money($card->desired_spend);

            // -------------------------------------------------------------------------
            // MỨC CHI TIÊU TỐI THIỂU (chỉ để hiển thị — không quyết định nghiệp vụ)
            // -------------------------------------------------------------------------
            // "Vạch Min-Spend" đọc thẳng `PolicyVersion.min_total_spend` của policy
            // ĐANG GẮN VỚI CHÍNH THẺ NÀY (`currentPolicy`).
            //
            // KHÔNG đọc `System Policy`: mỗi thẻ có một bản policy riêng, sửa được
            // riêng (xem `CardPolicySaveService`), nên đọc bản System sẽ vẽ vạch
            // theo ngưỡng của thẻ khác. Cũng KHÔNG suy ra ngưỡng từ `desired_spend`
            // hay từ bậc — mốc bắt đầu bậc là ranh giới TIỀN TỶ LỆ, không phải
            // Vạch-Min-Spend, và trộn hai khái niệm sẽ vẽ vạch ở 100% của mọi thẻ
            // có mục tiêu trùng mốc bậc.
            //
            // Vế phải là `spent` — đúng số mà dòng "Chi tiêu" in ra và đúng số đang
            // đo bằng thanh tiến độ (`progress_percent = spent / desired_spend`).
            // So ngưỡng với một số khác (ví dụ `total_eligible_spend`) sẽ tạo ra
            // trạng thái mà mắt thường không đổi: thanh đã vượt vạch đỏ mà vẫn cam.
            $eligibleSpend = $period === null ? '0.00' : Decimal::money($period->total_eligible_spend);
            $minimumSpend = $this->minimumSpendOf($card);

            // Ngưỡng 0 (hoặc không có ngưỡng) = không có mốc để vẽ ⇒ màu xanh bình
            // thường, không vẽ vạch đỏ.
            $hasMinimum = $minimumSpend !== null && Decimal::isPositive($minimumSpend);
            $meetsMinimum = ! $hasMinimum || Decimal::compare($spent, $minimumSpend) >= 0;

            $result[$cardId] = [
                'desired_spend' => $desiredSpend,
                'spent' => $spent,
                'cashback' => $period === null ? '0.00' : Decimal::money($period->total_cashback),
                // "Cashback DỰ KIẾN": theo BẬC ĐÍCH nhưng nhân với chi tiêu thực tế.
                'expected_cashback' => $this->expectedCashbackFor($card, $quotas[$cardId] ?? null, $spent),
                'progress_percent' => Decimal::percent($spent, $desiredSpend),
                // Mục tiêu bằng 0/NULL ⇒ thanh tiến độ không có ý nghĩa, hiển thị
                // trạng thái chưa đặt mục tiêu thay vì vẽ thanh 0%.
                'has_goal' => Decimal::isPositive($desiredSpend),
                'has_period' => $period !== null,
                'quota' => $quotas[$cardId] ?? null,

                // --- Chỉ để dựng thanh tiến độ + vạch mốc tối thiểu ở Tổng quan ---
                'eligible_spend' => $eligibleSpend,
                'minimum_spend' => $minimumSpend,
                'has_minimum' => $hasMinimum,
                'meets_minimum' => $meetsMinimum,
                // Vị trí vạch đỏ = ngưỡng / mục tiêu. `Decimal::percent()` trả `0.00`
                // khi mục tiêu bằng 0; giao diện tự bỏ vạch khi `has_goal` = false.
                'minimum_percent' => $hasMinimum ? Decimal::percent($minimumSpend, $desiredSpend) : null,
            ];
        }

        return $result;
    }

    /**
     * Phạm vi đọc của user: tập thẻ + các kỳ hiện tại (kỳ `open` chứa hôm nay).
     *
     * Chỉ lấy đúng cột cần dùng: `id` cho mọi `WHERE ... IN (...)` và
     * `desired_spend` cho tiến độ. Không eager-load quan hệ nào ở đây — controller
     * lo phần hiển thị thẻ.
     *
     * @return array{0: Collection<int, UserCard>, 1: Collection<int, StatementPeriod>}
     */
    private function scope(int $userId): array
    {
        $today = CarbonImmutable::now()->toDateString();

        // Tập thẻ của user: mọi truy vấn bên dưới kẹp trong tập này.
        // `with('currentPolicy')` chỉ để đọc `min_total_spend` của policy RIÊNG của
        // từng thẻ (xem `minimumSpendOf()`) — eager sẵn để không N+1.
        $cards = UserCard::query()
            ->ownedBy($userId)
            ->orderBy('id')
            ->with('currentPolicy')
            ->get(['id', 'desired_spend', 'current_policy_id']);

        if ($cards->isEmpty()) {
            return [new Collection, new Collection];
        }

        $currentPeriods = StatementPeriod::query()
            ->open()
            ->whereIn('user_card_id', $cards->pluck('id')->all())
            ->whereDate('period_start', '<=', $today)
            ->whereDate('period_end', '>=', $today)
            ->orderBy('user_card_id')
            ->get();

        return [$cards, $currentPeriods];
    }

    /**
     * Bốn chỉ số, tất cả aggregate ở tầng DB.
     *
     * @param  Collection<int, UserCard>  $cards
     * @param  array<int, array<string, mixed>>  $perCard
     * @return array{total_cards: int, total_credit_limit: string, total_spend: string, expected_cashback: string}
     */
    private function metrics(Collection $cards, Collection $currentPeriods, array $perCard = []): array
    {
        $cardIds = $cards->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $periodIds = $currentPeriods->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return [
            // Tuân thủ `status` sẵn có của UserCard: đếm MỌI thẻ thuộc user
            // (kể cả thẻ đã đóng) — khớp với danh sách thẻ hiển thị ngay dưới
            // trang, không tự ý đổi semantics active/closed.
            'total_cards' => $cards->count(),

            // `SUM` của cột NULL trả NULL ⇒ `COALESCE` về 0 để không bao giờ
            // dính NaN/null khi mọi thẻ đều chưa khai hạn mức.
            'total_credit_limit' => Decimal::money(
                UserCard::query()->whereIn('id', $cardIds)->sum('credit_limit')
            ),

            // `SUM(amount)` nên hoàn tiền âm tự bù trừ — đó là chi tiêu thực.
            'total_spend' => $periodIds === []
                ? '0.00'
                : Decimal::money(
                    Transaction::query()
                        ->whereIn('user_card_id', $cardIds)
                        ->whereIn('statement_period_id', $periodIds)
                        ->sum('amount')
                ),

            // "Cashback dự kiến" = TỔNG số dự kiến của từng thẻ, mỗi thẻ đã tính
            // theo bậc đích (do `desired_spend` quyết định) nhưng nhân với chi
            // tiêu thực tế (xem `expectedCashbackFor()`). KHÔNG
            // đọc `SUM(total_cashback)` của kỳ nữa: đó là tiền thực tế và bậc của
            // nó là bậc theo chi tiêu thực tế — sai nguồn cho một con số "dự kiến".
            // Cộng bằng `Decimal::add()` chứ không `array_sum` trên float: cột tiền
            // ở đây là chuỗi bcmath, float làm mất chữ số ở số lớn.
            'expected_cashback' => $this->sumExpectedCashback($perCard),
        ];
    }

    /**
     * Cộng "Cashback dự kiến" của các thẻ — cộng DẤU chứ không phải `array_sum`.
     *
     * @param  array<int, array<string, mixed>>  $perCard
     */
    private function sumExpectedCashback(array $perCard): string
    {
        $total = '0.00';

        foreach ($perCard as $row) {
            $total = Decimal::add($total, Decimal::money($row['expected_cashback'] ?? '0'));
        }

        return $total;
    }

    /**
     * "Cashback dự kiến" của một thẻ — theo MỤC TIÊU, KHÔNG theo chi tiêu thực tế.
     *
     * ---------------------------------------------------------------------------
     * CÔNG THỨC
     * ---------------------------------------------------------------------------
     *   desired_spend → bậc đích (quota đã resolve) → rate của bậc đích → tiền
     *
     * Rồi kẹp theo trần chung của bậc đích (`max_cashback_per_period`) để con số
     * không bao giờ vượt mẫu số đứng sau dấu "/" ở dòng "Cashback dự kiến".
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO KHÔNG DÙNG `StatementPeriod.total_cashback`
     * ---------------------------------------------------------------------------
     * Snapshot engine là tiền THỰC TẾ, và bậc engine chọn theo TỔNG CHI TIÊU THỰC
     * TẾ của kỳ (`CashbackRecordService` gọi `resolveTier($version, $totalEligibleSpend)`).
     * Người mới đặt mục tiêu 10.000.000đ mới chi 2.000.000đ sẽ bị engine chọn bậc
     * thấp và nhận 0đ — trong khi bậc ĐÍCH của họ là bậc cao. Đó đúng là loại nhầm
     * lẫn mà số "dự kiến" phải tránh: nó đang trả lời "hết kỳ này tôi được bao
     * nhiêu" thay vì "chạm mục tiêu thì tôi được bao nhiêu".
     *
     * ---------------------------------------------------------------------------
     * RATE LẤY THẾ NÀO
     * ---------------------------------------------------------------------------
     * `cashback_percent` nằm ở TỪNG rule (`PolicyTierCategory`), mỗi rule gắn với
     * một danh mục/combo — không có một cột rate duy nhất trên bậc. Rate dùng ở
     * đây là của BẬC ĐÍCH (bậc theo `desired_spend`), không phải bậc engine đang
     * chạy; xem {@see targetTierRate()}.
     *
     * Thẻ chưa đặt mục tiêu, hoặc mục tiêu chưa chạm bậc nào ⇒ `0.00`.
     */
    private function expectedCashbackFor(UserCard $card, ?array $quota, string $spent): string
    {
        $desiredSpend = Decimal::money($card->desired_spend);

        if (! Decimal::isPositive($desiredSpend)) {
            return '0.00';
        }

        $tierId = $quota['tier_id'] ?? null;

        if ($tierId === null) {
            return '0.00';
        }

        $tier = PolicyTier::query()->find((int) $tierId);

        if ($tier === null) {
            return '0.00';
        }

        $rate = $this->targetTierRate($tier);

        if (! Decimal::isPositive($rate)) {
            return '0.00';
        }

        // Nhân với CHI TIÊU THỰC TẾ của kỳ (`spent`), không nhân `desired_spend`:
        // nhân mục tiêu biến con số thành "trả được bao nhiêu nếu chi đủ mục tiêu",
        // đúng nhưng không phải "dự kiến" — và nó không giảm khi người dùng chi
        // nhiều hơn, nên dòng này cũng chẳng phản ánh chuyện gì. Rate vẫn của bậc
        // đích nên mục tiêu vẫn quyết định BẬC, chỉ không còn quyết định MẪU SỐ.
        $expected = Decimal::cashbackForSpend($spent, $rate);
        $ceiling = $quota['tier_cashback_max'] ?? null;

        return $ceiling === null ? $expected : Decimal::min($expected, $ceiling);
    }

    /**
     * Tỷ lệ hoàn tiền dùng để dự kiến, lấy từ BẬC ĐÍCH.
     *
     * Bậc không có một rate duy nhất: mỗi `PolicyTierCategory` mang rate riêng cho
     * một danh mục/combo. Ở đây chọn rate CAO NHẤT trong các rule đang bật của bậc
     * — tức "nếu phần chi tiêu thực tế rơi vào danh mục ưu đãi nhất của bậc đó".
     * Không đọc nhầm rate của bậc engine đang chạy (bậc theo chi tiêu thực tế).
     */
    private function targetTierRate(PolicyTier $tier): string
    {
        $rate = '0.00';

        foreach ($this->tiers->rulesForTier($tier) as $rule) {
            $rate = Decimal::max($rate, Decimal::money($rule['cashback_percent'] ?? '0'));
        }

        return $rate;
    }

    /**
     * Vạch Min-Spend đang lưu trong policy của CHÍNH thẻ này.
     *
     * Nguồn duy nhất: `UserCard.currentPolicy.min_total_spend` — bản policy riêng
     * của thẻ (mỗi thẻ clone một bản từ System Policy rồi sửa được riêng, xem
     * `CardPolicySaveService`). Thẻ chưa gắn policy, hoặc policy lưu NULL ⇒ không
     * có Vạch-Min-Spend nào để vẽ ⇒ `null` (KHÔNG fallback sang System Policy:
     * làm vậy là vẽ vạch của thẻ khác lên thẻ này).
     *
     * Cố tình KHÔNG đọc `StatementPeriod.calculation_meta.min_total_spend` nữa:
     * đó là ảnh chụp ngưỡng tại thời điểm engine tính kỳ, nên nó đọng theo kỳ và
     * không phản ánh ngưỡng đang lưu trên thẻ — người dùng sửa Vạch-Min-Spend xong
     * thì vạch phải dịch ngay, không chờ kỳ được tính lại.
     */
    private function minimumSpendOf(UserCard $card): ?string
    {
        $policy = $card->currentPolicy;

        if ($policy === null) {
            return null;
        }

        $value = $policy->min_total_spend;

        return $value === null ? null : Decimal::money($value);
    }

    /**
     * Chi tiêu kỳ hiện tại, gom theo thẻ trong MỘT query `GROUP BY`.
     *
     * @param  array<int, int>  $cardIds
     * @param  array<int, int>  $periodIds
     * @return array<int, string>
     */
    private function spentByCard(array $cardIds, array $periodIds): array
    {
        if ($cardIds === [] || $periodIds === []) {
            return [];
        }

        $transactions = (new Transaction)->getTable();

        $rows = Transaction::query()
            ->whereIn($transactions.'.user_card_id', $cardIds)
            ->whereIn($transactions.'.statement_period_id', $periodIds)
            ->groupBy($transactions.'.user_card_id')
            ->select($transactions.'.user_card_id')
            ->selectRaw('SUM('.$transactions.'.amount) as spent')
            ->get();

        $spent = [];

        foreach ($rows as $row) {
            $spent[(int) $row->user_card_id] = Decimal::money($row->spent);
        }

        return $spent;
    }
}
