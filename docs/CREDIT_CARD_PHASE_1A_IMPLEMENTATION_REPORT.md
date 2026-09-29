# CREDIT CARD PHASE_1A IMPLEMENTATION REPORT

**Báo cáo triển khai module Thẻ tín dụng — Phase 1A**
Ngày lập: 29/09/2026 · Branch: `main` · HEAD: `0591755` · Trạng thái: **hoàn tất, chưa commit**

---

## 1. Tổng quan

Phase 1A dựng nền tảng cho module Thẻ tín dụng: database riêng, 10 bảng nghiệp vụ, model
và policy, engine tính cashback, kỳ sao kê, biên giới import Excel, và trang `/thetindung`
hiển thị dữ liệu thật. Toàn bộ thay đổi **chưa commit** theo yêu cầu.

Kết quả kiểm thử: **144 test Credit Card pass (1200 assertions)**; full suite
**1279 pass / 3 fail**, trong đó 3 lỗi có sẵn từ trước và không liên quan module.

## 2. Mục tiêu và phạm vi

| Trong phạm vi | Ngoài phạm vi (để phase sau) |
| --- | --- |
| Tách DB `hoantien_creditcard` | Gọi API ngân hàng (module cố ý **không** phụ thuộc ngân hàng) |
| 10 bảng + migration riêng | CRUD đầy đủ thẻ/giao dịch/policy |
| 11 model, 4 policy | Xác thực loại thẻ, hạn mức động, queue/email |
| Cashback RETROACTIVE + 3 cap | Dashboard so sánh nhiều thẻ |
| Kỳ sao kê suy ra từ `statement_day` | Báo cáo tổng hợp |
| Import Excel idempotent | Cổng thanh toán / trao đổi thẻ |
| UI tối thiểu | Mobile app |

## 3. Kiến trúc tổng thể

```
routes/web.php  ─►  CreditCardController  ─►  UserCard (model namespaced, connection: creditcard)
                          │                          │
                          │                          ├─► product ─► bank
                          │                          ├─► statementPeriod
                          │                          └─► currentPolicy
                          ▼
     StatementPeriodService ── CashbackCalculator (thuần, deterministic)
                │                        ▲
                ▼                        │
     CashbackRecordService ──────────────┘   PolicyEngineService ── TierResolverService
                ▲
     TransactionImportService ── TransactionSheetReader
```

Tách ba tầng rõ ràng: **model** (đúng connection), **service** (luật nghiệp vụ, tự kiểm
tra ownership vì có thể bị gọi từ import/queue/CLI), **controller** (chỉ read + render).

## 4. Quyết định tách database

`users` nằm ở `hoantienaff`; dữ liệu thẻ vòng đời riêng và khối lượng lớn nên tách sang
`hoantien_creditcard`.

Hệ quả bắt buộc: **không cross-database foreign key**. `user_id` ở DB creditcard là
*logical reference*, chỉ được bảo vệ ở tầng application. Đây là đánh đổi có chủ đích.

⚠️ `credit_card_banks` và `credit_card_categories` tồn tại **cùng tên ở cả hai DB**. Một
model quên ghim connection sẽ đọc nhầm sang bảng rỗng, triệu chứng là "dữ liệu biến mất"
chứ không phải lỗi kết nối. Mọi model mới kế thừa `CreditCardModel` (ghim connection).

## 5. Schema và bảng

10 bảng trong `database/migrations/creditcard/`: `banks`, `products`, `categories`,
`policy_templates`, `user_cards`, `policies`, `policy_tiers`, `policy_tier_categories`,
`statement_periods`, `transactions`.

`credit_card_transactions` chia bạch 3 lớp để audit được:
- **A. SOURCE OF TRUTH** — `user_card_id`, `transaction_date`, `posted_date`, `statement_period_id`, `category_id`, `merchant`, `amount`, `note`
- **B. RESOLVED POLICY** — `policy_version_id`, `policy_tier_id`, `policy_tier_category_id`
- **C. CALCULATED** — `cashback_percent_snapshot`, `cashback_amount_snapshot`, `is_eligible`, `ineligible_reason`, `calc_basis`, `calc_meta`, `calculated_at`

