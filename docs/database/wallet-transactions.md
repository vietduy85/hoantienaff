# Wallet Transactions (Sổ cái)

## 1. Tổng quan

`wallet_transactions` là bảng sổ cái (ledger) ghi lại MỘT giao dịch làm thay đổi số dư ví.

**Nguyên tắc thiết kế:**

1. **Không sửa transaction cũ.** Nếu sai → tạo transaction mới (điều chỉnh).
2. **Không lưu số âm.** `amount` luôn dương. Chiều tăng/giảm xác định bằng `direction`.
3. **`credit`** = tiền vào ví. **`debit`** = tiền ra ví.
4. **Runtime balance** đọc từ `users.wallet_balance` (cache). **Ledger** (`wallet_transactions`) là Source of Truth — chỉ dùng để đối soát.
5. Mỗi record là bất biến (immutable) sau khi tạo.

---

## 2. Ý nghĩa từng cột

| Cột | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|
| `id` | bigint, PK | ✓ | ID nội bộ, không hiển thị cho User |
| `running_no` | string(30), unique | ✓ | Mã giao dịch hiển thị. Định dạng: `WT + YYYYMMDD + 4 số thứ tự`. Ví dụ: `WT202607030001` |
| `user_id` | foreignId | ✓ | FK → users.id. Cascade on delete |
| `username` | string(100) | ✓ | Username tại thời điểm tạo transaction. Lưu để search nhanh, đối soát |
| `platform` | string(30) | ✓ | Nền tảng: `Shopee`, `Lazada`, `TikTok` |
| `type` | enum | ✓ | `cashback`, `withdraw`, `promotion`, `bonus`, `referral`, `adjustment`, `refund` |
| `direction` | enum(credit, debit) | ✓ | `credit` = tiền vào, `debit` = tiền ra |
| `amount` | decimal(16,2) | ✓ | Số tiền. **Luôn dương.** |
| `balance_before` | decimal(16,2) | ✓ | Số dư ví trước giao dịch |
| `balance_after` | decimal(16,2) | ✓ | Số dư ví sau giao dịch |
| `reference_type` | string(50) | ✓ | Tên bảng tham chiếu: `affiliate_order_item`, `withdraw_request`, `manual` |
| `reference_id` | unsignedBigInt, nullable | ✗ | ID của record trong bảng tham chiếu. NULL nếu không có (VD: adjustment thủ công) |
| `description` | string(255) | ✓ | Mô tả giao dịch hiển thị cho User |
| `status` | enum | ✓ | `pending`, `completed`, `cancelled`, `failed` |
| `completed_at` | datetime, nullable | ✗ | Thời điểm transaction hoàn tất (khác `created_at` — transaction có thể tạo trước rồi duyệt sau) |
| `note` | text, nullable | ✗ | Ghi chú nội bộ (admin) |
| `processed_by` | foreignId, nullable | ✗ | NULL = system tự động tạo, có giá trị = admin xử lý |
| `metadata` | json, nullable | ✗ | Dữ liệu mở rộng (order_id Shopee, bank, account_number, …). Không cần thêm cột mới sau này |
| `created_at` | datetime | ✓ | Thời gian tạo |
| `updated_at` | datetime | ✓ | Thời gian sửa (chỉ sửa status/note, không sửa amount) |

---

## 3. QUAN TRỌNG — Source of Truth vs Runtime Balance Cache

### Source of Truth: `wallet_transactions`

Ledger là nguồn dữ liệu gốc. Mọi giao dịch đều được ghi bất biến vào đây.

Dùng để:

- Đối soát (reconciliation)
- Audit trail
- Hiển thị lịch sử giao dịch cho User
- Tính toán lại số dư khi cần (syncBalance)

### Runtime Balance Cache: `users.wallet_balance`

Khi runtime, **không** truy vấn ledger để tính balance.

Thay vào đó:

```
users.wallet_balance
```

là cache được cập nhật ngay sau mỗi giao dịch thành công.

### Cách hoạt động

