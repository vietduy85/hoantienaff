# Credit Card Phase 1A — Tài liệu triển khai

> Module Thẻ tín dụng, giai đoạn 1A (nền tảng dữ liệu + policy engine + cashback + UI tối thiểu).
> Tài liệu này mô tả **những gì đã được xây dựng thực tế**. Phần đối chiếu nghiệp vụ
> nằm ở `docs/CREDIT_CARD_PHASE_1A_ARCHITECTURE_REVIEW.md`.
>
> Trạng thái: **hoàn tất, chưa commit** (branch `main`, HEAD `0591755`).

---

## 1. Phạm vi 1A làm gì và không làm gì

### Làm

| Hạng mục | Kết quả |
| --- | --- |
| Tách database | DB riêng `hoantien_creditcard` qua connection `creditcard` |
| Schema | 10 bảng nghiệp vụ, migration riêng, không cross-DB FK |
| Model | 11 model namespaced, tự ghim connection |
| Policy | 4 policy Laravel, ownership + trạng thái bản ghi |
| Cashback | Engine RETROACTIVE, 3 cap, snapshot + audit |
| Kỳ sao kê | Suy ra kỳ từ `statement_day`, read-only khi xem |
| Import Excel | Đọc/validate/ghi có idempotency, từ chối cột cashback |
| UI | `/thetindung` hiển thị dữ liệu thật (không phải placeholder) |
| Test | 144 test Credit Card; full suite 1279 pass |

### Không làm (để phase sau)

- CRUD đầy đủ cho thẻ / giao dịch / policy (route `manage`, `settings`… vẫn là placeholder).
- Gọi API ngân hàng — module **không** phụ thuộc vào ngân hàng; bank chỉ là bảng tham chiếu do admin nhập.
- Xác thực loại thẻ (Visa/Mastercard), hạn mức động, thông báo hạn thanh toán qua queue/email.
- Dashboard so sánh nhiều thẻ, báo cáo tổng hợp.

---

## 2. Quyết định kiến trúc

### 2.1 Vì sao tách database

`users` nằm ở `hoantienaff`. Module Thẻ sinh dữ liệu khối lượng lớn và vòng đời riêng
(sao kê, giao dịch, policy), không nên kéo dài vòng đời bảng `users` và backup của nó.

Hệ quả bắt buộc: **không được tạo cross-database foreign key.** `user_id` trong DB
`creditcard` là *logical reference* — không có ràng buộc ở tầng DB, chỉ được bảo vệ ở tầng
application. Đây là đánh đổi được chấp nhận có chủ đích, và là lý do
`tests/Feature/CreditCard/ConnectionTest.php` tồn tại.

### 2.2 Vì sao có bảng legacy vẫn nằm ở DB chính

Bốn bảng `credit_card_banks`, `credit_card_categories`, `credit_cards`, `user_credit_cards`
(4 migration `2026_09_29_*`) là **WIP của phase trước**, đã chạy trên `hoantienaff`.
Chúng **được giữ nguyên**: không drop, không xoá model. Phase 1A chỉ chồng kiến trúc mới
lên cạnh, không cải tạo dữ liệu cũ.

⚠️ **Cảnh báo vận hành:** `credit_card_banks` và `credit_card_categories` tồn tại **cùng
tên ở cả hai database**. Đây là nguồn nhầm lẫn nguy hiểm nhất của module: một model
quên ghim connection sẽ đọc nhầm sang bảng rỗng ở DB chính, và triệu chứng là "dữ liệu
biến mất" chứ không phải lỗi kết nối. Vì vậy mọi model mới đều kế thừa
`CreditCardModel` (ghim connection) — không được tạo model credit card ngoài nhánh này.

### 2.3 Bảng legacy có được dùng lại không

Không. Controller, service và test của phase 1A **không** tham chiếu 4 model root
(`app/Models/CreditCard.php`, `CreditCardBank.php`, `CreditCardCategory.php`,
`UserCreditCard.php`). Chúng chỉ tồn tại để không mất WIP.

