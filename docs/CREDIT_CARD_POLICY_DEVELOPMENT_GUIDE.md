# Hướng dẫn phát triển & quản lý Chính sách hoàn tiền Thẻ tín dụng (HoanTienAff)

> Tài liệu viết dựa trên **code và dữ liệu thực tế** của repo `C:\xampp\htdocs\hoantienaff`
> (khảo sát ngày 12/10/2026). Mọi đường dẫn, tên class, tên bảng, số liệu DB đều được kiểm
> chứng, không suy đoán.
>
> Mục đích: để phiên OpenCode/ChatGPT kế tiếp kế thừa đúng quy trình *nghiên cứu → thiết kế →
> seed → kiểm thử → quản trị* các chính sách hoàn tiền hệ thống của module Thẻ tín dụng mà
> không phải đoán lại từ đầu.

---

## 0. Trạng thái repo tại thời điểm viết (bắt buộc đọc trước khi làm việc)

- Branch: `main`. HEAD: `9902230 module tra cuu v3 + add chinh sach cac the VIB`.
- `git status --short` có các thay đổi **chưa commit** (không được reset/revert/stash/xoá/ghi đè):
  - **Workstream MCC đang làm dở song song** (không thuộc phạm vi tài liệu này, KHÔNG đụng vào):
    `app/Models/CreditCard/PolicyMccProfile.php`,
    `app/Services/CreditCard/CardPolicyLookupService.php`,
    `app/Services/CreditCard/Import/MccRowBenefitImporter.php`,
    `database/data/credit_card_mcc_row_benefits.php`, `resources/views/credit-card/lookup.blade.php`,
    `tests/Feature/CreditCard/CardLookupMccProfileTest.php`,
    migration mới `database/migrations/creditcard/2026_10_13_000004_add_product_name_to_credit_card_policy_mcc_profiles_table.php`
    (migration này đã được chạy lên DB live, batch 21). Kèm các doc
    `CREDIT_CARD_MCC_WORKFLOW.md`, `CREDIT_CARD_MCC_VIB_PRODUCT_GROUPING_REPORT.md`,
    `CREDIT_CARD_MCC_PROFILES_REPORT.md`, `CREDIT_CARD_MCC_ROW_BENEFITS_REPORT.md`.
  - **Công việc template mới (bản thân tài liệu này mô tả)**: `database/seeders/CreditCardSeeder.php`
    (thêm `seedResearchedTemplates()`), `tests/Feature/CreditCard/ResearchedTemplatesPolicyTest.php` (mới),
    `tests/Feature/CreditCard/ShinhanSupremePolicyTemplateTest.php` (cập nhật số template 9→17).
  - **DB live đã seed xong** 8 template mới (bậc id 22–29) — chỉ đợi commit.
- Full suite hiện còn **4 failure ngoài phạm vi, có trước** (KHÔNG phải do module này):
  `Auth\RegistrationTest`, `CreditCardModuleTest` (snapshot route `credit-cards.api.policies.history`),
  `PromotionNewsHomepageTest`, `ShopeeFoodOrderSyncServiceTest`.

---

## 1. Kiến trúc module (đã xác minh)

### 1.1 Database tách riêng — connection `creditcard`

- Connection `creditcard` → database **`hoantien_creditcard`** (`config/database.php:119–139`).
- Biến env: `DB_CREDITCARD_DATABASE=hoantien_creditcard` (xem `.env`). Tất cả biến `DB_CREDITCARD_*`
  **fallback về `DB_*`**, nên dưới test chúng tự chuyển sang sqlite `:memory:` mà không cần khai báo thêm
  (xem comment `config/database.php:113–116` và `phpunit.xml:29–35`).
- **KHÔNG có cross-database FK**: `credit_card_user_cards.user_id` là logical reference sang
  `hoantienaff.users`; không có FK vật lý (bảng `users` chỉ nằm ở DB chính).
- Mọi model CreditCard kế thừa `App\Models\CreditCard\CreditCardModel` ghim `protected $connection = 'creditcard';`
  (`app/Models/CreditCard/CreditCardModel.php:32`). Lưu ý quan hệ sang `User` phải tự ghim connection chính
  (xem nhận xét trong `CreditCardModel.php:35–50` và `UserCard.php:111–120`).
- **Migrations chỉ chạy qua command riêng**, vì `Migrator` của Laravel glob không đệ quy
  (`app/Console/Commands/CreditCardMigrate.php:13–16`):

