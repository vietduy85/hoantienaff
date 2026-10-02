<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Bank;
use App\Models\CreditCard\UserCard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * UserCardService — vòng đời thẻ tín dụng: create / update / deactivate.
 *
 * ---------------------------------------------------------------------------
 * KIẾN TRÚC PHASE 1B
 * ---------------------------------------------------------------------------
 * `UserCard = bank_id + name`:
 *   - `bank_id` → FK trực tiếp tới `credit_card_banks`. Migration 11 siết
 *     NOT NULL, nhưng migration 22 đã mở lại thành NULLABLE: người dùng được
 *     khai thẻ trước khi biết ngân hàng phát hành (xem `normalizeBankId()`).
 *     `NULL` = "chưa biết" — mọi nơi đọc `->bank` phải chịu được null.
 *   - `name`    → tên gợi nhớ do USER tự đặt, ví dụ "MB JCB Ultimate".
 *
 * `product_id` KHÔNG xuất hiện trong bất kỳ method ghi nào. Cột đó chỉ còn để
 * đọc dữ liệu Phase 1A cũ (xem migration 000011).
 *
 * ---------------------------------------------------------------------------
 * KHÔNG LƯU DỮ LIỆU NHẠY CẢM
 * ---------------------------------------------------------------------------
 * Chỉ lưu `card_number_last4` (4 số cuối). Không có API nhận full PAN, CVV hay
 * ngày hết hạn — không chỉ vì PCI, mà vì dữ liệu đó không cần cho cashback và
 * tăng rủi ro lộ thông tin nếu DB bị đọc.
 *
 * ---------------------------------------------------------------------------
 * DEACTIVATE, KHÔNG HARD-DELETE
 * ---------------------------------------------------------------------------
 * Thẻ đã có giao dịch, policy version hoặc kỳ sao kê là dữ liệu lịch sử. Xoá
 * cứng sẽ phá cashback đã ghi. Nên `deactivate()` chỉ chuyển `status` và đóng
 * `closed_at`; bản ghi vẫn còn để tra snapshot cũ.
 */
class UserCardService
{
    public function __construct(
        private readonly BankService $banks,
    ) {}

    /**
     * Danh sách thẻ của user, thứ tự do user tự quyết định.
     *
     * @return Collection<int, UserCard>
     */
    public function listFor(int $userId, bool $activeOnly = false): Collection
    {
        return UserCard::query()
            ->ownedBy($userId)
            ->when($activeOnly, fn ($query) => $query->active())
            ->with(['bank', 'currentPolicy'])
            ->ordered()
            ->get();
    }

    /**
     * Lấy thẻ của user. Ném exception nếu không thuộc user ⇒ chặn user A đọc
     * dữ liệu thẻ của user B ngay ở tầng service.
     */
    public function findOwned(int $userCardId, int $userId): UserCard
    {
        $card = UserCard::query()
            ->ownedBy($userId)
            ->whereKey($userCardId)
            ->first();

        if ($card === null) {
            throw new InvalidArgumentException('Thẻ tín dụng không tồn tại hoặc không thuộc về bạn.');
        }

        return $card->loadMissing(['bank', 'currentPolicy']);
    }

    public function findOwnedActive(int $userCardId, int $userId): UserCard
    {
        $card = $this->findOwned($userCardId, $userId);

        if (! $card->isUsable()) {
            throw new LogicException('Thẻ đã đóng hoặc không active nên không dùng được.');
        }

        return $card;
    }

