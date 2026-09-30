# CREDIT CARD PHASE_1B FINAL REPORT

> Giai đoạn 1B — Domain services, policy engine, quản lý thẻ/danh mục/giao dịch và HTTP API.
> Ngày hoàn tất: 2026-09-30. Nhánh: `main`. Trạng thái schema: **migration đã chạy trên DB thật, chưa commit**.

---

## 1. Tổng quan

Phase 1A dựng nền tảng: database riêng `hoantien_creditcard`, 12 migration, model namespaced
`App\Models\CreditCard\*`, 6 trang UI, policy/cashback engine, import Excel và bộ test.

Phase 1B làm tròn phần **nghiệp vụ thật**: thay mô hình "chọn sản phẩm thẻ chuẩn hoá" bằng
**"chọn ngân hàng + tự đặt tên thẻ gợi nhớ"**, và đưa toàn bộ thao tác nghiệp vụ xuống tầng service
để controller chỉ còn mỏng.

Kết quả chính:

| Hạng mục | Kết quả |
|---|---|
| Service mới | 7 (`BankService`, `CategoryService`, `UserCardService`, `PolicyService`, `TierService`, `CategoryRuleService`, `CreditCardTransactionService`) |
| Policy mới | 1 (`CategoryPolicy`) |
| Controller + Form Request | 3 controller, 6 request |
| Route API mới | 13 (tổng module: 19) |
| Migration | 2 (`000011`, `000012`) |
| Test mới | 94 test / 247 assertion trong 4 file |
| Kết quả toàn suite | 1360 passed / 3 failed (3 lỗi baseline có sẵn) / 5508 assertions |

---

## 2. Mục tiêu và phạm vi

### 2.1 Mục tiêu đạt được

1. Bỏ phụ thuộc `credit_card_products` trong luồng tạo thẻ; dữ liệu ngân hàng về đúng bản chất
   (ngân hàng phát hành nhiều dòng thẻ, user tự đặt tên).
2. Chuẩn hoá danh mục ngân hàng về **43 bản ghi canonical** từ 44 dòng nghiệp vụ.
3. Có service cho mọi thao tác nghiệp vụ: thẻ, danh mục, giao dịch, policy, bậc, rule.
4. Ownership xuyên suốt `User → Card → Transaction / Category / Policy / Template`.
5. HTTP API JSON mỏng, không nhập tay cashback.
6. Test tự động phủ domain + HTTP + authorization.

### 2.2 Ngoài phạm vi (cố ý không làm)

Dashboard nâng cao, biểu đồ, Open Banking / API ngân hàng, OCR, mobile app, notification, AI,
và **không** mở UI nâng cao. Không viết API riêng cho Policy/Tier/Rule/Template ở giai đoạn này —
nghiệp vụ đó đã đủ qua service, UI cấu hình chi tiết để giai đoạn sau.

---

## 3. Kiến trúc tổng thể

```
routes/web.php  (middleware: web, auth)
   │
   ├── 6 trang Phase 1A ──────────────► CreditCardController (Blade)
   │
   └── 13 route JSON /thetindung/api ──► 3 controller mỏng
                                          │  1. FormRequest: validate + payload()
                                          │  2. Policy: quyết định 403
                                          │  3. Service: nghiệp vụ + kiểm lại ownership
                                          ▼
                       ┌──────────── tầng service ────────────┐
                       │ UserCardService   CategoryService     │
                       │ BankService       CreditCardTransactionService
                       │ PolicyService ── TierService          │
                       │              └─ CategoryRuleService   │
                       │ PolicyCloneService (deep clone)       │
                       │ CashbackRecordService (recalc/snapshot)│
                       └──────────────┬───────────────────────┘
                                      ▼
                     connection `creditcard` (hoantien_creditcard)
```

Nguyên tắc bất di bất dịch của 1B: **controller không chứa nghiệp vụ**. Mọi quy tắc nằm ở service và
đều nhận `userId` từ server, không nhận `userId` từ client.

