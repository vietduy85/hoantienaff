# CREDIT CARD PHASE_1C FINAL REPORT

> Giai đoạn 1C — HTTP API cấu hình chính sách cashback (Policy/Tier/Category Rule/Template), rate limit, UI cấu hình.
> Ngày hoàn tất: 2026-09-30. Nhánh: `main`. Đã backup dữ liệu, **chưa commit/push**.

---

## 1. Tổng quan

Phase 1B đã đưa toàn bộ nghiệp vụ (thẻ, danh mục, giao dịch, policy/tier/rule, template) vào tầng
service, nhưng cấu hình chính sách cashback chưa có HTTP API — chỉ qua service. Phase 1C mở API
cho đúng ba nhánh còn thiếu của policy engine và có UI cấu hình.

Kết quả chính:

| Hạng mục | Kết quả |
|---|---|
| Policy mới | 2 + 1 trait (`PolicyTierPolicy`, `PolicyTierCategoryPolicy`, `Concerns\ResolvesOwningCard`) + thêm `create(User, UserCard)` cho `PolicyPolicy` |
| Controller + trait | 4 controller (`PolicyController`, `TierController`, `CategoryRuleController`, `PolicyTemplateController`) + `Concerns\ResolvesCardResources` |
| Form Request mới | 9 (`StorePolicyRequest`, `StorePolicyVersionRequest`, `StoreTierRequest`, `UpdateTierRequest`, `StoreCategoryRuleRequest`, `UpdateCategoryRuleRequest`, `StoreTemplateRequest`, `CloneTierRequest`, `CloneCategoryRuleRequest`) |
| Route API mới | 18 (tổng module: 7 trang + 31 API) |
| Rate limit | Native `throttle:credit-card-api` trên toàn bộ API, per-user, JSON 429 |
| UI | 1 trang cấu hình (`credit-cards.policies`) + mục sidebar "Chính sách" |
| Backup | `storage/app/backups/credit-card/` — manifest `MANIFEST_2026-09-30_071857.txt`, CSV đã verify UTF-8 + số dòng |
| Test mới | `Phase1cHttpTest`: 21 test / 91 assertions |
| Kết quả toàn suite | **1381 passed / 3 failed (3 baseline) / 5623 assertions** |

---

## 2. Mục tiêu và checklist 12 mục

### 2.1 Checklist 12 mục của giai đoạn

| # | Mục | Trạng thái |
|---|---|---|
| 1 | Backup dữ liệu trước khi mở API | ✅ manifest `2026-09-30_071857` |
| 2 | Rate limit toàn bộ API credit card (native, per-user, JSON 429) | ✅ `throttle:credit-card-api` |
| 3 | API liệt kê / tạo / xem / đổi tên policy version của thẻ | ✅ `policies.index/store/show/update` |
| 4 | API tạo version N+1 (supersede version cũ, append-only) | ✅ `policies.versions.store` |
| 5 | API tạo policy scratch | ✅ `policies.store` + mode `scratch` |
| 6 | API clone system template | ✅ `policies.store` + mode `clone_system` |
| 7 | API clone user template (chỉ template của chính user) | ✅ `policies.store` + mode `clone_user` |
| 8 | API lưu cấu hình hiện tại thành user template | ✅ `policies.templates.store` |
| 9 | API bậc: index / create / update / delete / clone (deep copy rule) | ✅ `tiers.*` |
| 10 | API quy tắc: index / create / update / delete / clone | ✅ `rules.*` |
| 11 | UI cấu hình policy (Blade + Alpine) + điều hướng sidebar | ✅ `policies.blade.php` |
| 12 | Test HTTP tự động phủ toàn bộ + toàn suite xanh (chỉ còn 3 baseline) | ✅ 1381 passed |

### 2.2 Ngoài phạm vi (cố ý không làm)

Không sửa cơ chế tính cashback, không thêm progressive, không nhận `cashback_amount` từ client
(giữ nguyên triết lý 1B), không mở thêm dashboard/báo cáo. `credit_card_products` vẫn chưa được
quyết định drop.

---

## 3. Kiến trúc

