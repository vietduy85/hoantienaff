# CREDIT CARD VIB MAX CARD VISA REPORT (§X)

> Giai đoạn "Chính sách hệ thống VIB Max Card Visa" — 4 chính sách hệ thống (Starter / Plus / Pro /
> Elite) + 5 nhóm danh mục ưu đãi (combo) + ràng buộc bank/sản phẩm + lịch sử chính sách của thẻ.
> Ngày hoàn tất: 2026-10-11. Nhánh: `main`. **Chưa commit/push** (đúng quy ước các phase trước).

---

## 1. Tổng quan

Triển khai gói **VIB Max Card Visa** làm 4 chính sách hệ thống (`scope=system`, `owner_user_id=0`,
`is_builtin=1`), mỗi chính sách 1 bậc duy nhất với 5 rule ưu đãi nhóm danh mục + 1 rule nền
(0,1%) — mọi rule `counts_toward_tier_cap=1`. Template chỉ chọn được cho thẻ VIB có tên chứa
"max card visa" (`applicable_bank_id` + `applicable_product_match`). Mỗi thẻ có **lịch sử chính
sách** (nghĩa `previous_name`, `recorded_by_user_id`) hiển thị trong form Sửa thẻ.

| Hạng mục | Kết quả |
|---|---|
| Migration mới | 2 (`2026_10_13_000001` ràng buộc bank/sản phẩm trên template, `2026_10_13_000002` trường lịch sử trên policy) |
| Template hệ thống mới | 4 (VIB Max Card Visa Starter/Plus/Pro/Elite) |
| Combo hệ thống mới | 5 (`vib-shopping/travel/dining/entertainment/online`) |
| Guard applicability | `PolicyService::cloneTemplate` — điểm chung duy nhất, mọi đường clone |
| API mới | `GET /thetindung/api/the/{card}/lich-su-chinh-sach` (`policies.history`) |
| UI | cảnh báo "template không áp dụng cho thẻ này" + section lịch sử chính sách |
| Test mới | `VibMaxCardVisaPolicyTest`: **15 test / 423 assertions** |
| Toàn suite module | **1296 passed / 0 failed / 8498 assertions** |

---

## 2. Thông số & biểu diễn

| Hạng | Slug | Nhóm ưu đãi | Fallback nền | Trần/kỳ (VND) |
|---|---|---|---|---|
| Starter | `vib-max-card-visa-starter` | 0,500% | 0,100% | 500.000 |
| Plus | `vib-max-card-visa-plus` | 5,000% | 0,100% | 500.000 |
| Pro | `vib-max-card-visa-pro` | 8,000% | 0,100% | 1.000.000 |
| Elite | `vib-max-card-visa-elite` | 10,000% | 0,100% | 1.500.000 |

5 nhóm ưu đãi (combo, `scope=system`):

- `vib-shopping` = **Mua sắm — Shopping**: siêu-thi-tien-loi, thoi-trang, cua-hang-tien-loi,
  dien-may, nha-sach, cham-soc-sac-dep (6 danh mục).
- `vib-travel` = **Du lịch — Travel**: ve-may-bay-du-lich, khach-san-luu-tru, chi-tieu-nuoc-ngoai (3).
- `vib-dining` = **Ẩm thực — Dining**: am-thuc-an-uong (1).
- `vib-entertainment` = **Giải trí — Entertainment**: ung-dung-so-dich-vu-giai-tri (1).
- `vib-online` = **Giao dịch trực tuyến — Online Transactions**: mua-sam-truc-tuyen,
  san-thuong-mai-dien-tu, shopee, tiki, lazada, tiktok (6).

Biểu diễn nghiệp vụ:

- CẢ 5 nhóm đều set ở tỷ lệ ưu đãi cao; danh mục ngoài 5 nhóm rơi vào rule nền `scope_type=other`
  0,100% ("📦 Các danh mục còn lại"). Mọi rule `counts_toward_tier_cap=true`.
- 1 bậc/plan (min 0, max null), `max_cashback_per_period` = trần plan. Policy cap vô hạn
  (`max_cashback_total_per_period=NULL`), `rounding_mode='round'`, `min_total_spend=0`.
