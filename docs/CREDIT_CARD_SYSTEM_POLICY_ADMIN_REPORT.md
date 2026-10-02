# CREDIT CARD SYSTEM POLICY ADMIN REPORT (§23)

> Giai đoạn "Chính sách hoàn tiền hệ thống" — khu vực quản trị System Policy/Template (admin
> quản lý template hệ thống + version blueprint append-only), người dùng chỉ xem/clone bằng thẻ mình.
> Ngày hoàn tất: 2026-09-30. Nhánh: `main`. **Chưa commit/push** (đúng quy ước các phase trước).

> **Cập nhật vị trí file (sau giai đoạn này):** Policy Editor không còn nằm ở
> `admin/credit-card-policies/partials/editor.blade.php`. Partial đó đã bị xoá và chuyển thành
> `resources/views/credit-card/partials/policy-editor.blade.php` — bản CANONICAL dùng chung cho
> cả trang quản trị lẫn form Thêm/Sửa thẻ (`manage.blade.php` render ở chế độ `hosted`: không
> có `x-data`, không có nút lưu, state nằm ở `policyEditor.*`). State + payload nằm ở
> `window.policyEditorState()` / `versionConfig()`; trang quản trị bọc thêm bằng
> `window.systemPolicyEditor()` (nút lưu + endpoint API). Các dòng trong tài liệu này ghi đường
> dẫn cũ vẫn còn hiệu lực về mặt LỊCH SỬ, không phải vị trí file hiện nay.

---

## 1. Tổng quan

Phase 1C đã có HTTP API cho toàn bộ policy engine (policy/tier/rule/template) và UI cấu hình
`credit-cards.policies`, nhưng "Chính sách hoàn tiền hệ thống" chỉ có thể quản trị bằng tay trong
DB. Giai đoạn này mở **khu vực quản trị** cho các template `scope = system`:

- Admin tạo template hệ thống hoàn chỉnh (bậc + quy tắc theo danh mục hệ thống).
- Mọi thay đổi cấu hình đi qua **version blueprint N+1** (append-only, giữ nguyên §21/§23 của
  1A): version cũ chỉ đóng `effective_to` + `status = superseded`, không bao giờ sửa business rule.
- Admin sửa metadata template (tên / mô tả / xuất bản) độc lập với version.
- User chỉ **đọc trước** (modal chi tiết) và **clone về thẻ của mình**; song song đổi nhãn UI
  trong module từ "Chính sách" thành "Chính sách hoàn tiền".

Không có migration mới — tái sử dụng đúng mô hình đã có: System Policy = `PolicyTemplate`
`scope=system` + `Policy` blueprint (`user_card_id IS NULL`).

Kết quả chính:

| Hạng mục | Kết quả |
|---|---|
| Permission mới | `credit-cards.view` + `credit-cards.manage` (seeder); Admin role nhận toàn bộ |
| Rate limit riêng | `throttle:credit-card-admin-api` (per-user, JSON 429) — KHÔNG dùng chung loại admin/user API |
| Controller mới | `SystemPolicyApiController` (6 endpoint JSON) + `SystemPolicyAdminController` (4 trang) |
| Form Request mới | `CreateSystemPolicyRequest`, `StoreSystemPolicyVersionRequest`, `UpdateSystemPolicyRequest` |
| Route mới | 10 (4 trang + 6 API) trong group `admin` |
| Presenter | `SystemPolicyPresenter` — MỘT nơi định dạng template/blueprint dùng chung trang + API + test |
| View mới | `admin/credit-card-policies/{index,create,edit,show}` + `partials/editor` (Alpine `systemPolicyEditor`) |
| UI user | Section "🏦 Chính sách hoàn tiền hệ thống" + modal đọc trước + "Dùng cho thẻ của tôi" |
| Test mới | `SystemPolicyManagementTest`: **27 test / 147+ assertions** |
| Kết quả toàn suite | **1432 passed / 3 failed (3 baseline) / 5872 assertions** |

---

## 2. Mục tiêu và checklist

### 2.1 Checklist giai đoạn

| # | Mục | Trạng thái |
|---|---|---|
| 1 | Không migration — tái sử dụng template system + blueprint | ✅ (`PolicyTemplate.scope=system`, `Policy.user_card_id IS NULL`) |
| 2 | Permission admin riêng cho module credit card | ✅ `credit-cards.view` / `credit-cards.manage` |
| 3 | Admin: danh sách / xem chi tiết / tạo template hoàn chỉnh (bậc + rule) | ✅ `SystemPolicyApiController` |
| 4 | Admin: sửa cấu hình = tạo version N+1, append-only | ✅ `policies.createSystemVersion` |
| 5 | Admin: cập nhật metadata template không sinh version mới | ✅ `policies.updateSystemMeta` |
| 6 | Chặn danh mục ngoài phạm vi ở tầng SERVICE (không chỉ FormRequest) | ✅ `PolicyService::assertSystemOnlyCategories` |
| 7 | Chồng lớp bảo mật: permission middleware + `PolicyTemplatePolicy` (Admin role) | ✅ |
| 8 | User không thể ghi vào blueprint qua mọi endpoint | ✅ 404/403 (xem §5) |
| 9 | User clone template hệ thống; admin tạo version mới KHÔNG viết lại thẻ đã clone | ✅ |
| 10 | Rate limit admin riêng, đo theo USER | ✅ JSON 429 |
| 11 | UI user đổi tên "Chính sách hoàn tiền" + xem trước hệ thống | ✅ |
| 12 | Toàn suite xanh trừ 3 baseline | ✅ 1432 passed |

### 2.2 Ngoài phạm vi (cố ý không làm)

- Không tạo CRUD granular tier/rule cho blueprint: mọi thay đổi cấu hình đi qua version N+1
  (admin không "sửa trực tiếp" một bậc/quy tắc hệ thống).
- Không đổi `tier_application_mode`, không đụng module Danh mục chi tiêu / Bank / Transaction.
- Không có audit log riêng cho admin API (là hướng tiếp theo).
- Thẻ đã clone giữ cấu hình cố định; không có cơ chế "cập nhật theo hệ thống" (theo §10 thiết kế).

---

## 3. Kiến trúc

```
PolicyTemplate (scope=system, owner_user_id=0)
    └─ blueprints() = Policy (user_card_id=NULL): version 1..N  — append-only
          Current blueprint = có status='active' & effective_to NULL
          Login version N → supersede + deep copy tiers/rules (9.2, giữ §21/§23)
UserCard policy (user_card_id NOT NULL): bản SAO v1 từ blueprint hiện tại — CÔ LẬP
Sửa template hệ thống sau đó: tạo blueprint mới, KHÔNG chạm policy thẻ đã clone.
```

Layer:

| Tầng | Vai trò |
|---|---|
| `PolicyService` | Điểm vào nghiệp vụ: `createSystemTemplate`, `createSystemVersion`, `updateSystemMeta`, `systemVersions`, `cloneTemplate` + guard `assertSystemOnlyCategories` |
| `PolicyCloneService` | `createTemplateBlueprint`, `attachTemplateToCard`, `uniqueSlug` (public hoá) |
| `SystemPolicyPresenter` | Định dạng payload template/blueprint dùng chung trang admin + JSON API + test |
| Controllers | Mỏng: gọi service, bắt `InvalidArgumentException` → 422 JSON |
| `PolicyTemplatePolicy` | Lớp phòng thủ thứ hai: `update` system template chỉ cho user `isAdmin()` |

Append-only đảm bảo: version cũ là bản ghi lịch sử đọc được, `PolicyTierCategory` snapshot giá trị
trong bảng, clone thẻ là bản **sao** (không tham chiếu) — đúng luật bất biến D3/D4 của 1A.

---

## 4. Routes

Nhóm `admin` (middleware `auth` + các middleware admin khác). JSON API thêm
`throttle:credit-card-admin-api`.

### 4.1 Trang (Blade)

| Method | URI | Tên route | Permission |
|---|---|---|---|
| GET | `/admin/credit-card/policies` | `admin.credit-card-policies.index` | `credit-cards.view` |
| GET | `/admin/credit-card/policies/create` | `admin.credit-card-policies.create` | `credit-cards.manage` |
| GET | `/admin/credit-card/policies/{template}` | `admin.credit-card-policies.show` | `credit-cards.view` |
| GET | `/admin/credit-card/policies/{template}/edit` | `admin.credit-card-policies.edit` | `credit-cards.manage` |

### 4.2 JSON API

| Method | URI | Tên route | Permission |
|---|---|---|---|
| GET | `/admin/credit-card/api/policies` | `admin.credit-card-policies.api.index` | `credit-cards.view` |
| POST | `/admin/credit-card/api/policies` | `admin.credit-card-policies.api.store` | `credit-cards.manage` |
| GET | `/admin/credit-card/api/policies/{template}` | `admin.credit-card-policies.api.show` | `credit-cards.view` |
| GET | `/admin/credit-card/api/policies/{template}/versions` | `admin.credit-card-policies.api.versions` | `credit-cards.view` |
| POST | `/admin/credit-card/api/policies/{template}/versions` | `admin.credit-card-policies.api.versions.store` | `credit-cards.manage` |
| PATCH | `/admin/credit-card/api/policies/{template}` | `admin.credit-card-policies.api.update` | `credit-cards.manage` |

Đã verify bằng `php artisan route:list --path=credit-card` (10 route mới đăng ký đủ).

---

## 5. Bảo mật

1. **Route layer**: tất cả nằm trong group `admin` (auth); mỗi route gắn
   `permission:credit-cards.view|manage` ⇒ user thường 403 NGAY từ middleware không chạm controller.
2. **Policy layer**: `PolicyTemplatePolicy::update` trên template **system** yêu cầu
   `$user->isAdmin()` (role Admin); user có `credit-cards.manage` nhưng không phải Admin bị chặn
   ghi (`storeVersion`/`update` gọi `authorize('update', $template)`). `store` chấp nhận phụ thuộc
   middleware route (chống chồng thứ hai đã cover mọi đường ghi).
3. **Service layer — danh mục**: `PolicyService::assertSystemOnlyCategories` chặn bất kỳ danh mục
   nào không phải system đang active khi tạo template/version hệ thống (bất biến §10: blueprint chỉ
   dùng danh mục hệ thống); lỗi → `InvalidArgumentException` → **422 JSON** (không rơi thành 500).
4. **Cô lập user ⇄ system**:
   - User write endpoints (tier/rule) nhắm vào blueprint → **404** (tiers.store qua
     `ResolvesCardResources:forCard()`) hoặc **403** (rules.store/destroy qua authorize ownership).
   - `PolicyTemplateController` user không có store/update/destroy; index chỉ filter system + own;
     `present()` bổ sung trường mới không lộ dữ liệu bất kỳ ai.
   - Thẻ clone giữ bản SAO: admin tạo version N+1 sau đó không viết lại policy thẻ đã clone (test phủ).
5. **Tách limiter**: `credit-card-admin-api` đếm riêng theo user, admin 429 không tiêu quota của
   user API và ngược lại.

---

## 6. Rate limit

| Limiter | Áp dụng | Mặc định | Trả về |
|---|---|---|---|
| `credit-card-admin-api` | 6 endpoint JSON admin | 120/min/per-user | JSON `{message:"Vượt giới hạn..."}` 429 |
| `credit-card-api` | API user (đã có từ 1C) | giữ nguyên | JSON 429 |

Test phủ: gọi quá quota → 429 JSON, **admin khác vẫn còn quota** (đo theo user, không theo IP).

---

## 7. UI

### 7.1 Admin

- `index` — danh sách template hệ thống + tóm tắt version/bậc/danh mục, breadcrumb.
- `create` / `edit` — dùng chung partial `editor.blade.php` (Alpine `systemPolicyEditor`): tên, mô tả,
  ngày hiệu lực, trạng thái xuất bản, ngưỡng/spend, làm tròn, bậc + danh mục hệ thống (chỉ chọn
  category `scope=system` active). Edit là màn hình "tạo version mới" có cảnh báo amber — không sửa
  blueprint hiện hành.
- `show` — dòng thời gian "Lịch sử phiên bản chính sách" read-only, đánh dấu version hiện hành.

### 7.2 User (`credit-cards.policies`)

- Section "🏦 Chính sách hoàn tiền hệ thống" sau bộ chọn thẻ: chỉ hiện template `is_active`,
  kèm bậc/danh mục.
- [Xem chi tiết] → modal đọc trước qua `/thetindung/api/mau-chinh-sach/{template}`.
- [Dùng cho thẻ của tôi] → nếu thẻ đã có policy thì cảnh báo; ngược lại `createMode=clone_system`,
  gắn `draft.template_id`, scroll tới `#cc-create-area`.
- Đổi nhãn sidebar + tiêu đề từ "Chính sách" → "Chính sách hoàn tiền" (khớp tên chức năng).

---

## 8. Bất biến được giữ (có test)