```
routes/web.php (middleware: web, auth)
   │
   ├── 7 trang Blade ────────────────► CreditCardController
   │                                    └── policies() → credit-cards.policies (1C)
   │
   └── 31 route JSON /thetindung/api ─► 7 controller mỏng
                                          │  1. FormRequest: validate + payload()
                                          │  2. Policy: quyết định 403 (parent-scoped)
                                          │  3. Service: nghiệp vụ + kiểm lại ownership
                                          ▼
                       ┌──────────── tầng service ────────────┐
                       │ PolicyService  TierService           │
                       │ CategoryRuleService  PolicyCloneService │
                       └──────────────┬──────────────────────┘
                                      ▼
                     connection `creditcard` (hoantien_creditcard)
```

Toàn bộ route API nằm dưới middleware `throttle:credit-card-api`; route trang không bị giới hạn.
Nghiệp vụ vẫn nằm ở service — controller chỉ phân tuyến `mode` và authorize.

---

## 4. API 1C — danh sách

### 4.1 Policy version (scope theo thẻ)

| Method | URI | Name | Ghi chú |
|---|---|---|---|
| GET | `/thetindung/api/the/{userCard}/chinh-sach` | `credit-cards.api.policies.index` | version của thẻ, current trước |
| POST | cùng URI | `credit-cards.api.policies.store` | mode `scratch` / `clone_system` / `clone_user` |
| GET | `/chinh-sach/{policy}` | `credit-cards.api.policies.show` | version + tiers + rules |
| PATCH | cùng URI | `credit-cards.api.policies.update` | CHỈ đổi tên (metadata) |
| POST | `/chinh-sach/phien-ban` | `credit-cards.api.policies.versions.store` | version N+1, supersede cũ |
| POST | `/chinh-sach/mau` | `credit-cards.api.policies.templates.store` | lưu thành user template |

Version **luôn** scope theo thẻ bằng `Policy::forCard()` (`versionOfCard()`): hỏi version của thẻ
khác qua URL thẻ này ⇒ **404**, không phải 403 (không lộ sự tồn tại).

### 4.2 Template (chỉ đọc qua API 1C)

| Method | URI | Name |
|---|---|---|
| GET | `/thetindung/api/mau-chinh-sach` | `credit-cards.api.templates.index` |
| GET | `/mau-chinh-sach/{template}` | `credit-cards.api.templates.show` |

Index chỉ trả system template + các template riêng của user; template của người khác ⇒ 403.

### 4.3 Bậc (route phẳng, ownership resolve qua chuỗi)

| Method | URI | Name | Ghi chú |
|---|---|---|---|
| GET | `/chinh-sach/{policy}/bac` | `credit-cards.api.tiers.index` | |
| POST | cùng URI | `credit-cards.api.tiers.store` | chồng lấn khoảng ⇒ 409 |
| PATCH | `/bac/{tier}` | `credit-cards.api.tiers.update` | |
| DELETE | cùng URI | `credit-cards.api.tiers.destroy` | bậc còn rule ⇒ 409 |
| POST | `/bac/{tier}/nhan-ban` | `credit-cards.api.tiers.clone` | deep copy rule sang version khác |

### 4.4 Quy tắc (route phẳng)

| Method | URI | Name | Ghi chú |
|---|---|---|---|
| GET | `/bac/{tier}/quy-tac` | `credit-cards.api.rules.index` | |
| POST | cùng URI | `credit-cards.api.rules.store` | bỏ qua `cashback_amount` |
| PATCH | `/quy-tac/{rule}` | `credit-cards.api.rules.update` | |
| DELETE | cùng URI | `credit-cards.api.rules.destroy` | |
| POST | `/quy-tac/{rule}/nhan-ban` | `credit-cards.api.rules.clone` | clone qua `cloneRuleTo` |

---

## 5. Bảo mật

### 5.1 Authorize parent-scoped create

Lỗi nghiêm trọng đã gặp trong lần chạy test đầu tiên:

`AuthorizesRequests::authorize($ability, $arguments = [])` chỉ khai **2 tham số vị trí**. Gọi
`$this->authorize('create', PolicyTier::class, $version)` — tham số thứ 3 bị **PHP âm thầm bỏ**,
nên policy `create` nhận đúng `create($user)` thiếu model ⇒ "Too few arguments" (nếu không bắt sẽ là
lỗi runtime).

Cách sửa: dùng mảng làm đối số thứ hai:

```php
$this->authorize('create', [PolicyTier::class, $version]);
```

