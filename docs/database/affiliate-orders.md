# Affiliate Orders Database Design

## Table: `affiliate_order_items`

Lưu chi tiết từng item trong đơn hàng affiliate từ Shopee (và các nền tảng sau này).

---

## 1. Giải thích toàn bộ field

### 1.1. Fields từ Shopee (47 fields)

| # | Column | Type | Nullable | Gốc từ Shopee | Ý nghĩa |
|---|--------|------|----------|---------------|---------|
| 1 | `order_id` | string(50) | NO | ID đơn hàng | Mã đơn hàng Shopee, VD: `260701J1915H6N` |
| 2 | `order_status` | string(50) | NO | Trạng thái đặt hàng | VD: `Đang chờ xử lý`, `Đã hoàn thành` |
| 3 | `checkout_id` | string(30) | NO | Checkout id | Mã checkout (số, nhưng lưu string để tránh overflow) |
| 4 | `ordered_at` | datetime | YES | Thời Gian Đặt Hàng | Thời điểm user đặt hàng |
| 5 | `completed_at` | datetime | YES | Thời gian hoàn thành | Thời điểm đơn hoàn thành |
| 6 | `clicked_at` | datetime | YES | Thời gian Click | Thời điểm user click affiliate link |
| 7 | `shop_name` | string(200) | NO | Tên Shop | Tên shop bán hàng |
| 8 | `shop_id` | bigInteger | NO | Shop id | ID của shop |
| 9 | `shop_type` | string(50) | YES | Loại Shop | VD: `Shopee Mall(Non-CB)`, `Preferred(Non-CB)` |
| 10 | `item_id` | bigInteger | NO | Item id | ID sản phẩm |
| 11 | `item_name` | string(500) | NO | Tên Item | Tên sản phẩm |
| 12 | `model_id` | bigInteger | NO | ID Model | ID model/SKU |
| 13 | `product_type` | string(50) | YES | Loại sản phẩm | VD: `Normal Product` |
| 14 | `promotion_id` | string(50) | YES | Promotion id | ID khuyến mãi (nếu có) |
| 15 | `category_l1` | string(100) | YES | L1 Danh mục toàn cầu | Cấp danh mục 1, VD: `Sắc Đẹp` |
| 16 | `category_l2` | string(100) | YES | L2 Danh mục toàn cầu | Cấp danh mục 2, VD: `Chăm sóc da mặt` |
| 17 | `category_l3` | string(100) | YES | L3 Danh mục toàn cầu | Cấp danh mục 3, VD: `Sản phẩm dưỡng môi` |
| 18 | `item_price` | decimal(16,2) | NO | Giá(₫) | Đơn giá sản phẩm |
| 19 | `quantity` | integer | NO | Số lượng | Số lượng mua |
| 20 | `order_amount` | decimal(16,2) | NO | Giá trị đơn hàng (₫) | Tổng giá trị (giá × số lượng) |
| 21 | `refund_amount` | decimal(16,2) | NO | Số tiền hoàn trả (₫) | Số tiền hoàn lại (nếu có) |
| 22 | `commission_type` | string(30) | NO | Loại Hoa hồng | `Shopee Comm` hoặc `XTRA Comm` |
| 23 | `campaign_partner` | string(200) | YES | Đối tác chiến dịch | Tên đối tác chiến dịch (nếu có) |
| 24 | `shopee_commission_rate` | decimal(5,2) | NO | Tỷ lệ sản phẩm hoa hồng Shopee | Tỷ lệ % hoa hồng từ Shopee |
| 25 | `shopee_commission` | decimal(16,2) | NO | Hoa hồng Shopee trên sản phẩm(₫) | Số tiền hoa hồng từ Shopee |
| 26 | `seller_commission_rate` | decimal(5,2) | NO | Tỷ lệ sản phẩm hoa hồng người bán | Tỷ lệ % hoa hồng từ người bán |
| 27 | `xtra_commission` | decimal(16,2) | NO | Hoa hồng Xtra trên sản phẩm(₫) | Số tiền hoa hồng Xtra |
| 28 | `total_product_commission` | decimal(16,2) | NO | Tổng hoa hồng sản phẩm(₫) | Tổng hoa hồng trên sản phẩm |
| 29 | `order_commission_shopee` | decimal(16,2) | NO | Hoa hồng đơn hàng từ Shopee(₫) | Hoa hồng đơn hàng từ Shopee |
| 30 | `order_commission_seller` | decimal(16,2) | NO | Hoa hồng đơn hàng từ Người bán(₫) | Hoa hồng đơn hàng từ người bán |
| 31 | `total_order_commission` | decimal(16,2) | NO | Tổng hoa hồng đơn hàng(₫) | Tổng hoa hồng đơn hàng |
| 32 | `mcn_name` | string(200) | YES | Tên MNC đã liên kết | Tên MCN (Multi-Channel Network) |
| 33 | `mcn_contract_code` | string(100) | YES | Mã hợp đồng MCN | Mã hợp đồng MCN |
| 34 | `mcn_management_fee_rate` | decimal(5,2) | NO | Mức phí quản lý MCN | Tỷ lệ % phí quản lý MCN |
| 35 | `mcn_management_fee` | decimal(16,2) | NO | Phí quản lý MCN(₫) | Số tiền phí quản lý MCN |
| 36 | `agreed_commission_rate` | decimal(5,2) | NO | Mức hoa hồng tiếp thị liên kết theo thỏa thuận | Tỷ lệ % hoa hồng affiliate đã thỏa thuận |
| 37 | `net_commission` | decimal(16,2) | NO | Hoa hồng ròng tiếp thị liên kết(₫) | Hoa hồng ròng thực nhận |
| 38 | `affiliate_status` | string(50) | NO | Trạng thái sản phẩm liên kết | VD: `Đang chờ xử lý`, `Đã thanh toán` |
| 39 | `product_note` | text | YES | Ghi chú sản phẩm | Ghi chú chi tiết |
| 40 | `attribute_type` | string(100) | YES | Loại thuộc tính | VD: `Đơn hàng từ cùng một Shop` |
| 41 | `buyer_status` | string(50) | YES | Trạng thái người mua | VD: `Đã tồn tại` |
| 42 | `sub_id1` | string(100) | YES | Sub_id1 | Sub ID 1 — **chứa username** do extension ghi khi tạo link. Importer dùng giá trị này để lookup `users.username`. |
| 43 | `sub_id2` | string(100) | YES | Sub_id2 | Sub ID 2 |
| 44 | `sub_id3` | string(100) | YES | Sub_id3 | Sub ID 3 |
| 45 | `sub_id4` | string(100) | YES | Sub_id4 | Sub ID 4 |
| 46 | `sub_id5` | string(100) | YES | Sub_id5 | Sub ID 5 |
| 47 | `channel` | string(50) | YES | Kênh | VD: `Facebook`, `Websites` |