1. **Append-only**: thêm version N+1 không sửa/xoá version cũ (blueprint cũ giữ nguyên tiers/rules).
2. **Deep copy**: version mới không khai báo override sẽ sao chép nguyên cấu hình version hiện tại.
3. **Metadata ≠ config**: `updateSystemMeta` không tạo blueprint mới.
4. **Cô lập thẻ**: test clone → admin tạo version mới → rule thẻ vẫn 3.0% (không bị viết lại).
5. **Scope danh mục**: blueprint từ chối danh mục user/stranger; policy thẻ chấp nhận system + 
   danh mục của chính user, từ chối danh mục người khác.
6. **Blueprint bất khả xâm phạm qua user endpoints** (404/403) + template index người dùng không lộ
   template hệ thống inactive.
7. **Tạo v1 với cấu hình rỗng** sinh bậc mặc định (không để blueprint không thể hoạt động).

---

## 9. Kiểm thử

### 9.1 File mới

`tests/Feature/CreditCard/SystemPolicyManagementTest.php` — 27 test / 147+ assertions:

guest redirected/401, member 403, viewer xem-được/không-quản-trị, manage-không-Admin bị policy chặn,
tạo template xuất bản (với bậc/rule), draft ⇒ inactive, validation payload, category ngoài phạm vi 422,
tạo v1 mặc định, version N+1 giữ nguyên bản cũ, deep-copy không override, storeVersion + metadata,
meta-only không tạo blueprint, admin pages render, clone user dùng version LATEST, version mới không
rewrite thẻ clone, blueprint chặn qua user endpoints, template index loại inactive hệ thống + counts,
show chi tiết với bậc, trang user nhãn "hoàn tiền" + section hệ thống, service scope category
(bản mẫu / thẻ), rate limit admin API JSON 429, **edit page không còn 'Cách làm tròn'**, **payload
editor bảo toàn rules khi tạo version** (regression MB JCB).

### 9.2 Kết quả

| Phạm vi | Kết quả |
|---|---|
| `tests/Feature/CreditCard` | **297 passed / 1788 assertions** |
| `SystemPolicyManagementTest` | **27 passed / 150 assertions** |
| Toàn bộ suite | **1432 passed / 3 failed (3 baseline) / 5872 assertions** |

3 lỗi fail là **baseline có sẵn** (giống cuối 1C): `Auth\RegistrationTest`,
`ShopeeFoodOrderSyncServiceTest`, `TikTokSyncPhase3Test`. So với baseline 1399 passed (cuối giai đoạn
system-policy trước): **+33 test, +196 assertion, không regression**.

---

## 10. Bug phát hiện trong quá trình làm

| # | Bug | Mức độ | Sửa |
|---|---|---|---|
| 1 | `CategoryRuleService::update()`: closure transaction `use ($rule, $attributes)` thiếu (`$policy`) nhưng `assertCategoryUsable($rule->category_id, $policy)` bên trong thân ⇒ `Undefined variable $policy` khi sửa rule sau clone | Cao | Bổ sung `$policy` vào `use(...)`; suite CreditCard xanh lại (regression đã được bắt) |
| 2 | `SystemPolicyAdminController::show` truyền versions qua presenter nhưng view đọc `$version['active']` trong khi presenter xuất `status` ⇒ 500 trên trang show | TB | Presenter xuất thêm `active` (`status === active`) — một shape cho cả trang + API |
| 3 | (Test) `createSystemTemplate` nhận string ngày hiệu lực trong fixture ⇒ TypeError; `assertJsonPath` so khít int vs float 3.0 | Thấp | Dùng `CarbonImmutable::parse(...)`; so `assertEquals` thay vì strict trên `cashback_percent` |
| 4 | Blueprint qua user endpoints trả 404 vs 403 khác nhau theo controller (`forCard()` vs `authorize` ownership) — hành vi ĐÚNG chủ ý của codebase | — | Đồng bộ assert theo hành vi thực tế (tiers 404, rules 403/404→403) để test phản ánh tài liệu |
| 5 | **MB Ultimate JCB** (template 3: v2 active policy 4 / tier 5 có **0 rules**) — trang edit KHÔNG hydrate rule cũ: presenter xuất bậc kèm `tiers[].rules` nhưng editor + `show.blade` đọc `tier.categories` ⇒ payload gửi `categories: []` ⇒ `PolicyCloneService::replaceChildren()` xoá sạch rules của version mới | Cao | Editor hydrate chuẩn hoá `rules` → `categories` (strip `spend_*`) ngay ở `init`; `show.blade` đọc `$tier['rules']` + tên `category_name ?? name`; giải thích tại `editor.blade.php` (comment) |
| 6 | (Test/UI) Cơ chế "Cách làm tròn" (`rounding_mode`) ngừng dùng trong nghiệp vụ ⇒ cashback phải luôn **floor xuống ĐỒNG**; ngưỡng chi tiêu chỉ ở BẬC, Category Rule không có ngưỡng | TB | `CashbackCalculator::round()` = `floor(round($value, 6))` (mode bị bỏ qua, signature giữ cho caller); bỏ selector/display rounding khỏi toàn bộ UI (admin editor + show + user page); bỏ `rounding_mode` khỏi `editorInitial`; bỏ field ngưỡng khỏi UI rule; `rounding_mode` column và FormRequest vẫn giữ để tương thích |

---

## 11. Quyết định thiết kế chốt

1. KHÔNG migration: template hệ thống = `PolicyTemplate` + blueprint `Policy` detached; versioning
   append-only tận dụng đúng khung đã xây từ 1A.
2. Mọi đổi cấu hình = version N+1 (không CRUD tier/rule granular admin); metadata template tách riêng
   (`updateSystemMeta`).
3. Chống chồng 3 lớp: middleware permission → `PolicyTemplatePolicy::update` (Admin role) → guard
   danh mục trong `PolicyService` (service layer, không chỉ dựa FormRequest).
4. Trang admin + JSON API dùng chung MỘT presenter ⇒ payload không bao giờ lệch, test khẳng định
   đúng hình dạng thật.
5. Blueprint qua user endpoints từ chối 404/403 (không lộ tồn tại); clone là bản SAO, không tham
   chiếu — quyết định §10.
6. Limiter admin tách riêng khỏi user API `credit-card-api`.

---

## 12. Lệnh vận hành

```bash
# Routes
php artisan route:list --path=credit-card

# Test riêng
php artisan test --filter=SystemPolicyManagementTest

# Test module
php artisan test --filter CreditCard

# Toàn suite
php artisan test

# Format
.\vendor\bin\pint app\Http\Controllers\Admin\CreditCard app\Http\Requests\Admin\CreditCard \
    app\Support\CreditCard app\Services\CreditCard app\Http\Controllers\CreditCard\PolicyTemplateController.php \
    tests\Feature\CreditCard\SystemPolicyManagementTest.php
```

---

## 13. Rollback

1. **Ứng dụng:** xoá 2 controller admin + 3 request admin + `SystemPolicyPresenter` + thư mục
   `resources/views/admin/credit-card-policies`; bỏ 10 route trong group admin; hoàn trả
   `PolicyTemplateController::present`, `policies.blade.php`, `navigation.blade.php`,
   `sidebar.blade.php`, `RolePermissionSeeder` (xóa 2 permission) và phần guard trong
   `PolicyService`/`CategoryRuleService`; xoá test `SystemPolicyManagementTest`.
2. **Dữ liệu:** không có migration mới ở giai đoạn này ⇒ không cần hạ schema. Permission xoá qua
   seed lại (hoặc xoá tay bảng `permissions`/`role_has_permissions`).
3. Không thể `git revert` một lệnh vì chưa commit; theo thứ tự dữ liệu → code → test.

---

## 14. Kết luận và bước tiếp theo

Giai đoạn hệ thống hoá khu vực quản trị "Chính sách hoàn tiền hệ thống": admin có CRUD template +
version append-only bằng UI + JSON API, người dùng chỉ xem/clone và không thể chạm blueprint; mọi
thay đổi cấu hình giữ bất biến §21/§23; toàn suite 1424 passed, chỉ còn 3 baseline cũ, không
regression. Tình cờ bắt được 1 bug nền của `CategoryRuleService::update` (closure thiếu capture).

Hướng tiếp theo (ngoài phạm vi):

1. Audit log cho toàn bộ admin API credit card.
2. Cơ chế "theo dõi cập nhật hệ thống" cho thẻ đã clone (hiện là bản SAO cố định).
3. Xoá template hệ thống (hiện `is_builtin` không xoá; dashboard quản lý trạng thái xuất bản).
4. Dọn 3 test baseline để CI xanh tuyệt đối.

---

## 15. Addendum — Sửa MB Ultimate JCB + huỷ Rounding/ngưỡng Category Rule (cùng ngày)

Bổ sung vào cuối giai đoạn: **debug + fix** lỗi "MB Ultimate JCB" và **chốt business rule** làm
tròn/ngưỡng chi tiêu. Toàn bộ trong phạm vi module Thẻ tín dụng, không migration, không commit/push.

### 15.1 Bug MB Ultimate JCB (root cause)

- **Triệu chứng**: template `MB Ultimate JCB` (id 3): v1 (policy 3) có 3 rules; v2 (policy 4, active)
  có **0 rules**. Trang Edit cũng không hiển thị rule cũ.
- **Nguyên nhân**: `SystemPolicyPresenter` xuất bậc kèm key `tiers[].rules`, nhưng editor
  (`editor.blade.php` Alpine) và `show.blade.php` đọc `tier.categories`. Khi mở Edit, rules KHÔNG
  được hydrate vào state ⇒ khi lưu version mới, payload gửi `tiers[].categories: []` ⇒
  `PolicyCloneService::replaceChildren()` (chạy khi `isset($overrides['tiers'])`) **xoá toàn bộ
  rules** của blueprint mới — v1 chỉ bị supersede nên giữ nguyên, v2 mất rule.
- **Fix**:
  1. `editor.blade.php`: hydration chuẩn hoá `tier.rules` → `tier.categories` (bỏ `spend_from`,
     `spend_to`, `min`) ngay trong hàm `init`; giữ nguyên mọi field hợp lệ.
  2. `show.blade.php`: vòng lặp đọc `$tier['rules']` thay vì `$tier['categories']`; tên hiển thị
     `category_name ?? name`.
  3. Regression test mới: lấy payload presenter (`tiers[].rules`) → map sang `categories` như editor
     → POST version store → v2 GIỮ ĐỦ rules + % đã sửa, v1 bất biến.

### 15.2 Chốt nghiệp vụ làm tròn + ngưỡng chi tiêu

- Cashback mỗi giao dịch **luôn floor xuống ĐỒNG**: `CashbackCalculator::round()` =
  `floor(round($value, 6))` để loại nhiễu float (vd `55.555 @ 1%` = 555,55 không mất 1đ). Không còn
  `round`/`ceil`; tham số `$roundingMode` và cột `rounding_mode` **GIỮ** cho tương thích caller/test
  nhưng KHÔNG còn được dùng (bỏ ở UI + `editorInitial` + meta JS).
- Ngưỡng chi tiêu chỉ tồn tại ở **Bậc** (`min_total_spend` / `max_total_spend`). Category Rule
  **không còn ngưỡng**; UI bỏ `spend_from`, `spend_to`, `min_transaction_amount`. Backend giữ
  `spend_from=0`/`spend_to=null` mặc định (engine `pickRule` + FormRequest không đổi → test cũ vẫn
  xanh, payload mới an toàn).
- Nhãn/help mới (admin + user thống nhất): "Hoàn tiền (%)", "Hoàn tối đa / giao dịch",
  "Hoàn tối đa / danh mục", "Tổng chi tiêu tối thiểu", "Tổng chi tiêu tối đa",
  "Mức chi tiêu tối thiểu để được hoàn tiền / kỳ", "Hoàn tiền tối đa / kỳ".

### 15.3 Giao diện responsive (mobile-first)

- Admin editor: mỗi rule là **card xếp dọc** (`grid gap-3 sm:grid-cols-2 xl:grid-cols-4`) thay cho
  `<table>`; header "Quy tắc N" + nút Xoá; help-text cho 2 trường "tối đa". Layer bậc kèm field
  "Tổng chi tiêu tối đa" có help-chú thích, không chiếm space khi rỗng.
- User `policies.blade.php`: bảng rules bỏ cột "Ngưỡng chi tiêu" (colspan 6→5); category select chia
  **optgroup** "🏦 Danh mục hệ thống" / "👤 Danh mục của tôi"; rule form grid `sm:grid-cols-2
  lg:grid-cols-3`; modal chi tiết label chuẩn.
- Đảm bảo 390/375/360px không horizontal overflow, touch target 40-44px.

### 15.4 DB trước (checkpoint thực)

`credit-card` (MySQL `hoantien_creditcard`):

| Template | Policy | v | status | eff_from → eff_to | Tier | Rules |
|---|---|---|---|---|---|---|
| 3 MB Ultimate JCB | 3 | 1 | superseded | 2026-09-30 → 2026-09-29 | 4 | 3 (cats 8,9,10) |
| 3 MB Ultimate JCB | 4 | 2 | active | 2026-09-30 → null | 5 | **0** |