---

## 4. Quyết định tách database

Giữ nguyên quyết định Phase 1A, không thay đổi:

- DB chính `hoantienaff` — `users`, `link_requests`, affiliate/cashback cũ.
- DB riêng `hoantien_creditcard` — toàn bộ bảng `credit_card_*`.
- **Không có physical FK** từ bảng credit card sang `users`: hai DB độc lập, thêm FK sẽ làm mất
  khả năng backup/restore riêng và chặn migrate theo bước.
- Ownership được bảo vệ ở tầng ứng dụng (service + policy), đã có test cho cả chiều.

Hệ quả bắt buộc trong 1B: mọi truy vấn credit card phải đi qua model của connection `creditcard`.
Rủi ro thực tế đã xảy ra và đã sửa: `Rule::exists('credit_card_banks', ...)` (tên bảng thô) sẽ
validate trên **sai connection**; phải dùng `Rule::exists(Bank::class, 'id')`.

---

## 5. Schema và bảng

### 5.1 `000011_add_bank_to_user_cards_table`

`credit_card_user_cards` được thêm 5 cột:

| Cột | Kiểu | Ý nghĩa |
|---|---|---|
| `bank_id` | `unsignedBigInteger` NOT NULL, FK → `credit_card_banks`, `restrictOnDelete` | Ngân hàng phát hành |
| `opened_at` | `date` nullable | Ngày mở thẻ |
| `closed_at` | `date` nullable | Ngày đóng thẻ (thẻ đóng = có `closed_at`) |
| `sort_order` | `unsignedInteger` default 0 | Thứ tự hiển thị |
| `note` | `text` nullable | Ghi chú |

Kèm index `(bank_id, status)`.

**Không drop `product_id`.** Cột chuyển thành nullable và được đánh dấu deprecated; bảng
`credit_card_products` giữ nguyên. Lý do: schema chưa commit, giữ cột nullable là phương án ít
rủi ro nhất (không mất dữ liệu, rollback đơn giản), và giai đoạn sau còn muốn cân nhắc bỏ hẳn
Product catalog. Migration có backfill `bank_id` từ `product_id` cho dữ liệu Phase 1A nếu có.

### 5.2 `000012_add_aliases_to_credit_card_banks_table`

Thêm `aliases` kiểu `json` (MySQL) / `text` (SQLite test) — cả hai cast `'array'` được nên model
không cần biết đang chạy engine nào.

**Vấn đề gốc:** danh sách ngân hàng có 44 dòng nghiệp vụ nhưng chứa một cặp trùng năng lực:
`#23 "Sài Gòn (SCB)"` và `#33 "Sacombank (STB)"` — cùng một pháp nhân. Seed theo từng dòng sẽ tạo hai
bản ghi cho một ngân hàng ⇒ user thấy trùng, cashback template cấu hình nhầm, báo cáo "44 banks" sai.

Cách sửa: 44 dòng → **43 bank canonical**; `stb` là slug canonical của Sacombank, `scb` nằm trong
`aliases = ["scb"]`.

Vì sao JSON chứ không phải bảng `credit_card_bank_aliases`: alias chỉ đọc kèm bank, không có vòng đời
riêng, chỉ vài chục bản ghi — bảng riêng tạo thêm một nơi phải đồng bộ FK/unique mà không đổi được gì.
Không index cột JSON (không index trực tiếp được ở MySQL 5.7; tra cứu chỉ đi qua 43 dòng trong PHP).

### 5.3 Trạng thái DB sau khi chạy

```
php artisan credit-card:migrate --fresh --seed --force   → 12 migrations OK
credit_card_banks          43
credit_card_categories     19 (system, active)
credit_card_products        0
credit_card_user_cards      0
```

---

## 6. Mô hình dữ liệu

### 6.1 Bank

