# Báo cáo — Gộp kết quả Tra cứu MCC cho VIB Max Card Visa & VIB One Card Visa

Ngày thực hiện: 2026-10-13
Phạm vi: module **Tra cứu MCC** (`/thetindung/tra-cuu`) — chỉ hai dòng sản phẩm
**VIB Max Card Visa** và **VIB One Card Visa**.

---

## 1. Mục tiêu

Khi tra cứu một mã MCC, hai chương trình VIB dùng chung tài liệu cho nhiều hạng thẻ
phải hiển thị **MỘT kết quả đại diện theo sản phẩm**:

- `VIB Max Card Visa` (thay cho việc hiển thị tên hạng `… - Starter`/`- Plus`/`- Pro`/`- Elite`).
- `VIB One Card Visa` (thay cho `VIB One Card Gold`/`Platinum`/`Signature`).

Không được hiển thị tỷ lệ của hạng cao (Elite/Signature) dưới tên hạng thấp; không
gộp các hạng thành một mức chung; không bịa quan hệ từ tên hiển thị; không đụng các
ngân hàng khác.

## 2. Nguyên nhân (chẩn đoán)

- Mỗi sản phẩm VIB chỉ có **MỘT hồ sơ MCC** dùng chung, gắn vào **template vận chuyển**
  là một hạng:
  - Hồ sơ `#14` → template `#15` (`VIB Max Card Visa - Starter`), tài liệu `vib_max_card.pdf`.
  - Hồ sơ `#2` → template `#19` (`VIB One Card Gold`), tài liệu mục `2.12`.
- Các **dòng** của hồ sơ mang đầy đủ **bậc hoàn tiền của MỌI hạng** (VIB Max: Elite 10% /
  Pro 8% / Plus 5% / Starter 0,5% / ngoài nhóm 0,1%; VIB One: Signature 9% / Platinum 6% /
  Gold 3% / ngoài DMCT 0,1%).
- Trong `CardPolicyLookupService::profileMatch()`, trường `policy_name` được đặt bằng
  **tên template vận chuyển** (`$template->name`), còn `benefitCashback()` chọn **bậc có
  tỷ lệ số cao nhất**.
- Kết quả: tiêu đề hiển thị tên hạng thấp (`VIB Max Card Visa - Starter`) nhưng con số
  lại là của hạng cao (`10%` = Elite). Đây chính là lỗi "tên và tỷ lệ lệch nhau".
- Với VIB One: tiêu đề `VIB One Card Gold` nhưng số `9%` (Signature).

> Ghi chú: cơ sở dữ liệu hiện tại **không** tạo sẵn 4 kết quả trùng cho mỗi sản phẩm
> (mỗi sản phẩm chỉ có 1 hồ sơ và 0 `CardPolicyMccMapping`). Cơ chế gộp vẫn được cài đặt
> để nếu sau này có nhiều hồ sơ cùng sản phẩm thì tự động về một kết quả.

## 3. Cơ sở gộp (data-driven, không hardcode)

- Thêm cột **`product_name`** (nullable) vào bảng `credit_card_policy_mcc_profiles`:
  tên **sản phẩm đại diện** do dữ liệu khai báo, KHÔNG suy diễn từ tên template.
- Chỉ hai hồ sơ VIB được khai báo (qua `database/data/credit_card_mcc_row_benefits.php`,
  trình nhập idempotent ghi vào DB):
  - `vib-max-card-visa-starter` → `VIB Max Card Visa`
  - `vib-one-card-visa-gold` → `VIB One Card Visa`
- `profileMatch()` dùng `product_name` (nếu có) làm `policy_name`; nếu không có thì giữ
  nguyên hành vi cũ (tên template).
- `mergeProductMatches()` gộp mọi kết quả có **cùng `product_name`** về một kết quả:
  - Hợp nhất toàn bộ bậc của mọi hạng (khử trùng lặp).
  - Giữ tỷ lệ cao nhất + **điều kiện của chính hạng đó**.
  - Nếu có dòng loại trừ trong nhóm → kết quả là "loại trừ".

## 4. Trước / Sau

Tra cứu ví dụ `lookupMcc('4511')` (Du lịch):