    /**
     * Tạo thẻ mới.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(int $userId, array $attributes): UserCard
    {
        $bankId = $this->normalizeBankId($attributes['bank_id'] ?? null);

        return DB::connection('creditcard')->transaction(function () use ($userId, $bankId, $attributes): UserCard {
            [$periodStart, $periodEnd] = $this->normalizeStatementPeriod($attributes['statement_period_start'] ?? null);

            $card = UserCard::create([
                'user_id' => $userId,
                'bank_id' => $bankId,
                'name' => $this->normalizeName($attributes['name']),
                'card_number_last4' => $this->normalizeLast4($attributes['card_number_last4'] ?? null),
                'credit_limit' => $this->normalizeMoney($attributes['credit_limit'] ?? null),
                'desired_spend' => $this->normalizeMoney($attributes['desired_spend'] ?? null),
                'statement_day' => $this->normalizeDay($attributes['statement_day'] ?? 1, 'statement_day'),
                'payment_due_day' => $this->normalizeDay($attributes['payment_due_day'] ?? 25, 'payment_due_day'),
                'spending_deadline_day' => isset($attributes['spending_deadline_day'])
                    ? $this->normalizeDay($attributes['spending_deadline_day'], 'spending_deadline_day')
                    : null,
                'statement_date_basis' => $this->normalizeBasis($attributes['statement_date_basis'] ?? UserCard::BASIS_TRANSACTION_DATE),
                'opened_at' => $this->normalizeDate($attributes['opened_at'] ?? null),
                'statement_period_start' => $periodStart,
                'statement_period_end' => $periodEnd,
                'closed_at' => null,
                'sort_order' => (int) ($attributes['sort_order'] ?? $this->nextSortOrder($userId)),
                'note' => $attributes['note'] ?? null,
                'promotion_info' => $attributes['promotion_info'] ?? null,
                // Chưa có policy ⇒ current_policy_id để null, card vẫn tạo được.
                'current_policy_id' => null,
                'status' => UserCard::STATUS_ACTIVE,
            ]);

            return $card->load('bank');
        });
    }

    /**
     * Sửa thẻ.
     *
     * Thẻ đã đóng KHÔNG sửa được — cần mở lại trước, nếu không thì việc sửa sẽ
     * âm thầm tác động dữ liệu lịch sử của một thẻ không còn dùng.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(int $userId, int $userCardId, array $attributes): UserCard
    {
        $card = $this->findOwned($userCardId, $userId);

        if ($card->isClosed()) {
            throw new LogicException('Thẻ đã đóng. Hãy mở lại thẻ trước khi sửa.');
        }

        return DB::connection('creditcard')->transaction(function () use ($card, $attributes): UserCard {
            if (array_key_exists('bank_id', $attributes)) {
                $card->bank_id = $this->normalizeBankId($attributes['bank_id']);
            }

            if (array_key_exists('name', $attributes)) {
                $card->name = $this->normalizeName($attributes['name']);
            }

            if (array_key_exists('card_number_last4', $attributes)) {
                $card->card_number_last4 = $this->normalizeLast4($attributes['card_number_last4']);
            }

            if (array_key_exists('credit_limit', $attributes)) {
                $card->credit_limit = $this->normalizeMoney($attributes['credit_limit']);
            }

            if (array_key_exists('desired_spend', $attributes)) {
                $card->desired_spend = $this->normalizeMoney($attributes['desired_spend']);
            }

            if (array_key_exists('statement_day', $attributes)) {
                $card->statement_day = $this->normalizeDay($attributes['statement_day'], 'statement_day');
            }

            if (array_key_exists('payment_due_day', $attributes)) {
                $card->payment_due_day = $this->normalizeDay($attributes['payment_due_day'], 'payment_due_day');
            }

            if (array_key_exists('spending_deadline_day', $attributes)) {
                $card->spending_deadline_day = $this->normalizeDay($attributes['spending_deadline_day'], 'spending_deadline_day');
            }

            if (array_key_exists('statement_date_basis', $attributes)) {
                $card->statement_date_basis = $this->normalizeBasis($attributes['statement_date_basis']);
            }

            if (array_key_exists('opened_at', $attributes)) {
                $card->opened_at = $this->normalizeDate($attributes['opened_at']);
            }

            // Chỉ start mới là input. End luôn được tính lại từ start nên không
            // bao giờ xảy ra trường hợp cặp ngày lệch nhau do client gửi.
            if (array_key_exists('statement_period_start', $attributes)) {
                [$periodStart, $periodEnd] = $this->normalizeStatementPeriod($attributes['statement_period_start']);

                $card->statement_period_start = $periodStart;
                $card->statement_period_end = $periodEnd;
            }

            if (array_key_exists('sort_order', $attributes)) {
                $card->sort_order = (int) $attributes['sort_order'];
            }

            if (array_key_exists('note', $attributes)) {
                $card->note = $attributes['note'];
            }

            if (array_key_exists('promotion_info', $attributes)) {
                $card->promotion_info = $attributes['promotion_info'];
            }

            $card->save();

            return $card->refresh()->load('bank');
        });
    }

    /**
     * Đóng thẻ: chỉ chuyển trạng thái, KHÔNG xoá bản ghi.
     *
     * Giữ lại toàn bộ giao dịch, kỳ sao kê, policy version và snapshot cashback
     * đã ghi trước đó — đó là dữ liệu lịch sử, không phải rác.
     */
    public function deactivate(int $userId, int $userCardId, ?string $closedAt = null): UserCard
    {
        $card = $this->findOwned($userCardId, $userId);

        return DB::connection('creditcard')->transaction(function () use ($card, $closedAt): UserCard {
            $card->forceFill([
                'status' => UserCard::STATUS_INACTIVE,
                'closed_at' => $closedAt !== null
                    ? $this->normalizeDate($closedAt)
                    : CarbonImmutable::now()->toDateString(),
            ])->save();

            return $card->refresh();
        });
    }

