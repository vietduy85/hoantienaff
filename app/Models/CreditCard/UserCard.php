<?php

namespace App\Models\CreditCard;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * UserCard — thẻ thật của user. Mỗi thẻ có policy và cấu hình kỳ sao kê RIÊNG.
 *
 * BẢO MẬT: chỉ lưu `card_number_last4`. KHÔNG lưu số thẻ đầy đủ, CVV, ngày hết hạn.
 * `$hidden` khai `card_number_last4` để không serialize nhầm ra ngoài.
 *
 * `spending_deadline_day` là metadata CHỈ ĐỂ NHẮC NHỞ, không quyết định kỳ sao kê.
 *
 * ---------------------------------------------------------------------------
 * THẺ KHÔNG CẦN "PRODUCT CATALOG" (điều chỉnh kiến trúc Phase 1B)
 * ---------------------------------------------------------------------------
 * Luồng chính: chọn `bank_id` (master data hệ thống) + tự đặt `name` gợi nhớ.
 * Một ngân hàng phát hành rất nhiều dòng thẻ, nên bắt user chọn từ catalog sẽ tạo
 * ma sát và dữ liệu sai. Xem migration 000011.
 *
 * `product_id` còn lại là CỘT DEPRECATED (nullable): giữ để không mất dữ liệu
 * Phase 1A và không phải rollback. Code mới KHÔNG được đọc/ghi cột này.
 *
 * @property int $id
 * @property int $user_id
 * @property int $bank_id
 * @property string $name
 * @property string|null $card_number_last4
 * @property string|null $credit_limit
 * @property int $statement_day
 * @property int $payment_due_day
 * @property int|null $spending_deadline_day
 * @property string $statement_date_basis
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property int $sort_order
 * @property string|null $note
 * @property int|null $current_policy_id
 * @property string $status
 * @property int|null $product_id DEPRECATED — không dùng, xem migration 000011
 */
class UserCard extends CreditCardModel
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const BASIS_TRANSACTION_DATE = 'transaction_date';

    public const BASIS_POSTED_DATE = 'posted_date';

    protected $table = 'credit_card_user_cards';

    protected $fillable = [
        'user_id',
        'bank_id',
        'name',
        'card_number_last4',
        'credit_limit',
        'statement_day',
        'payment_due_day',
        'spending_deadline_day',
        'statement_date_basis',
        'opened_at',
        'closed_at',
        'sort_order',
        'note',
        'current_policy_id',
        'status',
    ];

    /**
     * 4 số cuối không bao giờ nên serialize ra ngoài.
     */
    protected $hidden = [
        'card_number_last4',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'bank_id' => 'integer',
            'credit_limit' => 'decimal:2',
            'statement_day' => 'integer',
            'payment_due_day' => 'integer',
            'spending_deadline_day' => 'integer',
            'sort_order' => 'integer',
            'opened_at' => 'date',
            'closed_at' => 'date',
            'current_policy_id' => 'integer',
        ];
    }

    /**
     * Chủ thẻ. QUAN HỆ CROSS-DATABASE (logical, không FK).
     *
     * `belongsTo` sinh một query riêng trên connection CHÍNH
     * (`select * from users where id = ?`) — KHÔNG sinh JOIN, nên vẫn an toàn
     * với DB isolation. Tuyệt đối không dùng `join('users')`.
     *
     * Phải DỰNG TAY bằng `newBelongsTo()` thay vì `belongsTo()`:
     *   1. `belongsTo()` gọi `newRelatedInstance()` → `new User` rồi copy
     *      connection `creditcard` sang `User`.
     *   2. `newQuery()` đã lấy connection `creditcard` vào builder, nên gọi
     *      `setConnection()` sau đó cũng vô ích.
     *   3. Dựng tay thì ghim connection chính TRƯỚC khi tạo query.
     *
     * Chi tiết: `CreditCardModel::mainDatabaseConnectionName()`.
     */
    public function user(): BelongsTo
    {
        $user = (new User)
            ->setConnection($this->mainDatabaseConnectionName());

        return $this->newBelongsTo(
            $user->newQuery(),
            $this,
            'user_id',
            $user->getKeyName(),
            'user'
        );
    }

    /**
     * Sản phẩm thẻ — CỘT DEPRECATED (Phase 1B).
     *
     * Giữ relation để dữ liệu Phase 1A cũ vẫn đọc được, nhưng luồng mới KHÔNG
     * dùng. Không thêm/xoá giao dịch nào dựa trên relation này.
     *
     * @see UserCard::bank()  luồng chính
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Ngân hàng phát hành thẻ này.
     *
     * Phase 1B: FK TRỰC TIẾP trên `credit_card_user_cards`, không đi qua
     * `product`. Cùng connection `creditcard` nên quan hệ này hoàn toàn bình
     * thường.
     *
     * Trước Phase 1B đây là `hasOneThrough` đi qua `credit_card_products`; xem
     * migration 000011.
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    /**
     * Policy root đang áp dụng cho thẻ này.
     */
    public function currentPolicy(): BelongsTo
    {
        return $this->belongsTo(Policy::class, 'current_policy_id');
    }

    public function statementPeriods(): HasMany
    {
        return $this->hasMany(StatementPeriod::class, 'user_card_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'user_card_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Thẻ đã đóng (có `closed_at`) không nhận giao dịch mới nữa.
     */
    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * Thẻ dùng được: còn active và chưa đóng.
     */
    public function isUsable(): bool
    {
        return $this->isActive() && ! $this->isClosed();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Thứ tự hiển thị do user tự quyết định (Phase 1B, `sort_order`).
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Ngày cuối cùng user nên chi tiêu để "kịp" kỳ sao kê hiện tại.
     *
     * CHỈ LÀ REMINDER. StatementPeriodService KHÔNG BAO GIỜ dùng hàm này để quyết
     * định kỳ sao kê — ngân hàng có thể ghi nhận (posted) khác ngày user mua.
     */
    public function spendingDeadlineFor(CarbonInterface $periodEnd): ?CarbonInterface
    {
        $day = $this->spending_deadline_day;

        if ($day === null) {
            return null;
        }

        $clamped = min($day, $periodEnd->copy()->daysInMonth);

        return $periodEnd->copy()->day($clamped);
    }

    /**
     * Cảnh báo nhắc nhở cho UI. Trả về null khi không cần nhắc.
     */
    public function spendingDeadlineWarning(CarbonInterface $today, CarbonInterface $periodEnd): ?string
    {
        $deadline = $this->spendingDeadlineFor($periodEnd);

        if ($deadline === null) {
            return null;
        }

        if ($today->greaterThan($deadline)) {
            return 'Bạn nên chi tiêu trước ngày '.$deadline->format('d/m')
                .' để tăng khả năng giao dịch được ghi nhận trong kỳ sao kê hiện tại.';
        }

        if ($today->equalTo($deadline)) {
            return 'Hôm nay là ngày '.$deadline->format('d/m')
                .' — hãy hoàn tất giao dịch sớm để giao dịch được ghi nhận trong kỳ sao kê hiện tại.';
        }

        return 'Bạn còn '.$today->diffInDays($deadline).' ngày nữa đến ngày '.$deadline->format('d/m')
            .' — hãy hoàn tất giao dịch sớm để giao dịch được ghi nhận trong kỳ sao kê hiện tại.';
    }
}
