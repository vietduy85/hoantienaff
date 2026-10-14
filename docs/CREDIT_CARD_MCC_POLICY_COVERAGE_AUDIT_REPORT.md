# BÁO CÁO KIỂM TOÁN ĐỘ PHỦ HỒ SƠ MCC THEO CHÍNH SÁCH HOÀN TIỀN

> Ngày thực hiện: 2026-10-13. Người thực hiện: phiên OpenCode.
> Phạm vi: đối chiếu **toàn bộ chính sách hoàn tiền** (template hệ thống + policy đang chạy) với
> **hồ sơ MCC** (`credit_card_policy_mcc_profiles`) trong CSDL `hoantien_creditcard` (connection `creditcard`).
> Mục tiêu: phát hiện chính sách thiếu/không đầy đủ hồ sơ, nghiên cứu nguồn chính thức, và **ghi nhận**
> các trường hợp chưa thể tạo/hoàn thiện kèm lý do + đề xuất.
>
> Nguyên tắc: chỉ dựa trên **nguồn chính thức** của ngân hàng; **KHÔNG suy luận** mã MCC từ tên danh mục;
> mã MCC chỉ dùng khi **có trong master** `credit_card_mcc_codes`; **KHÔNG** sửa công thức hoàn tiền,
> tỷ lệ/trần/điều kiện, master MCC, ví/sổ cái hay module khác trong đợt này.
>
> **Trạng thái:** tài liệu này gồm **3 đợt**:
> - **Đợt kiểm toán (audit)** — chỉ báo cáo, không ghi CSDL; kết quả ở Phần A–F.
> - **Đợt thực thi 1 (execution, 2026-10-13)** — tạo hồ sơ HSBC #31 (quyết định 1), giải quyết VIB nhiều hạng
>   bằng **liên kết dùng chung** (quyết định 3), bổ sung mã Sacombank Amex (quyết định 4). Chi tiết ở **Phần J**.
> - **Đợt thực thi 2 (2026-10-13)** — tạo **hồ sơ mô tả (không mã)** cho VPBank Super Shopee #7 (quyết định 2)
>   và hoàn thiện hồ sơ rỗng **VIB Online Plus 2in1 #26** (quyết định 5); chi tiết ở **Phần K**. Các mục
>   C/D/E/F/H được cập nhật kèm trạng thái sau cả hai đợt thực thi.

---

## A. Tổng quan & phương pháp

### A.1 Số liệu nền (đo trực tiếp trên CSDL)