```bash
php artisan credit-card:migrate           # migrate cho connection creditcard (path `database/migrations/creditcard`)
php artisan credit-card:migrate --seed    # migrate + chạy CreditCardSeeder
php artisan credit-card:migrate --fresh   # drop toàn bộ schema credit card rồi dựng lại (⚠️ phá dữ liệu)
php artisan credit-card:migrate --pretend # chỉ in SQL
php artisan credit-card:migrate:status    # trạng thái từng migration (authoritative)
```

Tương đương thủ công: `php artisan migrate --database=creditcard --path=database/migrations/creditcard`.
Bảng `migrations` của connection `creditcard` nằm trong `hoantien_creditcard` (khác database, độc lập).

- Migrations nằm tại `database/migrations/creditcard/` (**41 file**, dấu thời gian `2026_10_01_*` → `2026_10_13_*`).
  (`credit-card:migrate:status` chạy lúc khảo sát: tất cả 41 → **Ran**, batch 1–21.)

> ⚠️ **Nhiễu lịch sử đã ghi nhận:** bảng `migrations` của connection `creditcard` hiện có **83 dòng**,
> trong đó **42 dòng là tên migration của app chính** (users, campaigns, withdrawals, ...) còn sót lại do
> giai đoạn chưa tách DB. Weird nhưng vô hại: chúng không thuộc schema credit card và không bị các command
> `credit-card:*` đụng tới. Dùng `credit-card:migrate:status` làm nguồn tin cậy, đừng hoảng khi đếm 83.

### 1.2 Seeder hệ thống

- Class: `Database\Seeders\CreditCardSeeder` (`database/seeders/CreditCardSeeder.php`, 1586 dòng).
- `private const CONNECTION = 'creditcard';` (`:51`). Toàn bộ `run()` bọc trong
  `DB::connection(self::CONNECTION)->transaction(...)` (`:170`).
- `run()` theo thứ tự (`:168–184`):
  1. `seedBanks()` — 43 bank canonical từ `self::BANKS` (`:78–123`), có `assertNoDuplicateCanonicalIdentity()`
     và **`deactivateNonCanonicalBanks()`** → ⚠️ chạy cả `run()` có thể đổi trạng thái bank ngoài canonical.
  2. `seedCategories()` — đảm bảo 19 danh mục gốc (`:137–166`), **không đè `is_default`** đã được admin chỉnh.
  3. `seedBuiltinTemplate($categories)` — template "Cashback phổ thông".
  4. `seedShinhanSupremeTemplate($categories)`.
  5. `seedVibCombos($categories)` → `seedVibMaxCardVisaTemplates(...)`.
  6. `seedVibOneCardCombos($categories)` → `seedVibOneCardTemplates(...)`.
  7. `seedResearchedTemplates($banks, $categories)` — **8 template mới** (`:1133–1557`).

- **Tính idempotent của mọi hàm template:** dùng `updateOrCreate` theo `(scope, owner_user_id, slug)`;
  nếu template đã tồn tại blueprint thì **`continue`** — không bao giờ ghi đè blueprint mà admin đã chỉnh
  (ví dụ `:1492–1495`). Chỉ tạo mới khi blueprint chưa có.

### 1.3 Model chính (namespace `App\Models\CreditCard`)

Bảng trong `hoantien_creditcard`:

| Model        | Bảng                          | Vai trò |
|---|---|---|
| `Bank`       | `credit_card_banks`           | Ngân hàng, `slug` canonical, `short_name`, `aliases`; `resolveByCode()` |
| `Category`   | `credit_card_categories`      | Danh mục chi tiêu, `scope` = `system`/`user` |
| `CategoryCombo` | `credit_card_category_combos` + `..._combo_items` | Nhóm danh mục (dùng cho rule theo combo) |
| `Product`    | `credit_card_products`        | ⚠️ **KHÔNG còn được seed** từ Phase 1B (`CreditCardSeeder.php:171–173`) |
| `PolicyTemplate` | `credit_card_policy_templates` | Template hệ thống: `scope`, `is_builtin`, `is_active`, `sort_order`, `applicable_bank_id`, `applicable_product_match` |
| `Policy` (và `PolicyVersion extends Policy`) | `credit_card_policies` | MỘT bảng dùng chung cho **blueprint rời** (`user_card_id = NULL`), **policy gắn thẻ user**, và **version** (không có bảng `credit_card_policy_versions`) — `PolicyVersion.php:11–12` |
| `PolicyTier` | `credit_card_policy_tiers`     | Bậc, khoảng `min_total_spend`/`max_total_spend`, `max_cashback_per_period` |
| `PolicyTierCategory` | `credit_card_policy_tier_categories` | RULE của bậc; `scope_type` = `category` | `other` (fallback), `combo_id` nullable, 3 loại cap |
| `UserCard`, `Transaction`, `StatementPeriod`, `CreditCardStatement`, `CreditCardUserSetting`, `Report`, `SpendQualification*` | ... | Dữ liệu user |

