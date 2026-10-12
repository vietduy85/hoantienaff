# LƯU DỮ LIỆU HOÀN TIỀN THEO HỒ SƠ MCC + IMPORT LẠI TÀI LIỆU NGÂN HÀNG

> **TRẠNG THÁI: HOÀN TẤT (ngày 12/10/2026)** — đã tạo bảng con lưu tỷ lệ/bậc/điều kiện/trần/nguồn cho từng dòng MCC, đã import **12 hồ sơ / 40 dòng / 95 bậc** từ tài liệu ngân hàng gốc, và nâng tab **“Tra cứu MCC”** để hiển thị **tỷ lệ hoàn tiền cao nhất + điều kiện của nó** (thay vì suy diễn từ blueprint theo tên danh mục).

Báo cáo này đối chiếu lại từng hạng mục đã triển khai: **mục tiêu → thiết kế → dữ liệu đã nạp → cơ chế tra cứu → kiểm thử → tồn đọng**. Không thay đổi engine cashback, không đụng bảng master MCC, không sửa blueprint.

---

## 1. Mục tiêu và phạm vi

1. Lưu **đầy đủ** dữ liệu hoàn tiền trích từ tài liệu ngân hàng gốc cho **từng DÒNG MCC** của hồ sơ (`included`/`excluded`): tỷ lệ, bậc chi tiêu, điều kiện, trần + kỳ/đơn vị, ngoại lệ, nguồn (tài liệu + trang).
2. Import lại cho **toàn bộ** hồ sơ MCC đã có, idempotent (chạy lại không sinh trùng, không mồ côi).
3. Tab **“Tra cứu MCC”** chỉ hiển thị **tỷ lệ cao nhất + điều kiện của nó** lấy từ bảng dữ liệu đã lưu — **không suy diễn** tỷ lệ từ tên danh mục.

**Ranh giới (đã tuân thủ):**
- KHÔNG dùng bảng mới làm đầu vào engine cashback/wallet/ledger.
- KHÔNG ghi đè/thay thế blueprint (`credit_card_policy_*`).
- KHÔNG sửa `credit_card_mcc_codes`, `credit_card_policy_mcc_mappings`, hay dữ liệu dòng/tài liệu hồ sơ #1.
- KHÔNG sửa `.env`; KHÔNG đụng nhánh công việc chuẩn hóa danh mục/combo đang dang dở.

---

## 2. Kiến trúc đã thêm

### 2.1 Bảng dữ liệu (migration)

`database/migrations/creditcard/2026_10_13_000003_create_credit_card_policy_mcc_row_benefits_table.php`

| cột | kiểu | ghi chú |
|-----|------|---------|
| `id` | bigint | |
| `policy_mcc_row_id` | bigint, FK → `credit_card_policy_mcc_rows` | `cascadeOnDelete` (`cc_pmcc_row_benefits_row_fk`) |
| `tier_order` | unsigned int | thứ tự bậc trong tài liệu |
| `cashback_percent` | decimal(6,3) nullable | số đã chuẩn hoá (10.000 = 10%) |
| `rate_label` | string(191) nullable | nhãn nguyên văn khi tài liệu không nêu số (ví dụ “tới 20%”) |
| `min_spend` / `max_spend` | decimal(16,2) nullable | ngưỡng chi tiêu của bậc |
| `cap_amount` | decimal(16,2) nullable | trần hoàn tiền |
| `cap_period` / `cap_unit` | string nullable | kỳ (vd “kỳ sao kê”) + đơn vị (vd “VND”, “điểm”) |
| `condition_note` / `exception_note` | text nullable | điều kiện kèm theo + ngoại lệ |
| `source_document_id` | bigint, FK → `credit_card_policy_mcc_documents` | `nullOnDelete` (`cc_pmcc_row_benefits_doc_fk`) |
| `source_page` / `source_note` | string/text nullable | trang trích dẫn |

Ràng buộc **UNIQUE(`policy_mcc_row_id`, `tier_order`)** bảo đảm import idempotent.

