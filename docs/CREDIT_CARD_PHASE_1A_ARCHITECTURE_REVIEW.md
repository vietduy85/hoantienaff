# CREDIT CARD MODULE — PHASE 1A ARCHITECTURE REVIEW

> Ngày audit: 2026-09-29
> Branch: `main` (còn thay đổi chưa commit từ phiên trước)
> Phạm vi: **CHỈ ĐỌC — KHÔNG CODE, KHÔNG MIGRATE, KHÔNG COMMIT**
> Source of truth: code hiện tại trong repo + DB `hoantienaff` (MySQL local)

---

## 0. TÓM TẮT ĐIỀU HÀNH

Phiên trước **ĐÃ triển khai và ĐÃ migrate** một skeleton 4 bảng vào database chính `hoantienaff`.
Skeleton đó chạy được, test pass 18/18, UI không phá gì — nhưng **domain model sai ở mức cấu trúc**,
không phải sai ở mức chi tiết.

| Vấn đề | Mức độ |
|---|---|
| `credit_card_categories` đang dùng sai nghĩa (loại thẻ, không phải danh mục chi tiêu) | **Blocker** |
| Không có bất kỳ khái niệm nào về policy / template / version / tier / quota | **Blocker** |
| Không có statement period, không có transaction | **Blocker** |
| `user_credit_cards` có physical FK `user_id → users.id` — không tương thích DB riêng | **Blocker** |
| `user_credit_cards` thiếu "tên thẻ do user đặt" | Cao |
| Module nằm chung DB chính, chưa tách connection | Cao |
| Migration của CC nằm chung chuỗi migrate của DB chính | Cao |

**Khuyến nghị:** giữ nguyên toàn bộ UI/route/navigation/test hiện có, **thay thế** 4 bảng cũ bằng
schema mới 10 bảng trên connection `creditcard` (DB `hoantien_creditcard`). Bảng cũ để nguyên
(0 dòng dữ liệu), dọn ở Phase 1B.

---

# 1. PROJECT / ENVIRONMENT

| Hạng mục | Giá trị |
|---|---|
| Framework | **Laravel 12** (`laravel/framework ^12.0`) |
| PHP | `^8.2` (require) |
| Database driver | **MySQL** — `DB_CONNECTION=mysql` |
| Main DB | **`hoantienaff`** (đã tồn tại, đang dùng, có dữ liệu thật) |
| Credit Card DB | **CHƯA TỒN TẠI** — không có `hoantien_creditcard` trong `SHOW DATABASES` |
| Connection thứ 2 | **CHƯA CÓ** — `config/database.php` chỉ có `sqlite, mysql, mariadb, pgsql, sqlsrv` |
| Model nào khai báo `$connection`? | **KHÔNG** — grep toàn `app/` không có `protected $connection` |
| Auth | Laravel Breeze (session) + Socialite (Google) + Spatie Permission |
| Test DB | **sqlite `:memory:`** (`phpunit.xml`), KHÔNG dùng MySQL |
| Frontend | Vite + Tailwind 4, Blade class component (`x-app-layout`, `x-credit-card.layout`) |
| Design system | `docs/ui/design-system.md` — mobile-first, Be Vietnam Pro, emerald/amber/blue/red/gray |
| Current branch | `main` |
| APP_URL | `https://hoantien.xyz` |

### Git status (đọc, không thay đổi)

```
On branch main — up to date with 'origin/main'

Modified (chưa commit):
  M app/Models/User.php                                  (+9   : thêm userCreditCards())
  M resources/views/layouts/navigation.blade.php         (+38  : tab Thẻ tín dụng × 3 vị trí)
  M routes/web.php                                        (+12  : group thetindung)

Untracked:
  ?? app/Http/Controllers/CreditCard/
  ?? app/Models/CreditCard.php
  ?? app/Models/CreditCardBank.php
  ?? app/Models/CreditCardCategory.php
  ?? app/Models/UserCreditCard.php
  ?? app/View/Components/CreditCard/
  ?? database/migrations/2026_09_29_00000{1..4}_*.php
  ?? resources/views/credit-card/
  ?? tests/Feature/CreditCardModuleTest.php
```

**Lưu ý nguy hiểm:** 4 migration CC **đã được chạy trên MySQL `hoantienaff`** (bảng `migrations`
xác nhận đủ 4 record). Tức là schema CC đã "chạm" vào DB chính dù code chưa commit.
Phase 17 cần xử lý đúng điều này.

---

# 2. CURRENT CREDIT CARD MODULE

## 2.1 Routes — `routes/web.php:99-109`

Đăng ký trong `Route::middleware('auth')` (KHÔNG có `verified`), prefix `thetindung`, name `credit-cards.`:

| Name | URI | Controller@method |
|---|---|---|
| `credit-cards.index` | `thetindung` | `CreditCardController@index` |
| `credit-cards.manage` | `thetindung/quan-ly-the` | `CreditCardController@manage` |
| `credit-cards.categories` | `thetindung/danh-muc` | `CreditCardController@categories` |
| `credit-cards.reports` | `thetindung/bao-cao` | `CreditCardController@reports` |
| `credit-cards.compare` | `thetindung/so-sanh` | `CreditCardController@compare` |
| `credit-cards.settings` | `thetindung/cai-dat` | `CreditCardController@settings` |

Đã verify bằng `php artisan route:list --path=thetindung` → 6 route, đúng URI/name.
Không route nào ghi (POST/PUT/DELETE). Không route nào đè lên route hiện tại.

## 2.2 Controllers

`app/Http/Controllers/CreditCard/CreditCardController.php` (80 dòng)

- `index()` — query thật `UserCreditCard::where('user_id', auth()->id())->with('creditCard.bank')`,
  trả `totalCards` + `totalLimit`. Không hard-code số giả.
- `manage() / categories() / reports() / compare() / settings()` — return view placeholder.

**Không có FormRequest, không có Policy, không có Resource, không có Service nào.**

## 2.3 Models (4)

| File | Table | Ghi chú |
|---|---|---|
| `app/Models/CreditCard.php` | `credit_cards` | **Thực chất là PRODUCT CATALOG** (MB JCB Ultimate) — đặt tên sai |
| `app/Models/CreditCardBank.php` | `credit_card_banks` | Đúng |
| `app/Models/CreditCardCategory.php` | `credit_card_categories` | **Sai nghĩa domain** — là "loại thẻ", không phải danh mục chi tiêu |
| `app/Models/UserCreditCard.php` | `user_credit_cards` | Thiếu tên thẻ do user đặt |

Đã thêm vào `app/Models/User.php:155-158`:

```php
public function userCreditCards(): HasMany { return $this->hasMany(UserCreditCard::class, 'user_id'); }
```

Có `scopeActive()` trên `CreditCard` + `UserCreditCard`. Fillable/casts khai báo đúng kiểu.
`UserCreditCard::$hidden` chặn serialize `card_number_last4` — **điểm tốt, giữ nguyên**.

## 2.4 Migrations (4, đã chạy)

| File | Tạo bảng |
|---|---|
| `2026_09_29_000001_create_credit_card_banks_table.php` | `credit_card_banks` |
| `2026_09_29_000002_create_credit_card_categories_table.php` | `credit_card_categories` |
| `2026_09_29_000003_create_credit_cards_table.php` | `credit_cards` |
| `2026_09_29_000004_create_user_credit_cards_table.php` | `user_credit_cards` |

Tất cả dùng `Schema::create` (connection mặc định = `mysql`/`hoantienaff`).
**Số dòng dữ liệu: 0 / 0 / 0 / 0** (đã verify bằng `SELECT COUNT(*)`).

## 2.5 Views (9 file)

```
resources/views/credit-card/
├── layout.blade.php                 x-app-layout wrapper, grid content|sidebar
├── index.blade.php                  4 KPI card + danh sách thẻ (query thật)
├── manage.blade.php                 placeholder + "Chưa có dữ liệu"
├── categories.blade.php             placeholder
├── reports.blade.php                placeholder + 2 KPI giả ("—")
├── compare.blade.php                placeholder
├── settings.blade.php               placeholder
└── partials/
    ├── sidebar.blade.php            menu 6 mục, mobile ngang cuộn ngang + desktop cột phải
    ├── module-info.blade.php        box "Giai đoạn 1"
    └── placeholder.blade.php        component tái sử dụng
```

## 2.6 Components

`app/View/Components/CreditCard/Layout.php` — class component, props `title/subtitle/active`,
render `credit-card.layout`. Đúng convention class-component của Breeze.

## 2.7 CSS / JS

**KHÔNG CÓ.** Module dùng 100% Tailwind utility class, không thêm rule nào vào
`resources/css/app.css` hay `resources/js/app.js`. Tuân thủ design system.

## 2.8 Services

**KHÔNG CÓ.** Không có `app/Services/CreditCard/`.

> ⚠️ **Rủi ro đặt tên:** repo đã có `app/Services/CashbackCalculator.php` cho **affiliate**.
> Không được tạo `App\Services\CashbackCalculator` cho thẻ tín dụng. Bắt buộc sub-namespace.

## 2.9 Database (đã tồn tại trên MySQL `hoantienaff`)

```
credit_card_banks        0 dòng
credit_card_categories   0 dòng
credit_cards             0 dòng
user_credit_cards        0 dòng
```

`user_credit_cards` DDL thực tế:

```sql
`user_id` bigint unsigned NOT NULL,
`credit_card_id` bigint unsigned NOT NULL,
CONSTRAINT `user_credit_cards_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
```

→ **Physical cross-table FK đang tồn tại.** Khi tách sang DB riêng, FK này không thể tồn tại.

## 2.10 Navigation

`resources/views/layouts/navigation.blade.php` — thêm 3 entry:

1. `:23-31` tab desktop trong hàng nav, `hidden xl:inline` (có comment giải thích: hàng nav hiện tại
   rộng ~831px, đã tràn ở `<880px`, thêm tab làm tràn thêm ở 1024px → chỉ hiện `>=1280px`)
2. `:115-117` dropdown "Tài khoản"
3. `:212-214` menu responsive (hamburger)

Active state dùng `request()->routeIs('credit-cards.*')` + `aria-current="page"`.

## 2.11 Tests

`tests/Feature/CreditCardModuleTest.php` — 18 test, **181 assertions, PASS 100%** (đã chạy:
`php artisan test --filter=CreditCardModuleTest` → 18 passed, 5.07s).

Phủ: route registry, không đè route cũ, auth, render 6 trang, sidebar active state, tab nav,
layout kế thừa, schema 4 bảng, model relationship, user scoping, tổng hạn mức, regression auth.

---

# 3. CURRENT UI

| # | Hạng mục | Trạng thái | Ghi chú |
|---|---|---|---|
| 1 | Tab "💳 Thẻ tín dụng" trên nav (desktop) | ✅ có | `hidden xl:inline`, có comment lý do |
| 2 | Entry trong dropdown "Tài khoản" | ✅ có | |
| 3 | Entry trong menu hamburger | ✅ có | |
| 4 | Module shell `<x-credit-card.layout>` | ✅ có | Kế thừa `x-app-layout`, không tạo layout riêng cho site |
| 5 | Sidebar module 6 mục (mobile ngang + desktop cột phải) | ✅ có | Responsive xử lý tốt, không vỡ iPhone |
| 6 | Placeholder component dùng chung | ✅ có | |
| 7 | Box "Giai đoạn 1" | ✅ có | Chỉ text |
| 8 | Dashboard: 4 KPI card | ⚠️ một nửa | 2 card có số thật, 2 card là "—" |
| 9 | Dashboard: danh sách thẻ (tên + ngân hàng) | ⚠️ thiếu | Không hiện `****last4`, hạn mức, kỳ sao kê |
| 10 | Nút "+ Thêm thẻ" | ⚠️ dead-end | Trỏ tới `manage` nhưng `manage` chỉ là placeholder |
| 11 | Trang Quản lý thẻ | ❌ placeholder | |
| 12 | Trang Danh mục | ❌ placeholder | Subtitle sai nghĩa domain |
| 13 | Trang Báo cáo | ❌ placeholder | |
| 14 | Trang So sánh thẻ | ❌ placeholder | |
| 15 | Trang Cài đặt | ❌ placeholder | |
| 16 | UI Policy / Template / Tier / Category rule | ❌ chưa có | |
| 17 | UI nhập giao dịch | ❌ chưa có | |
| 18 | UI kỳ sao kê | ❌ chưa có | |

---

# 4. CURRENT DATABASE DESIGN

```
hoantienaff (DB chính)
┌──────────────┐        ┌─────────────────────┐
│    users     │        │ credit_card_banks   │  system master
│  (id, email, │        │ id, name, slug,     │
│   wallet_*)  │        │ logo, is_active     │
└──────┬───────┘        └──────────┬──────────┘
       │ ① FK CASCADE              │ ② FK SET NULL
       │                           │
       ▼                           ▼
