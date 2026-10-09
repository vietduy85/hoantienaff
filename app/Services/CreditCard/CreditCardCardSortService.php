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
 *   4. `statement_period` và `payment_due` suy HOÀN TOÀN từ CẤU HÌNH thẻ (anchor
 *      kỳ + `payment_due_day`) qua `StatementPeriodService` — KHÔNG đọc bảng
 *      `credit_card_statement_periods`, nên kết quả đúng kể cả khi thẻ chưa có
 *      bản ghi kỳ nào, và mở trang xem thử KHÔNG sinh bản ghi. Chỉ `min_spend`
 *      mới cần số chi tiêu của kỳ nên mới đọc bảng kỳ.
 *   5. Thiếu cấu hình (không suy được kỳ, hoặc không có `payment_due_day`) ⇒
 *      đứng CUỐI, không đứng đầu vì "không biết" phải bị coi là không ưu tiên.
 *   6. Trùng giá trị ⇒ phân định bằng `sort_order` rồi `id` để thứ tự ổn định
 *      giữa các lần render.
 */
class CreditCardCardSortService
{
    /** Thứ tự user tự chọn. */
    public const MODE_MANUAL = 'manual';

    /** Thẻ còn thiếu nhiều nhất tới mục tiêu chi tiêu tối thiểu đứng trước. */
    public const MODE_MIN_SPEND = 'min_spend';

    /**
     * Kỳ sao kê HIỆN TẠI đóng sớm nhất đứng trước.
     *
     * "Chốt" = `period_end` của kỳ chứa hôm nay (ngày kết thúc kỳ), không phải
     * `statement_day` thuần.
     */
    public const MODE_STATEMENT_PERIOD = 'statement_period';

    /**
     * Ngày đến hạn thanh toán KẾ TIẾP (chưa qua) gần nhất đứng trước.
     *
     * Hạn = `payment_due_day` của tháng sau kỳ liên quan (xem
     * `StatementPeriodService::paymentDueDateFor()`). Nếu hạn của kỳ trước đã
     * trôi qua thì lấy hạn kỳ hiện tại; không dùng `spending_deadline_day`.
     */
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

        if ($mode === self::MODE_MIN_SPEND) {
            // `min_spend` cần `currentPolicy` + số chi tiêu của KỲ HIỆN TẠI (dữ
            // liệu tổng hợp nằm trên bảng kỳ): nạp policy một lần, rồi MỘT truy
            // vấn lấy kỳ cho mọi thẻ thay vì mỗi thẻ một truy vấn.
            $this->loadCurrentPolicies($cards);
            $periods = $this->currentPeriodsFor($cards, $today);
            $items = $cards->map(fn (UserCard $card): array => $this->minSpendItem($card, $periods[(int) $card->id] ?? null))->all();
        } else {
            // `statement_period` và `payment_due` suy HOÀN TOÀN từ cấu hình thẻ:
            // không đọc bảng kỳ, không N+1, và kết quả không phụ thuộc việc kỳ đã
            // có bản ghi hay chưa.
            $items = $cards->map(fn (UserCard $card): array => match ($mode) {
                self::MODE_STATEMENT_PERIOD => $this->statementPeriodItem($card, $today),
                self::MODE_PAYMENT_DUE => $this->paymentDueItem($card, $today),
                default => $this->unsortableItem($card),
            })->all();
        }

        usort($items, $this->comparatorFor($mode));