Laravel's Gate resolve policy từ phần tử đầu (class string), sau đó **bỏ class string** và chuyển
các phần tử còn lại cho phương thức policy ⇒ `create($user, $version)` đúng 2 tham số. Tất cả 7 call
site parent-scoped (`PolicyController` ×3, `TierController` ×2, `CategoryRuleController` ×2) đã chuyển
sang dạng mảng.

### 5.2 Ma trận quyền mới (1C)

| Tài nguyên | index | create | update | delete | clone |
|---|---|---|---|---|---|
| `PolicyVersion` (thẻ) | Chủ thẻ | Chủ thẻ, thẻ mở | Chủ thẻ, chỉ đổi tên | — | — |
| `PolicyTemplate` | system + của mình | — | — | — | — |
| `PolicyTier` | Chủ (chuỗi) | Chủ + version còn sửa được | Chủ + version còn sửa được | Chủ + version còn sửa được | Chủ (copy dữ liệu) |
| `PolicyTierCategory` | Chủ | Chủ + version còn sửa được | Chủ + version còn sửa được | Chủ + version còn sửa được | Chủ (copy dữ liệu) |

Các điểm đáng chú ý:

- **Bậc/rule trong version locked/superseded**: `update`/`delete`/`create` chặn 403 (bất biến lịch
  sử — giao dịch kỳ cũ đã snapshot). Có test riêng.
- **`clone` KHÔNG yêu cầu version gốc còn sửa được**: clone là phép **copy dữ liệu** (không sửa bản
  gốc); version đích được kiểm `create` riêng trong controller. Nhờ vậy copy một bậc/rule từ version
  đã superseded sang version mới (luồng "sửa cấu hình" chuẩn) là hợp lệ. Lần chạy đầu chặn luôn cả
  nguồn superseded ⇒ test "clone tier sang version N+1" fail 403 vì tạo version mới đã supersede nguồn.
- **Template nghiêm cấm dùng template user khác**: 422 với message "Bạn chỉ được dùng template của
  chính mình." ở service, không phụ thuộc UI.

### 5.3 Chuỗi ownership của route phẳng

`/bac/{tier}` và `/quy-tac/{rule}` không mang `user_card_id`, nên ownership được resolve bằng trait
`ResolvesOwningCard`:

```
tier/rule → policyVersion → userCard → user_id
```

Nếu bậc/rule mồ côi (version mất hoặc blueprint) ⇒ owner `null` ⇒ 403/404, không crash.

### 5.4 Bảo vệ khác

- Mọi route API trong middleware `auth` + `throttle`; route trang cũng `auth`.
- `StorePolicyRequest` không có field `user_id`; user lấy từ auth.
- `tiers.*`/`rules.*` FormRequest KHÔNG có field cashback result; input `cashback_amount` /
  `calculated_cashback` / `final_cashback` bị bỏ qua (test xác nhận không ghi vào DB).
- `template_id` dùng `Rule::exists(PolicyTemplate::class, 'id')` (đúng connection `creditcard`);
  scope/ownership do service chốt.
- Route card-scoped: version thẻ khác ⇒ 404 (bài học 1B: không lộ tồn tại).

---

## 6. Rate limit

Một limiter native duy nhất cho cả module:

```php
RateLimiter::for('credit-card-api',
    Limit::perMinute((int) env('CREDIT_CARD_API_RATE_LIMIT', 60))
        ->by($request->user()?->getAuthIdentifier() ?? $request->ip())
        ->response(fn ($request, $headers) => response()->json(
            ['message' => 'Vượt giới hạn tốc độ.'], 429, $headers
        )));
```

- Đăng ký trong `AppServiceProvider::boot()`, áp bằng middleware `throttle:credit-card-api` chỉ trên
  group `credit-cards.api.*` (route trang không bị giới hạn).
- Đếm theo **user id** (fallback IP cho guest) ⇒ người dùng khác không bị chặn vì cùng IP (có test).
- Trả **JSON 429** thay vì trang HTML.

---

## 7. UI cấu hình

Trang `GET /thetindung/chinh-sach` (`credit-cards.policies`) — `resources/views/credit-card/policies.blade.php`:

- Component Alpine `policyConfig` nhận danh sách thẻ của user qua `@js(...)`; chọn thẻ ⇒ nạp versions
  (current trước), chọn version ⇒ nạp tiers + rules.