┌──────────────────────────┐   ┌──────────────────────────┐
│  user_credit_cards       │   │      credit_cards        │  ← THỰC CHẤT LÀ
│  id                     │   │  id                      │    "PRODUCT"
│  user_id          ──────┼───┤  bank_id            ─────┼──►
│  credit_card_id   ──────┼───┤  category_id  ◄──────┐  │
│  card_number_last4      │   │  name, slug, image     │  │
│  credit_limit           │   │  annual_fee, desc      │  │
│  statement_day          │   └────────────────────────┘  │
│  payment_due_day        │            ▲                  │
│  is_active              │            │ FK CASCADE       │
└──────────────────────────┘            │                  │
                                        │                  │
                          ┌─────────────┴──────────────────┴─────┐
                          │  credit_card_categories              │
                          │  id, name, slug, description,        │
                          │  is_active                           │
                          │  ⚠ "Loại thẻ" — SAI NGHĨA DOMAIN     │
                          └───────────────────────────────────────┘

❌ THIẾU HOÀN TOÀN:
   policy template · policy · policy version · tier · tier category rule
   statement period · transaction · cashback snapshot · quota
```

**Quan hệ hiện tại chỉ có 4 node.** Toàn bộ domain cashback (mục 8–11 của yêu cầu nghiệp vụ)
chưa tồn tại.

---

# 5. CURRENT ARCHITECTURE PROBLEMS

### P1 — `credit_card_categories` sai nghiĩa domain *(Blocker)*

Bảng này được dùng làm **"loại thẻ"** (`credit_cards.category_id` → "Thẻ hoàn tiền"), và UI gọi
nó là *"Danh mục thẻ tín dụng và ngân hàng phát hành"*.

Nhưng nghiệp vụ yêu cầu Category = **danh mục chi tiêu**: Online, Dining, Supermarket, Fuel,
Travel — và mỗi category mang `cashback_percent`, quota, rule theo tier.

→ Tên bảng khớp, nghĩa không khớp. Nguy hiểm vì ai đọc tên bảng sẽ hiểu nhầm.
**Phải tái định nghĩa bảng này.**

### P2 — Không có khái niệm Policy / Template / Version / Tier *(Blocker)*

0 bảng. Không có nơi lưu: chính sách hoàn tiền, template system/user, version (lịch sử policy),
tier theo tổng chi tiêu, cashback % / cap / quota theo từng category trong từng tier,
minimum spend.

→ Không thể tính cashback. Đây là 80% giá trị của module.

### P3 — Không có Statement Period / Transaction *(Blocker)*

Không có nơi lưu kỳ sao kê, không có nơi lưu giao dịch. `statement_day` / `payment_due_day` là
**cột chết** — không model nào đọc chúng để tính gì.

### P4 — Physical FK chặn việc tách DB riêng *(Blocker)*

`user_credit_cards.user_id → users.id ON DELETE CASCADE` là FK **vật lý**. Yêu cầu tách sang
`hoantien_creditcard` khiến FK này bất khả thi (MySQL không có cross-database FK).

Ngoài ra: cascade delete `users` → xoá luôn thẻ. Khi mất FK vật lý, cần cơ chế dọn orphan.

### P5 — `user_credit_cards` thiếu "tên thẻ do user đặt" *(Cao)*

Yêu cầu §9 bắt buộc user tự đặt tên ("MB JCB chính"). Bảng hiện chỉ có `credit_card_id` trỏ tới
product — tên hiển thị lấy từ **product**, không phải tên user chọn. Hai user sở hữu cùng một
product sẽ không phân biệt được. `index.blade.php:63` đang render `$userCard->creditCard?->name`.

### P6 — `credit_cards` đặt tên sai vai trò *(Cao)*

`credit_cards` là **product catalog** (MB JCB Ultimate), còn thẻ thật là `user_credit_cards`.
Hai khái niệm B và C trong yêu cầu bị gộp tên. `CreditCard` (product) vs `UserCreditCard` (thẻ
thật) → khi đọc code rất dễ nhầm. Đề xuất đổi `credit_cards` → `credit_card_products`.

### P7 — Migration CC nằm trong chuỗi migrate của DB chính *(Cao)*

4 file nằm trong `database/migrations/`. Nếu chạy `php artisan migrate --database=creditcard`
để tạo DB mới, Laravel sẽ **quét cả 4 file này** (glob không recursive) và chạy lại chúng trên
DB mới → tạo bảng sai shape, và `user_credit_cards` sẽ **fail** vì FK tới `users` không tồn tại.

### P8 — Test dùng sqlite, schema mới sẽ không tồn tại ở sqlite *(Cao)*

`phpunit.xml` ép `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`. Khi thêm connection `creditcard`
mới, nếu env test không define thì:

- `RefreshDatabase` chỉ migrate connection mặc định → bảng CC không tồn tại trong test
- 18 test hiện tại sẽ **fail hàng loạt**

### P9 — Thiếu `statement_date_basis` / `spending_deadline_day` *(Trung bình)*

Yêu cầu §19 đòi phân biệt `transaction_date` vs `posted_date`. Schema hiện tại không có cả hai
loại ngày trên transaction, cũng không có cấu hình chọn cơ sở.

### P10 — Thiếu đường dẫn sửa thẻ *(Trung bình)*

6 route đều là `GET`, không có `edit/update/destroy`. Không có FormRequest, không có Policy
(`credit-cards.*` không có policy class → chỉ dựa vào `where('user_id', auth()->id())` thủ công
ở controller — dễ sót khi thêm endpoint mới).

### P11 — Không có `verified` middleware *(Thấp)*

Group CC nằm trong `Route::middleware('auth')` giống `wallet`, `orders`, `referrals` — nhất quán
với phần còn lại của app. `dashboard` mới dùng `['auth','verified']`. **Giữ nguyên như hiện tại**,
không cần đổi.

### P12 — Đặc biệt: `App\Models\CreditCard` vs namespace `App\Models\CreditCard\`

Nếu chọn sub-namespace `app/Models/CreditCard/` mà **không xoá** `app/Models/CreditCard.php`,
sẽ có class `App\Models\CreditCard` cùng tồn tại với namespace `App\Models\CreditCard`. PHP cho
phép nhưng rất dễ gây lỗi "Class not found" khi ai đó viết `use App\Models\CreditCard;` trong khi
đang muốn `CreditCard\Bank`. Xem khuyến nghị ở §16.

---

# 6. WHAT CAN BE KEPT

| # | Hạng mục | Quyết định | Lý do |
|---|---|---|---|
| K1 | Toàn bộ 6 route + URI + name `credit-cards.*` | ✅ **GIỮ NGUYÊN** | URL tiếng Việt đúng chuẩn SEO/UX; test đang assert |
| K2 | `resources/views/credit-card/layout.blade.php` | ✅ **GIỮ NGUYÊN** | Kế thừa `x-app-layout` đúng, không tạo layout song song |
| K3 | `partials/sidebar.blade.php` (cấu trúc responsive) | ✅ **GIỮ, sửa nội dung menu** | Xử lý mobile ngang cuộn ngang rất tốt |
| K4 | `partials/placeholder.blade.php` | ✅ **GIỮ NGUYÊN** | Tái sử dụng cho các trang chưa làm ở 1A |
| K5 | `app/View/Components/CreditCard/Layout.php` | ✅ **GIỮ NGUYÊN** | Đúng convention Breeze |
| K6 | 3 entry nav (tab / dropdown / hamburger) | ✅ **GIỮ NGUYÊN** | Comment `hidden xl:inline` là quyết định có lý do |
| K7 | `CreditCardController@index` (query thật, không số giả) | ✅ **GIỮ pattern** | Rất tốt — số liệu thật, empty state chuẩn |
| K8 | `UserCreditCard::$hidden = ['card_number_last4']` | ✅ **GIỮ** | Bảo mật đúng |
| K9 | Quyết định "chỉ lưu 4 số cuối, không lưu CVV / số thẻ / ngày hết hạn" | ✅ **GIỮ** | Đúng, không đổi |
| K10 | Comment tiếng Việt giải thích *tại sao* trong migration/route/nav | ✅ **GIỮ** | Văn phong project |
| K11 | `credit_card_banks` table + model + migration | ✅ **GIỮ, mở rộng nhẹ** | Đúng domain; chỉ thêm `short_name`, `sort_order` |
| K12 | Toàn bộ 18 test trong `CreditCardModuleTest` | ✅ **GIỮ, cập nhật** | Lưới an toàn hồi quang rất tốt |
| K13 | Design tokens (emerald primary, `rounded-2xl`, `bg-gray-100`) | ✅ **GIỮ** | Tuân thủ `docs/ui/design-system.md` |
| K14 | Cách dùng `decimal` + `casts()` cho tiền tệ | ✅ **GIỮ** | Không dùng float |
| K15 | `scopeActive()` | ✅ **GIỮ** | Convention sẵn có |
| K16 | Không đụng `app/Services/CashbackCalculator.php` (affiliate) | ✅ **BẮT BUỘC GIỮ** | Không được refactor cashback affiliate |
| K17 | 4 bảng legacy trong `hoantienaff` | ⏸ **ĐỂ NGUYÊN** (0 dòng) | Dọn ở Phase 1B, không drop bây giờ |

---

# 7. TARGET PHASE 1A ARCHITECTURE

```
┌──────────────────────────────────────────────────────────────────────┐
│                          Laravel 12 app                              │
│                                                                      │
│  routes/web.php ──▶ app/Http/Controllers/CreditCard/                 │
│                          │                                           │
│                          ▼                                           │
│                   app/Services/CreditCard/                           │
│   ┌──────────────────┬──────────────────┬───────────────────┐         │
│   │StatementPeriod   │CashbackPolicy    │ PolicyClone       │         │
│   │Service           │Resolver          │ Service           │         │
│   │(resolve kỳ)      │(policy+tier+rule)│(deep clone)       │         │
│   └────────┬─────────┴────────┬─────────┴─────────┬─────────┘         │
│            │                  │                    │                   │
│            │            CashbackCalculator (PURE)  │                   │
│            │                  │                    │                   │
│  ┌─────────▼──────────────────▼────────────────────▼──────────┐       │
│  │  app/Models/CreditCard/  (Eloquent, connection=creditcard) │       │
│  └───────────────────────────┬───────────────────────────────┘       │
└──────────────────────────────┼───────────────────────────────────────┘
                               │
        ┌──────────────────────┴───────────────────────┐
        │                                              │
        ▼                                              ▼