**Không có `calculation_date`** — sẽ tạo hai nguồn sự thật lệch nhau. Truy vấn chuẩn hoá
theo `transaction_date` + `statement_period_id`; `calc_basis` chỉ lưu để audit.

**`category_id` dùng `RESTRICT`**: danh mục đã dùng cho giao dịch thì không xoá cứng được
(sẽ mất lịch sử); ngừng dùng thì `is_active = false`. Migration ban đầu khai báo
`nullOnDelete()` — đã sửa và **kiểm chứng trên DB thật** (MySQL trả lỗi 1451 khi xoá danh
mục đang được dùng).

## 6. Mô hình dữ liệu

11 model dưới `app/Models/CreditCard/`, tất cả ghim `connection = 'creditcard'`:
`Bank`, `Product`, `Category`, `PolicyTemplate`, `UserCard`, `Policy`, `PolicyTier`,
`PolicyTierCategory`, `PolicyVersion`, `StatementPeriod`, `Transaction`.

Ba lỗi thật phát hiện ở tầng model:

1. `UserCard::user()` — `belongsTo()` mặc định thừa kế connection `creditcard` và query `users` sai DB. Sửa bằng `newBelongsTo()` + `CreditCardModel::mainDatabaseConnectionName()`.
2. `UserCard::bank()` — `hasOneThrough` map khoá sai, sinh SQL hỏng.
3. `Category::scopeSelectableBy()` — bắt buộc scope, nếu không sẽ lộ danh mục riêng của user khác.

## 7. Phân quyền

4 policy tại `app/Policies/CreditCard/`, auto-discover của Laravel 12 nhờ namespace model
khớp `App\Policies\CreditCard\*Policy`.

| Policy | Quy tắc |
| --- | --- |
| `UserCardPolicy` | `viewAny`/`create`: ai cũng được. `view`/`update`/`delete`: **chỉ chủ thẻ** |
| `TransactionPolicy` | `view`: chủ thẻ. `update`/`delete`: chủ thẻ **và** kỳ chưa `finalized` |
| `PolicyPolicy` | `view`: chủ thẻ. `update`: chủ thẻ, chưa `superseded`. `delete`: chỉ khi `draft` |
| `PolicyTemplatePolicy` | `system`: ai cũng xem, chỉ admin sửa/xoá. `user`: chỉ chủ template. `is_builtin` **không bao giờ xoá** |

Hai điểm cố ý "khó chịu" nhưng đúng nghiệp vụ:

- **Admin không tự động có quyền trên thẻ của user khác.** Quyền trên dữ liệu cá nhân
  bám chủ sở hữu, không bám role. Có test khẳng định.
- **Bản ghi đã chốt là bất biến.** Kỳ `finalized` và policy `superseded` là lịch sử tài
  chính; sửa làm hỏng số liệu đã báo cáo.

Policy chỉ bảo vệ HTTP, nên service **tự kiểm tra ownership**: `assignTransactionToPeriod()`
và `calculateTransaction()` chặn trước mọi DB write.

## 8. Kỳ sao kê

- `boundariesForDate()` — thuần toán, không query. Dùng cho deadline reminder.
- `findForDate()` — **chỉ đọc, không tạo**. Có test `index does not create statement periods`
  chứng minh GET không ghi DB.
- `assignTransactionToPeriod()` — chỉ khi kỳ còn `open`, và kiểm tra thuộc thẻ.

Kỳ chỉ được tạo khi có giao dịch thật đi qua `calculateTransaction()`. Phân biệt
"tìm" và "tạo" là quyết định thiết kế, không phải tiện lợi.

`spending_deadline_day` **chỉ là ngày nhắc**, không tham gia xác định kỳ.

## 9. Policy engine

- `PolicyEngineService` — chọn version hiệu lực theo `effective_from/to`.
- `TierResolverService` — chọn bậc theo tổng chi tiêu eligible cả kỳ.
- Mỗi `Policy` là gốc; `PolicyVersion` tạo chuỗi bằng `root_policy_id` + `version_no`.
- `PolicyCloneService`:
  - `createNextVersion()` clone từ **version mới nhất**, không clone từ gốc (sửa bug).
  - `saveAsTemplate()` clone tiers từ version hiện hành.
  - `unsetRelation('currentPolicy')` sau khi đổi pointer, tránh đọc cache cũ.