Không có data migration ở fix này → DB sau = DB trước. **Điểm cần hành động**: policy 4 (v2,
0 rules) chưa được hồi — admin vào trang Edit của template rồi "Lưu phiên bản mới" (editor mới sẽ
hydrate đủ 3 rules) để tạo v3 khôi phục, hoặc chờ duyệt. Với DB thật, dùng:
`/admin/credit-card/policies/{3}/edit` → kiểm tra "Quy tắc 1..3" hiển thị đủ → lưu version ⇒ v3
có enough rules.

### 15.5 Files đổi

| File | Thay đổi |
|---|---|
| `app/Services/CreditCard/CashbackCalculator.php` | `round()` = `floor(round($value, 6))`; bỏ mode ở 2 call site; docblock `$roundingMode` ghi rõ giữ-cho-tương-thích |
| `app/Http/Controllers/Admin/CreditCard/SystemPolicyAdminController.php` | bỏ `rounding_mode` khỏi `editorInitial` |
| `resources/views/admin/credit-card-policies/partials/editor.blade.php` | hydration `rules→categories`; bỏ selector rounding + cột ngưỡng; rule stacked cards; labels/help |
| `resources/views/admin/credit-card-policies/show.blade.php` | `tier.rules` + `category_name`; bỏ card "Cách làm tròn"; relabel min/cap |
| `resources/views/credit-card/policies.blade.php` | bỏ rounding (detail + modal), bỏ cột/field ngưỡng, optgroup categories, labels/help chuẩn |
| `tests/Unit/CreditCard/CashbackCalculatorTest.php` | block rounding → floor-to-đồng: `floorToDongCases` (1234567@10%→123456.00, 999999@5%→49999.00, 100000@10%→10000.00), mode bị bỏ qua (411.00), không vượt giá trị thật (833.00), không mất 1đ vì float (555.00) |
| `tests/Feature/CreditCard/SystemPolicyManagementTest.php` | +2 regression: edit page không hiển thị rounding/`rounding_mode`; version-save từ payload editor bảo toàn rules (MB JCB) |

### 15.6 Kết quả sau fix

| Phạm vi | Kết quả |
|---|---|
| `php artisan test --filter CreditCard` | **297 passed / 1788 assertions** |
| `php artisan test` (toàn suite) | **1432 passed / 3 failed (3 baseline) / 5872 assertions** |
| `vendor/bin/pint` (4 file PHP đổi) | passed, không sửa thêm |

3 baseline giữ nguyên: `Auth\RegistrationTest`, `ShopeeFoodOrderSyncServiceTest`,
`TikTokSyncPhase3Test` (không đụng tới).

---

## 16. Addendum — DEFAULT VERSION + sửa CHÍNH version N + xoá version (cùng ngày)

Bổ sung giai đoạn 2 cùng ngày: **định nghĩa lại "đâu là nguồn clone hợp lệ"** (default version),
màn hình **chỉnh sửa thông tin** template (metadata inline), **sửa CHÍNH version N** (version mới
sinh ra từ config của N, vẫn append-only), **đặt version mặc định** và **xoá version** có guard.
Kèm migration nhỏ + 2 route mới. Không commit/push. Không đụng danh mục/Bank/Affiliate/Fast/Normal;
MB JCB v2 (0 rules) **giữ nguyên không sửa**, chỉ ghi nhận tại §15.4.

### 16.1 Khái niệm chốt: DEFAULT VERSION

- Một template giữ `default_version_id` chỉ vào blueprint *chuỗi* (không phải thẻ user).
- **Clone user mới / `cloneTemplate` / `attachTemplateToCard` / user present dùng
  `defaultBlueprint()`** (= default version nếu hợp lệ, ngược lại `currentBlueprint()`).
- Tạo template (`createSystemTemplate`) tự set default = chính blueprint v1. Tạo version mới
  **KHÔNG** tự đổi default. Chỉ admin chủ động "Đặt mặc định".

| Chuyển hành vi | Trước | Sau |
|---|---|---|
| Clone user khi v1 default + v2 mới | v2 (latest) | **v1 (default)** |
| `user_clone_uses_latest_blueprint_version` | kỳ vọng latest | **kỳ vọng default**, rồi set default → v2 |

### 16.2 Migration (LẦN ĐẦU có migration trong giai đoạn này)

`database/migrations/creditcard/2026_10_01_000013_add_default_version_to_credit_card_policy_templates_table.php`

- Thêm nullable `default_version_id` FK → `credit_card_policies` **nullOnDelete** + index
  `credit_card_templates_default_version_index`.
- Down: dropForeign / dropIndex / dropColumn.
- Đã chạy trên DB thật: `php artisan credit-card:migrate --force` (DONE).

Ghi chú: DB hiện tại cột này là NULL cho các template cũ; `defaultBlueprint()` được thiết kế fallback
về `currentBlueprint()` để template cũ không bị lệch hành vi.

### 16.3 Các guard mới (nghiệp vụ, có test)

| Thao tác | Quy tắc |
|---|---|
| `setDefaultVersion` | version thuộc template + `user_card_id` null; sai → `InvalidArgumentException` "Phiên bản không thuộc blueprint..." (422/`error` flash) |
| `deleteSystemVersion` | **Chỉ một ràng buộc: version đang là default → chặn** ("Không thể xóa phiên bản MẶC ĐỊNH. Hãy đặt phiên bản khác làm mặc định trước."). Mọi trường hợp khác đều xóa được: version đã có thẻ/giao dịch/kỳ sao kê trỏ tới, version gốc của chuỗi, version duy nhất. Lý do: thẻ nhận **deep clone** nên policy thẻ là bản SAO độc lập, không FK trực tiếp về blueprint (FK `statement_periods.policy_id` / `transactions.policy_version_id` đều `nullOnDelete`). Khi xóa version gốc, `root_policy_id` của các version còn lại được remap sang version thấp nhất còn lại (version mới tự trỏ về chính nó) ⇒ không còn `root_policy_id` nào trỏ tới bản ghi đã xóa |
| Presenter | blueprint xuất `is_default` / `referenced` / `referenced_label` / `can_delete` / `delete_block_reason`. `can_delete = !is_default`; `delete_block_reason` chỉ có khi version là mặc định: "Không thể xóa phiên bản đang mặc định. Hãy đặt phiên bản khác làm mặc định trước." `referenced`/`referenced_label` chỉ còn là thông tin hiển thị, KHÔNG chặn xóa |

### 16.4 Sửa CHÍNH version N (`source_version_id`)

- `edit` page nhận `?version={id}` → editor khởi tạo từ config của **đúng version N** (hiện 1 note
  "Đang chỉnh sửa từ Version N").
- Lưu: POST versions kèm `source_version_id` → `createTemplateBlueprint($template, $date, $overrides,
  sourceBlueprintId)` copy **từ CHÍNH version đó** (deep copy tiers/rules + category_id, id bảng MỚI),
  vẫn nối tiếp chain + supersede current active, `version_no` = max+1. Nguồn lệch template → 422.
- KHÔNG có source → copy từ current blueprint (hành vi cũ, test cũ giữ nguyên).

### 16.5 Routes & UI mới

| Method | URI | Tên route | Ghi chú |
|---|---|---|---|
| POST | `/admin/credit-card/api/policies/{template}/default` | `admin.credit-card-policies.api.default.store` | body `version_id`; flash "Version N đã được đặt làm mặc định." |
| DELETE | `/admin/credit-card/api/policies/{template}/versions/{version}` | `admin.credit-card-policies.api.versions.destroy` | flash "Đã xóa Version N." |

Cả 2: middleware `permission:credit-cards.manage` + `authorize('update', $template)` (Admin role) +
guard service; lỗi → flash `error` (đặt mặc định/xoá) hoặc JSON 422 (đặt mặc định qua API không tồn
tại). `storeVersion` giờ cũng bắt `LogicException` → 422 (guard "version nguồn không thuộc template").

UI `show.blade.php` viết lại: card thông tin view/`Sửa thông tin` (PATCH metadata → flash + reload;
min/cap/effective_from hiển thị read-only kèm note "thuộc phiên bản"), "Lịch sử phiên bản chính sách"
(danh sách card mobile-first: phiên bản, ⭐ Mặc định, chip Hiện hành/Đã thay thế, hiệu lực, tổng hợp
bậc/rule; hành động ✏️ Sửa version này / ⭐ Đặt mặc định / 🗑 Xóa phiên bản; **mọi version không
phải mặc định đều có nút 🗑 Xóa bấm được**, chỉ version mặc định mới disable + kèm title
"Không thể xóa phiên bản đang mặc định..."). Chỉ render action khi `canManage`.

### 16.6 Quyết định thiết kế (chốt giai đoạn 2)

1. CLONE = DEFAULT version (không phải latest) — admin kiểm soát nguồn cấp mới.
2. Sửa version N = phiên bản mới copy TỪ N; không có "sửa trực tiếp" record (giữ bất biến append-only).
3. Xoá version cho phép dọn dữ liệu nháp nhưng có guard: default, đang được dùng, gốc chuỗi, nguồn cuối.
4. Metadata (tên/mô tả/trạng thái) chỉ sửa qua `update`; cấu hình min/cap/effective là của version.
5. Editor/Alpine `systemPolicyEditor` nhận thêm tham số `sourceVersionId`, gửi `source_version_id`.

### 16.7 Files đổi

| File | Thay đổi |
|---|---|
| `database/migrations/creditcard/2026_10_01_000013_*.php` | MỚI: `default_version_id` (nullable, nullOnDelete, index); đã migrate DB thật |
| `app/Models/CreditCard/PolicyTemplate.php` | `default_version_id` fillable/cast; `defaultVersion()`; `defaultBlueprint()` |
| `app/Models/CreditCard/PolicyVersion.php` | `usageCounts()` / `usageCountsForPolicy()` / `isReferenced()` |
| `app/Services/CreditCard/PolicyService.php` | `createSystemTemplate` set default=v1; `createSystemVersion(source?)`; `setDefaultVersion`; `deleteSystemVersion` (chỉ chặn default; xóa gốc chuỗi ⇒ remap `root_policy_id`); `versionUsageLabels` (chỉ để hiển thị, không chặn); `normaliseDefault`; clone guard dùng default |
| `app/Services/CreditCard/PolicyCloneService.php` | `createTemplateBlueprint(..., ?sourceBlueprintId)`; `attachTemplateToCard` dùng default |
| `app/Support/CreditCard/SystemPolicyPresenter.php` | template: `default_version_id`/`default_version_no` + dùng default; blueprint: `is_default`/`referenced`/`can_delete`/`delete_block_reason` |
| `app/Http/Requests/Admin/CreditCard/StoreSystemPolicyVersionRequest.php` | `source_version_id` (sometimes nullable integer exists policies.id) + accessor |
| `app/Http/Controllers/Admin/CreditCard/SystemPolicyApiController.php` | `destroyVersion`, `setDefaultVersion` (RedirectResponse); storeVersion catch LogicException; update flash |
| `app/Http/Controllers/Admin/CreditCard/SystemPolicyAdminController.php` | `show` truyền `canManage`; `edit` nhận `?version=` (resolveVersionForEdit) |
| `routes/web.php` | +2 route (`default.store`, `versions.destroy`) trong api group |
| `resources/views/admin/credit-card-policies/show.blade.php` | viết lại (info card view/edit + version list + actions + flash), Alpine `systemPolicyShow` |
| `resources/views/admin/credit-card-policies/edit.blade.php` | note "Đang chỉnh sửa từ Version N" + truyền sourceVersionId |
| `resources/views/admin/credit-card-policies/partials/editor.blade.php` | tham số `$sourceVersionId`, payload `source_version_id` |
| `resources/views/admin/credit-card-policies/index.blade.php` | chip "⭐ Mặc định: Version N" |
| `app/Http/Controllers/CreditCard/PolicyTemplateController.php` | present dùng `defaultBlueprint()` |

### 16.8 Tests mới (tất cả trong `SystemPolicyManagementTest`)

`user_clone_uses_default_blueprint_not_just_latest` (viết lại semantic cũ), `creating_a_system_policy_sets_the_first_blueprint_as_default`, `creating_new_versions_does_not_change_the_default_version`, `default_marker_is_exposed_to_admin_pages_and_api`, `setting_a_default_version_updates_the_source_for_new_users_and_ui`, `setting_a_nonexistent_or_foreign_version_as_default_is_rejected`, `unused_version_can_be_deleted_and_default_stays`, `referenced_version_cannot_be_deleted` (statement_period trỏ blueprint), `default_version_and_chain_root_cannot_be_deleted`, `new_boundaries_are_forbidden_for_viewer_and_manage_without_admin_role`, `admin_can_edit_metadata_and_show_page_reflects_it`, `editing_a_specific_version_copies_from_that_version_and_keeps_others_intact`, `version_edit_page_targets_the_requested_version`.

### 16.9 Kết quả