### 1.2. Fields do hệ thống thêm (12 fields)

| # | Column | Type | Mục đích |
|---|--------|------|----------|
| 48 | `platform` | string(30) | Tên nền tảng: `Shopee`, `Lazada`, `TikTok Shop` |
| 49 | `user_id` | foreignId (nullable) | Khóa ngoại tới `users.id`. Importer lookup: `users WHERE username = sub_id1` → ghi `user_id`. NULL nếu không tìm thấy user. |
| 50 | `username` | string(100) | Username lấy từ lookup `sub_id1` → `users.username`. Lưu trực tiếp để tránh JOIN. NULL nếu không tìm thấy. |
| 51 | `cashback_rate` | decimal(5,2) | Tỷ lệ cashback (50, 60, 70) tính theo business rule |
| 52 | `cashback_amount` | decimal(16,2) | Số tiền user thực nhận = `net_commission × cashback_rate / 100` |
| 53 | `import_batch` | string(20) | Mã lần import, VD: `20260703_020000` |
| 54 | `source_file` | string(255) | Tên file CSV gốc |
| 55 | `first_imported_at` | datetime | Lần đầu record xuất hiện trong hệ thống |
| 56 | `last_shopee_sync_at` | datetime | Lần cuối record được đồng bộ từ Shopee |
| 57 | `locked_at` | datetime | Thời điểm khóa (đơn hoàn thành, không sync lại) |
| 58 | `content_id` | string(64) nullable | TikTok content/link tracking ID (RioHub `content_id`) — "content nào sinh ra đơn". Không phải checkout id. |
| 59 | `last_tiktok_sync_at` | datetime | Lần cuối record được đồng bộ từ TikTok/RioHub (song song với `last_shopee_sync_at`, không tái sử dụng field tên Shopee) |