Xoá chúng cần owner quyết định riêng, vì sẽ phải xoá 4 migration đã chạy production.

---

## 3. Database và connection

```php
// config/database.php
'creditcard' => [
    'driver'   => env('DB_CREDITCARD_DRIVER', env('DB_CONNECTION', 'mysql')),
    'database' => env('DB_CREDITCARD_DATABASE', env('DB_DATABASE', 'hoantien_creditcard')),
    'host'     => env('DB_CREDITCARD_HOST',     env('DB_HOST', '127.0.0.1')),
    'port'     => env('DB_CREDITCARD_PORT',     env('DB_PORT', '3306')),
    'username' => env('DB_CREDITCARD_USERNAME', env('DB_USERNAME', 'root')),
    'password' => env('DB_CREDITCARD_PASSWORD', env('DB_PASSWORD', '')),
    // ...
],
```

Mọi biến `DB_CREDITCARD_*` **fallback về `DB_*`**. Nhờ đó `phpunit.xml` chỉ cần ép
`DB_CONNECTION=sqlite` + `:memory:` là connection `creditcard` tự trỏ về DB test mà
không cần khai báo thêm. Xem §10.

### Lệnh vận hành

```bash
php artisan credit-card:migrate --seed          # migrate + seed
php artisan credit-card:migrate --fresh --seed  # drop toàn bộ bảng creditcard rồi dựng lại
php artisan credit-card:migrate --pretend       # xem SQL, không ghi
php artisan credit-card:migrate:status          # trạng thái từng migration
```

`--fresh` **chỉ** drop các bảng trong `database/migrations/creditcard/`; không đụng
DB chính. Lưu ý nó xoá luôn dữ liệu user của module (thẻ, giao dịch, kỳ sao kê) — chỉ
dùng khi DB còn chỉ có dữ liệu seed.

Tương đương thủ công:

```bash
php artisan migrate --database=creditcard --path=database/migrations/creditcard --realpath
```

---

## 4. Schema (10 bảng)

| # | Bảng | Vai trò |
| --- | --- | --- |
| 1 | `credit_card_banks` | Ngân hàng phát hành (dữ liệu tham chiếu, admin nhập tay) |
| 2 | `credit_card_products` | Sản phẩm thẻ của bank |
| 3 | `credit_card_categories` | Danh mục chi tiêu; `scope` phân biệt hệ thống / riêng user |
| 4 | `credit_card_policy_templates` | Khuôn mẫu policy; `scope` = `system` \| `user` |
| 5 | `credit_card_user_cards` | Thẻ của user; chứa `statement_day`, `payment_due_day` |
| 6 | `credit_card_policies` | Gốc policy gắn với thẻ + `max_cashback_total_per_period` |
| 7 | `credit_card_policy_tiers` | Bậc chi tiêu; `version_no` + `root_policy_id` tạo chuỗi version |
| 8 | `credit_card_policy_tier_categories` | Rule: `%` + `spend_from/to` + cap + `min_transaction_amount` |
| 9 | `credit_card_statement_periods` | Kỳ sao kê suy ra từ `statement_day` |
| 10 | `credit_card_transactions` | Giao dịch + snapshot cashback + `calc_meta` audit |

### 4.1 Ba lớp dữ liệu trên `credit_card_transactions`

Cột được chia bạch có chủ đích để audit được:

- **A. SOURCE OF TRUTH** — `user_card_id`, `transaction_date`, `posted_date`, `statement_period_id`, `category_id`, `merchant`, `amount`, `note`
- **B. RESOLVED POLICY** — `policy_version_id`, `policy_tier_id`, `policy_tier_category_id`
- **C. CALCULATED** — `cashback_percent_snapshot`, `cashback_amount_snapshot`, `is_eligible`, `ineligible_reason`, `calc_basis`, `calc_meta`, `calculated_at`

### 4.2 Vì sao không có `calculation_date`