`Bank` là master data **chỉ đọc** từ phía ứng dụng (seeder quản lý). Mã nhận diện canonical là
`slug` (unique, lowercase). `matchesCode()` chấp nhận cả slug và alias;
`Bank::resolveByCode()` quét 43 dòng trả về bank đúng, `activeOnly` mặc định `true`.

Phân giải mã cũ vẫn tra đúng ngân hàng, nên dữ liệu Excel cũ ghi `SCB` không bị mất.

### 6.2 Category

Hai loại trong cùng bảng, phân biệt bằng `scope`:

- `SYSTEM` — 19 danh mục tham chiếu, **read-only** với user.
- `USER` — danh mục riêng của user, `owner_user_id` bắt buộc.

`scopeSelectableBy($userId)` là scope dùng chung mọi nơi: system đang bật + danh mục riêng đang bật
của chính user đó. Mọi nơi chọn danh mục đều đi qua scope này để không tồn tại khả năng gắn rule
vào danh mục bị ẩn.

Quyết định giữ riêng Shopee/Tiki/Lazada/TikTok (mỗi sàn một mục, vì cashback khác nhau) và **không**
gộp chung một mục "Sàn thương mại điện tử" trong seeder — mục chung chỉ tồn tại làm danh mục dự phòng
cho giao dịch không xác định được sàn cụ thể.

### 6.3 UserCard

`UserCard = bank_id + name`. Không có `product_id` trong luồng mới.

- Chỉ lưu `card_number_last4`; **không** lưu PAN / CVV / ngày hết hạn.
- Thẻ đóng = `status = inactive` **và** `closed_at` khác null. Không xoá dòng thẻ.
- `deactivate` chuyển `status = inactive` + ghi `closed_at`; `reactivate` xoá `closed_at` và trả về
  thẻ về trạng thái dùng được (kèm tái-gắn policy/period theo luồng service).

### 6.4 Transaction

Nguồn dữ liệu: `source` (`manual` | `import`). `amount` là số tiền gốc; hoàn tiền là số âm được
chấp nhận, số 0 bị từ chối. Cashback **không** phải input của user — nó là cột snapshot do
`CashbackRecordService` ghi.

---

## 7. Phân quyền

### 7.1 Nguyên tắc

1. **Policy quyết định 403.** Controller tra cứu model *không lọc owner* rồi gọi `authorize()`; service
   vẫn kiểm lại ownership (defense in depth). Cách này trả 403 đúng ngữ nghĩa thay vì 500/422.
2. **Ownership suy ra từ server.** Service nhận `userId` lấy từ `$request->user()`; các API template
   không nhận `userId` từ caller mà suy ra từ `$userCard->user_id` — thứ caller không thể khai sai.
3. Policy tự khám phá theo quy ước Laravel (`UserCardPolicy` → `TransactionPolicy`), không cần
   đăng ký thủ công.

### 7.2 Ma trận quyền

| Tài nguyên | index | create | update | delete |
|---|---|---|---|---|
| `UserCard` | Chỉ của mình | ✓ | Chủ thẻ | Chủ thẻ (đóng thẻ, không xoá cứng) |
| `Category` | system + của mình | ✓ (service ép scope USER) | Chủ danh mục; system read-only | Chủ danh mục |
| `Transaction` | Chỉ của mình | ✓ (thẻ của mình) | Chủ giao dịch; kỳ finalized bị chặn | Chủ giao dịch (soft delete) |
| `PolicyVersion` / `PolicyTemplate` | Chủ (qua thẻ) | ✓ | version append-only | template của chính mình |

### 7.3 Bảo vệ dữ liệu lịch sử