### 2.2 Model

`app/Models/CreditCard/PolicyMccRowBenefit.php` — cast `cashback_percent` (`decimal:3`), tiền (`decimal:2`), quan hệ `row()` và `document()`, tiện ích `hasRate()`.
`app/Models/CreditCard/PolicyMccProfileRow.php` — thêm quan hệ `benefits()` (HasMany, sắp theo `tier_order, id`).

### 2.3 Bộ dữ liệu tĩnh

`database/data/credit_card_mcc_row_benefits.php` — mảng khoá theo **slug template** → `['source_doc' => mảnh tên tài liệu, 'source_page' => trang mặc định, 'rows' => [category_name => [bậc…]]]`. Mỗi bậc dùng khoá gọn: `pct, label, min, max, cap, cap_period, cap_unit, cond, exc, page`.

### 2.4 Trình nhập (service + command)

- `app/Services/CreditCard/Import/MccRowBenefitImporter.php` — định vị **theo cấu trúc, không hard-code id**: template theo slug → hồ sơ đầu tiên → dòng `included` theo `category_name` → tài liệu nguồn theo mảnh `source_doc` (fallback: tài liệu đầu tiên). Upsert bậc theo UNIQUE; **xoá bậc thừa** khi dữ liệu rút gọn; toàn bộ trong **một transaction** trên connection `creditcard`.
- `app/Console/Commands/CreditCardImportMccRowBenefits.php` — lệnh `php artisan credit-card:import-mcc-row-benefits` (tuỳ chọn `--file=`), in thống kê tiếng Việt.

---

## 3. Dữ liệu đã nạp (đối chiếu)

Chạy `php artisan credit-card:import-mcc-row-benefits`:

```
Hồ sơ đã nạp ......... 12
Dòng đã nạp .......... 40
Bậc tạo mới .......... 95   (lần đầu)
Bậc cập nhật ......... 0
Bậc gỡ bỏ ............ 0
```

Chạy lại: `0 / 0 / 0` — **idempotent đã xác nhận**.

**Thống kê bảng dữ liệu hiện tại:** 95 bậc · 95 bậc có tài liệu nguồn (12 tài liệu phân biệt) · 95 bậc có điều kiện · 80 bậc có trần · 3 bậc chỉ có nhãn (không số).

| # | slug template | dòng có dữ liệu | bậc |
|---|---------------|----------------:|----:|
| 1 | `lpbank-ultimate-jcb` | 3 | 3 |
| 2 | `vib-one-card-visa-gold` | 4 | 16 |
| 3 | `sacombank-uniq-visa` | 2 | 4 |
| 4 | `mb-ultimate-jcb` | 3 | 3 |
| 5 | `vpbank-stepup-mastercars` | 2 | 6 |
| 6 | `vpbank-lady-mastercard` | 5 | 10 |
| 7 | `vpbank-z-card-jcb` | 3 | 3 |
| 8 | `msb-mdigi-mastercard` | 3 | 3 |
| 9 | `msb-online-visa` | 2 | 2 |
| 10 | `vpbank-cake-freedom-visa` | 2 | 2 |
| 11 | `bidv-cashback-online-visa` | 0 | 0 |
| 12 | `seabank-sea-easy-visa` | 0 | 0 |
| 13 | `shinhan-supreme-visa` | 6 | 18 |
| 14 | `vib-max-card-visa-starter` | 5 | 25 |
| | **Tổng** | **40** | **95** |

### 3.1 Tóm tắt tỷ lệ theo từng hồ sơ (nguồn: tài liệu gốc)