Điểm dữ liệu chuẩn hoá tất cả truy vấn theo **ngày thực hiện giao dịch**
(`transaction_date`), kết hợp `statement_period_id`. Cột riêng `calculation_date` sẽ
tạo khả năng hai nguồn sự thật lệch nhau. `calc_basis` chỉ lưu *ngày dùng để xác định
kỳ* theo `statement_date_basis` của thẻ — phục vụ audit, không phải nguồn truy vấn.

### 4.3 Ba cap

| Cap | Cột | Ý nghĩa |
| --- | --- | --- |
| 1 | `max_cashback_per_transaction` | Trần mỗi giao dịch |
| 2 | `max_cashback_per_category_per_period` | Trần mỗi danh mục trong một kỳ |
| 3 | `max_cashback_total_per_period` (ở `credit_card_policies`) | Trần tổng một kỳ |

Cap 2 và 3 cộng dồn theo thứ tự cố định (1 → 2 → 3) vì chúng cùng tiêu một hạn mức;
`calc_meta.caps` ghi lại cap nào đã chạm để giải thích con số cuối.

### 4.4 `category_id` dùng `RESTRICT`

Danh mục đã dùng cho giao dịch thì **không xoá cứng được**. Xoá loại sẽ làm mất lịch sử
giao dịch. Muốn ngừng dùng thì `is_active = false` (danh mục vẫn hiện với giao dịch cũ).
Cùng cơ chế cho `credit_card_policy_tier_categories.category_id`.

> Migration `000010` ban đầu khai báo `nullOnDelete()`; đã sửa thành `restrictOnDelete()`.
> Vì migration đã chạy lên DB thật, phải chạy `--fresh --seed` mới áp dụng được —
> **đã thực hiện và kiểm chứng** (MySQL trả lỗi 1451 khi xoá danh mục đang được dùng).

### 4.5 Mốc thời gian

| Cột | Ý nghĩa |
| --- | --- |
| `transaction_date` | Ngày user thực hiện giao dịch (nguồn sự thật) |
| `posted_date` | Ngày ngân hàng ghi nhận — **có thể NULL**, vẫn chưa biết lúc nhập |
| `statement_date` | Ngày chốt kỳ, suy ra từ `statement_day` |
| `payment_due_date` | Hạn thanh toán, suy ra từ `payment_due_day` |
| `spending_deadline_day` | **Chỉ là ngày nhắc**, không tham gia xác định kỳ |

---

## 5. Model

Tất cả kế thừa `App\Models\CreditCard\CreditCardModel`, khai báo `protected $connection = 'creditcard'`.

`Bank`, `Product`, `Category`, `PolicyTemplate`, `UserCard`, `Policy`, `PolicyTier`,
`PolicyTierCategory`, `PolicyVersion`, `StatementPeriod`, `Transaction`.

### 5.1 Cross-DB: `UserCard::user()`

Quan hệ sang `users` (DB chính) **không dùng `belongsTo()` mặc định**. Nếu không, Eloquent
thừa kế connection `creditcard` và sinh SQL đọc `users` trong `hoantien_creditcard` — sai
DB. Sử dụng `newBelongsTo()` kèm `CreditCardModel::mainDatabaseConnectionName()`:

```php
public function user(): BelongsTo
{
    return $newBelongsTo($this->user_id, (new User)->setConnection(
        self::mainDatabaseConnectionName()
    )->getTable(), 'id');
}
```

Phát hiện nhờ `CreditCardModuleTest` — test assert `$user->is($userCard->user)`, tức buộc
Eloquent **thực sự query**, nên lỗi connection hiện ra ngay thay vì chỉ so sánh id trong RAM.

### 5.2 `UserCard::bank()`

`bank` không nằm trên bảng thẻ mà đi qua `product`:

```php
return $this->hasOneThrough(
    Bank::class, Product::class, 'id', 'id', 'product_id', 'bank_id'
);
```

Bản đầu dùng nhầm `localKey`/`secondLocalKey` nên sinh SQL sai; test `credit card models
and relationships work` bắt được.

### 5.3 `Category::scopeSelectableBy()`