Luật của version là bất biến; version `superseded` không sửa được.

## 10. Cashback engine

**RETROACTIVE, không progressive.** Tier chọn bằng tổng chi tiêu eligible cả kỳ, tỷ lệ áp
cho **mọi** giao dịch trong kỳ. Yêu cầu: cashback phải **ổn định** — không để con số giao
dịch tháng trước nhảy khi kỳ kết thúc.

Hệ quả bắt buộc: cashback **không bất biến lúc nhập**; kỳ `open` thì tính lại được, kỳ
`finalized` thì đóng băng.

Thứ tự tính: lọc eligible theo rule khớp category → tổng eligible spend chọn tier → rate
theo tổng chi tiêu của **từng category** → tính từng giao dịch → áp cap theo thứ tự
1 → 2 → 3, ghi `calc_meta.caps`.

| Cap | Cột |
| --- | --- |
| 1. mỗi giao dịch | `max_cashback_per_transaction` |
| 2. mỗi danh mục mỗi kỳ | `max_cashback_per_category_per_period` |
| 3. tổng mỗi kỳ | `max_cashback_total_per_period` (ở `credit_card_policies`) |

**Đa rule trong một category** — sàng lọc theo mức sàn nhỏ nhất, rồi kiểm tra lại
`min_transaction_amount` của đúng rule được chọn. Không có rule nào thoả ⇒ không eligible
(không rơi vào rule không liên quan). Hai test khóa hành vi này.

Rounding một lần ở cuối theo `rounding_mode`, không làm tròn từng tầng.

**Cashback không nhập tay** ở mọi đường vào.

## 11. Import Excel

| Lớp | Trách nhiệm |
| --- | --- |
| `TransactionSheetReader` | Đọc + map header. Không validate nghiệp vụ, không ghi DB |
| `TransactionImportService` | Validate từng dòng, resolve danh mục, ghi, gọi cashback pipeline |
| `TransactionImportResult` | Số liệu + lỗi theo từng dòng |

Quy tắc đã áp dụng:

1. **Map cột theo tên header**, không theo vị trí; tiếng Việt lẫn tiếng Anh, không dấu/không dấu. Bắt buộc có ngày giao dịch và số tiền.
2. **Từ chối cả file** nếu có cột `cashback` / `hoan tien` / `tien hoan`. Danh sách cố tình chỉ gồm từ không thể trùng tên cột hợp lệ — đã bỏ `'bac'` và `'tier'` sau khi phát hiện từ chối oan ("Bắc Giang", "bác sĩ").
3. **Dòng sai không làm hỏng cả file** — báo kèm số dòng + tên cột; dòng hợp lệ vẫn ghi.
4. **Idempotent** — `source_reference` = `"<tên file>#<số dòng>"`; nạp lại cùng file thì mọi dòng `skipped`. Đổi tên file ⇒ nguồn khác, vẫn nhập.
5. **Số tiền** — nhận cả kiểu Việt (`1.000.000`) và Anh (`1,000,000.50`), âm qua `-` hoặc ngoặc. Phân biệt dấu thập phân với dấu phân cách nghìn bằng quy tắc "sau dấu đúng 3 chữ số ⇒ phân cách nghìn".
6. **Toàn bộ ghi trong một transaction DB** — lỗi giữa chừng không để lại kỳ nửa vời.
7. Danh mục resolve qua `scopeSelectableBy` — không import được vào danh mục người khác.
8. Chỉ nhận `.xlsx`; `.csv` ném exception có thông báo rõ.

## 12. Controller và UI

Sáu route `thetindung` giữ nguyên URL cũ. `index` là trang thật (scope
`UserCard::ownedBy(auth()->id())`, hiển thị tổng thẻ, tổng hạn mức, cashback kỳ hiện tại,
ngày chốt, hạn thanh toán, cảnh báo hạn); năm route còn lại là placeholder có chủ đích để
không phá link cũ.

Chrome dùng class component `App\View\Components\CreditCard\Layout`; cả sáu view đều bọc
trong `<x-credit-card.layout>`, và test của `index` render thật nên component được bảo vệ.