- Quan hệ template: `blueprint()` (một), `blueprints()` (nhiều version, `:118–123`),
  `currentBlueprint()` (`:131`), `defaultBlueprint()` = version do admin đánh dấu mặc định, fallback về
  current (`:153–164`), `applicableBank()` (`:169`), `applicableToCard()` (`:95–100`).

### 1.4 Service / resolver / engine

`app/Services/CreditCard/` (chọn lọc, đã xác minh từ glob + grep):

- `CashbackCalculator.php` — **engine tính hoàn tiền** (giống `app/Services/CashbackCalculator.php` của affiliate
  nhưng đây là bản CreditCard; KHÔNG sửa/nhầm lẫn hai cái). Đọc `rules`/`transactionCaps`/`tier`; min giao dịch,
  chọn rule theo khoảng, cap từng loại, round-to-đồng.
- `TierResolverService.php` — chọn bậc theo tổng chi tiêu; **khoảng bậc là nửa mở `[min, max)`**
  (`spend >= min AND (max === null OR spend < max)`).
- `PolicyService.php` — tạo/clone template hệ thống, **guard applicability** tại `cloneTemplate`; version, default.
- `PolicyCloneService.php` — deep clone blueprint khi gắn template vào thẻ / tạo version kế tiếp.
- `CardPolicyLookupService.php` — **choke point** tra cứu "giao dịch thuộc policy/mức hoàn nào" (template theo
  bank + keyword sản phẩm, MCC mapping, MCC profile; có `mergeProductMatches()` đang được workstream MCC phát triển).
- `CategoryRuleService.php` — giữ 1 fallback/bậc: `ensureSingleFallback()` (`:530`), chuẩn hoá thứ tự:
  `normaliseRuleOrder()` (`:137`); `assertCategoryUsable()`, rule CRUD.
- Khác: `BankService`, `CategoryService`, `CategoryComboService`, `SystemCategoryService`, `CashbackRecordService`,
  `CashbackQuotaService`, `CardRecommendationService`, `CardPolicySaveService`, `StatementPeriodService`,
  `SpendQualificationService`, `CreditCardReportService`, `Import/*` (đọc CSV transaction, MCC...),
  `PolicyMccProfileService`.

### 1.5 Controller / route / view quản trị

- Nhóm route admin: `routes/web.php:296` (`Route::middleware(['auth'])->prefix('admin')->name('admin.')`).
- **Màn hình "Chính sách hoàn tiền hệ thống"**: `GET /admin/credit-card/policies`
  (`admin.credit-card-policies.index`, quyền `credit-cards.view`) — `routes/web.php:375`.
- Controller: `app/Http/Controllers/Admin/CreditCard/SystemPolicyAdminController.php` — `index/create/show/edit/cloneForm`.
  Query index đọc `PolicyTemplate::query()->system()` (scope=system), **KHÔNG lọc `is_active`**, tìm kiếm theo
  `name`/`applicable_product_match`/`description` + tên bank (`:33–48`), sắp theo `sort_order, name`.
- JSON API editor: `app/Http/Controllers/Admin/CreditCard/SystemPolicyApiController.php` — `index/store/show/versions/
  storeVersion/clone/updateVersion/update/destroyVersion/setDefaultVersion`, nhóm route
  `/admin/credit-card/api/policies...` (`routes/web.php:395–428`, `throttle:credit-card-admin-api`, 120/min/user).
- Cùng nhóm: `SystemCategoryAdminController`, `SystemComboAdminController`, `SpendQualificationAdminController`,
  `SpendQualificationTemplateApiController`, `PolicyMccAdminController`.
