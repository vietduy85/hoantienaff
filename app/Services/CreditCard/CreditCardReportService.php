<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\Report;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Support\CreditCard\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * CreditCardReportService — lưu cấu hình báo cáo + tính số liệu báo cáo.
 *
 * ---------------------------------------------------------------------------
 * TÍNH LẠI TỪ DỮ LIỆU GỐC, KHÔNG LƯU KẾT QUẢ
 * ---------------------------------------------------------------------------
 * Báo cáo chỉ lưu "xem gì" (bảng `credit_card_reports` + thẻ trong
 * `credit_card_report_cards`). Mọi con số được tính lại tại đây, mỗi lần mở:
 *
 *   - Chi tiêu = SUM(`credit_card_transactions.amount`) của giao dịch NẰM TRONG
 *     kỳ đang chọn (theo `transaction_date`).
 *   - Cashback = SUM(`credit_card_transactions.cashback_amount_snapshot`).
 *
 * ---------------------------------------------------------------------------
 * CASHBACK LÀ SNAPSHOT THẬT, KHÔNG TÍNH LẠI
 * ---------------------------------------------------------------------------
 * `cashback_amount_snapshot` là số engine đã ghi cho TỪNG giao dịch. Báo cáo chỉ
 * ĐỌC LẠI, không gọi `CashbackCalculator`, không áp policy hiện tại, không tự
 * `amount * rate`. Nhờ vậy báo cáo khớp tuyệt đối với những gì engine đã tính, và
 * sửa policy hôm nay không làm đổi số của kỳ đã qua.
 *
 * ---------------------------------------------------------------------------
 * PHẠM VI = NGÀY GIAO DỊCH TRONG [period_start, period_end] CỦA TỪNG THẺ
 * ---------------------------------------------------------------------------
 * Kỳ của mỗi thẻ được suy ra từ anchor (chỉ TÍNH TOÁN, không tạo bản ghi), rồi
 * lọc giao dịch theo `transaction_date` nằm trong khoảng đó. KHÔNG join
 * `statement_periods` vào `transactions` (join sẽ nhân dòng khi một kỳ có nhiều
 * giao dịch và làm tổng phồng lên), và KHÔNG phụ thuộc việc có bản ghi kỳ khớp
 * ranh giới hay không — record lệch ranh giới chỉ ảnh hưởng nhãn `has_record`,
 * không ảnh hưởng con số.
 *
 * ---------------------------------------------------------------------------
 * MỞ BÁO CÁO KHÔNG GHI
 * ---------------------------------------------------------------------------
 * Toàn bộ đường đọc ở đây thuần read-only: `currentBoundaries()`/`selectableBoundaries()`
 * không truy vấn và không tạo kỳ, nên thẻ chưa có kỳ nào vẫn ra báo cáo (0 đồng)
 * mà không sinh bản ghi kỳ/giao dịch nào.
 *
 * Số tiền đi qua {@see Decimal} (chuỗi + bcmath), không qua float.
 */
class CreditCardReportService
{
    /** Giá trị kỳ đặc biệt: kỳ sao kê HIỆN TẠI của RIÊNG từng thẻ. */
    public const PERIOD_CURRENT = 'current';

    public function __construct(
        private readonly StatementPeriodService $periods,
    ) {}

    // =====================================================================
    // CRUD cấu hình báo cáo
    // =====================================================================

    /**
     * Báo cáo của user, mới nhất trước, kèm thẻ (để đếm và hiển thị).
     *
     * @return Collection<int, Report>
     */
    public function forUser(int $userId): Collection
    {
        return Report::query()
            ->ownedBy($userId)
            ->with('cards')
            ->withCount('cards')
            ->latest('id')
            ->get();
    }

    /**
     * Báo cáo thuộc user, hoặc 404.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOwned(int $reportId, int $userId): Report
    {
        return Report::query()
            ->ownedBy($userId)
            ->with('cards')
            ->findOrFail($reportId);
    }

    /**
     * Tạo báo cáo mới. Thẻ được lọc theo chủ sở hữu trước khi gắn — không tin id
     * do client gửi lên.
     *
     * @param  array{name: string, type: string, card_ids: array<int, int>}  $data
     */
    public function create(int $userId, array $data): Report
    {
        $report = Report::create([
            'user_id' => $userId,
            'name' => $data['name'],
            'type' => $data['type'],
        ]);

        $this->syncCards($report, $userId, $data['card_ids'] ?? []);

        return $report->refresh();
    }