```
1. Hệ thống đọc users.wallet_balance (cache) — nhanh, không cần SUM
2. Khi tạo giao dịch:
   a. Đọc users.wallet_balance
   b. Ghi transaction với balance_before = wallet_balance, balance_after ± amount
   c. Cập nhật users.wallet_balance = balance_after
3. Chỉ khi cần đối soát mới tính lại từ ledger bằng công thức:
   SUM(credit completed) − SUM(debit completed)
   và đối chiếu với users.wallet_balance
```

### Chỉ `status = completed` mới tham gia đối soát

```
CHỈ
status = completed
mới được tính khi đối soát.

pending
→ Không ảnh hưởng số dư.

cancelled
→ Không ảnh hưởng số dư.

failed
→ Không ảnh hưởng số dư.
```

Công thức đối soát:

```
SUM(amount WHERE direction = 'credit' AND status = 'completed')
−
SUM(amount WHERE direction = 'debit'  AND status = 'completed')
```

---

## 4. Ý nghĩa từng loại transaction

### cashback
- **Mô tả:** Tiền cashback từ đơn hàng affiliate hoàn thành.
- **direction:** credit
- **trigger:** Khi `affiliate_order_items.affiliate_status` chuyển từ "Đang chờ xử lý" → "Hoàn thành".
- **reference_type:** `affiliate_order_item`
- **reference_id:** `affiliate_order_items.id`

### withdraw
- **Mô tả:** User rút tiền.
- **direction:** debit
- **status ban đầu:** completed (tạo cùng lúc với paid).
- **reference_type:** `withdraw_request`

### promotion
- **Mô tả:** Tiền thưởng từ chương trình khuyến mãi.
- **direction:** credit
- **reference_type:** tuỳ theo nguồn.

### bonus
- **Mô tả:** Thưởng đặc biệt (KPI, sự kiện).
- **direction:** credit
- **reference_type:** `manual`

### referral
- **Mô tả:** Hoa hồng giới thiệu.
- **direction:** credit
- **reference_type:** tuỳ theo bảng tham chiếu sau.

### adjustment
- **Mô tả:** Admin điều chỉnh thủ công (cộng/trừ tiền).
- **direction:** credit (cộng) hoặc debit (trừ).
- **reference_type:** `manual`
- **reference_id:** NULL

### refund
- **Mô tả:** Hoàn tiền (khi đơn hàng bị hủy sau khi đã thanh toán cashback).
- **direction:** debit
- **reference_type:** `affiliate_order_item`

---

## 5. Luồng tạo transaction cashback

Kiểm tra trùng lặp bằng `reference_type` + `reference_id`:

```sql
SELECT COUNT(*) FROM wallet_transactions
WHERE reference_type = 'affiliate_order_item'
  AND reference_id = ?
  AND type = 'cashback'
  AND status = 'completed';
```

```
affiliate_order_items.affiliate_status = 'Hoàn thành'
               ↓
Kiểm tra: đã có wallet_transaction nào với
  reference_type = 'affiliate_order_item'
  reference_id   = affiliate_order_items.id
  type           = 'cashback'
  status         = 'completed'
chưa?
               ↓
Nếu chưa → tạo transaction mới:
  running_no     = WT + YYYYMMDD + seq
  user_id        = affiliate_order_items.user_id
  username       = users.username (tra cứu)
  platform       = affiliate_order_items.platform
  type           = 'cashback'
  direction      = 'credit'
  amount         = affiliate_order_items.cashback_amount
  balance_before = số dư hiện tại (từ WalletService)
  balance_after  = balance_before + amount
  reference_type = 'affiliate_order_item'
  reference_id   = affiliate_order_items.id
  description    = 'Cashback đơn hàng ' + order_id
  status         = 'completed'
  completed_at   = now()
  processed_by   = NULL (system)
  metadata       = {"order_id": "260701HVK8YT00"}
```

---

## 6. Luồng tạo transaction withdraw