        return collect($items)->pluck('card')->values();
    }

    /**
     * Mục KHÔNG sắp được (không nên xảy ra qua đường normal) — luôn đứng cuối.
     *
     * @return array<string, mixed>
     */
    private function unsortableItem(UserCard $card): array
    {
        return [
            'card' => $card,
            'id' => (int) $card->id,
            'group' => 1,
            'money' => PHP_INT_MAX,
            'sort_order' => (int) $card->sort_order,
        ];
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
     * `statement_period`: kỳ HIỆN TẠI của thẻ đóng lúc nào.
     *
     * Ngày chốt suy từ cấu hình (`currentBoundaries`, không đọc DB) nên thẻ chưa
     * có bản ghi kỳ vẫn ra kết quả đúng. Thẻ không suy được kỳ ⇒ cuối danh sách.
     *
     * @return array<string, mixed>
     */
    private function statementPeriodItem(UserCard $card, CarbonInterface $today): array
    {
        $end = $this->currentPeriodEnd($card, $today);

        return [
            'card' => $card,
            'id' => (int) $card->id,
            'group' => $end === null ? 1 : 0,
            // Tăng dần: kỳ nào chốt trước thì càng gấp.
            'money' => $end === null ? PHP_INT_MAX : $end->timestamp,
            'sort_order' => (int) $card->sort_order,
        ];
    }

    /**
     * `payment_due`: NGÀY ĐẾN HẠN THANH TOÁN KẾ TIẾP của thẻ tính từ hôm nay.
     *
     * Không đọc `payment_due_date` đã lưu (có thể cũ/lệch kỳ, hoặc chưa tồn tại):
     * hạn được suy từ `payment_due_day` + ranh giới kỳ của chính thẻ. Thẻ không có
     * `payment_due_day` ⇒ cuối danh sách.
     *
     * @return array<string, mixed>
     */
    private function paymentDueItem(UserCard $card, CarbonInterface $today): array
    {
        $due = $this->nextPaymentDueDate($card, $today);

        return [
            'card' => $card,
            'id' => (int) $card->id,
            'group' => $due === null ? 1 : 0,
            // Tăng dần: hạn gần nhất đứng trước. Vì đã lọc "chưa qua", không còn
            // chuyện hạn cũ trong quá khứ chen lên đầu.
            'money' => $due === null ? PHP_INT_MAX : $due->timestamp,
            'sort_order' => (int) $card->sort_order,
        ];
    }

    /**
     * Ngày kết thúc kỳ HIỆN TẠI của thẻ, suy từ cấu hình. Null khi thẻ không có
     * đủ dữ liệu để xác định kỳ.
     */
    private function currentPeriodEnd(UserCard $card, CarbonInterface $today): ?CarbonInterface
    {
        if (! $this->hasPeriodConfiguration($card)) {
            return null;
        }

        [, $end] = $this->periods->currentBoundaries($card, $today);

        return $end;
    }

    /**
     * NGÀY ĐẾN HẠN THANH TOÁN KẾ TIẾP (chưa qua) của thẻ.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO PHẢI XÉT CẢ KỲ LIỀN TRƯỚC, KHÔNG CHỈ KỲ HIỆN TẠI
     * ---------------------------------------------------------------------------
     * Hạn thanh toán gắn với một kỳ: `payment_due_day` của tháng SAU `period_end`.
     * Hôm nay luôn nằm trong kỳ hiện tại nên hạn của kỳ hiện tại luôn ở tương lai.
     * Nhưng hoá đơn NGAY TRƯỚC vẫn có thể chưa tới hạn (ví dụ kỳ trước đóng ngày
     * 30/09 → hạn 12/10, hôm nay 09/10): đó mới là khoản phải trả GẦN NHẤT. Vì
     * vậy lấy hạn nhỏ nhất trong {kỳ liền trước, kỳ hiện tại, kỳ kế tiếp} mà chưa
     * trôi qua; nếu tất cả đã qua (dữ liệu bất thường) thì lấy hạn xa nhất.
     *
     * Dùng `StatementPeriodService` cho MỌI phép tính ngày: không tự chế công
     * thức clamp/next-month ở đây.
     */
    private function nextPaymentDueDate(UserCard $card, CarbonInterface $today): ?CarbonInterface
    {
        if ($card->payment_due_day === null || ! $this->hasPeriodConfiguration($card)) {
            return null;
        }

        $today = CarbonImmutable::instance($today)->startOfDay();

        [, $currentEnd] = $this->periods->currentBoundaries($card, $today);
        [, $previousEnd] = $this->periods->completedBoundaries($card, $today);
        [, $nextEnd] = $this->periods->boundariesForDate($card, $currentEnd->addDay());

        $candidates = [];

        foreach ([$previousEnd, $currentEnd, $nextEnd] as $periodEnd) {
            $due = $this->periods->paymentDueDateFor($card, $periodEnd);

            if ($due !== null) {
                $candidates[] = $due;
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (CarbonInterface $a, CarbonInterface $b): int => $a->timestamp <=> $b->timestamp);

        foreach ($candidates as $due) {
            // "Chưa qua" tính cả HÔM NAY: đến hạn đúng hôm nay vẫn là gần nhất.
            if ($due->greaterThanOrEqualTo($today)) {
                return $due;
            }
        }

        // Mọi hạn đều đã qua (không xảy ra với luồng thường) ⇒ hạn xa nhất.
        return $candidates[count($candidates) - 1];
    }

    /**
     * Thẻ có đủ cấu hình để suy ra kỳ sao kê không.
     *
     * Có `statement_period_start` (nguồn chuẩn) HOẶC `statement_day` (fallback,
     * mặc định 1) là đủ. Chỉ khi CẢ HAI đều thiếu mới coi là không xác định được.
     */
    private function hasPeriodConfiguration(UserCard $card): bool
    {
        return $card->statement_period_start !== null
            || $card->statement_day !== null;
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