    /**
     * Mở lại thẻ đã đóng.
     */
    public function reactivate(int $userId, int $userCardId): UserCard
    {
        $card = $this->findOwned($userCardId, $userId);

        return DB::connection('creditcard')->transaction(function () use ($card): UserCard {
            $card->forceFill([
                'status' => UserCard::STATUS_ACTIVE,
                'closed_at' => null,
            ])->save();

            return $card->refresh();
        });
    }

    /**
     * Sắp xếp lại thứ tự hiển thị.
     *
     * @param  array<int, int>  $orderedIds  user card ids theo thứ tự mong muốn
     * @return Collection<int, UserCard>
     */
    public function reorder(int $userId, array $orderedIds): Collection
    {
        return DB::connection('creditcard')->transaction(function () use ($userId, $orderedIds): Collection {
            $cards = $this->listFor($userId)->keyBy('id');
            $position = 1;

            foreach ($orderedIds as $cardId) {
                $card = $cards->get((int) $cardId);

                // Bỏ qua id không thuộc user thay vì ném lỗi: đây là thao tác
                // kéo-thả trên UI, id lạ chỉ cần bị loại.
                if ($card === null) {
                    continue;
                }

                $card->forceFill(['sort_order' => $position++])->save();
            }

            return $this->listFor($userId);
        });
    }

    /**
     * Thẻ còn nhận giao dịch không? Dùng cho UI chặn nút thêm giao dịch.
     */
    public function hasUsableCard(int $userId): bool
    {
        return UserCard::query()
            ->ownedBy($userId)
            ->active()
            ->whereNull('closed_at')
            ->exists();
    }

    /**
     * Tổng hạn mức của các thẻ đang active (trang tổng quan).
     */
    public function totalActiveLimit(int $userId): float
    {
        return (float) UserCard::query()
            ->ownedBy($userId)
            ->active()
            ->sum('credit_limit');
    }

    /**
     * Ngân hàng dùng để hiển thị/số liệu — luôn resolve qua `bank_id` trực tiếp.
     */
    public function bankOf(UserCard $card): ?Bank
    {
        return $card->bank;
    }

    private function nextSortOrder(int $userId): int
    {
        return ((int) UserCard::query()->ownedBy($userId)->max('sort_order')) + 1;
    }

