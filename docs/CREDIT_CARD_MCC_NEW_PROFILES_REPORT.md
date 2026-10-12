# Báo cáo tạo Hồ sơ MCC cho 8 chính sách thẻ tín dụng mới

Ngày thực hiện: 2026-10-12
Phạm vi: Tạo hồ sơ MCC (profiles + dòng MCC + mã + tài liệu + bậc hoàn tiền) cho 8 template mới (ID 22–29) trong CSDL `hoantien_creditcard` (connection `creditcard`).

---

## 1. Tổng quan kết quả

- Phân loại: cả 8 template (22–29) đều **Nhóm A** (chính sách mới, chưa có hồ sơ MCC).
- **8 hồ sơ MCC mới** đã tạo (profile ID 15–22).
- **34 dòng MCC** (gồm cả `included` và `excluded`), **127 mã MCC**.
- **27 bậc hoàn tiền** đã import (bảng `credit_card_policy_mcc_row_benefits`).
- **11 tài liệu nguồn** đã gắn vào vùng lưu trữ riêng (`storage/app/private/credit-card-mcc/<id>/`).
- File thay đổi trong mã nguồn: **duy nhất** `database/data/credit_card_mcc_row_benefits.php` (thêm 8 mục mới).
- Kiểm thử: `55 passed (924 assertions)` trên 5 file test MCC liên quan.
- Pint: `passed`. `php -l`: không lỗi.

---

## 2. Bảng tổng hợp

| Ngân hàng | Tên thẻ / chính sách | Policy ID | Hồ sơ MCC ID | MCC áp dụng | MCC loại trừ | Bậc hoàn tiền đã import | Tài liệu | Kết quả |
|---|---|---|---|---|---|---|---|---|
| Sacombank | Visa Bạch Kim Hoàn Tiền | 22 | 15 | 5411, 5499, 5722, 4121, 4131, 4789, 5969 | 6211; 7800, 7801, 7802, 7995, 9406; 4829, 6010, 6011, 6051; 5094, 5944; 5542; 4814, 4816, 4899, 4900, 5541, 5816, 5960, 5965, 5968, 6513, 7311, 7399, 8999, 9311, 9399, 9402 | 2 (6,8% / 16,8%) | sacombank_platinum_cashback.pdf | Thành công |
| Sacombank | Platinum American Express | 23 | 16 | (dải 3000–3836 — **thiếu trong master**) | 6211; 7800, 7801, 7802, 7995, 9406; 5944; 4900 | 2 (20% / 10%) | sacombank_amex_tnc.pdf, sacombank_amex_mcc.pdf | Thành công (có cảnh báo master) |
| MB | JCB Platinum | 24 | 17 | 5399 (TikTok Shop); Shopee theo merchant_name | 4900, 5462, 5811, 5812, 5813, 5814 | 2 (10% / 10%) | mb_jcb_platinum.pdf | Thành công (có xung đột seeder) |
| UOB | One | 25 | 18 | 4121, 4789; 4899, 5815, 5816, 5817, 5818, 5968; 5960, 6300; 5094, 5137, 5139, 5311, 5611, 5621, 5631, 5641, 5651, 5655, 5661, 5681, 5691, 5697, 5698, 5699, 5941, 5944, 5948, 5949, 7251, 7296, 7631 | 4900, 5541, 5542 | 4 (10% / 5% / 3% / 2%) | uob_one_cashback.pdf | Thành công |
| VPBank | Flex Mastercard | 26 | 19 | 5441, 5451, 5462, 5811, 5812, 5814 (gói Ăn uống mặc định) | 4812, 4814, 4816, 4899, 4900, 5172, 5271, 5541, 5542, 5983, 6011 | 1 (15%) | vpbank_loyalty_q1_2026.pdf | Thành công (có xung đột seeder) |
| Shinhan | 365 Cashback Platinum | 27 | 20 | 5411 | — | 3 (10%, 12% / 3%) | shinhan_365_tnc.pdf | Thành công |
| Shinhan | MyLife Visa – Bạch Kim | 28 | 21 | 4121; 5411, 5451, 5499; 5812, 5813, 5814; 4899, 5815, 5817, 5818, 7832 | — | 4 (10% mỗi danh mục) | shinhan_mylife_tnc.pdf | Thành công |
| Cake (VPBank) | Cake 2in1 – Gói Premium | 29 | 22 | 8211, 8220; 5722, 5732; 4511, 4722, 7011 | 4111, 4900, 5541 (không tính chi tiêu tối thiểu) | 9 (20% × 3 hạng) | cake_premium_mcc.pdf | Thành công |