- User-side: `app/Http/Controllers/CreditCard/*` (CardManagement, Policy user, lookup...).
- View: `resources/views/admin/credit-card-policies/{index,create,edit,show}.blade.php`;
  editor dùng chung `resources/views/credit-card/partials/policy-editor.blade.php`.
- **Presenter chung cho Blade + API**: `app/Support/CreditCard/SystemPolicyPresenter.php` — payload không lệch nhau.
- Permission: `credit-cards.view` (đọc), `credit-cards.manage` (ghi). Role Admin nhận toàn bộ.

### 1.6 Kiểm thử

- Thư mục: `tests/Feature/CreditCard/` (58 file).
- `tests/Concerns/InteractsWithCreditCardDatabase.php` — trait bắt buộc cho Feature test CreditCard:
  - Gộp connection `creditcard` vào `connectionsToTransact()` (`:57–64`) để `RefreshDatabase` rollback CẢ HAI DB.
  - Nếu `DB_CREDITCARD_DATABASE = ':memory:'` thì migrate credit card schema **một lần mỗi process** (`:66–79`);
    nếu DB thật thì bỏ qua (schema do `credit-card:migrate` lo).
- `phpunit.xml:35` ép `DB_CREDITCARD_DATABASE=:memory:` → test KHÔNG bao giờ chạm DB thật.
- Chạy:
  ```bash
  php artisan test tests/Feature/CreditCard
  php artisan test --filter ResearchedTemplatesPolicyTest
  php artisan test --filter CreditCard
  ```
- Chuẩn hoá mã nguồn/pint: `vendor\bin\pint` (vd `vendor\bin\pint tests\Feature\CreditCard\ResearchedTemplatesPolicyTest.php`).
- Kiểu tra trivial: `git diff --check`.

---

## 2. Quy trình nghiên cứu chính sách ngân hàng

Chuẩn 8 bước (đã áp dụng cho đợt 10 sản phẩm, xem kết quả chi tiết ở
`docs/CREDIT_CARD_NEW_TEMPLATES_RESEARCH_REPORT.md`):

1. Tìm tài liệu **chính thức** của ngân hàng (trang sp chính thức, PDF "quy định hoàn tiền", thể lệ).
2. Xác minh **đúng tên thẻ, đúng ngân hàng, đúng mạng lưới** (Visa/Mastercard/JCB/Amex).
   ⚠️ Thực tế: **6/10 sản phẩm** ban đầu bị lệch tên/mạng lưới so với tài liệu chính thức
   (vd Flex và Shinhan 365 **là Mastercard**, Cake 2in1 Premium **là Visa Signature**, Sacombank
   "VisA Platinum Cashback" đúng ra là sản phẩm riêng).
3. Đọc thể lệ/PDF, ghi rõ **thời gian hiệu lực** và ngày truy cập nguồn.
4. Trích xuất: % hoàn/điểm, danh mục, điều kiện tổng chi tiêu theo kỳ, **cap** (theo giao dịch / theo nhóm /
   theo kỳ / theo hạng), **fallback** (giao dịch còn lại), giao dịch loại trừ, min giao dịch.
5. Phân biệt **hoàn tiền VND** với **điểm thưởng quy đổi**. Quy ước hiện tại: mô hình điểm 1:1 thành % khi
   đơn vị điểm ≈ tiền (UOB One, VPBank Flex, Shinhan MyLife), kèm chú thích trong `description`.
6. Lưu nguồn + URL + ngày hiệu lực + điểm chưa xác minh (vd UOB One network chưa rõ, Amex 10% vs 5% online
   nước ngoài không tách kênh được trong engine).
7. **KHÔNG tự đặt thông số để lấp chỗ trống**.
8. Chỉ triển khai (seed) khi đủ căn cứ VÀ engine biểu diễn được chính xác.

### 2.1 Phân loại kết quả

| Mức | Ý nghĩa | Hành động |
|---|---|---|
| **HOÀN THÀNH** | Đủ nguồn chính thức + engine mô hình đúng | Seed template + test engine |
| **CHƯA ĐỦ DỮ LIỆU** | Chưa tìm được nguồn/sản phẩm chính xác | KHÔNG seed; ghi vô report kèm lý do |
| **CHƯA HỖ TRỢ** | Engine/chủng loại chưa thể hiện (ún, nhóm online tách, gộp khách sạn+hàng không...) | KHÔNG seed; nêu rõ giới hạn |
| **CẦN XÁC NHẬN** | Dữ liệu mâu thuẫn / cần owner quyết | KHÔNG seed; báo chờ xác nhận |

