# Withdraw Requests (Yêu cầu rút tiền)

## 1. Tổng quan

`withdraw_requests` là bảng ghi lại yêu cầu rút tiền của User.

Mỗi record đại diện cho MỘT lần User yêu cầu rút tiền từ ví về tài khoản ngân hàng.

**Nguyên tắc quan trọng:**

- **Không trừ tiền khi User gửi yêu cầu.** Chỉ tạo `wallet_transactions` (type=withdraw, direction=debit) khi `status = paid`.
- Yêu cầu có thể bị từ chối (rejected) hoặc hủy (cancelled) mà không ảnh hưởng số dư.
- Mỗi yêu cầu chỉ được paid **một lần duy nhất**.

---

## 2. Ý nghĩa từng cột

| Cột | Kiểu | Bắt buộc | Mô tả |
|---|---|---|---|
| `id` | bigint, PK | ✓ | ID nội bộ |
| `running_no` | string(30), unique | ✓ | Mã yêu cầu hiển thị. Định dạng: `WR + YYYYMMDD + 4 số thứ tự`. Ví dụ: `WR202607030001` |
| `user_id` | foreignId | ✓ | FK → users.id. Cascade on delete |
| `username` | string(100) | ✓ | Username snapshot tại thời điểm tạo yêu cầu |
| `platform` | string(30) | ✓ | Nền tảng: `Shopee`, `Lazada`, `TikTok` |
| `amount` | decimal(16,2) | ✓ | Số tiền yêu cầu rút |
| `bank_name` | string(100) | ✓ | Tên ngân hàng (VD: BIDV, Techcombank) |
| `bank_account` | string(50) | ✓ | Số tài khoản ngân hàng |
| `account_name` | string(150) | ✓ | Tên chủ tài khoản |
| `status` | enum | ✓ | `pending` — chờ duyệt, `approved` — đã duyệt, `paid` — đã thanh toán, `rejected` — từ chối |
| `processed_by_user_id` | foreignId, nullable | ✗ | Admin xử lý yêu cầu này |
| `processed_at` | datetime, nullable | ✗ | Thời điểm admin xử lý (duyệt/từ chối) |
| `note` | text, nullable | ✗ | Ghi chú nội bộ (VD: lý do từ chối) |
| `metadata` | json, nullable | ✗ | Dữ liệu mở rộng: `{"bank_code": "970418", "ip": "..."}` |
| `created_at` | datetime | ✓ | Thời gian tạo yêu cầu |
| `updated_at` | datetime | ✓ | Thời gian cập nhật gần nhất |

---

## 3. Nguồn thông tin ngân hàng

Thông tin ngân hàng (`bank_name`, `bank_account`, `account_name`) trong `withdraw_requests` được lấy từ **Profile của User** (`users` table), cụ thể:

| withdraw_requests | users table |
|---|---|
| `bank_name` | `users.bank_name` |
| `bank_account` | `users.bank_account_number` |
| `account_name` | `users.bank_account_name` |

**Luồng:**

1. User bấm "Rút tiền".
2. Hệ thống kiểm tra `users.bank_name`, `users.bank_account_number`, `users.bank_account_name`.
3. Nếu thiếu bất kỳ trường nào → hiển thị cảnh báo yêu cầu User cập nhật Profile, KHÔNG cho tạo yêu cầu.
4. Nếu đủ → tự động điền vào form rút tiền, User không phải nhập lại.

**Tại sao lưu snapshot?**
`withdraw_requests` lưu bản sao thông tin ngân hàng tại thời điểm tạo yêu cầu (`bank_name`, `bank_account`, `account_name`). Nếu User sau này đổi thông tin trong Profile, các yêu cầu cũ vẫn giữ nguyên dữ liệu gốc — phục vụ đối soát và truy vết lịch sử.

---

## 4. Luồng xử lý

### pending → approved

Trước khi tạo yêu cầu, kiểm tra Profile User có đủ thông tin ngân hàng không (xem mục 3).

```
User gửi yêu cầu rút tiền
          ↓
withdraw_requests.status = 'pending'
          ↓
Admin xem xét, đồng ý
          ↓
withdraw_requests.status = 'approved'
withdraw_requests.processed_by_user_id = admin_id
withdraw_requests.processed_at = now()
          ↓
(KHÔNG tạo wallet_transactions — chưa trừ tiền)
```

### pending → rejected

```
User gửi yêu cầu rút tiền
          ↓
withdraw_requests.status = 'pending'
          ↓
Admin từ chối
          ↓
withdraw_requests.status = 'rejected'
withdraw_requests.processed_by_user_id = admin_id
withdraw_requests.processed_at = now()
withdraw_requests.note = 'Lý do từ chối'
          ↓
(KHÔNG tạo wallet_transactions — không ảnh hưởng số dư)
```

### approved → paid

Chỉ chuyển từ `approved` → `paid`. **KHÔNG chuyển từ `pending` → `paid`.**

```
withdraw_requests.status = 'approved'
          ↓
Admin xác nhận đã chuyển tiền
          ↓
withdraw_requests.status = 'paid'
          ↓
Tạo wallet_transactions:
  running_no     = WT + YYYYMMDD + seq
  user_id        = withdraw_requests.user_id
  username       = withdraw_requests.username
  platform       = withdraw_requests.platform
  type           = 'withdraw'
  direction      = 'debit'
  amount         = withdraw_requests.amount
  reference_type = 'withdraw_request'
  reference_id   = withdraw_requests.id
  description    = 'Rút tiền ' + bank_name
  status         = 'completed'
  completed_at   = now()
  processed_by   = admin_id
  metadata       = {"bank": bank_name, "account_number": bank_account}
```

---

## 5. Quy tắc

1. **Không trừ tiền khi User gửi yêu cầu** — chỉ trừ khi `status = paid`.
2. **Không chuyển `pending` → `paid` trực tiếp.** Phải qua `approved` trước.
3. **Mỗi yêu cầu chỉ paid một lần.** Không thể paid lại.
4. **Không sửa `amount`** sau khi tạo. Nếu admin muốn thay đổi → từ chối yêu cầu cũ, user tạo yêu cầu mới.
5. Khi yêu cầu bị `rejected`, số dư User **không thay đổi**.
6. Khi yêu cầu được `paid`, một `wallet_transactions` type=withdraw được tạo — số dư giảm tương ứng.

---

## 6. Wallet Transactions liên quan

Khi `status = paid`, một record `wallet_transactions` được tạo:

| Trường | Giá trị |
|---|---|
| `type` | `withdraw` |
| `direction` | `debit` |
| `amount` | `withdraw_requests.amount` |
| `reference_type` | `withdraw_request` |
| `reference_id` | `withdraw_requests.id` |
| `description` | `Rút tiền {bank_name}` |

Có thể kiểm tra duplicate bằng cách query:

```sql
SELECT COUNT(*) FROM wallet_transactions
WHERE reference_type = 'withdraw_request'
  AND reference_id = ?
  AND type = 'withdraw'
  AND status = 'completed';
```

---

## 7. Indexes

| Index | Cột | Mục đích |
|---|---|---|
| PRIMARY | id | PK |
| unique | running_no | Tránh trùng mã yêu cầu |
| index | running_no | Tra cứu theo mã |
| index | user_id | Lọc yêu cầu của user |
| index | username | Search theo username |
| index | status | Lọc theo trạng thái |
| index | created_at | Sort theo thời gian |
