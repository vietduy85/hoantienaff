# QUY TRÌNH MODULE MCC CHÍNH SÁCH THẺ TÍN DỤNG (BÀN GIAO)

> Tài liệu bàn giao cho các phiên OpenCode sau. Mô tả **đúng như hệ thống đang chạy**,
> không mô tả kế hoạch cũ. Mọi tên bảng / lớp / lệnh / route đều lấy từ mã nguồn hiện tại.
>
> Phạm vi: module **Quản lý MCC** (admin) + tab **Tra cứu MCC** (`/thetindung/tra-cuu`).
> Kết nối CSDL: `creditcard` (database `hoantien_creditcard`).
>
> Ghi chú: Phần H được tổng hợp lại từ các báo cáo trong `docs/` (nội dung gốc trong đề bài
> bị cắt ở tiêu đề Phần H) — xem Phần H để biết danh sách lỗi và cách xử lý có bằng chứng.

---

## PHẦN A — KIẾN TRÚC & MÔ HÌNH DỮ LIỆU

### A.1 Ba lớp dữ liệu phải phân biệt rõ

| Lớp | Nơi lưu | Vai trò | Ai ghi |
|---|---|---|---|
| **Dữ liệu hồ sơ MCC** | `credit_card_policy_mcc_profiles` + `..._rows` + `..._row_codes` + `..._documents` | Admin nhập tay: danh sách MCC theo danh mục (included/excluded) + tài liệu ngân hàng đính kèm. | Admin (form) |
| **Dữ liệu hoàn tiền theo tài liệu** | `credit_card_policy_mcc_row_benefits` | Bậc/tỷ lệ/điều kiện/trần trích từ **tài liệu gốc**, gắn theo từng dòng MCC. **Chỉ phục vụ tra cứu.** | Trình nhập `credit-card:import-mcc-row-benefits` |
| **Dữ liệu blueprint (engine)** | `credit_card_policy_templates` → `credit_card_policies` (blueprint) → `credit_card_policy_tiers` → `credit_card_policy_tier_categories` | Công thức hoàn tiền thật mà engine đọc. Nguồn tỷ lệ chính thống cho các mapping import. | Nghiệp vụ chính sách (ngoài phạm vi module MCC) |

Nguyên tắc xuyên suốt: module MCC **không** ghi vào blueprint, **không** đụng engine cashback/ví/sổ cái.
Bảng `credit_card_policy_mcc_row_benefits` cũng **không** là đầu vào của engine.

### A.2 Quan hệ giữa các thực thể

```
credit_card_policy_templates (1)
  └── (n) credit_card_policy_mcc_profiles      [hồ sơ: name, note, product_name]
        │      template chủ = policy_template_id
        │      template LIÊN KẾT (dùng chung) = (n) qua credit_card_policy_mcc_profile_template_links
        ├── (n) credit_card_policy_mcc_rows     [row_type: included|excluded, category_name, note]
        │      ├── (n) credit_card_policy_mcc_row_codes   [mỗi mã MCC 1 bản ghi → mcc_code_id, mcc]
        │      └── (n) credit_card_policy_mcc_row_benefits [bậc hoàn tiền trích tài liệu; tier_order]
        └── (n) credit_card_policy_mcc_documents [tài liệu ngân hàng, disk private]

credit_card_policy_mcc_profile_template_links  [policy_mcc_profile_id ↔ policy_template_id, UNIQUE]
credit_card_mcc_codes (master MCC)  ←── mcc (natural key, char 4)
```

### A.3 Vai trò từng bảng / model

- **`PolicyMccProfile`** (`app/Models/CreditCard/PolicyMccProfile.php`) — "hồ sơ MCC chính sách".
  Trường: `policy_template_id` (**template CHỦ**), `name` (tên riêng của hồ sơ, KHÔNG đổi tên template), `note`,
  `product_name` (nullable — tên **sản phẩm đại diện** khi một chương trình dùng chung tài liệu cho
  nhiều hạng thẻ; chỉ VIB khai báo). Quan hệ: `template()`, `rows()`, `includedRows()`,
  `excludedRows()`, `documents()`, `templateLinks()` (HasMany tới bảng liên kết),
  `sharedTemplates()` (BelongsToMany tới các template dùng chung).
  Scope `scopeForTemplate($id)` = template **chủ** HOẶC có **liên kết** tới template đó; helper
  `allTemplateIds()` = [template chủ + mọi template liên kết] (dùng để mở rộng phạm vi loại trừ — Phần G).
  Trường **phí thường niên** (nullable, `decimal(16,2)`, cast `decimal:2`):
  `annual_fee_primary` (thẻ chính), `annual_fee_supplementary` (thẻ phụ),
  `annual_spend_for_annual_fee_waiver` (tổng chi tiêu/năm để được miễn/hoàn phí) — xem Phần I.
- **`PolicyMccProfileTemplateLink`** (`app/Models/CreditCard/PolicyMccProfileTemplateLink.php`) — một dòng
  liên kết hồ sơ ↔ template **dùng chung**: `policy_mcc_profile_id` + `policy_template_id`
  (UNIQUE đôi, FK cascade). Xem Phần G để biết quy tắc và A.6.
- **`PolicyMccProfileRow`** (`PolicyMccProfileRow.php`) — một DÒNG MCC. `row_type` = `included`
  (hoàn tiền) | `excluded` (loại trừ). `category_name` là **text tự do**, KHÔNG liên kết
  `credit_card_categories` (VIB dùng combo nên tên không khớp blueprint). Một dòng chứa **nhiều** MCC.
  Quan hệ: `codes()` (HasMany `PolicyMccProfileRowCode`), `benefits()` (HasMany `PolicyMccRowBenefit`,
  sắp theo `tier_order`).
- **`PolicyMccProfileRowCode`** — một mã MCC của dòng. Lưu cả `mcc_code_id` (FK → master, `nullOnDelete`)
  lẫn `mcc` (char 4) để tra ngược. UNIQUE (`policy_mcc_row_id`, `mcc`); index (`mcc`).
- **`PolicyMccProfileDocument`** — tài liệu ngân hàng (PDF/XLSX/XLS). Trường: `disk` (mặc định `local`),
  `path`, `original_name`, `mime`, `extension`, `size`, `uploaded_by`.