Thực tế đợt đầu: 8/10 **HOÀN THÀNH**; `vpbank-reward-visa` → **CHƯA ĐỦ DỮ LIỆU** (chưa có sản phẩm Visa
chính thức); `shinhan-cashback-visa` → **CẦN XÁC NHẬN** (3 hạng + T&C xung đột + ưu đãi cuối tuần không
mô hình được). Test `reporter_flagged_products_are_not_seeded` chặn việc seed lầm 2 sản phẩm này.

### 2.2 Phí thường niên hồ sơ MCC (bắt buộc khi tạo/cập nhật hồ sơ)

Mỗi hồ sơ MCC khai 3 trường phí thường niên trên `credit_card_policy_mcc_profiles`
(`annual_fee_primary`, `annual_fee_supplementary`, `annual_spend_for_annual_fee_waiver`; nullable,
`decimal(16,2)`). Quy tắc nghiên cứu:

- Chỉ ghi khi có **nguồn chính thức** đúng **sản phẩm + hạng** (biểu phí thẻ tín dụng của ngân hàng,
  hoặc trang sản phẩm chính thức). Nguồn thứ cấp (sosanhthe, rcgv, fingo…) chỉ tham khảo.
- **KHÔNG điền `0`** kể cả khi ngân hàng ghi "Miễn phí"; KHÔNG suy luận/sao chép giữa hồ sơ; thiếu
  căn cứ ⇒ **NULL** + ghi lý do.
