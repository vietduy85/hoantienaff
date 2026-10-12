# CHUẨN HÓA DANH MỤC / COMBO DANH MỤC — MODULE THẺ TÍN DỤNG

> **TRẠNG THÁI: GIAI ĐOẠN 2 ĐÃ HOÀN TẤT (ngày 12/10/2026)** — seeder đã bỏ combo 1-danh-mục; UI xóa guarded đã thêm; **đã áp dụng lên DB live**: chuyển 11 rule in-place + xóa hẳn 5 combo (theo xác nhận Q1/Q2, mục 4). Các phần 1–3 giữ nguyên như báo cáo gốc để truy vết; kết quả thực hiện nằm ở mục 6.

## Giai đoạn 1: Khảo sát + Báo cáo Mapping (KHÔNG đụng dữ liệu live)

- Ngày khảo sát: 12/10/2026 (DB `hoantien_creditcard`, connection `creditcard`).
- Trạng thái git: working tree SẠCH (HEAD `952f529`). Không thay đổi gì tới DB live trong giai đoạn này.
- Mục đích: xác định toàn bộ combo chỉ có **một (1) danh mục**, lập mapping và kế hoạch chuyển đổi an toàn sang tham chiếu trực tiếp danh mục hệ thống, rồi xác nhận với vận hành trước khi triển khai Giai đoạn 2.

---

## 1. Kết quả khảo sát dữ liệu live

### 1.1 Danh mục (credit_card_categories)

- 27 danh mục `system` (id 1–19 + 21–28, riêng id 20 là `user`) + 1 danh mục `user` (`test-1`, owner 5).
- Các danh mục liên quan trực tiếp tới chuẩn hóa:

| id | slug | tên |
|----|------|-----|
| 1 | `am-thuc-an-uong` | Ẩm thực & Ăn uống |
| 8 | `bao-hiem` | Bảo hiểm |
| 10 | `giao-duc-hoc-phi` | Giáo dục & Học phí |
| 12 | `chi-tieu-nuoc-ngoai` | Chi tiêu nước ngoài / Ngoại tệ |
| 13 | `ung-dung-so-dich-vu-giai-tri` | Ứng dụng số & dịch vụ giải trí trực tuyến |

### 1.2 Combo có ĐÚNG 1 danh mục (target chuẩn hóa) — 5 combo