- **`PolicyMccRowBenefit`** — bậc hoàn tiền của một dòng: `tier_order`, `cashback_percent`
  (decimal 6,3; 10.000 = 10%), `rate_label` (nhãn nguyên văn như "tới 20%"), `min_spend`/`max_spend`,
  `cap_amount`, `cap_period`, `cap_unit`, `condition_note`, `exception_note`, `source_document_id`
  (FK → documents, `nullOnDelete`), `source_page`, `source_note`. UNIQUE (`policy_mcc_row_id`, `tier_order`).
- **`MccCode`** (`credit_card_mcc_codes`) — MCC master: `mcc` char(4) UNIQUE (giữ số 0 đầu),
  `description_en`, `description_vi`, `keywords`, `source`, `verification_status`
  (`verified` | `unverified` | `needs_review`, mặc định `unverified`), `verified_at`.
- **`CardPolicyMccMapping`** (`credit_card_policy_mcc_mappings`) — dữ liệu import **append-only** cho
  tra cứu: `policy_template_id`, `category_id`, `policy_tier_category_id`, `match_type`
  (`direct` | `group` | `all` | `all_except`), `mcc`, `mcc_group`, `mcc_codes` (JSON),
  `application_status` (`applied` | `excluded` | `unverified`), `cashback_percent`, `condition_note`,
  `exclusion_reason`, `source_pdf`, `source_page`, `verification_status`, `verified_at`,
  `fingerprint` UNIQUE (chống trùng khi import lại). Không lưu lại tên/mức của chính sách.
- **Bảng cũ giữ nguyên** (chưa xoá): `credit_card_policy_mcc_groups`, `..._group_codes`,
  `..._common_exclusions`. Migration `2026_10_12_000005_backfill_policy_mcc_groups_into_profiles.php`
  sao lưu (backfill) dữ liệu nhóm cũ vào mô hình hồ sơ; `down()` **cố ý không xoá** dữ liệu đã backfill.

### A.4 Lưu trữ & truy cập tài liệu gốc

- Hằng số trong `PolicyMccProfileService`: `DOCUMENT_DIRECTORY = 'credit-card-mcc'`,
  `DOCUMENT_DISK = 'local'` → file nằm ở **disk private** `storage/app/private/credit-card-mcc/{profileId}/`.
- Tên file khi lưu: `uuid.{ext}`; `original_name` giữ tên gốc để hiển thị/tải.
- **Không public.** Truy cập qua route `admin.credit-card.mcc.documents.download`
  (`GET /credit-card/mcc/{profile}/documents/{document}`), middleware `permission:credit-cards.view`.
  Controller `PolicyMccAdminController::downloadDocument()` kiểm tra tài liệu thuộc đúng hồ sơ và
  file tồn tại, rồi stream download với `original_name`. Cột `path` **không** lộ ra view (view chỉ dùng
  `original_name`).
- Tải lên **không xoá tài liệu cũ** (giữ lịch sử đối chiếu). Có hàm `deleteDocument()` (xoá bản ghi + file).

### A.5 Phân quyền

- Đọc (index/show/master/download): `credit-cards.view`.
- Ghi (create/store/edit/update): `credit-cards.manage`.
- Form request kiểm tra `authorize()` = có quyền `credit-cards.manage`.
- Chỉ hồ sơ gắn **chính sách hệ thống** (`scope = system`) mới thuộc phạm vi.

### A.6 Chương trình dùng chung nhiều template (bảng liên kết)

Một chương trình ngân hàng có thể áp dụng cho **nhiều hạng thẻ = nhiều template** nhưng chỉ có **một bộ
danh mục MCC** (ví dụ VIB Max Card, VIB One Card — xem Phần G). Để **không nhân bản** dữ liệu:

- Hồ sơ vẫn thuộc **một template chủ** (`policy_template_id`) để không phá luồng tạo/sửa hiện có.
- Các template hạng khác được ghi vào bảng **`credit_card_policy_mcc_profile_template_links`**
  (`policy_mcc_profile_id` + `policy_template_id`).
- **Tra cứu theo template** dùng `PolicyMccProfile::scopeForTemplate($id)`:
  `where('policy_template_id', $id)` **OR** `whereHas('templateLinks', … policy_template_id = $id)`.
  ⇒ mọi hạng dùng chung đều thấy **đúng một** hồ sơ.
- **Loại trừ thắng** được mở rộng theo `allTemplateIds()` (chủ + liên kết) để mapping import của
  template liên kết cũng bị loại khi hồ sơ có dòng `excluded` chứa mã.
- Tra cứu theo **mã MCC** vẫn gộp về **một kết quả đại diện** theo `product_name` (Phần F/G) — không trùng.

Hiện có **7 liên kết**: #14 → #15/#16/#17/#18 (Max Card) và #2 → #19/#20/#21 (One Card). Migration seed
`2026_10_13_000006_...`. Ví dụ ngoài VIB: **HSBC Cash Back** tạo hồ sơ **#28** cho template #31 (không dùng chung).

---

## PHẦN B — QUY TRÌNH TẠO MỘT HỒ SƠ MCC MỚI (12 BƯỚC)

1. **Xác định chính sách đích.** Chọn template `credit_card_policy_templates` có `scope = system`
   và `is_active = true` (danh sách lấy qua `PolicyMccAdminController::templateOptions()`).
   Lưu `slug` template để dùng cho bước nạp tỷ lệ.
2. **Thu thập tài liệu chính thức** của ngân hàng (thể lệ/quy định hoàn tiền). Không dùng nguồn thứ cấp.
3. **Trích danh sách MCC** được hoàn tiền (included) và bị loại trừ (excluded) từ tài liệu; trích luôn
   tỷ lệ/bậc/điều kiện/trần + số trang.
4. **Đối chiếu từng mã với master** `credit_card_mcc_codes`. Mã **có trong tài liệu nhưng chưa có trong
   master** ⇒ KHÔNG đưa vào hồ sơ, ghi nhận lại để bổ sung master sau (xem Phần D).