- **#1 LPBank Ultimate JCB** — Y tế/Giáo dục/Bảo hiểm **15%**, min 8.000.000/kỳ sao kê, trần 800.000/kỳ (Bảo hiểm phụ trần 400.000).
- **#2 VIB One Card** — theo hạng: Gold **3%** / Platinum **6%** / Signature **9%**, còn lại 0,1%; trần (điểm/kỳ sao kê) 500k / 1M / 1,5M.
- **#3 Sacombank UniQ** — “Siêu thị, cửa hàng tiện lợi” & “Ứng dụng di chuyển” **50%** giao dịch đầu (trong 30 ngày kích hoạt) / **20%** giao dịch sau; trần 300.000/kỳ TBGD mỗi nhóm.
- **#4 MB Ultimate JCB** — Y tế/Giáo dục/Bảo hiểm **10%**, trần 800.000/kỳ sao kê (Bảo hiểm phụ 400.000).
- **#5 VPBank StepUp** — “Chi tiêu online” 15% (≥100tr) / 15% (10–<100tr) / 6% (<10tr); “Ăn uống” 4%/4%/2%; trần theo nhóm.
- **#6 VPBank Lady** — “Bảo hiểm trực tuyến” 15%/7,5%; Giáo dục 15%/7,5%; “Chăm sóc sắc đẹp” & Y tế 10%/5%; Siêu thị 5%/2,5% (nhóm ≥15tr / <15tr).
- **#7 VPBank Z Card** — Ăn uống 10% trần 200k; “Giải trí và Fitness” 10% trần 200k; “Di chuyển và đặt đồ ăn” 10% trần 100k; điều kiện tổng chi tiêu ≥4.000.000/kỳ sao kê.
- **#8 MSB mDigi** — nhãn **“tới 20%”**, trần 300.000/kỳ sao kê, chọn 1 trong 3 (Ăn uống/Du lịch/Sản phẩm số).
- **#9 MSB Online** — “Thương mại điện tử” & “Mua vé xem phim online” **10%**, min 3.000.000, trần 300.000/kỳ sao kê.
- **#10 Cake Freedom** — **20%** cho 5 danh mục (3 cố định + 2 linh hoạt chọn trong 9); trần 200.000/danh mục/kỳ; tổng 1.000.000/khách/kỳ; min 5.000.000/kỳ.
- **#13 Shinhan Supreme** — **12%** (TMĐT/Điện máy/Giáo dục/Nhà sách), **6%** (Bệnh viện/Bảo hiểm); trần (điểm) 300k (3–<15tr) / 1.000k (≥15tr).
- **#14 VIB Max Card** — theo hạng: Starter **0,5%** / Plus **5%** / Pro **8%** / Elite **10%**; trần (điểm/kỳ sao kê) 500k / 500k / 1.000k / 1.500k.

---

## 4. Cơ chế tra cứu MCC (đã cập nhật)

`app/Services/CreditCard/CardPolicyLookupService.php`:

- `adminProfileMatches()` eager-load thêm `rows.benefits.document`.
- `profileMatch()` gắn `cashback` từ `benefitCashback()` và bổ sung `source_pdf` / `source_page` (lấy từ tài liệu đã trích).
- `benefitCashback()` trả:
  - `status = excluded` khi dòng bị loại trừ;
  - `status = missing` khi dòng không có dữ liệu (`no_data`);
  - `status = available` + `tiers` + `highest` khi có bậc. `highest` = bậc có `cashback_percent` lớn nhất (phá hoà bằng trần lớn hơn); nếu mọi bậc chỉ có nhãn ⇒ `highest = null` (**không bịa số**).
- `attachCashback()` **bỏ qua** kết quả đến từ hồ sơ MCC (`source === 'policy_mcc_profile'`) — hồ sơ dùng dữ liệu tài liệu đã lưu, không bị ghi đè bằng blueprint.

`resources/views/credit-card/lookup.blade.php` (tab **Tra cứu MCC**): khi `cashback.highest` tồn tại, hiển thị khối nổi bật **“Hoàn tối đa \<tỷ lệ\>”** kèm **điều kiện** và **trần**; chỉ khi không có tỷ lệ số mới liệt kê bậc chi tiết (kèm nhãn nguyên văn).

---

## 5. Kiểm thử