```
withdraw_requests.status = 'paid'
               ↓
Tạo wallet_transaction:
  type           = 'withdraw'
  direction      = 'debit'
  amount         = withdraw_requests.amount
  balance_before = số dư hiện tại (từ WalletService)
  balance_after  = balance_before - amount
  reference_type = 'withdraw_request'
  reference_id   = withdraw_requests.id
  description    = 'Rút tiền ' + bank_name
  status         = 'completed'
  completed_at   = now()
  processed_by   = admin_id
  metadata       = {"bank": bank_name, "account_number": bank_account}
```

Không có trạng thái `pending` cho withdraw transaction. Transaction được tạo `completed` ngay khi admin đánh dấu `paid`.

---

## 7. Luồng adjustment

```
Admin muốn cộng/trừ tiền cho User
               ↓
Tạo wallet_transaction:
  type           = 'adjustment'
  direction      = 'credit' (cộng) | 'debit' (trừ)
  amount         = số tiền
  balance_before = số dư hiện tại (từ WalletService)
  balance_after  = balance_before ± amount
  status         = 'completed'
  completed_at   = now()
  reference_type = 'manual'
  reference_id   = NULL
  processed_by   = admin_id
```

---

## 8. Luồng refund

```
Đơn hàng đã thanh toán cashback bị hủy/hoàn trả
               ↓
Tạo wallet_transaction bù trừ:
  type           = 'refund'
  direction      = 'debit'
  amount         = cashback_amount đã nhận
  balance_before = số dư hiện tại (từ WalletService)
  balance_after  = balance_before - amount
  reference_type = 'affiliate_order_item'
  reference_id   = affiliate_order_items.id
  description    = 'Hoàn tiền đơn hàng ' + order_id + ' (đã hủy)'
  status         = 'completed'
  completed_at   = now()
  processed_by   = NULL (system)
  metadata       = {"order_id": "260701HVK8YT00"}
```

---

## 9. Quy tắc bất biến (Immutability)

1. **Không UPDATE `amount`, `direction`, `type`, `running_no`, `balance_before`, `balance_after`** sau khi tạo.
2. **Chỉ được UPDATE:** `status`, `note`, `completed_at`, `processed_by`.
3. Nếu tạo sai → tạo transaction mới để điều chỉnh (không xóa/sửa transaction cũ).
4. Mọi thay đổi số dư đều phải thông qua `WalletService` — **không update số dư trực tiếp trên users table**.

---

## 10. WalletService — Trung tâm xử lý số dư

### Nguyên tắc

1. **WalletService là nơi DUY NHẤT được phép ghi vào `wallet_transactions` và cập nhật `users.wallet_balance`.**
2. Controller, Command, Job, Seeder, Import **không được gọi `WalletTransaction::create()`** trực tiếp — tất cả đều gọi `WalletService`.
3. Controller **không được phép truy vấn ledger** (SUM, `WalletTransaction::query()`, `DB::table()`) để tính balance.
4. Controller chỉ gọi: `$walletService->balance($user)` hoặc `$walletService->availableBalance($user)`.
5. `WalletService` tự động đọc `users.wallet_balance`, tính `balance_before`/`balance_after`, ghi transaction, cập nhật cache.

### API

```php
class WalletService
{
    // Ghi cashback từ đơn hàng hoàn thành
    public function creditCashback(AffiliateOrderItem $item): WalletTransaction;

    // Ghi rút tiền (khi admin paid)
    public function debitWithdraw(WithdrawRequest $request, User $admin): WalletTransaction;

    // Admin cộng/trừ thủ công
    public function adjust(User $user, float $amount, string $direction, string $reason, ?User $admin): WalletTransaction;

    // Lấy số dư runtime từ cache (users.wallet_balance) — KHÔNG SUM ledger
    public function balance(User $user): float;

    // Lấy số dư khả dụng (balance − pending withdraw)
    public function availableBalance(User $user): float;

    // Đồng bộ users.wallet_balance từ ledger (chỉ dùng khi đối soát)
    public function syncBalance(User $user): float;

    // Kiểm tra đã ghi cashback cho item này chưa
    public function isCashbackCredited(AffiliateOrderItem $item): bool;
}
```

