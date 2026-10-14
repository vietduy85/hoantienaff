# Báo cáo — Cập nhật hồ sơ MCC "VPBank Z Card JCB" theo thể lệ mới

- **Ngày thực hiện:** 2026-10-14
- **Phạm vi:** cập nhật **hồ sơ MCC #7** của **template #6** (`vpbank-z-card-jcb`) theo thể lệ chính thức mới.
- **CSDL:** `hoantien_creditcard` (connection `creditcard`).
- **Trạng thái:** đã áp dụng trên DB thật; **chưa commit/push/deploy**.

---

## 1. Bối cảnh

Hồ sơ **#7** ("VPBank Z Card JCB - Thể lệ ưu đãi dài hạn") trước đây theo bản thể lệ **2025**
(10%, tối thiểu 4 triệu/kỳ, trần 200k/200k/100k). VPBank đã ban hành **thể lệ dài hạn mới** cho cùng
sản phẩm **Z Card JCB**, thay đổi **cả danh mục mã ngành lẫn tỷ lệ/trần** ⇒ bắt buộc cập nhật hồ sơ.

**Yêu cầu trọng tâm:** xác minh đúng template/hồ sơ đang tồn tại; cập nhật (không tạo trùng); phản ánh
đúng 3 nhóm 15% / trần 500k mỗi nhóm / tổng 1 triệu mỗi kỳ; bổ sung MCC còn thiếu; thay tài liệu sang PDF
mới; chỉ dùng mã có trong master; không đụng engine/blueprint; báo cáo tiếng Việt.

---

## 2. Nguồn tài liệu & hiệu lực

- **Nguồn:** VPBank — *"Thể lệ ưu đãi dài hạn dành cho chủ thẻ tín dụng VPBank Z JCB"*.
  - URL: `https://www.vpbank.com.vn/-/media/vpbank-latest/1retail/cards/credit-cards/The-le-uu-dai-dai-han-danh-cho-chu-the-tin-dung-VPBank-Z-JCB-Web.pdf`
  - **Truy cập:** 2026-10-14. Dung lượng PDF: **395.052 bytes**.
- **Hiệu lực:** từ **27/08/2026** đến hết **31/12/2027** (mục 5).
- **BIN:** `35677200`.
- **Kỳ sao kê Z JCB:** từ ngày 27 tháng hiện tại đến hết ngày 26 tháng kế tiếp.

---

## 3. Xác minh định danh (không hard-code id)

| Thành phần | Giá trị |
|---|---|
| Template | #6, `slug = vpbank-z-card-jcb`, `name = VPBank Z Card JCB`, `default_version_id = 8` |
| Hồ sơ | #7, `policy_template_id = 6`, `name = VPBank Z Card JCB - Thể lệ ưu đãi dài hạn` |
| Tài liệu cũ | #8 — `vpbank_zcard.pdf` (558.525 bytes) — **bản 2025, đã hết hiệu lực** |
| Phí thường niên (giữ nguyên) | chính 399.000; miễn phí khi chi tiêu năm trước đạt 80.000.000 |

Hồ sơ **đang tồn tại** ⇒ cập nhật tại chỗ, **không tạo hồ sơ mới**.

---

## 4. Nội dung thể lệ mới (mục 9.2.2 & 9.2.3)

| STT | Nhóm hoàn tiền | Tỷ lệ | Trần mỗi nhóm | MCC áp dụng (Bảng 1 - Phụ đính) |
|---|---|---|---|---|
| 1 | Ăn uống | 15% | 500.000 đ/kỳ sao kê | 5811, 5812, 5813, 5921, **5462** |
| 2 | Giải trí & Fitness | 15% | 500.000 đ/kỳ sao kê | 5734, 5735, 7832, 7997 |
| 3 | Di chuyển & đặt đồ ăn | 15% | 500.000 đ/kỳ sao kê | 4121, 5814 |
| — | Ngành hàng khác | 0% | — | — |

- **Tổng số tiền hoàn tối đa trong kỳ sao kê: 1.000.000 đ** (trần chung cho cả 3 nhóm).
- Ghi chú diễn giải: (1) nhóm Giải trí gồm game, xem phim, âm nhạc trực tuyến…; (2) nhóm Di chuyển & đặt
  đồ ăn gồm nền tảng gọi xe & giao đồ ăn như Be, Grab, ShopeeFood…; (3) tổng so với trần chung; (4) tài
  khoản hoàn tiền (VPBank NEO, tối thiểu 500.000 để yêu cầu hoàn, hết hạn sau 24 tháng…).

### 4.1 Giao dịch KHÔNG hợp lệ (loại trừ) — mô tả theo loại giao dịch

