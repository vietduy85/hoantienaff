# Điều tra: sai trần hoàn tiền "MSB Online Visa" trên màn hình Tra cứu thẻ

- **Ngày điều tra:** 2026-10-14
- **Hiện tượng:** Tra cứu thẻ → "MSB Online Visa" hiển thị:
  - Chi tiêu tối thiểu **3.000.000đ** (đúng),
  - "Trần hoàn tiền mỗi kỳ: **800.000 đ**" (**sai**),
  - dòng ghi chú: `Blueprint version 1 của template "MB Ultimate JCB".` (**sai**),
  - các dòng Shopee/Lazada/TikTok 10%, tối đa 300.000đ/kỳ (đúng).
- **Kết luận:** 800.000đ là **giá trị trần cấp-policy `max_cashback_total_per_period` được SAO CHÉP NHẦM** từ blueprint của template *MB Ultimate JCB* khi template *MSB Online Visa* được tạo bằng luồng "sao chép chính sách hệ thống". Dòng "MB Ultimate JCB" là `note` của chính blueprint đó, cũng bị sao chép nguyên văn. **KHÔNG phải giá trị tính động** và **KHÔNG phải trần tổng của nhiều quyền lợi**.

---

## 1. Nguồn gốc con số 800.000đ (chính xác)

### Bảng / cột / bản ghi (DB đang chạy: MySQL `hoantien_creditcard`, connection `creditcard`)

| Mục | Giá trị |
|---|---|
| Bảng | `credit_card_policies` |
| Cột | `max_cashback_total_per_period` |
| Bản ghi | `id = 11` (template_id = 9, user_card_id = NULL, version_no = 1) |
| Giá trị (trước khi sửa) | `800000.00` |
| `note` (trước khi sửa) | `Blueprint version 1 của template "MB Ultimate JCB".` |
| `status` | `superseded` |
| `min_total_spend` | `3000000.00` |

Bản ghi `policy#11` **thuộc chính template MSB Online Visa** (`credit_card_policy_templates.id = 9`, slug `msb-online-visa`, scope `system`), và là `default_version_id` của template đó. Điều này loại trừ giả thuyết "trỏ nhầm sang blueprint của template khác": `PolicyTemplate::defaultBlueprint()` (app/Models/CreditCard/PolicyTemplate.php:153) có guard `template_id` — nếu trỏ sai template nó đã fallback về blueprint hiện hành của chính template.

### Nơi con số được đọc để hiển thị (không tính động)

- `app/Services/CreditCard/CardPolicyLookupService.php:80`
  `$summary['max_cashback_total_per_period'] = $blueprint->max_cashback_total_per_period;`
- `app/Http/Controllers/CreditCard/CreditCardLookupController.php:77` (`policy()`) trả `'policy' => $this->lookup->summarize($template)`.
- `resources/views/credit-card/lookup.blade.php:128-130` in nhãn **"Trần hoàn tiền mỗi kỳ"** = `policyResult.policy.max_cashback_total_per_period`.
- `resources/views/credit-card/lookup.blade.php:133` in `policyResult.policy.note` (chính là dòng "MB Ultimate JCB").

⇒ Giá trị được **đọc thẳng từ 1 cột DB và in ra**, không có phép cộng/tính toán nào tạo ra 800.000.

### Vì sao là 800.000 và không phải con số khác

Template nguồn **MB Ultimate JCB** (`credit_card_policy_templates.id = 3`, slug `mb-ultimate-jcb`) có blueprint `policy#3` với đúng `max_cashback_total_per_period = 800000.00` và `note = 'Blueprint version 1 của template "MB Ultimate JCB".'`. Bản clone sang MSB Online Visa giữ nguyên hai giá trị này.

> Kỹ thuật: giá trị 800.000 là **cột legacy**. Trần hoàn thực tế của engine nằm ở **từng bậc** (`credit_card_policy_tiers.max_cashback_per_period`), xem `PolicyService::backfillSystemBlueprintCaps()` (app/Services/CreditCard/PolicyService.php:562-614). Engine cashback **không đọc** `credit_card_policies.max_cashback_total_per_period` — nó chỉ dùng để **hiển thị** ở màn Tra cứu.

---

## 2. Nguyên nhân dòng hiển thị `MB Ultimate JCB`

Đường tạo template: **sao chép chính sách hệ thống** (`PolicyCloneService::createSystemPolicyFromEditor()`, app/Services/CreditCard/PolicyCloneService.php:455-571). Khi clone, bản ghi blueprint mới được chèn với:

```php
// PolicyCloneService.php:512  ... 'status' => $blueprint->status,
// PolicyCloneService.php:521-523 ... 'max_cashback_total_per_period' => $blueprint->max_cashback_total_per_period,
// PolicyCloneService.php:527  'note' => $blueprint->note,
```

