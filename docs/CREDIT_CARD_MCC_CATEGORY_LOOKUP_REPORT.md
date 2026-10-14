# BÁO CÁO — TAB "TRA CỨU DANH MỤC" (tra cứu thẻ hoàn tiền theo nhóm ngành)

> **Ngày:** 14/10/2026 · **DB:** `hoantien_creditcard` (connection `creditcard`) · **HEAD:** `e377a24`
> **Trạng thái:** đã triển khai, test xanh. **CHƯA commit/push.**
> **Phạm vi:** chỉ đọc dữ liệu hồ sơ MCC đã nhập; **không** đổi schema, **không** tạo mã MCC,
> **không** tạo bảng danh mục, **không** liên kết `category_name` với `credit_card_categories`.

Mục tiêu: thêm tab thứ ba **"Tra cứu danh mục"** cho màn `/thetindung/tra-cuu`. Người dùng chọn
một **danh mục ngành** (đã chuẩn hoá) và xem **mọi thẻ có hoàn tiền** cho danh mục đó, dựng hoàn
toàn từ các dòng `included` của hồ sơ MCC.

---

## 1. Danh mục thực tế phát hiện trong dữ liệu

Quét **tất cả dòng `included`** của mọi hồ sơ MCC (`credit_card_policy_mcc_rows`): có **52 tên
danh mục gốc** (khác nhau, sau khi đếm số dòng):

| # | Tên gốc | Số dòng |
|---:|---|---:|
| 1 | Bảo hiểm | 6 |
| 2 | Bảo hiểm trực tuyến | 1 |
| 3 | Bệnh viện | 1 |
| 4 | Chi tiêu online | 1 |
| 5 | Chi tiêu quốc tế | 1 |
| 6 | Chi tiêu trực tuyến khác | 1 |
| 7 | Chi tiêu trực tuyến nước ngoài | 1 |
| 8 | Chi tiêu tại TBCNT nước ngoài | 1 |
| 9 | Chăm sóc sắc đẹp | 1 |
| 10 | Chăm sóc sức khỏe | 1 |
| 11 | Di chuyển | 2 |
| 12 | Di chuyển và đặt đồ ăn | 1 |
| 13 | Du lịch | 3 |
| 14 | Điện máy | 1 |
| 15 | Giao dịch ăn uống, siêu thị (Co.op, BigC, Lottemart, Winmart), Grab/Be | 1 |
| 16 | Giao dịch chi tiêu còn lại | 1 |
| 17 | Giao dịch có lưu thông tin Thẻ (card-on-file) | 1 |
| 18 | Giao dịch trực tuyến | 1 |
| 19 | Giao dịch tại Shopee, ShopeeFood | 1 |
| 20 | Giáo dục | 7 |
| 21 | Giải trí | 2 |
| 22 | Giải trí và Fitness | 1 |
| 23 | Grab & di chuyển mặt đất | 1 |
| 24 | Gói đăng ký định kỳ (recurring subscriptions) | 1 |
| 25 | Hoàn tiền cố định | 1 |
| 26 | Hoàn tiền linh hoạt (chọn 2 trong 9) | 1 |
| 27 | Khác | 1 |
| 28 | Mua sắm | 2 |
| 29 | Mua sắm (trung tâm thương mại & chuỗi thời trang) | 1 |
| 30 | Mua sắm online - Shopee, ShopeeFood | 1 |
| 31 | Mua sắm online – Shopee | 1 |
| 32 | Mua sắm online – TikTok Shop | 1 |
| 33 | Mua vé xem phim online | 1 |
| 34 | Nhà sách | 1 |
| 35 | Nhóm Du lịch, khách sạn, hàng không | 1 |
| 36 | Nhóm Đồ điện tử, điện máy | 1 |
| 37 | Nhóm Giáo dục | 1 |
| 38 | Nhóm ưu đãi được chọn (mặc định: Ăn uống) | 1 |
| 39 | Siêu thị | 3 |
| 40 | Siêu thị, cửa hàng bách hóa và dịch vụ taxi, limousine | 1 |
| 41 | Siêu thị, cửa hàng thực phẩm | 1 |
| 42 | Siêu thị, cửa hàng tiện lợi | 3 |
| 43 | Sản phẩm số | 1 |
| 44 | Sức khỏe | 1 |
| 45 | Thương mại điện tử | 2 |
| 46 | Tất cả giao dịch còn lại | 1 |
| 47 | Vé máy bay, khách sạn, đại lý du lịch (MCC 3000–3836) | 1 |
| 48 | Y tế | 3 |
| 49 | Y tế sức khỏe | 1 |
| 50 | Ăn uống | 5 |
| 51 | Ẩm thực | 1 |
| 52 | Ứng dụng di chuyển | 2 |