- Danh mục **đã được dùng** chỉ được đổi `is_active`; cấu hình cashback của kỳ cũ không được đổi nghĩa.
- Danh mục đã dùng không xoá cứng → chuyển `is_active = false`, response báo `deleted: false`.
- Bậc còn rule thì không xoá được (bắt xoá/gỡ rule trước) — tránh âm thầm mất cấu hình.
- Giao dịch trong kỳ đã finalize: chặn sửa và xoá ở policy lẫn service.

---

## 8. Kỹ thuật

### 8.1 Mười service mới và trách nhiệm

| Service | Trách nhiệm | Ghi chú |
|---|---|---|
| `BankService` | Đọc bank, chỉ cho gán bank đang active, `resolveByCode` | Không ghi dữ liệu bank |
| `CategoryService` | CRUD danh mục, ownership, slug unique, xoá/ẩn theo mức sử dụng | `import` alias của `Builder` đã sửa |
| `UserCardService` | CRUD/lifecycle thẻ, validate, ownership, sắp xếp, tổng hạn mức | Không ghi `product_id` |
| `PolicyService` | 5 đường vào: from-scratch, clone system template, clone user template, tạo version N+1, lưu thành template | Facade mỏng, clone do `PolicyCloneService` |
| `TierService` | CRUD bậc, bất biến version locked/superseded, kiểm khoảng | `assertNoOverlappingBands` |
| `CategoryRuleService` | CRUD/clone rule, chặn danh mục không dùng được, % hợp lệ | |
| `CreditCardTransactionService` | CRUD giao dịch, resolve kỳ, tính lại cashback RETROACTIVE, chặn kỳ finalized | |

Không tạo `PolicyTemplateService` riêng: vòng đời template đã được `PolicyService` (điểm vào) và
`PolicyCloneService` (deep clone) chia sẻ tròn vai — tách thêm chỉ tạo một tầng trung gian.

### 8.2 Rủi ro kỹ thuật đã gặp và cách xử lý

| Vấn đề | Nguyên nhân | Cách sửa |
|---|---|---|
| Validate sai connection | `Rule::exists` dùng tên bảng thô | Truyền **model class** để Laravel lấy connection của model |
| Quan hệ lồng sâu bị chặn | `Transaction` không có quan hệ trực tiếp tới `User` | Thêm policy dựa trên quan hệ đi xa thay vì `Gate::before` |
| Encoding hỏng file PHP | `Set-Content` PowerShell ghi lại UTF-8 không BOM | Viết lại file bằng UTF-8, kiểm tra không còn U+FFFD |
| Hai bản ghi một ngân hàng | Seed theo từng dòng nghiệp vụ | Gộp về canonical + `aliases` |

### 8.3 Ánh xạ exception domain → HTTP

`bootstrap/app.php` đăng ký handler **giới hạn theo route `credit-cards.api.*`**:

| Exception domain | HTTP | Ví dụ |
|---|---|---|
| `InvalidArgumentException` | 422 | Thẻ/danh mục không hợp lệ hoặc không thuộc về bạn |
| `LogicException` | 409 | Kỳ đã chốt, thẻ đã đóng, version đã superseded |

Không áp dụng toàn cục: hai exception này được dùng ở nhiều module khác (ShopeeFood, TikTok) và không
được đổi hành vi. Trước 1B chúng biến thành HTTP 500 — sai và làm lộ chi tiết nội bộ.

---

## 9. Policy engine

### 9.1 Versioning append-only

Không có `update()` sửa thẳng business rule của version đang active. Mọi thay đổi tạo version mới;
version cũ đóng `effective_to` và chuyển `superseded`. Nhờ vậy giao dịch kỳ cũ vẫn resolve đúng
version cũ về sau.

Ngoại lệ duy nhất: sửa **metadata** của version chưa dùng cho kỳ nào (`rename()`).

### 9.2 Năm đường vào

```
createFromScratch()      user tự dựng
cloneSystemTemplate()    chọn template hệ thống
cloneUserTemplate()      chỉ template của chính user sở hữu thẻ
createVersion()          sửa cấu hình ⇒ version N+1
saveAsUserTemplate()     lưu cấu hình hiện tại thành template riêng
```

