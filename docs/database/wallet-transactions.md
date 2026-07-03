# Wallet Transactions (Sổ cái)

## 1. Tổng quan

`wallet_transactions` là bảng sổ cái (ledger) ghi lại MỘT giao dịch làm thay đổi số dư ví.

**Nguyên tắc thiết kế:**

1. **Không sửa transaction cũ.** Nếu sai → tạo transaction mới (điều chỉnh).
2. **Không lưu số âm.** `amount` luôn dương. Chiều tăng/giảm xác định bằng `direction`.
3. **`credit`** = tiền vào ví. **`debit`** = tiền ra ví.
4. **Số dư thực tế** luôn được tính bằng `SUM(credit) − SUM(debit)` trên các transaction có `status = completed`.
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
| `type` | enum | ✓ | `cashback`, `withdraw`, `refund`, `adjustment` |
| `direction` | enum(credit, debit) | ✓ | `credit` = tiền vào, `debit` = tiền ra |
| `amount` | decimal(16,2) | ✓ | Số tiền. **Luôn dương.** |
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

## 3. QUAN TRỌNG — Chỉ `status = completed` mới tham gia tính số dư

```
CHỈ
status = completed
mới được tham gia tính số dư.

pending
→ Không ảnh hưởng số dư.

cancelled
→ Không ảnh hưởng số dư.

failed
→ Không ảnh hưởng số dư.
```

Công thức tính số dư:

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
- **status ban đầu:** pending (chờ admin duyệt).
- **Khi duyệt:** status → completed, ghi `completed_at`.
- **Khi từ chối:** status → cancelled (không ảnh hưởng số dư).
- **reference_type:** `withdraw_request`

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
User gửi yêu cầu rút tiền
               ↓
Tạo withdraw_request (bảng riêng)
               ↓
Tạo wallet_transaction:
  type           = 'withdraw'
  direction      = 'debit'
  amount         = số tiền rút
  status         = 'pending'
  completed_at   = NULL
               ↓
Admin duyệt:
  ⇒ status       = 'completed'
  ⇒ completed_at  = now()
  ⇒ processed_by  = admin_id

Admin từ chối:
  ⇒ status       = 'cancelled'
  ⇒ completed_at  = NULL
  ⇒ processed_by  = admin_id
  ⇒ note         ghi rõ lý do
```

---

## 7. Luồng adjustment

```
Admin muốn cộng/trừ tiền cho User
               ↓
Tạo wallet_transaction:
  type           = 'adjustment'
  direction      = 'credit' (cộng) | 'debit' (trừ)
  amount         = số tiền
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

1. **Không UPDATE `amount`, `direction`, `type`, `running_no`** sau khi tạo.
2. **Chỉ được UPDATE:** `status`, `note`, `completed_at`, `processed_by`.
3. Nếu tạo sai → tạo transaction mới để điều chỉnh (không xóa/sửa transaction cũ).
4. Mọi thay đổi số dư đều phải thông qua transaction — **không update số dư trực tiếp trên users table**.

---

## 10. Giải thích các cột quan trọng

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

### platform
Lưu nền tảng gốc của giao dịch. Ví dụ: Shopee, Lazada, TikTok.
Với withdraw/adjustment không thuộc nền tảng nào → lấy platform gần nhất hoặc để trống.

### reference_type & reference_id
- `reference_type`: tên bảng nội bộ (snake_case).
- `reference_id`: ID của record trong bảng đó.
- Ví dụ: `reference_type = 'affiliate_order_item'`, `reference_id = 125` → link đến `affiliate_order_items.id = 125`.
- Không lưu order_id Shopee trực tiếp (tránh dependency vào bên ngoài). Thay vào đó lưu trong `metadata`.

### completed_at
- Thời điểm transaction thực sự hoàn tất.
- Khác `created_at`: transaction withdraw được tạo với status `pending`, mãi sau admin duyệt mới có `completed_at`.
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

## 11. Cách tính số dư

**Số dư thực tế (dùng trong UI):**
```sql
SELECT
  COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0)
  - COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END), 0)
  AS balance
FROM wallet_transactions
WHERE user_id = ?
  AND status = 'completed';
```

**Tổng thu nhập (total earned):**
```sql
SELECT COALESCE(SUM(amount), 0)
FROM wallet_transactions
WHERE user_id = ?
  AND direction = 'credit'
  AND status = 'completed';
```

**Tổng đã rút (total withdrawn):**
```sql
SELECT COALESCE(SUM(amount), 0)
FROM wallet_transactions
WHERE user_id = ?
  AND direction = 'debit'
  AND status = 'completed';
```

---

## 12. Ví dụ thực tế

### User A (username: nguyenvana) có 3 giao dịch:

| running_no | user_id | username | type | direction | amount | reference_type | description | status | completed_at |
|---|---|---|---|---|---|---|---|---|---|
| WT202607030001 | 1 | nguyenvana | cashback | credit | 18500 | affiliate_order_item | Cashback đơn hàng 260701HVK8YT00 | completed | 2026-07-03 10:00 |
| WT202607030002 | 1 | nguyenvana | cashback | credit | 22000 | affiliate_order_item | Cashback đơn hàng 260702ABC12345 | completed | 2026-07-03 11:30 |
| WT202607030003 | 1 | nguyenvana | withdraw | debit | 40000 | withdraw_request | Rút tiền BIDV | completed | 2026-07-04 09:15 |

**Số dư hiện tại:** 18500 + 22000 - 40000 = 500đ

### User B (username: tranthib) có 1 giao dịch pending:

| running_no | user_id | username | type | direction | amount | reference_type | description | status | completed_at |
|---|---|---|---|---|---|---|---|---|---|
| WT202607030004 | 2 | tranthib | withdraw | debit | 100000 | withdraw_request | Rút tiền Techcombank | pending | NULL |

**Số dư hiện tại:** 0đ (giao dịch pending không được tính)

---

## 13. Indexes

| Index | Cột | Mục đích |
|---|---|---|
| PRIMARY | id | PK |
| unique | running_no | Tránh trùng mã giao dịch |
| index | username | Search theo username |
| index | platform | Lọc theo nền tảng |
| index | type | Lọc theo loại giao dịch |
| index | status | Lọc theo trạng thái |
| index | reference_id | Tra cứu reference đơn lẻ |
| index | created_at | Sort theo thời gian |
| index | (reference_type, reference_id) | Tra cứu reference, tránh duplicate |
