# BÁO CÁO PHÍ THƯỜNG NIÊN HỒ SƠ MCC (PHASE I)

> Ngày thực hiện: 2026-10-13. Người thực hiện: phiên OpenCode.
> Phạm vi: bổ sung phí thường niên (thẻ chính / thẻ phụ / ngưỡng chi tiêu miễn phí) cho các hồ sơ
> MCC `credit_card_policy_mcc_profiles`, dựa **duy nhất** trên nguồn chính thức của ngân hàng.
> CSDL: `hoantien_creditcard` (connection `creditcard`).
>
> Nguyên tắc: chỉ ghi giá trị có nguồn chính thức đúng **sản phẩm + hạng**; **KHÔNG suy luận**,
> **KHÔNG sao chép** giữa hồ sơ, **KHÔNG điền `0`** kể cả khi ngân hàng ghi "Miễn phí"; trường
> không xác minh để **NULL**. Khi tài liệu không có bảng phí, KHÔNG coi là miễn phí.

---

## 1. Kết quả tổng quan

| Hạng mục | Số lượng |
|---|---|
| Tổng hồ sơ MCC | 27 |
| Hồ sơ **có ≥1 giá trị phí** | **20** |
| Hồ sơ để **NULL** (có lý do) | 7 |
| Trường còn NULL do thiếu nguồn chính thức | xem mục 3 |

Ba trường được ghi: `annual_fee_primary` (thẻ chính), `annual_fee_supplementary` (thẻ phụ),
`annual_spend_for_annual_fee_waiver` (tổng chi tiêu/năm để miễn/hoàn phí).

---

## 2. Giá trị đã ghi theo từng hồ sơ

Đơn vị VND/năm. Nguồn ghi kèm hiệu lực nếu tài liệu nêu rõ; nếu không, ghi "truy cập 13/10/2026".

| # | Sản phẩm | Thẻ chính | Thẻ phụ | Ngưỡng miễn phí | Nguồn chính thức |
|---|---|---|---|---|---|
| 1 | LPBank JCB Ultimate | 1.499.000 | — | — | `lpbank.com.vn` (biểu phí) |
| 3 | Sacombank Visa Uniq Platinum | 599.000 | — | — | `sacombank.com.vn` (trang EN) |
| 5 | VPBank StepUp MC | 499.000 | 200.000 | 100.000.000 | `vpbank.com.vn` (biểu phí 22/06/2026) |
| 6 | VPBank Lady MC | 499.000 | miễn phí* | 120.000.000 | `vpbank.com.vn` (biểu phí 22/06/2026) |
| 7 | VPBank Z Card JCB | 399.000 | miễn phí* | 80.000.000 | `vpbank.com.vn` (biểu phí 22/06/2026) |
| 8 | MSB mDigi MC | 399.000 | — | 60.000.000 | `msb.com.vn` |
| 9 | MSB Visa Online | 399.000 | — | 60.000.000 | `msb.com.vn` |
| 10 | VPBank Cake Freedom Visa | 599.000 | — | 150.000.000 | `cake.vn` (ĐKĐK) |
| 11 | BIDV Visa Cashback Online | 1.000.000 | 600.000 | — | `bidv.com.vn` |
| 12 | SeABank SeA-Easy | — | — | 70.000.000 | `seabank.com.vn` |
| 13 | Shinhan Supreme Visa | 550.000 | — | — | `shinhan.com.vn` |
| 15 | Sacombank Visa Bạch Kim Hoàn Tiền | 599.000 | — | — | `sacombank.com.vn` |
| 16 | Sacombank Platinum Amex | 599.000 | — | — | `sacombank.com.vn` |
| 18 | UOB One | 1.200.000 | — | 60.000.000 | `uob.com.vn` (SP + PDF biểu phí) |
| 20 | Shinhan 365 Cashback Platinum (Bạch Kim) | 1.100.000 | 700.000 | — | `shinhan.com.vn` |
| 21 | Shinhan MyLife Visa (Bạch Kim) | 1.100.000 | 700.000 | — | `shinhan.com.vn` |
| 23 | VPBank S Rewards MC | 499.000 | 249.000 | 100.000.000 | `vpbank.com.vn` (biểu phí 22/06/2026) |
| 24 | BIDV Visa Cashback 360 | 1.000.000 | 600.000 | 70.000.000 | `bidv.com.vn` |
| 26 | VIB Online Plus 2in1 | 599.000 | 299.000 | — | `vib.com.vn` |
| 27 | OCB JCB Platinum | 999.000 | 499.000 | 60.000.000 | `ocb.com.vn` + `bieu-phi-the-upweb01.04.2026.pdf` |

**Ghi chú nguồn:**
- `#18 UOB One`: miễn phí năm 1 & 2 khi chi tiêu/rút ≥ 60.000.000 trong năm đầu.
- `#27 OCB JCB Platinum`: thẻ phụ 499.000, miễn với thẻ phụ phát hành từ 01/01/2025 (để giá trị
  chuẩn 499.000 làm mốc tham chiếu, không áp ưu đãi thời hạn vào dữ liệu tĩnh).
