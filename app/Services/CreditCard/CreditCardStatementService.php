<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\CreditCardStatement;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use App\Support\CreditCard\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * CreditCardStatementService — đọc/ghi SAO KÊ THỰC TẾ của một kỳ.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO CẦN NỐI RIÊNG `StatementPeriodService`
 * ---------------------------------------------------------------------------
 * `StatementPeriodService` là nguồn duy nhất của ranh giới kỳ. Service này KHÔNG
 * tự tính kỳ, chỉ hỏi nó rồi ghép dòng sao kê vào đúng cặp (thẻ, kỳ).
 *
 * ---------------------------------------------------------------------------
 * ĐỌC ≠ GHI — KHÔNG BAO GIỜ TẠO KỲ KHI CHỈ ĐỌC
 * ---------------------------------------------------------------------------
 * `StatementPeriodService::currentPeriod()` TẠO bản ghi kỳ nếu chưa có; `findForDate()`
 * thì không. Trang Sao kê là trang NHẬP LIỆU, nên mọi đường đọc ở đây dùng
 * `findForDate()`: mở trang không được sinh bản ghi (cùng nguyên tắc đã có ở
 * trang Tổng quan — xem `CreditCardModuleTest::index_does_not_create_statement_periods`).
 */
class CreditCardStatementService
{
    public function __construct(private readonly StatementPeriodService $periods)
    {
    }

    /**
     * Kỳ hiện tại của thẻ NẾU kỳ đã tồn tại; không tạo mới.
     */
    public function currentPeriodFor(UserCard $card, ?CarbonInterface $today = null): ?StatementPeriod
    {
        return $this->periods->findForDate($card, $today ?? CarbonImmutable::now());
    }

    /**
     * Dòng sao kê của kỳ hiện tại, hoặc null. Chỉ đọc.
     */
    public function currentFor(UserCard $card, ?CarbonInterface $today = null): ?CreditCardStatement
    {
        $period = $this->currentPeriodFor($card, $today);

        return $period === null ? null : $this->findOrNull($card, $period);
    }

    /**
     * Dòng sao kê của một kỳ cụ thể, hoặc null. Chỉ đọc.
     */
    public function findOrNull(UserCard $card, StatementPeriod $period): ?CreditCardStatement
    {
        return CreditCardStatement::query()
            ->where('user_card_id', $card->id)
            ->where('statement_period_id', $period->id)
            ->first();
    }

    /**
     * Cặp [kỳ hiện tại, dòng sao kê] để view dùng — gom vào đây để controller
     * không phải tự ghép, và để mọi màn hình đều đi qua CÙNG một đường đọc.
     *
     * @return array{0: ?StatementPeriod, 1: ?CreditCardStatement}
     */
    public function currentBundleFor(UserCard $card, ?CarbonInterface $today = null): array
    {
        $period = $this->currentPeriodFor($card, $today);

        return [$period, $period === null ? null : $this->findOrNull($card, $period)];
    }

    /**
     * `period_start` MẶC ĐỊNH khi mở form "Nhập sao kê": kỳ đã kết thúc gần nhất.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO MẶC ĐỊNH LÀ KỲ ĐÃ KẾT THÚC, KHÔNG PHẢI KỲ HIỆN TẠI
     * ---------------------------------------------------------------------------
     * Người dùng mở trang để nhập bảng kê NGÂN HÀNG ĐÃ PHÁT HÀNH — bảng kê đó ứng
     * với kỳ vừa chốt, không phải kỳ đang mở. Mặc định kỳ hiện tại buộc người
     * dùng nhập số của kỳ cũ vào kỳ mới: sai dữ liệu, sai ngày đến hạn, và
     * "còn phải trả" sai.
     *
     * Kỳ đã kết thúc gần nhất luôn tồn tại (lùi một kỳ từ kỳ hiện tại) nên hàm
     * này không bao giờ null — không cần fallback.
     */
    public function defaultPeriodStart(UserCard $card, ?CarbonInterface $today = null): string
    {
        [$start] = $this->periods->completedBoundaries($card, $today);

        return $start->toDateString();
    }