    /**
     * Cập nhật cấu hình báo cáo (tên / kiểu / danh sách thẻ).
     *
     * @param  array{name: string, type: string, card_ids: array<int, int>}  $data
     */
    public function update(Report $report, int $userId, array $data): Report
    {
        $report->fill([
            'name' => $data['name'],
            'type' => $data['type'],
        ])->save();

        $this->syncCards($report, $userId, $data['card_ids'] ?? []);

        return $report->refresh();
    }

    public function delete(Report $report): void
    {
        $report->delete();
    }

    /**
     * Gắn đúng tập thẻ THUỘC user vào báo cáo.
     *
     * Lọc qua `ownedBy()` ở tầng truy vấn: id thẻ của người khác (nếu có lọt qua
     * validation) bị bỏ im lặng thay vì gắn vào báo cáo — không có đường nào để
     * báo cáo đọc được dữ liệu thẻ của người khác.
     *
     * @param  array<int, int>  $cardIds
     */
    private function syncCards(Report $report, int $userId, array $cardIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $cardIds)));

        if ($ids === []) {
            $report->cards()->sync([]);

            return;
        }

        $owned = UserCard::query()
            ->ownedBy($userId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $report->cards()->sync($owned);
    }

    // =====================================================================
    // Danh sách kỳ & chọn kỳ
    // =====================================================================

    /**
     * Lựa chọn kỳ cho dropdown của trang kết quả:
     *
     *   1. "Kỳ hiện tại của từng thẻ" — mỗi thẻ lấy kỳ đang mở của nó.
     *   2. Các kỳ tháng `Kỳ tháng MM/YYYY`, mới nhất trước.
     *
     * Tháng lấy từ `period_end` của kỳ (KHÔNG phải tháng lịch của ngày giao dịch):
     * kỳ `07/09 → 06/10` là "Kỳ tháng 10". Danh sách gom từ MỌI thẻ được chọn nên
     * chỉ gồm những tháng thực sự có kỳ.
     *
     * @param  Collection<int, UserCard>  $cards
     * @return list<array{key: string, label: string}>
     */
    public function periodOptions(Collection $cards, ?CarbonInterface $today = null): array
    {
        $options = [[
            'key' => self::PERIOD_CURRENT,
            'label' => 'Kỳ hiện tại của từng thẻ',
        ]];

        $months = [];

        foreach ($cards as $card) {
            foreach ($this->periods->selectableBoundaries($card, $today) as [$start, $end]) {
                $months[$end->format('Y-m')] = 'Kỳ tháng '.$end->format('m/Y');
            }
        }

        // Mới nhất trước, đúng thứ tự người dùng mong đợi khi xem báo cáo.
        krsort($months);

        foreach ($months as $key => $label) {
            $options[] = ['key' => (string) $key, 'label' => $label];
        }

        return $options;
    }

    /**
     * Chuẩn hoá khoá kỳ người dùng gửi lên: không hợp lệ ⇒ rơi về "kỳ hiện tại".
     *
     * Đây là bộ lọc XEM, không phải thao tác ghi tiền — bỏ qua giá trị lạ thay vì
     * báo lỗi vẫn cho người dùng một màn hình hợp lệ.
     *
     * @param  Collection<int, UserCard>  $cards
     */
    public function normalizePeriodKey(?string $key, Collection $cards, ?CarbonInterface $today = null): string
    {
        if ($key === self::PERIOD_CURRENT) {
            return self::PERIOD_CURRENT;
        }

        if ($key !== null && $key !== '') {
            foreach ($this->periodOptions($cards, $today) as $option) {
                if ($option['key'] === $key) {
                    return $key;
                }
            }
        }

        return self::PERIOD_CURRENT;
    }

    /**
     * Kỳ của TỪNG thẻ ứng với khoá đang chọn — chỉ TÍNH TOÁN + tra record.
     *
     * @param  Collection<int, UserCard>  $cards
     * @return array<int, array{card: UserCard, start: ?CarbonImmutable, end: ?CarbonImmutable, period_id: ?int, label: ?string, has_record: bool}>
     */
    public function resolveCardPeriods(Collection $cards, string $periodKey, ?CarbonInterface $today = null): array
    {
        $records = [];

        if ($cards->isNotEmpty()) {
            // MỘT query cho mọi kỳ của mọi thẻ được chọn (không N+1).
            foreach (StatementPeriod::query()
                ->whereIn('user_card_id', $cards->pluck('id')->all())
                ->get() as $period) {
                $cardId = (int) $period->user_card_id;
                $key = $period->period_start->toDateString().'|'.$period->period_end->toDateString();
                $records[$cardId][$key] = (int) $period->id;
            }
        }

        $resolved = [];

        foreach ($cards as $card) {
            [$start, $end] = $this->boundariesFor($card, $periodKey, $today);

            $periodId = null;

            if ($start !== null && $end !== null) {
                $key = $start->toDateString().'|'.$end->toDateString();
                $periodId = $records[(int) $card->id][$key] ?? null;
            }

            $resolved[(int) $card->id] = [
                'card' => $card,
                'start' => $start,
                'end' => $end,
                'period_id' => $periodId,
                'label' => $start === null ? null : $start->format('d/m/Y').' – '.$end->format('d/m/Y'),
                'has_record' => $periodId !== null,
            ];
        }

        return $resolved;
    }

    /**
     * Ranh giới [start, end] của thẻ ứng với khoá kỳ, hoặc [null, null] khi tháng
     * được chọn không nằm trong cửa sổ kỳ của thẻ.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function boundariesFor(UserCard $card, string $periodKey, ?CarbonInterface $today): array
    {
        if ($periodKey === self::PERIOD_CURRENT) {
            return $this->periods->currentBoundaries($card, $today);
        }

        foreach ($this->periods->selectableBoundaries($card, $today) as [$start, $end]) {
            if ($end->format('Y-m') === $periodKey) {
                return [$start, $end];
            }
        }

        return [null, null];
    }

    // =====================================================================
    // Báo cáo A — chi tiêu theo THẺ
    // =====================================================================

    /**
     * @param  Collection<int, UserCard>  $cards
     * @return array<string, mixed>
     */
    public function byCard(Collection $cards, string $periodKey, ?CarbonInterface $today = null): array
    {
        $resolved = $this->resolveCardPeriods($cards, $periodKey, $today);
        $sums = $this->sumsByCard($this->rangesOf($resolved));

        $rows = [];
        $totalSpend = '0.00';
        $totalCashback = '0.00';

        foreach ($resolved as $cardId => $entry) {
            $card = $entry['card'];

            $spend = $sums[$cardId]['spend'] ?? '0.00';
            $cashback = $sums[$cardId]['cashback'] ?? '0.00';

            $totalSpend = Decimal::add($totalSpend, $spend);
            $totalCashback = Decimal::add($totalCashback, $cashback);

            $rows[] = [
                'card_id' => $cardId,
                'name' => $card->name,
                'bank' => $card->bank?->name,
                'last4' => $card->card_number_last4,
                'period_label' => $entry['label'],
                'has_period' => $entry['start'] !== null,
                'has_record' => $entry['has_record'],
                'spend' => $spend,
                'cashback' => $cashback,
                'percent' => self::ratio($cashback, $spend),
            ];
        }

        return [
            'mode' => Report::TYPE_BY_CARD,
            'rows' => $rows,
            'total_spend' => $totalSpend,
            'total_cashback' => $totalCashback,
            'total_percent' => self::ratio($totalCashback, $totalSpend),
        ];
    }

    // =====================================================================
    // Báo cáo B — chi tiêu theo DANH MỤC
    // =====================================================================

    /**
     * Bảng chéo: một dòng một danh mục, mỗi thẻ hai cột (chi tiêu + cashback).
     *
     * Danh mục của thẻ nào thì cộng vào ô của thẻ đó; dòng "Chưa phân loại" gom
     * giao dịch `category_id = NULL` — tiền không bị bỏ khỏi tổng.
     *
     * @param  Collection<int, UserCard>  $cards
     * @return array<string, mixed>
     */
    public function byCategory(Collection $cards, string $periodKey, ?CarbonInterface $today = null): array
    {
        $resolved = $this->resolveCardPeriods($cards, $periodKey, $today);

        // (card_id => category_id => [spend, cashback]); category `0` = chưa phân loại.
        $cells = $this->sumsByCardCategory($this->rangesOf($resolved));

        $usedCategories = [];

        foreach ($cells as $byCategory) {
            foreach (array_keys($byCategory) as $categoryId) {
                $usedCategories[$categoryId] = true;
            }
        }

        $names = $this->categoryNames(array_filter(
            array_keys($usedCategories),
            fn (int $id): bool => $id > 0,
        ));

        $rows = [];

        foreach (array_keys($usedCategories) as $categoryId) {
            $categoryId = (int) $categoryId;
            $cellValues = [];
            $spendTotal = '0.00';
            $cashbackTotal = '0.00';

            foreach ($cards as $card) {
                $id = (int) $card->id;
                $spend = $cells[$id][$categoryId]['spend'] ?? '0.00';
                $cashback = $cells[$id][$categoryId]['cashback'] ?? '0.00';

                $cellValues[$id] = ['spend' => $spend, 'cashback' => $cashback];

                $spendTotal = Decimal::add($spendTotal, $spend);
                $cashbackTotal = Decimal::add($cashbackTotal, $cashback);
            }

            $rows[] = [
                'category_id' => $categoryId === 0 ? null : $categoryId,
                'name' => $categoryId === 0
                    ? 'Chưa phân loại'
                    : ($names[$categoryId] ?? 'Danh mục #'.$categoryId),
                'is_uncategorized' => $categoryId === 0,
                'cells' => $cellValues,
                'spend_total' => $spendTotal,
                'cashback_total' => $cashbackTotal,
                'percent' => self::ratio($cashbackTotal, $spendTotal),
            ];
        }

        // Tên A→Z; "Chưa phân loại" luôn xuống cuối để không cắt ngang nhóm danh mục.
        usort($rows, function (array $a, array $b): int {
            if ($a['is_uncategorized'] !== $b['is_uncategorized']) {
                return $a['is_uncategorized'] ? 1 : -1;
            }

            return strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']));
        });

        // Tổng theo cột = cộng các dòng (kể cả "Chưa phân loại") nên không mất tiền.
        $cardTotals = [];
        $grandSpend = '0.00';
        $grandCashback = '0.00';

        foreach ($cards as $card) {
            $id = (int) $card->id;
            $spend = '0.00';
            $cashback = '0.00';

            foreach ($rows as $row) {
                $spend = Decimal::add($spend, $row['cells'][$id]['spend']);
                $cashback = Decimal::add($cashback, $row['cells'][$id]['cashback']);
            }

            $cardTotals[$id] = [
                'spend' => $spend,
                'cashback' => $cashback,
                'percent' => self::ratio($cashback, $spend),
            ];

            $grandSpend = Decimal::add($grandSpend, $spend);
            $grandCashback = Decimal::add($grandCashback, $cashback);
        }

        $columns = [];
        $periods = [];

        foreach ($cards as $card) {
            $id = (int) $card->id;

            $columns[] = [
                'card_id' => $id,
                'name' => $card->name,
                'bank' => $card->bank?->name,
                'last4' => $card->card_number_last4,
            ];

            $periods[$id] = [
                'label' => $resolved[$id]['label'] ?? null,
                'has_period' => ($resolved[$id]['start'] ?? null) !== null,
                'has_record' => $resolved[$id]['has_record'] ?? false,
            ];
        }

        return [
            'mode' => Report::TYPE_BY_CATEGORY,
            'columns' => $columns,
            'rows' => $rows,
            'card_totals' => $cardTotals,
            'grand' => [
                'spend' => $grandSpend,
                'cashback' => $grandCashback,
                'percent' => self::ratio($grandCashback, $grandSpend),
            ],
            'periods' => $periods,
        ];
    }

    // =====================================================================
    // Aggregate ở tầng DB
    // =====================================================================

    /**
     * Tỷ lệ cashback / chi tiêu (%), hoặc `null` khi chi tiêu không dương.
     *
     * Tính trên VND thô bằng `Decimal::percent` (bcmath), KHÔNG qua `float` để
     * tỷ lệ ở các trường hợp biên không bị sai số.
     *
     * Trả `null` (view in `—`) khi chi tiêu bằng 0 — không có mẫu số để chia,
     * khác hẳn `0%`. Khi chi tiêu > 0 mà cashback = 0 thì `Decimal::percent`
     * trả `0.00` ⇒ hiển thị `0,00%`.
     */
    private static function ratio(string $cashback, string $spend): ?string
    {
        if (! Decimal::isPositive($spend)) {
            return null;
        }

        return Decimal::percent($cashback, $spend);
    }

    /**
     * Ranh giới [start, end] đã resolve của từng thẻ; bỏ qua thẻ không có kỳ
     * trong cửa sổ đang chọn (`[null, null]`).
     *
     * @param  array<int, array<string, mixed>>  $resolved
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function rangesOf(array $resolved): array
    {
        $ranges = [];

        foreach ($resolved as $cardId => $entry) {
            if (($entry['start'] ?? null) !== null && ($entry['end'] ?? null) !== null) {
                $ranges[(int) $cardId] = [$entry['start'], $entry['end']];
            }
        }

        return $ranges;
    }

    /**
     * Chi tiêu + cashback gom theo thẻ trong MỘT query `GROUP BY`, phạm vi lọc
     * theo `transaction_date` trong [period_start, period_end] của từng thẻ.
     *
     * Lọc theo NGÀY chứ không theo `statement_period_id`: một kỳ lưu trong DB có
     * thể là bản ghi thời kỳ cấu hình cũ (ranh giới lệch) nhưng giao dịch đã nhập
     * vẫn phải vào báo cáo, không được để tổng về 0.
     *
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges
     * @return array<int, array{spend: string, cashback: string}>
     */
    private function sumsByCard(array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }

        $table = (new Transaction)->getTable();

        $rows = Transaction::query()
            ->where(function ($query) use ($ranges, $table): void {
                foreach ($ranges as $cardId => [$start, $end]) {
                    $query->orWhere(function ($query) use ($table, $cardId, $start, $end): void {
                        $query->where($table.'.user_card_id', (int) $cardId)
                            ->whereDate($table.'.transaction_date', '>=', $start->toDateString())
                            ->whereDate($table.'.transaction_date', '<=', $end->toDateString());
                    });
                }
            })
            ->groupBy($table.'.user_card_id')
            ->select($table.'.user_card_id')
            ->selectRaw('SUM('.$table.'.amount) as spend')
            ->selectRaw('SUM(COALESCE('.$table.'.cashback_amount_snapshot, 0)) as cashback')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->user_card_id] = [
                'spend' => Decimal::money($row->spend),
                'cashback' => Decimal::money($row->cashback),
            ];
        }

        return $out;
    }

    /**
     * Chi tiêu + cashback gom theo (thẻ, danh mục) trong MỘT query.
     *
     * Không join danh mục/kỳ — chỉ group trên bảng giao dịch, nên không có nguy cơ
     * nhân dòng làm phồng tổng.
     *
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges
     * @return array<int, array<int, array{spend: string, cashback: string}>> card_id => category_id (0 = NULL) => tiền
     */
    private function sumsByCardCategory(array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }

        $table = (new Transaction)->getTable();

        $rows = Transaction::query()
            ->where(function ($query) use ($ranges, $table): void {
                foreach ($ranges as $cardId => [$start, $end]) {
                    $query->orWhere(function ($query) use ($table, $cardId, $start, $end): void {
                        $query->where($table.'.user_card_id', (int) $cardId)
                            ->whereDate($table.'.transaction_date', '>=', $start->toDateString())
                            ->whereDate($table.'.transaction_date', '<=', $end->toDateString());
                    });
                }
            })
            ->groupBy($table.'.user_card_id', $table.'.category_id')
            ->select($table.'.user_card_id', $table.'.category_id')
            ->selectRaw('SUM('.$table.'.amount) as spend')
            ->selectRaw('SUM(COALESCE('.$table.'.cashback_amount_snapshot, 0)) as cashback')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $cardId = (int) $row->user_card_id;
            $categoryId = $row->category_id === null ? 0 : (int) $row->category_id;

            $out[$cardId][$categoryId] = [
                'spend' => Decimal::money($row->spend),
                'cashback' => Decimal::money($row->cashback),
            ];
        }

        return $out;
    }

    /**
     * Tên các danh mục được tham chiếu bởi giao dịch của CHÍNH user.
     *
     * Id lấy từ giao dịch thuộc thẻ của user nên không thể là danh mục của người
     * khác; vẫn đọc qua bảng danh mục một lượt (không join) để tránh N+1.
     *
     * @param  array<int, int>  $categoryIds
     * @return array<int, string>
     */
    private function categoryNames(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $names = [];

        foreach (Category::query()->whereIn('id', $categoryIds)->get(['id', 'name']) as $category) {
            $names[(int) $category->id] = $category->name;
        }

        return $names;
    }
}