5. **Mở form tạo hồ sơ:** `/credit-card/mcc/create` (route `admin.credit-card.mcc.create`, cần quyền
   `credit-cards.manage`).
6. **Nhập tên hồ sơ (`name`)** — tên riêng của hồ sơ, KHÔNG đổi tên template gốc; nhập `note` chung.
7. **Nhập bảng "MCC được hoàn tiền" (included):** mỗi dòng gồm `category_name` (tên danh mục tự do),
   `description`, `note`, `codes[]` (nhiều mã, phân tách bằng dấu phẩy/khoảng trắng đều được).
8. **Nhập bảng "MCC loại trừ" (excluded):** cấu trúc dòng như trên.
9. **Đính kèm tài liệu ngân hàng** (`documents[]`): chỉ PDF/XLSX/XLS, tối đa **10 MB/file**, tối đa
   **20 file** (xem `StorePolicyMccProfileRequest`).
10. **Gửi form.** `StorePolicyMccProfileRequest` kiểm tra: template tồn tại và `scope = system`;
    mỗi mã **đúng 4 chữ số**; mã **tồn tại trong master** (không tự tạo); **không trùng trong cùng một
    dòng**; một mã **không vừa included vừa excluded** trong cùng hồ sơ. `PolicyMccProfileService::create()`
    ghi tất cả trong **một transaction** connection `creditcard` (hồ sơ + dòng + mã + tài liệu).
11. **Nạp tỷ lệ/bậc hoàn tiền (nếu có căn cứ).** Bổ sung mục theo `slug` template vào
    `database/data/credit_card_mcc_row_benefits.php` (khoá cấp 2 = `category_name` của dòng included),
    rồi chạy `php artisan credit-card:import-mcc-row-benefits` (idempotent). Giới hạn B/C/D/E áp dụng.
12. **Kiểm chứng.** Mở `/thetindung/tra-cuu` → tab Tra cứu MCC, tra một mã trong hồ sơ và đối chiếu kết
    quả (tên chính sách, quan hệ, tỷ lệ cao nhất + điều kiện, nguồn tài liệu/trang).

> Chỉnh sửa hồ sơ: `PATCH /credit-card/mcc/{profile}` qua `UpdatePolicyMccProfileRequest`.
> **Không đổi được `policy_template_id`** sau khi tạo. `syncRows()` xoá hết dòng cũ rồi tạo lại
> (bảng con cascade xoá mã), nên thao tác luôn nhất quán.
> KHÔNG có route xoá hồ sơ (spec chỉ yêu cầu xem/sửa).

---

## PHẦN C — QUY TẮC ƯU TIÊN NGUỒN TÀI LIỆU

1. **Nguồn chính thống duy nhất** = tài liệu/thể lệ chính thức của ngân hàng phát hành thẻ.
2. Thứ tự ưu tiên khi cùng một sản phẩm có nhiều dạng công bố:
   1. PDF/hợp đồng thể lệ chính thức (thường có phụ lục danh mục MCC).
   2. Trang HTML chính thức trên website ngân hàng (ví dụ VIB Max Card công bố dạng trang HTML — bản
      đính kèm là bản in nội dung trang, ghi rõ trong hồ sơ).
   3. File tham chiếu tổng hợp (ví dụ `MCC_reference_Vietnamese.xlsx`) **chỉ** dùng để làm giàu **MCC
      master**, KHÔNG dùng để suy ra tỷ lệ hoàn tiền.
3. **Không** thay danh mục bằng tên đơn vị chấp nhận (ĐVCNT) hay tên danh mục khi tài liệu không công
   bố mã MCC. Khi đó ghi "chưa đủ căn cứ", không tạo mục.
4. Mỗi kết luận phải dẫn nguồn: `source_doc` (mảnh tên file đính kèm) + `source_page` (trang/mục).
5. Khi tài liệu chỉ nêu **nhãn** (ví dụ "tới 20%") mà không có số cụ thể: lưu `rate_label`, để
   `cashback_percent = null` — **không bịa số**.

---

## PHẦN D — QUY TẮC MCC MASTER

- `credit_card_mcc_codes` là **nguồn tham chiếu duy nhất** cho mã + mô tả MCC. Không bảng nào khác
  lưu lại tên/mô tả MCC.
- Khoá tự nhiên `mcc` là **char(4)**, giữ số 0 đứng đầu, UNIQUE.
- `verification_status` mặc định `unverified`; các giá trị hợp lệ: `unverified` / `needs_review` / `verified`.
  Mọi dòng nhập từ file tham chiếu mặc định `unverified`.
- **KHÔNG tự tạo mã master** từ mã admin nhập. Admin nhập mã không có trong master ⇒ form báo lỗi
  ("MCC không tồn tại trong danh mục chuẩn").
- Nạp/cập nhật master **chỉ** qua lệnh `php artisan credit-card:import-mcc {file} [--sheet=]`
  (`MccReferenceImporter`), đọc `.csv/.xlsx/.xls`:
  - Header nhận alias không dấu/khác ngôn ngữ (`ma mcc`, `mo ta`, `ten`, `trang thai xac minh`, ...).
  - Upsert theo `mcc`; **chỉ ghi giá trị non-null** — chạy lại với ô trống không xoá dữ liệu tốt.
  - Mapping chỉ tạo khi xác định được chính sách đích; `fingerprint` đảm bảo idempotent.
  - Quy tắc "mọi MCC trừ danh sách" **phải** lưu dạng `match_type = all_except` + `mcc_codes`; KHÔNG tự
    mở rộng thành danh sách đầy đủ.