**Giới hạn đã biết:** ô "kỳ hiện tại" lấy theo thẻ đầu tiên theo `id`. Nhiều thẻ thì mỗi
thẻ có kỳ riêng nhưng UI 1A hiển thị một kỳ đại diện; cảnh báo hạn đã tính cho từng thẻ.

## 13. Seeder và dữ liệu tham chiếu

`CreditCardSeeder` idempotent — đã chạy 3 lần cho cùng kết quả: 5 banks, 5 products,
8 categories, 1 template, 1 policy, 2 tiers (tier 1: 6 rule, tier 2: 7 rule), 13
tier-category rules, và **0** thẻ / 0 kỳ / 0 giao dịch. Chỉ dữ liệu tham chiếu.

## 14. Lệnh vận hành

```bash
php artisan credit-card:migrate --seed          # migrate + seed
php artisan credit-card:migrate --fresh --seed  # drop bảng creditcard rồi dựng lại
php artisan credit-card:migrate --pretend       # xem SQL, không ghi
php artisan credit-card:migrate:status          # trạng thái từng migration
```

`--fresh` chỉ drop bảng trong `database/migrations/creditcard/`, không đụng DB chính —
nhưng xoá luôn dữ liệu user của module, nên chỉ dùng khi DB còn chỉ có dữ liệu seed.

## 15. Kiểm thử

| Phạm vi | Kết quả |
| --- | --- |
| `tests/Feature/CreditCard` | 98 pass, 927 assertions |
| `tests/Unit/CreditCard` | 26 pass, 68 assertions |
| `tests/Feature/CreditCardModuleTest` | 20 pass, 205 assertions |
| **Tổng Credit Card** | **144 pass, 1200 assertions** |
| **Full suite** | **1279 pass, 3 fail, 5284 assertions** |

Baseline trước khi thay đổi: 1153 pass / 3 fail / 4265 assertions. Nay là
**cùng 3 fail** ⇒ không regression; chênh lệch +126 test là phần mới thêm.

Harness `InteractsWithCreditCardDatabase` chạy migration trên **cả hai** connection rồi
rollback, vì `CreditCardModuleTest` cần đọc bảng legacy ở DB chính.

## 16. Bug phát hiện và sửa

| Test bắt được | Bug |
| --- | --- |
| `credit card models and relationships work` | `UserCard::bank()` map khoá `hasOneThrough` sai |
| `credit card data is scoped to the authenticated user` | `UserCard::user()` query nhầm DB |
| `index does not create statement periods` | Rủi ro GET ghi DB |
| `unrelated rule does not disqualify` | Sàng lọc rule quá rộng ⇒ mất cashback hợp lệ |
| `selected rule min enforced` | Rule không đạt `min_transaction_amount` vẫn được chọn |
| `a superseded policy version cannot be edited even by its owner` | Bất biến bản ghi lịch sử |
| `it cannot import into another users category` | Rò danh mục riêng qua import |
| Carbon strict mode (9 test fail) | `createFromFormat` **ném exception** thay vì trả `false` |
| `1.000.000` (VN) | `is_numeric('1.000.000')` = false ⇒ mất giao dịch hợp lệ |

## 17. Quyết định nghiệp vụ đã chốt

1. **Cashback RETROACTIVE, không progressive** — tier theo tổng kỳ, rate áp cho mọi giao dịch kỳ đó. Lý do: cashback phải ổn định.
2. **Rate theo tổng chi tiêu của từng category**, không phải tổng kỳ.
3. **Cashback không nhập tay**, kể cả Excel.
4. **`spending_deadline_day` chỉ là reminder**, không định nghĩa kỳ.
5. **Không có `calculation_date`** — tránh hai nguồn sự thật.
6. **Danh mục đã dùng: `RESTRICT`** thay vì xoá cứng.
7. **Kỳ sao kê suy ra từ `statement_day`**, người dùng tự chỉnh khi kỳ còn `open`.
8. **Module không gọi API ngân hàng** — bank chỉ là bảng tham chiếu.
9. **Bản ghi đã chốt là bất biến.**

## 18. Rủi ro và giới hạn