---

## 11. Giải thích các cột quan trọng

### running_no
Mã giao dịch hiển thị cho User. Định dạng:
```
WT + YYYYMMDD + 4 số thứ tự (0001-9999)
```
Ví dụ: `WT202607030001`, `WT202607030002`.

Cơ chế sinh:
- Reset số thứ tự mỗi ngày (bắt đầu từ 0001).
- Dùng database lock/unique constraint để tránh trùng.

### user_id & username
- `user_id`: FK để join, cascade khi user bị xóa.
- `username`: lưu snapshot tại thời điểm tạo. Mục đích:
  - Search giao dịch theo username không cần JOIN.
  - Đối soát khi username thay đổi.
  - Khi user bị xóa vẫn còn username để tra cứu.

### balance_before & balance_after
- Lưu số dư ví tại thời điểm giao dịch.
- `balance_after = balance_before ± amount` (cộng nếu credit, trừ nếu debit).
- Cho phép hiển thị lịch sử ví: "Số dư trước: X, Số dư sau: Y".
- Không sửa sau khi tạo.

### platform
Lưu nền tảng gốc của giao dịch. Ví dụ: Shopee, Lazada, TikTok.
Với withdraw/adjustment không thuộc nền tảng nào → lấy platform gần nhất hoặc để trống.

### reference_type & reference_id
- `reference_type`: tên bảng nội bộ (snake_case).
- `reference_id`: ID của record trong bảng đó.
- Ví dụ: `reference_type = 'affiliate_order_item'`, `reference_id = 125` → link đến `affiliate_order_items.id = 125`.
- Không lưu order_id Shopee trực tiếp (tránh dependency vào bên ngoài). Thay vào đó lưu trong `metadata`.
- Cặp `(reference_type, reference_id)` được dùng để kiểm tra trùng lặp — **không cần thêm flag `cashback_credited_at`**.

### completed_at
- Thời điểm transaction thực sự hoàn tất.
- Đa số transaction tạo với status `completed` → `completed_at = created_at`.
- NULL nếu transaction chưa completed.

### metadata
- JSON linh hoạt, chứa dữ liệu mở rộng không cần cột riêng.
- Ví dụ cashback: `{"order_id": "260701HVK8YT00", "platform": "Shopee"}`
- Ví dụ withdraw: `{"bank": "BIDV", "account_number": "1234567890", "account_name": "NGUYEN VAN A"}`
- Ví dụ adjustment: `{"reason": "Bù tiền do lỗi tính toán"}`

### processed_by
- **NULL** = giao dịch do hệ thống tự động tạo (cashback, refund).
- **Có giá trị** = giao dịch do admin xử lý (withdraw duyệt, adjustment).

---

## 12. Cách tính số dư

### Runtime (dùng trong UI mỗi ngày)

**Số dư hiện tại:**
```php
$walletService->balance($user);
// Đọc từ users.wallet_balance — 1 query, không SUM
```

**Số dư khả dụng (available):**
```php
$walletService->availableBalance($user);
// = users.wallet_balance − SUM(amount WHERE type='withdraw' AND status='pending')
//   trên withdraw_requests
```

**Tổng thu nhập (total earned):**
```php
// WalletService cung cấp nếu cần
$walletService->totalEarned($user);
// Hoặc nếu chưa có method, dùng cache column nếu thêm sau
```

**Tổng đã rút (total withdrawn):**
```php
// Tính từ withdraw_requests (WHERE status IN ('paid', 'approved'))
// Không cần SUM ledger cho việc này
```

### Đối soát (Reconciliation — chạy định kỳ hoặc khi có nghi vấn)

Dùng công thức SUM trên ledger để kiểm tra `users.wallet_balance` có chính xác không:

```sql
-- Đối soát: tính balance từ ledger
SELECT
  COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0)
  - COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END), 0)
  AS calculated_balance
FROM wallet_transactions
WHERE user_id = ?
  AND status = 'completed';
```