- **Mã có dẫn chứng nhưng thiếu trong master** (đã ghi nhận, CHƯA thêm — chỉ bổ sung khi có căn cứ):
  - VIB (One Card & Max Card): `5301, 5298, 5296, 0046`; dải hàng không `3000–3838, 3998, 0070, 0010`.
  - Sacombank Platinum Amex: dải hàng không/khách sạn `3xxx` (đã thêm **4511, 4722, 7011** vào hồ sơ #16;
    phần dải 3xxx **tồn đọng có chủ đích**).
  - Shinhan Supreme: `6381, 6399`.
  - MB JCB Ultimate: `8044, 8082, 7295`.
  - Sacombank Uniq: `9751`.
  - BIDV Cashback Online: `6050, 6532, 6533, 6538`.
  - LPBank JCB Ultimate (hồ sơ #1): `8044, 8082, 6381, 6399, 7511, 6050`.

---

## PHẦN E — QUY TẮC NHẬP DỮ LIỆU HOÀN TIỀN TỪ TÀI LIỆU NGÂN HÀNG

### E.1 Bộ dữ liệu tĩnh

`database/data/credit_card_mcc_row_benefits.php` — mảng khoá theo **slug template**:

```php
'slug-template' => [
    'source_doc'   => 'mảnh tên file tài liệu đính kèm',
    'source_page'  => 'trang/mục mặc định',
    'product_name' => 'Tên sản phẩm đại diện',   // tuỳ chọn, chỉ khi dùng chung nhiều hạng
    'rows' => [
        'category_name' => [
            ['pct' => 15, 'label' => null, 'min' => 8000000, 'max' => null,
             'cap' => 800000, 'cap_period' => 'kỳ sao kê', 'cap_unit' => 'VND',
             'cond' => '...', 'exc' => '...', 'page' => '1'],
        ],
    ],
],
```

Từ khoá bậc: `pct` (10.000 = 10%; null nếu chỉ có nhãn), `label` (nhãn nguyên văn), `min`/`max`
(ngưỡng tổng chi tiêu), `cap`, `cap_period`, `cap_unit` ("VND"/"điểm"), `cond`, `exc`, `page`.

### E.2 Trình nhập

`php artisan credit-card:import-mcc-row-benefits` (tuỳ chọn `--file=`) —
`App\Console\Commands\CreditCardImportMccRowBenefits` gọi `MccRowBenefitImporter`.

Định vị **theo cấu trúc, KHÔNG hard-code id**:

1. Template: tra theo `slug`.
2. Hồ sơ: hồ sơ **đầu tiên** của template đó.
3. Dòng: dòng `included` có `category_name` khớp.
4. Tài liệu nguồn: tài liệu có `original_name` chứa mảnh `source_doc`; fallback = tài liệu đầu tiên.

Nếu `product_name` được khai báo và khác giá trị hiện tại ⇒ ghi vào `..._profiles.product_name`.

### E.3 Idempotent & an toàn

- Upsert bậc theo UNIQUE (`policy_mcc_row_id`, `tier_order`).
- Sau khi nạp, **xoá bậc thừa** (`tier_order >= số bậc mới`) để rút gọn dữ liệu không để lại bậc mồ côi.
- Toàn bộ trong **một transaction** connection `creditcard`.
- Chuẩn hoá: tỷ lệ `number_format($v, 3, '.', '')`; tiền `number_format($v, 2, '.', '')`; chuỗi rỗng → NULL.
- KHÔNG đụng `credit_card_mcc_codes`, blueprint, hay dữ liệu engine.

### E.4 Ranh giới

- Bảng bậc hoàn tiền **chỉ để tra cứu**; nếu muốn engine đọc trực tiếp cần thiết kế riêng (ngoài phạm vi).
- Thống kê lần nạp đầu: 12 hồ sơ / 40 dòng / 95 bậc; chạy lại `0/0/0` (idempotent đã xác nhận).

---

## PHẦN F — QUY TẮC HIỂN THỊ TRA CỨU MCC

Nguồn: `CardPolicyLookupService::lookupMcc()` (CHỈ ĐỌC; không tạo kỳ sao kê/khoông ghi snapshot).

### F.1 Luồng

1. Lấy `MccCode` master theo mã (có thể null).
2. Thu thập kết quả từ `CardPolicyMccMapping` (đã **loại trừ** các template có dòng `excluded` chứa mã —
   loại trừ thắng).
3. Thu thập kết quả từ **hồ sơ MCC admin** (`adminProfileMatches`).
4. Gộp theo sản phẩm (`mergeProductMatches`) — xem Phần G.
5. Sắp theo độ ưu tiên: `direct` → `group` → `excluded` → `broad` → `unverified`, sau đó theo tên chính sách.
6. Gắn tỷ lệ/bậc thực tế (`attachCashback`).

### F.2 Quy tắc quan trọng

- Trong **cùng một chính sách**, mã nằm ở **bất kỳ dòng `excluded`** của bất kỳ hồ sơ nào ⇒ **loại trừ
  thắng**, bỏ mọi dòng `included` của chính sách đó. (Đồng thời loại bỏ mapping import của template đó.)
- **Không suy diễn** "được hoàn tiền" chỉ vì tên danh mục nghe hợp lý. Chỉ dựa trên mapping/dữ liệu đã nhập.
- Mapping `verification_status != verified` hoặc `application_status = unverified` ⇒ nhãn **"Chưa đủ dữ liệu"**.
- `relation_label`: `direct` = "Áp dụng trực tiếp"; `group` = "Thuộc nhóm MCC"; `excluded` = "Bị loại trừ";
  `broad` = "Áp dụng rộng (mọi MCC / trừ danh sách ngoại lệ)"; `broad_except` = "Áp dụng trừ danh sách MCC
  ngoại lệ"; mặc định = "Chưa đủ dữ liệu để xác nhận".

### F.3 Tỷ lệ hoàn tiền của kết quả tra cứu

- **Kết quả từ hồ sơ MCC** (`source = 'policy_mcc_profile'`): đọc từ `credit_card_policy_mcc_row_benefits`
  (`benefitCashback`):
  - `excluded` ⇒ `status = excluded`.
  - Không có bậc ⇒ `status = missing` (`reason = no_data`).
  - Có bậc ⇒ `status = available` + `tiers` + `highest`.
  - `highest` = bậc có `cashback_percent` **lớn nhất**, phá hoà bằng `cap_amount` lớn hơn; nếu mọi bậc
    chỉ có nhãn ⇒ `highest = null` (**không bịa số**).
- **Kết quả từ mapping import** (`attachCashback`): đọc từ **blueprint đang áp dụng** của chính sách
  (`defaultBlueprint` → `credit_card_policy_tier_categories`), khớp theo `category_id`, hoặc theo tên
  (khớp chính xác → tiền tố → chứa). Không khớp ⇒ `status = missing` (`reason = not_found`).
  Bỏ qua (`continue`) các kết quả đến từ hồ sơ MCC để không ghi đè dữ liệu tài liệu.

### F.4 Ghi chú & giao diện

- `lookupNote`: không tìm thấy MCC → "Không tìm thấy MCC này trong dữ liệu hệ thống."; có master nhưng
  chưa mapping → "Chưa có dữ liệu mapping nào..."; có mapping chưa verify → cảnh báo kiểm tra tài liệu gốc.
- `resources/views/credit-card/lookup.blade.php` (tab Tra cứu MCC): khi có `cashback.highest`, hiển thị
  khối nổi bật **"Hoàn tối đa <tỷ lệ>"** kèm **điều kiện** và **trần**; nếu là kết quả sản phẩm dùng chung
  nhiều hạng (`product_name`) thì liệt kê **đủ các bậc**.
- **Dòng hồ sơ không có mã MCC** (hồ sơ mô tả, ví dụ VPBank Super Shopee): cột mã hiển thị nhãn
  **"Chưa có thông tin mã MCC"** thay vì để trống — tránh hiểu nhầm là đã kiểm tra đầy đủ.

---

## PHẦN G — QUY TẮC ĐẶC THÙ VIB MAX CARD VISA & VIB ONE CARD VISA

### G.1 Bối cảnh

- Hai chương trình VIB dùng **chung một tài liệu cho nhiều hạng thẻ**:
  - `VIB Max Card Visa` — hồ sơ **#14** (template chủ **#15** `vib-max-card-visa-starter`);
    template #16–18 (Plus/Pro/Elite) **liên kết** tới hồ sơ #14 qua bảng liên kết (A.6).
  - `VIB One Card Visa` — hồ sơ **#2** (template chủ **#19** `vib-one-card-visa-gold`);
    template #20–21 (Platinum/Signature) **liên kết** tới hồ sơ #2 qua bảng liên kết (A.6).
- Mỗi dòng của hồ sơ mang **đủ bậc của MỌI hạng** (VIB Max: Elite 10% / Pro 8% / Plus 5% /
  Starter 0,5% / ngoài nhóm 0,1%; VIB One: Signature 9% / Platinum 6% / Gold 3% / ngoài DMCT 0,1%).
- Chỉ hai hồ sơ này có `product_name`; mọi hồ sơ khác `product_name = NULL`.

### G.2 Cơ chế gộp (data-driven, không hard-code)

- **Phủ theo hạng (tra cứu thẻ):** nhờ bảng liên kết (A.6), mỗi template hạng #16–18/#20–21 trả về **đúng một**
  hồ sơ chủ (#14/#2) qua `scopeForTemplate()`. **Không** nhân bản dòng/mã/tài liệu.
- `profileMatch()` dùng `product_name` (nếu có) làm `policy_name`, ngược lại giữ tên template như cũ.
- `mergeProductMatches()` gộp mọi kết quả **cùng `product_name`** về **MỘT kết quả đại diện** (tra cứu theo mã MCC).
- `collapseProductGroup()` hợp nhất bậc của mọi hạng, **khử trùng lặp** theo khoá
  `cashback_percent|rate_label|min_total_spend|max_total_spend|cap_amount|note`; nếu nhóm có phần tử
  `excluded` ⇒ kết quả là **loại trừ**.
- Kết quả đại diện giữ `highest` + **điều kiện của chính hạng cao nhất** và vẫn liệt kê đủ bậc
  ⇒ **không làm mất khác biệt giữa các hạng**, không gán một mức chung cho tất cả hạng.

### G.3 Ràng buộc tuyệt đối

- Chỉ áp dụng cho VIB Max Card Visa & VIB One Card Visa; **không** mở rộng cho ngân hàng khác khi chưa có căn cứ.
- **Không** đụng MCC master; **không** đụng engine cashback/ví/sổ cái; **không** đổi tỷ lệ/bậc/điều kiện/trần
  của blueprint; **không** xoá hồ sơ/tài liệu gốc.
- **Không** suy luận quan hệ từ tên hiển thị. VIB dùng **combo danh mục** (tên danh mục ở tầng rule là NULL)
  nên tên danh mục trong hồ sơ **không** dùng để name-match blueprint — tỷ lệ luôn đọc từ bảng bậc đã lưu.

### G.4 Kiểm thử tham chiếu

`tests/Feature/CreditCard/CardLookupMccProfileTest.php` — gồm test cho cơ chế gộp:
`lookup_mcc_groups_shared_product_variants_into_one_representative_result`,
`lookup_mcc_shows_representative_product_name_and_preserves_every_tier_difference`,
`lookup_mcc_keeps_template_name_when_policy_has_no_shared_product`,
`lookup_mcc_keeps_representative_product_name_for_excluded_rows`;
và **3 test mới cho bảng liên kết dùng chung**:
`policy_lookup_returns_a_shared_profile_for_every_variant_template`,
`mcc_lookup_returns_one_result_for_a_shared_profile_across_linked_templates`,
`shared_profile_exclusion_applies_to_linked_templates`.
Chạy: `php artisan test --filter "CardLookupTest|CardLookupMccProfileTest"`.

---

## PHẦN H — CÁC LỖI ĐÃ GẶP VÀ CÁCH XỬ LÝ

> Tổng hợp từ `docs/CREDIT_CARD_MCC_PROFILES_REPORT.md`,
> `docs/CREDIT_CARD_MCC_ROW_BENEFITS_REPORT.md`, `docs/CREDIT_CARD_VIB_MAX_CARD_VISA_REPORT.md`,
> `docs/CREDIT_CARD_MCC_VIB_PRODUCT_GROUPING_REPORT.md` (đề bài gốc bị cắt ở tiêu đề Phần H).

1. **Tên và tỷ lệ lệch nhau (VIB).** Hồ sơ #14/#2 hiển thị **tên hạng thấp** (`... - Starter`,
   `VIB One Card Gold`) nhưng con số lại của **hạng cao** (10% Elite / 9% Signature).
   *Xử lý:* thêm cột `product_name` + `mergeProductMatches()`/`collapseProductGroup()`/`highestTier()`
   → hiển thị **tên sản phẩm đại diện** kèm đủ bậc và điều kiện của từng hạng.
2. **MCC có trong tài liệu nhưng thiếu trong master.** *Xử lý:* KHÔNG thêm vào hồ sơ, KHÔNG tự tạo master;
   ghi nhận danh sách "thiếu trong master" để bổ sung sau khi có căn cứ (xem Phần D).
3. **Tên danh mục hồ sơ không khớp blueprint (VIB dùng combo).** *Xử lý:* tra cứu đọc từ bảng
   `credit_card_policy_mcc_row_benefits` đã lưu, **không** name-match blueprint; `attachCashback` bỏ qua
   kết quả hồ sơ MCC để không ghi đè.
4. **Chỉ có tên ĐVCNT/danh mục, không có mã MCC** (VPBank Super Shopee, template #7). *Xử lý (cập nhật):*
   tạo **hồ sơ MÔ TẢ** — dòng included/excluded **không gắn mã**, kèm tài liệu gốc và note rõ "ngân hàng không
   công bố mã MCC"; **KHÔNG** suy đoán/gán mã. Tab "Tra cứu thẻ" hiển thị nhãn **"Chưa có thông tin mã MCC"**
   cho dòng không mã (xem báo cáo kiểm toán Phần K).
5. **PDF giải nén báo "lỗi font".** *Xử lý:* chỉ do codepage console Windows; bản PDF gốc hợp lệ, đã đọc
   đúng Phụ lục (ví dụ Shinhan Phụ lục 1).
6. **Bậc thừa khi rút gọn dữ liệu.** *Xử lý:* trình nhập xoá bậc có `tier_order >=` số bậc mới
   (idempotent khi chạy lại).
7. **Mã vừa included vừa excluded trong cùng hồ sơ.** *Xử lý:* chặn ở cả validate form
   (`ValidatesMccProfileRows`) lẫn service (`syncRows()` ném `InvalidArgumentException`).
8. **Hồ sơ chỉ có loại trừ, 0 dòng included** (BIDV #11, SeABank #12). *Xử lý:* không phải lỗi — không có
   gì để nạp bậc; hồ sơ vẫn hợp lệ cho tra cứu loại trừ.
9. **Test full-suite lỗi phụ thuộc thứ tự** —
   `ShinhanSupremePolicyTemplateTest::seeding_keeps_the_builtin_template_untouched` (kỳ vọng số template
   hệ thống khác nhau; PASS khi chạy riêng). *Xử lý:* thuộc luồng công việc khác đang mở rộng
   `CreditCardSeeder` — **không sửa**.
10. **Test nền đã lỗi sẵn** — `CreditCardModuleTest::credit_card_routes_do_not_override_existing_routes`
    (route `credit-cards.api.policies.history` chưa có trong danh sách chốt, từ nhánh chuẩn hóa
    danh mục/combo đang dang dở). *Xử lý:* **không sửa** để tránh đụng nhánh khác.

---

## PHẦN I — PHÍ THƯỜNG NIÊN CỦA HỒ SƠ MCC

Mỗi hồ sơ MCC mang thêm 3 trường **phí thường niên** trên `credit_card_policy_mcc_profiles`
(nullable, `decimal(16,2)`):

| Trường | Ý nghĩa |
|---|---|
| `annual_fee_primary` | Phí thường niên **thẻ chính** (VND/năm). |
| `annual_fee_supplementary` | Phí thường niên **thẻ phụ** (VND/năm). |
| `annual_spend_for_annual_fee_waiver` | Tổng **chi tiêu/năm** để được miễn/hoàn phí thường niên. |

### I.1 Giao diện

- Admin nhập ở form hồ sơ (khối "Phí thường niên") trên cả màn **tạo** và **sửa**
  (`resources/views/components/mcc-profile-form.blade.php`).
- Tab **Tra cứu MCC** (`/thetindung/tra-cuu/mcc`) luôn hiển thị khối "Phí thường niên"; trường
  trống hiển thị **"Chưa cập nhật"** — **không bao giờ** hiện `0 đ` (dùng `ccMoneyVnd` +
  `/năm`, xem `resources/views/credit-card/partials/money-js.blade.php`).

### I.2 Quy tắc nghiên cứu (bắt buộc)

1. **Chỉ ghi khi có nguồn chính thức** đúng **sản phẩm + hạng** của ngân hàng phát hành.
2. **KHÔNG suy luận**, **KHÔNG sao chép** giá trị giữa các hồ sơ; **KHÔNG coi "thiếu thông tin"
   là "miễn phí"**.
3. **KHÔNG điền `0`** — kể cả khi ngân hàng ghi "Miễn phí". Trường không xác minh để **NULL** và
   ghi rõ lý do trong báo cáo (quy ước chung với Phần C).
4. **Liệt kê riêng từng hạng/bậc phí — KHÔNG gộp.** Hồ sơ dùng chung nhiều hạng có phí **khác
   nhau** (VIB One Card #2: 3 hạng; VIB Max Card #14: 4 hạng). Schema hiện tại chỉ có **1 bộ 3
   trường/hồ sơ** ⇒ **KHÔNG ghi một mức chung**; để NULL, ghi lý do "phí phụ thuộc hạng", và
   **đề xuất cách biểu diễn** (xem I.4).
5. **Phân biệt loại phí:** phí thường niên **thẻ chính** vs **thẻ phụ**; **phí gói/thành viên**
   (membership/package fee — ví dụ gói Cake 2in1 Premium, gói hạng của VIB Max) là loại phí KHÁC,
   **không** ghi vào 3 trường phí thường niên.
6. **Tách "năm đầu" vs "các năm tiếp theo"**, và phân biệt **mức phí chuẩn** vs **ưu đãi có thời
   hạn** (promo). Giá trị ghi vào DB là **mức chuẩn**; ưu đãi thời hạn chỉ ghi trong báo cáo.
7. Ghi **nguồn (URL) + ngày hiệu lực** trong báo cáo nghiên cứu.
8. Thứ tự ưu tiên nguồn: (1) **biểu phí chính thức** của ngân hàng (PDF/HTML, thường tên
   "Biểu phí thẻ tín dụng"); (2) trang **sản phẩm chính thức**. Nguồn thứ cấp (sosanhthe, rcgv,
   fingo, topbank…) **chỉ tham khảo**, KHÔNG dùng để ghi giá trị.
9. ⚠️ `shinhanfinance.com.vn` là **Shinhan Finance (SVFC)** — KHÁC Shinhan Bank Việt Nam; KHÔNG
   dùng biểu phí đó cho thẻ của Shinhan Bank (`shinhan.com.vn`).
10. ⚠️ Bảng phí PDF nhiều cột: trích xuất văn bản có thể **lệch cột** ⇒ phải đối chiếu toạ độ
    x/y hoặc mở trực tiếp bảng trước khi ghi; số liệu "đáng ngờ" để NULL (xem I.4).

### I.3 Trạng thái (cập nhật 2026-10-13, đợt I.1)

- **20/27 hồ sơ** có ≥1 giá trị phí (nguồn chính thức, chỉ ghi trường đã kiểm chứng).
- Đợt **I.1** bổ sung **thẻ phụ / ngưỡng miễn phí** cho nhóm VPBank (`#5` StepUp, `#6` Lady,
  `#7` Z Card, `#23` S Rewards) từ **biểu phí chính thức hiệu lực 22/06/2026**.
- **7 hồ sơ để NULL toàn bộ**: `#2` (VIB One Card — 3 hạng), `#4` (MB JCB Ultimate — chưa có số
  chính thức), `#14` (VIB Max Card — 4 hạng), `#17` (MB JCB Platinum — chỉ có nguồn thứ cấp),
  `#19` (VPBank Flex — "miễn phí", NULL theo luật không-điền-0), `#22` (Thẻ Cake 2in1 gói
  Premium — phí gói, không phải phí thường niên cố định), `#25` (TPBank EVO — "miễn phí", NULL).
- Chi tiết từng hồ sơ (giá trị + nguồn + ngày hiệu lực) xem báo cáo nghiên cứu phí thường niên.

### I.4 Hạng/bậc phí & đề xuất biểu diễn

Hai hồ sơ gộp nhiều hạng có **mức phí khác nhau theo hạng** nhưng schema hiện tại chỉ có **1 bộ
3 trường/hồ sơ**, nên **không thể** biểu diễn đúng:

- `#2` **VIB One Card** (3 hạng): thẻ chính Gold **999.000** / Platinum **1.999.000** /
  Signature **2.999.000**; thẻ phụ Gold **499.000** / Platinum **999.000** / Signature
  **1.499.000**.
- `#14` **VIB Max Card** (4 hạng): Starter **miễn phí**; Plus/Pro/Elite phí thường niên
  **5.000 VNĐ/tháng (~60.000/năm)** cho thẻ chính & thẻ phụ; kèm **phí gói thành viên** riêng
  (Plus 300.000 / Pro 500.000 / Elite 1.000.000 — mỗi 3 kỳ sao kê).

**Đề xuất (chờ duyệt, chưa thực hiện):** thêm bảng con
`credit_card_policy_mcc_fee_tiers(profile_id, tier_name, credit_limit_min, credit_limit_max,
annual_fee_primary, annual_fee_supplementary, annual_spend_for_annual_fee_waiver,
membership_fee, membership_period_months, effective_date)` và **giữ nguyên 3 cột** hiện tại cho
hồ sơ **một hạng** (tương thích ngược, không phá tra cứu). Không đổi schema trong đợt I.1.

---

## PHẦN J — TAB "TRA CỨU DANH MỤC" (tra theo NHÓM ngành, chỉ đọc)

Tab thứ ba của màn tra cứu: người dùng **chọn một danh mục** rồi xem **mọi thẻ có hoàn tiền
cho danh mục đó**. Dữ liệu **100% tái sử dụng** từ các dòng `included` đã nhập — **không tạo
bảng danh mục mới**, **không đổi tên gốc** trong hồ sơ.

- Giao diện: tab thứ ba trong `resources/views/credit-card/lookup.blade.php`.
- Endpoint dữ liệu: `GET /thetindung/tra-cuu/danh-muc` — route `credit-cards.lookup.category`,
  controller `CreditCardLookupController::category()`.
- Service: `CardPolicyLookupService::lookupCategory()` (CHỈ ĐỌC, không tạo kỳ sao kê/snapshot).

### J.1 Nguồn chuẩn hoá — `config/credit_card_mcc_categories.php`

| Khoá | Ý nghĩa |
|---|---|
| `groups` | Danh sách **danh mục chuẩn** `key => nhãn tiếng Việt`; **thứ tự trong mảng = thứ tự hiển thị** trong dropdown. |
| `aliases` | Bản đồ **tên gốc (đã chuẩn hoá tại thời điểm chạy) => một khoá chuẩn HOẶC mảng khoá chuẩn**. |

- Tên gốc **không map** trong `aliases` vẫn xuất hiện, giữ **riêng** một lựa chọn với khoá
  `raw:<tên-đã-chuẩn-hoá>` — **KHÔNG tự gộp** hai danh mục chỉ vì tên "na ná".
- Một tên gốc có thể ánh xạ **nhiều** khoá chuẩn (dòng mô tả combo, ví dụ
  "Giao dịch ăn uống, siêu thị (…), Grab/Be" ⇒ `[food, supermarket, transport]`), nên nó xuất
  hiện ở **nhiều** danh mục — đúng bản chất dòng gộp.
- Các dòng **đặc thù/không phải ngành** (ví dụ "Chi tiêu quốc tế", "Chi tiêu trực tuyến nước
  ngoài", "Giao dịch có lưu thông tin Thẻ (card-on-file)", "Hoàn tiền cố định",
  "Nhóm ưu đãi được chọn (mặc định: Ăn uống)") **cố ý để `raw:`** — không gán bừa vào danh mục
  ngành nào.

### J.2 Chuẩn hoá & gom nhóm

- `normalizeCategoryKey()`: `Str::lower` → `Str::ascii` (bỏ dấu) → thay mọi ký tự không phải
  `[a-z0-9]` bằng dấu cách → gộp khoảng trắng. **Không bao giờ sửa tên gốc trong DB.**
- `categoryKeysFor($name)`: có trong `aliases` ⇒ trả **các khoá chuẩn**; ngược lại trả
  `['raw:'.$normalized]`.
- `categoryOptions()`: quét **mọi dòng `included`** của mọi hồ sơ để dựng dropdown ⇒ **tự động
  cập nhật** khi thêm hồ sơ/dòng mới, **không hard-code** ngân hàng/thẻ. Sắp theo thứ tự `groups`,
  rồi theo nhãn.

### J.3 Tra cứu (`lookupCategory()`)

1. Chỉ xét dòng **`included`**; dòng **`excluded` KHÔNG tạo kết quả** (loại trừ không chứng minh
   có hoàn tiền — quy tắc chung Phần F).
2. Một hồ sơ có **nhiều dòng cùng danh mục** ⇒ gộp về **MỘT kết quả** (không nhân bản thẻ):
   - Hợp nhất **mã MCC** (khử trùng lặp, sắp tăng dần).
   - Hợp nhất **bậc/tỷ lệ**, khử trùng lặp theo khoá
     `cashback_percent|rate_label|min_total_spend|max_total_spend|cap_amount|note` ⇒ **giữ mọi
     khác biệt bậc**, không gán một mức chung.
3. Chương trình **dùng chung nhiều hạng** (`product_name`, xem Phần G) tiếp tục gộp một kết quả
   qua `mergeProductMatches()`.
4. **Dòng mô tả không có mã MCC**: `descriptive_note` = ghi chú hồ sơ nếu có, ngược lại nhãn
   "Quyền lợi theo mô tả của ngân hàng — chưa có danh sách mã MCC cụ thể." (không hiểu nhầm là đã
   kiểm tra đủ mã).
5. Kết quả: `relation = 'group'`, `match_type = 'category'`, kèm `cashback` (đọc từ
   `credit_card_policy_mcc_row_benefits` như F.3), `documents` tải được, danh sách mã, và
   `matched_names` (tên gốc đã khớp — phục vụ truy vết). Rỗng ⇒ `found = false`.

### J.4 Giao diện & tái sử dụng

- Khối kết quả chia sẻ partial `resources/views/credit-card/partials/mcc-match.blade.php`
  (tham số `$matches` — biểu thức Alpine, `$codesLabel`). Tab MCC dùng nhãn `Mã trong nhóm`;
  tab danh mục dùng nhãn `Mã MCC liên quan`.
- Tiêu đề kết quả **"Các thẻ hoàn tiền (n)"**; ghi chú thao tác **"Chỉ tra cứu — không thay đổi
  dữ liệu."**; nhãn dòng mô tả hiển thị qua `descriptive_note`.
- Tiền/trần/phí dùng `ccMoneyVnd`, trường trống = **"Chưa cập nhật"** (không `0 đ`).

### J.5 Kiểm thử tham chiếu

`tests/Feature/CreditCard/CardLookupCategoryTest.php` (17 test): dựng dropdown từ dòng included,
bỏ qua excluded, gom nhóm chuẩn hoá, giữ tên lạ dưới `raw:`, tra cứu trả thẻ included, loại
excluded-only, một thẻ một kết quả, giữ đủ bậc, gộp chương trình dùng chung, trạng thái rỗng,
validate 422, dòng theo kênh không lọt danh mục khác, dòng mô tả được gắn cờ, tài liệu tải được.
Chạy: `php artisan test --filter CardLookupCategoryTest`.

---

## PHỤ LỤC — LỆNH & ĐƯỜNG DẪN NHANH

| Mục đích | Lệnh / đường dẫn |
|---|---|
| Áp dụng migration (connection `creditcard`) | `php artisan credit-card:migrate` (đường chạy DUY NHẤT; `php artisan migrate` KHÔNG chạy thư mục con) |
| Nạp MCC master + mapping | `php artisan credit-card:import-mcc {file} [--sheet=]` |
| Nạp bậc hoàn tiền theo dòng | `php artisan credit-card:import-mcc-row-benefits [--file=]` |
| Admin quản lý MCC | `/credit-card/mcc` (index/create/show/edit) |
| Tải tài liệu ngân hàng | `GET /credit-card/mcc/{profile}/documents/{document}` |
| Trang tra cứu | `/thetindung/tra-cuu` (tab MCC: `/thetindung/tra-cuu/mcc`; tab danh mục: `/thetindung/tra-cuu/danh-muc`) |
| Danh mục chuẩn & alias | `config/credit_card_mcc_categories.php` |
| Bộ dữ liệu bậc hoàn tiền | `database/data/credit_card_mcc_row_benefits.php` |
| Service ghi hồ sơ | `app/Services/CreditCard/PolicyMccProfileService.php` |
| Service tra cứu | `app/Services/CreditCard/CardPolicyLookupService.php` |
| Trình nhập bậc | `app/Services/CreditCard/Import/MccRowBenefitImporter.php` |
| Trình nhập master | `app/Services/CreditCard/Import/MccReferenceImporter.php` |
| Controller admin | `app/Http/Controllers/Admin/CreditCard/PolicyMccAdminController.php` |
| Controller tra cứu | `app/Http/Controllers/CreditCard/CreditCardLookupController.php` |

### Ranh giới bắt buộc khi vận hành module MCC

- KHÔNG ghi vào blueprint (`credit_card_policy_*`); KHÔNG đụng engine cashback/ví/sổ cái.
- KHÔNG tự tạo MCC master; KHÔNG liên kết `category_name` với `credit_card_categories`.
- KHÔNG xoá/sửa tài liệu gốc hay dữ liệu bậc đã có khi không có căn cứ.
- KHÔNG suy diễn quan hệ/tỷ lệ từ tên hiển thị hoặc tên danh mục.
- Danh mục tra cứu (Phần J) chỉ gom theo `config/credit_card_mcc_categories.php`; tên chưa chắc
  chắn ⇒ giữ riêng `raw:` (không tự gộp); dòng `excluded` không bao giờ tạo kết quả.