| Rủi ro | Mức | Giảm thiểu |
| --- | --- | --- |
| Trùng tên bảng ở hai DB | Cao | Mọi model ghim connection; có test |
| Không có cross-DB FK | Trung bình | Ownership guard ở service + policy |
| SQLite vs MySQL khác ở cột `DATE` | Trung bình | Ép `whereDate()`; chỉ lộ trên CI |
| Cashback tính lại khi kỳ còn `open` | Thấp | Đúng thiết kế; kỳ `finalized` đóng băng |
| Import giữ file trong RAM | Thấp | Chấp nhận ở 1A; phase sau chunk + queue |
| Bảng legacy còn sót | Thấp | Tách riêng, không dùng; cần owner quyết định |

## 19. Điểm còn nợ kỹ thuật

| # | Vấn đề | Mức |
| --- | --- | --- |
| 1 | 4 bảng legacy + 4 model root còn lại; cần owner quyết định xoá (xoá migration đã chạy production) | Thấp |
| 2 | UI 1A hiện kỳ của thẻ đầu tiên | Thấp |
| 3 | Import chỉ nhận `.xlsx` | Thấp |
| 4 | 3 lỗi test nền (Auth / ShopeeFood / TikTok) | Ngoài phạm vi |
| 5 | Năm view placeholder chưa có test render | Thấp |
| 6 | Chưa có file mẫu tải lên | Thấp |

## 20. Bảo mật và an toàn dữ liệu

- Mọi truy vấn user-owned đều scope theo `auth()->id()` hoặc qua policy.
- **Admin không tự động có quyền trên dữ liệu cá nhân của user khác.**
- Danh mục luôn scope theo `scopeSelectableBy` kể cả trong import.
- Cashback không bao giờ nhận từ đầu vào của người dùng.
- File Excel không được phép mang cột cashback.
- Cùng tên bảng ở hai DB là rủi ro nhầm lẫn lớn nhất — đã chặn bằng base model ghim connection.
- Bảo mật secrets: không có credential nào được ghi vào repo; `.env.example` chỉ có giá trị comment.
- Không commit, không push — thay đổi còn nguyên trong working tree để review.

## 21. Rollback

Phase 1A chưa commit nên rollback là revert 6 file đã sửa (`.env.example`,
`app/Models/User.php`, `config/database.php`, `phpunit.xml`,
`resources/views/layouts/navigation.blade.php`, `routes/web.php`) và dọn file untracked:

```bash
git checkout -- .env.example app/Models/User.php config/database.php \
                phpunit.xml resources/views/layouts/navigation.blade.php routes/web.php
git clean -nd
mysql -u root -e "DROP DATABASE hoantien_creditcard;"
```

Bốn bảng legacy ở DB chính **không bị đụng** bởi bất kỳ thao tác nào ở trên.

## 22. Kết luận và bước tiếp theo

Phase 1A đạt đủ mục tiêu: nền tảng dữ liệu tách DB, policy engine, cashback engine có kiểm
chứng, kỳ sao kê, import Excel an toàn, UI hiển thị dữ liệu thật, 144 test module pass và
không regression trên full suite.

**Việc đã xong:** 11 model · 4 policy · 10 migration · 6 service + 4 lớp import ·
13 test authorization · 13 test import · 2 tài liệu.

**Bước tiếp theo đề xuất:**

1. **Phase 1B — CRUD thật:** quản lý thẻ, giao dịch, policy; thay 5 route placeholder.
2. **Bỏ nợ kỹ thuật #1:** owner quyết định xoá 4 bảng legacy + 4 model root.
3. **Sửa 3 lỗi test nền** (Auth, ShopeeFood, TikTok) — việc riêng, không thuộc module.
4. **Harden import:** chunk + queue cho file lớn, thêm file mẫu tải lên, hỗ trợ `.csv`.
5. **Dashboard:** so sánh nhiều thẻ, báo cáo tổng hợp theo kỳ.

Tài liệu chi tiết kỹ thuật: `docs/CREDIT_CARD_PHASE_1A_IMPLEMENTATION.md`
Đối chiếu nghiệp vụ: `docs/CREDIT_CARD_PHASE_1A_ARCHITECTURE_REVIEW.md`