Rút tiền mặt; **Phí và lãi** (phí thường niên, phí rút tiền mặt, lãi suất, số tiền thanh toán dư nợ thẻ
tín dụng, phí trả chậm…); Thuế hàng hóa, dịch vụ; Giao dịch nghi ngờ rút tiền mặt hoặc gian lận; Giao dịch
hủy/hoàn lại (charge back); Chuyển khoản dư nợ; **Giao dịch ví điện tử** (VTC Pay, Zalo Pay, Ngan Luong
Online, Ngan Luong - Alepay, Vi Momo, VN Pay…); Mua bán chứng khoán, ngoại hối; Giao dịch qua VPBank NEO;
Chuyển tiền trên các trang điện tử (Paypal, SKR skrill.com…); Nạp tiền vào thẻ thanh toán trước; Chuyển
đổi ngoại tệ (Forex.com…); **Các giao dịch khác do Ngân hàng quy định**.

> Tài liệu **không gán mã MCC** cho các loại trừ này ⇒ **không tạo mã**; lưu dưới dạng dòng excluded mô tả
> bằng văn bản (đúng nguyên tắc "không tự suy diễn mã").

---

## 5. Thay đổi dữ liệu (trước → sau)

### 5.1 Dòng included

| Nhóm (trước) | Nhóm (sau) | MCC trước | MCC sau |
|---|---|---|---|
| Ăn uống | Ăn uống | 5811, 5812, 5813, 5921 | 5811, 5812, 5813, 5921, **5462** |
| Giải trí và Fitness | **Giải trí & Fitness** | 5734, 5735, 7832, 7997 | (không đổi) |
| Di chuyển và đặt đồ ăn | **Di chuyển & đặt đồ ăn** | 4121, 5814 | (không đổi) |
| — | **Ngành hàng khác** (mới) | — | (không mã, 0%) |

### 5.2 Bậc hoàn tiền (theo dòng)

| Nhóm | Trước | Sau |
|---|---|---|
| Ăn uống | 10%, min 4.000.000, trần 200.000 | **15%, không min, trần 500.000/kỳ sao kê** |
| Giải trí & Fitness | 10%, min 4.000.000, trần 200.000 | **15%, không min, trần 500.000/kỳ sao kê** |
| Di chuyển & đặt đồ ăn | 10%, min 4.000.000, trần 100.000 | **15%, không min, trần 500.000/kỳ sao kê** |
| Ngành hàng khác | — | **0%** |

> Điều kiện **tối thiểu 4 triệu/kỳ bị bỏ** (thể lệ mới không nêu). Trần **tổng 1.000.000/kỳ** được ghi ở
> `condition_note` từng bậc và ở `note` hồ sơ (xem §7).

### 5.3 Dòng excluded

- Trước: **0 dòng**.
- Sau: **13 dòng** mô tả theo loại giao dịch, **không gán mã** (theo §4.1).

### 5.4 Tài liệu & ghi chú hồ sơ

- **Gỡ** tài liệu cũ `vpbank_zcard.pdf` (2025, hết hiệu lực) và **gắn** bản mới
  `vpbank-z-card-jcb-uu-dai-dai-han-2026.pdf` (disk `local`, path `credit-card-mcc/7/…pdf`).
  Lý do: hệ thống **không có trường phiên bản/hiệu lực** cho tài liệu; nếu để song song hai bản, giao diện
  khó phân biệt bản nào là hiện hành ⇒ **thay** để không hiển thị bản cũ như thể là mới nhất (URL bản cũ vẫn
  được lưu trong báo cáo này để tra cứu lịch sử).
- **Cập nhật `note` hồ sơ:** nêu thể lệ + hiệu lực 27/08/2026–31/12/2027, 3 nhóm 15%/500k, trần tổng 1tr/kỳ,
  nguồn + ngày truy cập.

---

## 6. Kiểm tra mã MCC với master

- 11 mã của thể lệ **đều có trong master** (`credit_card_mcc_codes`) ⇒ **0 mã thiếu**, **0 mã tạo mới**.
- **0 mã vừa included vừa excluded** (loại trừ đều là văn bản, không mã).
- **Lệch nhãn cần lưu ý:**

  | MCC | Nhãn trong tài liệu VPBank | Nhãn trong master hệ thống |
  |---|---|---|
  | 5734 | "Record Shops" | "Computer Software Stores" |
  | 5735 | "Record Stores" | "Record Stores" |

  Cả hai mã đều **tồn tại** và được dùng; chỉ **khác nhãn mô tả**. Không sửa master trong phạm vi task này.

---

## 7. Giới hạn dữ liệu & đề xuất tối thiểu