Luôn phải scope khi hiển thị/import danh mục:

```php
->where('is_active', true)
->where(fn ($q) => $q->where('scope', 'system')
    ->orWhere(fn ($u) => $u->where('scope', 'user')->where('owner_user_id', $userId)));
```

Bỏ scope này là lộ danh mục riêng của người khác. Có test riêng cho import.

---

## 6. Phân quyền

Bốn policy tại `app/Policies/CreditCard/`, được Laravel 12 auto-discover nhờ model
`App\Models\CreditCard\*` khớp `App\Policies\CreditCard\*Policy`.

| Policy | Quy tắc |
| --- | --- |
| `UserCardPolicy` | `viewAny`/`create`: mọi user. `view`/`update`/`delete`: **chỉ chủ thẻ** |
| `TransactionPolicy` | `view`: chủ thẻ. `update`/`delete`: chủ thẻ **và** kỳ chưa `finalized` |
| `PolicyPolicy` | `view`: chủ thẻ. `update`: chủ thẻ, bản ghi chưa `superseded`. `delete`: chỉ khi `draft` |
| `PolicyTemplatePolicy` | `system`: ai cũng xem, chỉ admin sửa/xoá. `user`: chỉ chủ template. `is_builtin` **không bao giờ xoá** |

Hai điểm cố ý gây khó chịu cho người dùng nhưng đúng nghiệp vụ:

1. **Admin không tự động có quyền trên thẻ của user khác.** Quyền trên dữ liệu cá nhân
   bám theo chủ sở hữu, không bám theo role. Có test khẳng định điều này.
2. **Bản ghi đã chốt là bất biến.** Kỳ `finalized` và policy `superseded` là lịch sử tài
   chính; sửa chúng làm hỏng số liệu đã báo cáo.

### 6.1 Ownership guard ở tầng service

Policy chỉ bảo vệ HTTP. Service phải tự kiểm tra vì có thể bị gọi từ import, queue, CLI:

- `StatementPeriodService::assignTransactionToPeriod()` — chặn transaction không thuộc thẻ.
- `CashbackRecordService::calculateTransaction()` — kiểm tra **trước mọi DB write**.

Cả hai đều có test khẳng định foreign card bị từ chối.

---

## 7. Cashback engine

### 7.1 RETROACTIVE, không progressive

Tier được chọn bằng **tổng chi tiêu eligible cả kỳ**. Tỷ lệ áp dụng cho **mọi** giao dịch
trong kỳ, kể cả giao dịch đã phát sinh trước khi tổng vượt ngưỡng. Quyết định này rút ra
từ yêu cầu: cashback phải **ổn định** — không được để con số của giao dịch tháng trước
nhảy khi kỳ đó kết thúc.

Hệ quả bắt buộc: cashback **không bất biến ngay lúc nhập giao dịch**. Khi kỳ còn
`open`, snapshot có thể tính lại. Khi `finalized` thì đóng băng. Luật của version chính
lại bất biến (`effective_from`/`effective_to`), nên chỉ có **nguồn tiêu** là động.

### 7.2 Thứ tự tính

1. Sàng lọc giao dịch eligible theo rule khớp category (không tính cap ở bước này).
2. Tổng eligible spend cả kỳ → chọn tier.
3. Với mỗi category, rate theo tổng chi tiêu của **chính category đó** trong kỳ.
4. Tính cashback từng giao dịch.
5. Áp cap theo thứ tự 1 → 2 → 3, ghi vào `calc_meta.caps`.

### 7.3 Đa rule trong một category

Một category có thể có nhiều rule khác `spend_from`. Xử lý:

- Sàng lọc theo **mức sàn nhỏ nhất** trong category (rule dễ kích hoạt nhất) để tìm
  ứng viên, rồi **kiểm tra lại `min_transaction_amount` của đúng rule được chọn**.
- Nếu không có rule nào thoả, giao dịch không eligible — **không** rơi vào rule khác
  chỉ vì rule đó không liên quan.

Hai test khóa hành vi này (`selected rule min enforced`, `unrelated rule does not
disqualify`).