- `#13 Shinhan Supreme`: mức 550.000 khớp ưu đãi hoàn phí chào mừng trong biểu phí chính thức.
- `#20/#21 Shinhan Bạch Kim`: 1.100.000 / 700.000 theo trang so sánh thẻ chính thức.
- `#12 SeABank`: trang chính thức chỉ nêu điều kiện miễn phí ≥ 70.000.000; số phí cơ bản không
  đọc được rõ (PDF bảng bị lỗi trích xuất) ⇒ **chỉ ghi ngưỡng**, để phí cơ bản NULL.
- `*` **"miễn phí"** chỉ ghi trong báo cáo; trong DB **không** điền `0` — để NULL (xem Phần I.2.3).
- Nguồn VPBank `#5/#6/#7/#23`: **biểu phí chính thức** hiệu lực **22/06/2026** (xem Phần I.1 dưới).

---

## 3. Hồ sơ để NULL và lý do

| # | Sản phẩm | Lý do để NULL |
|---|---|---|
| 2 | VIB One Card (Gold/Platinum/Signature) | Phí **phụ thuộc hạng** (3 hạng ~999k/1.999k/2.999k); chưa xác minh đủ bậc thẻ phụ ⇒ không gán một mức chung. |
| 4 | MB JCB Ultimate | **Chưa tìm được số phí chính thức** từ `mbbank.com.vn`. |
| 14 | VIB Max Card (Starter/Plus/Pro/Elite) | Phí **phụ thuộc hạng** (Starter miễn phí / Plus, Pro, Elite 60.000). |
| 17 | MB JCB Platinum | Chỉ có nguồn **thứ cấp** (199.000), không dùng để ghi. |
| 19 | VPBank Flex MC | Ngân hàng ghi **"miễn phí"** ⇒ theo luật không-điền-0, để NULL. |
| 22 | Thẻ Cake 2in1 (gói Premium) | Là **phí quản lý gói** (300.000/3 kỳ = 1.200.000/năm), không phải phí thường niên cố định. |
| 25 | TPBank EVO | Ngân hàng ghi **"miễn phí"** ⇒ để NULL. |

