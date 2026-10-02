<?php

namespace App\Services\CreditCard;

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

        return [
            'summary' => $this->metrics($cards, $currentPeriods),
            'current_periods' => $currentPeriods,
            'cards' => $this->perCardFrom($cards, $currentPeriods),
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
     *                `CashbackRecordService`.
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
     *     quota: array<string, mixed>|null
     * }>
     */
    private function perCardFrom(Collection $cards, Collection $currentPeriods): array
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
        $quotas = $this->quotas->forCards($cards, $currentPeriods);

        $result = [];

        foreach ($cards as $card) {
            $cardId = (int) $card->id;
            $period = $periodByCard->get($cardId);

            $spent = $spentByCard[$cardId] ?? '0.00';
            $desiredSpend = Decimal::money($card->desired_spend);

            $result[$cardId] = [
                'desired_spend' => $desiredSpend,
                'spent' => $spent,
                'cashback' => $period === null ? '0.00' : Decimal::money($period->total_cashback),
                'progress_percent' => Decimal::percent($spent, $desiredSpend),
                // Mục tiêu bằng 0/NULL ⇒ thanh tiến độ không có ý nghĩa, hiển thị
                // trạng thái chưa đặt mục tiêu thay vì vẽ thanh 0%.
                'has_goal' => Decimal::isPositive($desiredSpend),
                'has_period' => $period !== null,
                'quota' => $quotas[$cardId] ?? null,
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
        $cards = UserCard::query()
            ->ownedBy($userId)
            ->orderBy('id')
            ->get(['id', 'desired_spend']);

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
     * @return array{total_cards: int, total_credit_limit: string, total_spend: string, expected_cashback: string}
     */
    private function metrics(Collection $cards, Collection $currentPeriods): array
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

            // Đọc thẳng snapshot engine đã ghi cho kỳ hiện tại.
            'expected_cashback' => $periodIds === []
                ? '0.00'
                : Decimal::money(
                    StatementPeriod::query()
                        ->whereIn('id', $periodIds)
                        ->sum('total_cashback')
                ),
        ];
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