---

## 3. Chính sách mới đã phát hiện

- 8 template mới: 22 `sacombank-visa-platinum-cashback`, 23 `sacombank-platinum-amex`, 24 `mb-jcb-platinum`, 25 `uob-one`, 26 `vpbank-flex`, 27 `shinhan-365-cashback-platinum`, 28 `shinhan-mylife-visa-bach-kim`, 29 `cake-2in1-premium`.
- Tất cả đều **chưa có hồ sơ MCC** trước khi thực hiện → Nhóm A.
- Riêng template 29 (Cake Premium) **dùng chung tài liệu** với template 10 (`vpbank-cake-freedom-visa`, profile #10) nhưng **không đặt `product_name`** để tránh gộp sai tỷ lệ/trần giữa cơ chế Freedom và Premium → tạo hồ sơ riêng, gắn tài liệu dùng chung. Đây là quyết định có chủ đích (khác cơ chế gộp `product_name` chỉ dành cho VIB).

---

## 4. Tài liệu & URL đã dùng

| Template | Tài liệu | Nguồn |
|---|---|---|
| 22 | sacombank_platinum_cashback.pdf | Website Sacombank – "Quy định hoàn tiền thẻ tín dụng Visa Bạch Kim Hoàn Tiền" |
| 23 | sacombank_amex_tnc.pdf + sacombank_amex_mcc.pdf | Website Sacombank – "Quy định hoàn tiền thẻ Platinum American Express" + "Danh mục MCC" |
| 24 | mb_jcb_platinum.pdf | https://www.mbbank.com.vn/resources/files/1/huyendn2/JCB/01/the-le-cvp-the-jcb-platinum.pdf (hiệu lực 01/11/2025) |
| 25 | uob_one_cashback.pdf | Website UOB Việt Nam – T&C chương trình hoàn tiền thẻ UOB One |
| 26 | vpbank_loyalty_q1_2026.pdf | https://www.vpbank.com.vn/-/media/vpbank-latest/1retail/promotion/tnc/The-le-Chuong-trinh-Loyalty-point-Q1.2026.pdf (Phụ lục 03) |
| 27 | shinhan_365_tnc.pdf | Website Shinhan Việt Nam – Điều khoản chương trình hoàn tiền 365 Cashback hạng Bạch Kim |
| 28 | shinhan_mylife_tnc.pdf | Website Shinhan Việt Nam – Điều khoản chương trình ưu đãi Shinhan MyLife hạng Bạch Kim (Phụ lục 1) |
| 29 | cake_premium_mcc.pdf | Website CAKE by VPBank – Điều khoản chương trình hoàn tiền thẻ tín dụng CAKE |

---

## 5. Số liệu tạo/cập nhật

| Loại | Số lượng |
|---|---|
| Hồ sơ MCC tạo mới | 8 (profile 15–22) |
| Hồ sơ MCC cập nhật | 1 (profile 19 – thêm mã 6011 vào dòng loại trừ) |
| Nhóm dòng MCC (row) | 34 |
| Mã MCC |
- `included`: 67 mã
- `excluded`: 60 mã
- Tổng: 127 mã |
| Bậc hoàn tiền import | 27 |
| Tài liệu gắn mới | 11 |

Lệnh import đã chạy: `php artisan credit-card:import-mcc-row-benefits`
Kết quả: `20 hồ sơ / 60 dòng / 27 bậc tạo mới / 0 cập nhật / 0 gỡ bỏ`.

---

## 6. MCC được tài liệu nêu nhưng THIẾU trong master (`credit_card_mcc_codes`)

Báo cáo, **không tự thêm vào master** theo quy định. Các mã này bị lọc khỏi danh sách `codes` của dòng và đã ghi chú trong `note` của dòng tương ứng.

| Template | Dòng | MCC thiếu |
|---|---|---|
| 22 | Siêu thị, cửa hàng tiện lợi | 9751 |
| 22 | Sòng bạc | 9754 |
| 22 | Giao dịch tài chính, chuyển tiền… | 6050, 6530, 6538 |
| 23 | Vé máy bay, khách sạn, đại lý du lịch | **toàn bộ dải 3000–3836** |
| 28 | Siêu thị, cửa hàng tiện lợi | 5412 |

> **Cảnh báo quan trọng**: danh mục master hiện **không có bất kỳ mã nào thuộc nhóm 3xxx** (hàng không/lưu trú). Hệ quả: hồ sơ #16 (Sacombank Platinum Amex) có 2 dòng `included` nhưng **không có mã nào khớp master**, nên tra cứu MCC sẽ **không trả về** chính sách này. Cần bổ sung dải MCC 3000–3836 vào master (bước nghiệp vụ riêng) để tra cứu hoạt động.

---

## 7. Kết quả kiểm thử thực tế

```
php artisan test \
  tests/Feature/CreditCard/CardLookupMccProfileTest.php \
  tests/Feature/CreditCard/CardLookupTest.php \
  tests/Feature/Admin/CreditCardMccManagementTest.php \
  tests/Feature/CreditCard/MccRowBenefitImportTest.php \
  tests/Feature/CreditCard/ResearchedTemplatesPolicyTest.php
```

- **55 passed (924 assertions)** — không có test nào thất bại.
- `vendor/bin/pint --test database/data/credit_card_mcc_row_benefits.php` → `passed`.
- `php -l database/data/credit_card_mcc_row_benefits.php` → không lỗi cú pháp.

Kiểm tra tra cứu thực tế (`CardPolicyLookupService::lookupMcc`):

| MCC | Kết quả |
|---|---|
| 5399 | MB JCB Platinum → 10% (cap 500.000đ/kỳ) |
| 8211 | Cake Premium → 20% |
| 5814 | VPBank Flex → 15%; Shinhan MyLife → 10%; MB JCB Platinum → loại trừ (excluded) |
| 5411 | Sacombank Visa Platinum Cashback → 6,8%; Shinhan 365 → 12%; Shinhan MyLife → 10% |
| 4789 | Sacombank Visa Platinum Cashback → 16,8%; UOB One → 10% |
| 4511 | Cake Premium → 20% (Sacombank Amex không khớp do thiếu master) |

---

## 8. Xung đột & vấn đề còn tồn

1. **Sacombank Platinum Amex (tpl 23)** — không có mã khớp master (thiếu dải 3xxx), tra cứu MCC không trả kết quả. Cần bổ sung master.
2. **MB JCB Platinum (tpl 24)** — tài liệu chính thức hiệu lực 01/11/2025 quy định hoàn 10% **mua sắm online (Shopee/TikTok Shop)**, min 2.000.000đ/merchant/kỳ, trần 500.000đ/kỳ. Trong khi seeder/blueprint của luồng công việc khác mô hình hoá MB JCB Platinum theo **doanh số kỳ**. **Xung đột đã ghi nhận, KHÔNG ghi đè blueprint**.
3. **VPBank Flex (tpl 26)** — seeder đặt 8%/trần 500.000đ, trong khi tài liệu Loyalty Q1.2026 quy định đầu BIN 52039939 (Credit): **15%/trần 1.000.000đ/kỳ**, min 2.000.000đ. **Xung đột đã ghi nhận, KHÔNG ghi đè blueprint**.
4. **Hiển thị "bậc cao nhất"** — với các hồ sơ có nhiều bậc theo hạng (Cake Premium: Tier 1/2/3; Shinhan 365: 10%/12%), tra cứu hiển thị bậc có trần cao nhất (Cake: 10.000.000đ — yêu cầu CASA ≥ 500tr). Điều kiện đi kèm đã nêu rõ trong `cond`. Đây là hành vi thiết kế sẵn có (giống VIB hiển thị hạng Signature).
5. **VPBank Flex** hiển thị mặc định **gói "Ăn uống"**; các gói còn lại (Du lịch/Thể thao/Mua sắm) đã ghi chú MCC trong `description` nhưng chưa tạo dòng riêng (thẻ chọn gói theo kỳ).

---

## 9. Tuân thủ quy tắc

- Không sửa `.env`; không commit/push; không `migrate:fresh`/`db:wipe`; không xoá bảng/model cũ.
- Không thay đổi công thức cashback/blueprint/wallet/ledger.
- Không tự suy diễn MCC/tỷ lệ/điều kiện/trần; chỉ ghi dữ liệu có căn cứ trong tài liệu gốc.
- Không tự thêm MCC vào master; chỉ báo cáo mã thiếu.
- Không xoá/ghi đè tài liệu đã gắn trước đó.
- Chỉ thay đổi duy nhất `database/data/credit_card_mcc_row_benefits.php` (bổ sung 8 mục).