- Tạo policy v1: **scratch** (tên + ngày hiệu lực), **clone system**, **clone user** (combo template).
- Tạo version N+1 với override min spend; lưu cấu hình hiện tại thành template riêng.
- CRUD bậc + thêm/sửa/xoá rule; "Nhân bản bậc" (deep copy) và "Nhân bản rule" sang version/bậc khác.
- Không có ô nhập số tiền cashback — đúng nguyên tắc "cashback do hệ thống tính".
- Sidebar (`partials/sidebar.blade.php`) thêm mục "Chính sách" (🎯) active khi ở trang này.

Test `CreditCardModuleTest` đã cập nhật: route map gồm `credit-cards.policies` + 18 API route 1C,
sidebar active-state cho `/thetindung/chinh-sach`.

---

## 8. Bất biến nghiệp vụ được giữ (có test 1C)

1. **Version append-only:** tạo version N+1 supersede cũ, gắn `current_policy_id` sang version mới.
2. **Tên version đổi được khi chưa khoá**; version locked ⇒ 409 (controller), service cũng chặn.
3. **Thẻ đã đóng không nhận policy mới** (create policy ⇒ 403).
4. **Bậc không chồng lấn** (409); **bậc còn rule không xoá được** (409), bậc trống xoá được.
5. **% cashback ≤ 100** (422).
6. **Không dùng danh mục của người khác** (422).
7. **Template: chỉ system hoặc của chính mình**; clone user template người khác ⇒ 422.
8. **Khoá/superseded chặn mọi thay đổi tier/rule** (403).
9. **Rule item trong version locked không sửa qua API** — như trong Phase 1B.

---

## 9. Backup dữ liệu

Chạy trước khi mở API (đã hoàn tất đầu giai đoạn):

```
php artisan credit-card:backup → storage/app/backups/credit-card/
MANIFEST_2026-09-30_071857.txt
```

| Bảng | Số bản ghi đã chụp | Verify |
|---|---|---|
| credit_card_banks | 43 | UTF-8, số dòng khớp |
| credit_card_categories | 19 | UTF-8, numberic |
| credit_card_products | 0 | |
| credit_card_policy_templates | 1 | |
| credit_card_policies | 1 | |
| credit_card_policy_tiers | 2 | |
| credit_card_policy_tier_categories | 15 | |
| credit_card_user_cards | 0 | |
| credit_card_statement_periods | 0 | |
| credit_card_transactions | 0 | |

CSV đã kiểm: header/row-count trên từng file và không còn ký tự hỏng (U+FFFD). Nếu rollback cần
restore, dùng chính các CSV này.

---

## 10. Kiểm thử

### 10.1 File mới

`tests/Feature/CreditCard/Phase1cHttpTest.php` — 21 test / 91 assertions:

Auth, scratch policy + gắn thẻ, clone system (deep copy blueprint), clone user (template người khác 422),
thẻ đóng 403, tạo version N+1 supersede + gắn `current_policy_id`, rename (owner ✓ / stranger 403 /
locked 409), lưu thành user template, tạo bậc + chồng lấn 409, xoá bậc (có/vô rule), clone bậc deep
copy 2 rule, tạo rule bỏ qua `cashback_amount`, danh mục người khác 422, % > 100 → 422, stranger bị
chặn 403 mọi endpoint của thẻ người khác, version thẻ khác qua URL card-scoped 404, template người
khác 403, template index chỉ system + own, locked/superseded chặn tier/rule 403, rate limit per-user
JSON 429.

### 10.2 Kết quả

| Phạm vi | Kết quả |
|---|---|
| Credit Card tập trung (`tests/Feature/CreditCard`) | **200 passed / 1228 assertions** |
| `CreditCardModuleTest` | **20 passed / 243 assertions** |
| Toàn bộ suite | **1381 passed / 3 failed / 5623 assertions** |

3 lỗi fail là **baseline có sẵn**, không liên quan module Thẻ tín dụng (giống hệt cuối 1B):

1. `Tests\Feature\Auth\RegistrationTest > new users can register`
2. `Tests\Feature\Services\ShopeeFood\ShopeeFoodOrderSyncServiceTest > cookie guard fails fast without http`
3. `Tests\Feature\TikTokSyncPhase3Test > preexisting credited order is not credited again by admin`

Số test tăng từ 1360 (cuối 1B) lên 1381: **+21 test, +115 assertion**, không regression.

---

