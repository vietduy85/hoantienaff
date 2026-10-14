# Báo cáo kiểm toán & sửa lỗi blueprint (note + trạng thái) cho các template thẻ tín dụng

- **Ngày thực hiện:** 2026-10-14
- **Phạm vi:** 8 template hệ thống có `note` blueprint nhắc sai tên thẻ nguồn (đợt 2, sau khi đã sửa `MSB Online Visa` ở migration `2026_10_14_000010`).
- **Nguyên nhân gốc:** luồng sao chép chính sách hệ thống `PolicyCloneService::createSystemPolicyFromEditor()` chép **nguyên văn** `note` của blueprint nguồn (`PolicyCloneService.php:527`), trong khi `createTemplateBlueprint()` (`PolicyCloneService.php:358`) sinh note theo tên template **đích**.
- **Nguyên tắc áp dụng:** chỉ sửa khi có bằng chứng xác minh; không sửa hàng loạt theo phỏng đoán; giữ nguyên mọi quyền lợi hoàn tiền hợp lệ.

> ⚠️ Lưu ý về danh sách: yêu cầu nêu 8 template `#5, #6, #7, #8, #9, #10, #11, #12`. Báo cáo điều tra MSB trước đó (§4a) thực tế liệt kê `#5 … #13` (9 dòng, gồm `#9`). **Đối chiếu DB thật** cho thấy sau khi loại `#9` (đã sửa) còn **đúng 8 template** sai note: **#5, #6, #7, #8, #10, #11, #12, #13**. Danh sách này được dùng làm cơ sở (không suy diễn từ con số trong báo cáo).

---

## 1. Danh sách 8 template đã kiểm toán (loại trừ MSB Online Visa `#9`)

| # | Template (chủ sở hữu) | slug | Blueprint | Trạng thái | Note trước ✓/✗ | Note nhắc tới | Template nguồn (suy từ note) | `max_cashback_total_per_period` |
|---|---|---|---|---|---|---|---|---|
| 5 | VPBank Lady Mastercard | `vpbank-lady-mastercard` | id=7 (v1) | active | ✗ | "VPBank StepUp Mastercard" | #4 VPBank StepUp Mastercard | NULL |
| 6 | VPBank Z Card JCB | `vpbank-z-card-jcb` | id=8 (v1) | superseded | ✗ | "MB Ultimate JCB" | #3 MB Ultimate JCB | 800000.00 |
| 7 | VPBank  Super Shopee Platinum Visa | `vpbank-super-shopee-platinum-visa` | id=9 (v1) | superseded | ✗ | "MB Ultimate JCB" | #3 MB Ultimate JCB | 800000.00 |
| 8 | MSB  mDigi Mastercard | `msb-mdigi-mastercard` | id=10 (v1) | active | ✗ | "Sacombank Uniq Visa" | #2 Sacombank Uniq Visa | 300000.00 |
| 10 | VPBank  Cake Freedom Visa | `vpbank-cake-freedom-visa` | id=12 (v1) | superseded | ✗ | "MB Ultimate JCB" | #3 MB Ultimate JCB | 800000.00 |
| 11 | LPBank Ultimate JCB | `lpbank-ultimate-jcb` | id=13 (v1) | superseded | ✗ | "MB Ultimate JCB" | #3 MB Ultimate JCB | 800000.00 |
| 12 | BIDV Cashback Online Visa | `bidv-cashback-online-visa` | id=14 (v1) | active | ✗ | "VPBank StepUp Mastercard" | #4 VPBank StepUp Mastercard | NULL |
| 13 | SeABank SeA-Easy Visa | `seabank-sea-easy-visa` | id=15 (v1) | superseded | ✗ | "MB Ultimate JCB" | #3 MB Ultimate JCB | 800000.00 |