┌───────────────────────┐              ┌──────────────────────────────┐
│  hoantienaff  (MAIN)  │  logical FK   │  hoantien_creditcard (CC)    │
│                       │  (NO physical)│                              │
│  users  ──────────────┼──user_id─────▶│  credit_card_banks          │
│  wallet_transactions  │  (cross-DB)   │  credit_card_products       │
│                       │               │  credit_card_categories      │
│                       │               │  credit_card_policy_templates│
│                       │               │  credit_card_policies       │
│                       │               │  credit_card_policy_tiers   │
│                       │               │  credit_card_policy_tier_   │
│                       │               │    categories                │
│                       │               │  credit_card_user_cards      │
│                       │               │  credit_card_statement_     │
│                       │               │    periods                   │
│                       │               │  credit_card_transactions    │
│                       │               │  migrations (repo riêng)     │
└───────────────────────┘              └──────────────────────────────┘
```

**Quyết định kiến trúc cốt lõi (3 điểm):**

1. **Template và Policy dùng CHUNG bộ bảng.** Một `policy` là một "tài liệu chính sách hoàn chỉnh".
   Template = policy **chưa gắn thẻ nào** (`user_card_id = NULL`). Card policy = policy **đã gắn
   thẻ** (`user_card_id = NOT NULL`). Load template = **deep clone** tiers + tier_categories sang
   policy mới. → Không có shared mutable relationship. Đúng yêu cầu §10.
   → Giảm từ 7 bảng tiềm năng (templates + template_tiers + template_tier_categories + policies +
   policy_tiers + policy_tier_categories + ...) xuống **4 bảng**.

2. **Policy là append-only.** Sửa policy = tạo `policy` mới (`version_no + 1`, set `effective_from`),
   policy cũ chuyển `status='superseded'` + `effective_to`. Không sửa in-place.
   → Bảo vệ historical transaction tuyệt đối (yêu cầu §21).

3. **Transaction lưu snapshot đầy đủ** (`policy_id`, `policy_tier_id`, `policy_tier_category_id`,
   `cashback_rate`, `cashback_amount`, `calc_meta` JSON). Không bao giờ re-derive từ policy hiện tại.

---

# 8. DATABASE DESIGN

> Connection: `creditcard` → DB `hoantien_creditcard`
> Engine: InnoDB, `utf8mb4_unicode_ci` (khớp DB chính)
> Quy ước chung: `bigint unsigned` PK, `timestamps`, tiền tệ `decimal`, KHÔNG dùng `float`.
> `owner_user_id` = **logical reference** tới `hoantienaff.users.id`. **KHÔNG tạo physical FK.**

---

### 8.1 `credit_card_banks` — Bank *(giữ, mở rộng)*

| Field | Type | Null | Default | Index | Ghi chú |
|---|---|---|---|---|---|
| `id` | bigint unsigned AI | NO | | PK | |
| `name` | varchar(150) | NO | | | "MB Bank" |
| `short_name` | varchar(50) | YES | NULL | | "MB" |
| `slug` | varchar(150) | NO | | **UNIQUE** | |
| `logo` | varchar(255) | YES | NULL | | đường dẫn logo |
| `sort_order` | smallint unsigned | NO | 0 | IDX | |
| `is_active` | boolean | NO | 1 | IDX | |
| `created_at` / `updated_at` | timestamp | YES | NULL | | |

**Ownership:** system (seed bởi admin).
**Relationship:** `hasMany` products.

---

### 8.2 `credit_card_products` — Credit Card Product *(ĐỔI TÊN từ `credit_cards`)*

| Field | Type | Null | Default | Index | Ghi chú |
|---|---|---|---|---|---|
| `id` | bigint unsigned AI | NO | | PK | |
| `bank_id` | bigint unsigned | NO | | IDX, **FK → banks RESTRICT** | sản phẩm phải thuộc ngân hàng |
| `name` | varchar(191) | NO | | | "MB JCB Ultimate" |
| `slug` | varchar(191) | NO | | **UNIQUE** | |
| `type` | varchar(50) | YES | NULL | IDX | "JCB"/"Visa"/"Platinum" — **string tự do, KHÔNG enum cứng** |
| `image` | varchar(255) | YES | NULL | | |
| `annual_fee` | decimal(14,2) | YES | NULL | | |
| `description` | text | YES | NULL | | |
| `is_active` | boolean | NO | 1 | IDX | |
| `created_at` / `updated_at` | timestamp | YES | NULL | | |

**Đã bỏ:** `category_id` (FK tới bảng `credit_card_categories` cũ — sai nghĩa domain, xem P1).
**Ownership:** system.
**Relationship:** `belongsTo` bank; `hasMany` user_cards.

---

### 8.3 `credit_card_categories` — Spending Category Master + User Category *(TÁI ĐỊNH NGHĨA)*

| Field | Type | Null | Default | Index | Ghi chú |
|---|---|---|---|---|---|
| `id` | bigint unsigned AI | NO | | PK | |
| `scope` | enum('system','user') | NO | 'system' | | **ownership rõ ràng** |
| `owner_user_id` | bigint unsigned | NO | **0** | IDX | **0 = system.** Logical ref `users.id`, KHÔNG FK |
| `name` | varchar(150) | NO | | | "Online" / "Shopee" |
| `slug` | varchar(150) | NO | | | |
| `description` | text | YES | NULL | | |
| `sort_order` | smallint unsigned | NO | 0 | IDX | |
| `is_active` | boolean | NO | 1 | IDX | |
| `created_at` / `updated_at` | timestamp | YES | NULL | | |
| | | | | **UNIQUE(`scope`,`owner_user_id`,`slug`)** | |

**Không có `cashback_percent`. Không có quota.** (yêu cầu §12 — cashback thuộc Policy/Tier/Rule)

**Vì sao dùng sentinel `owner_user_id = 0` thay vì NULL?**
MySQL coi mỗi NULL trong UNIQUE index là khác nhau → 2 system category trùng slug vẫn insert được.
Sentinel `0` (users.id auto-increment bắt đầu từ 1, không bao giờ = 0) là cách duy nhất đảm bảo
constraint thật sự hoạt động.

**Ownership:** `scope='system'` → hệ thống; `scope='user'` → `owner_user_id` = user hiện tại.
**Relationship:** `hasMany` policy_tier_categories; `hasMany` transactions.

---

### 8.4 `credit_card_policy_templates` — Policy Template *(MỚI)*

| Field | Type | Null | Default | Index | Ghi chú |
|---|---|---|---|---|---|
| `id` | bigint unsigned AI | NO | | PK | |
| `scope` | enum('system','user') | NO | 'system' | | system / user template |
| `owner_user_id` | bigint unsigned | NO | 0 | IDX | 0 = system, KHÔNG FK |
| `name` | varchar(191) | NO | | | "MB JCB Standard" |
| `slug` | varchar(191) | NO | | | |
| `description` | text | YES | NULL | | |
| `source_policy_id` | bigint unsigned | YES | NULL | IDX | "Tạo template từ policy này" — audit, KHÔNG FK logic |
| `is_builtin` | boolean | NO | 0 | | system template không cho xoá |
| `is_active` | boolean | NO | 1 | IDX | |
| `created_at` / `updated_at` | timestamp | YES | NULL | | |
| | | | | **UNIQUE(`scope`,`owner_user_id`,`slug`)** | |

**Quan trọng:** Template **không chứa** tier/rule trực tiếp. Template là container của một
`policy` detached (`user_card_id = NULL`). Cấu trúc tier nằm ở `credit_card_policy_tiers` /
`..._tier_categories` với `policy_id` trỏ về policy detached đó.
→ Đây chính là cơ chế **clone**, không phải shared reference (yêu cầu §10).

---

### 8.5 `credit_card_policies` — Policy Document / Version *(MỚI — bảng trung tâm)*

| Field | Type | Null | Default | Index | Ghi chú |
|---|---|---|---|---|---|
| `id` | bigint unsigned AI | NO | | PK | |
| `user_card_id` | bigint unsigned | **YES** | NULL | IDX, FK → user_cards **RESTRICT** | **NULL = template policy (chưa gắn thẻ)** |
| `template_id` | bigint unsigned | YES | NULL | IDX, FK → templates **SET NULL** | "policy này clone từ template nào" |
| `parent_policy_id` | bigint unsigned | YES | NULL | IDX, FK → self **SET NULL** | lineage version |
| `name` | varchar(191) | NO | | | |
| `version_no` | smallint unsigned | NO | 1 | | |
| `status` | enum('draft','active','superseded') | NO | 'draft' | IDX | **chỉ `active` được gán cho kỳ** |
| `tier_application_mode` | enum('progressive','retroactive') | NO | 'progressive' | | yêu cầu §22 — **không hard-code** |
| `min_total_spend` | decimal(16,2) | NO | 0 | | **minimum spend gate toàn policy** (yêu cầu §18) |
| `max_cashback_total_per_period` | decimal(14,2) | YES | NULL | | **QUOTA LOẠI 3** (§17) |
| `rounding_mode` | enum('floor','round_half_up') | NO | 'floor' | | không hard-code luật làm tròn |
| `effective_from` | date | YES | NULL | IDX | |
| `effective_to` | date | YES | NULL | | |
| `note` | text | YES | NULL | | |
| `created_at` / `updated_at` | timestamp | YES | NULL | | |
| | | | | IDX(`user_card_id`,`status`), IDX(`user_card_id`,`effective_from`) | |

**Ràng buộc nghiệp vụ (validate ở tầng service, không phải DB):**

- Template policy: `user_card_id IS NULL` **AND** `status = 'draft'`
- Card policy: `user_card_id IS NOT NULL`
- Mỗi `user_card_id` chỉ có **tối đa 1** policy `status='active'` tại một thời điểm
- Mỗi `user_card_id` chỉ có **tối đa 1** policy `status='draft'`

> `UNIQUE(user_card_id, version_no)` **không** dùng được: MySQL cho phép nhiều NULL → mọi template
> policy sẽ trùng `version_no`. Dùng composite INDEX + validate ở `PolicyCloneService`.
> Ghi nhận đây là điểm đánh đổi có chủ đích.

---

### 8.6 `credit_card_policy_tiers` — Policy Tier *(MỚI)*

| Field | Type | Null | Default | Index | Ghi chú |
|---|---|---|---|---|---|
| `id` | bigint unsigned AI | NO | | PK | |
| `policy_id` | bigint unsigned | NO | | IDX, FK → policies **CASCADE** | |
| `name` | varchar(150) | NO | | | "Tier 1" — user tự đặt |
| `sort_order` | smallint unsigned | NO | 0 | IDX(`policy_id`,`sort_order`) | thứ tự hiển thị |
| `min_total_spend` | decimal(16,2) | NO | 0 | | **ngưỡng dưới (≥)** |
| `max_total_spend` | decimal(16,2) | **YES** | NULL | | **ngưỡng trên (<)**. NULL = không trần |
| `note` | text | YES | NULL | | |
| `created_at` / `updated_at` | timestamp | YES | NULL | | |

**Default tier khi tạo policy mới:** `name='Tier 1'`, `min_total_spend=0`, `max_total_spend=NULL`.

Yêu cầu §15 nói default là `0 → 200.000.000`; khuyến nghị **đặt NULL/không trần** thay vì con số
200tr, vì con số đó chỉ là ví dụ và `PolicyCloneService` phải cho phép sửa. Nếu product team muốn
con số cụ thể thì đưa vào **seeder**, không hard-code trong code.

**Quan hệ min/max:** các tier trong một policy phải **không chồng lấn**. Validate khi save
(`min_i < max_i <= min_{i+1}`), sort theo `min_total_spend`.

---

### 8.7 `credit_card_policy_tier_categories` — Tier Category Rule *(MỚI)*

| Field | Type | Null | Default | Index | Ghi chú |
|---|---|---|---|---|---|
| `id` | bigint unsigned AI | NO | | PK | |
| `tier_id` | bigint unsigned | NO | | IDX, FK → policy_tiers **CASCADE** | |
| `category_id` | bigint unsigned | NO | | IDX, FK → categories **RESTRICT** | danh mục đang dùng thì không xoá được |
| `cashback_rate` | decimal(5,2) | NO | 0 | | **% hoàn**, vd `10.00` |
| `max_cashback_per_transaction` | decimal(14,2) | YES | NULL | | **QUOTA LOẠI 1** (§17) |
| `max_cashback_per_category_per_period` | decimal(14,2) | YES | NULL | | **QUOTA LOẠI 2** (§17) |
| `min_transaction_amount` | decimal(16,2) | NO | 0 | | min 1 giao dịch để được hoàn |
| `min_spend_per_period` | decimal(16,2) | YES | NULL | | điều kiện riêng cho category |
| `is_enabled` | boolean | NO | 1 | | bật/tắt rule mà không xoá |
| `sort_order` | smallint unsigned | NO | 0 | | reorder (yêu cầu §12) |
| `note` | text | YES | NULL | | |
| `created_at` / `updated_at` | timestamp | YES | NULL | | |
| | | | | **UNIQUE(`tier_id`,`category_id`)** | |

**Ba loại quota được phân biệt đúng yêu cầu §17:**

| Loại | Ở đâu | Ý nghĩa |
|---|---|---|
| Max per transaction | `..._tier_categories.max_cashback_per_transaction` | trần mỗi giao dịch |
| Max per category per period | `..._tier_categories.max_cashback_per_category_per_period` | trần từng category trong 1 kỳ |
| Max total per period | `policies.max_cashback_total_per_period` | trần toàn bộ policy trong 1 kỳ |

> Quota tổng đặt ở **policy** chứ không phải tier: nó là trần toàn kỳ của cả policy, không thuộc
> một category cụ thể. Nếu sau này cần trần riêng theo tier thì thêm
> `..._policy_tiers.max_cashback_total_per_period` — **không thêm ở 1A** (tránh over-engineer).

---

### 8.8 `credit_card_user_cards` — User Credit Card *(TÁI TẠO từ `user_credit_cards`)*

| Field | Type | Null | Default | Index | Ghi chú |
|---|---|---|---|---|---|
| `id` | bigint unsigned AI | NO | | PK | |
| `user_id` | bigint unsigned | NO | | IDX(`user_id`,`is_active`) | **logical ref `hoantienaff.users.id`. KHÔNG FK.** |
| `product_id` | bigint unsigned | YES | NULL | IDX, FK → products **SET NULL** | NULL = thẻ ngoài catalog |
| `name` | varchar(150) | **NO** | | | ⭐ **TÊN THẺ DO USER ĐẶT** (thiếu ở schema cũ — P5) |
| `card_number_last4` | char(4) | YES | NULL | | ⚠ `$hidden` — không serialize |
| `credit_limit` | decimal(16,2) | YES | NULL | | |
| `statement_day` | tinyint unsigned | YES | NULL | | 1-31, ngày chốt sao kê |
| `payment_due_day` | tinyint unsigned | YES | NULL | | 1-31, ngày đến hạn thanh toán |
| `spending_deadline_day` | tinyint unsigned | YES | NULL | | 1-31, hạn chót chi tiêu. NULL ⇒ = `statement_day` |
| `statement_date_basis` | enum('transaction_date','posted_date') | NO | 'transaction_date' | | ⭐ yêu cầu §19 |
| `opened_at` | date | YES | NULL | | |
| `closed_at` | date | YES | NULL | | |
| `is_active` | boolean | NO | 1 | IDX | |
| `sort_order` | smallint unsigned | NO | 0 | | |
| `note` | text | YES | NULL | | |
| `created_at` / `updated_at` | timestamp | YES | NULL | | |

**Đã bỏ:** physical FK `user_id → users.id` (P4).
**Đã thêm:** `name`, `spending_deadline_day`, `statement_date_basis`, `opened_at`, `closed_at`,
`sort_order`, `note`.

**Phân biệt 3 ngày (yêu cầu §19):**

| Khái niệm | Ý nghĩa |
|---|---|
| `statement_day` | Ngày chốt sao kê. Kỳ = `(statement_day tháng trước + 1)` → `statement_day tháng này` |
| `payment_due_day` | Hạn trả tiền. **Khác** hạn chót chi tiêu |
| `spending_deadline_day` | Hạn chót chi tiêu để tính vào kỳ hiện tại. NULL ⇒ lấy `statement_day` |

**Edge case 29/30/31:** tháng ngắn không có ngày 30/31 → **clamp về ngày cuối tháng**.
Luật này nằm trong `StatementPeriodService`, có test riêng. **Không hard-code ở view/controller.**

---

### 8.9 `credit_card_statement_periods` — Statement Period *(MỚI)*

| Field | Type | Null | Default | Index | Ghi chú |
|---|---|---|---|---|---|
| `id` | bigint unsigned AI | NO | | PK | |
| `user_card_id` | bigint unsigned | NO | | IDX, FK → user_cards **CASCADE** | |
| `period_no` | smallint unsigned | YES | NULL | | số thứ tự kỳ |
| `period_start` | date | NO | | | giao dịch **sau** ngày này thuộc kỳ |
| `period_end` | date | NO | | | = ngày chốt sao kê |
| `statement_date` | date | NO | | | ngày chốt sao kê thực tế |
| `payment_due_date` | date | YES | NULL | | |
| `status` | enum('open','closed','finalized') | NO | 'open' | IDX(`user_card_id`,`status`) | |
| `policy_id` | bigint unsigned | YES | NULL | IDX, FK → policies **SET NULL** | policy áp dụng cho kỳ này |
| `tier_id` | bigint unsigned | YES | NULL | FK → policy_tiers **SET NULL** | tier cuối cùng của kỳ |
| `total_spend` | decimal(16,2) | YES | NULL | | **snapshot** khi finalize |
| `eligible_spend` | decimal(16,2) | YES | NULL | | **snapshot** |
| `non_eligible_spend` | decimal(16,2) | YES | NULL | | **snapshot** |
| `total_cashback` | decimal(14,2) | YES | NULL | | **snapshot** |
| `effective_cashback_rate` | decimal(5,2) | YES | NULL | | = `total_cashback / eligible_spend * 100` |
| `finalized_at` | timestamp | YES | NULL | | |
| `note` | text | YES | NULL | | |
| `created_at` / `updated_at` | timestamp | YES | NULL | | |
| | | | | **UNIQUE(`user_card_id`,`period_start`)** | |

**Quyết định về aggregate (yêu cầu §24) — HYBRID:**

| Trạng thái kỳ | Nguồn số liệu |
|---|---|
| `open` | **Query realtime** từ `credit_card_transactions` (dữ liệu còn chạy, cần cập nhật tức thì) |
| `closed` | Query realtime, nhưng đã chốt `policy_id` |
| `finalized` | **Đọc snapshot** (`total_spend`, `eligible_spend`, `total_cashback`, `tier_id`) |

Lý do: kỳ `open` phải sống (số tiền user nhập thay đổi mỗi ngày), nhưng kỳ `finalized` phải bất
biến (nguồn sự thật lịch sử). Lưu snapshot khi finalize vừa nhanh vừa bảo toàn lịch sử.
Cột aggregate để NULL khi chưa finalize → phân biệt được "chưa có" với "bằng 0".

---

### 8.10 `credit_card_transactions` — Transaction + Cashback Snapshot *(MỚI)*

| Field | Type | Null | Default | Index | Ghi chú |
|---|---|---|---|---|---|
| `id` | bigint unsigned AI | NO | | PK | |
| `user_card_id` | bigint unsigned | NO | | IDX, FK → user_cards **CASCADE** | |
| `statement_period_id` | bigint unsigned | YES | NULL | IDX, FK → statement_periods **SET NULL** | NULL = chưa gán kỳ |
| `category_id` | bigint unsigned | NO | | IDX, FK → categories **RESTRICT** | |
| `type` | enum('spend','refund','fee','adjustment') | NO | 'spend' | | tránh nhập số âm mơ hồ |
| `transaction_date` | date | NO | | IDX | ngày user nhập |
| `posted_date` | date | YES | NULL | | ngày ghi sổ |
| `amount` | decimal(16,2) | NO | | | luôn dương; `type` quyết định dấu |
| `merchant` | varchar(191) | YES | NULL | | |
| `note` | text | YES | NULL | | |
| `policy_id` | bigint unsigned | YES | NULL | IDX, FK SET NULL | ⭐ **snapshot** |
| `policy_tier_id` | bigint unsigned | YES | NULL | FK SET NULL | ⭐ **snapshot** |
| `policy_tier_category_id` | bigint unsigned | YES | NULL | FK SET NULL | ⭐ **snapshot** |
| `cashback_rate` | decimal(5,2) | YES | NULL | | ⭐ **snapshot %** |
| `cashback_amount` | decimal(14,2) | YES | NULL | | ⭐ **snapshot tiền** |
| `is_eligible` | boolean | YES | NULL | | NULL = chưa tính, TRUE/FALSE = kết quả |
| `calc_basis` | enum('transaction_date','posted_date') | YES | NULL | | cơ sở ngày đã dùng lúc tính |
| `calc_meta` | json | YES | NULL | | audit: cap nào chạm, quota trước/sau, min spend |
| `calculated_at` | timestamp | YES | NULL | | |
| `calc_version` | smallint unsigned | NO | 0 | | bump khi thuật toán đổi |
| `is_locked` | boolean | NO | 0 | | kỳ finalized ⇒ không sửa |
| `created_at` / `updated_at` | timestamp | YES | NULL | | |
| `deleted_at` | timestamp | YES | NULL | | **softDeletes** — user cần undo |

**Luật bất biến (yêu cầu §23):**

- `is_locked = true` ⇒ mọi cột cashback **bất biến**. Không có code path nào tự ghi đè.
- Tính lại chỉ qua action tường minh "Tính lại kỳ", chỉ trên kỳ `status != 'finalized'`.
- `is_eligible = NULL` (chưa tính) **khác** `FALSE` (đã tính, không đủ điều kiện).

---

# 9. TEMPLATE DESIGN

## 9.1 Ba khái niệm, ba vai trò

```
                    credit_card_policy_templates
                    ┌──────────────────────────────┐
                    │ scope = system | user        │  ← "công thức mẫu", KHÔNG gắn thẻ
                    │ owner_user_id = 0 | user_id │  ← KHÔNG AI sửa trực tiếp
                    └──────────────┬───────────────┘
                                   │ 1
                                   │  (template policy: user_card_id = NULL)
                                   ▼
                    credit_card_policies
                    ┌──────────────────────────────┐
                    │ user_card_id  = NULL ────────┼──► Template Policy (blueprint)
                    │ user_card_id  = 12   ───────┼──► Card Policy (gắn thẻ)
                    │ status = draft|active|superseded
                    │ version_no, effective_from/to
                    └──────────────┬───────────────┘
                                   │ 1:N
                                   ▼
                    credit_card_policy_tiers
                    ┌──────────────────────────────┐
                    │ min_total_spend / max_total  │  ← ngưỡng chọn tier
                    └──────────────┬───────────────┘
                                   │ 1:N
                                   ▼
                    credit_card_policy_tier_categories
                    ┌──────────────────────────────┐
                    │ cashback_rate                │  ← QUY ĐỊNH CASHBACK
                    │ max_cashback_per_transaction │  ← quota 1
                    │ max_cashback_per_category_   │  ← quota 2
                    │   per_period                 │
                    └──────────────────────────────┘