| Hạng mục | Số lượng |
|---|---|
| Template chính sách hệ thống (`scope = system`) | **35** |
| Template chính sách người dùng (`scope = user`) | **0** |
| Policy (`credit_card_policies`) | **69** (active **62**, superseded **7**) |
| Policy gắn thẻ người dùng thật (`user_card_id != NULL`) | 34 |
| Hồ sơ MCC (`credit_card_policy_mcc_profiles`) | **29** (sau TT2; sau TT1: 28; audit gốc: 27) |
| Hồ sơ **có ≥1 dòng** | **29** (sau TT2; trước TT2: 27 — chỉ #26 rỗng) |
| Hồ sơ **có ≥1 tài liệu** | **28** (sau TT2; VPBank #29 có tài liệu; VIB #26 không tài liệu) |
| Liên kết chương trình dùng chung (`credit_card_policy_mcc_profile_template_links`) | **7** (sau thực thi; audit gốc: 0) |
| Master MCC (`credit_card_mcc_codes`) | **293** |
| Mapping MCC import (`credit_card_policy_mcc_mappings`) | 0 |

### A.2 Phương pháp

1. Trích xuất toàn bộ template + policy + profile + dòng/code/tài liệu → `audit_inventory.json`.
2. Đối chiếu **mã trong hồ sơ** với **master**: tìm mã thiếu, mã trùng included/excluded, tài liệu thiếu file.
3. Kiểm tra **policy ↔ template ↔ profile** để phát hiện template có policy nhưng **không có hồ sơ**.
4. Đọc chính sách (route/service) `CardPolicyLookupService` + `CreditCardLookupController` để hiểu cách
   hồ sơ được **tra cứu** theo template và theo mã MCC.
5. Nghiên cứu nguồn ngân hàng cho các case thiếu (HSBC, VPBank Super Shopee, VIB Online Plus 2in1,
   Sacombank Amex) và ghi lại URL/ngày truy cập/hiệu lực.

### A.3 Kết luận nhanh (cập nhật sau cả hai đợt thực thi)

- **Sau TT2, chỉ còn 1 template hệ thống không có hồ sơ MCC** (audit gốc: 8):
  - **#1 Cashback phổ thông** — template khởi đầu, không phải sản phẩm ngân hàng (E.6) → giữ nguyên.
- **#7 VPBank Super Shopee Platinum Visa** đã có **hồ sơ MÔ TẢ #29** (VPBank không công bố mã MCC):
  included 3 dòng (không mã), excluded 14 dòng (không mã), kèm tài liệu gốc; xem E.2 và Phần K.
- **prof#26 (VIB Online Plus 2in1)** đã được **hoàn thiện** (trước rỗng): included 4 dòng (mô tả theo kênh,
  không mã) + excluded 7 dòng (MCC **4900, 4814** + loại giao dịch không mã); xem E.5 và Phần K.
- **5 template VIB nhiều hạng** (#16–18/#20–21) **phủ qua bảng liên kết dùng chung** (không nhân bản); E.3, J.
- **HSBC Cash Back (#31)** có hồ sơ **#28** (E.1, J).
- **prof#16 (Sacombank Amex)** đã bổ sung **4511, 4722, 7011**; dải 3xxx **tồn đọng có chủ đích** (E.4, J).
- **Không còn hồ sơ rỗng**. **0 mã trong hồ sơ bị thiếu ở master**, **0 mã vừa included vừa excluded**,
  **0 tài liệu thiếu file**.

---

## B. Danh sách toàn bộ chính sách & hồ sơ

### B.1 29 template ĐÃ có hồ sơ riêng (sau TT2)

Ký hiệu: `rows in/out` = số dòng included/excluded; `codes in/out` = số mã included/excluded (đã khử trùng);
`tiers` = số bậc hoàn tiền (`credit_card_policy_mcc_row_benefits`); `docs` = số tài liệu.
Cột "sau TT" = trạng thái sau đợt thực thi.

| Template# | Slug | Hồ sơ# | rows in/out | codes in/out | tiers | docs | Ghi chú |
|---|---|---|---|---|---|---|---|
| 2 | sacombank-uniq-visa | 3 | 2/4 | 6/8 | 4 | 1 | |
| 3 | mb-ultimate-jcb | 4 | 3/0 | 26/0 | 3 | 1 | |
| 4 | vpbank-stepup-mastercars | 5 | 2/1 | 5/11 | 6 | 1 | |
| 5 | vpbank-lady-mastercard | 6 | 5/1 | 30/11 | 10 | 1 | |
| 6 | vpbank-z-card-jcb | 7 | 4/13 | 11/0 | 4 | 1 | **Cập nhật thể lệ 27/08/2026–31/12/2027** (L): 15%/500k ×3 nhóm + tổng 1tr/kỳ; thêm MCC **5462**, thêm dòng "Ngành hàng khác" 0%, thêm 13 dòng loại trừ |
| 7 | vpbank-super-shopee-platinum-visa | **29** | 3/14 | 0/0 | 3 | 1 | **Hồ sơ MÔ TẢ không mã** (VPBank không công bố MCC); tạo trong đợt TT2 (K) |
| 8 | msb-mdigi-mastercard | 8 | 3/0 | 12/0 | 3 | 1 | |
| 9 | msb-online-visa | 9 | 2/0 | 16/0 | 2 | 1 | |
| 10 | vpbank-cake-freedom-visa | 10 | 2/1 | 13/3 | 2 | 2 | |
| 11 | lpbank-ultimate-jcb | 1 | 3/6 | 21/8 | 3 | 2 | |
| 12 | bidv-cashback-online-visa | 11 | 0/1 | 0/13 | 0 | 1 | **Theo kênh GD** — chỉ có loại trừ (đúng thiết kế) |
| 13 | seabank-sea-easy-visa | 12 | 0/1 | 0/15 | 0 | 1 | **Theo kênh GD** — chỉ có loại trừ (đúng thiết kế) |
| 14 | shinhan-supreme-visa | 13 | 6/0 | 17/0 | 18 | 1 | |
| 15 | vib-max-card-visa-starter | 14 | 5/1 | 79/2 | 25 | 1 | `product_name = VIB Max Card Visa` |
| 19 | vib-one-card-visa-gold | 2 | 4/0 | 63/0 | 16 | 1 | `product_name = VIB One Card Visa` |
| 22 | sacombank-visa-platinum-cashback | 15 | 2/6 | 7/29 | 2 | 1 | |
| 23 | sacombank-platinum-amex | 16 | 2/4 | **3**/8 | 2 | 2 | **Đã bổ sung 4511/4722/7011** (J) — dải 3xxx vẫn tồn đọng |
| 24 | mb-jcb-platinum | 17 | 2/1 | 1/6 | 2 | 1 | |
| 25 | uob-one | 18 | 4/1 | 33/3 | 4 | 1 | |
| 26 | vpbank-flex | 19 | 1/1 | 6/11 | 1 | 1 | |
| 27 | shinhan-365-cashback-platinum | 20 | 2/0 | 1/0 | 3 | 1 | `5411`; phần "quốc tế" 0 mã (theo kênh) |
| 28 | shinhan-mylife-visa-bach-kim | 21 | 4/0 | 12/0 | 4 | 1 | |
| 29 | cake-2in1-premium | 22 | 3/1 | 7/3 | 9 | 1 | |
| 30 | vpbank-s-rewards-mastercard | 23 | 4/1 | 7/11 | 3 | 1 | |
| 32 | bidv-visa-cashback-360 | 24 | 3/0 | 15/0 | 4 | 1 | |
| 33 | tpb-evo | 25 | 0/1 | 0/9 | 0 | 1 | **Theo kênh GD** — chỉ có loại trừ (đúng thiết kế) |
| 34 | vib-online-plus-2in1 | 26 | **4/7** | **0/2** | **4** | 0 | **Hoàn thiện TT2** (trước rỗng): included theo kênh (không mã) + excluded 4900/4814 (K); `docs=0` (không có PDF gốc) |
| 35 | ocb-jcb-platinum | 27 | 2/1 | 18/1 | 4 | 1 | |
| 31 | hsbc-cash-back | **28** | 4/11 | 23/0 | 4 | 1 | **Tạo mới trong đợt thực thi** (J); `product_name=NULL` |

### B.2 Template hệ thống CHƯA có hồ sơ (audit gốc) — cập nhật sau thực thi

| Template# | Slug | Tên | Bank | Policy | Loại | Trạng thái sau TT |
|---|---|---|---|---|---|---|
| 1 | cashback-pho-thong | Cashback phổ thông | — | #1 (active, ucard=NULL) | Template khởi đầu | Không phải sản phẩm ngân hàng (E.6) — **để nguyên** |
| 7 | vpbank-super-shopee-platinum-visa | VPBank Super Shopee Platinum Visa | — | #9 (superseded), #33/#34/#42/#43 (active) | SP ngân hàng | **ĐÃ TẠO** hồ sơ MÔ TẢ #29, không mã (E.2, K) |
| 16 | vib-max-card-visa-plus | VIB Max Card Visa - Plus | Quốc tế | #52 (active) | VIB nhiều hạng | **ĐÃ PHỦ** qua liên kết → prof#14 (J) |
| 17 | vib-max-card-visa-pro | VIB Max Card Visa - Pro | Quốc tế | #53 (active) | VIB nhiều hạng | **ĐÃ PHỦ** qua liên kết → prof#14 (J) |
| 18 | vib-max-card-visa-elite | VIB Max Card Visa - Elite | Quốc tế | #54 (active) | VIB nhiều hạng | **ĐÃ PHỦ** qua liên kết → prof#14 (J) |
| 20 | vib-one-card-visa-platinum | VIB One Card Platinum | Quốc tế | #56 (active) | VIB nhiều hạng | **ĐÃ PHỦ** qua liên kết → prof#2 (J) |
| 21 | vib-one-card-visa-signature | VIB One Card Signature | Quốc tế | #57 (active) | VIB nhiều hạng | **ĐÃ PHỦ** qua liên kết → prof#2 (J) |
| 31 | hsbc-cash-back | HSBC Cash Back | Ngân hàng TNHH MTV HSBC Việt Nam | #67 (active) | SP ngân hàng | **ĐÃ TẠO** hồ sơ #28 (E.1, J) |

> Tổng: 29 (B.1) + 6 (B.2) = **35** template hệ thống (B.1 = 27 gốc + HSBC #31 + VPBank #7; B.2 = 6
> template chưa có hồ sơ **tại thời điểm audit**). Sau TT2: **29 template có hồ sơ riêng**,
> **5 template phủ qua liên kết dùng chung** (#16/#17/#18 → #14; #20/#21 → #2) ⇒ **34/35 template được phủ**;
> còn **1 template không hồ sơ** (#1 Cashback phổ thông — template khởi đầu, không phải SP ngân hàng).

---

## C. Hồ sơ đã tạo trong đợt thực thi

Đợt audit: **KHÔNG** tạo hồ sơ nào (report-only). Các đợt **thực thi** tạo **2 hồ sơ mới**:

- **HSBC Cash Back — hồ sơ #28** (TT1; template #31, policy #67), `product_name = NULL`.
  - Included (4 dòng): Siêu thị/bách hóa/taxi/limousine (**5411, 4121**); Giáo dục
    (**8211, 8220, 8241, 8244, 8249, 8299, 8351**); Chăm sóc sức khỏe
    (**5047, 5122, 5912, 8011, 8021, 8041, 8042, 8043, 8049, 8050, 8062, 8071, 8099**);
    "Khác" (0 mã, 0,3%).
  - Excluded (11 dòng, mô tả theo **loại giao dịch**, không gắn mã): rút tiền mặt; phí và lãi;
    trả góp; chuyển khoản số dư; chứng khoán/ngoại hối; chuyển tiền trên trang điện tử; nạp thẻ
    trả trước; giao dịch tương tự tiền mặt (quasi-cash); chuyển đổi ngoại tệ; ví điện tử;
    thanh toán hóa đơn trực tuyến qua HSBC.
  - 4 bậc hoàn tiền (nạp qua `credit-card:import-mcc-row-benefits`).
  - 1 tài liệu: `hsbc-cash-back-terms-vn.pdf` (T&C hiệu lực 06/03/2026).

- **VPBank Super Shopee Platinum Visa — hồ sơ #29** (TT2; template #7) — **hồ sơ MÔ TẢ không mã**
  (VPBank không công bố danh sách mã MCC):
  - Included (3 dòng, **0 mã**): Shopee/ShopeeFood (10%); ăn uống/siêu thị (Co.op, BigC, Lottemart, Winmart)
    + Grab/Be (2%); "Tất cả giao dịch còn lại" (0,1%).
  - Excluded (14 dòng, **0 mã**, mô tả theo loại GD): rút tiền mặt; phí và lãi; thuế; nghi ngờ rút tiền/gian lận;
    hủy/hoàn (chargeback); chuyển khoản dư nợ; ví điện tử; chứng khoán/ngoại hối; qua VPBank NEO; chuyển tiền
    trên trang điện tử; nạp thẻ trả trước; chuyển đổi ngoại tệ; xăng dầu/hóa đơn điện/gas/nước/dầu sưởi/CNTT-viễn
    thông; trả góp.
  - 3 bậc hoàn tiền; 1 tài liệu `vpbank-super-shopee-terms.pdf` (756.803 bytes).
  - Note hồ sơ ghi rõ "VPBank không công bố danh sách mã MCC cụ thể…".

## D. Hồ sơ đã hoàn thiện trong đợt thực thi

Đợt audit: **KHÔNG** sửa hồ sơ nào. Đợt **thực thi** hoàn thiện:

- **prof#16 (Sacombank Platinum Amex)** — bổ sung **4511, 4722, 7011** vào dòng included
  "Vé máy bay, khách sạn, đại lý du lịch" (trước **0 mã**).
- **Template #31 HSBC** — cập nhật mô tả, bỏ câu *"HSBC chưa công bố mã MCC"*, ghi rõ nhóm nào
  hoàn bao nhiêu % (dữ liệu lấy từ cùng nguồn T&C).
- **Cơ chế dùng chung** — thêm bảng liên kết để #16/#17/#18 và #20/#21 thấy hồ sơ #14/#2 ở tab
  "Tra cứu thẻ" mà **không nhân bản** dữ liệu (xem Phần J).
- **prof#26 (VIB Online Plus 2in1)** — TT2: **hoàn thiện hồ sơ rỗng**; thêm 4 dòng included (mô tả theo kênh,
  không mã) + 7 dòng excluded (MCC **4900, 4814** + loại giao dịch không mã) + 4 bậc hoàn tiền (xem Phần K).

---

## E. Các trường hợp chưa thể tạo/hoàn thiện (chi tiết)

### E.1 HSBC Cash Back — template #31, policy #67 (hồ sơ: **ĐÃ TẠO #28**)

> **Trạng thái: ĐÃ THỰC HIỆN** — xem Phần J. Nội dung dưới đây là kết quả nghiên cứu/thực thi.

- **Ngân hàng / sản phẩm:** HSBC Việt Nam — thẻ HSBC Cash Back.
- **Nguồn chính thức:** `https://www.hsbc.com.vn/content/dam/hsbc/hbvn/documents/vi/credit-cards/credit-card-cashback-terms-vn.pdf`
  (Điều khoản & Điều kiện Chương trình Hoàn tiền), **hiệu lực 06/03/2026**; truy cập 2026-10-13.
- **Đã xác minh:** tài liệu **có liệt kê mã MCC cụ thể** theo nhóm:
  - Siêu thị / cửa hàng bách hóa / taxi / limousine: **5411, 4121** — hoàn **6%** (nâng **8%** nếu nhận lương
    qua HSBC ≥ 10.000.000 VNĐ/tháng), **trần 200.000 VNĐ/tháng**.
  - Giáo dục: **8211, 8220, 8241, 8244, 8249, 8299, 8351** — **1%**, không trần.
  - Chăm sóc sức khỏe: **5047, 5122, 5912, 8011, 8021, 8041, 8042, 8043, 8049, 8050, 8062, 8071, 8099** — **1%**, không trần.
  - Còn lại: **0,3%** (không gắn mã).
  - Tất cả mã trên **đã có trong master** (293 mã).
- **Thiếu / mâu thuẫn:** mô tả template #31 cũ ghi *"HSBC chưa công bố mã MCC"* — **đã lỗi thời** so với T&C mới.
- **Đã thực hiện:** tạo hồ sơ **#28** cho template #31 với 4 dòng included + 11 dòng excluded (theo loại giao dịch)
  + 4 bậc hoàn tiền + gắn tài liệu T&C; cập nhật mô tả template #31 bỏ câu "chưa công bố mã MCC".
- **Kiểm chứng:** tra mã **5411/4121** → hiển thị "HSBC Cash Back", hoàn tối đa 6% (chưa gồm bậc +2%);
  tra **8211/5047…** → 1%; không có kết quả trùng.

### E.2 VPBank Super Shopee Platinum Visa — template #7 (hồ sơ: **ĐÃ TẠO #29**)

> **Trạng thái: ĐÃ THỰC HIỆN (đợt TT2)** — xem Phần K. VPBank không công bố mã MCC ⇒ hồ sơ mô tả **không mã**.

- **Ngân hàng / sản phẩm:** VPBank — thẻ Super Shopee Platinum Visa.
- **Policy:** #9 (superseded), **#33, #34, #42, #43 (active)**.
- **Nguồn chính thức đã tải:**
  - `https://www.vpbank.com.vn/-/media/vpbank-latest/1retail/files/cards/tbsct_the-le-uu-dai-super-shopee-platinum.pdf`
  - `https://www.vpbank.com.vn/-/media/vpbank-latest/1retail/files/cards/tnc-super-shopee-032023.pdf`
- **Đã xác minh:** tỷ lệ theo danh mục **do Visa/MCC quyết định**; VPBank **KHÔNG công bố danh sách mã MCC cụ
  thể**. Tỷ lệ: 10% Shopee/ShopeeFood (tối đa 400.000đ), 2% ăn uống/siêu thị (Co.op, BigC, LotteMart, Winmart)
  + Grab/Be (tối đa 200.000đ), 0,1% còn lại; điều kiện chi tiêu tối thiểu 4.000.000đ/kỳ.
- **Thiếu:** danh sách mã MCC cụ thể từ nguồn chính thức.
- **Lý do (audit):** chỉ có **tên danh mục**, không có mã ⇒ hồ sơ **không thể có mã** (chỉ dòng mô tả + tài liệu).
- **Đã thực hiện (TT2):** tạo hồ sơ mô tả **#29** — included theo danh mục (không mã) + 14 dòng excluded mô tả
  theo loại GD (rút tiền, phí/lãi, thuế, NEO, xăng dầu/điện/gas/nước/dầu sưởi + viễn thông, hoàn tiền/chargeback,
  gian lận, trả góp…), gắn tài liệu chính thức, note rõ "VPBank không công bố mã MCC".
- **Điều kiện để tiếp tục:** không còn — đã hoàn tất (không thêm mã MCC nào khi chưa có căn cứ).

### E.3 VIB nhiều hạng — template #16/#17/#18 (Max Card Plus/Pro/Elite) & #20/#21 (One Card Platinum/Signature) — **ĐÃ XỬ LÝ (liên kết dùng chung)**

- **Ngân hàng / sản phẩm:** VIB — Max Card Visa và One Card Visa.
- **Thực trạng:**
  - Template **#15** (Starter) có hồ sơ **#14** (`product_name = VIB Max Card Visa`).
  - Template **#19** (Gold) có hồ sơ **#2** (`product_name = VIB One Card Visa`).
  - Template **#16/#17/#18** và **#20/#21** **có policy active** (#52/#53/#54, #56/#57) nhưng **KHÔNG có hồ sơ**
    gắn trực tiếp.
- **Nguồn nội bộ:** `docs/CREDIT_CARD_MCC_WORKFLOW.md` **G.1** ghi *"template #16–18 dùng chung hồ sơ #14;
  #20–21 dùng chung hồ sơ #2"*.
- **Phát hiện quan trọng (cơ chế):** `CardPolicyLookupService::mccProfilesForTemplate()` (dòng 124–127) lọc
  **`where('policy_template_id', $templateId)`** — tức tra cứu theo template **không** gộp theo `product_name`.
  ⇒ Khi người dùng chọn đúng hạng **Plus/Pro/Elite** ở tab **"Tra cứu thẻ"**, hệ thống **trả về không hồ sơ**.
  - Cơ chế gộp `mergeProductMatches()`/`collapseProductGroup()` chỉ áp dụng cho **tra cứu theo mã MCC**
    (`lookupMcc`), nơi mã `5252`… vẫn hiển thị đại diện "VIB Max Card Visa". ⇒ **Tra cứu MCC không bị ảnh hưởng**;
    chỉ tab **Tra cứu thẻ theo từng hạng** bị thiếu.
- **Lý do chưa xử lý (audit):** người dùng chọn **"Document only (no change)"**.
- **Phương án đã chọn & thực hiện:** **phương án 2 (mở rộng ở tầng code, dạng dữ liệu)** — thêm bảng liên kết
  `credit_card_policy_mcc_profile_template_links` để một hồ sơ nhận **nhiều template**; `CardPolicyLookupService`
  và admin dùng `PolicyMccProfile::scopeForTemplate()`. **Không nhân bản** dữ liệu; mã MCC tra cứu vẫn **một
  kết quả đại diện** (giữ cơ chế gộp theo `product_name`). Chi tiết + test ở Phần J.
- **Kiểm chứng:** mỗi hạng #16/#17/#18 trả về **đúng một** hồ sơ #14; #20/#21 trả về **đúng một** hồ sơ #2;
  tra mã MCC không phát sinh kết quả trùng.
- **Điều kiện để tiếp tục:** không còn — đã hoàn tất.

### E.4 Sacombank Platinum Amex — hồ sơ #16 (template #23) — **thiếu mã ở dòng included**

- **Nguồn chính thức:** `sacombank_amex_tnc.pdf`, `sacombank_amex_mcc.pdf` (tài liệu Sacombank đã tải).
- **Đã xác minh:** tài liệu liệt kê mã theo 3 nhóm: Vé máy bay (**4511** + dải **3000–3301**), Khách sạn
  (**3501–3581**), Đại lý du lịch (**4722, 3836, 7011** + dải **3582–3826**).
- **Thiếu:** master **KHÔNG có mã 3xxx**. Chỉ **4511, 4722, 7011** có trong master.
- **Lý do chưa điền (audit):** quy tắc "không đưa mã không có trong master"; KHÔNG tự thêm master trong đợt này.
- **Đã thực hiện:** bổ sung **4511, 4722, 7011** vào dòng included "Vé máy bay, khách sạn, đại lý du lịch"
  (trước 0 mã); giữ note về dải 3000–3836 chưa có trong master. Dòng "Chi tiêu tại TBCNT nước ngoài" giữ **0 mã**
  (theo **quốc gia**, không theo mã). Các dòng excluded giữ nguyên.
- **Tồn đọng có chủ đích:** master **chưa có dải 3xxx** ⇒ các mã máy bay/khách sạn còn lại **chưa thêm**;
  **không** mở rộng master trong đợt này (tách đợt, cần nghiên cứu + căn cứ).
- **Điều kiện để tiếp tục:** nghiên cứu bổ sung master 3xxx (đợt riêng).

### E.5 VIB Online Plus 2in1 — hồ sơ #26 (template #34) — **ĐÃ HOÀN THIỆN**

> **Trạng thái: ĐÃ THỰC HIỆN (đợt TT2)** — xem Phần K. Hồ sơ không còn rỗng.

- **Thực trạng:** hồ sơ #26 có `note` (nguồn VIB, Chương trình Điểm thưởng & Hoàn tiền mục 2.6) nhưng
  **0 dòng, 0 mã, 0 tài liệu**.
- **Nguồn chính thức:** trang chương trình VIB (trực tiếp bị "Challenge Validation"; dùng phần mô tả từ kết quả
  tìm kiếm) — `https://www.vib.com.vn/vn/the-tin-dung/diem-thuong-hoan-tien`.
- **Đã xác minh:** chương trình hiện tại là **điểm thưởng theo KÊNH**: 5% điểm chi tiêu online nước ngoài,
  3% online khác, 0,1% còn lại; tối đa 600.000 điểm/kỳ; cộng cashback "card-on-file" (Grab, Netflix, Tiki,
  Agoda, Booking, iTunes, CH Play, Spotify, Traveloka) 50k/GD, tối đa 100k/kỳ, 300k/khách.
  T&C master VIB hiện hành **loại trừ**: **MCC 4900** (điện/gas/dầu sưởi/vệ sinh/nước) và **MCC 4814**
  (viễn thông) — **cả hai có trong master** — và giao dịch **không có mã MCC** (QR payment, thanh toán hóa đơn qua MyVIB).
- **Lý do (audit):** người dùng chọn report-only.
- **Đã thực hiện (TT2):** (a) thêm **7 dòng excluded** — 2 dòng **có mã** (4900, 4814) + 5 dòng mô tả theo loại
  GD (không mã MCC → giao dịch không có mã MCC/QR/MyVIB; thuế; phí/lãi/trả góp; đặt cọc BĐS; đại lý hóa đơn tiện ích);
  (b) thêm **4 dòng included mô tả theo kênh** (không mã): chi tiêu trực tuyến nước ngoài (5%), trực tuyến khác (3%),
  còn lại (0,1%), card-on-file (50k/GD); (c) **không** gắn tài liệu (không tải được bản PDF gốc — chỉ ghi URL + ngày truy cập).
- **Điều kiện để tiếp tục:** không còn — đã hoàn tất (chỉ dùng MCC 4900/4814 đã có trong master).

### E.6 Cashback phổ thông — template #1, policy #1 — **template khởi đầu, không phải SP ngân hàng**

- **Thực trạng:** policy #1 `status = active`, `user_card_id = NULL`, template không gắn ngân hàng
  (`applicable_bank_id = NULL`), mô tả *"Template khởi đầu: 2 bậc chi tiêu, cashback theo danh mục, có trần."*
- **Lý do:** là template mẫu/khởi đầu dùng chung, **không** đại diện một sản phẩm ngân hàng ⇒ không có căn cứ
  tạo hồ sơ MCC ngân hàng.
- **Đề xuất:** chỉ ghi nhận; không tạo hồ sơ.

---

## F. Vấn đề dữ liệu & rủi ro phát hiện

| # | Vấn đề | Mức độ | Trạng thái sau TT |
|---|---|---|---|
| F1 | **Tra cứu thẻ theo template hạng** không gộp theo `product_name` ⇒ #16–18/#20–21 trả về **không hồ sơ** | Cao (UX sai) | **ĐÃ SỬA** — bảng liên kết + `scopeForTemplate()` (E.3, J) |
| F2 | `docs/CREDIT_CARD_MCC_WORKFLOW.md` **G.1** khẳng định #16–18/#20–21 "dùng chung hồ sơ" — **không khớp** liên kết FK thực tế | Trung bình (tài liệu sai) | **ĐÃ SỬA** — cập nhật G.1 + Phần A.2/A.6 mô tả bảng liên kết |
| F3 | Mô tả template **#31 HSBC** ghi "chưa công bố mã MCC" — **lỗi thời** | Trung bình | **ĐÃ SỬA** — cập nhật mô tả khi tạo hồ sơ #28 (E.1, J) |
| F4 | Master **thiếu toàn bộ dải 3xxx** ⇒ không thể nhập mã máy bay/khách sạn Amex | Trung bình | **Tồn đọng có chủ đích** — bổ sung master ở đợt riêng (E.4) |
| F5 | Hồ sơ **#26 rỗng** (chỉ có note) | Trung bình | **ĐÃ SỬA (TT2)** — hoàn thiện #26: 4 included + 7 excluded (E.5, K) |
| F6 | Các hồ sơ **0 mã included** do hoàn theo **kênh** (#11 BIDV Online, #12 SeABank, #25 TPBank EVO) | Thấp (đúng thiết kế) | Giữ nguyên; đã ghi nhận là hợp lệ |
| F7 | Template **#7** có 4 policy active + 1 superseded (nhiều bản ghi) | Thấp | Xác nhận không nhân bản hồ sơ; hồ sơ theo template |
| F8 | Dòng hồ sơ **không có mã MCC** hiển thị **TRỐNG** ở tab "Tra cứu thẻ" ⇒ dễ hiểu nhầm là đã kiểm tra đầy đủ | Thấp (UX/đúng đắn) | **ĐÃ SỬA (TT2)** — thêm nhãn "Chưa có thông tin mã MCC" cho dòng không mã (K) |
| F9 | Template **#7 VPBank Super Shopee** không có hồ sơ | Trung bình | **ĐÃ SỬA (TT2)** — tạo hồ sơ MÔ TẢ #29 không mã (E.2, K) |

---

## G. Kiểm thử & kiểm tra

- **Đợt audit KHÔNG thay đổi code/CSDL.** Đợt **thực thi** thay đổi code + CSDL; kết quả kiểm thử:
  - **Baseline tra cứu (audit):** `php artisan test --filter "CardLookupTest|CardLookupMccProfileTest"`
    → **42 passed (203 assertions)**.
  - **Sau thực thi:** cùng bộ lọc → **45 passed (227 assertions)** (thêm **3 test** cho hồ sơ dùng chung);
    `CreditCardMccManagementTest` → **15 passed (69 assertions)**.
  - `php -l` tệp thay đổi: không lỗi cú pháp; `vendor\bin\pint --test`: **passed**;
    `git diff --check`: **sạch**.
- **Nhất quán dữ liệu (đo trực tiếp sau thực thi):** mã thiếu master = **0**; overlap included/excluded = **0**;
  tài liệu thiếu file = **0**; template có >1 hồ sơ riêng = **0**; liên kết pivot hợp lệ = **7**.
- **Kiểm chứng tra cứu (sau thực thi):** #15/#16/#17/#18 → prof#14; #19/#20/#21 → prof#2; #31 → prof#28;
  mã 5411 (HSBC + 10 chính sách khác) không trùng; mã 4511 xuất hiện đúng 1 lần cho VIB Max và 1 lần cho VIB One.
- **Đợt TT2 (VPBank #29 + VIB #26):**
  - `CardLookupTest` + `CardLookupMccProfileTest` + `CreditCardMccManagementTest` → **62 passed (301 assertions)**
    (thêm **2 test**: dòng mô tả không mã trả `codes=[]`; trang Tra cứu có nhãn "Chưa có thông tin mã MCC").
  - `php -l` tệp thay đổi: không lỗi cú pháp; `vendor\bin\pint --test`: **passed**; `git diff --check`: **sạch**.
  - **Nhất quán TT2:** **29** hồ sơ (không trùng theo template); prof#29: 3 included/14 excluded/**0 mã**/1 tài liệu/3 bậc;
    prof#26: 4 included/7 excluded (chỉ **4900, 4814**)/0 tài liệu/4 bậc; cả hai mã có trong master; overlap = **0**.
  - **Kiểm chứng tra cứu TT2:** template #7 → prof#29 (dòng không mã hiển thị "Chưa có thông tin mã MCC");
    template #34 → prof#26; tra mã **4900** và **4814** → VIB Online Plus 2in1 hiển thị quan hệ **excluded**;
    VPBank (0 mã) **không** phát sinh kết quả giả.

---

## H. Quyết định & trạng thái

1. **HSBC #31 (E.1):** ✅ **ĐÃ THỰC HIỆN (TT1)** — tạo hồ sơ #28 đầy đủ mã + gắn T&C + sửa mô tả.
2. **VPBank Super Shopee #7 (E.2):** ✅ **ĐÃ THỰC HIỆN (TT2)** — tạo hồ sơ **MÔ TẢ** #29 không mã + tài liệu.
3. **VIB nhiều hạng #16–18/#20–21 (E.3):** ✅ **ĐÃ THỰC HIỆN (TT1)** — bảng liên kết dùng chung (phương án 2 dạng
   dữ liệu ở tầng code), không nhân bản hồ sơ.
4. **Sacombank Amex #16 (E.4):** ✅ **ĐÃ THỰC HIỆN** một phần — thêm **4511/4722/7011**; đợt **bổ sung master 3xxx**
   **vẫn để mở** (tách riêng).
5. **VIB Online Plus 2in1 #26 (E.5):** ✅ **ĐÃ THỰC HIỆN (TT2)** — hoàn thiện #26 (excluded 4900/4814 + dòng mô tả).

> Đã thực hiện: **1, 2, 3, 4, 5**. **Không còn quyết định để mở.** Tồn đọng có chủ đích: master dải 3xxx.

---

## J. Nhật ký thực thi (2026-10-13)

### J.1 Hồ sơ dùng chung nhiều template (VIB)

- **Vấn đề gốc:** `credit_card_policy_mcc_profiles` chỉ có **một** `policy_template_id`; tra cứu theo template
  lọc `where('policy_template_id', $id)` ⇒ 5 hạng VIB (#16–18, #20–21) không thấy hồ sơ.
- **Giải pháp:** bảng liên kết **`credit_card_policy_mcc_profile_template_links`**
  (`policy_mcc_profile_id` + `policy_template_id`, UNIQUE đôi, FK cascade). Hồ sơ vẫn có **một template chủ**
  (`policy_template_id` giữ nguyên) để không phá service/tạo/sửa hiện có; các template khác **liên kết thêm**.
- **Code:**
  - Model mới `app/Models/CreditCard/PolicyMccProfileTemplateLink.php`.
  - `PolicyMccProfile`: `templateLinks()` (HasMany), `sharedTemplates()` (BelongsToMany),
    `scopeForTemplate()` = chủ **hoặc** có liên kết, `allTemplateIds()` = [chủ + các template liên kết].
  - `CardPolicyLookupService::mccProfilesForTemplate()` dùng `forTemplate()`;
    `profileExcludedTemplateIds()` mở rộng phạm vi loại trừ theo `allTemplateIds()`.
  - `PolicyMccAdminController` (index) cũng lọc theo `forTemplate()`.
- **Dữ liệu seed (guarded, `insertOrIgnore`):** #14 → #15/#16/#17/#18; #2 → #19/#20/#21 (**7 liên kết**).
- **Bất biến:** tra mã MCC vẫn **một kết quả đại diện** (gộp theo `product_name`); **không** nhân bản dòng/mã/tài liệu.

### J.2 HSBC Cash Back (template #31)

- Tạo hồ sơ **#28** + 4 dòng included (3 dòng có mã, 1 dòng "Khác" 0 mã) + 11 dòng excluded (theo loại GD)
  + gắn `hsbc-cash-back-terms-vn.pdf` (311.438 bytes, disk `local`, path `credit-card-mcc/28/…pdf`).
- Nạp **4 bậc** hoàn tiền qua `credit-card:import-mcc-row-benefits` (mục mới `hsbc-cash-back` trong
  `database/data/credit_card_mcc_row_benefits.php`).
- Cập nhật mô tả template #31 trong `CreditCardSeeder` (bỏ "chưa công bố mã MCC").

### J.3 Sacombank Amex (hồ sơ #16)

- Thêm **4511, 4722, 7011** vào dòng included "Vé máy bay, khách sạn, đại lý du lịch" (trước 0 mã).
- Dải 3xxx **không** thêm (master chưa có) — tồn đọng có chủ đích.

### J.4 Tệp thay đổi

- **Mới:** migration `2026_10_13_000006_create_credit_card_policy_mcc_profile_template_links_table.php`;
  migration `2026_10_13_000007_seed_hsbc_cash_back_mcc_profile_and_sacombank_amex_codes.php`;
  `app/Models/CreditCard/PolicyMccProfileTemplateLink.php`;
  `database/data/credit_card_documents/hsbc-cash-back-terms-vn.pdf`.
- **Sửa:** `app/Models/CreditCard/PolicyMccProfile.php`; `app/Services/CreditCard/CardPolicyLookupService.php`;
  `app/Http/Controllers/Admin/CreditCard/PolicyMccAdminController.php`;
  `database/data/credit_card_mcc_row_benefits.php`; `database/seeders/CreditCardSeeder.php`;
  `tests/Feature/CreditCard/CardLookupMccProfileTest.php`.
- **Áp dụng:** chỉ qua `php artisan credit-card:migrate` (000006 DONE, 000007 DONE).
- **Không** commit/push/deploy.

---

## K. Nhật ký thực thi 2 (2026-10-13) — VPBank Super Shopee & VIB Online Plus 2in1

### K.1 VPBank Super Shopee Platinum Visa (template #7) — hồ sơ MÔ TẢ #29

- **Quyết định:** tạo **hồ sơ mô tả** vì VPBank không công bố danh sách mã MCC.
- **Dữ liệu:** 3 dòng included (Shopee/ShopeeFood 10%/400k; ăn uống-siêu thị-Grab/Be 2%/200k; còn lại 0,1%)
  + 14 dòng excluded (mô tả theo loại GD) — **tất cả 0 mã**; 3 bậc hoàn tiền; 1 tài liệu.
- **Note hồ sơ (trích):** "VPBank không công bố danh sách mã MCC cụ thể trong tài liệu đã rà soát. Hồ sơ này mô tả
  quyền lợi, không phải danh sách MCC đã xác minh."
- **Nguồn:** `vpbank-super-shopee-terms.pdf` (Thể lệ ưu đãi dài hạn Super Shopee Platinum, mục 7.1 Hoàn tiền).
- **Lưu ý:** VPBank **không nêu ngày hiệu lực** trong tài liệu ⇒ không ghi nhận hiệu lực.

### K.2 VIB Online Plus 2in1 (hồ sơ #26, template #34) — hoàn thiện

- **Dữ liệu:** 4 dòng included **mô tả theo kênh** (không mã): trực tuyến nước ngoài 5%, trực tuyến khác 3%,
  còn lại 0,1% (gồm phí bảo hiểm Prudential qua MyVIB), card-on-file 50k/GD; 7 dòng excluded: **MCC 4900**,
  **MCC 4814** (loại trừ theo T&C VIB) + 5 dòng mô tả theo loại GD (không mã).
- **Nguồn:** "Chương trình điểm thưởng/hoàn tiền thẻ tín dụng VIB" (hiệu lực **06/03/2025**), mục 2.6;
  URL tham chiếu `https://www.vib.com.vn/vn/the-tin-dung/diem-thuong-hoan-tien` (truy cập **12/10/2026**).
- **Không gắn tài liệu:** trang VIB bị "Challenge Validation", không tải được bản PDF gốc ⇒ ghi URL + ngày truy cập.
- **Nguyên tắc:** không gán mã cho các nhóm theo kênh; không đồng nhất "giao dịch không có mã MCC (QR/MyVIB)"
  với 4900/4814.

### K.3 Hiển thị "Chưa có thông tin mã MCC"

- `resources/views/credit-card/lookup.blade.php` (tab Tra cứu thẻ): dòng included/excluded **không có mã** nay
  hiển thị nhãn **"Chưa có thông tin mã MCC"** thay vì bỏ trống (tránh hiểu nhầm đã kiểm tra đầy đủ).

### K.4 Tệp thay đổi (đợt TT2)

- **Mới:** migration `2026_10_13_000008_seed_vpbank_super_shopee_and_complete_vib_online_plus_mcc_profiles.php`;
  `database/data/credit_card_documents/vpbank-super-shopee-terms.pdf`.
- **Sửa:** `database/data/credit_card_mcc_row_benefits.php` (thêm `vpbank-super-shopee-platinum-visa` và
  `vib-online-plus-2in1`); `resources/views/credit-card/lookup.blade.php`;
  `tests/Feature/CreditCard/CardLookupMccProfileTest.php` (+2 test).
- **Áp dụng:** chỉ qua `php artisan credit-card:migrate` (**000008 DONE**); nạp bậc qua `MccRowBenefitImporter`
  (đã nạp 2 hồ sơ, 7 dòng, 7 bậc). **Không** commit/push/deploy.

---

## L. Nhật ký thực thi 3 (2026-10-14) — cập nhật hồ sơ VPBank Z Card JCB theo thể lệ mới

### L.1 Bối cảnh & nguồn

- **Hồ sơ #7** ("VPBank Z Card JCB - Thể lệ ưu đãi dài hạn", template **#6** `vpbank-z-card-jcb`) trước đây
  theo bản thể lệ **2025** (10%/4 triệu/200k–100k) — **cùng một sản phẩm** nhưng đã bị thay bằng thể lệ mới.
- **Nguồn:** VPBank — "Thể lệ ưu đãi dài hạn dành cho chủ thẻ tín dụng VPBank Z JCB"
  (`The-le-uu-dai-dai-han-danh-cho-chu-the-tin-dung-VPBank-Z-JCB-Web.pdf`, truy cập 14/10/2026).
- **Hiệu lực:** từ **27/08/2026** đến hết **31/12/2027**.
- **BIN:** 35677200.

### L.2 Nội dung ưu đãi mới (mục 9.2.2)

| Nhóm | Tỷ lệ | Trần/nhóm | MCC (Bảng 1) |
|---|---|---|---|
| Ăn uống | 15% | 500.000 đ/kỳ sao kê | 5811, 5812, 5813, 5921, **5462** |
| Giải trí & Fitness | 15% | 500.000 đ/kỳ sao kê | 5734, 5735, 7832, 7997 |
| Di chuyển & đặt đồ ăn | 15% | 500.000 đ/kỳ sao kê | 4121, 5814 |
| Ngành hàng khác | 0% | — | — |

- **Tổng hạn mức hoàn tiền cả 3 nhóm: 1.000.000 đ/kỳ sao kê** (trần chung, mục 9.2.2 + 9.2.3).
- **Loại trừ (giao dịch không hợp lệ):** rút tiền mặt; phí & lãi; thuế hàng hóa dịch vụ; giao dịch nghi ngờ rút
  tiền mặt/gian lận; hủy/hoàn (charge back); chuyển khoản dư nợ; ví điện tử; mua bán chứng khoán/ngoại hối;
  qua VPBank NEO; chuyển tiền trên trang điện tử; nạp tiền thẻ thanh toán trước; chuyển đổi ngoại tệ; **và các
  giao dịch khác do Ngân hàng quy định** — mô tả theo loại giao dịch, **không gán mã MCC**.

### L.3 Thay đổi dữ liệu (trước → sau)

- **Đặc thù:** cả **danh mục mã MCC** lẫn **tỷ lệ/trần** đều thay đổi ⇒ **không** chỉnh tại chỗ mà **tái tạo**
  toàn bộ dòng của hồ sơ theo thể lệ mới (tránh sót dữ liệu cũ 10%/4 triệu trái thể lệ).
- **Included:** thêm **5462** vào nhóm "Ăn uống"; đổi tên "Giải trí và Fitness" → **"Giải trí & Fitness"**,
  "Di chuyển và đặt đồ ăn" → **"Di chuyển & đặt đồ ăn"**; thêm dòng **"Ngành hàng khác"** (0%). Trần mỗi nhóm
  **200k/200k/100k → 500k/500k/500k**; tỷ lệ **10% → 15%**; **bỏ điều kiện tối thiểu 4 triệu** (thể lệ mới không nêu).
- **Excluded:** **0 → 13** dòng mô tả theo loại giao dịch (không mã).
- **Tài liệu:** gỡ bản cũ `vpbank_zcard.pdf` (2025, đã hết hiệu lực) và gắn bản mới
  `vpbank-z-card-jcb-uu-dai-dai-han-2026.pdf` (395.052 bytes, disk `local`, path `credit-card-mcc/7/…pdf`).
- **Bậc hoàn tiền:** nạp lại **4 bậc** qua `credit-card:import-mcc-row-benefits` (mục `vpbank-z-card-jcb`).

### L.4 Giới hạn / lệch dữ liệu cần lưu ý

- **Trần TỔNG 1.000.000 đ/kỳ** (trần chung của cả 3 nhóm) **CHƯA có trường riêng** ở cấp hồ sơ MCC
  (`credit_card_policy_mcc_profiles` chỉ có `note`, `annual_fee_*`). Hiện **ghi nhận tại `note` của hồ sơ** và
  **trường `condition_note` của từng bậc**; cấp dòng vẫn lưu trần **500.000 đ/nhóm** đúng mô hình. *Đề xuất tối
  thiểu (chưa thực hiện):* thêm cột `cashback_cap_per_period` (decimal, nullable) cho hồ sơ — **không đụng engine/
  blueprint**.
- **Lệch nhãn MCC 5734/5735:** tài liệu ghi `5734 = "Record Shops"`, `5735 = "Record Stores"`; master hệ thống
  hiển thị `5734 = "Computer Software Stores"`, `5735 = "Record Stores"`. **Cả hai mã đều tồn tại**; chỉ khác
  nhãn mô tả (không tạo mã, không sửa master).
- **Mã MCC:** thể lệ nêu **11 mã** cho 3 nhóm; tất cả **đã có trong master** ⇒ **0 mã thiếu** (không tạo mã mới).

### L.5 Tệp thay đổi

- **Mới:** migration `2026_10_14_000009_update_vpbank_z_card_jcb_mcc_profile.php`;
  `database/data/credit_card_documents/vpbank-z-card-jcb-uu-dai-dai-han-2026.pdf`;
  báo cáo `docs/CREDIT_CARD_MCC_VPBANK_Z_CARD_JCB_UPDATE_REPORT.md`.
- **Sửa:** `database/data/credit_card_mcc_row_benefits.php` (mục `vpbank-z-card-jcb`);
  `config/credit_card_mcc_categories.php` (thêm alias `Giải trí & Fitness`, `Di chuyển & đặt đồ ăn`, `Ngành hàng khác`).
- **Áp dụng:** `php artisan credit-card:migrate` (**000009 DONE**) rồi
  `php artisan credit-card:import-mcc-row-benefits` (hồ sơ 26, dòng 80, **4 bậc tạo mới**).
- **Trước khi commit:** hạ tầng Diff/Pint/tests OK (xem báo cáo riêng). **Không** commit/push/deploy.

---

## Phụ lục — Lệnh & đường dẫn nhanh

- CSDL: `hoantien_creditcard` (connection `creditcard`).
- Trích xuất kiểm toán: `%TEMP%\opencode\audit_inventory.json`, `sum_report.txt`, `gap_out.txt`.
- Nguồn HSBC: `credit-card-cashback-terms-vn.pdf` (hiệu lực 06/03/2026) → `%TEMP%\opencode\audit2\hsbc.txt`.
- Nguồn VPBank Super Shopee: `tbsct_the-le-uu-dai-super-shopee-platinum.pdf`, `tnc-super-shopee-032023.pdf`.
- Tra cứu theo template: `app/Services/CreditCard/CardPolicyLookupService.php:124` (`mccProfilesForTemplate`).
- Tra cứu theo mã + gộp sản phẩm: cùng file — `lookupMcc()` / `mergeProductMatches()` (dòng ~473).
- Controller tra cứu: `app/Http/Controllers/CreditCard/CreditCardLookupController.php:76,110`.
- Quy tắc VIB (cần đối chiếu): `docs/CREDIT_CARD_MCC_WORKFLOW.md` Phần G.
- Nguồn VIB Online Plus 2in1 (TT2): "Chương trình điểm thưởng/hoàn tiền thẻ tín dụng VIB" (hiệu lực 06/03/2025),
  mục 2.6; `%TEMP%\opencode\vib_pdf_text.txt`; URL `https://www.vib.com.vn/vn/the-tin-dung/diem-thuong-hoan-tien`.
- Nguồn VPBank Super Shopee (TT2): `database/data/credit_card_documents/vpbank-super-shopee-terms.pdf`
  (Thể lệ ưu đãi dài hạn Super Shopee Platinum).
- Migration đợt TT2: `database/migrations/creditcard/2026_10_13_000008_seed_vpbank_super_shopee_and_complete_vib_online_plus_mcc_profiles.php`.
- Nguồn VPBank Z Card JCB (đợt TT3, cập nhật): `database/data/credit_card_documents/vpbank-z-card-jcb-uu-dai-dai-han-2026.pdf`
  (thể lệ hiệu lực 27/08/2026–31/12/2027); migration `database/migrations/creditcard/2026_10_14_000009_update_vpbank_z_card_jcb_mcc_profile.php`.