- **Liệt kê riêng từng hạng/bậc — KHÔNG gộp.** Hồ sơ dùng chung nhiều hạng có phí khác nhau
  (VIB One Card #2 — 3 hạng; VIB Max Card #14 — 4 hạng) ⇒ để NULL ("phụ thuộc hạng") và đề xuất
  bảng hạng riêng; **KHÔNG** ghi một mức chung.
- Phân biệt **thẻ chính** vs **thẻ phụ**; **phí gói/thành viên** (membership/package fee) là loại
  phí khác; tách **năm đầu** vs **các năm tiếp theo** và **mức chuẩn** vs **ưu đãi thời hạn**.
- Ghi nguồn (URL) + ngày hiệu lực trong báo cáo.
- Bảng phí PDF nhiều cột: **đối chiếu toạ độ cột** (hoặc mở trực tiếp) trước khi ghi; số liệu
  "đáng ngờ" ⇒ NULL.
- ⚠️ `shinhanfinance.com.vn` = Shinhan **Finance** (SVFC), KHÔNG phải Shinhan Bank VN.
- Quy trình đầy đủ + trạng thái từng hồ sơ: `docs/CREDIT_CARD_MCC_WORKFLOW.md` Phần I.

---

## 3. Quy trình tạo System Template (bằng code thực tế)

Chuỗi ghi dữ liệu dùng chung (xem `seedResearchedTemplates`, `CreditCardSeeder.php:1133–1557`):

```
PolicyTemplate (scope=system, owner=0, slug, bank_id, product_match, sort_order, is_builtin, is_active)
  └─ Policy blueprint   user_card_id=NULL, version_no=1, status=active,
                        effective_from=today, root_policy_id=CHÍNH NÓ,  min_total_spend=0
       └─ PolicyTier×N  min_total_spend/max_total_spend (nửa mở [min,max)), max_cashback_per_period
            ├─ ensureSingleFallback(tier, {percent, counts_cap, min_tx})   → 1 rule scope_type='other'
            └─ PolicyTierCategory×M  scope_type='category', spend_from=0, spend_to=null, combo_id=null,
                                     cashback_percent, max_cashback_per_transaction, max_cashback_per_category_per_period,
                                     min_transaction_amount, counts_toward_tier_cap, is_enabled, sort_order
            └─ normaliseRuleOrder(tier)
```

### 3.1 Quy ước cấu hình bắt buộc

- **Slug** định danh unique, tĩnh (vd `sacombank-visa-platinum-cashback`) — khóa `updateOrCreate` cùng
  `(scope=system, owner_user_id=0)`.
- `applicable_bank_id` trỏ `Bank` theo slug đã có trong DB (dùng map `$banks` của `seedBanks()`).
  `applicable_product_match` là **keyword chứa trong tên thẻ user** (case-insensitive substring). Choke point
  áp dụng: `CardPolicyLookupService` + guard `PolicyService::cloneTemplate` + `PolicyTemplate::applicableToCard()`.
- **Ngân hàng/slug hiện có:** `stb`(32), `mb`(43), `uob`(38), `vpb`(5), `shbvn`(35), `cakevpb`(41), `vib`(4).
- **Danh mục:** chỉ trỏ 19 danh mục gốc trong `CATEGORIES` (`CreditCardSeeder.php:146–166`) — KHÔNG trỏ các
  danh mục extra chỉ tồn tại ở DB live (vd `cua-hang-tien-loi`, `dien-may`, ...) vì sẽ lỗi trong test DB mới.
  Gộp về danh mục gần tương đương + chú thích (Sacombank tiện lợi → `sieu-thi-tien-loi`: `:1145–1146`).
- **Fallback:** mỗi bậc **đúng 1** fallback (`scope_type='other'`); dùng `ensureSingleFallback` để hợp nhất;
  `normaliseRuleOrder` để thứ tự = danh mục → fallback cuối.
- **Caps:**
  - Trần nhóm/kỳ ⇒ `max_cashback_per_period` của `PolicyTier` (seeder gọi là `cap`), kèm cờ
    `counts_toward_tier_cap` ở **từng rule** (bonus `true`, nền `false` khi nền "không giới hạn");
  - Trần/giao dịch ⇒ `max_cashback_per_transaction` (`cap_tx`);
  - Trần/từng danh mục/kỳ ⇒ `max_cashback_per_category_per_period` (`cap_cat`);
  - Bậc cao trước, thấp sau; giá trị decimal dạng string `'680000.00'`, `percent` `'6.800'`.
- **Min giao dịch** ⇒ `min_transaction_amount` trên rule/fallback (Sacombank = 100.000).
- **Điều kiện tổng chi tiêu/kỳ nằm ở từng bậc** (`min_total_spend` của `PolicyTier`), cột legacy
  `policy.min_total_spend` để `0`.
- **Không tạo combo mới** nếu không bắt buộc: nhóm "chọn-1-trong-N" tạm cấu hình thẳng danh mục mặc định
  (Flex → Ăn uống, Cake → Giáo dục) và để admin đổi thủ công trên màn hình.

### 3.2 8 template đã nghiên cứu & hiện diện (slug → cấu hình cốt lõi)

| slug | sort | bank/match | Nội dung chính |
|---|---|---|---|
| `sacombank-visa-platinum-cashback` | 50 | stb / `platinum cashback` | 1 bậc, siêu thị 6,8% + di chuyển 16,8%, fallback 0,39% (`counts_cap=false`), cap 680k, min tx 100k |
| `sacombank-platinum-amex` | 51 | stb / `platinum american express` | Du lịch (vmb+khách sạn) 20%, nước ngoài 10%, fallback 0,5 (`counts_cap=true`), cap 1.000.000 |
| `mb-jcb-platinum` | 52 | mb / `jcb platinum` | 2 bậc [0,5M) 5% / [5M,∞) 10%, cap 200k/500k, fallback 0% |
| `uob-one` | 53 | uob / `uob one` | Điểm 1:1 → %, cap tier 600k (proxy), fallback 0,3 (`counts_cap=false`) |
| `vpbank-flex` | 54 | vpb / `flex` | 2 bậc [0,2M) / [2M,∞), Ăn uống 8% cap 500k, fallback 0% |
| `shinhan-365-cashback-platinum` | 55 | shbvn / `365 cashback` | 2 bậc [0,3M) nền 0,4% / [3M,∞) siêu thị 10% + nước ngoài 3% (cap_cat 200k), tier cap 400k |
| `shinhan-mylife-visa-bach-kim` | 56 | shbvn / `mylife` | 2 bậc, hạng cao 4 nhóm × 10% (cap_cat 300k, `counts_cap=false`), nền 0,4% |
| `cake-2in1-premium` | 57 | cakevpb / `cake 2in1` | 2 bậc [0,20M) / [20M,∞), Giáo dục 20% (`cap_tx` 200k, `counts_cap=true`), tier cap 2.000.000 |

Chi tiết từng cấu hình + nguồn: `docs/CREDIT_CARD_NEW_TEMPLATES_RESEARCH_REPORT.md`.

---

## 4. Seeding: code ≠ dữ liệu live (bài học rút ra)

⚠️ **Quy tắc vàng:** thêm seeder vào code **không hề** tạo dữ liệu trên DB live. Màn hình admin đọc
`hoantien_creditcard` trực tiếp. Đợt đầu viết xong chỉ chạy trong test (`:memory:`) nên admin không thấy
template — phải chạy seed lên **DB thật** mới hiển thị.

### 4.1 Seed toàn bộ (an toàn, idempotent)

```bash
php artisan db:seed --class="Database\Seeders\CreditCardSeeder" --database=creditcard --force
```

- Idempotent, không ghi đè blueprint đã tồn tại, không tạo bản trùng.
- NHƯNG chạy cả `seedBanks()` → **`deactivateNonCanonicalBanks()`** có thể đổi `is_active` các bank không nằm
  trong `BANKS` canonical (hiện live đều canonical, nhưng cẩn thận mỗi lần chạy).

### 4.2 Seed "chỉ phần cần thiết" (khuyến nghị khi thêm template mới)

Khi chỉ thêm một nhóm template, chạy riêng đúng phương thức mới bằng reflection, bọc transaction — đây chính
là thao tác đã dùng để seed 8 template (đã xác minh: `before 21 → after 29`, idempotent khi chạy lại).

```php
<?php
// seed_only_researched.php — chạy: php seed_only_researched.php (CWD = project)
$project = __DIR__;
require $project.'/vendor/autoload.php';
$app = require_once $project.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CreditCard\Bank;
use App\Models\CreditCard\Category;
use Database\Seeders\CreditCardSeeder;
use Illuminate\Support\Facades\DB;

$banks      = Bank::query()->get()->keyBy('slug')->all();
$categories = Category::query()->where('scope', Category::SCOPE_SYSTEM)->get()->keyBy('slug')->all();
$seeder  = app(CreditCardSeeder::class);
$method  = new ReflectionMethod($seeder, 'seedResearchedTemplates');

DB::connection('creditcard')->transaction(fn () => $method->invoke($seeder, $banks, $categories));
echo "seeded OK\n";
```

Lưu ý: `keyBy()->all()` (method khai báo `array<string, ...>`, KHÔNG nhận Collection).

### 4.3 Kiểm tra "code đã có nhưng DB chưa" (chẩn đoán template mất tích)

Chạy kiểu truy vấn sau trên DB live (không ghi gì):

```php
$slugs = ['sacombank-visa-platinum-cashback','sacombank-platinum-amex','mb-jcb-platinum',
          'uob-one','vpbank-flex','shinhan-365-cashback-platinum',
          'shinhan-mylife-visa-bach-kim','cake-2in1-premium'];
foreach ($slugs as $s) {
    echo $s.' => ';
    $t = App\Models\CreditCard\PolicyTemplate::query()->where('slug', $s)->first();
    echo $t ? "scope={$t->scope} active={$t->is_active} blueprints={$t->blueprints()->count()}"
            : 'MISSING';
    echo PHP_EOL;
}
```

**Lưu ý khi khảo sát bằng script boot:** script phải nằm trong repo (hoặc `require` đường dẫn tuyệt đối tới
`vendor/autoload.php` + `bootstrap/app.php`) vì bootstrap dựa trên path; dùng path tuyệt đối cho chắc.

---

## 5. Kiểm thử template mới

Mẫu chuẩn: `tests/Feature/CreditCard/ResearchedTemplatesPolicyTest.php` (11 test / 702 assertions) — làm theo
`VibMaxCardVisaPolicyTest.php` (helper `makeCardWithTemplate`, `addTxn`, `snapshotPercent`, `snapshotAmount`).
Phạm vi test khuyến nghị:

1. **Cấu trúc** — đủ template, `scope=system`, `is_active`, đúng tier/rule/fallback (đếm per bậc).
2. **Idempotency** — chạy seeder 2 lần không trùng, không đè blueprint.
3. **Applicability** — thẻ đúng bank+keyword gắn được; lệch bank/keyword bị **từ chối** (qua
   `PolicyService::cloneUserTemplate` — choke point).
4. **Engine behavior** — từng tỷ lệ, cap (đủ loại), chuyển bậc đúng ngưỡng nửa mở, fallback, min tx
   (test thật bằng `CashbackCalculator`).
5. **Chốt các sản phẩm chờ** — `reporter_flagged_products_are_not_seeded` (VPBank Reward / Shinhan Cashback).

Khi thêm template mới: **cập nhật assertion đếm template hệ thống** trong các test tĩnh khác
(vd `ShinhanSupremePolicyTemplateTest` — đã nâng 9 → 17 khi thêm 8 template).

```bash
php artisan test --filter ResearchedTemplatesPolicyTest
vendor\bin\pint tests\Feature\CreditCard\ResearchedTemplatesPolicyTest.php
git diff --check
```

---

## 6. Quản trị trên màn hình

- Danh sách hệ thống: **Chính sách hoàn tiền hệ thống** `/admin/credit-card/policies` — liệt kê TẤT CẢ template
  `scope=system` (kể cả `is_active=0`), tìm kiếm theo tên/keyword mô tả/tên bank.
- Tạo mới / Sửa version: JSON API `/admin/credit-card/api/policies...` (store, storeVersion, updateVersion,
  setDefaultVersion, destroyVersion, clone). `scope_type='other'` là fallback, không hiện như danh mục bình thường.
- Editor dùng chung `resources/views/credit-card/partials/policy-editor.blade.php`.
- Admin chỉnh sửa → tạo **version N+1** (append-only); version cũ chỉ đóng `effective_to` + `superseded`;
  `defaultBlueprint()` quyết định bản dùng cho user mới. Khi clone/version must toàn qua `PolicyCloneService`.

---

## 7. Trạng thái DB live (đã xác minh 12/10/2026)

DB: `hoantien_creditcard` (connection `creditcard`). App DB: `hoantienaff`.

| Hạng mục | Giá trị |
|---|---|
| banks | 43 |
| categories system / user | 27 / 1 |
| combos system / user | 7 / 0 |
| **templates system / user** | **29 / 0** (blueprint rời đều đủ: `policies` `user_card_id IS NULL` = 29) |
| user_cards | 16 |
| mcc_profiles | 14 |
| migrations credit card | 41, tất cả `Ran` (batch 1–21) |
| 8 template nghiên cứu | id 22–29, `scope=system`, `is_active=1`, `blueprints=1`, sort 50–57 |

Giải thích 29 template system: 21 cũ (0–11, 30–33 VIB Max, 40–42 VIB One) + 8 mới (50–57).

---

## 8. Việc đang dở / hàng rào không được phá

- **Còn chờ owner xác nhận (KHÔNG tạo):** `vpbank-reward-visa`, `shinhan-cashback-visa`.
- **KHÔNG động vào** workstream MCC đang làm dở (liệt kê §0), plan combo
  `docs/CREDIT_CARD_CATEGORY_COMBO_NORMALIZATION_PLAN.md`, và module affiliate
  (`app/Services/CashbackCalculator.php` là affiliate — khác `app/Services/CreditCard/CashbackCalculator.php`).
- **KHÔNG reset/revert/stash/xoá** file chưa commit.
- **Không chạy seed đại trà** lên DB live nếu chỉ cần một phần; dùng §4.2.
- **Không thay đổi** thẻ user, giao dịch, snapshot, lịch sử chính sách, kỳ sao kê, template hiện hữu
  (trừ khi task yêu cầu rõ).

## 9. Chỉ mục các report liên quan (`docs/`)

- `CREDIT_CARD_NEW_TEMPLATES_RESEARCH_REPORT.md` — nghiên cứu 10 sản phẩm, quyết định mapping, 8 template.
- `CREDIT_CARD_SYSTEM_POLICY_ADMIN_REPORT.md` — kiến trúc admin + API + limiter + audit toàn bộ màn quản trị.
- `CREDIT_CARD_VIB_MAX_CARD_VISA_REPORT.md` / `CREDIT_CARD_PHASE_1C_FINAL_REPORT.md` / `PHASE_1B/1A_*` — lịch sử phase.
- `CREDIT_CARD_MCC_WORKFLOW.md` + các `CREDIT_CARD_MCC_*` — workstream MCC (mapping, profiles, row benefits).
- `CREDIT_CARD_CATEGORY_COMBO_NORMALIZATION_PLAN.md` — kế hoạch chuẩn hoá combo.
- `database-schema.md`, `CODEMAP.md`, `architecture.md`, `api-endpoints.md` — bản đồ tổng quan repo.