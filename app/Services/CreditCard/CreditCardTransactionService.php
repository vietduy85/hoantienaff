<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * CreditCardTransactionService — vòng đời giao dịch NHẬP TAY.
 *
 * ---------------------------------------------------------------------------
 * CASHBACK KHÔNG ĐƯỢC NHẬP TAY
 * ---------------------------------------------------------------------------
 * Không method nào nhận `cashback_*`. Cashback là hệ quả của pipeline:
 *
 *   Transaction::create(...)  →  CashbackRecordService::calculateTransaction()
 *
 * `calculateTransaction()` tự: (1) gắn `statement_period_id`, (2) chạy lại
 * TOÀN BỘ kỳ nếu kỳ còn `open`. Nhờ vậy RETROACTIVE tier luôn đúng khi tổng kỳ
 * đổi, mà không cần service này biết gì về tier/rule.
 *
 * ---------------------------------------------------------------------------
 * KỲ FINALIZED LÀ BẤT BIẾN
 * ---------------------------------------------------------------------------
 * Giao dịch nằm trong kỳ đã chốt không được sửa/xoá — kể cả khi người dùng là
 * chủ thẻ. Đây là bất biến lịch sử; muốn điều chỉnh phải mở lại kỳ (quy trình
 * riêng, có kiểm soát).
 *
 * Xoá giao dịch là SOFT-DELETE (`Transaction` dùng SoftDeletes), nên bản ghi
 * vẫn còn cho audit; nhưng nó rời khỏi phép tính vì query mặc định loại trashed.
 */
class CreditCardTransactionService
{
    public function __construct(
        private readonly StatementPeriodService $periods,
        private readonly CashbackRecordService $records,
    ) {}

