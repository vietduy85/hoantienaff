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