| | Trước | Sau |
|---|---|---|
| Tên kết quả VIB Max | `VIB Max Card Visa - Starter` | `VIB Max Card Visa` |
| Tỷ lệ hiển thị VIB Max | `10%` (Elite) dưới tên Starter | `Hoàn tối đa 10%` + điều kiện `Gói Elite…`, kèm danh sách đủ 5 bậc |
| Tên kết quả VIB One | `VIB One Card Gold` | `VIB One Card Visa` |
| Tỷ lệ hiển thị VIB One | `9%` (Signature) dưới tên Gold | `Hoàn tối đa 9%` + điều kiện `Hạng Signature…`, kèm đủ 4 bậc |
| Số kết quả cho mỗi sản phẩm | 1 | 1 (gộp nếu có nhiều hồ sơ) |

Các ngân hàng khác: **không đổi** (`product_name = NULL`, vẫn dùng tên template như cũ).
Kiểm chứng: hồ sơ `#1,3–13` đều `product_name = NULL`; chỉ `#2` và `#14` được đặt.

## 5. Bảo toàn khác biệt theo hạng

- Kết quả đại diện vẫn liệt kê **đầy đủ bậc** của mọi hạng (VIB Max 5 bậc; VIB One 4 bậc).
- Dòng "Hoàn tối đa" luôn đi kèm **điều kiện nêu rõ hạng** (`Gói Elite…`, `Hạng Signature…`),
  nên giao diện **không** khẳng định một mức áp dụng chung cho tất cả hạng.
- Không xoá/không sửa tỷ lệ, trần, điều kiện, hay tài liệu gốc của bất kỳ hạng nào.

## 6. Thay đổi

**Mã nguồn / dữ liệu (thuộc task này):**

- `database/migrations/creditcard/2026_10_13_000004_add_product_name_to_credit_card_policy_mcc_profiles_table.php` (mới) — thêm cột `product_name` nullable.
- `app/Models/CreditCard/PolicyMccProfile.php` — `product_name` vào `$fillable` + property.
- `database/data/credit_card_mcc_row_benefits.php` — thêm `product_name` cho 2 mục VIB + mô tả header.
- `app/Services/CreditCard/Import/MccRowBenefitImporter.php` — ghi `product_name` vào hồ sơ (idempotent).
- `app/Services/CreditCard/CardPolicyLookupService.php` — `profileMatch()` dùng tên sản phẩm; thêm `mergeProductMatches()` / `collapseProductGroup()` / `highestTier()`.
- `resources/views/credit-card/lookup.blade.php` — hiển thị đủ bậc khi kết quả là sản phẩm dùng chung.
- `tests/Feature/CreditCard/CardLookupMccProfileTest.php` — thêm 4 test cho cơ chế gộp.

**Dữ liệu đã nạp (DB `creditcard`, môi trường dev):**

- Đã chạy migration thêm cột `product_name`.
- Đã chạy `php artisan credit-card:import-mcc-row-benefits`: `12 hồ sơ / 40 dòng`
  (0 tạo mới / 0 cập nhật / 0 gỡ bỏ bậc — idempotent). Chỉ ghi `product_name` cho 2 hồ sơ VIB.

**KHÔNG đụng tới:** master `credit_card_mcc_codes`; engine cashback / ví / sổ cái; blueprint
(tỷ lệ/bậc/điều kiện/trần); các hồ sơ MCC khác; tài liệu gốc; `.env`. Không xoá dữ liệu gốc
hay dữ liệu các hạng.

> Lưu ý: file `database/seeders/CreditCardSeeder.php` đang được sửa ở luồng công việc khác
> (không thuộc task này) — giữ nguyên, không chỉnh sửa.

## 7. Kiểm thử & lint

- `php -l` toàn bộ file PHP thay đổi: đạt.
- Pint trên các file thay đổi: **passed**.
- `tests/Feature/CreditCard/CardLookupMccProfileTest.php`: **12 passed (52 assertions)**.
- Nhóm test liên quan (Lookup, Import, VIB Max/One, Admin MCC):
  **73 passed (888 assertions)**.
- Toàn bộ `tests/Feature/CreditCard`: **1365 passed**; 1 lỗi **không liên quan** ở
  `ShinhanSupremePolicyTemplateTest::seeding_keeps_the_builtin_template_untouched`
  (thuộc luồng công việc khác đang mở rộng `CreditCardSeeder` — test này PASS khi chạy riêng,
  lỗi chỉ xuất hiện khi chạy cả suite do phụ thuộc thứ tự; không liên quan thay đổi này).

## 8. Xác nhận

- **Không** commit, **không** push, **không** deploy.
- Chỉ thay đổi phạm vi hai dòng VIB Max Card Visa & VIB One Card Visa.