    /**
     * Giao dịch của một thẻ, mới nhất trước.
     *
     * @param  array{period_id?:int|null, category_id?:int|null, from?:string|null, to?:string|null, keyword?:string|null}  $filters
     * @return Collection<int, Transaction>
     */
    public function listFor(UserCard $userCard, array $filters = []): Collection
    {
        return Transaction::query()
            ->where('user_card_id', $userCard->id)
            ->when(isset($filters['period_id']), fn ($query) => $query->inPeriod((int) $filters['period_id']))
            ->when(isset($filters['category_id']), fn ($query) => $query->where('category_id', (int) $filters['category_id']))
            ->when(! empty($filters['from']), fn ($query) => $query->whereDate('transaction_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($query) => $query->whereDate('transaction_date', '<=', $filters['to']))
            ->when(! empty($filters['keyword']), function ($query) use ($filters): void {
                $needle = trim((string) $filters['keyword']);
                $query->where(function ($inner) use ($needle): void {
                    $inner->where('merchant', 'like', '%'.$needle.'%')
                        ->orWhere('note', 'like', '%'.$needle.'%');
                });
            })
            ->with(['category', 'statementPeriod'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Lấy giao dịch và xác nhận nó thuộc user (qua thẻ).
     *
     * Giao dịch không lưu `user_id` trực tiếp, nên phải đi qua `userCard`. Đây
     * là lớp phòng thủ cuối — không tin bất kỳ id nào do caller truyền.
     */
    public function findOwned(int $transactionId, int $userId): Transaction
    {
        $transaction = Transaction::query()
            ->with(['userCard', 'statementPeriod', 'category'])
            ->whereKey($transactionId)
            ->first();

        if ($transaction === null || (int) $transaction->userCard->user_id !== $userId) {
            throw new InvalidArgumentException('Giao dịch không tồn tại hoặc không thuộc về bạn.');
        }

        return $transaction;
    }

    /**
     * Thêm giao dịch nhập tay.
     *
     * @param  array{transaction_date:string, amount:mixed, category_id?:int|null, merchant?:string|null, posted_date?:string|null, note?:string|null}  $attributes
     */
    public function create(UserCard $userCard, array $attributes): Transaction
    {
        $this->assertCardUsable($userCard);

        $categoryId = $this->resolveCategoryId($userCard, $attributes['category_id'] ?? null);

        return DB::connection('creditcard')->transaction(function () use ($userCard, $attributes, $categoryId): Transaction {
            $transaction = Transaction::create([
                'user_card_id' => $userCard->id,
                'statement_period_id' => null,
                'category_id' => $categoryId,
                'transaction_date' => $this->parseDate($attributes['transaction_date'], 'transaction_date')->toDateString(),
                'posted_date' => $this->parseOptionalDate($attributes['posted_date'] ?? null, 'posted_date'),
                'amount' => $this->normalizeAmount($attributes['amount']),
                'merchant' => $this->nullableTrim($attributes['merchant'] ?? null),
                'note' => $this->nullableTrim($attributes['note'] ?? null),
                'source' => Transaction::SOURCE_MANUAL,
                'source_reference' => null,
            ]);

            // Gắn kỳ + tính cashback qua pipeline chuẩn.
            return $this->records->calculateTransaction($userCard, $transaction);
        });
    }

    /**
     * Sửa giao dịch nhập tay.
     *
     * Nếu ngày giao dịch đổi làm giao dịch rơi sang kỳ khác thì:
     *   - gỡ khỏi kỳ cũ (để tổng kỳ cũ hết tính nó),
     *   - gắn vào kỳ mới qua pipeline,
     *   - tính lại CẢ HAI kỳ.
     *
     * @param  array{transaction_date?:string, amount?:mixed, category_id?:int|null, merchant?:string|null, posted_date?:string|null, note?:string|null}  $attributes
     */
    public function update(int $userId, int $transactionId, array $attributes): Transaction
    {
        $transaction = $this->findOwned($transactionId, $userId);
        $this->assertMutable($transaction);

        $userCard = $transaction->userCard;
        $oldPeriodId = $transaction->statement_period_id;
        $dateChanged = array_key_exists('transaction_date', $attributes);

        return DB::connection('creditcard')->transaction(function () use ($transaction, $userCard, $attributes, $oldPeriodId, $dateChanged): Transaction {
            if ($dateChanged) {
                $transaction->transaction_date = $this->parseDate($attributes['transaction_date'], 'transaction_date')->toDateString();
            }

            if (array_key_exists('posted_date', $attributes)) {
                $transaction->posted_date = $this->parseOptionalDate($attributes['posted_date'], 'posted_date');
            }

            if (array_key_exists('amount', $attributes)) {
                $transaction->amount = $this->normalizeAmount($attributes['amount']);
            }

            if (array_key_exists('category_id', $attributes)) {
                $transaction->category_id = $this->resolveCategoryId($userCard, $attributes['category_id']);
            }

            if (array_key_exists('merchant', $attributes)) {
                $transaction->merchant = $this->nullableTrim($attributes['merchant']);
            }

            if (array_key_exists('note', $attributes)) {
                $transaction->note = $this->nullableTrim($attributes['note']);
            }

            // Đổi ngày ⇒ phải resolve lại kỳ. Ép về NULL để `calculateTransaction`
            // gắn lại theo basis mới thay vì giữ kỳ cũ.
            if ($dateChanged) {
                $transaction->statement_period_id = null;
            }

            $transaction->save();

            $transaction = $this->records->calculateTransaction($userCard, $transaction);

            // Kỳ cũ mất giao dịch này ⇒ phải tính lại để tổng/ cap đúng.
            $this->recalculateDetachedPeriod($userCard, $oldPeriodId, $transaction);

            return $transaction;
        });
    }

    /**
     * Xoá mềm giao dịch và tính lại kỳ chứa nó.
     */
    public function delete(int $userId, int $transactionId): void
    {
        $transaction = $this->findOwned($transactionId, $userId);
        $this->assertMutable($transaction);

        $userCard = $transaction->userCard;
        $period = $transaction->statementPeriod;

        DB::connection('creditcard')->transaction(function () use ($transaction, $userCard, $period): void {
            $transaction->delete();

            if ($period !== null && ! $period->isFinalized()) {
                $this->records->calculatePeriod($userCard, $period);
            }
        });
    }

    /**
     * Danh mục của giao dịch phải nằm trong tập user được chọn (hệ thống + riêng
     * của họ). Không cho gán vào danh mục của user khác.
     */
    private function resolveCategoryId(UserCard $userCard, mixed $categoryId): ?int
    {
        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        $exists = Category::query()
            ->selectableBy((int) $userCard->user_id)
            ->whereKey((int) $categoryId)
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException('Danh mục không tồn tại hoặc không thuộc quyền sử dụng của bạn.');
        }

        return (int) $categoryId;
    }

    private function recalculateDetachedPeriod(UserCard $userCard, ?int $oldPeriodId, Transaction $transaction): void
    {
        if ($oldPeriodId === null || (int) $transaction->statement_period_id === (int) $oldPeriodId) {
            return;
        }

        $oldPeriod = StatementPeriod::query()->whereKey($oldPeriodId)->first();

        if ($oldPeriod !== null && ! $oldPeriod->isFinalized()) {
            $this->records->calculatePeriod($userCard, $oldPeriod);
        }
    }

    private function assertCardUsable(UserCard $userCard): void
    {
        if ($userCard->isClosed()) {
            throw new LogicException('Thẻ đã đóng nên không thêm được giao dịch.');
        }
    }

    private function assertMutable(Transaction $transaction): void
    {
        $period = $transaction->statementPeriod;

        if ($period !== null && $period->isFinalized()) {
            throw new LogicException('Kỳ sao kê đã chốt nên giao dịch không được sửa hoặc xoá.');
        }
    }

    private function normalizeAmount(mixed $value): string
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException('Số tiền giao dịch không được để trống.');
        }

        $amount = (float) $value;

        if ($amount === 0.0) {
            throw new InvalidArgumentException('Số tiền giao dịch phải khác 0.');
        }

        return number_format($amount, 2, '.', '');
    }

    private function parseDate(mixed $value, string $field): CarbonImmutable
    {
        $date = $this->parseOptionalDate($value, $field);

        if ($date === null) {
            throw new InvalidArgumentException("{$field} không hợp lệ (cần định dạng Y-m-d).");
        }

        return $date;
    }

    private function parseOptionalDate(mixed $value, string $field): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        // App bật Carbon strict mode nên phải kiểm tra format trước, không dựa
        // vào việc `parse()` trả false.
        $value = trim((string) $value);

        if (! CarbonImmutable::hasFormat($value, 'Y-m-d')) {
            throw new InvalidArgumentException("{$field} không hợp lệ (cần định dạng Y-m-d).");
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