⇒ `note` và `max_cashback_total_per_period` (và cả `status`) được **chép NGUYÊN VĂN** từ blueprint nguồn. Vì template MSB Online Visa được clone **từ MB Ultimate JCB**, `note` giữ nguyên `'Blueprint version 1 của template "MB Ultimate JCB".'`.

Ngoài ra `status` cũng bị chép (`superseded` của blueprint MB), nên blueprint duy nhất của MSB Online Visa mang trạng thái `superseded` dù chưa từng được thay version — đây là hệ quả cùng nguồn (xem §4).

Ghi chú: nhánh tạo version MỚI cho một template (`createTemplateBlueprint()`, dòng 358) lại sinh `note` theo tên template hiện tại (`'Blueprint version '.$versionNo.' của template "'.$template->name.'".'`) nên KHÔNG dính lỗi; chỉ nhánh **clone template** mới chép note nguồn.

---

## 3. Dữ liệu MSB Online Visa: hiện tại vs. nguồn chính thức

**Nguồn chính thức** (truy cập 2026-10-14):
- https://www.msb.com.vn/khach-hang-ca-nhan/the/the-msb-visa-online/
  → *"Hoàn 10% chi tiêu online khi tổng chi tiêu đạt 3 triệu đồng/kỳ sao kê: Mua sắm tại Shopee, Lazada, TikTok; Đặt vé xem phim tại CGV, BHD, Galaxy Cinema, Lotte Cinema, Cinestar"*; *"Hoàn 10% giao dịch online tới 300.000 đồng mỗi tháng"*.
- https://www.msb.com.vn/ve-chung-toi/tin-tuc-va-su-kien/ve-msb/the-tin-dung-msb-visa-online-cap-nhat-dien-mao-moi/

### Blueprint (cấp policy) — `policy#11`

| Trường | Trước sửa | Sau sửa | Nguồn chính thức |
|---|---|---|---|
| `min_total_spend` | 3.000.000 | 3.000.000 | 3.000.000/kỳ ✔ |
| `max_cashback_total_per_period` | **800.000** ✘ | **300.000** ✔ | 300.000/kỳ |
| `note` | *"…MB Ultimate JCB…"* ✘ | *"Chính sách hệ thống MSB Online Visa."* ✔ | — |

### Bậc & rule (giữ nguyên, KHÔNG sửa)

| Bậc | Khoảng chi tiêu | Trần bậc/kỳ | Rule |
|---|---|---|---|
| Bậc 1 | 0 – 2.999.999 | — | fallback 0% |
| **Bậc 2 – Từ 3tr** | ≥ 3.000.000 | 300.000 | Shopee 10% (cap_cat 300.000), Lazada 10% (300.000), Tiktok 10% (300.000), fallback 0% |

- Tỷ lệ 10%, điều kiện đạt bậc từ 3.000.000/kỳ, trần 300.000/kỳ ⇒ **khớp nguồn chính thức**.
- Ghi chú: nguồn còn nêu **vé xem phim** (CGV/BHD/Galaxy/Lotte/Cinestar). Blueprint chỉ cấu hình Shopee/Lazada/Tiktok; phần vé xem phim đã có ở **hồ sơ MCC** (`database/data/credit_card_mcc_row_benefits.php` mục `msb-online-visa`: nhóm "Thương mại điện tử" và "Mua vé xem phim online", 10%, cap 300.000/kỳ). Việc bổ sung rule engine cho vé xem phim **ngoài phạm vi** báo cáo này.

---

## 4. Phạm vi ảnh hưởng tới thẻ khác

Cùng cơ chế "clone chép nguyên `note`/`max_cashback_total_per_period`/`status`", các template hệ thống tạo bằng clone cũng bị:

**a) `note` còn nhắc template KHÁC (chưa sửa — ngoài phạm vi):**

| Template | Slug | `note` đang nhắc tới |
|---|---|---|
| #5 VPBank Lady Mastercard | `vpbank-lady-mastercard` | "VPBank StepUp Mastercard" |
| #6 VPBank Z Card JCB | `vpbank-z-card-jcb` | "MB Ultimate JCB" |
| #7 VPBank Super Shopee Platinum Visa | — | "MB Ultimate JCB" |
| #8 MSB mDigi Mastercard | `msb-mdigi-mastercard` | "Sacombank Uniq Visa" |
| ~~#9 MSB Online Visa~~ | `msb-online-visa` | **đã sửa** |
| #10 VPBank Cake Freedom Visa | — | "MB Ultimate JCB" |
| #11 LPBank Ultimate JCB | `lpbank-ultimate-jcb` | "MB Ultimate JCB" |
| #12 BIDV Cashback Online Visa | `bidv-cashback-online-visa` | "VPBank StepUp Mastercard" |
| #13 SeABank SeA-Easy Visa | — | "MB Ultimate JCB" |