- `is_builtin=1`, `is_active=1`, `sort_order` 30–33 (sau Shinhan 20). Không có
  spend qualification / tier cap riêng / cap giao dịch — nền 0,1% áp cho cả 5 nhóm khi vượt trần.
- `recorded_by_user_id`: blueprint để NULL; policy của THẺ (= `user_card_id`) luôn ghi
  **chủ thẻ** (`user_card->user_id`) tại `attachTemplateToCard`, `createNextVersion`, `createFromScratch`.

---

## 3. Kiến trúc

```
PolicyTemplate (scope=system, owner_user_id=0, is_builtin=1)
   ├─ applicable_bank_id  = bank VIB (id)
   ├─ applicable_product_match = 'max card visa'   ← định danh sản phẩm bằng bank + keyword tên thẻ
   └─ defaultBlueprint() = Policy (user_card_id NULL) v1
         └─ tier: min 0 / max null / cap trần plan
              ├─ 5 rule category (combo_id) @ tỷ lệ ưu đãi, counts_cap=1
              └─ 1 rule other (fallback) @ 0,100%, counts_cap=1
UserCard policy (user_card_id NOT NULL): bản SAO v1 — CÔ LẬP
   └─ recorded_by_user_id = user chủ thẻ (lịch sử đổi chính sách)
```

- **Applicability** kiểm ở `PolicyService::cloneTemplate` (line 771): nếu template
  `restrictedToBankOrProduct()` (có `applicable_bank_id` HOẶC `applicable_product_match`) mà
  `applicableToCard()` false → ném `InvalidArgumentException` (→ 422). Đây là **điểm chung duy
  nhất**: `CardPolicySaveService` (form Thẻ), `versions.store`, `cloneUserTemplate`... đều đi qua.
  UI lọc sẵn (`templateUsable`) nhưng API không dựa vào client.
- **Dedupe re-save**: `CardPolicySaveService::applyPolicySelection` trả về sớm (không tạo version
  mới / không thêm dòng lịch sử) khi giữ nguyên template + cùng `effective_from`, không kèm
  tiers / min_total_spend / spendQualification.
- **Lịch sử chính sách** đọc từ `PolicyVersion::forCard` sắp MỚI → CŨ; `previous_name` của dòng i
  là tên dòng CŨ HƠN kế tiếp (chính sách chạy trước đó); `is_current` so với `currentPolicy`.
- **Recalc**: đổi policy cho thẻ → `attachTemplateToCard` re-attach + recalc kỳ `open`; kỳ đã
  `finalized` (status chốt) KHÔNG bị đụng.

---

## 4. Routes

| Method | URI | Tên | Mô tả |
|---|---|---|---|
| GET | `/thetindung/api/the/{userCard}/lich-su-chinh-sach` | `policies.history` | Danh sách version chính sách của thẻ (json) |

---

## 5. Files

| File | Nội dung |
|---|---|
| `database/migrations/creditcard/2026_10_13_000001_*` | `applicable_bank_id`, `applicable_product_match` trên `policy_templates` |
| `database/migrations/creditcard/2026_10_13_000002_*` | `recorded_by_user_id`, index `(user_card_id, created_at)` trên `policies` |
| `app/Models/CreditCard/PolicyTemplate.php` | `restrictedToBankOrProduct()`, `applicableToCard()` |
| `app/Models/CreditCard/Policy.php` | cast + fillable `recorded_by_user_id` |
| `app/Services/CreditCard/PolicyService.php` | guard applicability trong `cloneTemplate`; `createFromScratch` ghi `recorded_by` |
| `app/Services/CreditCard/PolicyCloneService.php` | ghi `recorded_by_user_id` ở `attachTemplateToCard`/`createNextVersion` |
| `app/Services/CreditCard/CardPolicySaveService.php` | dedupe re-save (giữ nguyên template) |
| `app/Http/Controllers/CreditCard/PolicyController.php` | `history()` (logic `previous_name` đúng chiều MỚI→CŨ) |
| `database/seeders/CreditCardSeeder.php` | `seedVibCombos()`, `seedVibMaxCardVisaTemplates()` (idempotent, bỏ qua slug thiếu) |
| `resources/views/credit-card/manage.blade.php` | payload template + cảnh báo applicability + section lịch sử |
| `tests/Feature/CreditCard/VibMaxCardVisaPolicyTest.php` | 15 test — seed/applicability/lịch sử/engine |

