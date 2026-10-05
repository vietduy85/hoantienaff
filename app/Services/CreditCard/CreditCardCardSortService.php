<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use App\Support\CreditCard\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * CreditCardCardSortService — THỨ TỰ HIỂN THỊ THẺ, dùng chung cho cả ba màn hình
 * (Tổng quan, Quản lý thẻ, Sao kê).
 *
 * ---------------------------------------------------------------------------
 * HỢP ĐỒNG
 * ---------------------------------------------------------------------------
 *   1. KHÔNG mutate thẻ: không ghi `sort_order`, không save. Thứ tự `manual` là
 *      thứ tự user đã lưu; ba chế độ còn lại chỉ TÍNH ra thứ tự hiển thị.
 *   2. KHÔNG mutate collection đầu vào — trả về collection MỚI đã `values()`.
 *   3. Mọi kỳ sao kê lấy bằng anchor qua `StatementPeriodService`; TUYỆT ĐỐI
 *      không `whereMonth()`/`whereYear()` (xem docblock `StatementPeriodService`).
 *   4. Thiếu kỳ hoặc thiếu hạn thanh toán ⇒ đứng CUỐI, không đứng đầu vì
 *      "không biết" phải bị coi là không ưu tiên.
 *   5. Trùng giá trị ⇒ phân định bằng `sort_order` để thứ tự ổn định giữa các
 *      lần render.
 */
class CreditCardCardSortService
{
    /** Thứ tự user tự chọn. */
    public const MODE_MANUAL = 'manual';

    /** Thẻ còn thiếu nhiều nhất tới mục tiêu chi tiêu tối thiểu đứng trước. */
    public const MODE_MIN_SPEND = 'min_spend';

    /** Kỳ sao kê sắp chốt trước. */
    public const MODE_STATEMENT_PERIOD = 'statement_period';

    /** Hạn thanh toán sớm trước. */
    public const MODE_PAYMENT_DUE = 'payment_due';

    /**
     * Nhãn hiển thị cho ô chọn thứ tự.
     *
     * @return array<string, string>
     */
    public static function modes(): array
    {
        return [
            self::MODE_MANUAL => 'Thứ tự tôi đã sắp xếp',
            self::MODE_MIN_SPEND => 'Còn thiếu nhiều nhất tới mục tiêu',
            self::MODE_STATEMENT_PERIOD => 'Kỳ sao kê chốt sớm nhất',
            self::MODE_PAYMENT_DUE => 'Đến hạn thanh toán sớm nhất',
        ];
    }

    public function __construct(private readonly StatementPeriodService $periods)
    {
    }

    /**
     * Chuẩn hoá mode lấy từ query string / localStorage.
     *
     * Giá trị lạ (user sửa tay URL, localStorage còn sót từ phiên bản cũ) ⇒ rơi về
     * `manual` chứ KHÔNG ném lỗi: đây chỉ là thứ tự hiển thị, hỏng thì tệ hơn là
     * trắng trang.
     */
    public function normalizeMode(mixed $mode): string
    {
        $mode = is_string($mode) ? strtolower(trim($mode)) : '';

        return array_key_exists($mode, self::modes()) ? $mode : self::MODE_MANUAL;
    }

    /**
     * @param  Collection<int, UserCard>  $cards
     * @return Collection<int, UserCard>
     */
    public function sort(string $mode, Collection $cards, ?CarbonInterface $today = null): Collection
    {
        $mode = $this->normalizeMode($mode);
        $today = $today === null ? CarbonImmutable::now() : CarbonImmutable::instance($today);

        if ($cards->isEmpty()) {
            return $cards->values();
        }

        if ($mode === self::MODE_MANUAL) {
            return $cards->sortBy(fn (UserCard $card): array => [
                (int) $card->sort_order,
                (int) $card->id,
            ])->values();
        }

        // Nạp `currentPolicy` một lần cho cả danh sách: đọc relation trong vòng
        // lặp mà không eager-load là N+1, và mode `min_spend` cần đúng cột này.
        $this->loadCurrentPolicies($cards);

        // Một truy vấn lấy kỳ hiện tại cho MỌI thẻ thay vì mỗi thẻ một truy vấn.
        $periods = $this->currentPeriodsFor($cards, $today);

        $items = match ($mode) {
            self::MODE_MIN_SPEND => $cards->map(fn (UserCard $card): array => $this->minSpendItem($card, $periods[(int) $card->id] ?? null))->all(),
            self::MODE_STATEMENT_PERIOD => $cards->map(fn (UserCard $card): array => $this->statementPeriodItem($card, $periods[(int) $card->id] ?? null))->all(),
            self::MODE_PAYMENT_DUE => $cards->map(fn (UserCard $card): array => $this->paymentDueItem($card, $periods[(int) $card->id] ?? null))->all(),
            default => [],
        };

        usort($items, $this->comparatorFor($mode));

        return collect($items)->pluck('card')->values();
    }