```

## 9.2 Luồng "Load Template" — không shared mutable relationship

```
User bấm "Dùng template này" trên card MB JCB
   ↓
PolicyCloneService::cloneTemplateToCard(templateId, userCardId)
   ↓
BEGIN TRANSACTION
  1. INSERT credit_card_policies
       user_card_id       = 12
       template_id        = 3
       parent_policy_id   = (policy hiện tại của card, nếu có)
       version_no         = max+1
       status             = 'draft'        ← user sửa thoải mái
       ...copy scalar...
  2. SELECT * FROM credit_card_policy_tiers WHERE policy_id = <templatePolicyId>
     → INSERT từng dòng vào credit_card_policy_tiers (policy_id = <newId>)   ← ID MỚI
  3. SELECT * FROM credit_card_policy_tier_categories WHERE tier_id IN (templateTierIds)
     → INSERT từng dòng, map tier_id cũ → tier_id MỚI                      ← ID MỚI
COMMIT
```

**Không có bất kỳ dòng nào trong template bị UPDATE.** Sửa template sau đó **không** ảnh hưởng
thẻ nào đã load. Đúng yêu cầu §10.

## 9.3 Ownership matrix

| | `scope` | `owner_user_id` | Ai được sửa | Ai xoá |
|---|---|---|---|---|
| System Template | `system` | `0` | Admin / seed | Không (`is_builtin=1`) |
| User Template | `user` | user hiện tại | Chủ sở hữu | Chủ sở hữu |
| Template policy | — | — | Chỉ qua clone | Cascade khi xoá template |
| Card policy (draft) | — | — | Chủ thẻ | Khi thẻ bị xoá |
| Card policy (active/superseded) | — | — | **KHÔNG** | Chỉ cascade theo thẻ |

## 9.4 "Lưu policy hiện tại thành Template mới" (yêu cầu §10.6)

```
PolicyCloneService::savePolicyAsTemplate(policyId, userId, name, slug)
   ↓
1. INSERT credit_card_policy_templates (scope='user', owner_user_id=userId,
                                        source_policy_id=policyId)
2. clonePolicy() — deep copy tiers + tier_categories sang policy detached (user_card_id=NULL)
3. Trả về template_id
```

Lưu ý: bước 2 tạo ra **một policy detached** thật, không dùng chung row với policy gốc.
→ Sửa template sau không đụng policy gốc.

---

# 10. CATEGORY DESIGN

4 tầng, phân biệt rõ (yêu cầu §10, §12, §13):

| Tầng | Nơi lưu | Ai tạo | Chứa cashback? | Chứa quota? |
|---|---|---|---|---|
| **Master Category** | `credit_card_categories` `scope='system'` | Hệ thống / admin | ❌ | ❌ |
| **User Category** | `credit_card_categories` `scope='user'` | User | ❌ | ❌ |
| **Policy Category** (rule) | `credit_card_policy_tier_categories` | Khi build policy | ✅ `cashback_rate` | ✅ 2 loại |
| **Tier Category Rule** | (cùng bảng, gắn `tier_id`) | Khi build tier | ✅ | ✅ |

**Quyết định:** **MỘT bảng** cho Master + User, phân biệt bằng `scope` + `owner_user_id`.
Lý do: hai loại này hành xử **giống hệt nhau** trong policy (cùng là "một danh mụu có thể gắn rule").
Tách 2 bảng sẽ tạo polymorphic reference (`category_type` + `category_id`) — phức tạp hơn mà không
mang lại lợi ích gì.

**Ràng buộc:**

- Category master **KHÔNG** chứa `cashback_percent` hay quota (yêu cầu §12) → schema 8.3 xác nhận.
- Policy có thể thêm/bỏ category, chỉnh rule, reorder → `is_enabled` + `sort_order` trong
  `credit_card_policy_tier_categories`.
- Category đang được dùng bởi một rule ⇒ FK **RESTRICT**, không cho xoá cứng (chỉ `is_active=0`).
- User category chỉ hiện cho chính user đó. **Luôn scope query theo `owner_user_id`** khi lấy
  danh sách category để chọn trong form nhập giao dịch:

```sql
WHERE scope='system' OR (scope='user' AND owner_user_id = :auth_id)
```

---

# 11. TIER DESIGN

## 11.1 Cấu trúc

Một `policy` có N `tier`. Mỗi tier có band `[min_total_spend, max_total_spend)` — **nửa mở** để
không trùng ranh giới:

```
Tier 1: [0,            200.000.000)
Tier 2: [200.000.000,  500.000.000)
Tier 3: [500.000.000,  NULL)        ← NULL = không trần
```

**Validate khi lưu policy** (trong `PolicyCloneService` / FormRequest):

- `min_total_spend >= 0`
- `max_total_spend` NULL **hoặc** `> min_total_spend`
- Sort theo `min_total_spend`; **không được chồng lấn**
- Phải có **ít nhất 1 tier** (`min_total_spend = 0`) để mọi giao dịch đều xếp được vào một tier

## 11.2 Default tier (yêu cầu §15)

Khi tạo policy mới, seed **1 tier**: `name='Tier 1'`, `min_total_spend=0`, `max_total_spend=NULL`.

**Khuyến nghị mạnh:** dùng `NULL` (không trần) thay vì `200.000.000`. Con số 200tr trong đề bài
được ghi rõ là "DEFAULT EXAMPLE". Hard-code 200tr vào code sẽ vi phạm nguyên tắc "không hard-code
business logic". Nếu product cần một mốc cụ thể → đặt trong **seeder** (`CreditCardSeeder`),
sửa được bằng dữ liệu, không cần deploy code.

## 11.3 Clone Tier (yêu cầu §16)

```
PolicyCloneService::cloneTier(tierId)
   ↓
1. INSERT credit_card_policy_tiers
     policy_id        = <cùng policy>
     name             = "{tên cũ} (bản sao)"
     min_total_spend  = copy
     max_total_spend  = copy
     sort_order       = max(sort_order)+1