    /**
     * `period_start` người dùng gửi lên có hợp lệ với dropdown không.
     *
     * Chỉ SO SÁNH với ranh giới service suy ra, không tự tính lại chu kỳ — nếu
     * hai nơi cùng suy luận kỳ thì chỗ sai là chỗ không ai nhìn thấy.
     */
    public function isSelectablePeriodStart(UserCard $card, string $periodStart, ?CarbonInterface $today = null): bool
    {
        // So sánh CHUỖI ngày, không parse: `selectableBoundaries()` trả về ISO
        // `Y-m-d`, nên trùng chuỗi đã đủ hẹp — ngày sai định dạng không thể trùng
        // được chuỗi nào, và không cần thêm một nhánh parse để bắt lỗi đó.
        foreach ($this->periods->selectableBoundaries($card, $today) as [$start]) {
            if ($start->toDateString() === $periodStart) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bundle của một kỳ CHỈ ĐỌC, theo `period_start`.
     *
     * Kỳ chưa có bản ghi vẫn trả về ranh giới đúng (người dùng cần biết mình
     * đang nhìn kỳ nào) nhưng `period` là null và `statement` là null. Chỉ khi
     * LƯU thì `StatementPeriodService::resolvePeriodStart()` mới tạo bản ghi kỳ.
     *
     * `due_date` trả về cả khi kỳ chưa có bản ghi: hạn thanh toán suy ra được
     * từ `paymentDueDateFor()` (thuần toán), và người dùng cần biết kỳ mình đang
     * xem đến hạn khi nào — kể cả trước khi nhập. Lưu lại cùng khi lưu sao kê
     * cho ra CÙNG ngày, vì cùng một công thức.
     *
     * @return array{period: ?StatementPeriod, statement: ?CreditCardStatement, start: CarbonImmutable, end: CarbonImmutable, due_date: ?CarbonImmutable}
     */
    public function bundleForPeriodStart(UserCard $card, string $periodStart, ?CarbonInterface $today = null): array
    {
        $date = CarbonImmutable::createFromFormat('Y-m-d', $periodStart)->startOfDay();

        [$start, $end] = $this->periods->boundariesForPeriodStart($card, $date);
        $period = $this->periods->findByBoundaries($card, $start, $end);

        return [
            'period' => $period,
            'statement' => $period === null ? null : $this->findOrNull($card, $period),
            'start' => $start,
            'end' => $end,
            'due_date' => $period?->payment_due_date ?? $this->periods->paymentDueDateFor($card, $end),
        ];
    }

    /**
     * Danh sách kỳ cho dropdown, mới nhất trước — CHỈ ĐỌC.
     *
     * Ranh giới lấy từ `StatementPeriodService::selectableBoundaries()`; bản ghi
     * kỳ và dòng sao kê được nạp SẴN cho cả danh sách để không tạy N+1 khi
     * người dùng mở trang có nhiều thẻ.
     *
     * `period_id` có thể null: kỳ hợp lệ mà chưa có bản ghi. Đó là bình thường —
     * bản ghi kỳ chỉ sinh khi có việc cần ghi.
     *
     * @return list<array<string, mixed>>
     */
    public function selectablePeriods(UserCard $card, ?CarbonInterface $today = null, int $limit = StatementPeriodService::SELECTABLE_LIMIT): array
    {
        $bounds = $this->periods->selectableBoundaries($card, $today, $limit);

        $periods = StatementPeriod::query()
            ->where('user_card_id', $card->id)
            ->get()
            ->keyBy(fn (StatementPeriod $period): string => $period->period_start->toDateString());

        $statements = CreditCardStatement::query()
            ->where('user_card_id', $card->id)
            ->get()
            ->keyBy('statement_period_id');

        [$currentStart] = $this->periods->currentBoundaries($card, $today);

        $options = [];

        foreach ($bounds as [$start, $end]) {
            $period = $periods->get($start->toDateString());
            $statement = $period === null ? null : $statements->get($period->id);

            $options[] = [
                'period_start' => $start->toDateString(),
                'start_label' => $start->format('d/m/Y'),
                'end_label' => $end->format('d/m/Y'),
                'period_id' => $period === null ? null : (int) $period->id,
                'has_statement' => $statement !== null,
                // Cờ "kỳ hiện tại" so với RANH GIỚI kỳ hiện tại, không so với bản
                // ghi kỳ: thẻ chưa có bản ghi nào thì so bản ghi sẽ cho cờ sai.
                'is_current' => $start->toDateString() === $currentStart->toDateString(),
            ];
        }

        return $options;
    }

    /**
     * Bundle kỳ đã kết thúc gần nhất của một thẻ — cho TỔNG QUAN hiển thị.
     *
     * Không trả về `period` khi kỳ đó chưa có bản ghi: Tổng quan cần câu trả lời
     * "kỳ nào đã có sao kê", mà kỳ chưa ghi thì chưa có gì để hiển thị.
     *
     * @return array{period: ?StatementPeriod, statement: ?CreditCardStatement, start: CarbonImmutable, end: CarbonImmutable, due_date: ?CarbonImmutable}
     */
    public function latestCompletedBundleFor(UserCard $card, ?CarbonInterface $today = null): array
    {
        [$start, $end] = $this->periods->completedBoundaries($card, $today);
        $period = $this->periods->findByBoundaries($card, $start, $end);

        return [
            'period' => $period,
            'statement' => $period === null ? null : $this->findOrNull($card, $period),
            'start' => $start,
            'end' => $end,
            'due_date' => $period?->payment_due_date ?? $this->periods->paymentDueDateFor($card, $end),
        ];
    }

    /**
     * Ghi sao kê cho một cặp (thẻ, kỳ) — tạo nếu chưa có, sửa nếu đã có.
     *
     * `closing_balance` KHÔNG đọc từ `$data`: nó luôn tính lại trong model từ
     * `actual_spend - actual_reward`. Client gửi kèm cũng bị bỏ qua.
     *
     * @param  array<string, mixed>  $data  chỉ cần `actual_spend` + `actual_reward`
     */
    public function upsert(UserCard $card, StatementPeriod $period, array $data): CreditCardStatement
    {
        // Chặn ghép kỳ của thẻ khác: `update`/`destroy` nhận id từ URL nên đây là
        // lớp phòng thủ CUỐI, sau policy.
        if ((int) $period->user_card_id !== (int) $card->id) {
            throw new \InvalidArgumentException('Kỳ sao kê không thuộc thẻ này.');
        }

        // Kỳ đã chốt là bản ghi lịch sử ⇒ không sửa được. Cùng nguyên tắc với
        // `TransactionPolicy` và `StatementPeriodService::assignTransactionToPeriod()`.
        if ($period->isFinalized()) {
            throw new \LogicException('Không thể sửa sao kê của kỳ đã chốt.');
        }

        return DB::connection('creditcard')->transaction(function () use ($card, $period, $data): CreditCardStatement {
            $statement = $this->findOrNull($card, $period);

            // Gộp với giá trị ĐANG LƯU: cập nhật là từng phần, nên sửa riêng
            // `actual_reward` không được làm `actual_spend` rơi về 0 — mất tiền thật.
            $actualSpend = array_key_exists('actual_spend', $data)
                ? Decimal::money($data['actual_spend'])
                : ($statement === null ? '0.00' : Decimal::money($statement->actual_spend));

            $actualReward = array_key_exists('actual_reward', $data)
                ? Decimal::money($data['actual_reward'])
                : ($statement === null ? '0.00' : Decimal::money($statement->actual_reward));

            if (Decimal::compare($actualReward, $actualSpend) > 0) {
                throw new \InvalidArgumentException('Tiền hoàn/thưởng không được lớn hơn thực tế chi tiêu.');
            }

            $attributes = [
                'actual_spend' => $actualSpend,
                'actual_reward' => $actualReward,
            ];

            if ($statement === null) {
                $statement = new CreditCardStatement([
                    'user_card_id' => $card->id,
                    'statement_period_id' => $period->id,
                    ...$attributes,
                ]);
            } else {
                $statement->forceFill($attributes);
            }

            // Tính TRƯỚC rồi `save()` một lần: `closing_balance` là NOT NULL, nên
            // tạo dòng trước rồi tính sau sẽ phải UPDATE lần hai.
            $statement->recalculateClosingBalance();
            $statement->save();

            return $statement->refresh();
        });
    }

    /**
     * Ghi sao kê vào kỳ NGƯỜI DÙNG CHỌN, theo `period_start`.
     *
     * Đây là cổng GHI duy nhất cho form nhập: nó nối "người dùng chọn kỳ nào"
     * với "tạo bản ghi kỳ đó nếu chưa có" trong một chỗ, để controller không
     * phải tự ghép `resolvePeriodStart()` + `upsert()` và không thể quên kiểm tra
     * kỳ có hợp lệ với thẻ không.
     */
    public function upsertForPeriodStart(UserCard $card, string $periodStart, array $data): CreditCardStatement
    {
        $date = CarbonImmutable::createFromFormat('Y-m-d', $periodStart)->startOfDay();

        $period = $this->periods->resolvePeriodStart($card, $date);

        return $this->upsert($card, $period, $data);
    }

    /**
     * Xoá dòng sao kê của một cặp (thẻ, kỳ).
     */
    public function delete(UserCard $card, StatementPeriod $period): void
    {
        if ((int) $period->user_card_id !== (int) $card->id) {
            throw new \InvalidArgumentException('Kỳ sao kê không thuộc thẻ này.');
        }

        if ($period->isFinalized()) {
            throw new \LogicException('Không thể xoá sao kê của kỳ đã chốt.');
        }

        CreditCardStatement::query()
            ->where('user_card_id', $card->id)
            ->where('statement_period_id', $period->id)
            ->delete();
    }
}