    /**
     * `bank_id` OPTIONAL (migration 22) — trả `null` khi người dùng chưa chọn.
     *
     * Chuỗi rỗng / `null` / giá trị không phải số đều nghĩa là "chưa biết ngân hàng",
     * không phải lỗi: form mobile cho phép khai thẻ trước khi tra được mã ngân hàng.
     * Còn lại thì vẫn phải là bank tồn tại VÀ đang active, ném lỗi nếu không.
     */
    private function normalizeBankId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Ngân hàng không hợp lệ.');
        }

        return $this->banks->findActiveForAssignment((int) $value)->id;
    }

    private function normalizeName(string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            throw new InvalidArgumentException('Tên thẻ không được để trống.');
        }

        return $name;
    }

    /**
     * Chỉ giữ 4 số cuối, và chỉ khi là đúng 4 chữ số.
     */
    private function normalizeLast4(?string $last4): ?string
    {
        if ($last4 === null) {
            return null;
        }

        $last4 = preg_replace('/\D+/', '', (string) $last4) ?? '';

        if ($last4 === '') {
            return null;
        }

        if (strlen($last4) !== 4) {
            throw new InvalidArgumentException('Số cuối thẻ phải đúng 4 chữ số.');
        }

        return $last4;
    }

    private function normalizeMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $amount = (float) $value;

        if ($amount < 0) {
            throw new InvalidArgumentException('Hạn mức tín dụng không được âm.');
        }

        return number_format($amount, 2, '.', '');
    }

    private function normalizeDay(mixed $value, string $field): int
    {
        $day = (int) $value;

        if ($day < StatementPeriodService::MIN_DAY || $day > StatementPeriodService::MAX_DAY) {
            throw new InvalidArgumentException("{$field} phải từ 1 đến 31.");
        }

        return $day;
    }

    private function normalizeBasis(string $basis): string
    {
        $allowed = [UserCard::BASIS_TRANSACTION_DATE, UserCard::BASIS_POSTED_DATE];

        if (! in_array($basis, $allowed, true)) {
            throw new InvalidArgumentException('statement_date_basis không hợp lệ.');
        }

        return $basis;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->toDateString();
    }

    /**
     * Cặp ngày kỳ sao kê `[start, end]`.
     *
     * ---------------------------------------------------------------------------
     * CÔNG THỨC
     * ---------------------------------------------------------------------------
     * Kỳ sao kê là MỘT chu kỳ tháng, neo theo ngày bắt đầu:
     *
     *     end = start + 1 tháng − 1 ngày
     *
     * Nhờ vậy "01/01 → 31/01", "15/01 → 14/02", "05/03 → 04/04"… luôn đúng một
     * chu kỳ tháng mà không cần user tự tính, và độ dài kỳ không phụ thuộc tháng
     * nào có 30 hay 31 ngày.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO TRÀN NGÀY ĐƯỢC XỬ LÝ
     * ---------------------------------------------------------------------------
     * `addMonthNoOverflow()` giữ nguyên ngày khi tháng đích đủ dài, còn ngày 29–31
     * gặp tháng ngắn hơn sẽ tràn. Ta lấy ngày cuối tháng đích thay vì để
     * Carbon lùi về 28/02 — kỳ phải phủ HẾT tháng sau, không được hụt mấy ngày.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function statementPeriodEnd(mixed $start): ?string
    {
        $start = $this->normalizeDate($start);

        if ($start === null) {
            return null;
        }

        $from = CarbonImmutable::parse($start);
        $nextMonth = $from->addMonthNoOverflow();

        if ((int) $nextMonth->day !== (int) $from->day) {
            // addMonthNoOverflow đã lùi sang cuối tháng ngắn ⇒ lấy đúng ngày cuối.
            $nextMonth = $nextMonth->endOfMonth()->startOfDay();
        }

        return $nextMonth->subDay()->toDateString();
    }

    /**
     * Chuẩn hoá input kỳ sao kê. Client CHỈ gửi `start`; `end` luôn do server tính.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function normalizeStatementPeriod(mixed $start): array
    {
        $start = $this->normalizeDate($start);

        return [$start, $this->statementPeriodEnd($start)];
    }
}