2. SELECT * FROM credit_card_policy_tier_categories WHERE tier_id = :tierId
   → INSERT từng dòng với tier_id MỚI
     copy: category_id, cashback_rate,
           max_cashback_per_transaction,
           max_cashback_per_category_per_period,
           min_transaction_amount, min_spend_per_period,
           is_enabled, sort_order, note
```

**Clone đủ 100% field nghiệp vụ.** Tạo **record độc lập**, không copy bằng reference.
Sau đó user sửa `min/max_total_spend` và các rule của bản sao — bản gốc không đổi.

> ⚠️ Sau khi clone, user **phải sửa `min/max_total_spend`** nếu không muốn chồng lấn với tier gốc.
> UI Phase 1B cần cảnh báo "2 tier đang chồng khoảng chi tiêu". 1A chỉ validate khi **lưu policy**,
> không chặn **clone** (để user tự chỉnh).

---

# 12. POLICY VERSION / SNAPSHOT

## 12.1 Vấn đề

Yêu cầu §21: tháng 9 Online = 10%, tháng 10 Online = 5% → giao dịch tháng 9 **không được** đổi
thành 5%. Bất kỳ thiết kế nào để transaction **tham chiếu** policy "hiện hành" đều sai.

## 12.2 Ba phương án đã cân nhắc

| Phương án | Cách | Đánh giá |
|---|---|---|
| A. `policy_version_id` trên transaction | Mỗi lần sửa policy tạo version mới, transaction trỏ version | ✅ Chọn |
| B. Snapshot JSON đầy đủ trên transaction | Copy toàn bộ rule vào JSON | ❌ Mất khả năng query/group theo rule; JSON khó index |
| C. `effective_from` / `effective_to` trên policy | Transaction resolve theo ngày | ⚠️ **Không đủ một mình** — giao dịch nhập trễ (nhập hôm nay cho kỳ tháng trước) sẽ resolve sai |

## 12.3 Phương án chọn: **A + C + snapshot value**

Ba lớp bảo vệ cộng dồn:

```
LỚP 1 — Policy append-only
  Sửa policy KHÔNG sửa dòng cũ. Tạo policy mới version_no+1, status='active',
  effective_from = ngày. Dòng cũ → status='superseded', effective_to = ngày - 1.
  → Lịch sử policy bất biến theo thời gian.

LỚP 2 — Transaction trỏ policy_id (FK thật)
  credit_card_transactions.policy_id → credit_card_policies.id
  → Có thể JOIN để audit "giao dịch này dùng version nào".

LỚP 3 — Snapshot value trên transaction
  policy_tier_id, policy_tier_category_id, cashback_rate, cashback_amount, calc_meta
  → Kể cả khi dữ liệu hỏng, cashback đã ghi vẫn còn nguyên giá trị lịch sử.
```

**Tại sao cần cả 3:**

- Chỉ L1+L2: nếu ai đó xoá/sửa dòng policy (bug, admin) → mất dữ liệu lịch sử.
- Chỉ L3: mất khả năng truy vấn "10 giao dịch tháng 9 dùng rate 10%".
- Cả 3: vừa query được vừa an toàn.

**`policy_id` NULL = giao dịch chưa được tính** (ví dụ user nhập trước khi tạo policy).
Phân biệt rõ với `policy_id` có giá trị nhưng `cashback_amount = 0` (đã tính, không đủ điều kiện).

## 12.4 Retroactive vs Progressive (yêu cầu §22)

Field: `policies.tier_application_mode` — enum, **default `'progressive'`**, không hard-code.

| Mode | Cách tính | Hệ quả |
|---|---|---|
| `progressive` | Tier = tier chứa tổng eligible spend **tích luỹ tới giao dịch này** (theo thứ tự `transaction_date, id`) | Cashback từng giao dịch ổn định, không đổi |
| `retroactive` | Tính **tổng eligible spend cả kỳ** → xác định **một tier duy nhất** → áp tier đó cho **mọi** giao dịch eligible trong kỳ | Cashback **chỉ chốt khi kỳ finalize** |

**Hệ quả thiết kế quan trọng:**

- Chế độ `retroactive` **bắt buộc** phải có trạng thái kỳ `open → finalized` (§8.9).
  Đây là lý do mạnh nhất để bảng `statement_periods` tồn tại, thay vì query realtime thuần.
- UI phải hiển thị nhãn **"tạm tính"** khi kỳ `open` và policy là `retroactive`.
- Không được `is_locked` transaction của kỳ `open` khi policy `retroactive` — vì tier còn có thể đổi.
  Chỉ khoá khi kỳ `finalized`.

**Thứ tự tiêu thụ quota — phải tất định (deterministic):**

Khi nhiều giao dịch tranh nhau 1 quota, thứ tự xử lý quyết định ai được hoàn.
Bắt buộc: **`ORDER BY transaction_date ASC, id ASC`**. Ghi vào doc + test.
Nếu không, cùng một bộ dữ liệu sẽ ra kết quả khác nhau giữa 2 lần chạy → mất niềm tin vào hệ thống.

---

# 13. STATEMENT DESIGN

## 13.1 Chu kỳ

Cho `statement_day = 15`:

```
Kỳ N     : 16/08/2026 → 15/09/2026   (statement_date = 15/09, payment_due theo payment_due_day)
Kỳ N+1   : 16/09/2026 → 15/10/2026
```

Quy tắc: `period_start = statement_day tháng trước + 1 ngày`, `period_end = statement_day tháng này`.

## 13.2 transaction_date vs posted_date

| Khái niệm | Nguồn | Dùng để |
|---|---|---|
| `transactions.transaction_date` | User nhập tay (ngày mua) | Mặc định |
| `transactions.posted_date` | User nhập tay (ngày ghi sổ), có thể NULL | Một số bank ghi sổ trễ 1-3 ngày |
| `user_cards.statement_date_basis` | Config trên thẻ | Chọn cái nào là **chuẩn xác định kỳ** |
| `transactions.calc_basis` | Snapshot | Ghi lại cái nào **đã thực sự dùng** lúc tính |

**Luồng resolve kỳ:**

```
basis = user_cards.statement_date_basis
date  = (basis == 'posted_date' AND posted_date IS NOT NULL) ? posted_date : transaction_date
period = findOrCreatePeriod(userCardId, date)
transaction.statement_period_id = period.id
transaction.calc_basis = date đã dùng
```

→ Nếu user sửa `posted_date` sau khi tạo giao dịch, kỳ phải được **gán lại** (chỉ khi kỳ chưa finalize).

## 13.3 Spending deadline

- `spending_deadline_day = NULL` ⇒ dùng `statement_day`
- Giao dịch có `date > spending_deadline` **của kỳ đang mở** ⇒ **không** tính vào kỳ đó
  (UI cảnh báo "quá hạn, sẽ sang kỳ sau"). Không tự động chuyển — user quyết định.

## 13.4 Clamp ngày 29/30/31

```php
// StatementPeriodService — clamp về ngày cuối tháng, KHÔNG roll-over sang tháng sau
$day = min($statementDay, Carbon::create($y, $m)->daysInMonth);
```

Tháng 2/2026 (28 ngày) + `statement_day=31` → `28/02/2026`.
**Không roll-over** (31/02 không tồn tại; roll-over sẽ tạo kỳ âm 1 ngày).
Bắt buộc có test cho: Feb không năm nhuận, Feb năm nhuận, tháng 30 ngày, statement_day=31.

## 13.5 Vòng đời kỳ

```
        (tạo khi có giao dịch đầu tiên, hoặc cron mở kỳ mới)
                 ↓
             ┌────────┐  tự động khi period_end < today
             │  open  │ ─────────────────────────┐
             └────────┘                          ↓
          user xem số liệu               ┌────────────┐
          realtime từ transactions        │  closed   │ (kỳ đã qua, chưa chốt)
                                          └────────────┘
                                                 │ user bấm "Chốt kỳ"
                                                 ↓
                                          ┌────────────┐
                                          │ finalized  │ → ghi snapshot
                                          └────────────┘
                                                 → is_locked = true cho mọi transaction
                                                 → không bao giờ tính lại tự động
```

**Khuyến nghị 1A:** chỉ cần `open` + `finalized`. `closed` thêm vào nếu muốn cron mở kỳ mới.
Giữ cả 3 trong schema vì status enum rẻ, nhưng **chưa xây cron** ở 1A.

---

# 14. TRANSACTION DESIGN

## 14.1 Luồng tạo giao dịch (user nhập tay)

```
POST /thetindung/than/{card}/giaodich
  Card, Date, Category, Merchant, Amount, Note
  (KHÔNG có ô nhập cashback — yêu cầu §20)
   ↓
CreditCardTransactionService::create()
  1. Authorize: user sở hữu card?          → 403 nếu không
  2. Authorize: category thuộc user?      → system hoặc owner_user_id = auth id
  3. Resolve period theo statement_date_basis  → StatementPeriodService
  4. Insert transaction với snapshot = NULL
  5. Nếu kỳ status != 'finalized':
       → CashbackPolicyResolver::resolve(card, period, transaction)
       → CashbackCalculator::calculate(context)  [PURE, không DB]
       → Ghi snapshot + is_locked
```

## 14.2 Pipeline CashbackCalculator (thuần, không side-effect)

```
BƯỚC 0 — Xác định policy
  policy = resolveActivePolicy(userCard, period)      theo effective_from/to
  nếu null → is_eligible = NULL, cashback = NULL, DỪNG

BƯỚC 1 — Minimum spend gate (yêu cầu §18)
  eligible_spend_kỳ = SUM(amount) của giao dịch eligible trước đó trong kỳ
  + amount giao dịch này
  nếu < policies.min_total_spend
     → is_eligible = FALSE, cashback = 0

BƯỚC 2 — Xác định tier (yêu cầu §22)
  progressive : tier = tierChứa(eligible_spend_tíchLuỹ_tới_giaoDịchNày)
  retroactive : tier = tierChứa(TỔNG eligible_spend CẢ KỲ)   ← chỉ chốt khi finalize

BƯỚC 3 — Tìm category rule
  rule = credit_card_policy_tier_categories
           WHERE tier_id = tier.id
             AND category_id = transaction.category_id
             AND is_enabled = true
  nếu null → is_eligible = FALSE, cashback = 0   (chi tiêu ngoài danh mục hoàn)

BƯỚC 4 — Minimum spend của category
  nếu rule.min_spend_per_period khác null
     và spending_cua_category_trong_kỳ < rule.min_spend_per_period
     → is_eligible = FALSE
  nếu transaction.amount < rule.min_transaction_amount
     → is_eligible = FALSE

BƯỚC 5 — Cashback thô
  raw = amount * rule.cashback_rate / 100
  raw = round_theo(policies.rounding_mode, raw)

BƯỚC 6 — QUOTA 1: trần mỗi giao dịch
  nếu rule.max_cashback_per_transaction khác null
     cashback = MIN(raw, rule.max_cashback_per_transaction)

BƯỚC 7 — QUOTA 2: trần mỗi category mỗi kỳ
  used_category = SUM(cashback_amount) của cùng category trong kỳ
  còn_trừ = rule.max_cashback_per_category_per_period - used_category
  cashback = MIN(cashback, MAX(còn_trừ, 0))

BƯỚC 8 — QUOTA 3: trần tổng mỗi kỳ
  used_total = SUM(cashback_amount) toàn kỳ
  còn_trừ = policies.max_cashback_total_per_period - used_total
  cashback = MIN(cashback, MAX(còn_trừ, 0))

BƯỚC 9 — Ghi snapshot
  policy_id, policy_tier_id, policy_tier_category_id,
  cashback_rate, cashback_amount, is_eligible,
  calc_basis, calc_meta(JSON: cap nào chạm, quota trước/sau, min spend), calculated_at
```

**Bước 6-8 là stateful** (phụ thuộc các giao dịch trước) → bắt buộc xử lý tuần tự theo
`ORDER BY transaction_date ASC, id ASC` trong 1 transaction DB. `CashbackCalculator` nhận
`quotaState` (mảng số dư) làm tham số → **pure**, không query DB. Service bên ngoài lo phần state.

## 14.3 `calc_meta` — ví dụ JSON

```json
{
  "tier_application_mode": "progressive",
  "min_total_spend": 20000000,
  "eligible_spend_in_period": 18500000,
  "caps": [
    { "type": "per_transaction",  "limit": 200000,  "applied": false },
    { "type": "per_category",     "limit": 1000000, "used_before": 450000, "remaining": 550000, "applied": false },
    { "type": "per_period_total", "limit": 800000,  "used_before": 620000, "remaining": 180000, "applied": false }
  ],
  "calc_version": 1
}
```

Cho phép tái kiểm chứng **tại sao** một giao dịch nhận đúng số tiền đó, khi không có log.

---

# 15. USER INTEGRATION

## 15.1 Quyết định: dùng `hoantienaff.users`, KHÔNG tạo bảng user riêng

Xác nhận từ code:

- `App\Models\User extends Illuminate\Foundation\Auth\User` (session auth, Breeze)
- `app/Models/User.php` hiện đã có `userCreditCards()` (thêm ở phiên trước) — giữ nguyên
- `users.id` = `bigint unsigned AUTO_INCREMENT` → khớp kiểu với cột `user_id` ở DB CC

## 15.2 Cách implement

```php
// app/Models/User.php — chỉ đổi import + tên class, KHÔNG đổi logic
use App\Models\CreditCard\UserCard;