### 7.4 Rounding

Làm tròn về cuối cùng một lần theo `rounding_mode` của version, không làm tròn từng tầng
— tránh trôi số tích luỹ.

### 7.5 Cashback không nhập tay

Cashback **luôn** tính toán. Không có cột nào cho phép user tự điền, và file Excel có
cột cashback bị từ chối ở tầng đọc file (xem §8).

---

## 8. Import Excel

Ba lớp, mỗi lớp một trách nhiệm:

| Lớp | Trách nhiệm |
| --- | --- |
| `TransactionSheetReader` | Đọc + map header. **Không** validate nghiệp vụ, **không** ghi DB |
| `TransactionImportService` | Validate từng dòng, resolve danh mục, ghi, gọi cashback pipeline |
| `TransactionImportResult` | Số liệu + lỗi theo từng dòng |

### 8.1 Map cột theo tên header, không theo vị trí

Sao kê từ nhiều ngân hàng khác nhau về thứ tự cột. Header chấp nhận tiếng Việt lẫn tiếng
Anh, không dấu/không dấu, không phân biệt hoa thường (`Ngày giao dịch` ≡ `ngay giao dich`).
Cột bắt buộc: **ngày giao dịch** và **số tiền**.

### 8.2 Từ chối cột cashback

Nếu header chứa `cashback`, `hoan tien`, `tien hoan` ⇒ **từ chối cả file**, ném
`RuntimeException`. Danh sách cố tình chỉ gồm từ không thể trùng tên cột hợp lệ — đã
bỏ `'bac'` và `'tier'` sau khi nhận ra chúng gây từ chối oan ("Bắc Giang", "bác sĩ").

### 8.3 Dòng sai không làm hỏng cả file

Mỗi dòng validate độc lập. Dòng lỗi được đếm và báo kèm số dòng + tên cột; các dòng
hợp lệ vẫn được ghi. Dòng trống hoàn toàn bị bỏ qua.

### 8.4 Idempotency

`source_reference` = `"<tên file>#<số dòng>"`, ví dụ `sao-ke-thang-10.xlsx#2`. Nạp lại
cùng file ⇒ mọi dòng `skipped`, không tạo trùng. Đổi tên file ⇒ coi là nguồn khác, vẫn
nhập. Cột này cũng là câu trả lời cho "giao dịch này tôi import từ đâu?".

### 8.5 Số tiền

Chấp nhận cả hai quy ước: kiểu Việt (`1.000.000`, `1.000.000,50`) và kiểu Anh
(`1,000,000.50`); dấu âm qua `-` hoặc ngoặc `(150.000)`. Quy tắc phân biệt dấu thập phân
với dấu phân cách nghìn: có cả hai thì dấu bên phải cùng là thập phân; chỉ có một dấu và
sau nó đúng 3 chữ số thì là phân cách nghìn (`1.000` = 1000, không phải 1.0).

### 8.6 Ngày

`hasFormat` chứ không phải `createFromFormat`: app bật Carbon strict mode nên
`createFromFormat` **ném exception** thay vì trả `false` khi dữ liệu sai — code ban đầu
dự đỏng `false` và làm hỏng 9 test.

### 8.7 An toàn

- Toàn bộ ghi nằm trong **một** transaction DB ⇒ lỗi giữa chừng không để lại kỳ nửa vời.
- Danh mục resolve qua `scopeSelectableBy` ⇒ không import được vào danh mục của user khác.
- Chỉ nhận `.xlsx`; `.csv` ném exception có thông báo rõ.
- Cashback và kỳ sao kê **không** lấy từ file; kỳ do `StatementPeriodService` suy ra
  từ `statement_day`, cashback do `CashbackRecordService` tính.

---

## 9. Kỳ sao kê

```php
$periods->boundariesForDate($card, $date); // thuần toán, không query
$periods->findForDate($card, $date);       // chỉ đọc, KHÔNG tạo
$periods->assignTransactionToPeriod($tx); // chỉ khi kỳ còn open
```