| Phạm vi | Kết quả |
|---|---|
| `SystemPolicyManagementTest` | **39 passed / 267 assertions** |
| `php artisan test --filter CreditCard` | **309 passed / 1898 assertions** |
| `php artisan test` (toàn suite) | **1444 passed / 3 failed (3 baseline) / 5982 assertions** |
| `vendor/bin/pint --dirty` | fixed (migration, `routes/web.php`, `RolePermissionSeeder` — import FQCN, mechanical) |

3 baseline giữ nguyên. **CÒN LẠI (chưa làm, có chủ đích)**: MB Ultimate JCB v2 (0 rules) chưa hồi —
chờ admin tạo version trên UI; không đụng trong giai đoạn này.

---

# 17. Giai đoạn 3 — "Fix Edit Version 1" + nút "Lưu lại" (update in-place)

## 17.1 Kết luận bug "Edit Version 1 không load category" (đã xác minh bằng chứng, không đoán)

**Server KHÔNG sai — lỗi nằm ở phía CLIENT (Alpine).**

- Render thật trang edit (`diag_render_edit.php`, auth bằng `User` #1, không role) chứng minh x-data của
  editor chứa đủ rule của version đang sửa: `\u0022category_id\u0022:9 / :10 / :8` (MB JCB) và tham số
  `categories` đã có toàn bộ danh mục hệ thống ids 1..19 (`id`:8/9/10 đều có).
- Trình tự Alpine: `x-model="rule.category_id"` gán value **trong lúc init**, và thời điểm đó `<template
  x-for="cat in categories">` **chưa render các `<option>`** → không có option nào khớp → select rơi vào
  placeholder `— Chọn danh mục —`. Khi x-for chạy xong, x-model KHÔNG chạy lại → không bao giờ tự khoá.
- Bug tái lập được với Alpine 3.15.12 (project khai `^3.4.2`).
- Fix (chốt): thêm `x-init="$nextTick(() => { $el.value = rule.category_id ?? ''; })"` vào `<select>` danh
  mục — gán lại value SAU khi `$nextTick` đã render options của x-for.
- Nghi vấn cũ "edit() thiếu `$categories`" đã bị bác: `SystemPolicyAdminController@edit` truyền đủ
  `categories` (hàm `systemCategories()`), trang render ra list đầy đủ.

## 17.2 Nghĩa kép của nút lưu trong editor

Một editor, HAI hành động lưu — KHÔNG trộn vào nhau:

| Nút | Method + route | Nghiệp vụ |
|---|---|---|
| **Lưu lại** | PATCH `versions.update` (`UpdateSystemPolicyVersionRequest` + `PolicyService::updateSystemVersion`) | Cập nhật **in-place CHÍNH version đang mở**: giữ `version_no`, giữ `id` của tier/rule khi còn khớp payload, xóa tier/rule vắng trong payload, không tạo version mới, không đổi default, không cascade sang thẻ user |
| **Lưu phiên bản mới** | POST `versions.store` + `source_version_id` | Deep copy version đang mở thành phiên bản kế tiếp (id bảng MỚI, `category_id` giữ nguyên), như §16.4 |

- Trang **Tạo mới** không có `updateEndpoint` → chỉ hiện nút đơn `Tạo chính sách hệ thống` + Hủy.
- Trang **Chỉnh sửa** có `updateEndpoint` → hiện `Lưu lại` / `Lưu phiên bản mới` / `Hủy`.
- Nút bấm di động thân thiện: `flex-col` trên mobile, `sm:flex-row` trên desktop, `w-full sm:w-auto`,
  chiều chạm tối thiểu 44px (`min-h-[44px]`).

## 17.3 `updateSystemVersion` — cấu trúc & guard

- Thân hàm chạy trong `DB::connection('creditcard')->transaction()`.
- Guard: `version->template_id === template->id` và `user_card_id === null` → sai: `InvalidArgumentException`
  "Phiên bản không thuộc blueprint của chính sách này."; `is_locked` → `LogicException` "đã bị khoá" (kỳ
  đã finalize). Controller bắt cả 2 → JSON 422.
- Cập nhật metadata version (`effective_from`, `min_total_spend`, `max_cashback_total_per_period`) chỉ khi
  `array_key_exists` (không reset mặc định khi thiếu).
- `syncTiers`: tier vắng trong payload bị xóa; tier có `id` khớp giữ id và cập nhật; tier mới tạo.
- `syncRules`: rule vắng bị xóa (chỉ xóa `PolicyTierCategory`, không bao giờ xóa danh mục Master); rule có
  `id` khớp giữ id (kể cả đổi `category_id`); rule trùng category trong cùng tier: giữ rule xuất hiện trước,
  bỏ bản sau (theo UNIQUE (tier_id, category_id, spend_from)); trước khi đổi category cho rule giữ id, xóa
  các rule khác đang giữ category đích để tránh vi phạm UNIQUE (có xử lý swap category hai chiều).
- `assertSystemOnlyCategories` chốt ngay trước transaction: mọi `category_id` trong `tiers[].rules` phải là
  danh mục hệ thống đang active.
- KHÔNG: tạo version mới, đổi `version_no`, đổi default, đổi metadata template (tên/mô tả/trạng thái — tự
  qua PATCH `api.update` như trước), cascade user policy (thẻ clone là bản SAO, không tham chiếu blueprint).

## 17.4 Canonical `tiers[].rules` — bỏ hẳn `tier.categories`

- Presenter xuất `tiers[].rules`; editor giữ state `tier.rules` **nguyên một-một** (không normalize
  rules↔categories — lỗi gốc giai đoạn 1 đã xoá sạch rule khi lưu lần đầu); payload gửi `rules` + giữ `id`.
- Nơi đã chuyển sang đọc `rules` (28 fixture test + code):
  - `PolicyService::insertTiers` (`createFromScratch`/user) — đọc `$tier['rules']`.
  - `PolicyService::assertSystemOnlyCategories` — pluck từ `rules`.
  - `PolicyCloneService::replaceChildren` (tạo version/template) — đọc `$tier['rules']`.
  - `CreateSystemPolicyRequest` + `StoreSystemPolicyVersionRequest` — validate `tiers.*.rules.*`.
- BỊ CẤM: fallback/alias giữa `rules` ↔ `categories`; không đụng các `categories` không liên quan
  (`SystemPolicyAdminController::systemCategories()` dropdown, `CategoryRuleController`, `UserCardController`,
  PromotionNews, PriceComparison).

## 17.5 Request mới `UpdateSystemPolicyVersionRequest`

- CHỈ cấu hình thuộc version: `effective_from`, `min_total_spend`, `max_cashback_total_per_period`, `tiers`
  (kèm `tiers.*.id`/`rules.*.id` optional, `category_id` required + exists, `cashback_percent` 0–100...).
- KHÔNG nhận: `name`, `description`, `status`, `source_version_id`.
- `overrides()` chỉ trả các field có mặt + `array_values($tiers)` → truyền thẳng vào `updateSystemVersion`.

## 17.6 Routes, controller, UI

- Route mới: `PATCH /admin/credit-card/api/policies/{template}/versions/{version}` →
  `admin.credit-card-policies.api.versions.update`, middleware `permission:credit-cards.manage` +
  `authorize('update', $template)`; method `updateVersion` trong `SystemPolicyApiController`.
- Flash: `Đã tạo Version N.` (storeVersion) & `Đã cập nhật Version N.` (updateVersion) — `index.blade.php`
  đã hiển thị `session('success')`.
- `edit.blade.php`: note mới giải thích 2 nút; wording `Đang chỉnh sửa từ Version N` → `Đang chỉnh sửa
  Version N`; truyền `updateEndpoint`.
- Editor Alpine: signature `systemPolicyEditor(initial, endpoint, categories, sourceVersionId,
  updateEndpoint)`; `payload()` (tạo mới / Lưu phiên bản mới) vs `versionConfig()` (Lưu lại, chỉ cấu hình
  version); `submit(mode)` với `mode === 'current'` → PATCH `updateEndpoint`, ngược lại POST `endpoint`.
- Select danh mục: thêm `x-init="$nextTick(...)"` (§17.1) để khoá đúng giá trị.

## 17.7 Tests mới (8, đều trong `SystemPolicyManagementTest`)

| Test | Chốt |
|---|---|
| `edit_page_hydrates_the_rules_and_category_options_of_the_target_version` | A — trang edit nhúng đủ `category_id` + `id` rule của đúng version; danh mục có sẵn |
| `editor_payload_uses_the_canonical_rules_key_only` | B — payload `rules`, không còn `categories` |
| `in_place_save_updates_the_same_version_keeping_ids` | C — PATCH giữ id tier/rule, `version_no`=1, không tạo version/default đổi |
| `in_place_save_on_a_newer_version_keeps_the_chain_and_default` | D — sửa on v2 không đổi default v1, chain giữ nguyên |
| `in_place_save_does_not_rewrite_cloned_cards` | E — thẻ clone vẫn 3% khi blueprint thành 9% |
| `in_place_save_removes_absent_rules_and_keeps_present_ids` | F — rule vắng bị xóa, rule giữ id |
| `in_place_update_rejects_foreign_or_locked_versions` | G — version của template khác / đã khoá → 422 |
| `versions_update_follows_the_auth_and_policy_matrix` | H — guest 401, member/viewer/editor(non-Admin) 403, manager 200 |

Các test auth cũ cũng được mở rộng 1 dòng PATCH `versions.update` (guest, member, viewer, manage-non-admin).
Deep-copy version (lưu ra bản mới, id mới, giữ category) đã có sẵn từ §16: `editing_a_specific_version_copies_...`.

## 17.8 Kết quả

| Phạm vi | Kết quả |
|---|---|
| `SystemPolicyManagementTest` | **47 passed / 321 assertions** |
| `php artisan test --filter CreditCard` | **317 passed / 1952 assertions** |
| `php artisan test` (toàn suite) | **1452 passed / 3 failed (3 baseline) / 6036 assertions** |
| `vendor/bin/pint --dirty` | fixed: `PolicyService.php`, `UpdateSystemPolicyVersionRequest.php` (mechanical) |

3 baseline giữ nguyên: `Auth\RegistrationTest`, `ShopeeFoodOrderSyncServiceTest`, `TikTokSyncPhase3Test`.
KHÔNG commit, KHÔNG push — chờ review.

## 18. Addendum - ADMIN "⚙️ Quản lý danh mục hệ thống" (cùng ngày)

### 18.1 Phạm vi & bất biến

Chỉ **3 thao tác write** trên danh mục hệ thống (master data dùng chung): **thêm mới**, **đổi tên**, **sắp xếp lại**. KHÔNG có xoá / ẩn / khôi phục / lưu trữ.

Bất biến cốt lõi:
- `category_id` là **ĐỊNH DANH**, `name` là **THUỘC TÍNH HIỆN TẠI** — đổi tên chỉ chạm đúng bản ghi `Category`; không tạo `/Admin` policy/version mới; không snapshot tên ⇒ template hệ thống cũ, user policy rule và dữ liệu lịch sử tự hiển thị tên mới qua relation (`$rule->category->name`).
- Module ADMIN **không làm hỏng module USER**: user vẫn quản lý danh mục riêng; user KHÔNG đổi được tên danh mục hệ thống (giữ nguyên `CategoryPolicy`).
- Seeder idempotent: chỉ tạo còn thiếu, KHÔNG reset tên / thứ tự / trạng thái admin đã chỉnh.

### 18.2 Cấu trúc code

| File | Vai trò |
|---|---|
| `app/Services/CreditCard/SystemCategoryService.php` | Service riêng; `all/create/update/reorder`; transaction; `sort_order` mới = max+10; reorder validate ĐỦ và ĐÚNG tập id hệ thống rồi chuẩn hoá 1..N; `assertSystem` guard; slug chuẩn hoá `Str::slug` + guard trùng slug |
| `app/Http/Requests/Admin/CreditCard/StoreSystemCategoryRequest.php` | `authorize()` = `hasPermissionTo('credit-cards.manage')`; `name`/`slug` required·max150 (slug unique trong scope system, chuẩn hoá kebab), `description` nullable max1000; Vietnamese messages; `payload()` |
| `app/Http/Requests/Admin/CreditCard/UpdateSystemCategoryRequest.php` | `name`/`description` only; `payload()` chỉ field có mặt |
| `app/Http/Requests/Admin/CreditCard/ReorderSystemCategoriesRequest.php` | `ordered_ids` required·array·min:1, mỗi phần tử integer |
| `app/Http/Controllers/Admin/CreditCard/SystemCategoryAdminController.php` | index (Blade) / store (201) / update (404 nếu non-system) / reorder (200 `{saved:true}` hoặc 422 `{message}` khi `InvalidArgumentException`) |
| `resources/views/admin/system-categories/index.blade.php` | Trang quản lý (mobile-first) |

### 18.3 Routes & phân quyền (không tạo permission mới)

| Method | URI | Name | Permission |
|---|---|---|---|
| GET | `/admin/credit-card/system-categories` | `admin.credit-card.system-categories.index` | `credit-cards.view` |
| POST | `/admin/credit-card/system-categories` | `...store` | `credit-cards.manage` |
| PATCH | `/admin/credit-card/system-categories/{category}` | `...update` | `credit-cards.manage` |
| POST | `/admin/credit-card/system-categories/reorder` | `...reorder` | `credit-cards.manage` |

Không có route `...destroy` (test khẳng định `Route::has(...destroy) === false`). Menu được đặt trong `@can('credit-cards.view')` (dropdown desktop + responsive), active khi `request()->routeIs('admin.credit-card.system-categories.*')`. Writes gửi JSON + CSRF từ `meta[name=csrf-token]` (đã có trong app layout), lưu xong flash `cc_syscat_flash` (localStorage) rồi reload.

### 18.4 UI

Mobile-first: toolbar tìm kiếm (tên/slug) + nút "+ Thêm danh mục"; danh sách CARD (STT badge, icon qua `CategoryIcon::for()`, tên, slug, nút ↑/↓ 44px, nút sửa, kéo-thả HTML5 khi không search); modal thêm/sửa (name*, slug* auto-slugify tới khi user chỉnh tay, description); lỗi per-field. Danh mục mới không có trong map icon → `CategoryIcon::DEFAULT` (🏷️). Không hard-code 19 trong Blade.

### 18.5 Seeder

`CreditCardSeeder::seedCategories()` chuyển từ `updateOrCreate` (overwrite `name`/`sort_order`) sang **create-if-missing**: chỉ tạo bản ghi đồng bộ với danh mục gốc khi slug chưa tồn tại; không ghi đè `name`/`description`/`sort_order`/`is_active`/`is_default` admin chỉnh.

### 18.6 Tests (22, đều trong `SystemCategoryAdminTest`)

| Nhóm | Test |
|---|---|
| Menu & phân quyền | `menu_visible_for_manager_hidden_for_member`, `only_three_write_routes_exist_and_no_delete_route_is_registered`, `guest_redirected_from_page_and_gets_401_on_json`, `member_without_permission_is_forbidden`, `viewer_with_read_only_permission_cannot_write` |
| Index | `index_lists_all_system_categories_with_name_slug_and_search` |
| Thêm mới | `admin_can_add_new_system_category`, `slug_is_normalized_to_kebab_case_on_create`, `duplicate_slug_within_system_scope_is_rejected`, `empty_name_or_slug_is_rejected`, `new_category_appears_in_user_selector_automatically` |
| Đổi tên | `rename_keeps_category_identity_and_updates_master_name`, `rename_does_not_create_new_policy_or_touch_rules`, `renamed_name_is_reflected_in_system_template_without_snapshot`, `renamed_name_is_reflected_in_user_policy_rule_without_snapshot`, `user_flow_still_cannot_rename_system_categories`, `admin_module_cannot_touch_user_categories` |
| Sắp xếp | `reorder_normalizes_sort_order_and_keeps_identity`, `reorder_rejects_partial_list_or_foreign_ids`, `reorder_keeps_rule_references` |
| Seeder | `seeder_is_idempotent_and_preserves_admin_edits`, `seeder_recreates_a_missing_canonical_category_without_overwriting_others` |

### 18.7 Kết quả

| Phạm vi | Kết quả |
|---|---|
| `SystemCategoryAdminTest` | **22 passed / 131 assertions** |
| `php artisan test --filter CreditCard` | **339 passed / 2083 assertions** |
| `php artisan test` (toàn suite) | **1474 passed / 3 failed (3 baseline) / 6167 assertions** |
| `vendor/bin/pint --dirty` | fixed 6 file mới (mechanical), `--test` clean |

3 baseline giữ nguyên: `Auth\RegistrationTest`, `ShopeeFoodOrderSyncServiceTest`, `TikTokSyncPhase3Test`.
KHÔNG commit, KHÔNG push — chờ review.

---

## 19. Addendum — ADMIN "📋 CLONE CHÍNH SÁCH HOÀN TIỀN HỆ THỐNG"

Tính năng clone **toàn bộ** một System Policy (template + mọi blueprint version + tier + category rule) thành một template hệ thống **mới, độc lập hoàn toàn**. Mọi bản ghi clone nhận ID mới, dữ liệu nguồn KHÔNG bị sửa/xoá.

### 19.1 Files changed

| File | Loại |
|---|---|
| `app/Services/CreditCard/PolicyCloneService.php` | SỬA — thêm `cloneSystemPolicy()` (engine deep clone duy nhất) |
| `app/Http/Requests/Admin/CreditCard/CloneSystemPolicyRequest.php` | TẠO MỚI — validate + authorize |
| `app/Http/Controllers/Admin/CreditCard/SystemPolicyAdminController.php` | SỬA — action `clone()` + index truyền `canManage` |
| `routes/web.php` | SỬA — route POST clone |
| `resources/views/admin/credit-card-policies/index.blade.php` | SỬA — nút [📋 Clone] + modal Alpine + `session('error')` |
| `resources/views/admin/credit-card-policies/edit.blade.php` | SỬA — hiển thị flash `success`/`error` |
| `tests/Feature/CreditCard/SystemPolicyCloneTest.php` | TẠO MỚI — 33 tests |

### 19.2 Route

| Method | URI | Name | Permission |
|---|---|---|---|
| POST | `/admin/credit-card/system-policies/{template}/clone` | `admin.credit-card.system-policies.clone` | `credit-cards.manage` |

CSRF mặc định (web middleware), KHÔNG có GET. `authorize()` ở `CloneSystemPolicyRequest` (`hasPermissionTo('credit-cards.manage')`) chặn trùng lặp phòng hờ. Route model binding `{template}` → `PolicyTemplate`. (Lưu ý: prefix `credit-card.system-policies.*` theo đúng spec user, khác prefix `credit-card-policies.*` của các route trang sẵn có.)

### 19.3 Logic clone (`cloneSystemPolicy`, trong `DB::connection('creditcard')->transaction`)

1. Guard: nguồn phải `isSystemScope()` nếu không → `InvalidArgumentException`; nguồn không có blueprint → `LogicException` (controller bắt → back + flash `error`, log `[CreditCard][CloneSystemPolicy] blocked`).
2. Tạo template hệ thống mới: `is_builtin=false`, `is_active`=nguồn, `description`=nguồn, `slug = uniqueSlug(name)` (không trùng kể cả clone tên giống nhau), `sort_order = max(sort_order hệ thống)+1` ⇒ bản clone xếp cuối danh sách.
3. Clone từng blueprint (`version_no` tăng dần): giữ `version_no`, `status`, `effective_from/to`, `min_total_spend`, `max_cashback_total_per_period`, `rounding_mode`, `note`; `root_policy_id` giữ chain (root tự trỏ, các version khác trỏ về root mới); **`is_locked` luôn `false`** — bản clone mới chưa có kỳ nào finalize, giữ lock sẽ chặn việc "clone để chỉnh sửa" (test `cloned_versions_are_unlocked_even_when_source_versions_are`).
4. `copyChildren()` tái sử dụng: clone tier + category rule — giữ `category_id` (Category Master KHÔNG nhân đôi), name, sort_order, spend range, cap, min_transaction_amount, is_enabled, note.
5. **Remap `default_version_id`** qua map old→new; fallback về root (version 1) clone nếu default cũ vô hiệu. Đúng cả kịch bản default = Version 2 giữa chuỗi.
6. Toàn bộ chạy trong một transaction ⇒ fail giữa chừng rollback tất cả.

### 19.4 Authorization

- Chỉ `credit-cards.manage` (Role Admin): route middleware + FormRequest authorize + `@if ($canManage)` ẩn nút.
- Viewer (`credit-cards.view`) vẫn xem index nhưng KHÔNG thấy [📋 Clone] (test `clone_button_is_hidden_for_users_without_manage_permission`).
- User thường / viewer gọi thẳng endpoint → **403** (test `non_admin_cannot_call_clone_endpoint`), không tạo gì.
- Audit: `Log::info('[CreditCard][CloneSystemPolicy] cloned', action, source_policy_id, new_policy_id, new_policy_name, admin_id, timestamp)`.

### 19.5 UI

- Trang index, mỗi card: [Xem] [Chỉnh sửa] [Các phiên bản] + **(mới) [📋 Clone]** (amber, `min-h-[44px]`, cùng flex-wrap — mobile 360/375/390px không vỡ layout).
- Nút mở modal Alpine (`<x-modal name="clone-policy-form">`): "Clone chính sách hoàn tiền", tên chính sách nguồn, ô "Tên chính sách mới **\***" prefill `"{Tên nguồn} - Copy"`, ghi chú "Bản clone nhận ID mới hoàn toàn…". Submit = form POST + `@csrf` (native redirect, không fetch) → redirect về trang **Chỉnh sửa** bản clone + flash `Đã clone chính sách "X" thành "Y".`.
- `CloneSystemPolicyRequest` trim trước khi validate (tên toàn khoảng trắng → lỗi `name.required`), `max:150`.
- `edit.blade.php` bổ sung flash `success`/`error` (trước đây chỉ `index` có).

### 19.6 Tests (33, `SystemPolicyCloneTest`)

| Nhóm | Test |
|---|---|
| Endpoint & phân quyền | `admin_can_clone_..._redirected_to_edit_page`, `clone_button_is_hidden_for_users_without_manage_permission`, `non_admin_cannot_call_clone_endpoint`, `clone_name_is_required_and_trimmed` |
| Template clone | `clone_creates_a_new_independent_template`, `clone_uses_the_admin_entered_name`, `clone_creates_an_unique_slug` |
| Deep clone thành phần | `clone_copies_all_versions_with_the_same_order`, `cloned_versions_get_fresh_ids`, `clone_preserves_version_numbers_and_chain`, `clone_preserves_status_effective_dates_and_note_of_each_version`, `cloned_versions_are_unlocked_even_when_source_versions_are`, `clone_copies_all_tiers_per_version`, `cloned_tiers_get_fresh_ids`, `clone_copies_all_category_rules_per_tier`, `cloned_category_rules_get_fresh_ids`, `category_ids_are_preserved_in_the_clone`, `default_version_id_is_remapped_to_the_cloned_version`, `multi_version_policy_version_two_stays_default_after_clone` |
| Độc lập (§13) | `source_policy_is_untouched_after_clone`, `editing_tier_in_clone_does_not_affect_source`, `editing_rule_in_clone_does_not_affect_source`, `restructuring_clone_does_not_affect_source`, `cloning_an_existing_template_twice_keeps_both_fully_independent` |
| Không đụng dữ liệu khác | `clone_does_not_create_transactions`, `clone_does_not_create_statement_periods`, `clone_does_not_create_user_cards`, `clone_does_not_create_user_policies`, `clone_does_not_create_cashback_records`, `category_master_is_not_duplicated` |
| Transaction/rollback | `clone_commits_as_one_consistent_database_unit`, `clone_failure_midway_rolls_back_everything` (sqlite trigger `BEFORE INSERT ON credit_card_policy_tiers ... RAISE(ABORT)` → QueryException, DROP trong finally), `cloning_a_policy_without_versions_returns_error_and_creates_nothing` |

### 19.7 Kết quả

| Phạm vi | Kết quả |
|---|---|
| `SystemPolicyCloneTest` | **33 passed / 197 assertions** |
| `php artisan test --filter CreditCard` | **372 passed / 2280 assertions** |
| `php artisan test` (toàn suite) | **1507 passed / 3 failed (3 baseline) / 6364 assertions** |
| `vendor/bin/pint --dirty` | fix mechanical, `--test` clean |

3 baseline giữ nguyên: `Auth\RegistrationTest`, `ShopeeFoodOrderSyncServiceTest`, `TikTokSyncPhase3Test`.
KHÔNG commit, KHÔNG push — chờ review.

---

## 20. Addendum — REFACTOR: CLONE qua editor (thay modal cũ ở §19)

UBER quay lại bản thiết kế: clone KHÔNG còn là modal POST riêng (§19.5 cũ bị bỏ). Thay vào đó [📋 Clone] mở **chính trang edit** qua GET (không ghi DB), admin chỉnh tay toàn bộ cấu hình, rồi bấm **[Lưu thành chính sách mới]** → front-end POST JSON payload editor (đúng validate/business rules của Edit) → tạo template clone trong 1 transaction → redirect về trang **Chỉnh sửa bản clone** + flash.

### 20.1 Files changed (refactor)

| File | Loại |
|---|---|
| `app/Services/CreditCard/PolicyCloneService.php` | SỬA — thêm engine `createSystemPolicyFromEditor()`; giữ `cloneSystemPolicy()` thành wrapper mỏng (compat) |
| `app/Http/Requests/Admin/CreditCard/CloneSystemPolicyRequest.php` | REWRITE — extends `StoreSystemPolicyVersionRequest` + thêm `name` required (không còn `name()`/authorize riêng của §19) |
| `app/Http/Controllers/Admin/CreditCard/SystemPolicyApiController.php` | SỬA — thêm `clone()` JSON (201 + `redirect`, flash, 422) |
| `app/Http/Controllers/Admin/CreditCard/SystemPolicyAdminController.php` | SỬA — bỏ `clone()` POST cũ, thêm `cloneForm()` GET (chỉ hydrate dữ liệu, không ghi gì) |
| `routes/web.php` | SỬA — POST clone cũ → GET `cloneForm` (cùng name); thêm POST API `clone` trong nhóm `credit-card/api` |
| `resources/views/admin/credit-card-policies/index.blade.php` | SỬA — xoá modal `clone-policy-form`, hàm Alpine `clonePolicy()`, `x-data`; nút Clone thành link GET |
| `resources/views/admin/credit-card-policies/edit.blade.php` | SỬA — mode-aware (cloneMode): title "📋 Clone chính sách hoàn tiền hệ thống", banner "Bản sao từ …", endpoint + submitLabel |
| `resources/views/admin/credit-card-policies/partials/editor.blade.php` | SỬA — success redirect ưu tiên `data.redirect` (clone → edit bản mới), fallback index |
| `tests/Feature/CreditCard/SystemPolicyCloneTest.php` | REWRITE — 35 tests cho flow mới |

### 20.2 Routes (đều `permission:credit-cards.manage`)

| Method | URI | Name | Xử lý |
|---|---|---|---|
| GET | `/admin/credit-card/system-policies/{template}/clone` | `admin.credit-card.system-policies.clone` | `cloneForm` (Blade edit, mode=clone) |
| POST | `/admin/credit-card/api/system-policies/{template}/clone` | `admin.credit-card-policies.api.clone` | `clone` (JSON) |

`cloneForm`: không có blueprint → `back()` + flash error (không tạo gì); hydrate `defaultBlueprint() ?? currentBlueprint()` qua `SystemPolicyPresenter`, prefill tên `"{Nguồn} - Copy"`, `editingVersionNo=null`, `sourceVersionId=$blueprint->id`, `cloneMode=true`, `sourceName`. Viewer/member → 403 cả 2 route (`authorize('update', $template)` + `@if ($canManage)` ẩn nút).

### 20.3 Engine `createSystemPolicyFromEditor($source, $name, $description, $isActive, $effectiveFrom, $overrides, $sourceBlueprintId)`

- Giữ mọi nguyên tắc §19.3 (deep clone toàn bộ blueprint/tier/rule, ID mới, chain giữ nguyên, `is_locked=false`, Category Master không nhân đôi, remap `default_version_id` — edited == default → trỏ bản edited; ngược lại theo `version_no` của default nguồn; fallback version 1).
- **Overlay bản edited**: blueprint `sourceBlueprintId` (phải thuộc `$source`, ngược lại `LogicException`) được thay cấu hình version qua `replaceChildren`: `effective_from`, `min_total_spend`, `max_cashback_total_per_period`, `rounding_mode` + toàn bộ `tiers` (giống PATCH `versions.update`); các blueprint khác giữ nguyên bản sao.
- Guard hệ thống trong payload: `assertSystemOnlyCategories()` (private, sao chép cùng message với `PolicyService` — tránh phụ thuộc vòng) chốt `tiers[].rules[].category_id` phải là danh mục hệ thống đang active.
- 1 transaction duy nhất; fail giữa chừng → rollback tất cả. Nguồn KHÔNG bị sửa/xoá.
- `cloneSystemPolicy($source, $name)` cũ vẫn hoạt động = engine với `[], null` (không overlay).

### 20.4 API `clone()` & UI

- API: `authorize('update', $template)`; gọi engine với `$request->meta()`/`date('effective_from')`/`overrides()`/`sourceVersionId()`; `InvalidArgumentException|LogicException` → 422 `{message}` (editor giữ nguyên trạng thái, không sinh bản ghi); thành công → flash `Đã tạo chính sách "%s" từ bản sao "%s".` + 201 `{data, redirect: route(edit, cloned)}`.
- Editor: cloneMode dùng `endpoint=admin.credit-card-policies.api.clone`, `updateEndpoint=null` (chỉ 1 nút "Lưu thành chính sách mới"), `submitLabel='Lưu thành chính sách mới'`; `[Quay lại]/[Hủy]` → index. Success redirect: `data?.redirect || index`.
- `CloneSystemPolicyRequest` kế thừa mọi validate của `StoreSystemPolicyVersionRequest` (tiers/rules/category_id/cashback 0–100…), trim + `name` required·max150.

### 20.5 Tests (35, `SystemPolicyCloneTest` — REWRITE)

| Nhóm | Test |
|---|---|
| GET clone editor | `manager_can_open_the_clone_editor_page`, `opening_the_clone_editor_writes_no_database_rows`, `clone_editor_is_403_for_users_without_manage_permission`, `clone_editor_for_empty_policy_redirects_back_without_creating_anything`, `clone_button_is_hidden_for_users_without_manage_permission` |
| POST API | `admin_posts_clone_payload_and_gets_a_new_policy_with_redirect`, `non_admin_cannot_call_the_clone_api`, `clone_api_requires_a_name`, `clone_api_rejects_whitespace_only_name`, `clone_api_rejects_an_invalid_category_id`, `clone_api_rejects_cashback_percent_above_100`, `clone_api_rejects_a_user_scoped_category_in_the_payload` |
| Template meta | `clone_creates_a_new_independent_template_with_payload_meta`, `clone_uses_the_admin_edited_name`, `clone_creates_an_unique_slug` |
| Deep clone | `clone_copies_all_versions_with_the_same_order`, `cloned_versions_get_fresh_ids`, `clone_preserves_version_numbers_and_chain`, `non_edited_blueprints_preserve_business_metadata`, `edited_blueprint_gets_the_payload_configuration`, `cloned_versions_are_unlocked_even_when_source_versions_are`, `clone_copies_all_tiers_per_version`, `cloned_tiers_get_fresh_ids`, `clone_copies_all_category_rules_per_tier`, `cloned_category_rules_get_fresh_ids`, `category_ids_are_preserved_and_category_master_is_not_duplicated`, `default_version_id_is_remapped_to_the_edited_version` |
| Độc lập | `source_policy_is_untouched_after_clone`, `editing_tier_in_clone_does_not_affect_source`, `restructuring_clone_does_not_affect_source`, `cloning_an_existing_template_twice_keeps_both_fully_independent` |
| Business data & atomicity | `clone_does_not_touch_business_data`, `clone_commits_as_one_consistent_database_unit`, `clone_failure_midway_rolls_back_everything` (sqlite trigger RAISE(ABORT)), `cloning_a_policy_without_versions_returns_error_and_creates_nothing` |

### 20.6 Kết quả

| Phạm vi | Kết quả |
|---|---|
| `SystemPolicyCloneTest` | **35 passed / 284 assertions** |
| `SystemPolicyManagementTest` | **47 passed / 321 assertions** |
| `SystemCategoryAdminTest` | **22 passed / 131 assertions** |
| `php artisan test --filter CreditCard` | **374 passed / 2367 assertions** |
| `php artisan test` (toàn suite) | **1512 passed / 3 failed (3 baseline) / 6451 assertions** |
| `vendor/bin/pint --dirty` | fixed: `CloneSystemPolicyRequest.php`, `SystemPolicyCloneTest.php` (mechanical) |

3 baseline giữ nguyên: `Auth\RegistrationTest`, `ShopeeFoodOrderSyncServiceTest`, `TikTokSyncPhase3Test`.
KHÔNG commit, KHÔNG push — chờ review.

---

## 21. Trang "[Xem]" chỉ đọc + chuyển trần hoàn về BẬC (`max_cashback_per_period`)

### 21.1 Bối cảnh

Hai thay đổi làm đi từng yêu cầu:

- **(A)** Trang `admin.credit-card-policies.show` ("[Xem]") trước đây vừa hiển thị vừa cho sửa
  ngay trên trang (nút sửa/đặt mặc định/xóa từng version). Yêu cầu: chuyển thành trang **XEM
  CHỈ ĐỌC** dùng lại editor chung (không có bất kỳ nút lưu / thêm / xóa nào), chỉ giữ hành động
  `✏️ Chỉnh sửa` + `📋 Clone` khi người dùng có quyền `credit-cards.manage`, còn lại hiện chip
  "Quyền xem".
- **(B)** Trần hoàn tiền tối đa mỗi kỳ hiện đang đặt ở **cấp chính sách**
  (`credit_card_policies.max_cashback_total_per_period`, đồng nhất cho mọi bậc). Yêu cầu: chuyển
  về **từng bậc** (`credit_card_policy_tiers.max_cashback_per_period`), bỏ ô trần cấp chính sách
  khỏi toàn bộ admin (UI + API + presenter + request + calc pipeline).

### 21.2 Migration + backfill (idempotent)

`database/migrations/creditcard/2026_10_01_000014_add_max_cashback_per_period_to_credit_card_policy_tiers_table.php`:

- Thêm `max_cashback_per_period DECIMAL(16,2) NULL` (sau `max_total_spend`) trên
  `credit_card_policy_tiers` — nullable vì "không giới hạn" = để trống; miêu tả bằng comment.
- Chạy `PolicyService::backfillSystemBlueprintCaps()` ngay trong migration:
  - Chỉ xét **system blueprint** (`user_card_id IS NULL`) **đang còn trần cũ**
    (`max_cashback_total_per_period IS NOT NULL`).
  - Blueprint **1 bậc** và bậc đó chưa có trần → copy trần cũ xuống bậc (`backfilled++`).
  - Blueprint **nhiều bậc** → không thể chia đều, giữ nguyên, ghi vào `skipped_multi_tier`.
  - Idempotent: chạy lại không backfill thêm (đã có trần thì bỏ qua).
- `Log::info` kết quả gồm `backfilled` + `skipped_multi_tier_blueprint_ids` để chủ động xử lý
  các chính sách nhiều bậc đang có trần cũ.
- `down()`: `DROP COLUMN`.

### 21.3 Chuyển trần hoàn về bậc — layer theo layer

| Layer | Thay đổi |
|---|---|
| Model `PolicyTier` | thêm `max_cashback_per_period` vào `$fillable` + cast `decimal:2` + docblock `@property string|null` |
| Service | `TierService::create()` + `cloneTo()` nhận/ghi trần bậc (qua `normalizeMoney`); `PolicyCloneService::copyChildren()`/`replaceChildren()` copy/áp trần bậc; `PolicyService::insertTiers()`/`syncTiers()` (create + forceFill update in-place) kèm trần bậc |
| Requests | `CreateSystemPolicyRequest`, `StoreSystemPolicyVersionRequest`, `UpdateSystemPolicyVersionRequest`: **bỏ** rule trần policy, **thêm** `tiers.*.max_cashback_per_period => sometimes\|nullable\|numeric\|min:0`; loop overrides chỉ còn `min_total_spend` + `rounding_mode` (+ `effective_from`/`note` tùy request) |
| Presenter | `template()`/`blueprint()` KHÔNG còn `max_cashback_total_per_period`; `tiers()` phát `max_cashback_per_period => null\|float` |
| Calc engine | `CashbackCalculator::calculate()` tham số 4 đổi tên `maxCashbackTotalPerPeriod` → `maxCashbackPerPeriod` (cap 3 giờ của BẬC); `PolicyEngineService::resolveFor()` không còn truyền cap policy; `CashbackRecordService` lấy cap từ TIER (`$tier?->max_cashback_per_period`), key meta kỳ đổi thành `max_cashback_per_period` |
| `ResolvedPolicy` | bỏ hẳn field `maxCashbackTotalPerPeriod` (không dùng) |
| Editor partial | meta grid bỏ ô policy cap; tier grid `lg:grid-cols-4` thêm ô "Hoàn tiền tối đa của bậc / kỳ"; `versionConfig()`/state/`addTier()` kèm `max_cashback_per_period` (payload qua `num()`) |
| Non-edited/edited clone | `createSystemPolicyFromEditor` GIỮ overlay legacy trần policy (backward compat: cột cũ vẫn được copy từ nguồn; editor không gửi nữa) |

Cột legacy `credit_card_policies.max_cashback_total_per_period` **không drop** — giữ để đọc dữ
liệu cũ; toàn bộ viết mới đều về bậc.

### 21.4 Trang "[Xem]" — chế độ chỉ đọc (viewMode)

`resources/views/admin/credit-card-policies/show.blade.php` rewrite:

- **Header**: tên + badges (🟢 Đang sử dụng / ⚪ Nháp, ⭐ Mặc định: Version N) + "← Quay lại danh sách"
  + dòng kích thước "N bậc · M danh mục" + mô tả (nếu có).
- **Actions**: `canManage` (permission `credit-cards.manage`) → `[✏️ Chỉnh sửa]` (edit page) +
  `[📋 Clone]` (clone editor); ngược lại chip "Quyền xem".
- **Editor chung** được include với `viewMode => true`, `endpoint => ''`, `updateEndpoint => null`
  (`source_version_id` = default version vì editor hydrate từ ⭐ default). Khi `viewMode`:
  mọi input/select/textarea `:disabled`, nút `+ Thêm bậc` / `Xoá bậc` / `+ Thêm quy tắc` /
  `Xoá` đều `x-show="!viewMode"`; khối hành động `template x-if="updateEndpoint"` /
  `template x-if="!updateEndpoint"` bị `@if (! $viewMode)` loại khỏi output.
- **Lịch sử phiên bản** (heading "Lịch sử phiên bản chính sách"): chỉ đọc — ngày hiệu lực,
  min-spend + kích thước + rounding chips, từng bậc kèm "Hoàn tiền tối đa của bậc / kỳ"; **bỏ**
  nút per-version (✏️ Sửa version này / ⭐ Đặt mặc định / 🗑 Xóa phiên bản) và **bỏ** chip
  "Hoàn tiền tối đa / kỳ" cấp policy. Script Alpine `systemPolicyShow` cũ đã gỡ.

### 21.5 Legacy phía người dùng — KHÔNG đụng

Giữ nguyên (chỉ liệt kê, admin cap không hiện ở user):

- `app/Http/Controllers/CreditCard/PolicyController.php:191` (dùng policy cap).
- `app/Http/Controllers/CreditCard/PolicyTemplateController.php:85`.
- `app/Http/Requests/CreditCard/StorePolicyVersionRequest.php:36` (rule) + `:59` (payload).
- `resources/views/credit-card/policies.blade.php:260` và `:493` (form user).
- `tests/Feature/CreditCard/Phase1cHttpTest.php:97` (payload user giữ cap policy — vẫn hợp lệ khi
  clone hệ thống → bản sao cứ copy cột legacy).
- `database/seeders/CreditCardSeeder.php:367`: builtin 2 bậc cap 5.000.000 tại policy → đây chính là
  **trường hợp nhiều bậc** cần xử lý thủ công sau migration (nằm trong `skipped_multi_tier`).

### 21.6 Tests

**Đã sửa** (`tests/Feature/CreditCard/SystemPolicyManagementTest.php`, `SystemPolicyCloneTest.php`,
`tests/Unit/CreditCard/CashbackCalculatorTest.php`):

- `manager_can_create_published_system_policy_with_tiers_and_rules`: cap đưa vào TIER
  (`data.tiers.0.max_cashback_per_period` = 1000000), DB `'1000000.00'`, policy cap `null`.
- `in_place_save_updates_the_same_version_keeping_ids`: PATCH `max_cashback_per_period => 2000000`
  trong tier; assert tier `'2000000.00'` + policy cap `null`.
- `SystemPolicyCloneTest`: fixture `makeSourcePolicy()` v1 GIỮ cap policy legacy `'5000000'`
  (backward compat) + THÊM tier cap `'5000000'`; `non_edited_blueprints_preserve_business_metadata`
  giữ assert policy cap + thêm map tier cap; `edited_blueprint_gets_the_payload_configuration` thay
  override policy cap bằng tier cap và assert cap tier `'9000000.00'`; `editorPayload()` **bỏ**
  policy cap (khớp editor mới); `clone_copies_all_tiers_per_version` thêm `max_cashback_per_period`
  vào signature map.
- `CashbackCalculatorTest`: rename 22 named-arg `maxCashbackTotalPerPeriod:` → `maxCashbackPerPeriod:`.

**Thêm mới** (6 test):

| Nhóm | Test |
|---|---|
| Xem chỉ đọc | `manager_show_page_renders_readonly_editor_with_tier_cap` (editor chung viewMode, không `<template x-if>` hành động, không `@click="submit(...)"`, có tier cap hydrate `\u0022max_cashback_per_period\u0022:5000000`, không `max_cashback_total_per_period`, có "Lịch sử phiên bản chính sách", có Chỉnh sửa/Clone, có "← Quay lại danh sách") |
| Xem chỉ đọc | `viewer_show_page_is_readonly_without_manage_actions` ("Quyền xem", KHÔNG Chỉnh sửa/Clone, không route edit/clone, heading version history hiển thị) |
| Tier cap | `store_version_records_tier_cashback_cap_and_no_policy_cap` (versions.store 2.000.000 ở bậc, policy cap null) |
| Policy-cap removal | `create_and_store_ignore_legacy_policy_cap_record_tier_cap` (post cap cũ 999 bị BỎ IM LẶNG, tier cap 1000000 được lưu) |
| Validation | `tier_cashback_cap_rejects_negative_values` (cap −1 → 422 `tiers.0.max_cashback_per_period`) |
| Backfill | `backfill_copies_legacy_policy_cap_to_single_tier_blueprints_only` (1 bậc → backfill; 2 bậc → `skipped_multi_tier` + không chia đôi; idempotent) |

### 21.7 Kết quả

| Phạm vi | Kết quả |
|---|---|
| `CashbackCalculatorTest` | **26 passed / 70 assertions** |
| `SystemPolicyCloneTest` | **35 passed / 289 assertions** |
| `SystemPolicyManagementTest` | **53 passed / 372 assertions** |
| `SystemCategoryAdminTest` | **22 passed / 131 assertions** |
| `PolicyCloneServiceTest` | **16 passed / 50 assertions** |
| `php artisan test --filter CreditCard` | **380 passed / 2423 assertions** |
| `php artisan test` (toàn suite) | **1518 passed / 3 failed (3 baseline) / 6507 assertions** |
| `vendor/bin/pint --dirty` | first pass fixed migration formatting; re-run clean |

3 baseline giữ nguyên: `Auth\RegistrationTest`, `ShopeeFoodOrderSyncServiceTest`, `TikTokSyncPhase3Test`.
KHÔNG commit, KHÔNG push — chờ review.

## 22. Addendum — RULE FALLBACK "📦 CÁC DANH MỤC CÒN LẠI" + CỜ TÍNH VÀO TRẦN BẬC + BACKFILL (30/09)

### 22.1 Vì sao

- Mỗi BẬC phải có ĐÚNG MỘT rule fallback bắt mọi danh mục còn lại (`scope_type = 'other'`,
  `category_id = NULL`, `cashback_percent = 0.000`, `counts_toward_tier_cap = false`).
- Rule fallback KHÔNG ăn trần hoàn của Bậc/kỳ: chi tiêu vẫn tính vào `total_eligible_spend`
  (đủ điều kiện nhận hoàn), nhưng hoàn `0đ` và không chiếm `usedTotal`/cap.
- Admin có thể bật cờ "Tính vào giới hạn hoàn tiền của bậc" cho bất kỳ rule nào (cả fallback) —
  khi bật, fallback mới tính vào trần.

### 22.2 Chuẩn (được test)

| Quy tắc | Giá trị |
|---|---|
| `scope_type` | `category` (danh mục cụ thể) hoặc `other` (fallback) |
| `category_id` | bắt buộc khi `category`; **force NULL** khi `other` |
| `counts_toward_tier_cap` | mặc định `true` (cụ thể) / `false` (fallback); admin bật/tắt được |
| Fallback | `0%`, tự sinh đúng 1/bậc, không xóa được, không chuyển 1 rule cụ thể thành fallback khi đã có |
| Thêm bậc mới | tự động có fallback (`TierService::create`) |
| Clone | copy cả `scope_type` + cờ, id MỚI, không tạo bản thứ 2 |
| Editor round-trip | payload chứa fallback để ĐỌC; nếu thừa 2 dòng `other` → gộp giữ dòng đầu; thiếu → tái tạo đúng 1 |
| Trần Bậc/kỳ | chỉ rule `counts_toward_tier_cap = true` được tính (cap + `usedTotal`); rule `false` không bị clip |

Thứ tự quy đổi khi tính hoàn (`TierResolverService`): 1) rule cụ thể theo `category_id`, 2) fallback
theo dải chi tiêu của bậc, 3) không có rule → không đủ điều kiện. Fallback `0%` vẫn là rule "đủ
điều kiện" (không bị coi là thiếu rule).