```php
// So sánh với cache
$calculated = /* query trên */;
$cached = $user->wallet_balance;
if ($calculated !== $cached) {
    // Báo động — sai lệch dữ liệu, cần syncBalance
    $walletService->syncBalance($user);
}
```

### Lịch sử giao dịch (Transaction History)

Khi User hoặc Admin xem lịch sử giao dịch, query `wallet_transactions` với phân trang:

```php
$user->walletTransactions()
    ->where('status', 'completed')
    ->orderBy('created_at', 'desc')
    ->paginate(20);
```

Đây là lần DUY NHẤT Controller được query `wallet_transactions` — để hiển thị danh sách, **không phải để tính số dư**.

---

## 13. Ví dụ thực tế

### User A (username: nguyenvana) có 3 giao dịch:

| running_no | user_id | type | direction | amount | balance_before | balance_after | reference_type | status |
|---|---|---|---|---|---|---|---|---|
| WT202607030001 | 1 | cashback | credit | 18500 | 0 | 18500 | affiliate_order_item | completed |
| WT202607030002 | 1 | cashback | credit | 22000 | 18500 | 40500 | affiliate_order_item | completed |
| WT202607030003 | 1 | withdraw | debit | 40000 | 40500 | 500 | withdraw_request | completed |

**Runtime balance (users.wallet_balance):** 500đ (được WalletService cập nhật sau transaction cuối)
**Đối soát (SUM ledger):** 18500 + 22000 - 40000 = 500đ — khớp.

### User B (username: tranthib) có 1 giao dịch pending:

| running_no | user_id | type | direction | amount | balance_before | balance_after | reference_type | status |
|---|---|---|---|---|---|---|---|---|
| WT202607030004 | 2 | withdraw | debit | 100000 | NULL | NULL | withdraw_request | pending |

**Runtime balance (users.wallet_balance):** 0đ (giao dịch pending chưa ảnh hưởng đến cache)
**Available balance:** 0 - 0 = 0đ

---

## 14. Indexes

| Index | Cột | Mục đích |
|---|---|---|
| PRIMARY | id | PK |
| unique | running_no | Tránh trùng mã giao dịch |
| index | username | Search theo username |
| index | platform | Lọc theo nền tảng |
| index | type | Lọc theo loại giao dịch |
| index | status | Lọc theo trạng thái |
| index | (user_id, status) | Tính số dư |
| index | (user_id, direction, status) | Tính tổng thu nhập / đã rút |
| index | reference_id | Tra cứu reference đơn lẻ |
| index | created_at | Sort theo thời gian |
| index | (reference_type, reference_id) | Tra cứu reference, tránh duplicate |

---

## 15. Model WalletTransaction

```php
class WalletTransaction extends Model
{
    protected $fillable = [
        'running_no',
        'user_id',
        'username',
        'platform',
        'type',
        'direction',
        'amount',
        'balance_before',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
        'status',
        'completed_at',
        'note',
        'processed_by',
        'metadata',
    ];

    public function user(): BelongsTo
    public function processor(): BelongsTo
}
```

---

## 16. Quan hệ User

```php
// THÊM vào User model
public function walletTransactions(): HasMany
{
    return $this->hasMany(WalletTransaction::class);
}

public function withdrawRequests(): HasMany
{
    return $this->hasMany(WithdrawRequest::class);
}

// @deprecated — chưa xoá, chỉ đánh dấu
public function transactions(): HasMany       // Transaction (cũ)
public function withdrawals(): HasMany       // Withdrawal (cũ)
public function processedWithdrawals(): HasMany // Withdrawal (cũ)
```

**User.wallet_balance** là Runtime Balance Cache. `WalletService` tự động cập nhật sau mỗi giao dịch. Controller đọc giá trị này — không query ledger.

**User.total_earned** và **User.total_withdrawn** không còn sử dụng — có thể tính từ ledger khi cần đối soát. Nếu cần hiển thị tổng thu nhập/đã rút, `WalletService` cung cấp method riêng.