- `boundariesForDate()` không chạm DB — dùng cho deadline reminder.
- `findForDate()` **chỉ tìm**, không insert. Phân biệt này là lý do tồn tại test
  `index does not create statement periods`: trang `/thetindung` mở ra không được tạo
  rác trong DB.
- Tạo kỳ chỉ xảy ra khi có giao dịch thật đi qua `calculateTransaction()`.

---

## 10. Controller và UI

```php
Route::prefix('thetindung')->name('credit-cards.')->group(function () {
    Route::get('/',             [CreditCardController::class, 'index'])->name('index');
    Route::get('/quan-ly-the',  [CreditCardController::class, 'manage'])->name('manage');
    Route::get('/danh-muc',     [CreditCardController::class, 'categories'])->name('categories');
    Route::get('/bao-cao',      [CreditCardController::class, 'reports'])->name('reports');
    Route::get('/so-sanh',      [CreditCardController::class, 'compare'])->name('compare');
    Route::get('/cai-dat',      [CreditCardController::class, 'settings'])->name('settings');
});
```

Sáu route giữ nguyên URL cũ. `index` là trang thật; năm route còn lại là placeholder có
chủ đích để không phá link cũ.

`index` scope `UserCard::ownedBy(auth()->id())`, eager-load `product.bank`, hiển thị tổng
thẻ, tổng hạn mức, cashback kỳ hiện tại, ngày chốt, hạn thanh toán và cảnh báo hạn.

Chrome giao diện dùng class component `App\View\Components\CreditCard\Layout` (view
`credit-card.layout`) với tham số `title`, `subtitle`, `active`, để sáu route dùng chung
khung. Cả sáu view đều bọc trong `<x-credit-card.layout>`, và test của `index` render thật
nên component được bảo vệ bởi test. Năm view placeholder còn lại chưa có test render.

Giới hạn đã biết: ô "kỳ hiện tại" lấy theo **thẻ đầu tiên** theo `id`. Nhiều thẻ thì
mỗi thẻ có kỳ riêng nhưng UI 1A hiển thị một kỳ đại diện; cảnh báo hạn thì đã tính cho
từng thẻ. Xử lý ở phase sau.

---

## 11. Seeder

`php artisan credit-card:migrate --seed` — idempotent, đã chạy 3 lần cho cùng kết quả:

| Bảng | Số dòng |
| --- | --- |
| `credit_card_banks` | 5 |
| `credit_card_products` | 5 |
| `credit_card_categories` | 8 |
| `credit_card_policy_templates` | 1 |
| `credit_card_policies` | 1 |
| `credit_card_policy_tiers` | 2 (tier 1: 6 rule, tier 2: 7 rule) |
| `credit_card_policy_tier_categories` | 13 |
| `credit_card_user_cards` | 0 |
| `credit_card_statement_periods` | 0 |
| `credit_card_transactions` | 0 |

Chỉ dữ liệu tham chiếu. Không seed thẻ, kỳ hay giao dịch.

---

## 12. Kiểm thử

### 12.1 Harness hai connection

`tests/Concerns/InteractsWithCreditCardDatabase` chạy migration trên **cả hai** connection
rồi rollback. `connectionsToTransact()` khai báo cả `creditcard` lẫn connection chính, vì
`CreditCardModuleTest` cần đọc bảng legacy ở DB chính để chứng minh chúng còn nguyên.

### 12.2 Vì sao ép SQLite

`phpunit.xml` ép `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`. `DB_CREDITCARD_*` fallback
theo nên connection `creditcard` cũng về `:memory:`.

⚠️ **SQLite khác MySQL:** cột `DATE` trả về chuỗi `"YYYY-MM-DD 00:00:00"`. Mọi truy vấn
theo ngày phải dùng `whereDate()` — so sánh chuỗi thẳng sẽ hỏng trên SQLite nhưng lại
chạy đúng trên MySQL, tức lỗi chỉ lộ ra trên CI. Đây là điểm dễ tái phát nhất khi thêm
truy vấn mới.