Seeder combo CHỈ THÊM membership, không gỡ (admin có thể đã thêm danh mục mở rộng). Chạy lại
idempotent: template hệ thống có blueprint rồi thì skip.

---

## 6. Test

`VibMaxCardVisaPolicyTest` (15 tests / 423 assertions):

- **A. Cấu trúc seeder**: 5 combo tách rời nhau trên 19 danh mục hệ thống; 4 template đúng thông
  số; không mức tỷ lệ ẩn ngoài `{0.1, 0.5, 5, 8, 10}`; idempotent; không tạo thẻ/policy user.
- **B. Applicability + người ghi**: thẻ VIB "max card visa" chọn được đúng hạng; thẻ VIB tên khác
  bị 422; thẻ bank khác bị 422; `recorded_by_user_id` của thẻ = chủ thẻ; API trả warning UI.
- **C. Lịch sử + dedupe**: switch template thêm dòng lịch sử đúng `previous_name`; re-save giữ
  nguyên template KHÔNG tạo version/dòng; history đúng định dạng.
- **D. Engine**: đúng tỷ lệ nhóm/ngoài nhóm; trần toàn bậc Elite kẹp đúng; switch Starter→Plus
  recalc kỳ open (5%) nhưng kỳ finalized giữ nguyên version + số + snapshot.

Toàn suite module: **1296 passed / 0 failed / 8498 assertions** (tại phase VIB); sau đợt "rà soát
thứ tự quy tắc" (xem §10): **1393 tests — 1392 passed / 1 failed**. Test failed duy nhất
`CreditCardModuleTest::credit_card_routes_do_not_override_existing_routes` thuộc workstream MCC
song song (route `policies.history` thêm ở AFF mà danh sách route "đã chốt" chưa cập nhật) —
không thuộc phạm vi đợt này. `git diff --check` sạch. Không BOM.

---

## 7. Xác minh DB LIVE (`hoantien_creditcard`)

- Migration 2 file `2026_10_13_*` đã `Ran` (batch 18).
- `db:seed --class=Database\Seeders\CreditCardSeeder --force` chạy lại an toàn.
- Template #15–18 VIB system, `bank=6` (VIB), `match='max card visa'`, `builtin=1`.
- Combo #3–7 system: members 6/3/1/1/6.
- Root policy của mỗi hạng: 6 rule (5 combo @ tỷ lệ ưu đãi + 1 other 0,1%), counts_cap=1,
  tier cap 500k/500k/1M/1.5M, rounding round, min 0.

---

## 8. Hạng mục mở / gap (§X.7)

| Mục | Trạng thái |
|---|---|
| Per-card xác định **nhóm ưu tiên** (Starter/Plus/Pro/Elite theo hạng thẻ) | Chưa hỗ trợ — chọn template thủ công; đổi qua form là clone version mới, không tự suy diễn từ hạng |
| Xác nhận **Cap1/Cap2** giao dịch / danh mục từ tài liệu gốc | Cấu hình thành NULL; nếu tài liệu yêu cầu cần bổ sung |
| **Special cap** (VD 10.000đ/kỳ áp cho tất cả) | Chưa có cơ chế — hiện chỉ có cap tier/rule; báo cáo theo kỳ hiển thị trần tổng |
| Định danh sản phẩm = bank + keyword tên thẻ | Đã làm (bank id + `applicable_product_match` như "max card visa"); sản phẩm có tên khác cần thêm match riêng |
| Admin chọn ưu tiên "mọi sàn" cho combo | Không làm — mỗi sàn vẫn là danh mục riêng (giữ nguyên §23 module Danh mục) |

**Ảnh hưởng cạnh tranh:** combo hệ thống tham chiếu danh mục system nên admin xoá danh mục đang
được combo dùng sẽ bị FK chặn (phải gỡ khỏi combo trước) — là hành vi sản phẩm mong muốn;
test `SystemCategoryAdminTest::seeder_recreates_a_missing_canonical_category...` đã đổi sang dùng
danh mục không bị combo tham chiếu (`xang-dau`).

---

## 9. Ghi chú vận hành