| combo_id | slug | tên | danh mục duy nhất | đang được rule tham chiếu? |
|----------|------|-----|-------------------|----------------------------|
| 5 | `vib-dining` | Ẩm thực — Dining | `am-thuc-an-uong` (#1) | CÓ (4 rule) |
| 6 | `vib-entertainment` | Giải trí — Entertainment | `ung-dung-so-dich-vu-giai-tri` (#13) | CÓ (4 rule) |
| 10 | `vib-one-card-giao-duc` | Giáo dục — Education | `giao-duc-hoc-phi` (#10) | CÓ (1 rule) |
| 11 | `vib-one-card-bao-hiem` | Bảo hiểm — Insurance | `bao-hiem` (#8) | CÓ (2 rule) |
| 12 | `vib-one-card-nuoc-ngoai` | Chi tiêu nước ngoài | `chi-tieu-nuoc-ngoai` (#12) | KHÔNG (0 rule) |

5 combo này đều `scope = system`.

### 1.3 Toàn bộ rule đang tham chiếu 5 combo trên (bản đồ chuyển đổi)

| rule_id | combo | → danh mục | tier | policy (template) | cashback |
|---------|-------|-----------|------|-------------------|----------|
| 256 | 5 `vib-dining` | `am-thuc-an-uong` | 80 (Max Starter tier 1) | 51 `vib-max-card-visa-starter` | 0.500 |
| 262 | 5 | … | 81 (Plus) | 52 `vib-max-card-visa-plus` | 5.000 |
| 268 | 5 | … | 82 (Pro) | 53 `vib-max-card-visa-pro` | 8.000 |
| 274 | 5 | … | 83 (Elite) | 54 `vib-max-card-visa-elite` | 10.000 |
| 257 | 6 `vib-entertainment` | `ung-dung-so-dich-vu-giai-tri` | 80 | 51 | 0.500 |
| 263 | 6 | … | 81 | 52 | 5.000 |
| 269 | 6 | … | 82 | 53 | 8.000 |
| 275 | 6 | … | 83 | 54 | 10.000 |
| 284 | 10 `vib-one-card-giao-duc` | `giao-duc-hoc-phi` | 86 (Signature tier 1) | 57 `vib-one-card-visa-signature` | 9.000 |
| 281 | 11 `vib-one-card-bao-hiem` | `bao-hiem` | 85 (Platinum tier 1) | 56 `vib-one-card-visa-platinum` | 6.000 |
| 285 | 11 | … | 86 (Signature tier 1) | 57 | 9.000 |

Tổng cộng **11 rule** cần chuyển: 8 của VIB Max Card Visa (4 blueprint) + 3 của VIB One Card (2 blueprint). Tất cả 11 rule đều:
- `scope_type = category`, `category_id = NULL`, `combo_id` có giá trị;
- `spend_from = 0`, `spend_to = NULL`, không cap/min/band;
- `counts_toward_tier_cap = 1`, `is_quota_category = 0`;
- thuộc policy `status = active`, `is_locked = 0` (có thể sửa).

### 1.4 Xung đột / điểm cần xác nhận

- **KHÔNG có xung đột trùng-danh-mục**: trong từng bậc có rule combo 1-danh-mục nói trên, danh mục member KHÔNG có rule danh mục trực tiếp khác (`same_tier_direct_category_conflicts` = rỗng). Vì vậy chuyển `combo rule → category rule` KHÔNG bị từ chối bởi `assertNoDuplicateCategory*` và KHÔNG làm thay đổi mức hoàn (engine ưu tiên CATEGORY > COMBO > FALLBACK, chuyển đổi là tương đương hành vi).
- **VIB One Card (§ yêu cầu mở rộng)**: Bảo hiểm (#8) đang là DMCT đơn được tham chiếu trực tiếp; Giáo dục (#10), Chi tiêu nước ngoài (#12) tương tự. Chuẩn hóa đúng tinh thần bài toán: không tạo combo đại diện cho 1 danh mục.
- **VIB Max Card (§ bảo toàn)**: `vib-dining` + `vib-entertainment` thuộc nhóm "5 combo" được yêu cầu bảo toàn ý nghĩa. Nhưng chúng hiện có đúng 1 danh mục (trái nguyên tắc chung). → **CÂU HỎI XÁC NHẬN #1** (xem mục 4).

### 1.5 Các tham chiếu tài sản khác

- **Giao dịch**: KHÔNG giao dịch nào trỏ tới 11 rule combo nói trên. Giao dịch trong danh mục member: cat #1: 5, cat #8: 4, cat #13: 1 — snapshot `category_id`, không đổi khi chuyển.
- **MCC mapping**: `credit_card_policy_mcc_mappings` không có dòng nào trỏ tới 11 rule hay các danh mục member (cả hai truy vấn đều rỗng). FK `category_id`/`policy_tier_category_id` trong bảng này là `nullOnDelete` — không phụ thuộc.
- **Spend qualification**: danh mục #8, #10, #12 bị liệt kê trong excluded-categories (template condition #2; policy conditions #1/#2/#3) — FK `RESTRICT`. → các danh mục này KHÔNG thể xóa cứng (liên quan Mục tiêu A, mục 3).
- **Combo khác nhiều danh mục — KHÔNG chạm tới**:
  - `#1 san-thuong-mai-dien-tu` (shopee/tiki/lazada/tiktok) — Shinhan Supreme blueprint, rule 245 @12% (tier 2). Ghi nhận: cùng có danh mục system `san-thuong-mai-dien-tu` (#26) được dùng trực tiếp @12% tại tier 3 của Shinhan. Ngoài phạm vi chuẩn hóa.
  - `#2 cham-soc-sac-dep-y-te-thoi-trang` (3 member) — VPBank Lady Mastercard blueprint #7 + clones #21/#28.
  - `#3 vib-shopping` (6), `#4 vib-travel` (3), `#7 vib-online` (6) — Max Card 4 blueprint.
  - `#8 vib-one-card-mua-sam` (6), `#9 vib-one-card-du-lich` (2) — One Card (du-lich hiện không có rule trên live, chỉ nằm trong kho DMCT).
- **Combo user**: không có combo scope `user` nào trên live.

### 1.6 Khảo sát giao diện / backend hiện có (Mục tiêu A)

| Màn / endpoint | Sẵn có |
|----------------|--------|
| Danh mục hệ thống (admin): `system-categories` index/store/update/reorder | CÓ — **THIẾU destroy và ẩn/khôi phục** |
| Danh mục riêng user (`CategoryController::destroy`) | CÓ — hard-delete nếu chưa dùng, ẩn nếu đã dùng |
| Combo hệ thống (admin): `system-combos` index/store/update | CÓ — **THIẾU destroy**; update đã nhận `category_ids` nên việc **gỡ danh mục khỏi combo** đã có sẵn (ràng buộc ≥1 member + `assertMembershipKeepsTierRulesValid`) |
| Xóa rule trong bậc (`CategoryRuleController`/`CategoryRuleService::delete`) | CÓ — chặn fallback; chặn rule thuộc version locked/superseded |

---

## 2. Kế hoạch chuyển đổi dữ liệu (Giai đoạn 2 — đề xuất, CHỜ xác nhận)

> Trạng thái thực hiện: **tất cả các bước đã hoàn tất và áp dụng lên live** (xem mục 6).

### 2.1 Bước 1 — Convert rule (11 rule, in-place)

Cho từng rule trong danh sách 1.3: đặt `category_id = <member>`, `combo_id = NULL`, giữ nguyên mọi cột còn lại (percent, countries, cap, sort_order…) rồi `normaliseRuleOrder(tier)`.

- Không xóa rồi tạo lại rule → id rule, snapshot giao dịch, lịch sử không đổi.
- Không đụng policy khác (Shinhan, VPBank, v.v.).

### 2.2 Bước 2 — Xử lý 5 combo sau khi hết tham chiếu

Sau bước 1: combo #5, #6, #10, #11 hết rule; #12 vốn đã không có rule. Lúc đó chọn chính sách (**CÂU HỎI #2**):
- Phương án A (khuyến nghị): **deactivate** (`is_active = false`) — giữ dữ liệu an toàn, đúng tinh thần "đã dùng ⇒ ẩn", không chặn history.
- Phương án B: xóa hẳn (được phép sau khi 0 tham chiếu — FK `combo_id` RESTRICT sẽ cho xóa), gọn số liệu.

### 2.3 Bước 3 — Sửa seeder (`CreditCardSeeder`) để không tạo combo 1-danh-mục

- `seedVibCombos`: bỏ `vib-dining`, `vib-entertainment`; các blueprint Max Card thay rule combo bằng rule danh mục trực tiếp (`am-thuc-an-uong`, `ung-dung-so-dich-vu-giai-tri`) với CHÍNH tỷ lệ cũ.
- `seedVibOneCardCombos`: bỏ `vib-one-card-giao-duc`, `vib-one-card-bao-hiem`, `vib-one-card-nuoc-ngoai`; `default_combos` của Platinum/Signature chuyển thành danh mục trực tiếp (Bảo hiểm / Giáo dục) cùng tỷ lệ cũ.
- Giữ nguyên 2 combo mua-sắm nhiều-danh-mục + du-lịch (2 member), max shopping/travel/online.
- Idempotent, không xóa-rồi-tạo lại gì.

### 2.4 Cam kết không đổi hành vi

- Tỷ lệ/cap/min/spend/counts/quota giữ nguyên (chỉ đổi target của 11 rule).
- Engine: CATEGORY > COMBO > FALLBACK ⇒ sau chuyển, giao dịch danh mục member nhận đúng tỷ lệ trước đây.
- Snapshot giao dịch, kỳ finalized, lịch sử version, spend-qual, mcc mapping: không đổi; crawl lại kiểm chứng bằng báo cáo trước/sau + test regression.

---

## 3. Mục tiêu A — bổ sung UI/backend (Giai đoạn 2)

- **Combo**: thêm nút **"Xóa danh mục khỏi combo"** (đã có sẵn qua edit `category_ids`; thêm confirm rõ ràng + chặn khi còn 0 member, thông báo khi danh mục còn dùng ở nơi khác). Thêm **xoá combo** với guard: combo còn rule tham chiếu ⇒ từ chối kèm thông báo; combo hết ref ⇒ cho deactivate/xóa theo chính sách đã chốt.
- **Danh mục hệ thống**: bổ sung **destroy có guard** (gesture: đang được rule/combo/spend-qual/mcc dùng ⇒ 422 kèm lý do cụ thể; chưa dùng ⇒ xóa) — tương tự `CategoryService::deleteUserCategory` nhưng cho scope system, có hàng rào cột FK RESTRICT ở `credit_card_policy_tier_categories`, `credit_card_category_combo_items`, `credit_card_spend_qualification_*`.
- Giữ nguyên thứ tự ưu tiên hiển thị (category → combo → fallback).

---

## 4. CÂU HỎI CẦN VẬN HÀNH XÁC NHẬN (trước khi chạm live)

**Q1. VIB Max Card `vib-dining` & `vib-entertainment`**: chuyển thành rule danh mục trực tiếp (chuẩn theo nguyên tắc chung, hành vi không đổi — khuyến nghị) hay GIỮ combo như cấu trúc "5 combo" của Max Card?
→ **ĐÃ XÁC NHẬN (12/10): chuyển thành rule danh mục trực tiếp.**

**Q2. Sau khi chuyển đổi 5 combo về 0 tham chiếu**: deactivate (ẩn, giữ — khuyến nghị) hay xóa hẳn?
→ **ĐÃ XÁC NHẬN (12/10): xóa hẳn (guarded — FK `combo_id` RESTRICT đảm bảo chỉ xóa khi 0 tham chiếu).**

**Q3. Xác nhận phạm vi Giai đoạn 2**: seed không còn combo 1-danh-mục · convert 11 rule · bổ sung UI xóa danh mục khỏi combo + xóa combo + xóa danh mục hệ thống có guard · test regression đầy đủ (danh sách test mục 5 bên dưới).
→ **ĐÃ XÁC NHẬN: đúng phạm vi; đã hoàn tất toàn bộ.**

## 5. Kế hoạch test (Giai đoạn 2)

Trạng thái: **tất cả đã chạy và pass** (`php artisan test tests/Feature/CreditCard` → 1334 pass; `vendor\bin\pint --test` → pass; `git diff --check` → sạch).

1. Chuyển combo rule 1-danh-mục → rule danh mục: target, tỷ lệ, cờ, thứ tự đúng. ✅ add `deleting_a_system_category_referenced_by_a_rule_is_rejected` v.v. trong `SystemCategoryAdminTest` / `SystemCategoryComboTest`.
2. Chặn xóa combo còn rule; cho phép deactivate/xóa combo hết ref. ✅
3. Chặn xóa danh mục còn tham chiếu (rule/combo/spend-qual/mcc); cho phép xóa khi không dùng. ✅
4. Seeder: KHÔNG tạo combo 1-danh-mục; One Card defaults tương đương sau khi normalize; idempotent. ✅ (`CreditCardSeeder`, `RuleOrderingRegressionTest`, `VibMaxCardVisaPolicyTest`, `VibOneCardVisaPolicyTest`)
5. Max Card: 4 blueprint giữ tỷ lệ 0.5/5/8/10 cho từng nhóm; Shinhan/VPBank không đổi. ✅
6. Engine/cashback: giao dịch danh mục member vẫn nhận đúng mức hoàn; fallback cuối; snapshot/history/kỳ finalized không đổi; không ảnh hưởng affiliate/MCC. ✅

## 6. KẾT QUẢ TRIỂN KHAI GIAI ĐOẠN 2 (đã áp dụng lên DB live)

### 6.1 Convert 11 rule (in-place, chạy ngày 12/10/2026 qua `CategoryRuleService::update`)

| rule_id | trước (combo) | sau (danh mục trực tiếp) | tỷ lệ | tier | policy | sort_order |
|---------|----------------|---------------------------|-------|------|--------|------------|
| 256 | #5 `vib-dining` | #1 `am-thuc-an-uong` | 0.500 | 80 | VIB Max Starter | 1 |
| 257 | #6 `vib-entertainment` | #13 `ung-dung-so-dich-vu-giai-tri` | 0.500 | 80 | VIB Max Starter | 2 |
| 262 | #5 | #1 | 5.000 | 81 | VIB Max Plus | 1 |
| 263 | #6 | #13 | 5.000 | 81 | VIB Max Plus | 2 |
| 268 | #5 | #1 | 8.000 | 82 | VIB Max Pro | 1 |
| 269 | #6 | #13 | 8.000 | 82 | VIB Max Pro | 2 |
| 274 | #5 | #1 | 10.000 | 83 | VIB Max Elite | 1 |
| 275 | #6 | #13 | 10.000 | 83 | VIB Max Elite | 2 |
| 281 | #11 `vib-one-card-bao-hiem` | #8 `bao-hiem` | 6.000 | 85 | One Card Platinum | 1 |
| 284 | #10 `vib-one-card-giao-duc` | #10 `giao-duc-hoc-phi` | 9.000 | 86 | One Card Signature | 1 |
| 285 | #11 | #8 | 9.000 | 86 | One Card Signature | 2 |

- `id`, `cashback_percent`, cờ, spend band, cap giữ NGUYÊN — chỉ đổi `category_id` + `combo_id = NULL`.
- `sort_order` được `normaliseRuleOrder` xếp lại theo nhóm hiển thị (category → combo → fallback) trong từng bậc.
- Kiểm chứng sau: 0 rule trỏ 5 combo mồ; 77 rule danh mục trực tiếp cho 5 target category.

### 6.2 Xóa hẳn 5 combo (guarded)

- Combos **#5, #6, #10, #11, #12** đã **xóa hẳn** khỏi `credit_card_category_combos` cùng toàn bộ item (`credit_card_category_combo_items`, FK CASCADE) sau khi xác nhận `rule_refs = 0`.
- Kiểm chứng: rows còn lại = 0; combo system còn **7** (trước 12): #1 `san-thuong-mai-dien-tu`, #2 `cham-soc-sac-dep-y-te-thoi-trang`, #3 `vib-shopping`, #4 `vib-travel`, #7 `vib-online`, #8 `vib-one-card-mua-sam`, #9 `vib-one-card-du-lich` — tất cả ≥2 danh mục, KHÔNG bị chạm tới.
- Danh mục system #1/#8/#10/#12/#13 vẫn giữ nguyên (không xóa) vì bị spend-qual excluded FK `RESTRICT` tham chiếu và là mục tiêu của các rule trực tiếp.

### 6.3 Thay đổi mã nguồn (đã commit-ready, chưa commit)

- `database/seeders/CreditCardSeeder.php`: gỡ 5 combo 1-danh-mục; blueprint Max Card dùng `$targets` hỗn hợp (3 combo + 2 danh mục trực tiếp); `seedVibOneCardTemplates` nhận `(banks, categories, combos)`.
- `SystemCategoryService::deleteSystemCategory` + `CategoryComboService::deleteSystemCombo` (xóa guarded, message tiếng Việt liệt kê tham chiếu).
- `SystemCategoryAdminController::destroy` + `SystemComboAdminController::destroy` (404 nếu không system; 422 kèm lý do khi còn tham chiếu).
- `routes/web.php`: 2 route `DELETE` `admin.credit-card.system-categories/{category}` / `admin.credit-card.system-combos/{combo}` (middleware `credit-cards.manage`).
- Views `system-categories/index.blade.php` + `system-combos/index.blade.php`: nút xóa + xác nhận + flash.
- Tests: `SystemCategoryAdminTest` (+4), `SystemCategoryComboTest` (+3), route tests cập nhật; chạy `php artisan test tests/Feature/CreditCard` → **1334 pass**; full suite còn 4 fail **ngoài phạm vi** (`Auth\RegistrationTest`, `CreditCardModuleTest` snapshot route cũ có thêm `credit-cards.api.policies.history` từ task trước, `PromotionNewsHomepageTest`, `ShopeeFoodOrderSyncServiceTest`).

---

*Báo cáo này CHỈ khảo sát và đề xuất cho Giai đoạn 1. Giai đoạn 2 (seeder, UI xóa guarded, convert 11 rule, xóa 5 combo) đã được vận hành xác nhận và **đã ghi vào DB live** ngày 12/10/2026 như mô tả ở mục 6.*