### 12.3 Kết quả

| Phạm vi | Kết quả |
| --- | --- |
| `tests/Feature/CreditCard` | 98 pass, 927 assertions |
| `tests/Unit/CreditCard` | 26 pass, 68 assertions |
| `tests/Feature/CreditCardModuleTest` | 20 pass, 205 assertions |
| **Tổng Credit Card** | **144 pass, 1200 assertions** |
| **Full suite** | **1279 pass, 3 fail, 5284 assertions** |

Ba lỗi của full suite **có sẵn từ trước phase 1A** và không liên quan module (Auth,
ShopeeFood, TikTok):

1. `Tests\Feature\Auth\RegistrationTest > new users can register`
2. `Tests\Feature\Services\ShopeeFood\ShopeeFoodOrderSyncServiceTest > cookie guard fails fast without http`
3. `Tests\Feature\TikTokSyncPhase3Test > preexisting credited order is not credited again by admin`

Baseline trước khi thay đổi: 1153 pass / 3 fail / 4265 assertions. Nay là
1279 pass / **cùng 3 fail** ⇒ không có regression nào; chênh lệch +126 test là do phần
mới thêm.

### 12.4 Test nào bắt được bug thật

| Test | Bug phát hiện |
| --- | --- |
| `credit card models and relationships work` | `UserCard::bank()` map khoá `hasOneThrough` sai |
| `credit card data is scoped to the authenticated user` | `UserCard::user()` query nhầm DB |
| `index does not create statement periods` | Rủi ro GET ghi DB |
| `unrelated rule does not disqualify` | Sàng lọc rule quá rộng ⇒ mất cashback hợp lệ |
| `a superseded policy version cannot be edited even by its owner` | Bất biến bản ghi lịch sử |
| `it cannot import into another users category` | Rò danh mục riêng qua import |

---

## 13. Lệnh kiểm tra

```bash
php artisan test tests\Feature\CreditCard tests\Unit\CreditCard tests\Feature\CreditCardModuleTest.php
php artisan test                                   # full suite
php artisan credit-card:migrate:status
php -l app\Services\CreditCard\TransactionImportService.php
```

---

## 14. Điểm còn nợ kỹ thuật

| # | Vấn đề | Mức độ | Ghi chú |
| --- | --- | --- | --- |
| 1 | 4 bảng legacy + 4 model root còn lại | Thấp | Cần owner quyết định xoá; phải xoá migration đã chạy production |
| 2 | UI 1A hiện kỳ của thẻ đầu tiên | Thấp | Đúng khi 1 thẻ; phase sau cần per-card |
| 3 | Import chỉ nhận `.xlsx` | Thấp | `.csv` đã có thông báo lỗi rõ ràng |
| 4 | 3 lỗi test nền (Auth/ShopeeFood/TikTok) | Ngoài phạm vi | Cần fix riêng, không thuộc phase 1A |
| 5 | Nhập Excel dòng rất lớn giữ nguyên trong RAM | Thấp | Chấp nhận được ở 1A; phase sau cần chunk + queue |
| 6 | Không có `csv` theo hàng/ký hiệu cột cố định | Thấp | Có thể thêm file mẫu download ở phase sau |

---

## 15. Rollback

Phase 1A **chưa commit**, nên rollback cơ bản là bỏ file untracked và revert 6 file đã
sửa (`.env.example`, `app/Models/User.php`, `config/database.php`, `phpunit.xml`,
`resources/views/layouts/navigation.blade.php`, `routes/web.php`).

```bash
git checkout -- .env.example app/Models/User.php config/database.php \
                phpunit.xml resources/views/layouts/navigation.blade.php routes/web.php
git clean -nd   # xem trước các file untracked sẽ bị xoá
```

Xoá DB `creditcard` hoàn toàn:

```bash
mysql -u root -e "DROP DATABASE hoantien_creditcard;"
```

Bốn bảng legacy ở DB chính **không bị đụng** bởi bất kỳ thao tác nào ở trên.