    /**
     * Comparator cho từng mode — tách riêng để phép so sánh của từng mode đọc
     * được ngay, thay vì một `match` dài gộp chung.
     *
     * @return callable(array<string, mixed>, array<string, mixed>): int
     */
    private function comparatorFor(string $mode): callable
    {
        return function (array $a, array $b) use ($mode): int {
            $group = $a['group'] <=> $b['group'];

            if ($group !== 0) {
                return $group;
            }

            $byMoney = $mode === self::MODE_MIN_SPEND
                // Chưa đạt mục tiêu: còn thiếu NHIỀU nhất đứng trước.
                // So bằng `Decimal` chứ không `<` trên chuỗi/float: đây là tiền thật.
                ? Decimal::compare($b['money'], $a['money'])
                : $a['money'] <=> $b['money'];

            if ($byMoney !== 0) {
                return $byMoney;
            }

            $byOrder = $a['sort_order'] <=> $b['sort_order'];

            return $byOrder !== 0 ? $byOrder : $a['id'] <=> $b['id'];
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function minSpendItem(UserCard $card, ?StatementPeriod $period): array
    {
        $item = [
            'card' => $card,
            'id' => (int) $card->id,
            'group' => 0,
            'money' => '0.00',
            'sort_order' => (int) $card->sort_order,
        ];

        // Mục tiêu lấy từ policy ĐANG ÁP DỤNG của thẻ (`currentPolicy`), và chi
        // tiêu lấy từ KỲ HIỆN TẠI CỦA CHÍNH THẺ ĐÓ — không cộng chung các thẻ,
        // vì mỗi thẻ một mục tiêu và một kỳ riêng.
        $policy = $card->currentPolicy;
        $target = $policy === null ? null : Decimal::money($policy->min_total_spend);

        // Không có mục tiêu (hoặc mục tiêu = 0) ⇒ không có gì để "còn thiếu".
        if ($target === null || ! Decimal::isPositive($target)) {
            return [...$item, 'group' => 3];
        }

        $spent = Decimal::money($period?->total_eligible_spend);

        if (Decimal::compare($spent, $target) >= 0) {
            return [...$item, 'group' => 2];
        }

        return [...$item, 'group' => 1, 'money' => Decimal::subtract($target, $spent)];
    }

    /**
     * @return array<string, mixed>
     */
    private function statementPeriodItem(UserCard $card, ?StatementPeriod $period): array
    {
        return [
            'card' => $card,
            'id' => (int) $card->id,
            // Chưa có kỳ nào ⇒ đứng cuối.
            'group' => $period === null ? 1 : 0,
            // Sắp xếp theo ngày CHỐT kỳ: kỳ nào chốt trước thì càng gấp.
            'money' => $period === null ? PHP_INT_MAX : $period->period_end->timestamp,
            'sort_order' => (int) $card->sort_order,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentDueItem(UserCard $card, ?StatementPeriod $period): array
    {
        $due = $period?->payment_due_date;

        return [
            'card' => $card,
            'id' => (int) $card->id,
            // Thiếu kỳ HOẶC kỳ chưa có hạn thanh toán ⇒ đứng cuối.
            'group' => $due === null ? 1 : 0,
            // Tăng dần nên hạn đã TRÔN qua (quá khứ) tự động đứng trước hạn tương lai.
            'money' => $due === null ? PHP_INT_MAX : $due->timestamp,
            'sort_order' => (int) $card->sort_order,
        ];
    }

    /**
     * Đảm bảo `currentPolicy` đã nạp cho mọi thẻ.
     *
     * `sort()` nhận `Collection` theo hợp đồng nên phải chịu được CẢ collection
     * thuần (`collect([...])`), không chỉ `EloquentCollection` — gọi thẳng
     * `loadMissing()` sẽ ném `BadMethodCallException` với collection thuần.
     *
     * @param  Collection<int, UserCard>  $cards
     */
    private function loadCurrentPolicies(Collection $cards): void
    {
        if ($cards instanceof EloquentCollection) {
            $cards->loadMissing('currentPolicy');

            return;
        }

        foreach ($cards as $card) {
            if (! $card->relationLoaded('currentPolicy')) {
                $card->setRelation('currentPolicy', $card->currentPolicy()->first());
            }
        }
    }

    /**
     * Kỳ hiện tại của mọi thẻ trong MỘT truy vấn, khoá theo `user_card_id`.
     *
     * Mỗi thẻ một anchor nên ranh giới khác nhau; không gộp được thành một điều
     * kiện `OR` khổng lồ, nên lấy vùng bao [ngày nhỏ nhất, ngày lớn nhất] rồi khớp
     * chính xác ranh giới từng thẻ trong PHP.
     *
     * CHỈ ĐỌC — dùng `findForDate()` (không tạo kỳ) để mở trang không sinh bản ghi.
     *
     * @param  Collection<int, UserCard>  $cards
     * @return array<int, StatementPeriod>
     */
    private function currentPeriodsFor(Collection $cards, CarbonInterface $today): array
    {
        $bounds = [];

        foreach ($cards as $card) {
            [$start, $end] = $this->periods->currentBoundaries($card, $today);
            $bounds[(int) $card->id] = [$start->toDateString(), $end->toDateString()];
        }

        $cardIds = array_keys($bounds);

        /** @var EloquentCollection<int, StatementPeriod> $candidates */
        $candidates = StatementPeriod::query()
            ->whereIn('user_card_id', $cardIds)
            ->whereDate('period_start', '<=', max(array_column($bounds, 1)))
            ->whereDate('period_end', '>=', min(array_column($bounds, 0)))
            ->get();

        $byCard = [];

        foreach ($candidates as $period) {
            $byCard[(int) $period->user_card_id][$period->period_start->toDateString()] = $period;
        }

        $result = [];

        foreach ($bounds as $cardId => [$start, $end]) {
            $period = $byCard[$cardId][$start] ?? null;

            if ($period !== null && $period->period_end->toDateString() === $end) {
                $result[$cardId] = $period;
            }
        }

        return $result;
    }
}