### 9.3 Bất biến được củng cố trong 1B

- **Khoảng bậc không chồng lấn** và **không đảo ngược**: trước 1B, `TierService::create` và
  `createFromScratch` insert thẳng, bỏ qua kiểm tra; chỉ `update` mới kiểm. Hệ quả là policy dựng
  bằng hai đường khác nhau cho ra hai bộ luật khác nhau — và khoảng chồng lấn khiến `TierResolverService`
  phải đoán, tức cashback của user đổi theo thứ tự id, không theo cấu hình.
  Nay `PolicyService::insertTiers` ủy quyền `TierService` + `CategoryRuleService` để **mọi đường
  vào dùng chung một bộ kiểm tra**.
- **Template phải đúng scope**: `cloneSystemTemplate()` nay chặn template USER. Trước đó hàm này gọi
  thẳng `cloneTemplate()` (cố ý không kiểm scope vì dùng chung cho cả hai đường vào) nên kẻ xâm nhậm
  chỉ cần gọi đúng tên hàm "hệ thống" là đọc được template riêng của người khác, né được kiểm tra
  sở hữu của đường kia.

### 9.4 Deep clone

`Policy → Version → Tiers → Category Rules` được clone sâu. Template hệ thống và template user không
dùng chung child records: sửa một bản không đụng bản kia.

---

## 10. Cashback engine

### 10.1 Nguyên tắc

- `CashbackCalculator` thuộc Credit Card là hàm **thuần**: cùng đầu vào ⇒ cùng đầu ra, không query DB,
  không đọc thời gian hệ thống. Đây là lý do có thể test bằng case cố định.
- Cashback **luôn** do hệ thống tính. Form Request không có `cashback_amount`/`cashback_percent`;
  request chỉ whitelist field hợp lệ, field lạ bị bỏ qua. Cột cashback trong Excel cũng bị từ chối.

### 10.2 Thứ tự áp cap (đã giữ nguyên, có test)

```
cap từng giao dịch  →  cap theo danh mục trong kỳ  →  cap tổng trong kỳ
```

Sai thứ tự sẽ cho ra kết quả khác, nên thứ tự này được khẳng định bằng test chứ không chỉ bằng comment.

### 10.3 Tính lại RETROACTIVE

Sửa/nghỉ kỳ ⇒ tính lại cashback các giao dịch kỳ đó; kỳ cũ giữ nguyên kết quả đã chốt
(snapshot/audit được giữ). Kỳ đã finalize là bất biến.

### 10.4 Phạm vi

Chỉ chế độ **RETROACTIVE**. Không có trường, input hay luồng progressive trong 1B.

---

## 11. Import Excel

Không thay đổi cơ chế import của Phase 1A, nhưng các bất biến được giữ và có test:

- Ánh xạ header cố định; cột chứa cashback bị **từ chối** (chống nhập tay).
- Validate từng dòng, ghi lỗi theo dòng thay vì dừng cả file.
- Ownership và phạm vi danh mục được kiểm khi import.
- Idempotent: chạy lại file đã nạp không tạo bản ghi trùng.
- `TransactionImportService` và các DTO liên quan được cập nhật cho schema `bank_id`.

---

## 12. Controller và UI

### 12.1 Ba controller mỏng

| Controller | Endpoint | Ghi chú |
|---|---|---|
| `UserCardController` | index, store, update, destroy, reorder | Không lưu PAN; `status` đi qua luồng deactivate/reactivate riêng |
| `CategoryController` | index, store, update, destroy | Danh mục hệ thống read-only; xoá đã dùng ⇒ ẩn |
| `TransactionController` | index, store, update, destroy | Kỳ resolve tự động; kỳ finalized bị chặn |

Mỗi action gọi đúng một service. Không có transaction DB mở trực tiếp trong controller.