### 22.3 Backfill (migration `000015`)

- Migration thêm `scope_type` + `counts_toward_tier_cap`, nới `category_id` thành NULLABLE, rồi gọi
  `CategoryRuleService::backfillFallbackRules()` — **idempotent**, chỉ tạo fallback cho bậc CHƯA có,
  KHÔNG sửa bất kỳ rule hiện có.
- Kết quả chạy trên DB dev (`hoantien_creditcard`, MySQL XAMPP) lúc 20:23:55:

| Chỉ số | Giá trị |
|---|---|
| `backfilled_tiers` | **5** |
| `existing_fallbacks` | 0 |
| `anomalies` | [] |
| `manual_review_tier_ids` | [] |

- Chạy lại `credit-card:migrate` → **"Nothing to migrate"** (không tạo thêm).
- Trạng thái sau: 4 policies / 5 tiers / 28 rules / **5 fallback** (1/bậc).
- Nhánh anomaly (`category_rule_without_category_id`, `fallback_has_category_id`) + manual review
  được phủ bởi test `backfill_flags_anomalies_without_touching_existing_rules` (M3); dev DB sạch nên
  report rỗng.

### 22.4 Một điều lệch thứ tự hiển thị (chỉ cosmetic)

- Đường `createSystemTemplate` / `replaceChildren`: tạo rule cụ thể (sort 1..N) **rồi** fallback cuối
  → fallback liệt kê CUỐI (DB dev: `sort_order = 7` mỗi bậc).