- `tests/Feature/CreditCard/CardLookupMccProfileTest.php` — cập nhật luồng hồ sơ MCC: tra cứu lấy **bậc cao nhất + điều kiện** từ bảng dữ liệu (`highest`), và trường hợp chỉ có nhãn (“tới 20%”) không sinh số. **8/8 pass.**
- `tests/Feature/CreditCard/MccRowBenefitImportTest.php` (**mới**) — tạo bậc + phân giải tài liệu/trang, idempotent lần 2, xoá bậc thừa khi rút gọn, bỏ qua slug/dòng không khớp. **4/4 pass.**
- Toàn bộ `tests/Feature/CreditCard`: **1339 pass / 8951 assertions.**

**Sự cố tồn tại (ngoài phạm vi):** `tests/Feature/CreditCardModuleTest::credit_card_routes_do_not_override_existing_routes` đang fail vì route `credit-cards.api.policies.history` (từ nhánh **chuẩn hóa danh mục/combo** đang dang dở) chưa có trong danh sách chốt của test. Không do thay đổi trong báo cáo này; **không sửa** để tránh đụng nhánh công việc khác.

---

## 6. Tồn đọng / cần theo dõi

1. **Master `credit_card_mcc_codes` thiếu mã có dẫn chứng** — đã ghi nhận, **chưa thêm**: VIB `5301, 5298, 5296, 0046`; nhóm hàng không `3000–3838, 3998, 0070, 0010`; Shinhan `6381, 6399`; MB `8044, 8082, 7295`; Sacombank `9751`; BIDV `6050, 6532, 6533, 6538`; LPBank `8044, 8082, 6381, 6399, 7511, 6050`.
2. **VIB One Card / Max Card** — `category_name` dòng hồ sơ là text tự do, **không** khớp tên danh mục blueprint (VIB dùng `combo_id`); tra cứu vì thế đọc từ bảng dữ liệu đã lưu, **không** name-match blueprint.
3. **`VPBank Super Shopee` (template 7)** — chưa có tài liệu MCC nguồn ⇒ **chưa đủ căn cứ**, không nạp.
4. **Hồ sơ #11 (BIDV) và #12 (SeABank)** — 0 dòng `included` ⇒ không có gì để nạp (không phải lỗi).
5. Bảng dữ liệu **chỉ phục vụ tra cứu**; nếu sau này muốn engine đọc trực tiếp cần một thiết kế riêng (ngoài phạm vi).

---

## 7. Tệp liên quan

| Tệp | Vai trò |
|-----|---------|
| `database/migrations/creditcard/2026_10_13_000003_create_credit_card_policy_mcc_row_benefits_table.php` | Migration bảng con (đã áp dụng). |
| `app/Models/CreditCard/PolicyMccRowBenefit.php` | Model bậc hoàn tiền theo dòng. |
| `app/Models/CreditCard/PolicyMccProfileRow.php` | Thêm `benefits()`. |
| `database/data/credit_card_mcc_row_benefits.php` | Bộ dữ liệu tĩnh 12 hồ sơ. |
| `app/Services/CreditCard/Import/MccRowBenefitImporter.php` | Trình nhập idempotent. |
| `app/Console/Commands/CreditCardImportMccRowBenefits.php` | Lệnh `credit-card:import-mcc-row-benefits`. |
| `app/Services/CreditCard/CardPolicyLookupService.php` | Tra cứu dùng dữ liệu đã lưu. |
| `resources/views/credit-card/lookup.blade.php` | Tab “Tra cứu MCC”: cao nhất + điều kiện. |
| `tests/Feature/CreditCard/CardLookupMccProfileTest.php` | Test tra cứu hồ sơ MCC (cập nhật). |
| `tests/Feature/CreditCard/MccRowBenefitImportTest.php` | Test trình nhập (mới). |
| `docs/CREDIT_CARD_MCC_PROFILES_REPORT.md` | Báo cáo khảo sát hồ sơ/tài liệu gốc. |