- Seed DB live: `php artisan db:seed --class="Database\Seeders\CreditCardSeeder" --force`.
- Không dùng `credit-card:migrate --seed`.
- Chưa commit/push theo đúng quy ước; khi chuyển production chạy `credit-card:migrate`.

---

## 10. Rà soát thứ tự quy tắc hoàn tiền (Shinhan Supreme + VIB Max Card Visa)

### 10.1 Sự cố

Fallback "📦 Các danh mục còn lại" (`scope_type=other`) bị hiển thị CHÈN GIỮA danh sách rule trong
bậc trên DB live: Shinhan (`#50`) bậc 78/79 để `fb@2`; VIB (`#51–54`) để `combo@0,fb@1,combo@1..4`.
Nguyên nhân: seeder tạo fallback TRƯỚC (`sort_order=1`) rồi mới tạo rule khác (`sort 0..n`); editor
`versionConfig()` đánh số `sort_order` theo vị trí hiển thị nên vòng lỗi tự duy trì; `copyChildren`
clone giữ nguyên `sort_order` nên version/clone thừa hưởng lỗi. Engine CHỌN rule theo (category →
combo → fallback) **không** theo `sort_order` nên kết quả hoàn tiền KHÔNG đổi.

### 10.2 Chuẩn hoá

- **Thứ tự chuẩn** trong một bậc: danh mục cụ thể → combo danh mục → fallback (luôn cuối); trong
  từng nhóm giữ `sort_order` rồi `id`. Gán lại `sort_order = 1..n`.
- `PolicyTierCategory::displayGroup()` + `scopeOrdered()`: nhóm dùng `CASE scope_type=other→2,
  combo_id≠NULL→1, còn lại 0`; `reorder()` trước để khử `orderBy('sort_order')` mặc định của quan hệ
  `tierCategoryRules()`. Áp cho `listFor()` (admin) và presenter `SystemPolicyPresenter::tiers()`.
- `CategoryRuleService::normaliseRuleOrder(PolicyTier): int` — idempotent, CHỈ viết `sort_order`;
  wiring vào mọi đường ghi: `create/update/delete`, `ensureSingleFallback`, `cloneRuleTo`,
  `PolicyCloneService::copyChildren` (mỗi bậc mới), seeder Shinhan `seedShinhanSupremeTemplate` +
  VIB `seedVibMaxCardVisaTemplates` (cuối template). Lưu ý Laravel: sortBy NHIỀU closure bị
  `sortByMany` gọi như comparator `($a,$b)` → sinh thứ tự sai; dùng MỘT closure trả mảng khóa.
- **Lệnh sửa dữ liệu live:** `php artisan credit-card:normalise-rule-order` (`--all` /
  `--templates=slug,slug`; mặc định 5 template phạm vi + MỌI policy có `template_id` = blueprint +
  bản gắn thẻ + mọi version). Chạy trên `hoantien_creditcard`.

### 10.3 Xác minh

| Mục | Kết quả |
|---|---|
| Test mới | `RuleOrderingRegressionTest`: **15 test / 134 assertions** — canon seeder (Shinhan 3 bậc + VIB 4 hạng), idempotent seeder, `ordered()`/presenter chống dữ liệu sai, `ensureSingleFallback` bỏ qua payload `sort_order` giữa, update→fallback đứng cuối, clone/version giữ nguyên tỷ lệ + thứ tự chuẩn (kể cả nguồn xáo), engine KHÔNG đổi khi xáo `sort_order`, lệnh đúng phạm vi + chỉ sửa `sort_order` + idempotent |
| DB live | 35 rule rà soát, **33 rule đổi `sort_order`** chạy lần 1; lần 2 = **0** (idempotent). Sau đó: Shinhan `#50` → `cat,cat,cat,+combo+fb @1..5` / `cat×4,fb @1..5`; VIB `#51–54` → `combo×5,fb @1..6`. Fallback cuối mọi nơi |
| Chỉ đổi `sort_order` | `normaliseRuleOrder` chỉ `forceFill(['sort_order'])`; snapshot trước/sau giữ nguyên id/scope/combo/category/percent/cap/cờ (kiểm bằng assertion trong test) |

Không có thẻ nào đang gắn 5 template này trên DB live (chỉ blueprint), nên không có kỳ mở /
transaction bị ảnh hưởng engine.