### 12.2 Sáu Form Request

`StoreUserCardRequest`, `UpdateUserCardRequest`, `StoreCategoryRequest`, `UpdateCategoryRequest`,
`StoreTransactionRequest`, `UpdateTransactionRequest`.

Mỗi request có `payload()` trả về đúng tập field hợp lệ — đây là ranh giới chống mass-assignment và
chống nhập tay cashback.

Validate theo phạm vi owner (không chỉ kiểm id tồn tại):

- `user_card_id` → `Rule::exists(UserCard::class, 'id')->where('user_id', $userId)`.
- `category_id` → closure gọi `Category::scopeSelectableBy($userId)`.

Thẻ không tồn tại và thẻ của người khác trả **cùng một lỗi** ⇒ không lộ thông tin tồn tại.

Lưu ý phân tách trách nhiệm: thẻ **đã đóng** vẫn qua được validate (id hợp lệ, thuộc về user) và bị
service chặn bằng 409 — đúng ngữ nghĩa "xung đột trạng thái", không phải lỗi field.

### 12.3 UI

6 trang Phase 1A giữ nguyên, cập nhật hiển thị theo mô hình bank + tên thẻ. Không thêm dashboard
nâng cao.

---

## 13. Seeder và dữ liệu tham chiếu

`CreditCardSeeder` dùng `updateOrCreate` theo khoá tự nhiên (`slug`) nên chạy lại nhiều lần vẫn
idempotent, không nhân bản.

- **43 bank canonical**, mỗi dòng có `slug` + `name` + `short_name` (+ `aliases` khi có).
  Sacombank: `slug = stb`, `aliases = ["scb"]`.
- Các cặp gần giống giữ là **hai bank riêng**, không gộp: `mb` / `mbv`, `acb`, `vietinbank`,
  `vpb` / `pgb`.
- **19 danh mục hệ thống**; Shopee/Tiki/Lazada/TikTok tách riêng, cộng mục dự phòng
  "Sàn thương mại điện tử".
- `credit_card_products` **không** seed: bảng còn để tương thích nhưng không còn dữ liệu.

---

## 14. Lệnh vận hành

```bash
# Migrate + seed DB riêng của module (destructive, chỉ dùng cho môi trường dev)
php artisan credit-card:migrate --fresh --seed --force

# Route
php artisan route:list --path=thetindung

# Test
php artisan test tests\Feature\CreditCard tests\Feature\CreditCardModuleTest.php
php artisan test

# Format
.\vendor\bin\pint app\Services\CreditCard app\Http\Controllers\CreditCard \
    app\Http\Requests\CreditCard app\Policies\CreditCard tests\Feature\CreditCard
```

---

## 15. Kiểm thử

### 15.1 File mới

| File | Test | Assertion | Phủ |
|---|---|---|---|
| `CardManagementServicesTest` | 42 | 92 | Bank, danh mục, thẻ, policy, template, bậc, rule |
| `CreditCardTransactionServiceTest` | 16 | 30 | Vòng đời giao dịch, kỳ, tính lại, finalized |
| `Phase1bHttpTest` | 20 | 73 | Auth, ownership, CRUD API, chặn nhập cashback tay |
| `CreditCardAuthorizationTest` (mở rộng) | 16 | 52 | Policy, gồm danh mục hệ thống/riêng |

### 15.2 Kết quả

| Phạm vi | Kết quả |
|---|---|
| Credit Card tập trung | **199 passed / 1356 assertions** |
| Toàn bộ suite | **1360 passed / 3 failed / 5508 assertions** |

Ba lỗi fail là **baseline có sẵn từ trước 1B**, không liên quan module Thẻ tín dụng, và đã được xác
nhận là không đổi:

1. `Tests\Feature\Auth\RegistrationTest > new users can register`
2. `Tests\Feature\Services\ShopeeFood\ShopeeFoodOrderSyncServiceTest > cookie guard fails fast without http`
3. `Tests\Feature\TikTokSyncPhase3Test > preexisting credited order is not credited again by admin`

