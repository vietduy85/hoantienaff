<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * UserCard — thẻ thật của user. Mỗi thẻ có policy và cấu hình kỳ sao kê RIÊNG.
 *
 * BẢO MẬT: chỉ lưu `card_number_last4`. KHÔNG lưu số thẻ đầy đủ, CVV, ngày hết hạn.
 * `$hidden` khai `card_number_last4` để không serialize nhầm ra ngoài.
 *
 * `spending_deadline_day` là metadata CHỈ ĐỂ NHẮC NHỞ, không quyết định kỳ sao kê.
 *
 * @property int $id
 * @property int $user_id
 * @property int $product_id
 * @property string $name
 * @property string|null $card_number_last4
 * @property string|null $credit_limit
 * @property int $statement_day
 * @property int $payment_due_day
 * @property int|null $spending_deadline_day
 * @property string $statement_date_basis
 * @property int|null $current_policy_id
 * @property string $status
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
        'product_id',
        'name',
        'card_number_last4',
        'credit_limit',
        'statement_day',
        'payment_due_day',
        'spending_deadline_day',
        'statement_date_basis',
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
            'product_id' => 'integer',
            'credit_limit' => 'decimal:2',
            'statement_day' => 'integer',
            'payment_due_day' => 'integer',
            'spending_deadline_day' => 'integer',
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
        $user = (new \App\Models\User())
            ->setConnection($this->mainDatabaseConnectionName());

        return $this->newBelongsTo(
            $user->newQuery(),
            $this,
            'user_id',
            $user->getKeyName(),
            'user'
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Ngân hàng phát hành sản phẩm của thẻ này.
     *
     * KHÔNG dùng `->through('product')`: `BelongsTo` không hỗ trợ `through`, và
     * nếu viết tay sẽ sinh SQL sai (`banks.id = user_cards.product_id`).
     * Gọi 2 tầng bằng `hasOneThrough` để Laravel sinh đúng JOIN trong CÙNG
     * connection `creditcard` (cả 3 bảng đều nằm ở DB Thẻ tín dụng).
     *
     * SQL sinh ra (đã test — xem CreditCardModuleTest::credit_card_models_and_relationships_work):
     *   select banks.* from credit_card_banks
     *   inner join credit_card_products on credit_card_products.bank_id = credit_card_banks.id
     *   where credit_card_products.id = <user_cards.product_id>
     *
     * Ánh xạ tham số của `hasOneThrough($related, $through, $firstKey, $secondKey, $localKey, $secondLocalKey)`:
     *   $firstKey       = cột trên bảng TRUNG GIAN dùng cho WHERE  → products.id
     *   $secondKey      = cột trên bảng ĐÍCH dùng cho JOIN         → banks.id
     *   $localKey       = cột trên user_cards lấy giá trị WHERE    → product_id
     *   $secondLocalKey = cột trên bảng TRUNG GIAN dùng cho JOIN   → products.bank_id
     */
    public function bank(): HasOneThrough
    {
        return $this->hasOneThrough(
            Bank::class,
            Product::class,
            'id',
            'id',
            'product_id',
            'bank_id'
        );
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
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
    public function spendingDeadlineFor(\Carbon\CarbonInterface $periodEnd): ?\Carbon\CarbonInterface
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
    public function spendingDeadlineWarning(\Carbon\CarbonInterface $today, \Carbon\CarbonInterface $periodEnd): ?string
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