## 11. Bug phát hiện trong quá trình làm

| # | Bug | Mức độ | Sửa |
|---|---|---|---|
| 1 | `authorize('create', PolicyTier::class, $version)` — tham số thứ 3 bị PHP bỏ ⇒ policy nhận thiếu model, lỗi runtime | Cao | Chuyển sang dạng mảng `[Class::class, $model]` cho cả 7 call site parent-scoped |
| 2 | `CloneTierRequest` validate `target_policy_id` với `whereNull('user_card_id')` — chỉ nhận blueprint, chặn mọi version thật | Cao | Đổi `whereNotNull('user_card_id')`: đích phải là version thật của thẻ |
| 3 | Policy `clone` yêu cầu version gốc còn sửa được ⇒ không copy được bậc/rule từ version đã superseded sang version mới (luồng sửa cấu hình chuẩn) | TB | `clone` chỉ kiểm ownership; mutability của đích do `create` trên target đảm bảo |
| 4 | Test helper `makePolicyForCard` dùng key `percent`; 3 test 1C gửi `cashback_percent` ⇒ `Undefined array key` | Thấp | Đồng bộ về `percent` theo hợp đồng helper |

---

## 12. Quyết định thiết kế chốt

1. Gộp 3 đường tạo policy v1 vào MỘT endpoint (`POST /chinh-sach`, phân biệt bằng `mode`) để
   validation/userCard/effective_from không lặp 3 lần.
2. Version scope theo thẻ ở mọi route (`versionOfCard` lọc `user_card_id`); tier/rule route phẳng
   nhưng ownership resolve qua chuỗi quan hệ.
3. Blueprint (`user_card_id IS NULL`) không truy cập được qua route version/tier/rule — chỉ đọc qua
   template.
4. Endpoint policy duy nhất sửa được là `update` = đổi tên; business rule chỉ qua version mới.
5. Clone cho phép nguồn là dữ liệu lịch sử (locked/superseded); đích luôn phải còn sửa được.
6. Rate limit native Laravel (không package thứ ba), per-user, JSON 429, riêng cho API.
7. UI không nhận `user_id`, không có ô nhập cashback; validate nằm ở FormRequest, payload whitelist.

---

## 13. Lệnh vận hành

```bash
# Backup dữ liệu trước khi đụng schema/API
php artisan credit-card:backup

# Route
php artisan route:list --path=thetindung

# Test
php artisan test tests\Feature\CreditCard tests\Feature\CreditCardModuleTest.php
php artisan test --filter=Phase1cHttpTest
php artisan test

# Format
.\vendor\bin\pint app\Services\CreditCard app\Http\Controllers\CreditCard \
    app\Http\Requests\CreditCard app\Policies\CreditCard tests\Feature\CreditCard
```

---

## 14. Rollback

1. **Ứng dụng:** xoá 4 controller + 9 request + 2 policy + trait, khối route `credit-cards.api.*`
   mới, view `policies.blade.php` + mục sidebar, test `Phase1cHttpTest`; hoàn trả `CreditCardController::policies` + `CreditCardModuleTest` + `AppServiceProvider` (bỏ limiter).
2. **Dữ liệu:** không migration mới ở 1C ⇒ không cần hạ schema. Dùng CSV backup `2026-09-30_071857`
   nếu cần khôi phục dữ liệu đã bị sửa qua API.
3. Không thể `git revert` một lệnh vì chưa commit; theo thứ tự dữ liệu → code → test.

---

## 15. Kết luận và bước tiếp theo

Phase 1C hoàn tất: toàn bộ policy engine đã có HTTP API (version/policy, template, tier, rule + clone
deep), rate limit native trên mọi API, UI cấu hình chính sách đi vào dùng được, backup đã chụp trước
khi mở cửa. Trong quá trình làm phát hiện và sửa 1 lỗi nền tảng (parent-scoped authorize mất đối số)
và 1 lỗi validation ngược chiều.

Toàn suite: 1381 passed, 3 baseline cũ giữ nguyên, không regression.

Các hướng tiếp theo (ngoài phạm vi 1C):

1. Quyết định `credit_card_products` (drop hay giữ).
2. Audit log cho toàn bộ API credit card.
3. Báo cáo / dashboard nâng cao.
4. Job nền cho import + tính lại số lượng lớn.
5. Dọn 3 test baseline để CI xanh tuyệt đối.