Số test tăng từ 1279 (baseline) lên 1360: **+81 test, +223 assertion**, không có regression.

---

## 16. Bug phát hiện và sửa

| # | Bug | Mức độ | Sửa |
|---|---|---|---|
| 1 | 8/20 test HTTP fail vì domain exception thành HTTP **500** lộ chi tiết nội bộ | Cao | Ánh xạ exception → 422/409 trong `bootstrap/app.php`, giới hạn theo route `credit-cards.api.*` |
| 2 | Controller gọi service lọc owner **trước** khi authorize ⇒ truy cập chéo user ra 500 thay vì 403 | Cao | Tra cứu model không lọc owner → `authorize()` trả 403 → service kiểm lại |
| 3 | Lỗi truy cập chéo không gắn vào field ⇒ frontend không biết hiển thị lỗi ở đâu | TB | Validate theo phạm vi owner trong Form Request |
| 4 | `cloneSystemTemplate()` không kiểm scope ⇒ đọc được template riêng của user khác | Cao (bảo mật) | Chặn template USER tại đường vào này |
| 5 | `TierService::create` và `createFromScratch` bỏ qua kiểm khoảng ⇒ hai đường vào cho hai bộ luật | Cao | `insertTiers` ủy quyền `TierService`/`CategoryRuleService` |
| 6 | `Rule::exists` dùng tên bảng thô ⇒ validate trên sai connection | TB | Truyền model class |
| 7 | Test Phase 1A đếm cứng 6 route ⇒ đỏ sau khi thêm 13 API route | Thấp | So tập tên route đã chốt thay vì đếm số |
| 8 | `Set-Content` PowerShell làm hỏng UTF-8 của `PolicyService.php` | TB | Viết lại UTF-8, kiểm tra U+FFFD |

---

## 17. Quyết định nghiệp vụ đã chốt

1. **Thẻ = ngân hàng + tên tự nhập**, không chọn sản phẩm chuẩn hoá. Bỏ ma sát nhập liệu.
2. **43 bank canonical**, `stb` là mã chính thức, `scb` là alias.
3. `mb`/`mbv`, `vpb`/`pgb`, `acb`, `vietinbank` là các pháp nhân/mã khác nhau ⇒ tách bản ghi.
4. **19 danh mục hệ thống**; Shopee/Tiki/Lazada/TikTok tách riêng, không gộp chung.
5. Danh mục hệ thống **read-only**; danh mục riêng thuộc owner.
6. Danh mục đã dùng: chỉ đổi `is_active`, không sửa cấu hình, không xoá cứng.
7. **Chỉ RETROACTIVE.** Không progressive.
8. **Cashback không bao giờ nhập tay** — kể cả qua Excel.
9. Thứ tự cap: giao dịch → danh mục/kỳ → tổng/kỳ.
10. Kỳ finalize là bất biến; sửa cấu hình sinh version mới (append-only).
11. `product_id` nullable + deprecated, chưa drop.
12. Ownership suy ra từ server; API template không nhận `userId` từ caller.
13. Chi tiết policy engine chỉ qua service, chưa mở HTTP API ở 1B.

---

## 18. Rủi ro và giới hạn

| Rủi ro | Mức | Giảm thiểu hiện tại |
|---|---|---|
| API policy/tier/rule chưa có HTTP | TB | Đủ qua service; UI cấu hình để giai đoạn sau |
| Chưa rate limit trên 13 route API | TB | Cần thêm `throttle` khi mở rộng |
| Hai DB độc lập ⇒ không có FK bảo vệ dữ liệu | TB | Đã có test ownership ở tầng ứng dụng |
| `credit_card_products` còn tồn tại nhưng rỗng | Thấp | Đã đánh dấu deprecated; quyết định drop để giai đoạn sau |
| Module lớn, service tăng nhanh | TB | Facade mỏng; logic clone chỉ có một nơi |
| Import Excel giữ nguyên giới hạn cũ | TB | Có test; cần cân nhắc job nền nếu file lớn |