Ghi chú:
- `default_version_id` của mỗi template đều trỏ đúng vào blueprint duy nhất này.
- Mỗi template chỉ có **một** blueprint (version 1). Không có version nào khác.
- Template nguồn (#2 Sacombank Uniq, #3 MB Ultimate JCB, #4 VPBank StepUp) có note **đúng** (nhắc chính tên mình) nên **không bị đụng**.

---

## 2. Bảng trước → sau

Chỉ trường `note` thay đổi. `status`, `max_cashback_total_per_period`, `min_total_spend`, `effective_from/to`, bậc và rule **giữ nguyên**.

| # | slug | note TRƯỚC | note SAU |
|---|---|---|---|
| 5 | `vpbank-lady-mastercard` | `Blueprint version 1 của template "VPBank StepUp Mastercard".` | `Chính sách hệ thống VPBank Lady Mastercard.` |
| 6 | `vpbank-z-card-jcb` | `Blueprint version 1 của template "MB Ultimate JCB".` | `Chính sách hệ thống VPBank Z Card JCB.` |
| 7 | `vpbank-super-shopee-platinum-visa` | `Blueprint version 1 của template "MB Ultimate JCB".` | `Chính sách hệ thống VPBank  Super Shopee Platinum Visa.` |
| 8 | `msb-mdigi-mastercard` | `Blueprint version 1 của template "Sacombank Uniq Visa".` | `Chính sách hệ thống MSB  mDigi Mastercard.` |
| 10 | `vpbank-cake-freedom-visa` | `Blueprint version 1 của template "MB Ultimate JCB".` | `Chính sách hệ thống VPBank  Cake Freedom Visa.` |
| 11 | `lpbank-ultimate-jcb` | `Blueprint version 1 của template "MB Ultimate JCB".` | `Chính sách hệ thống LPBank Ultimate JCB.` |
| 12 | `bidv-cashback-online-visa` | `Blueprint version 1 của template "VPBank StepUp Mastercard".` | `Chính sách hệ thống BIDV Cashback Online Visa.` |
| 13 | `seabank-sea-easy-visa` | `Blueprint version 1 của template "MB Ultimate JCB".` | `Chính sách hệ thống SeABank SeA-Easy Visa.` |

Quy ước note mới `Chính sách hệ thống {tên template}.` trùng với định dạng mà `CreditCardSeeder::seedResearchedTemplates()` dùng cho các template được seed đúng (dòng 771/1052/1863) — **không bịa** tên ngân hàng/sản phẩm mới; tên lấy đúng từ `credit_card_policy_templates.name` (kể cả khoảng trắng kép sẵn có, ví dụ `VPBank  Super Shopee…`).

---

## 3. Bằng chứng xác định note nào SAI

1. **Định dạng note là do hệ thống sinh tự động theo tên template sở hữu.**
   `PolicyCloneService::createTemplateBlueprint()` (`PolicyCloneService.php:358`):
   `'note' => $overrides['note'] ?? 'Blueprint version '.$versionNo.' của template "'.$template->name.'".'`
   ⇒ note đúng luôn phải nhắc **chính** tên template đang sở hữu blueprint.
2. **Luồng clone chép nguyên văn note nguồn.** `createSystemPolicyFromEditor()` (`PolicyCloneService.php:527`): `'note' => $blueprint->note` — không tái sinh theo tên template đích.
3. **Vì vậy**, một template có note `... template "TÊN KHÁC"` **chỉ có thể** phát sinh từ luồng clone, và bản thân nội dung note đã sai (nhắc template nguồn). Không có lý do nghiệp vụ nào để template "MSB mDigi" lại ghi chú là "Sacombank Uniq Visa".
4. **Note này hiển thị cho người dùng.** `CardPolicyLookupService::summarize()` đọc `$blueprint->note` và màn "Tra cứu thẻ" in ra (`resources/views/credit-card/lookup.blade.php`). ⇒ sai note = sai thông tin hiển thị.
5. **Định vị dữ liệu:** DB thật `hoantien_creditcard`, bảng `credit_card_policies`, cột `note`; 8 dòng tương ứng `id` 7, 8, 9, 10, 12, 13, 14, 15 (đúng như bảng §1).

Kết luận phân loại: **cả 8 note đều SAI CHẮC CHẮN** (mục 1 — "Sai chắc chắn"). Không có trường hợp nào thuộc nhóm "Đúng" hay "Chưa đủ bằng chứng" trong phạm vi note.

---

## 4. Phân tích riêng trạng thái `superseded`

### 4.1 Các blueprint `superseded` liên quan
Với các template trong phạm vi, blueprint duy nhất mang `status = superseded` ở: **#6, #7, #10, #11, #13** (và `#9` đã sửa note nhưng vẫn `superseded`). Ngoài phạm vi 8 template còn có **#3 MB Ultimate JCB** (nguồn). Tất cả đều:
- là blueprint **duy nhất** của template (không có version 2),
- là `default_version_id` của template,
- có `effective_from = 2026-09-30` nhưng `effective_to = 2026-09-29` (đóng **trước** ngày bắt đầu).

### 4.2 Vì sao có `superseded` — bằng chứng
- **Clone giữ nguyên trạng thái là hành vi CÓ CHỦ ĐÍCH:** `createSystemPolicyFromEditor()` copy `'status' => $blueprint->status` (`PolicyCloneService.php:512`), và điều này **được test khẳng định** tại `tests/Feature/CreditCard/SystemPolicyCloneTest.php:405`:
  `$this->assertSame((string) $sourceBlueprint->status, (string) $copy->status);`
- **Truy vết nguồn:** các template clone từ nguồn đang `active` (#2, #4) ⇒ blueprint **active** (#5, #8, #12). Các template clone từ nguồn `#3 MB Ultimate JCB` (đang `superseded`) ⇒ blueprint **superseded** (#6, #7, #10, #11, #13). Hoàn toàn khớp.
- **Bất thường gốc** nằm ở template nguồn **#3 MB Ultimate JCB** (blueprint duy nhất đã `superseded` sẵn trước khi bị clone) — không thuộc 8 template của đợt này.

### 4.3 Ảnh hưởng của `currentBlueprint()` / `defaultBlueprint()`
- `currentBlueprint()` (`PolicyTemplate.php:131`) lọc `status = active` ⇒ với các template `superseded` nó trả **null**.
- `defaultBlueprint()` (`PolicyTemplate.php:153`) ưu tiên `default_version_id` (không kiểm tra `status`) ⇒ **vẫn trả đúng** blueprint. Vì vậy **màn Tra cứu thẻ và luồng clone thẻ vẫn hoạt động bình thường**; sửa/không sửa `status` **không đổi** kết quả hiển thị hiện tại.

### 4.4 Kết luận: GIỮ NGUYÊN trạng thái (không sửa)
- Việc `superseded` ở bản clone là **hệ quả của hành vi clone có chủ đích, đã có test** — không phải lỗi của engine clone.
- **Chưa đủ bằng chứng** để khẳng định trạng thái đúng theo quy tắc vòng đời hiện hành: cần một quyết định riêng về ngữ nghĩa (bản clone của một template độc lập có buộc phải `active` không, và xử lý `effective_to` ra sao). Bất thường gốc nằm ở template nguồn `#3` (ngoài phạm vi 8 template).
- Rủi ro nếu tự ý chuyển `superseded → active`: đổi ngữ nghĩa vòng đời version và `currentBlueprint()`; cần sửa kèm `effective_to` (đang vô lý) ⇒ vượt khỏi phạm vi "sửa ghi chú tối thiểu".
- ⇒ **Không sửa `status`.** Ghi thành **mục cần xử lý riêng** (§7).

---

## 5. Migration, test và tài liệu đã thay đổi

| File | Loại | Nội dung |
|---|---|---|
| `database/migrations/creditcard/2026_10_14_000011_fix_stale_system_blueprint_notes.php` | **Mới** | Sửa `note` cho đúng 8 template; định vị theo `scope='system'` + `slug`; chỉ đổi khi note đúng định dạng hệ thống **và** tên nhắc khác tên chủ sở hữu; idempotent; bỏ qua an toàn khi thiếu; `down()` no-op (không phục hồi dữ liệu sai). |
| `tests/Feature/CreditCard/SystemBlueprintStaleNoteFixTest.php` | **Mới** | 6 test hồi quy (xem §6). |
| `docs/CREDIT_CARD_BLUEPRINT_NOTE_STATUS_AUDIT_REPORT.md` | **Mới** | Báo cáo này. |

**Không** sửa: engine cashback, `PolicyCloneService`, tier/rule, `status`, `max_cashback_total_per_period` của các thẻ khác, `.env`, dữ liệu MCC/tài liệu ngân hàng.

### An toàn migration
- Kết nối `creditcard`; không hard-code ID; guard `hasTable(...)`; guard template không tồn tại.
- Chỉ khớp note theo regex `^Blueprint version \d+ của template "(.+)"\.$` **và** tên khác tên template ⇒ **không đè** ghi chú do admin sửa tay.
- Idempotent: sau lần đầu note không còn khớp định dạng ⇒ bỏ qua.
- `down()` không khôi phục note SAI (chính sách rollback: giữ ghi chú đúng).

---

## 6. Kết quả test thực tế

| Lần chạy | Kết quả |
|---|---|
| `SystemBlueprintStaleNoteFixTest` (mới) | **OK — 6 tests, 21 assertions** |
| Nhóm liên quan (mới + `MsbOnlineVisaBlueprintCapFixTest`, `CardLookupTest`, `CardLookupMccProfileTest`, `CardLookupCategoryTest`, `SystemPolicyCloneTest`, `SystemPolicyManagementTest`) | **OK — 204 tests, 1329 assertions** |
| Toàn bộ `tests/Feature/CreditCard` | **OK — 1491 tests, 11003 assertions** |
| `php -l` (migration + test) | Không lỗi cú pháp |
| Pint (`--test`) | passed |
| `git diff --check` | clean |

Nội dung test hồi quy:
1. Sửa đúng note cho **cả 8** slug.
2. Gom **mọi** blueprint của template (không chỉ default) — v2 cũng được sửa.
3. **Idempotent** khi chạy lặp.
4. **Không đụng** template ngoài danh sách; không đụng note đã đúng; không đụng note admin sửa tay.
5. **Không đổi** `status`, `max_cashback_total_per_period`, `min_total_spend`, trần bậc, tỷ lệ rule.
6. Template thiếu trong test DB ⇒ **bỏ qua an toàn** (test DB sqlite `:memory:` không seed sẵn các template này; test tự dựng fixture đúng hạ tầng, không thêm dữ liệu vào DB thật).

---

## 7. Những trường hợp CHƯA đủ bằng chứng / không sửa

1. **Trạng thái `superseded` của blueprint duy nhất** (#6, #7, #9, #10, #11, #13 — và nguồn #3): giữ nguyên (lý do §4.4). Cần xử lý riêng nếu muốn chuẩn hoá vòng đời.
2. **Template nguồn #3 MB Ultimate JCB** (không thuộc 8 template): blueprint duy nhất `superseded`, `effective_to (2026-09-29) < effective_from (2026-09-30)`. Ngoài phạm vi; cần xác minh lịch sử tạo/xoá version trước khi can thiệp.
3. **`max_cashback_total_per_period` của các thẻ khác**: giữ nguyên (giá trị có thể hợp lệ theo thể lệ riêng; chỉ MSB Online Visa đã được chứng minh sai và sửa ở 000010).
4. **Vấn đề tiềm ẩn trong `createTemplateBlueprint()`**: khi template chỉ còn blueprint `superseded`, `currentBlueprint()` trả `null` ⇒ nhánh `if ($previous === null)` cấp `version_no = 1` và **bỏ qua `copyChildren`** (dựng bậc mặc định rỗng). Đây là **quan sát từ đọc code**, cần test xác minh riêng trước khi kết luận/khoá phạm vi. Ghi nhận để xử lý cùng mục 1.

---

## 8. Xác nhận

1. **Trần hoàn tiền, tier, rules, điều kiện chi tiêu và engine cashback KHÔNG bị thay đổi ngoài chủ đích.** Migration 000011 chỉ `UPDATE` cột `note` của 8 blueprint; bằng chứng so sánh snapshot DB trước/sau cho thấy **duy nhất** `note` (và `updated_at`) đổi; `status`, `min_total_spend`, `max_cashback_total_per_period`, `effective_from/to`, bậc, rule giữ nguyên.
2. **Không commit / push / deploy.** Mọi thay đổi còn ở working tree.
3. **Các workstream khác giữ nguyên:** không đụng `app/Services/CreditCard/Optimizer/*`, `config/credit_card_online.php`, `CardPortfolioOptimizerTest.php`, các file backup/order-sync; không sửa `.env`; không xoá `_cc_quota_audit.php`; không chạy `migrate:fresh`/`db:wipe`/rollback diện rộng.
4. Migration 000011 đã áp dụng vào DB thật bằng `php artisan credit-card:migrate --force` (chạy lại ⇒ "Nothing to migrate").

---

## 9. Tóm tắt

- **Đã kiểm toán:** 8 template (loại trừ MSB Online Visa đã sửa) + đối chiếu chéo template nguồn #2/#3/#4.
- **Ghi chú được sửa:** **8/8** (tất cả đều "sai chắc chắn").
- **Trạng thái được sửa:** **0** — giữ nguyên `superseded`; lý do: hành vi clone giữ nguyên trạng thái là **có chủ đích và đã được test** (`SystemPolicyCloneTest:405`), đồng thời chưa đủ bằng chứng về ngữ nghĩa vòng đời đúng.
- **Cần xác minh tiếp:**
  1. Chuẩn hoá vòng đời cho nhóm template chỉ còn blueprint `superseded` (#6, #7, #9, #10, #11, #13 và nguồn #3), gồm cả `effective_to` vô lý.
  2. Xác minh (bằng test) vấn đề tiềm ẩn của `createTemplateBlueprint()` khi `currentBlueprint()` trả `null`.
  3. Cân nhắc sửa `PolicyCloneService::createSystemPolicyFromEditor()` để sinh note theo template đích thay vì chép nguyên văn — tránh tái diễn cho các lần clone sau.