- Đường `createFromScratch` / `insertTiers` → `TierService::create`: gọi `ensureSingleFallback` ngay
  khi bậc chưa có rule → fallback `sort_order = 1` đứng ĐẦU.
- Cả hai đều đảm bảo ĐÚNG 1 fallback/bậc; thứ tự resolution theo scope (không theo sort), nên đây
  chỉ là khác biệt thứ tự hiển thị trong editor — không sửa trong đợt này.

### 22.5 Tests MỚI (40) — cả 5 file đều xanh

| File | Thêm | Tổng |
|---|---|---|
| `CashbackCalculatorTest` (unit) | +10 | **36** |
| `CardManagementServicesTest` | +11 | **53** |
| `SystemPolicyManagementTest` (admin) | +12 | **65** |
| `SystemPolicyCloneTest` | +6 | **41** |
| `CashbackPipelineTest` (E2E) | +1 | **17** |

Nhóm test: sinh fallback tự động & sort; `ensureSingleFallback` (idempotent, gộp trùng, config);
guard chặn (không xóa fallback, không có bản thứ 2, rule cụ thể bắt buộc category, không chuyển
thành fallback); round-trip editor/toggle; hydrat hóa view (show + editor non-removable); backfill
và flag anomaly; clone (giữ cấu hình, id mới, không nhân đôi, tái-clone); resolver (fallback scope
+ cờ phẳng hóa, mỗi tier 1 fallback trong `allEnabledRulesFor`); E2E giao dịch danh mục không có
rule cụ thể → fallback `0%` (eligible, `0đ`, không clip, spend vẫn tính minimum, period
`total_cashback` đúng).