public function userCards(): HasMany
{
    // KHÔNG physical FK, chỉ logical reference
    return $this->hasMany(UserCard::class, 'user_id');
}
```

**Không** dùng `HasMany` với `->constrained()`. Eloquent `belongsTo`/`hasMany` **không cần** FK vật
lý để hoạt động — nó chỉ sinh SQL `JOIN ... ON users.id = user_cards.user_id`. Vì 2 bảng nằm ở
2 DB khác nhau, Laravel vẫn chạy được vì mỗi query chỉ chạm 1 connection.

## 15.3 4 hệ quả bắt buộc phải biết

| # | Hệ quả | Xử lý |
|---|---|---|
| 1 | **Không JOIN được.** `User::with('userCards')` dùng 2 query riêng → OK. Nhưng `User::join('credit_card_user_cards')` → **SQL error** | Cấm join xuyên DB. Dùng `hasMany` + `with()`. Thêm test chặn |
| 2 | **Mất FK `ON DELETE CASCADE`** khi xoá user | `User::destroy()` xoá cứng (đã kiểm tra). Thêm `credit-card:prune-orphans` command + ghi vào docs. Xem Risk R1 |
| 3 | `RefreshDatabase` trong test chỉ migrate connection mặc định (sqlite) | Bắt buộc cấu hình connection `creditcard` cho test. Xem §16.5, bước 1A.1 |
| 4 | 2 bảng `migrations` trùng tên ở 2 DB | Không sao — khác database. Cần `--database=creditcard` khi dùng `migrate:status` |

## 15.4 Auth giữ nguyên 100%

- Không model User mới, không guard mới, không bảng `credit_card_users`
- Không sửa `routes/auth.php`, không sửa `AuthenticatedSessionController`
- Role/permission (`spatie`) không đổi
- CC route group giữ `middleware('auth')`, không thêm `verified` (đồng nhất `wallet`/`orders`)

---

# 16. LARAVEL ARCHITECTURE

## 16.1 Cấu trúc thư mục

```
app/Models/CreditCard/
├── Bank.php                       → credit_card_banks
├── Product.php                    → credit_card_products
├── Category.php                   → credit_card_categories
├── PolicyTemplate.php             → credit_card_policy_templates
├── Policy.php                     → credit_card_policies
├── PolicyTier.php                 → credit_card_policy_tiers
├── PolicyTierCategory.php         → credit_card_policy_tier_categories
├── UserCard.php                   → credit_card_user_cards
├── StatementPeriod.php            → credit_card_statement_periods
└── Transaction.php                → credit_card_transactions
    (tất cả: protected $connection = 'creditcard')

app/Services/CreditCard/
├── StatementPeriodService.php     → resolve/tạo kỳ, clamp ngày, basis
├── CashbackPolicyResolver.php     → policy + tier + rule (tra cứu, read-only)
├── CashbackCalculator.php         → PURE. Không DB, không side-effect
├── PolicyCloneService.php         → clone template→policy, clone tier, save-as-template,
│                                   → tạo version mới, validate không chồng lấn
└── CreditCardTransactionService.php → create/update transaction, gán kỳ, chạy pipeline,
                                      ghi snapshot, khoá theo trạng thái kỳ

app/Http/Controllers/CreditCard/
├── CreditCardController.php       → TỔNG QUAN / dashboard
├── UserCardController.php         → CRUD thẻ
├── PolicyController.php           → CRUD policy + template + tier
└── TransactionController.php      → CRUD giao dịch

app/Policies/CreditCard/           → UserCardPolicy, PolicyPolicy, TransactionPolicy
resources/views/credit-card/       → giữ layout/sidebar/placeholder, thêm view theo màn hình
database/migrations/creditcard/    → migrations riêng (xem §17)
database/seeders/CreditCardSeeder.php
```

## 16.2 Vì sao CHỌN sub-namespace `CreditCard` (có điều kiện)

**Điều kiện bắt buộc:** xoá cả 4 model root hiện tại trong **cùng một bước**:
`app/Models/CreditCard.php`, `CreditCardBank.php`, `CreditCardCategory.php`, `UserCreditCard.php`.

Lý do:

- Có precedent trong repo: `App\Services\Lazada\`, `App\Services\ShopeeFood\`, `App\Services\TikTok\`,
  `App\Services\PriceComparison\`, `App\Services\PromotionNews\`
- Tránh nhầm lẫn `CreditCard` (product) vs `UserCard` (thẻ thật) — vấn đề P6
- 10 model trong `App\Models` sẽ làm file phẳng rối (hiện đã có 20 model)

⚠️ **Rủi ro bắt buộc phải biết (P12):** nếu còn class `App\Models\CreditCard` tồn tại song song
với namespace `App\Models\CreditCard`, sẽ có ambiguity khi `use App\Models\CreditCard;`.
→ **Quy tắc: sau 1A, không bao giờ tạo lại class `App\Models\CreditCard`.**
Ghi vào comment đầu `app/Models/CreditCard/` để người sau không vô tình phá.

**Tương đương nếu chọn phẳng:** `App\Models\CreditCardBank`, `CreditCardProduct`, ...
Cũng hợp lệ (khớp 20 model phẳng hiện có) và **tránh được rủi ro P12 hoàn toàn**.
Đây là **quyết định cần bạn chốt** trước khi code.

## 16.3 Services — trách nhiệm chính xác (không over-engineer)

| Service | Trách nhiệm DUY NHẤT | KHÔNG làm |
|---|---|---|
| `StatementPeriodService` | `(date, userCard) → period`. Tạo period nếu chưa có. Clamp ngày 29/30/31. Áp `statement_date_basis`. Áp `spending_deadline_day`. | Không tính tiền. Không query policy |
| `CashbackPolicyResolver` | `(userCard, period, transaction) → {policy, tier, rule} \| null`. Chọn policy theo `effective_from/to` + `status`. Chọn tier theo `tier_application_mode`. Tìm rule theo category. | Không tính tiền. Không ghi DB |
| `CashbackCalculator` | `(resolvedContext, transaction, quotaState) → CashbackResult`. **PURE.** Áp min spend → rate → 3 quota → rounding. | ⛔ Không query DB. ⛔ Không ghi DB. ⛔ Không gọi service khác |
| `PolicyCloneService` | `cloneTemplateToCard()`, `cloneTier()`, `savePolicyAsTemplate()`, `createNewVersion()`, `validateTiers()`. Mọi thao tác **deep copy**. | Không tính cashback. Không validate form HTTP |
| `CreditCardTransactionService` | Orchestrator: authorize → insert → gán kỳ → chạy pipeline tuần tự → ghi snapshot → khoá nếu kỳ finalized. | Không chứa công thức tính (đã ở Calculator) |

**5 service. Không thêm interface, không thêm strategy pattern, không thêm event.**
Cân nhắc `CreditCardCashbackService` (ghi vào `wallet_transactions` ở DB chính) — **HOÃN, không làm
ở 1A** (§29 nói rõ chưa làm cashback production).

## 16.4 ⚠️ Xung đột tên bắt buộc phải tránh

| Tên | Đã tồn tại ở | Xung đột? |
|---|---|---|
| `App\Services\CashbackCalculator` | affiliate (Shopee/Lazada/TikTok) | 🔴 **CAO** — CC phải ở `App\Services\CreditCard\CashbackCalculator` |
| `App\Services\BankExportService` | export bảng ngân hàng (chuyển khoản) | 🟡 Trùng *nghĩa* "ngân hàng" nhưng khác class → chấp nhận được |
| `App\Models\CreditCard` | product catalog | 🔴 **CAO** — xem P12 |
| `App\Models\Transaction` | affiliate transaction (deprecated) | 🟡 `CreditCard\Transaction` khác namespace → OK |
| `App\Models\Category` | không có | ✅ |
| `App\Models\Bank` | không có | ✅ |
| `App\Services\CreditCard\StatementPeriodService` | không có | ✅ |

## 16.5 Database connection

```php
// config/database.php — thêm, KHÔNG sửa connection hiện tại
'creditcard' => [
    'driver' => 'mysql',
    'host'        => env('DB_CREDITCARD_HOST', env('DB_HOST', '127.0.0.1')),
    'port'        => env('DB_CREDITCARD_PORT', env('DB_PORT', '3306')),
    'database'    => env('DB_CREDITCARD_DATABASE', env('DB_DATABASE', 'hoantien_creditcard')),
    'username'    => env('DB_CREDITCARD_USERNAME', env('DB_USERNAME', 'root')),
    'password'    => env('DB_CREDITCARD_PASSWORD', env('DB_PASSWORD', '')),
    'unix_socket' => env('DB_CREDITCARD_SOCKET', ''),
    'charset'     => 'utf8mb4',
    'collation'   => 'utf8mb4_unicode_ci',
    'prefix'      => '',
    'prefix_indexes' => true,
    'strict'      => true,
    'engine'      => null,
],
```

**Fallback có chủ đích:** nếu `DB_CREDITCARD_DATABASE` chưa set → dùng `DB_DATABASE`.
→ Test sqlite (`:memory:`) không cần env riêng, migration tự chạy trên connection chính.
Sau khi tạo DB thật, chỉ cần thêm 1 dòng vào `.env`.

**Model:**

```php
abstract class CreditCardModel extends Model   // hoặc trait
{
    protected $connection = 'creditcard';
}
```

Dùng base class/trait để 10 model không lặp 10 lần. Base class **không** dùng `SoftDeletes`
(chỉ `Transaction` cần).

**Migrations:** xem §17.

---

# 17. MIGRATION STRATEGY

## 17.1 Hiện trạng

```
DB hoantienaff:
  migrations      → có 4 record credit_card (ĐÃ CHẠY)
  credit_card_banks        0 dòng   ← legacy
  credit_card_categories   0 dòng   ← legacy (sai nghĩa)
  credit_cards             0 dòng   ← legacy (tên sai)
  user_credit_cards        0 dòng   ← legacy (có FK users)