> Lưu ý: một vài dòng mô tả **gộp nhiều ngành trong một tên** (ví dụ dòng của VPBank Super
> Shopee #29: "Giao dịch ăn uống, siêu thị (…), Grab/Be") nên được ánh xạ vào **nhiều** nhóm.

---

## 2. Chuẩn hoá & gom nhóm

Cấu hình tại `config/credit_card_mcc_categories.php`:
- `groups`: **19 nhóm chuẩn** (khoá => nhãn tiếng Việt). **Thứ tự mảng = thứ tự dropdown.**
- `aliases`: tên gốc (chuẩn hoá lúc chạy) => **một khoá** hoặc **mảng khoá**.
- Tên **không** map ⇒ giữ **riêng** một lựa chọn `raw:<tên-chuẩn-hoá>` (không tự gộp).

Chuẩn hoá khoá (`normalizeCategoryKey`): `Str::lower` → `Str::ascii` (bỏ dấu) → thay ký tự không
phải `[a-z0-9]` bằng dấu cách → gộp khoảng trắng. **Không sửa tên gốc lưu trong hồ sơ.**

### 2.1 Ánh xạ tên gốc → nhóm chuẩn

| Nhóm chuẩn | Tên gốc ánh xạ |
|---|---|
| **food** (Ăn uống) | Ẩm thực, Ăn uống, *(một phần)* Di chuyển và đặt đồ ăn, Giao dịch tại Shopee, ShopeeFood, "Giao dịch ăn uống, siêu thị (…), Grab/Be", "Mua sắm online - Shopee, ShopeeFood" |
| **supermarket** (Siêu thị) | Siêu thị, Siêu thị cửa hàng tiện lợi, Siêu thị cửa hàng thực phẩm, *(một phần)* "Siêu thị… taxi, limousine", "Giao dịch ăn uống, siêu thị (…), Grab/Be" |
| **health** (Y tế và sức khỏe) | Y tế, Y tế sức khỏe, Sức khỏe, Bệnh viện, Chăm sóc sức khỏe |
| **beauty** (Chăm sóc sắc đẹp) | Chăm sóc sắc đẹp |
| **education** (Giáo dục) | Giáo dục, Nhóm Giáo dục |
| **insurance** (Bảo hiểm) | Bảo hiểm, Bảo hiểm trực tuyến |
| **travel** (Du lịch và lưu trú) | Du lịch, Nhóm Du lịch khách sạn hàng không, "Vé máy bay, khách sạn, đại lý du lịch (MCC 3000–3836)" |
| **transport** (Di chuyển) | Di chuyển, Ứng dụng di chuyển, Grab & di chuyển mặt đất, *(một phần)* Di chuyển và đặt đồ ăn, "Siêu thị… taxi, limousine", "Giao dịch ăn uống, siêu thị (…), Grab/Be" |
| **shopping** (Mua sắm) | Mua sắm, "Mua sắm (trung tâm thương mại & chuỗi thời trang)", *(một phần)* Mua sắm online – Shopee, Mua sắm online – TikTok Shop, "Mua sắm online - Shopee, ShopeeFood" |
| **ecommerce** (Thương mại điện tử) | Thương mại điện tử, *(một phần)* Mua sắm online – Shopee, – TikTok Shop, "Mua sắm online - Shopee, ShopeeFood", Giao dịch tại Shopee, ShopeeFood |
| **online** (Chi tiêu trực tuyến) | Chi tiêu online, Giao dịch trực tuyến, Chi tiêu trực tuyến khác |
| **entertainment** (Giải trí) | Giải trí, *(một phần)* Giải trí và Fitness, Mua vé xem phim online |
| **cinema** (Mua vé xem phim) | *(một phần)* Mua vé xem phim online |
| **fitness** (Thể thao & Fitness) | *(một phần)* Giải trí và Fitness |
| **electronics** (Điện máy) | Điện máy, Nhóm Đồ điện tử, điện máy |
| **digital_products** (Sản phẩm số) | Sản phẩm số |
| **books** (Nhà sách) | Nhà sách |
| **subscriptions** (Gói đăng ký định kỳ) | Gói đăng ký định kỳ (recurring subscriptions) |
| **other** (Giao dịch còn lại) | Khác, Giao dịch chi tiêu còn lại, Tất cả giao dịch còn lại |

### 2.2 Dòng mô tả gộp nhiều nhóm (ánh xạ mảng)

| Tên gốc | → Nhóm |
|---|---|
| Di chuyển và đặt đồ ăn | transport, food |
| "Siêu thị, cửa hàng bách hóa và dịch vụ taxi, limousine" | supermarket, transport |
| "Mua sắm online – Shopee" | shopping, ecommerce |
| "Mua sắm online – TikTok Shop" | shopping, ecommerce |
| "Mua sắm online - Shopee, ShopeeFood" | shopping, ecommerce, food |
| Giải trí và Fitness | entertainment, fitness |
| Mua vé xem phim online | cinema, entertainment |
| "Giao dịch tại Shopee, ShopeeFood" | ecommerce, food |
| "Giao dịch ăn uống, siêu thị (Co.op, BigC, Lottemart, Winmart), Grab/Be" | food, supermarket, transport |

### 2.3 Giữ RIÊNG (không gom) — 7 danh mục `raw:`

Các tên mang tính **điều kiện kênh / cơ chế hoàn tiền**, KHÔNG phải ngành hàng ⇒ cố ý để riêng,
không gán bừa vào nhóm ngành nào:

1. Chi tiêu quốc tế
2. Chi tiêu trực tuyến nước ngoài
3. Chi tiêu tại TBCNT nước ngoài
4. Giao dịch có lưu thông tin Thẻ (card-on-file)
5. Hoàn tiền cố định
6. Hoàn tiền linh hoạt (chọn 2 trong 9)
7. Nhóm ưu đãi được chọn (mặc định: Ăn uống)

### 2.4 Tổng số lựa chọn dropdown

**52** tên gốc → **26 lựa chọn**: **19** nhóm chuẩn + **7** `raw:`. (Tất cả 19 nhóm đều có ít
nhất một tên gốc; `cinema`/`fitness` xuất hiện nhờ các dòng gộp.)

---

## 3. Cách một thẻ "khớp" với một danh mục

`CardPolicyLookupService::lookupCategory($categoryKey)` (chỉ đọc):

1. Với mỗi hồ sơ MCC, lấy các dòng **`included`** có `category_name` ánh xạ tới `$categoryKey`.
   Dòng **`excluded` KHÔNG tạo kết quả** (loại trừ không chứng minh có hoàn tiền).
2. Hồ sơ có **nhiều dòng cùng danh mục** ⇒ gộp về **MỘT kết quả** (không nhân bản thẻ):
   - Hợp nhất **mã MCC** (khử trùng lặp, sắp tăng dần).
   - Hợp nhất **bậc/tỷ lệ**, khử trùng lặp theo khoá
     `cashback_percent|rate_label|min_total_spend|max_total_spend|cap_amount|note`
     ⇒ **giữ mọi khác biệt giữa các hạng**, không gán một mức chung.
3. **Chương trình dùng chung nhiều hạng** (`product_name`) gộp tiếp một kết quả qua
   `mergeProductMatches()` (như Phần G của workflow).
4. Kết quả trả về: `relation='group'`, `match_type='category'`, `cashback` (đọc từ bảng
   `credit_card_policy_mcc_row_benefits`), `documents` tải được, `mcc_codes`, `descriptive_note`,
   và `category.matched_names` (tên gốc đã khớp — phục vụ truy vết).

Sắp xếp kết quả theo `policy_name`. Rỗng ⇒ `found=false` (UI hiện trạng thái rỗng).

---

## 4. Xử lý trường hợp đặc biệt

- **Hồ sơ không có mã MCC (mô tả)** — VPBank Super Shopee #29: dòng vẫn khớp theo **tên mô tả**;
  khi kết quả `mcc_codes` rỗng, gắn `descriptive_note` = ghi chú hồ sơ (hoặc nhãn "Quyền lợi theo
  mô tả của ngân hàng — chưa có danh sách mã MCC cụ thể.") để không hiểu nhầm là đã kiểm tra đủ mã.
- **Dòng điều kiện KÊNH** (VIB One Card #26: "Chi tiêu trực tuyến nước ngoài", "Chi tiêu trực
  tuyến khác", "Giao dịch chi tiêu còn lại", "card-on-file"): chỉ xuất hiện khi người dùng chọn
  đúng **danh mục tương ứng**; không tự gán sang danh mục ngành khác.
- **Hồ sơ dùng chung nhiều hạng** (VIB Max #14, VIB One #2): gộp **một** kết quả, **giữ đủ bậc** —
  không dùng bậc của hạng khác, không nhân bản thẻ.
- **Nhiều dòng cùng danh mục trong một hồ sơ**: khử trùng lặp bậc, giữ các tỷ lệ/điều kiện khác
  nhau.

---

## 5. Giao diện

- Tab thứ ba trong `resources/views/credit-card/lookup.blade.php` (`data-testid="cc-lookup-tab-category"`),
  dropdown tìm-kiếm `components/credit-card/searchable-select.blade.php`, nút **Tra cứu**,
  ghi chú **"Chỉ tra cứu — không thay đổi dữ liệu."**; kết quả tiêu đề **"Các thẻ hoàn tiền (n)"**.
- Khối kết quả chia sẻ partial `resources/views/credit-card/partials/mcc-match.blade.php`
  (tham số `$matches`, `$codesLabel`); tab MCC dùng nhãn "Mã trong nhóm", tab danh mục dùng
  "Mã MCC liên quan".
- Tiền/trần dùng `ccMoneyVnd`; trường trống = "Chưa cập nhật" (không `0 đ`).

---

## 6. File thay đổi

| File | Thay đổi |
|---|---|
| `config/credit_card_mcc_categories.php` | **MỚI** — `groups` (19) + `aliases`. |
| `app/Services/CreditCard/CardPolicyLookupService.php` | Thêm `categoryOptions()`, `lookupCategory()` và helpers (`categoryProfileMatch`, `categoryAliasMap`, `categoryGroupLabels`, `categoryKeysFor`, `normalizeCategoryKey`). |
| `app/Http/Controllers/CreditCard/CreditCardLookupController.php` | Thêm action `category()`; `index()` truyền `categoryOptions`. |
| `routes/web.php` | Thêm `GET /thetindung/tra-cuu/danh-muc` — name `credit-cards.lookup.category`. |
| `resources/views/credit-card/lookup.blade.php` | Thêm tab thứ ba + section + JS (`runCategory()`); tab MCC chuyển sang `@include` partial. |
| `resources/views/credit-card/partials/mcc-match.blade.php` | **MỚI** — khối kết quả dùng chung. |
| `tests/Feature/CreditCard/CardLookupCategoryTest.php` | **MỚI** — 17 test. |
| `docs/CREDIT_CARD_MCC_WORKFLOW.md` | Thêm **PHẦN J** + cập nhật PHỤ LỤC. |

---

## 7. Kết quả kiểm thử

- `php artisan test tests/Feature/CreditCard/ tests/Feature/Admin/CreditCardMccManagementTest.php`
  → **1483 passed (10944 assertions)**.
- Riêng `CardLookupCategoryTest` → **17 passed**.
- `vendor\bin\pint` trên các file PHP đã sửa → **passed**.
- `git diff --check` → **sạch**.

---

## 8. Trường hợp chưa phân loại được / lưu ý

- **7 danh mục `raw:`** (mục 2.3) cố ý chưa gom — vì bản chất là điều kiện kênh/cơ chế, không
  phải ngành hàng. Nếu vận hành muốn gom, chỉ cần bổ sung `aliases`, không sửa code.
- Tên gốc **lệch dấu gạch** ("Mua sắm online – Shopee" dùng en-dash `–`) — sau chuẩn hoá
  (`Str::ascii` + thay ký tự không phải `[a-z0-9]`) nên khớp đúng nhóm; **không** cần thêm biến thể.
- Dòng **"Bảo hiểm trực tuyến"** được xếp vào **insurance** (không phải `online`) vì ngành là bảo
  hiểm; "trực tuyến" chỉ là kênh.
- Chưa đưa **phí thường niên** vào khối tab danh mục (giữ nguyên phạm vi hiện tại; tab MCC đã có).
- **Không** tạo/không đổi mã MCC; **không** dùng `credit_card_categories`; **không** chạm engine
  cashback.

---

## 9. Việc CHƯA làm

- **Chưa commit/push/deploy.**
- **Không** động tới các workstream song song (Portfolio Optimizer, Backup/OrderSync) — các file
  `app/Services/CreditCard/Optimizer/*` đang `M`/`??` là của workstream khác.