### 22.6 Kết quả cuối

| Phạm vi | Kết quả |
|---|---|
| `CashbackCalculatorTest` | **36 passed** |
| `CardManagementServicesTest` | **53 passed / 133 assertions** |
| `SystemPolicyManagementTest` | **65 passed / 440 assertions** |
| `SystemPolicyCloneTest` | **41 passed / 361 assertions** |
| `CashbackPipelineTest` | **17 passed / 64 assertions** |
| `php artisan test --filter CreditCard` | **420 passed / 2638 assertions** |
| `php artisan test` (toàn suite) | **1555 passed / 3 failed (3 baseline) / 6722 assertions** |
| `vendor/bin/pint --dirty` | fixed 4 files (app + migration); chạy lại filter CreditCard vẫn xanh |

3 baseline giữ nguyên: `Auth\RegistrationTest`, `ShopeeFoodOrderSyncServiceTest`, `TikTokSyncPhase3Test`.
Migration đã chạy trên DB dev (backfill 5 bậc), hệ thống test đầy đủ. KHÔNG commit, KHÔNG push — chờ review.

---

# §23 — Giới hạn hoàn tiền theo giá trị giao dịch (cap ĐỘNG theo từng giao dịch)

## Mục tiêu

Một BẬC giờ có thể định nghĩa **nhiều khoảng giá trị giao dịch**, mỗi khoảng một
`max_cashback_per_transaction` riêng. Cap động là TÀI SẢN CỦA BẬC (migration 000017 chuyển từ
rule → bậc) và áp cho MỌI rule trong bậc — kể cả fallback 0%. Giao dịch thuộc khoảng nào dùng
cap của khoảng đó (THAY THẾ cap cố định `max_cashback_per_transaction`); không khớp khoảng nào
thì quay về cap cố định như cũ. Backward compatible hoàn toàn: cấu hình cũ không có cap động →
calculator chạy đúng như trước. Payload chuẩn: `tiers[].transaction_caps[]`.

## 23.1 Files (mới + sửa)