---

## 2. Field nào lấy từ Shopee

Tất cả 47 fields từ mục 1.1 đều lấy trực tiếp từ file export `AffiliateCommissionReport_*.csv` của Shopee.

Tên field được chuyển từ tiếng Việt sang snake_case tiếng Anh nhưng giữ nguyên ý nghĩa.

## 3. Field nào do hệ thống thêm

12 fields từ mục 1.2:
- `platform` — hỗ trợ đa nền tảng
- `user_id`, `username` — mapping user (xem mục 3a)
- `cashback_rate`, `cashback_amount` — tính toán cashback
- `import_batch`, `source_file` — tracking import
- `first_imported_at`, `last_shopee_sync_at`, `last_tiktok_sync_at`, `locked_at` — vòng đời record
- `content_id` — TikTok content/link tracking ID (provenance) |

### 3a. Luồng mapping user

```
Shopee Export (.csv)
    │
    │  Cột "Sub_id1"
    │  (do extension ghi username vào đây khi tạo link)
    ▼
sub_id1
    │
    │  Importer: SELECT id, username FROM users WHERE username = sub_id1
    ▼
┌─────────┬────────────────────────────────────┐
│ Tìm thấy │         Không tìm thấy             │
├─────────┼────────────────────────────────────┤
│ username = sub_id1  │  username = NULL       │
│ user_id = users.id  │  user_id = NULL        │
│                      │  (vẫn giữ nguyên       │
│                      │   sub_id1 để truy vết) │
└─────────┴────────────────────────────────────┘
```

**Quan trọng:**
- `sub_id1` là dữ liệu gốc từ Shopee — luôn được giữ nguyên.
- `username` và `user_id` là dẫn xuất — được gán sau khi lookup thành công.
- Nếu lookup thất bại, record vẫn được import đầy đủ với `username = NULL`, `user_id = NULL`.
- **Không bao giờ** xoá record chỉ vì không tìm thấy user.

## 4. Field phục vụ chức năng gì

### Wallet (Ví tiền)
- `cashback_amount` — số tiền user được nhận
- `cashback_rate` — tỷ lệ áp dụng
- `net_commission` — hoa hồng gốc để tính
- `affiliate_status` — kiểm tra trạng thái trước khi thanh toán
- `locked_at` — đảm bảo chỉ tính cashback cho đơn đã locked

### Tra cứu đơn hàng
- `order_id`, `checkout_id` — tra cứu theo mã đơn
- `item_id`, `item_name`, `model_id` — thông tin sản phẩm
- `shop_name`, `shop_id` — thông tin shop
- `ordered_at`, `completed_at` — thời gian
- `order_status`, `affiliate_status` — trạng thái
- `sub_id1` — tra theo mã affiliate (giá trị gốc từ Shopee)
- `username` — tra theo username (dẫn xuất từ sub_id1)
- `user_id` — tra theo user nội bộ (dẫn xuất từ sub_id1)

### Thống kê
- `platform` — lọc theo nền tảng
- `order_amount`, `item_price`, `quantity` — doanh số
- `net_commission`, `total_order_commission` — hoa hồng
- `ordered_at`, `completed_at` — thống kê theo thời gian
- `category_l1` ~ `category_l3` — thống kê theo ngành hàng
- `commission_type` — phân loại Shopee Comm vs XTRA Comm
- `channel` — thống kê theo kênh (Facebook, Websites)
- `import_batch` — thống kê theo đợt import
- `cashback_amount` — tổng cashback đã chi

### Import
- `import_batch` — xác định đợt import
- `source_file` — truy vết file gốc
- `first_imported_at` — phát hiện record mới
- `last_shopee_sync_at` — ghi nhận lần sync gần nhất
- `locked_at` — bỏ qua record đã locked khi import
- `sub_id1` → lookup `users.username` → ghi `username`, `user_id` (xem mục 3a)

## 5. Giải thích `first_imported_at`, `last_shopee_sync_at`, `locked_at`