---

## 19. Điểm còn nối kỹ thuật

- HTTP API cho Policy/Tier/Rule/Template.
- Rate limit + audit log cho API.
- Quyết định cuối về `credit_card_products` (drop hay giữ).
- Bảng điều khiển/báo cáo nâng cao.
- Job nền cho import và tính lại số lượng lớn.
- Tách `UserCardController` mỗi action nhiều nhánh về resource/FormRequest chuyên biệt.

---

## 20. Bảo mật và an toàn dữ liệu

| Hạng mục | Thực hiện |
|---|---|
| PAN / CVV / hạn thẻ | **Không lưu**; chỉ `card_number_last4` |
| Xác thực | Toàn bộ 19 route trong middleware `auth` |
| CSRF | API dùng session ⇒ giữ nguyên CSRF của nhóm `web` |
| Mass assignment | Form Request whitelist field |
| IDOR | Policy 403 + service kiểm lại ownership |
| Rò rỉ thông tin tồn tại | Thẻ/danh mục không hợp lệ và thuộc người khác trả cùng thông báo |
| Rò rỉ chi tiết nội bộ | Domain exception → 422/409 thay vì 500 |
| Lội lộ tiền qua cấu hình sai | Chặn khoảng bậc chồng lấn/đảo ngược, chặn danh mục không dùng được |
| Xoá nhầm dữ liệu lịch sử | Thẻ đóng không xoá; giao dịch soft delete; bậc còn rule không xoá được |
| SQL injection | Toàn bộ qua Eloquent/Query Builder + rule có tham số |

---

## 21. Rollback

1. **Hạ tầng dữ liệu (migration):** `down()` của `000011` bỏ `bank_id` và trả `product_id` về NOT NULL —
   chỉ khả thi nếu mọi dòng đã có `product_id`. Vì 1B không ghi `product_id`, cần xuất dữ liệu
   `bank_id`/`name` sang CSV trước khi hạ. `down()` của `000012` chỉ bỏ cột `aliases`.
2. **Ứng dụng:** xoá 3 controller + 6 request + 7 service + 1 policy, và khối route
   `credit-cards.api.*`. Trang Phase 1A cần sửa lại vì hiển thị đã chuyển sang bank.
3. **Test:** 4 file test mới xoá cùng nhóm.
4. **Không thể rollback bằng `git revert` một lệnh** vì schema chưa commit; phải theo thứ tự
   dữ liệu → migration → code.

---

## 22. Kết luận và bước tiếp theo

Phase 1B hoàn tất: mô hình nghiệp vụ đã đúng bản chất (thẻ = ngân hàng + tên), 43 bank canonical,
19 danh mục hệ thống, toàn bộ thao tác nghiệp vụ nằm trong service, ownership xuyên suốt có test,
và 13 HTTP API mỏng không nhận tay cashback. Toàn suite xanh ngoại trừ 3 lỗi baseline có sẵn.

Trong quá trình làm đã phát hiện và vá 3 lỗi nghiêm trọng về bảo mật/tính đúng đắn
(exception lộ nội bộ, đường vào bypass ownership của template, hai bộ luật khác nhau tùy đường vào).

Bước tiếp theo, theo thứ tự nên làm:

1. Xuất `bank_id`/`name` hiện tại ra CSV để có đường quay lui trước khi chốt schema.
2. Mở HTTP API cho policy/tier/rule + rate limit.
3. Quyết định `credit_card_products`.
4. Báo cáo/bảng điều khiển.
5. Xử lý 3 test baseline đang đỏ (ngoài phạm vi module, nhưng nên dọn để CI xanh).