| File | Vai trò |
|---|---|
| `database/migrations/creditcard/2026_10_01_000016_create_credit_card_policy_tier_category_transaction_caps_table.php` | MỚI — bảng con của **bậc** |
| `database/migrations/creditcard/2026_10_01_000017_move_transaction_caps_from_rule_to_tier.php` | MỚI — đổi cap từ rule → bậc (phiên bản chốt) |
| `app/Models/CreditCard/PolicyTierCategoryTransactionCap.php` | MỚI |
| `app/Models/CreditCard/PolicyTier.php` | + `transactionCaps()` hasMany |
| `app/Services/CreditCard/TierService.php` | sở hữu `normalizeTransactionCaps`/`syncTransactionCaps`/`capsPayload` + wiring create/update/clone |
| `app/Services/CreditCard/PolicyService.php` | `insertTiers` + clone: sync cap bậc khi payload có `transaction_caps` |
| `app/Services/CreditCard/PolicyCloneService.php` | copyChildren + replaceChildren: copy cap của bậc với id MỚI |
| `app/Services/CreditCard/TierResolverService.php` | `transactionCapsForTier()` — nạp cap bậc khi tính cashback |
| `app/Services/CreditCard/CashbackCalculator.php` | `pickTransactionCap`: nhận cap bậc qua `$transactionCaps` |
| `app/Support/CreditCard/SystemPolicyPresenter.php` | hydrate `transaction_caps` vào BẬC (blade + API JSON cùng một nguồn) |
| `resources/views/admin/credit-card-policies/partials/editor.blade.php` | panel cap cấp bậc (checkbox + khoảng) + payload `tiers[].transaction_caps` |
| `resources/views/admin/credit-card-policies/show.blade.php` | hiển thị read-only cap bậc |
| `app/Http/Requests/Admin/CreditCard/{CreateSystemPolicyRequest,StoreSystemPolicyVersionRequest,UpdateSystemPolicyVersionRequest}.php` | validation `tiers.*.transaction_caps` |
| Tests: `ConnectionTest`, `SystemPolicyManagementTest`, `SystemPolicyCloneTest`, `CashbackPipelineTest`, `CashbackCalculatorTest` | +17 |

## 23.2 Migration `000016` + `000017` — bảng `credit_card_policy_tier_category_transaction_caps`

`000016` tạo bảng (phiên bản đầu: con của rule); `000017` là phiên bản CHỐT: đổi cap về con của
**BẬC** (`PolicyTierCategoryTransactionCap → policy_tier_id`). Hiện trạng cuối:

- Cột: `id`, `policy_tier_id` (FK `cc_transaction_caps_tier_id_foreign` → `credit_card_policy_tiers`,
  **ON DELETE CASCADE**), `min_transaction_amount` decimal(16,2) NOT NULL DEFAULT 0,
  `max_transaction_amount` decimal(16,2) NULL, `max_cashback_per_transaction` decimal(16,2) NOT NULL,
  `sort_order` unsignedInteger DEFAULT 0, `created_at`, `updated_at`.
- Index: UNIQUE `cc_transaction_caps_tier_min_unique` (policy_tier_id, min_transaction_amount)
  + index `cc_transaction_caps_tier_sort_index` (policy_tier_id, sort_order).
- Driver-aware: SQLite drop FK theo cột (`['policy_tier_id']`/`['category_rule_id']`), MySQL theo tên
  constraint; `down()` tách 2 bước (bỏ ràng buộc bậc rồi tạo lại ràng buộc rule cũ).

## 23.3 Model + dịch vụ

- `PolicyTierCategoryTransactionCap`: `decimal:2` cast cho 3 số tiền, belongsTo `tier`.
- `TierService::normalizeTransactionCaps` (kiểm + chuẩn hoá, sort **stable** theo `(min, max)`,
  null max = float max để xếp cuối):
  - bắt buộc `min >= 0`, `max >= min` (nếu khác null), bắt buộc `max_cashback`;
  - các khoảng TRONG CÙNG bậc không chồng lấn/trùng mốc: `next.min <= prev.max` ⇒ lỗi 422;
  - valid ví dụ: `[0, 199999.99]` + `[200000, NULL]`.
- `TierService::syncTransactionCaps`: XÓA toàn bộ dòng con + tạo lại (không giữ id, `sort_order` = index+1)
  — gọi TRONG transaction của bậc, mọi caller (create/update/clone).
- `PolicyCloneService::copyChildren`: eager-load `transactionCaps` của bậc, copy từng dòng **id MỚI**;
  `replaceChildren`: sync cap khi payload có `transaction_caps` của bậc. Nguồn không đổi.
- `TierResolverService::transactionCapsForTier()` / `SystemPolicyPresenter` (hydrate cấp BẬC):
  `transaction_caps[].{min_transaction_amount, max_transaction_amount|null, max_cashback_per_transaction}`.

## 23.4 Math calculator (`CashbackCalculator::pickTransactionCap`)

- Calculator nhận cap qua tham số `$transactionCaps` (`calculate(..., array $transactionCaps = [])`)
  — `CashbackRecordService` nạp bằng `TierResolverService::transactionCapsForTier($tier)`.
- Đóng hai đầu: `amount >= min && (max = NULL || amount <= max)`.
- Khớp khoảng → cap khoảng đó; không khớp / bậc không có khoảng → `max_cashback_per_transaction` cố định.
- Caps meta `per_transaction` GIỮ NGUYÊN shape (không thêm cờ `source`) để không vỡ assert exact-array cũ.
- Cap bậc áp cho MỌI rule trong bậc — kể cả fallback (`scope_type = other`, kể cả 0%).

## 23.5 Editor UI

- Checkbox "Giới hạn hoàn tiền theo giá trị giao dịch" cấp BẬC (trong panel bậc, trên lưới rule);
  bật → panel khoảng; +Thêm khoảng / Xoá; mỗi khoảng: Từ (số), Đến (trống = không giới hạn),
  Hoàn tối đa/giao dịch. Input `min total spend` của panel bậc đã GỠ (thuộc §22, không nằm UI editor).
- Payload gửi đi: `tiers[].transaction_caps` đầy đủ khi bật, `[]` khi tắt (toggle OFF ⇒ xoá sạch DB).
- `viewMode`: input disabled, button ẩn.

## 23.6 Validation & vòng đời

| Tình huống | Kết quả |
|---|---|
| Overlap / trùng mốc giữa 2 khoảng | 422 "Các khoảng ... không được chồng lấn hoặc trùng nhau." |
| `max < min` | 422 '"Đến" ... phải lớn hơn hoặc bằng "Từ" ...' |
| Thiếu `min` / `max_cashback` | 422 (message 'phải có giá trị "Từ"' / 'phải có "Hoàn tối đa"') |
| Số âm | 422 'không được âm' |
| Toggle OFF → `tiers[x].transaction_caps: []` | xoá sạch dòng con, bậc giữ nguyên id |

- FormRequest chặn hình dạng (`tiers.*.transaction_caps.*.{min_transaction_amount,max_transaction_amount,
  max_cashback_per_transaction}`) + `min:0`; lỗi nghiệp vụ (overlap, max<min) do `TierService` ném
  `InvalidArgumentException` → controller map 422 — cả hai đều có test.

## 23.7 Backward compat

- Bậc không có `transaction_caps` (hoặc `[]`): `pickTransactionCap` trả cap cố định của rule → hành vi
  cũ 100%.
- Migration `000017` KHÔNG chuyển data (DB hiện tại `transaction_caps = 0` dòng): bảng rỗng nên không đụng
  rule/không tạo cap thừa. `min_total_spend` VẪN ở DB (`tiers.min_total_spend`, §14) — chỉ UI editor gỡ input.
- Show/editor cũ hiển thị đúng dữ liệu hiện có (không cap → dòng "Hoàn tối đa / giao dịch" cấp bậc).

## 23.8 Tests MỚI (17) — cả 5 file đều xanh

| File | Thêm | Tổng |
|---|---|---|
| `CashbackCalculatorTest` (unit) | +6 | **42** |
| `ConnectionTest` | +2 | **10** |
| `SystemPolicyManagementTest` (admin) | +7 | **73** |
| `SystemPolicyCloneTest` | +1 | **42** |
| `CashbackPipelineTest` (E2E) | +1 | **18** |

Nhóm test: 6 kịch bản calculator (khoảng khớp/nhịp, fallback static khi lệch khoảng, band cap THAY THẾ
static, biên đóng hai đầu, null max = không trần, cap bậc áp cả fallback 0%); schema + FK cascade (xóa
BẬC ⇒ xóa cap); api store hydrat ngược; update in-place thay cap giữ nguyên id bậc; toggle OFF xoá sạch;
3 lỗi 422 (overlap / max<min / thiếu field / âm); clone copy cap id mới − nguồn nguyên vẹn; E2E 2 khoảng
áp đúng trên giao dịch thật + KPI period đúng.

## 23.9 Kết quả cuối

| Phạm vi | Kết quả |
|---|---|
| `php artisan test --filter SystemPolicyManagementTest` | **73 passed / 533 assertions** |
| `php artisan test --filter CreditCard` | **438 passed / 2783 assertions** |
| `php artisan test` (toàn suite) | **1573 passed / 3 failed (3 baseline) / 6867 assertions** |
| `vendor/bin/pint --dirty` | passed (không đổi file ngoài phạm vi) |

3 baseline giữ nguyên. Migration `000016` + `000017` ĐÃ chạy trên DB dev thật (`php artisan
credit-card:migrate`) — `000017` batch 5, DONE 359.56ms; verify thủ công (bootstrap php): templates=4,
blueprints=5, tiers=8, rules=36, caps=0 dòng, cột đúng `policy_tier_id`. KHÔNG commit, KHÔNG push — chờ
review.

## 24. Đổi luật xóa phiên bản hệ thống (default là ràng buộc DUY NHẤT)

### 24.1 Luật mới

`PolicyService::deleteSystemVersion()` **chỉ chặn version đang là mặc định**:

| Trường hợp | Trước | Sau |
|---|---|---|
| version là default | chặn | **chặn** (flash "Không thể xóa phiên bản MẶC ĐỊNH. Hãy đặt phiên bản khác làm mặc định trước.") |
| version có thẻ / giao dịch / kỳ sao kê trỏ tới | chặn ("đã được sử dụng") | **cho xóa** — `referenced`/`referenced_label` chỉ còn là thông tin hiển thị |
| version là gốc chuỗi (chuỗi còn version khác) | chặn ("gốc của chuỗi") | **cho xóa** + remap chain |
| xóa xong template mất hết nguồn clone | chặn | **cho xóa** |

Lý do bỏ 3 guard sau: kiến trúc là **System Template → deep clone → User Policy → User Card**, nên
thẻ **không FK trực tiếp** về blueprint; các FK lịch sử (`credit_card_statement_periods.policy_id`,
`credit_card_transactions.policy_version_id`) đều `nullOnDelete`, còn
`credit_card_policy_tiers.policy_id` là `cascadeOnDelete` (bậc/rule/cap của version bị xóa đi theo).

### 24.2 Sửa chain khi xóa version gốc

Xóa V1 (root, `root_policy_id = 1`) trong chuỗi V1←V2←V3:

- Gốc chuỗi = `root_policy_id` (fallback về chính `id` cho bản ghi legacy `root_policy_id = NULL`).
- Version thấp nhất còn lại (V2) làm **gốc mới**, tự trỏ về chính nó (`root_policy_id = 2`).
- Mọi version còn lại của chuỗi được `update(['root_policy_id' => 2])` ⇒ **không còn `root_policy_id`
  nào trỏ tới bản ghi đã xóa** (FK `nullOnDelete` không kịp biến thành NULL rác).
- `version_no` giữ nguyên, nên `createTemplateBlueprint()` vẫn cấp `max(version_no) + 1` cho chain
  đã remap.
- Xóa version KHÔNG phải gốc: chain giữ nguyên, chỉ xóa đúng bản ghi đó.

### 24.3 UI

`show.blade.php` — mọi version không phải mặc định có nút 🗑 Xóa bấm được (form DELETE thật); version
mặc định có nút disabled kèm `title="Không thể xóa phiên bản đang mặc định. Hãy đặt phiên bản khác làm
mặc định trước."`. Không còn hiển thị lý do kiểu "đang được thẻ sử dụng"/"gốc của chuỗi".

### 24.4 Test

| Test | Phủ |
|---|---|
| `referenced_version_is_still_deletable_and_cloned_card_survives` | version có kỳ sao kê trỏ thẳng vẫn xóa được; thẻ đã clone giữ nguyên policy riêng 3%; kỳ sao kê không bị xóa; default không đổi |
| `default_version_cannot_be_deleted_but_chain_root_can_and_chain_is_repaired` | default vẫn chặn; xóa V1 (gốc) ⇒ V2 thành gốc mới, V3 trỏ V2, không có `root_policy_id` treo, tạo version tiếp vẫn ra `version_no = 4` |
| `version_list_shows_set_default_and_delete_actions_and_default_is_the_only_blocked_one` | UI: version mặc định disabled + tooltip mới; version thường có nút xóa; xóa V3 rồi V1 (gốc) đều thành công; xóa version default vẫn lỗi; viewer/member/editor vẫn 403 |

> LƯU Ý: không chạy `vendor/bin/pint` (không `--dirty`) trên toàn repo trong phiên — pint sẽ reformat
> hàng trăm file ngoài phạm vi. Chỉ dùng `pint --dirty` (hoặc liệt kê file đã sửa).