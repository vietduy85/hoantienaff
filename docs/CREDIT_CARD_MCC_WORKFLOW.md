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
        ├── (n) credit_card_policy_mcc_rows     [row_type: included|excluded, category_name, note]
        │      ├── (n) credit_card_policy_mcc_row_codes   [mỗi mã MCC 1 bản ghi → mcc_code_id, mcc]
        │      └── (n) credit_card_policy_mcc_row_benefits [bậc hoàn tiền trích tài liệu; tier_order]
        └── (n) credit_card_policy_mcc_documents [tài liệu ngân hàng, disk private]

credit_card_mcc_codes (master MCC)  ←── mcc (natural key, char 4)
```

### A.3 Vai trò từng bảng / model

- **`PolicyMccProfile`** (`app/Models/CreditCard/PolicyMccProfile.php`) — "hồ sơ MCC chính sách".
  Trường: `policy_template_id`, `name` (tên riêng của hồ sơ, KHÔNG đổi tên template), `note`,
  `product_name` (nullable — tên **sản phẩm đại diện** khi một chương trình dùng chung tài liệu cho
  nhiều hạng thẻ; chỉ VIB khai báo). Quan hệ: `template()`, `rows()`, `includedRows()`,
  `excludedRows()`, `documents()`.
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

---

## PHẦN G — QUY TẮC ĐẶC THÙ VIB MAX CARD VISA & VIB ONE CARD VISA

### G.1 Bối cảnh

- Hai chương trình VIB dùng **chung một tài liệu cho nhiều hạng thẻ**:
  - `VIB Max Card Visa` — hồ sơ **#14** → template **#15** (`vib-max-card-visa-starter`),
    template #16–18 (Plus/Pro/Elite) **dùng chung** hồ sơ #14.
  - `VIB One Card Visa` — hồ sơ **#2** → template **#19** (`vib-one-card-visa-gold`),
    template #20–21 (Platinum/Signature) **dùng chung** hồ sơ #2.
- Mỗi dòng của hồ sơ mang **đủ bậc của MỌI hạng** (VIB Max: Elite 10% / Pro 8% / Plus 5% /
  Starter 0,5% / ngoài nhóm 0,1%; VIB One: Signature 9% / Platinum 6% / Gold 3% / ngoài DMCT 0,1%).
- Chỉ hai hồ sơ này có `product_name`; mọi hồ sơ khác `product_name = NULL`.

### G.2 Cơ chế gộp (data-driven, không hard-code)

- `profileMatch()` dùng `product_name` (nếu có) làm `policy_name`, ngược lại giữ tên template như cũ.
- `mergeProductMatches()` gộp mọi kết quả **cùng `product_name`** về **MỘT kết quả đại diện**.
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

`tests/Feature/CreditCard/CardLookupMccProfileTest.php` (12 passed / 52 assertions), gồm 4 test cho cơ chế gộp:
`lookup_mcc_groups_shared_product_variants_into_one_representative_result`,
`lookup_mcc_shows_representative_product_name_and_preserves_every_tier_difference`,
`lookup_mcc_keeps_template_name_when_policy_has_no_shared_product`,
`lookup_mcc_keeps_representative_product_name_for_excluded_rows`.

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
4. **Chỉ có tên ĐVCNT/danh mục, không có mã MCC** (VPBank Super Shopee, template #7). *Xử lý:*
   "chưa đủ căn cứ" ⇒ không tạo hồ sơ, không suy đoán MCC.
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

## PHỤ LỤC — LỆNH & ĐƯỜNG DẪN NHANH

| Mục đích | Lệnh / đường dẫn |
|---|---|
| Áp dụng migration (connection `creditcard`) | `php artisan migrate --database=creditcard --path=database/migrations/creditcard` |
| Nạp MCC master + mapping | `php artisan credit-card:import-mcc {file} [--sheet=]` |
| Nạp bậc hoàn tiền theo dòng | `php artisan credit-card:import-mcc-row-benefits [--file=]` |
| Admin quản lý MCC | `/credit-card/mcc` (index/create/show/edit) |
| Tải tài liệu ngân hàng | `GET /credit-card/mcc/{profile}/documents/{document}` |
| Trang tra cứu | `/thetindung/tra-cuu` (tab MCC: `/thetindung/tra-cuu/mcc`) |
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