DB hoantien_creditcard:  CHƯA TỒN TẠI
```

## 17.2 Vấn đề then chốt (P7)

Nếu chạy `php artisan migrate --database=creditcard`, Laravel quét **non-recursive**
`database/migrations/*_*.php` (đã xác minh trong `Migrator::getMigrationFiles()`:
`$this->files->glob($path.'/*_*.php')`).
→ **4 migration legacy SẼ BỊ CHẠY LẠI** trên DB mới → tạo bảng sai shape, và `user_credit_cards`
**FAIL** vì FK tới `users` không tồn tại trong `hoantien_creditcard`.

## 17.3 Giải pháp: tách thư mục migration + migration path riêng

**Không sửa 4 file migration cũ. Không drop bảng cũ. Zero downtime.**

```
database/migrations/
├── 0001_01_01_000000_create_users_table.php          ← chuỗi chính (không đổi)
├── ... (46 file hiện tại, không đổi)
├── 2026_09_29_000001_create_credit_card_banks_table.php     ← LEGACY, ĐỂ NGUYÊN
├── 2026_09_29_000002_create_credit_card_categories_table.php ← LEGACY
├── 2026_09_29_000003_create_credit_cards_table.php          ← LEGACY
├── 2026_09_29_000004_create_user_credit_cards_table.php     ← LEGACY
└── creditcard/                                           ← THƯ MỤC MỚI
    ├── 2026_10_01_000001_create_credit_card_banks_table.php
    ├── 2026_10_01_000002_create_credit_card_products_table.php
    ├── 2026_10_01_000003_create_credit_card_categories_table.php
    ├── 2026_10_01_000004_create_credit_card_policy_templates_table.php
    ├── 2026_10_01_000005_create_credit_card_policies_table.php
    ├── 2026_10_01_000006_create_credit_card_policy_tiers_table.php
    ├── 2026_10_01_000007_create_credit_card_policy_tier_categories_table.php
    ├── 2026_10_01_000008_create_credit_card_user_cards_table.php
    ├── 2026_10_01_000009_create_credit_card_statement_periods_table.php
    └── 2026_10_01_000010_create_credit_card_transactions_table.php
```

Cơ chế (3 tầng, **không sửa file cũ**):

| Tầng | Cách | Kết quả |
|---|---|---|
| 1 | Thư mục con → glob non-recursive **không** thấy | `php artisan migrate` (thường) **không** chạy CC. Main chain an toàn tuyệt đối |
| 2 | Mỗi migration CC khai `protected $connection = 'creditcard';` | `Migrator::resolveConnection($migration->getConnection())` chạy đúng connection. Đã verify `Illuminate\Database\Migrations\Migration` có `protected $connection` + `getConnection()` |
| 3 | `php artisan migrate --database=creditcard --path=database/migrations/creditcard --realpath` | Repository ghi vào bảng `migrations` **trong DB `hoantien_creditcard`** (khác DB ⇒ tách bạch hoàn toàn với `hoantienaff.migrations`) |

⚠️ `migrations.table` trong `config/database.php` là config **chung** tên `'migrations'` — nhưng vì
2 connection trỏ 2 database khác nhau nên 2 bảng `migrations` nằm ở 2 DB khác nhau. **Không xung đột.**

## 17.4 Wrapper command (khuyến nghị)

```bash
php artisan credit-card:migrate            # = migrate --database=creditcard --path=database/migrations/creditcard --realpath
php artisan credit-card:migrate --fresh
php artisan credit-card:migrate:status
```

Wrapper chạy `--force` khi `APP_ENV=production`.
Không dùng wrapper ở 1A nếu bạn muốn giảm scope — nhưng **phải** ghi lệnh dài vào
`docs/deployment.md`, nếu không chắc chắn sẽ có người chạy `php artisan migrate` và tưởng CC đã
migrate.

## 17.5 Trình tự chuyển đổi (Current → Target)

```
BƯỚC 0  Ghi nhận hiện trạng
        4 bảng legacy, 0 dòng. KHÔNG drop. KHÔNG sửa migration cũ.
                    ↓
BƯỚC 1  Tạo DB: CREATE DATABASE hoantien_creditcard
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                    ↓
BƯỚC 2  Thêm connection 'creditcard' + .env + wrapper command
                    ↓
BƯỚC 3  Chạy 10 migration mới trên DB creditcard
        → DB creditcard có schema đầy đủ, hoàn chỉnh
                    ↓
BƯỚC 4  Seed: banks, categories system, 1 system template + policy mẫu
                    ↓
BƯỚC 5  Deploy code mới (models/services/controllers trỏ connection 'creditcard')
        → Đọc/ghi vào hoantien_creditcard
                    ↓
        ⚠️ TRẠNG THÁI SONG SONG 2 SCHEMA
        4 bảng legacy trong hoantienaff: KHÔNG còn model nào trỏ tới
        → tự nhiên "chết", không gây sai logic. Nhưng phải cẩn thận:
          KHÔNG được để 2 nguồn sự thật cùng tồn tại.
                    ↓
BƯỚC 6  (Phase 1B, SAU khi đã verify ≥1 kỳ sao kê chạy đúng)
        mysqldump 4 bảng legacy → file backup
        RENAME 4 bảng legacy thành *_legacy_20260929
        hoặc DROP (đã backup, 0 dòng)
```

**Về mặt dữ liệu: không có mất dữ liệu nào** — cả 4 bảng legacy đều có 0 dòng.
Rủi ro là **rollback**, không phải mất dữ liệu: nếu deploy code mới có lỗi, chỉ cần deploy lại
commit cũ — commit cũ đọc bảng legacy vẫn còn nguyên. Đây là lý do **không drop ở bước 5**.

## 17.6 Thứ tự tạo bảng (FK dependency)

```
banks ──┐
         ├──► products ──┐
categories ──────────────┤
                         ├──► user_cards ──► statement_periods ──► transactions
policy_templates ──► policies ──► policy_tiers ──► policy_tier_categories
                              └──► transactions (SET NULL)
                              └──► statement_periods (SET NULL)
```

---

# 18. RISKS

| ID | Mức | Rủi ro | Ảnh hưởng | Giảm thiểu |
|---|---|---|---|---|
| **R1** | 🔴 Cao | Mất physical FK `user_id → users.id` khi tách DB | Xoá user → thẻ mồ côi, không cascade | `credit-card:prune-orphans` command + ghi rõ trong docs. Chấp nhận (yêu cầu §6 cho phép) |
| **R2** | 🔴 Cao | Test dùng sqlite `:memory:`; connection `creditcard` mới có thể không được migrate | 18 test hiện tại **fail hàng loạt** | Fallback `DB_CREDITCARD_DATABASE → DB_DATABASE` (§16.5). Verify ở **bước 1A.1**, trước mọi thứ khác |
| **R3** | 🔴 Cao | `php artisan migrate` vô tình chạy 4 migration legacy lên DB creditcard | Migrate fail giữa chừng | Tách thư mục (glob non-recursive) + `protected $connection` + wrapper command (§17.3) |
| **R4** | 🔴 Cao | Trùng tên `App\Services\CashbackCalculator` (affiliate) | Đè logic affiliate = **sự cố production** | Bắt buộc `App\Services\CreditCard\CashbackCalculator`. Thêm test khẳng định 2 class khác nhau |
| **R5** | 🟠 TB | Trùng `App\Models\CreditCard` class vs namespace `App\Models\CreditCard\` | "Class not found" khó debug | Xoá cả 4 model root trong cùng 1 commit. Ghi cảnh báo ở đầu thư mục |
| **R6** | 🟠 TB | Retroactive tier → cashback chỉ chốt khi kỳ finalize | User thấy số tiền "nhảy" | Bắt buộc nhãn "tạm tính" + `is_locked=false` khi kỳ open. Document trong UI |
| **R7** | 🟠 TB | Không quy định thứ tự tiêu thụ quota → kết quả không tất định | Cùng dữ liệu, 2 lần chạy → 2 kết quả | Cứng `ORDER BY transaction_date, id`. Test tính lại 2 lần cho kết quả giống hệt |
| **R8** | 🟠 TB | `statement_day = 31` ở tháng 28/29/30 ngày | Period sai, giao dịch rơi sai kỳ | Clamp về ngày cuối tháng, **không roll-over**. 4 test case (Feb thường, Feb nhuận, T4, T2) |
| **R9** | 🟠 TB | Nhập/sửa `posted_date` sau khi giao dịch đã gán kỳ | Giao dịch sai kỳ | Chỉ gán lại khi kỳ `status != 'finalized'`. Cấm sửa khi `is_locked` |
| **R10** | 🟠 TB | 2 schema cùng tồn tại trong giai đoạn chuyển đổi | 2 nguồn sự thật → bug khó tái hiện | Xoá model trỏ bảng legacy **ngay** ở bước 5. Tên bảng legacy đủ khác để không nhầm |
| **R11** | 🟡 Thấp | Tiền tệ dùng float khi tính toán | Sai số 1đ, tích luỹ dài hạn | `decimal` toàn bộ. `CashbackCalculator` nhận/trả decimal, không float. Test rounding |
| **R12** | 🟡 Thấp | Query JOIN xuyên DB (`join('users')`) | SQL error runtime | Cấm trong CC. Test khẳng định `User::with('userCards')` hoạt động |
| **R13** | 🟡 Thấp | `WalletTransaction` (DB chính) tương lai cần tham chiếu `credit_card_transactions` (DB CC) | Không có FK | `reference_type='credit_card_transaction'` + `reference_id=<id>` — logical reference, đúng pattern sẵn có. **Phase 1B+** |
| **R14** | 🟡 Thấp | `credit_card_categories` legacy đang trống nhưng nếu có data sẽ vỡ khi tái định nghĩa | Mất dữ liệu category | **Đã verify 0 dòng.** Bước 5 giữ bảng cũ, chỉ đổi model trỏ bảng mới |
| **R15** | 🟡 Thấp | `migrate:fresh` trên DB chính không xoá DB creditcard | Dev confusion | Document rõ 2 lệnh tách biệt trong `docs/deployment.md` |
| **R16** | 🟡 Thấp | `statement_day`/`payment_due_day`/`spending_deadline_day` là `tinyint` 1-31, MySQL không có CHECK constraint ở bản 5.7 | Giá trị 0 hoặc 32 lọt vào DB | Validate 1-31 ở FormRequest **và** trong `StatementPeriodService` (defense in depth) |
| **R17** | 🟡 Thấp | Policy version tăng vô hạn nếu user sửa liên tục | Bảng phình | Đây là **đúng thiết kế** (bảo toàn lịch sử). Giữ 1 policy `draft`; chỉ tạo version mới khi user xác nhận |
| **R18** | 🟡 Thấp | Tier min/max chồng lấn nếu user nhập tay | Tier không xác định | `PolicyCloneService::validateTiers()` chặn lúc **lưu policy** (không chặn lúc clone) |

### Ưu tiên xử lý trước khi viết dòng code đầu tiên

**R2 → R3 → R4.** Ba cái này quyết định mọi thứ còn lại có đúng hay không.

---

# 19. PHASE 1A IMPLEMENTATION PLAN

> Mỗi bước phải **PASS test** trước khi sang bước sau. Không gộp 2 bước.

### Phase 1A.1 — Database connection *(làm ĐẦU TIÊN, chưa đụng schema)*

1. Thêm `creditcard` connection vào `config/database.php` (fallback `DB_DATABASE`)
2. Thêm `DB_CREDITCARD_*` vào `.env.example` (giá trị rỗng = fallback)
3. Tạo DB `hoantien_creditcard`
4. Thêm `protected $connection = 'creditcard'` cho migration mới (chưa tạo file)
5. Tạo `credit-card:migrate` command
6. **Test:** chạy `php artisan test` — 18 test CC cũ **phải vẫn xanh** (bằng chứng R2 đã xử lý)
7. **Test:** `php artisan db:show --database=creditcard`

### Phase 1A.2 — Core migrations

1. Tạo `database/migrations/creditcard/`
2. 10 file migration theo thứ tự §17.6, mỗi file `protected $connection = 'creditcard'`
3. Bảng đầu tiên: banks → products → categories → templates → policies → tiers →
   tier_categories → user_cards → statement_periods → transactions
4. **Test:** `Schema::hasTable()` cho cả 10 bảng trên connection `creditcard`
5. **Test:** `Schema::hasColumns()` — assert **KHÔNG** có `cashback_percent` trong
   `credit_card_categories`, **KHÔNG** có `card_number`/`cvv`/`exp_date` trong `credit_card_user_cards`
6. **Test:** `php artisan credit-card:migrate:status`
7. **VERIFY:** `php artisan migrate` (thường, connection chính) **KHÔNG** tạo bảng nào trong
   `hoantien_creditcard`

### Phase 1A.3 — Models

1. Xoá 4 model root: `CreditCard.php`, `CreditCardBank.php`, `CreditCardCategory.php`, `UserCreditCard.php`
2. Tạo `app/Models/CreditCard/` — base class/trait khai `protected $connection = 'creditcard'`
3. Tạo 10 model + fillable + casts + relationships 2 chiều
4. Cập nhật `User::userCreditCards()` → trỏ `CreditCard\UserCard`
5. Giữ nguyên `$hidden` (chuyển từ `UserCreditCard` sang `UserCard`)
6. Thêm scope: `scopeActive`, `scopeSystem`, `scopeOwnedBy($userId)`, `scopeForPeriod`
7. **Test:** 10 model create/read được trên connection creditcard
8. **Test:** mọi relationship 2 chiều hoạt động
9. **Test:** `User::with('userCards')` hoạt động **xuyên DB** (R12)
10. **Test:** `assertNotSame(App\Services\CashbackCalculator::class, App\Services\CreditCard\CashbackCalculator::class)` (R4)

### Phase 1A.4 — Access control

1. Tạo `app/Policies/CreditCard/UserCardPolicy.php` + `PolicyPolicy`
2. Gắn policy vào controller
3. Thay `where('user_id', auth()->id())` thủ công bằng `$this->authorize()`
4. **Test:** user A **không** đọc/sửa/xoá được dữ liệu của user B qua **mọi** endpoint

### Phase 1A.5 — Services (chưa nối UI)

1. `StatementPeriodService` — resolve period, clamp 29/30/31, áp basis, áp spending deadline
2. `CashbackPolicyResolver` — resolve policy/tier/rule
3. `CashbackCalculator` — **PURE**, 9 bước §14.2
4. `PolicyCloneService` — clone template→card, clone tier, save-as-template, create version, validate tiers
5. `CreditCardTransactionService` — orchestrator
6. **Test `StatementPeriodService`:** 4 case clamp (Feb thường / Feb nhuận / T4 30 ngày / day=31)
7. **Test `StatementPeriodService`:** statement_day=15 → kỳ 16/08→15/09; giao dịch 15/09 thuộc kỳ đó,
   16/09 thuộc kỳ sau
8. **Test `StatementPeriodService`:** `posted_date` ảnh hưởng khi `basis='posted_date'`
9. **Test `CashbackCalculator`:** min spend chưa đạt → 0; đạt → tính
10. **Test `CashbackCalculator`:** 3 loại quota, mỗi loại 2 case (chưa chạm / đã chạm)
11. **Test `CashbackCalculator`:** **không** truy cập DB (unit test thuần, không `RefreshDatabase`)
12. **Test `CashbackCalculator`:** category không có rule → 0; category không thuộc user → exception
13. **Test tính lại tất định:** chạy pipeline 2 lần → kết quả giống hệt (R7)
14. **Test `PolicyCloneService`:** clone template **không** tạo bất kỳ UPDATE nào lên template
    (dùng `DB::listen` đếm query)
15. **Test `PolicyCloneService`:** sửa policy sau khi clone → thẻ gốc **không** đổi
16. **Test `PolicyCloneService`:** clone tier copy **đủ** 6 field nghiệp vụ
17. **Test version:** sửa policy → version cũ `status='superseded'`, transaction cũ giữ nguyên
    `cashback_rate` (§21 — **test quan trọng nhất**)
18. **Test retroactive:** tier đổi giữa kỳ → mọi giao dịch kỳ đó dùng tier cuối (§22)

### Phase 1A.6 — Seed data

1. `database/seeders/CreditCardSeeder.php`
2. Seed **banks**: MB, VPBank, Techcombank, ACB, Sacombank, Agribank, Vietcombank, BIDV, SHB, VIB
3. Seed **system categories**: Online, Dining, Supermarket, Fuel, Travel, Pharmacy, Entertainment, Others
4. Seed **1 system template** "MB JCB Standard" + policy detached + 3 tier + rule mẫu
   ⚠️ Con số 200tr/500tr trong tier mẫu nằm ở **SEEDER**, không nằm trong code (§11.2)
5. Idempotent (`updateOrCreate` theo slug) — chạy lại nhiều lần không nhân bản
6. **Test:** chạy seeder 2 lần → count không đổi
7. **Test:** seed **không** tạo dữ liệu user (không có bảng user trong DB CC)

### Phase 1A.7 — Cập nhật UI hiện có (chỉ phần bảo trì, **không** làm UI mới)

1. Sửa `CreditCardController@index` → query trên model mới, **giữ** pattern "số thật / 'Chưa có dữ liệu'"
2. Hiển thị đúng theo §27: tên thẻ do user đặt, `****last4`, hạn mức, kỳ sao kê hiện tại
3. Cập nhật `partials/sidebar.blade.php` — đổi nhãn/nội dung menu cho khớp domain 1A
4. Sửa `partials/module-info.blade.php` — text
5. **KHÔNG** xoá `placeholder.blade.php` — 4 trang placeholder vẫn dùng
6. **Test:** 18 test cũ — cập nhật phần assert schema (§8.10), **giữ nguyên** phần assert route/UI

### Phase 1A.8 — Test suite

1. `tests/Feature/CreditCard/SchemaTest.php` — 10 bảng, cột, index, FK, **cấm** cột nhạy cảm
2. `tests/Feature/CreditCard/ModelTest.php` — relationship, cross-DB, scope
3. `tests/Feature/CreditCard/AccessControlTest.php` — user A không chạm dữ liệu user B
4. `tests/Feature/CreditCard/LegacyIsolationTest.php` — 4 bảng legacy còn nguyên, không model nào trỏ tới
5. `tests/Unit/CreditCard/CashbackCalculatorTest.php` — **pure unit**, không DB
6. `tests/Unit/CreditCard/StatementPeriodDateTest.php` — clamp, basis, boundary
7. `tests/Feature/CreditCard/PolicyVersionTest.php` — historical integrity
8. `tests/Feature/CreditCard/QuotaTest.php` — 3 loại quota + thứ tự tất định
9. **Test cuối:** `php artisan test` — **toàn bộ suite xanh**, không regression module cũ

### Ngoài phạm vi 1A (không làm)

❌ Cashback calculator production-hardening ❌ Dashboard §27 đầy đủ ❌ Reports/charts
❌ Notifications ❌ Import sao kê từ file/email ❌ Banking API / Open Banking
❌ Ghi cashback vào `wallet_transactions` ❌ Multi-user demo data
❌ Drop bảng legacy (→ Phase 1B)

---

# 20. FINAL RECOMMENDATION

## 20.1 Quyết định kiến trúc

> **Tách module Thẻ tín dụng sang database `hoantien_creditcard` với connection `creditcard`.
> Giữ nguyên 100% UI, route, navigation và test hiện có.
> Thay 4 bảng legacy bằng 10 bảng mới trên DB riêng. Tái sử dụng `hoantienaff.users` qua logical
> reference `user_id` — không FK vật lý. Policy là append-only version; transaction mang snapshot
> cashback đầy đủ; statement period có vòng đời open → finalized với snapshot khi chốt.**

## 20.2 7 quyết định cốt lõi (cần bạn duyệt)

| # | Quyết định | Lý do |
|---|---|---|
| D1 | **Connection riêng `creditcard` → DB `hoantien_creditcard`** | Tách bạch domain, cô lập rủi ro, đúng yêu cầu §7. Fallback về `DB_DATABASE` để test sqlite không vỡ |
| D2 | **Sub-namespace `app/Models/CreditCard/` + `app/Services/CreditCard/`**, xoá 4 model root | Có precedent (`Lazada/`, `TikTok/`, `ShopeeFood/`). **Bắt buộc xoá cả 4 model root cùng lúc** để tránh P12. ⚠️ Có phương án phẳng an toàn hơn — xem §16.2 |
| D3 | **Template và Policy dùng chung 4 bảng** (`policy_templates`, `policies`, `policy_tiers`, `policy_tier_categories`). Template = policy detached (`user_card_id = NULL`) | Deep clone tự nhiên, không shared mutable relationship, giảm 7 bảng xuống 4. Đúng §10 |
| D4 | **Policy append-only versioning** (`version_no` + `effective_from/to` + `status`), transaction mang snapshot (`policy_id`, `policy_tier_id`, `policy_tier_category_id`, `cashback_rate`, `cashback_amount`, `calc_meta`) | Dùng **cả 3 lớp bảo vệ** (L1 append-only + L2 FK thật + L3 snapshot value) → historical integrity không thể vỡ. Đúng §21/§23 |
| D5 | **Category = 1 bảng** phân biệt bằng `scope` + `owner_user_id` (sentinel `0` cho system). **Không** chứa cashback/quota | Master và User hành xử giống nhau trong policy. Sentinel 0 là cách duy nhất UNIQUE index MySQL thật sự chạy. Đúng §10/§12 |
| D6 | **Statement period hybrid**: `open` → query realtime; `finalized` → đọc snapshot + `is_locked = true`. 3 mốc ngày tách bạch (`statement_day` / `payment_due_day` / `spending_deadline_day`) + `statement_date_basis` enum | Vừa sống vừa bất biến. Hỗ trợ retroactive tier bắt buộc cần finalize. Đúng §19/§22/§24 |
| D7 | **5 service**, trong đó `CashbackCalculator` **PURE** (không DB, không side-effect), nhận `quotaState` làm tham số | Test được 100% không cần DB. Tránh over-engineer. **Bắt buộc** khác namespace với `App\Services\CashbackCalculator` của affiliate |

## 20.3 Kiểm chứng bằng Dashboard mục tiêu (§27)

Tất cả đều **suy ra được** từ schema §8 mà không cần thêm cột nào:

| Hiển thị | Nguồn |
|---|---|
| `MB JCB ****1234` | `user_cards.name` + `card_number_last4` |
| Hạn mức `50.000.000` | `user_cards.credit_limit` |
| Tổng chi tiêu `18.500.000` | `SUM(transactions.amount)` kỳ `open`, `deleted_at IS NULL` |
| Chi tiêu trong danh mục hoàn `14.000.000` | `SUM` where `is_eligible = true` |
| Chi tiêu ngoài `4.500.000` | `tổng − eligible` |
| Minimum spend `20.000.000` | `policies.min_total_spend` (policy resolve cho kỳ) |
| Còn thiếu `1.500.000` | `min_total_spend − eligible_spend` (suy ra) |
| Cashback rate `8%` | ⚠️ **KHÔNG** phải field cố định. = `total_cashback / eligible_spend * 100` (effective rate), sẵn cột `statement_periods.effective_cashback_rate` |
| Quota `800.000` | `policies.max_cashback_total_per_period` |
| Đã dùng `620.000` | `SUM(transactions.cashback_amount)` trong kỳ |
| Còn `180.000` | `quota − đã dùng` (suy ra) |
| Tier tiếp theo `25.000.000` | `policy_tiers.min_total_spend` kế tiếp sau tier hiện tại |
| Còn thiếu `6.500.000` | `next_tier.min_total_spend − eligible_spend` (suy ra) |

**Kết luận:** schema §8 **đủ** để sinh ra toàn bộ dashboard mục tiêu. Không cần thêm cột nào.
`effective_cashback_rate` đã được đặt sẵn trên `statement_periods` để kỳ `finalized` hiển thị
nhanh không cần tính lại.

## 20.4 3 câu hỏi cần bạn quyết trước khi code

1. **D2 — Model namespace:** sub-namespace `App\Models\CreditCard\` (cần xoá 4 model root, có rủi ro P12
   nếu làm dở) hay phẳng `App\Models\CreditCard*` (khớp convention hiện tại, zero rủi ro)?
   *Khuyến nghị: phẳng — an toàn hơn, ít việc hơn, cùng kết quả.*

2. **§11.2 — Default tier:** dùng `max_total_spend = NULL` (không trần, sạch, đúng nguyên tắc
   "không hard-code") hay `200.000.000` như ví dụ trong đề bài (đặt trong seeder)?
   *Khuyến nghị: NULL trong code, con số cụ thể (nếu muốn) trong seeder.*

3. **§17.4 — Wrapper command:** có làm `credit-card:migrate` command trong 1A không, hay chỉ ghi
   lệnh dài vào `docs/deployment.md`?
   *Khuyến nghị: làm — tránh người sau chạy nhầm `php artisan migrate`.*

## 20.5 Trạng thái sau khi duyệt

Sau khi bạn duyệt kiến trúc, thứ tự code sẽ là:

```
1A.1 connection  →  1A.2 migrations  →  1A.3 models
   →  1A.4 access control  →  1A.5 services  →  1A.6 seed
   →  1A.7 cập nhật UI hiện có  →  1A.8 test suite
```

Mỗi bước dừng lại chờ test xanh trước khi sang bước kế. Bước 1A.1 và 1A.2 **không** đụng vào
bất kỳ file đang untracked sẵn có, nên có thể rollback an toàn bằng cách bỏ file mới.

---

## PHỤ LỤC — BẢNG ĐỐI CHIẾU YÊU CẦU NGHIỆP VỤ → THỨ ĐÃ ĐỀ XUẤT

| Yêu cầu | Mục | Nơi đáp ứng |
|---|---|---|
| §6 Dùng chung `users` | 15 | `user_id` logical, không FK |
| §7 DB riêng | 17 | connection `creditcard`, thư mục migration riêng |
| §8A Bank | 8.1 | `credit_card_banks` |
| §8B Product | 8.2 | `credit_card_products` |
| §8C User Card | 8.8 | `credit_card_user_cards` (+ `name` do user đặt) |
| §8D Category Master | 8.3 | `credit_card_categories` `scope='system'` |
| §8E User Category | 8.3 | `credit_card_categories` `scope='user'` |
| §8F Policy Template | 9 | `credit_card_policy_templates` + policy detached |
| §8G Card Policy | 8.5 | `credit_card_policies` `user_card_id NOT NULL` |
| §8H Policy Tier | 8.6 | `credit_card_policy_tiers` |
| §8I Tier Category Rule | 8.7 | `credit_card_policy_tier_categories` |
| §8J Statement Period | 8.9 | `credit_card_statement_periods` |
| §8K Transaction | 8.10 | `credit_card_transactions` |
| §9 Tạo thẻ (6 trường) | 8.8 | `name`, `product_id`(→bank), `credit_limit`, policy, `statement_day`, `spending_deadline_day` |
| §10 Load template = clone | 9.2 | `PolicyCloneService::cloneTemplateToCard()` |
| §11 System vs User template | 9.3 | `scope` + `owner_user_id` + `is_builtin` |
| §12 Category master không chứa cashback | 10 | schema 8.3 xác nhận |
| §13 Policy category rule | 8.7 | `cashback_rate` + 2 quota |
| §14 Nhiều tier | 11.1 | N tier/policy, không chồng lấn |
| §15 Default tier | 11.2 | 1 tier, `0 → NULL` |
| §16 Clone tier | 11.3 | copy đủ 6 field, record độc lập |
| §17 3 loại quota | 8.7 | per-transaction / per-category / per-period-total |
| §18 Minimum spend | 8.5 + 8.6 + 8.7 | policy gate + tier band + rule min |
| §19 Statement + posted_date | 13 | 3 mốc ngày + `statement_date_basis` + `calc_basis` |
| §20 Transaction nhập tay, không nhập cashback | 14.1 | form không có ô cashback |
| §21 Historical policy | 12 | 3 lớp bảo vệ |
| §22 Retroactive / progressive | 12.4 | `policies.tier_application_mode` |
| §23 Cashback snapshot | 8.10 + 14.2 | 6 cột snapshot + `calc_meta` + `is_locked` |
| §24 Statement period aggregate | 8.9 | Hybrid realtime/snapshot |
| §25 Bảng cần giữ/bỏ/thêm | 8 | Giữ: banks. Bỏ: `credit_cards`, `category_id`. Thêm: 7 bảng |
| §26 Laravel architecture | 16 | 4 nhóm thư mục + 5 service |
| §27 Dashboard mục tiêu | 20.3 | 100% suy ra được |
| §28 UI audit | 3 + 6 | Phân loại A/B/C/D |
| §29 Phase 1A scope | 19 | 1A.1 → 1A.8 |
| §30 Không code | — | Báo cáo này chỉ đọc, không sửa source |

---

*Báo cáo audit — 2026-09-29. Không có file source nào bị sửa. 4 bảng legacy giữ nguyên 0 dòng.
18 test hiện tại vẫn PASS.*