- **Trần TỔNG 1.000.000 đ/kỳ** (trần chung của cả 3 nhóm) **chưa có trường riêng** ở cấp hồ sơ MCC
  (`credit_card_policy_mcc_profiles` chỉ có `name`, `note`, `product_name`, `annual_fee_*`).
  - Hiện **biểu diễn được**: trần **mỗi nhóm 500.000 đ** = `credit_card_policy_mcc_row_benefits.cap_amount`.
  - **Không biểu diễn cấu trúc được**: trần **tổng** ⇒ **ghi tại `note` hồ sơ + `condition_note` từng bậc**.
  - **Đề xuất tối thiểu (chưa thực hiện):** thêm cột `cashback_cap_per_period` (decimal(16,2), nullable) cho
    hồ sơ MCC. **Không** đổi engine cashback/blueprint.
- Trần theo nhóm **không sinh mã MCC** ⇒ thể lệ vẫn chỉ có **11 mã** cho 3 nhóm (xem §6).

---

## 8. Thay đổi mã nguồn / dữ liệu

**Mới:**
- `database/migrations/creditcard/2026_10_14_000009_update_vpbank_z_card_jcb_mcc_profile.php`
- `database/data/credit_card_documents/vpbank-z-card-jcb-uu-dai-dai-han-2026.pdf` (395.052 bytes)
- `docs/CREDIT_CARD_MCC_VPBANK_Z_CARD_JCB_UPDATE_REPORT.md` (tài liệu này)

**Sửa:**
- `database/data/credit_card_mcc_row_benefits.php` — mục `vpbank-z-card-jcb` (15%, trần 500k, thêm
  `Ngành hàng khác` 0%, đổi `source_doc` sang tài liệu 2026).
- `config/credit_card_mcc_categories.php` — thêm alias `Giải trí & Fitness` → `[entertainment, fitness]`,
  `Di chuyển & đặt đồ ăn` → `[transport, food]`, `Ngành hàng khác` → `other`.
- `docs/CREDIT_CARD_MCC_POLICY_COVERAGE_AUDIT_REPORT.md` — cập nhật bảng B.1 + thêm **Phần L** + phụ lục.

**Không đụng:** engine cashback, blueprint, Portfolio Optimizer, các hồ sơ VPBank khác, `.env`.

---

## 9. Cách áp dụng & kết quả thực thi

```text
php artisan credit-card:migrate
  → 2026_10_14_000009_update_vpbank_z_card_jcb_mcc_profile ... DONE

php artisan credit-card:import-mcc-row-benefits
  → Hồ sơ đã nạp: 26 | Dòng đã nạp: 80 | Bậc tạo mới: 4 | Bậc cập nhật: 0 | Bậc gỡ bỏ: 0
```

**Kiểm chứng trên DB thật (probe):**
- Hồ sơ #7: `note` mới đúng thể lệ mới; **không** tạo hồ sơ mới.
- Included: `Ăn uống` (5462, 5811, 5812, 5813, 5921), `Giải trí & Fitness` (5734, 5735, 7832, 7997),
  `Di chuyển & đặt đồ ăn` (4121, 5814), `Ngành hàng khác` (0 mã).
- Tiers: 15%/500.000 (không min) cho 3 nhóm; 0% cho Ngành hàng khác; `condition_note` nêu trần tổng 1tr.
- Excluded: 13 dòng, 0 mã.
- Tài liệu: **chỉ còn 1** bản mới (`id 32`, `vpbank-z-card-jcb-uu-dai-dai-han-2026.pdf`); bản cũ `id 8`
  đã gỡ khỏi DB và file trên disk.

---

## 10. Kiểm thử & lint

| Hạng mục | Kết quả |
|---|---|
| `php -l` (migration, data file, config) | không lỗi cú pháp |
| `vendor/bin/pint --test` (3 file thay đổi) | `passed` |
| `git diff --check` | sạch |
| `phpunit tests/Feature/CreditCard` | **OK — 1474 tests, 10906 assertions** |
| Nhóm chuyên đề (CardLookup / CardLookupMccProfile / CardLookupCategory / MccRowBenefitImport / CreditCardMccManagement) | **OK — 91 tests, 437 assertions** |

> Migration **có guard** (bỏ qua khi template/hồ sơ chưa tồn tại, và khi đã cập nhật) ⇒ không ảnh hưởng bộ
> test (DB test rỗng có chủ đích, không seed template).

---

## 11. Việc CHƯA làm (ngoài phạm vi / chờ duyệt)

- Không thêm trường `cashback_cap_per_period` (mới đề xuất tối thiểu — §7).
- Không sửa master MCC 5734/5735 (chỉ ghi nhận lệch nhãn — §6).
- Không đổi giao diện.
- **Không commit / push / deploy.**