**b) Blueprint `status = superseded` dù là version duy nhất** (thừa hưởng từ MB): template #3, #6, #7, #9, #10, #11, #13.

**c) `max_cashback_total_per_period = 800000`** (giá trị MB) xuất hiện trên #3, #6, #7, #9, #10, #11, #13.

> ⚠️ **Chỉ MSB Online Visa được khẳng định là SAI** theo nguồn chính thức (300.000). Các thẻ khác có thể **trùng giá trị 800.000 một cách hợp lệ** theo thể lệ riêng của chúng ⇒ **KHÔNG tự ý đổi** giá trị trần của các thẻ khác. Riêng phần `note` nhắc sai tên template là lỗi dữ liệu rõ ràng nhưng nằm ngoài phạm vi task (có thể xử lý sau bằng cùng cách).

---

## 5. Phương án sửa (đã thực hiện, tối thiểu & an toàn)

### Migration dữ liệu
`database/migrations/creditcard/2026_10_14_000010_fix_msb_online_visa_blueprint_note_and_total_cap.php`
- Định vị template theo `scope='system'` + `slug='msb-online-visa'` (**không hard-code id**).
- Với các blueprint (`user_card_id IS NULL`) của template:
  - `note` → `'Chính sách hệ thống MSB Online Visa.'` **chỉ khi** note còn chứa `"MB Ultimate JCB"`.
  - `max_cashback_total_per_period` → `300000.00` **chỉ khi** đang bằng `800000` (so sánh dạng số để hợp cả SQLite và MySQL).
- **Idempotent**: điều kiện tự triệt tiêu sau lần chạy đầu; bỏ qua khi template chưa tồn tại.
- **KHÔNG** đụng `credit_card_policy_tiers` / `credit_card_policy_tier_categories` ⇒ **không đổi tỷ lệ/điều kiện engine**. Cột sửa là cột legacy chỉ dùng để **hiển thị** ⇒ không ảnh hưởng tính hoàn tiền.
- `down()` không khôi phục dữ liệu SAI.

### Kết quả áp dụng (DB thật)
`php artisan credit-card:migrate --force` → `2026_10_14_000010_... DONE`.
Kiểm chứng qua `CardPolicyLookupService::summarize()` cho MSB Online Visa:
- `min_total_spend = '3000000.00'` ✔
- `max_cashback_total_per_period = '300000.00'` ✔
- `note = 'Chính sách hệ thống MSB Online Visa.'` ✔
- Bậc/rule không đổi (Shopee/Lazada/Tiktok 10%, cap 300.000).

### Đề xuất tiếp theo (không thực hiện trong phạm vi này)
1. Xử lý `note`/`status` sao chép sai cho các template ở §4 (cùng loại migration, mỗi thẻ phải đối chiếu thể lệ riêng trước khi đổi trần).
2. Cân nhắc sửa `PolicyCloneService::createSystemPolicyFromEditor()` để **không** chép `note` nhắc template nguồn (sinh note theo template đích) và **không** kế thừa `status='superseded'` cho bản clone — tránh tái diễn cho các lần clone sau. Đây là thay đổi hành vi clone nên cần test riêng, **chưa** làm.

---

## 6. Kiểm thử

- **Mới:** `tests/Feature/CreditCard/MsbOnlineVisaBlueprintCapFixTest.php` — 4 test / 13 assertion:
  - sửa đúng `note` + trần 300.000 và `summarize()` phản ánh đúng;
  - idempotent + **không đụng template khác**;
  - **không đổi** trần bậc / tỷ lệ rule;
  - bỏ qua an toàn khi template không tồn tại.
- Test liên quan đã chạy: xem mục "Kết quả test" cuối báo cáo (tổng hợp).
- `php -l`, Pint, `git diff --check`: sạch.

---

## 7. Tóm tắt thay đổi file

| File | Loại |
|---|---|
| `database/migrations/creditcard/2026_10_14_000010_fix_msb_online_visa_blueprint_note_and_total_cap.php` | **Mới** — migration sửa dữ liệu hiển thị MSB Online Visa |
| `tests/Feature/CreditCard/MsbOnlineVisaBlueprintCapFixTest.php` | **Mới** — test |
| `docs/CREDIT_CARD_MSB_ONLINE_800K_CAP_INVESTIGATION.md` | **Mới** — báo cáo này |

**Không** sửa: engine cashback, blueprint/tier/rule, `.env`, các thẻ khác, các nhánh công việc chưa commit khác (PortfolioOptimizerService, backup/order-sync). **Không** commit/push/deploy.