Các trường còn trống ở hồ sơ đã có giá trị (ví dụ ngưỡng miễn phí của #11, thẻ phụ của #3/#5–#10…)
cũng để NULL do tài liệu chưa công bố — không suy đoán.

---

## 4. Phạm vi & ràng buộc đã tuân thủ

- **Không** đụng engine cashback / ví / sổ cái; **không** sửa MCC, benefit tiers, blueprint, seeder,
  rates, limits, cashback logic.
- **Không** chạy full seeder/import; **không** `migrate:fresh` / `db:wipe` / rollback.
- Chỉ cập nhật 3 cột phí trên đúng 20 hồ sơ (các trường trước đó NULL).
- **Không** commit / push / deploy.
- Workstream **Portfolio Optimizer** (`CreditCardController`, `compare.blade.php`,
  `CardPortfolioOptimizerTest`, `PortfolioOptimizerService`) **không bị chạm**.

### Kiểm chứng
- `php artisan test --filter "CreditCardMccManagementTest|CardLookupMccProfileTest|CardLookupTest"`
  → **57 passed** (272 assertions).
- `vendor\bin\pint --test` trên các file PHP liên quan → **passed**.
- `git diff --check` → sạch.

---

## 5. Tài liệu đã cập nhật

- `docs/CREDIT_CARD_MCC_WORKFLOW.md` — thêm **Phần I: Phí thường niên hồ sơ MCC** (3 trường, giao
  diện, quy tắc nghiên cứu, trạng thái đợt đầu) + mô tả 3 trường ở mục A.3.
- `docs/CREDIT_CARD_POLICY_DEVELOPMENT_GUIDE.md` — thêm mục **2.2 Phí thường niên hồ sơ MCC** vào
  quy trình nghiên cứu bắt buộc.

---

## 6. Việc còn lại (khuyến nghị)

- Bổ sung nguồn chính thức: phí MB (#4, #17), bậc/phí phụ VIB One Card (#2) và VIB Max Card (#14),
  phí cơ bản SeABank (#12), ngưỡng miễn phí BIDV Cashback Online (#11) và các hồ sơ VPBank.
- Cân nhắc quy ước **có nên mã hoá `0`** cho các thẻ ngân hàng công bố "miễn phí" (#19, #25) — hiện
  giữ NULL theo luật Phase I.

---

# PHASE I.1 — ĐIỀU TRA PHÍ THƯỜNG NIÊN THEO HẠNG (2026-10-13)

> Mục tiêu: hoàn thiện theo **từng hạng/bậc** (thẻ chính / thẻ phụ / phí gói), tách **năm đầu** vs
> **các năm tiếp theo**, chỉ dùng **nguồn chính thức**. Tiếp nối Phần I.

## I.1.1 Giá trị bổ sung vào DB (đợt I.1)

| # | Sản phẩm | Trường thêm | Giá trị | Nguồn (hiệu lực) |
|---|---|---|---|---|
| 5 | VPBank StepUp MC | thẻ phụ | 200.000 | VPBank biểu phí 22/06/2026 |
| 5 | VPBank StepUp MC | ngưỡng miễn (năm sau) | 100.000.000 | 〃 |
| 6 | VPBank Lady MC | ngưỡng miễn (năm sau) | 120.000.000 | 〃 |
| 7 | VPBank Z Card JCB | ngưỡng miễn (năm sau) | 80.000.000 | 〃 |
| 23 | VPBank S Rewards MC | ngưỡng miễn (năm sau) | 100.000.000 | 〃 |

Ghi chú VPBank:
- Thẻ phụ Lady & Z Card: ngân hàng ghi **miễn phí** ⇒ **NULL** trong DB (không điền `0`).
- VPBank còn điều kiện thay thế "miễn phí năm 2 khi tham gia gói HVTV" (ghi trong báo cáo, không vào DB).

## I.1.2 Hồ sơ nhiều hạng — KHÔNG ghi (kèm đề xuất biểu diễn)

- `#2` **VIB One Card** (nguồn `vib.com.vn`): thẻ chính Gold **999.000** / Platinum **1.999.000** /
  Signature **2.999.000**; thẻ phụ Gold **499.000** / Platinum **999.000** / Signature **1.499.000**
  (hạng theo hạn mức; ưu đãi miễn năm đầu theo chương trình Q3–Q4/2026).
- `#14` **VIB Max Card** (nguồn `vib.com.vn`): Starter **miễn phí**; Plus/Pro/Elite phí thường niên
  **5.000 VNĐ/tháng (~60.000/năm)** (thẻ chính & thẻ phụ); **phí gói thành viên** Plus 300.000 /
  Pro 500.000 / Elite 1.000.000 — mỗi **3 kỳ sao kê**; hoàn theo doanh số mỗi kỳ (Plus >30tr→100.000;
  Pro >50tr→166.667; Elite >100tr→333.334).
- **Đề xuất:** bảng con `credit_card_policy_mcc_fee_tiers(...)` — chi tiết ở
  `CREDIT_CARD_MCC_WORKFLOW.md` Phần I.4; giữ 3 cột hiện tại cho hồ sơ một hạng. **Chưa đổi schema trong đợt I.1.**

## I.1.3 Hồ sơ vẫn để NULL (lý do + trạng thái nguồn)

| # | Sản phẩm | Lý do |
|---|---|---|
| 2 | VIB One Card | Nhiều hạng — cần bảng hạng (đã có số liệu chính thức nhưng chưa có nơi lưu). |
| 4 | MB JCB Ultimate | Biểu phí chính thức MB render JS, không trích xuất được số; nguồn thứ cấp nêu 800.000 (miễn năm đầu; miễn năm sau khi ≥100tr) — **không dùng**. |
| 12 | SeABank SeA-Easy | Phí cơ bản không trích xuất được (font PDF thiếu ToUnicode); giữ ngưỡng miễn 70.000.000 + điều kiện. |
| 14 | VIB Max Card | Nhiều hạng + phí gói — cần bảng hạng. |
| 17 | MB JCB Platinum | Chỉ nguồn thứ cấp (199.000) — không dùng. |
| 19 | VPBank Flex MC | Ngân hàng ghi "miễn phí" — theo luật không-điền-0. |
| 22 | Cake 2in1 Premium | Phí **gói** (300.000/3 kỳ), không phải phí thường niên cố định. |
| 25 | TPBank EVO | Ngân hàng ghi "miễn phí" — NULL. |

Trường riêng lẻ còn NULL: `#11` ngưỡng miễn (trang BIDV ghi "10–50 triệu/năm" — dạng khoảng, chưa
đủ rõ thành 1 ngưỡng) ⇒ NULL + báo cáo.

## I.1.4 Kiểm chứng & ràng buộc

- Xác minh cột PDF bằng toạ độ x/y (`pdftext2.php --xy`) để ánh xạ chính xác cột↔hạng VPBank, tránh
  lệch cột khi trích xuất text thuần.
- Chỉ UPDATE 4 hồ sơ (5, 6, 7, 23) trên 3 cột phí; không chạm cashback/blueprint/MCC/seeder.
- Không `migrate:fresh` / `db:wipe` / rollback; không commit/push/deploy; không đụng Portfolio Optimizer.

## I.1.5 Nguồn chính thức đã dùng (đợt I.1)

- VPBank "Biểu phí thẻ tín dụng tại VPBank – Khối KHCN" hiệu lực **22/06/2026**.
- VIB `vib.com.vn` (trang sản phẩm VIB One Card / VIB Max Card + biểu phí).
- BIDV `bidv.com.vn` (trang sản phẩm Visa Cashback Online).
- MB `mbbank.com.vn` (trang sản phẩm JCB Ultimate — biểu phí đính kèm render JS, chưa trích được).