### `first_imported_at`
- Gán khi importer **tạo record lần đầu** (record chưa tồn tại trong DB).
- Không thay đổi ở các lần import sau.
- Dùng để biết đơn hàng đã xuất hiện từ bao giờ.

### `last_shopee_sync_at`
- Cập nhật mỗi lần importer **update** record (dù thay đổi hay không).
- Nếu record đã `locked_at`, trường này không thay đổi.
- Dùng để biết lần cuối record được đồng bộ.

### `locked_at`
- Gán khi đơn hàng đạt trạng thái "hoàn thành" (theo tiêu chí business).
- Importer sẽ **bỏ qua** record có `locked_at` — không update, không ghi đè.
- Đảm bảo số liệu cashback không bị thay đổi sau khi đã chốt.
- Có thể unlock thủ công nếu cần sync lại.

### Vòng đời của một record

```
[Import lần 1] → first_imported_at = now()
                   last_shopee_sync_at = now()
                   locked_at = null

[Import lần 2..n] → last_shopee_sync_at = now()
                     (update các field khác nếu thay đổi)

[Đơn hoàn thành]  → last_shopee_sync_at = now()
                     locked_at = now()

[Import sau đó]   → Bỏ qua (locked_at != null)
```

## 6. Tại sao lưu đầy đủ dữ liệu Shopee thay vì chỉ lưu cột cần thiết?

1. **Truy vết** — Khi có tranh chấp hoặc sai lệch số liệu, cần đối chiếu từng field với file export gốc.
2. **Tránh import lại** — Nếu sau này cần field mới, không phải import lại lịch sử.
3. **Thống kê linh hoạt** — Category, channel, commission_type có thể dùng cho analytics sau này.
4. **Minimize business logic lúc import** — Importer chỉ đơn giản là copy từ CSV vào DB, không cần xử lý. Business logic (cashback, wallet) xử lý sau.
5. **Dữ liệu là gốc** — Giữ nguyên dữ liệu Shopee cho phép kiểm toán (audit) độc lập.

---

## 7. Business Rule (cho Importer / Wallet)

### Công thức tính Cashback

```text
commission_rate = (net_commission × 0.9) / order_amount

Nếu:
  commission_rate < 0.12   → cashback_rate = 50
  0.12 <= commission_rate <= 0.52 → cashback_rate = 60
  commission_rate > 0.52   → cashback_rate = 70

cashback_amount = net_commission × cashback_rate / 100
```

### Điều kiện Lock

Đơn được coi là "hoàn thành" và có thể lock khi:
- `affiliate_status` = `Đã thanh toán` (hoặc trạng thái tương đương)
- Hoặc `completed_at` đã qua và không có tranh chấp

Chi tiết implement ở Wallet Service — không nằm trong phạm vi database design này.

---

## 8. Indexes

| Index | Type | Columns | Mục đích |
|-------|------|---------|----------|
| `uk_order_item` | UNIQUE | `order_id`, `item_id` | Mỗi item trong đơn là duy nhất |
| `affiliate_order_items_user_id_index` | INDEX | `user_id` | Tra cứu đơn theo user |
| `affiliate_order_items_username_index` | INDEX | `username` | Tra cứu đơn theo username |
| `affiliate_order_items_platform_index` | INDEX | `platform` | Lọc theo nền tảng |
| `affiliate_order_items_order_status_index` | INDEX | `order_status` | Lọc theo trạng thái |
| `affiliate_order_items_ordered_at_index` | INDEX | `ordered_at` | Sắp xếp/thống kê theo thời gian |
| `affiliate_order_items_sub_id1_index` | INDEX | `sub_id1` | Tra cứu theo mã affiliate |
| `affiliate_order_items_import_batch_index` | INDEX | `import_batch` | Truy vết theo đợt import |
| `affiliate_order_items_locked_at_index` | INDEX | `locked_at` | Lọc record đã lock/chưa lock |

---

## 9. Tổng kết

- Tổng số field: **59**
- Field từ Shopee: **47**
- Field hệ thống thêm: **12**
- Khóa UNIQUE: **1** (`order_id` + `item_id`)
- Index thường: **8**
- Không có field nào bị lược bỏ — tất cả 47 field Shopee đều được giữ nguyên.
