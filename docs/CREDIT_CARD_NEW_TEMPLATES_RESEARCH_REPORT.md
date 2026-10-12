# CREDIT_CARD_NEW_TEMPLATES_RESEARCH_REPORT

Báo cáo nghiên cứu & triển khai **10 chính sách hoàn tiền thẻ tín dụng** trên module Thẻ tín dụng (engine hiện có). Ngày đối chiếu nguồn chính thức: **12/10/2026**.

Seeder chịu trách nhiệm: `Database\Seeders\CreditCardSeeder::seedResearchedTemplates()` — lần chạy đầu tiên của loạt thẻ mới này là ở file này (350-450 dòng), idempotent, không xoá-rồi-tạo, không ghi đè blueprint đã được admin chỉnh.

---

## 1. Tổng kết nhanh

| # | Tên định danh trong yêu cầu | Tên sản phẩm CHÍNH THỨC | Mạng lưới chính thức | Trạng thái | Template slug |
|---|---|---|---|---|---|
| 1 | Sacombank Visa Platinum Cashback | Sacombank Visa Bạch Kim Hoàn Tiền | Visa | ✅ HOÀN THÀNH | `sacombank-visa-platinum-cashback` |
| 2 | Sacombank Amex | SACOMBANK Platinum American Express | Amex | ✅ HOÀN THÀNH | `sacombank-platinum-amex` |
| 3 | MB JCB Platinum | Thẻ tín dụng quốc tế MB JCB Platinum | JCB | ✅ HOÀN THÀNH | `mb-jcb-platinum` |
| 4 | UOB One Visa | UOB One Credit Card | Visa/Mastercard — CẦN XÁC NHẬN | ✅ HOÀN THÀNH | `uob-one` |
| 5 | VPBank Flex Visa | Thẻ đa năng 2 trong 1 VPBank Flex | **Mastercard** (léch tên yêu cầu) | ✅ HOÀN THÀNH | `vpbank-flex` |
| 6 | Shinhan Cashback 365 Visa | Shinhan 365 Cashback Platinum | **Mastercard** (léch tên yêu cầu) | ✅ HOÀN THÀNH | `shinhan-365-cashback-platinum` |
| 7 | Shinhan MyLife Visa | Shinhan MyLife Platinum | Visa | ✅ HOÀN THÀNH | `shinhan-mylife-visa-bach-kim` |
| 8 | Cake Premium Mastercard | Thẻ Cake 2in1 – Gói Premium | **Visa** Signature (léch tên yêu cầu) | ✅ HOÀN THÀNH | `cake-2in1-premium` |
| 9 | VPBank Reward Visa | (không tìm thấy sản phẩm chính thức) | — | ⛔ CHƯA ĐỦ DỮ LIỆU | — |
| 10 | Shinhan Cashback Visa | Thẻ tín dụng quốc tế Visa Cash Back | Visa | ⛔ CẦN XÁC NHẬN | — |

> ⚠️ **Lưu ý quan trọng**: 4/10 tên định danh lệch với sản phẩm chính thức (VPBank Flex là Mastercard; Shinhan 365 là Mastercard; Cake Premium là Visa Signature; UOB One không công bố rõ mạng lưới). Trong seeder bắt buộc dùng **tên + mạng lưới CHÍNH THỨC**, thể hiện qua `name` và `applicable_product_match` của template.

---

## 2. Chi tiết từng sản phẩm

### 2.1 Sacombank Visa Platinum Cashback — `sacombank-visa-platinum-cashback`
- **Ngân hàng / từ khoá áp dụng**: `stb` / `platinum cashback` (không đụng `sacombank-uniq-visa`).
- **Nguồn**: Quy định hoàn tiền thẻ SACOMBANK Visa Bạch Kim Hoàn Tiền (PDF chính thức) + trang sản phẩm.
- **Cấu hình**: 1 bậc từ 0đ.
  - Siêu thị & cửa hàng tiện lợi: **6,8%**
  - Di chuyển: **16,8%**
  - Giao dịch khác: **0,39% "không giới hạn"**
  - Trần **chung** nhóm ưu đãi: **680.000đ/kỳ TBGD** → mô hình bằng trần toàn bậc (`max_cashback_per_period = 680000`), fallback đặt `counts_toward_tier_cap = false`.
  - Mỗi giao dịch ≥ **100.000đ** mới được hoàn → `min_transaction_amount` áp cho cả rule ưu đãi lẫn fallback.
- **Ghi chú engine**: "Cửa hàng tiện lợi" là danh mục riêng trên DB live nhưng không nằm trong bộ seed gốc ⇒ gộp vào `sieu-thi-tien-loi`. Mức hoàn tối thiểu 10.000đ/kỳ không phản ánh được ở engine (không có cột mức tối thiểu hoàn).

### 2.2 Sacombank Platinum American Express — `sacombank-platinum-amex`
- **Ngân hàng / từ khoá áp dụng**: `stb` / `platinum american express`.
- **Nguồn**: trang chính thức Sacombank.
- **Cấu hình**: 1 bậc từ 0đ.
  - Vé máy bay, khách sạn, đại lý du lịch: **20%** (`ve-may-bay-du-lich` + `khach-san-luu-tru`, trỏ thẳng)
  - Giao dịch tại TBCNT nước ngoài: **10%** (`chi-tieu-nuoc-ngoai`)
  - Giao dịch trực tuyến nước ngoài: **5%** — KHÔNG tách riêng được (cùng danh mục `chi-tieu-nuoc-ngoai`); cấu hình 10%.
  - Các giao dịch khác: **0,5%**
  - Trần: **1.000.000đ/kỳ TBGD** đánh trên TẤT CẢ (kể cả nền) → tier cap `1000000.00`, fallback `counts_toward_tier_cap = true`.
- **Ghi chú engine**: trang chính thức quảng cáo "20%" cho vé máy bay/khách sạn; tỷ lệ thực theo MCC của TBCNT nước ngoài (10% POS / 5% online) bị gom chung — đã ghi trong `description` template.

### 2.3 MB JCB Platinum — `mb-jcb-platinum`
- **Ngân hàng / từ khoá áp dụng**: `mb` / `jcb platinum` (không đụng `mb-ultimate-jcb`).
- **Nguồn**: T&C "Thẻ tín dụng quốc tế MB JCB Platinum" hiệu lực **01/11/2025**.
- **Cấu hình**: 2 bậc theo tổng chi tiêu/kỳ.
  - Bậc 1 (< 5.000.000đ): Ẩm thực (MCC 5812) + Siêu thị Nhật Bản (Aeon, Muji, FujiMart, FamilyMart, Ministop) **5%**, trần **200.000đ/kỳ**.
  - Bậc 2 (≥ 5.000.000đ): cùng nhóm **10%**, trần **500.000đ/kỳ**.
  - Ngoài nhóm: **0%** (tỷ lệ "khác" không được T&C công bố).
- **Xung đột dữ liệu đã xử lý**: một số aggregator nói nhóm Shopee/TikTok Shop (MCC 5399) được hoàn tiền; **ưu tiên T&C chính thức** ⇒ không cấu hình nhóm này.
- **Ghi chú engine**: "Siêu thị Nhật Bản" là danh sách nhà bán lẻ cụ thể, xấp xỉ bằng `sieu-thi-tien-loi`.

### 2.4 UOB One (Credit Card) — `uob-one`
- **Ngân hàng / từ khoá áp dụng**: `uob` / `uob one`.
- **Nguồn**: trang chính thức UOB.
- **Cấu hình**: 1 bậc từ 0đ (chương trình Điểm Thưởng → quy đổi hoàn tiền, mô hình hoá như % — tiền lệ VIB).
  - Grab & di chuyển mặt đất: **10%** (`goi-xe-di-chuyen`)
  - Gói đăng ký định kỳ (Apple Music, Spotify, LinkedIn...): **5%** (`ung-dung-so-dich-vu-giai-tri`)
  - Bảo hiểm: **3%** (`bao-hiem`)
  - Mua sắm (trung tâm thương mại & chuỗi thời trang): **2%** (`thoi-trang`)
  - Các chi tiêu khác: **0,3%** không giới hạn (`counts_toward_tier_cap = false`)
  - Trần 4 nhóm ưu đãi: **600.000 Điểm Thưởng/tháng** → tier cap `600000.00`.
- **CẦN XÁC NHẬN (dán nhãn trong `description`)**: (a) mạng lưới có BIN cả Visa lẫn Mastercard; (b) tỷ lệ quy đổi 1 điểm = 1đ giả định để đặt trần theo đ — khi xác nhận chính thức phải kiểm lại.

### 2.5 VPBank Flex Mastercard — `vpbank-flex`
- **Ngân hàng / từ khoá áp dụng**: `vpb` / `flex` (name template = "VPBank Flex Mastercard").
- **Nguồn**: trang chính thức VPBank.
- **Cấu hình**: 2 bậc theo tổng doanh số thanh toán tín dụng/kỳ.
  - Bậc 1 (< 2.000.000đ): chưa đủ điều kiện ⇒ **0%**.
  - Bậc 2 (≥ 2.000.000đ): nhóm được chọn **8% điểm LynkiD** (mặc định **Ăn uống** = `am-thuc-an-uong`), trần **500.000đ/tháng**.
  - Ngoài nhóm: **0%** (tỷ lệ chưa công bố).
- **Ghi chú engine**: chọn 1 trong 4 nhóm (Ăn uống / Du lịch / Thể thao / Mua sắm) không có cơ chế "chọn 1" trong engine — cấu hình mặc định nhóm Ăn uống, admin đổi qua quản lý chính sách khi khách đăng ký nhóm khác.

### 2.6 Shinhan 365 Cashback Platinum — `shinhan-365-cashback-platinum`
- **Ngân hàng / từ khoá áp dụng**: `shbvn` / `365 cashback` (name template dùng tên chính thức).
- **Nguồn**: trang & T&C chính thức Shinhan (nước ngoài gồm cả "cashback thẻ 365" — chương trình Mastercard).
- **Cấu hình**: 2 bậc theo tổng chi tiêu/kỳ.
  - Bậc 1 (< 3.000.000đ): chỉ nền **0,4%**.
  - Bậc 2 (≥ 3.000.000đ):
    - Siêu thị / tạp hóa: **10%** (POS) — trần **400.000đ/kỳ** (tier cap `400000.00`, `counts_toward_tier_cap = true`)
    - Chi tiêu nước ngoài: **3%** (POS) — trần riêng **200.000đ/kỳ** (cap danh mục `200000`, không tính vào trần toàn bậc)
    - Còn lại: **0,4%** không giới hạn.
- **Ghi chú engine**: tỷ lệ 12% khi đi kèm thẻ debit Be-SAFE không mô hình được (phụ thuộc sản phẩm phụ kiện); giới hạn "chỉ POS" không có cờ kênh — giao dịch online rơi vào đúng danh mục vẫn có thể bị tính nhầm.

### 2.7 Shinhan MyLife Platinum — `shinhan-mylife-visa-bach-kim`
- **Ngân hàng / từ khoá áp dụng**: `shbvn` / `mylife`.
- **Nguồn**: trang & T&C chính thức Shinhan (chương trình điểm my point, 1 điểm = 1đ).
- **Cấu hình**: 2 bậc theo tổng chi tiêu/kỳ.
  - Bậc 1 (< 3.000.000đ): nền **0,4%**.
  - Bậc 2 (≥ 3.000.000đ):
    - Di chuyển; Siêu thị & cửa hàng tiện lợi; Ăn uống; Giải trí: **10% điểm** mỗi nhóm (`goi-xe-di-chuyen`, `sieu-thi-tien-loi`, `am-thuc-an-uong`, `ung-dung-so-dich-vu-giai-tri`)
    - Trần **300.000đ/nhóm/kỳ** (cap danh mục `300000`), tổng tối đa **1.200.000đ/kỳ**.
    - Còn lại: **0,4%** không giới hạn.
- **Ghi chú engine**: "Giải trí" của MyLife theo MCC rộng (rạp phim, karaoke...) — xấp xỉ `ung-dung-so-dich-vu-giai-tri`.

### 2.8 Thẻ Cake 2in1 – Gói Premium — `cake-2in1-premium`
- **Ngân hàng / từ khoá áp dụng**: `cakevpb` / `cake 2in1` (name template = "Thẻ Cake 2in1 – Gói Premium").
- **Nguồn**: trang & T&C chính thức Cake by VPBank (12/10/2026).
- **Cấu hình**: 2 bậc theo tổng chi tiêu/kỳ.
  - Bậc 1 (< 20.000.000đ): **0%**.
  - Bậc 2 (≥ 20.000.000đ): nhóm được chọn **20%** (mặc định **Giáo dục** = `giao-duc-hoc-phi`), trần **200.000đ/giao dịch** (`max_cashback_per_transaction`), trần **2.000.000đ/kỳ** (hạng thấp nhất) — tier cap `2000000.00`.
- **Ghi chú engine**: (a) chọn 1 trong 3 nhóm (Giáo dục / Đồ điện tử–điện máy / Du lịch–khách sạn–hàng không) — cấu hình mặc định nhóm Giáo dục, admin đổi theo đăng ký; (b) hạng CASA (2M/5M/10M theo số dư tiết kiệm + doanh số) không mô hình được bằng bậc chi tiêu — dùng trần 2M (thấp nhất).

---

## 3. Sản phẩm KHÔNG triển khai template

### 3.1 VPBank Reward Visa — ⛔ CHƯA ĐỦ DỮ LIỆU
Không tìm thấy sản phẩm **Visa** chính thức tên "Reward" của VPBank (gần nhất là "S Rewards Mastercard" — chương trình khác, không phải hoàn tiền theo cơ chế hiện có). Không tạo template để tránh dựng cấu hình tưởng tượng. Cần ngân hàng xác nhận sản phẩm & T&C trước khi triển khai.

### 3.2 Shinhan Cashback Visa — ⛔ CẦN XÁC NHẬN
Chương trình chính thức "Thẻ tín dụng quốc tế Visa Cash Back" có 3 hạng (Chuẩn/Vàng/Bạch Kim) với **xung đột dữ liệu** giữa trang live (0,3%/0,3%/0,4%) và T&C (0,3%/0,4%/0,5%), và ưu đãi Ăn uống **+5% CUỐI TUẦN** (MCC 5812/5813/5814) khi tổng chi tiêu ≥10.000.000đ — engine không lọc theo ngày trong tuần, và phải chốt 1 trong 3 hạng. Cần xác nhận hạng mục tiêu + tỷ lệ thống nhất trước khi seed.

---

## 4. Quy ước mô hình hoá (engine hiện có)

- **Trần "tối đa/kỳ"** → `max_cashback_per_period` trên bậc (Cap toàn bậc, Lớp 3). Chỉ các rule có `counts_toward_tier_cap = true` tiêu thụ trần này ⇒ nền "không giới hạn" đặt cờ `false`.
- **Trần theo nhóm riêng** (vd. nước ngoài 200k của 365) → `max_cashback_per_category_per_period` trên chính rule đó.
- **Trần theo giao dịch** (Cake 200k/giao dịch) → `max_cashback_per_transaction`.
- **Điều kiện tổng chi tiêu/kỳ** → tách bậc theo `[min_total_spend, max_total_spend)` (khoảng nửa mở), engine tự chọn bậc theo tổng chi tiêu kỳ và áp **retroactive** cho cả kỳ.
- **Chương trình ĐIỂM quy đổi 1:1** → mô hình hoá như phần trăm hoàn tiền (tiền lệ VIB Max Card Visa / One Card), nhãn rõ trong `description`.
- **Chọn-1-trong-N nhóm** (Flex, Cake) → dùng nhóm mặc định; admin đổi qua module quản lý chính sách (có `data-testid`/API chuẩn, không cần tạo thêm cơ chế).
- **Kênh POS/online** (Shinhan 365, Amex) → không có cờ kênh; gom về một danh mục và ghi nhận hạn chế trong `description`.

## 5. Ràng buộc & kiểm thử

- **Không đụng master data cũ**: không tạo combo mới, không sửa category; chỉ trỏ danh mục nằm trong bộ seed gốc (19 danh mục) để DB test (mới) và DB live khớp nhau.
- **Idempotent**: chạy lại seeder nhiều lần không nhân bản template/blueprint/rule; blueprint đã tồn tại (admin đã chỉnh) sẽ KHÔNG bị ghi đè.
- **Applicability**: mỗi template ràng buộc `applicable_bank_id` + `applicable_product_match`; từ khoá được chọn để không khớp nhầm các template hiện có của cùng ngân hàng.
- Test: `tests/Feature/CreditCard/ResearchedTemplatesPolicyTest.php` (11 test) — cấu trúc blueprint, idempotency, applicability (khớp + loại), và hành vi engine thật cho Sacombank Visa (16,8%/6,8%/0,39% + trần 680k + min giao dịch), Amex (20%/10%/0,5% + trần 1M), MB JCB (chuyển bậc 5%/10%), Shinhan 365 (nền 0,4% dưới ngưỡng, 10% trên ngưỡng).
- Đã cập nhật assertion số template hệ thống (9 → 17) trong `ShinhanSupremePolicyTemplateTest`.

---

## 6. Đợt bổ sung 2026-10-12: 6 sản phẩm nghiên cứu mới (Phase C–E đã triển khai)

| STT | Template (slug) | Bank | Quyền lợi mô hình hoá | Hồ sơ MCC | Bậc thưởng theo dòng |
|-----|-----------------|------|------------------------|-----------|----------------------|
| 1 | `vpbank-s-rewards-mastercard` | VPBank | B1 (< 5tr): 0%. B2 (≥ 5tr): Shopee/ShopeeFood **12%** (nhận diện MID, không MCC), combo _Ăn uống + Siêu thị + Di chuyển_ **4%**, còn lại **0,1%**; trần 2.400.000 điểm/kỳ TBGD (nhóm 12%+4%) | Profile 23 (inc 4 dòng/7 MCC; exc 11 MCC) | 3 dòng (4%/4%/4%) |
| 2 | `hsbc-cash-back` | HSBC (ngân hàng mới) | Siêu thị 6% (trần 200k/danh mục), Bảo hiểm 1%, Giáo dục 1%, còn lại 0,5%; không trần bậc | **CHƯA ĐỦ DỮ LIỆU** (không có danh mục MCC công bố) — chỉ template | — |
| 3 | `bidv-visa-cashback-360` | BIDV | Siêu thị **2%** (tổng DSGD tháng tại siêu thị 0–10tr) / **10%** (> 10tr) — ngưỡng trên **danh mục siêu thị**; Y tế 2%; Giáo dục 2%; trần 300k/nhóm → cap bậc 500k; fallback 0% | Profile 24 (3 dòng/15 MCC) | 4 bậc (2 / 10 / 2 / 2) |
| 4 | `tpb-evo` | TPBank | **1%/5%/10%** theo tổng chi tiêu kỳ (< 10tr / 10–30tr / ≥ 30tr) cho giao dịch **ONLINE**; trần 500k/kỳ; fallback 0% | Profile 25 (0 dòng inc; exc 9 MCC) — kênh online không theo MCC | — |
| 5 | `vib-online-plus-2in1` | VIB | **5%** chi tiêu online nước ngoài, **3%** online còn lại, **0,1%** còn lại; trần 600.000 điểm/kỳ | Profile 26 (0 dòng — CHƯA HỖ TRỢ MCC tự động, ghi chú minh bạch) | — |
| 6 | `ocb-jcb-platinum` | OCB | B1 (< 30tr): Sức khỏe 7% (trần 150k), Bảo hiểm 7% (trần 150k), tổng 700k, còn lại 0,1%. B2 (≥ 30tr): 15%/15% (trần 300k/nhóm), tổng 1,5tr | Profile 27 (2 dòng/18 MCC; exc 5499) | 4 bậc (7 / 15 × 2 dòng) |

### Các điểm mô hình hoá và hạn chế

- **Danh mục MCC theo nguồn chính thức**:
  - VPBank S Rewards — _Thể lệ ưu đãi/tính năng VPBank S Rewards Mastercard_ (phụ đính): Ăn uống 5811, 5812, 5813, 5921; Siêu thị 5411; Di chuyển 4121, 5814; Shopee/ShopeeFood theo MID; loại trừ 6011, 5172, 5541, 5542, 5983, 4812, 5271, 4814, 4816, 4899, 4900 + giao dịch qua VPBank NEO.
  - BIDV 360 — _Thể lệ Tích điểm đổi quà BIDV Membership Rewards 2026_ (mục 6.3, thẻ PVC0016/PVC0051/PVC0018): Siêu thị 5411; Y tế sức khỏe 7298, 8062, 8050, 8099, 8021, 8042, 8071; Giáo dục 8244, 8351, 8220, 8211, 8299, 8249, 8241; điều kiện tổng DSGD ≥ 3.000.000đ/tháng; tổng trần 800.000 (KH trả lương qua BIDV) / 600.000 điểm/tháng.
  - TPBank EVO — _Thể lệ hoàn tiền TPBank EVO_ (áp dụng 21.10.2025): online = Transaction type 680 + e-commerce, loại MCC 6211, 4829, 6051, 4900, 6011, 7311, 5968, 7801, 7995; ví điện tử AIRPAY/ALEPAY/MOMO/MPOS/PAYOO/PAYPAL/VIMO/VNPAY/YOLO/ZALOPAY loại trừ trừ whitelist FPTSHOP, TGDD, TIKI, BAEMIN, CONCUNG, VIETTEL STORE.
  - OCB JCB Platinum — _Thể lệ chương trình ưu đãi_ (cập nhật 1.9, áp dụng 01/06/2026–31/05/2027, Phụ lục A): Sức khỏe 4119, 5047, 5122, 5912, 5975, 5976, 8011, 8021, 8031, 8041, 8042, 8043, 8049, 8050, 8062, 8071, 8099; Bảo hiểm 6300; loại trừ MCC 5499 (ngoại trừ GS25, Circle K, Family Mart, MiniStop, 7-Eleven, Cheers); ≥ 7 giao dịch hợp lệ/kỳ.
- **Hạn chế engine (ghi chú, không tự thêm cơ chế mới)**:
  - HSBC bậc 8% theo khách trả lương — chưa thể hiện; OCB điều kiện ≥ 7 giao dịch/kỳ — chưa thể hiện; TPBank/VIB kênh online — mô hình gần đúng qua danh mục TMĐT/combo; BIDV trần tổng Private/Elite 600k — vẫn dùng 500k (mức phổ thông).
- **Combo hệ thống mới** (Phase C): `vpb-s-rewards-uu-dai` (Ăn uống + Siêu thị + Di chuyển), `tpb-evo-online` (Mua sắm trực tuyến + Sàn TMĐT + Shopee + Tiki + Lazada + TikTok + Ứng dụng số & giải trí). Rule loop seeder giờ hỗ trợ `combo`/`from`/`to` bên cạnh `category`.
- **Bidv CS siêu thị đã cập nhật mô hình**: 2 bậc cùng danh mục siêu thị theo `spend_from/spend_to` (0–10tr: 2%; ≥10tr: 10%) — phản ánh đúng tài liệu "lũy tiến theo doanh số siêu thị trong tháng".
- **Kiểm thử**: hệ thống template 23; `ResearchedTemplatesPolicyTest` (12 test, 14 plan, có `CategoryCombo`); toàn bộ `tests/Feature/CreditCard` = 1366 test OK; Pint `--test` đạt; không BOM; `git diff --check` sạch. Dữ liệu live đã verified (6 template + 5 hồ sơ MCC + 11 bậc thưởng mới). Sau Phase G (xem 6.1): +17 test hành vi engine; toàn bộ `tests/Feature/CreditCard` = **1383 test / 10441 assertion OK**.

### 6.1 Kiểm tra hành vi engine thật cho 6 sản phẩm — Phase G (12/10/2026)

Đã thêm **17 test hành vi** (mục D) vào `tests/Feature/CreditCard/ResearchedTemplatesPolicyTest.php`, chạy bằng `CashbackRecordService::calculatePeriod` thật qua blueprint đã seed (không dùng fake). Kết quả: toàn bộ `tests/Feature/CreditCard` = 1383 test / 10441 assertion OK; Pint `--test` đạt; `php -l` + `git diff --check` sạch; không BOM. KHÔNG commit/push/deploy; KHÔNG đổi engine/seeder/DB/profiles/MCC — chỉ thêm test và tài liệu.

**Chính sách engine hỗ trợ ĐÚNG (đã khẳng định bằng test):**
- **VPBank S Rewards**: < 5tr/kỳ → 0%; đúng 5tr/kỳ → Shopee 12% (cap 400k), combo _Ăn uống + Siêu thị + Di chuyển_ 4% (cap 200k/danh mục), còn lại 0,1%.
- **HSBC Cash Back**: Siêu thị 6% (cap 200k), Bảo hiểm 1%, Giáo dục 1%, còn lại 0,5%.
- **BIDV 360**: siêu thị 2% (DSGD danh mục siêu thị 0–10tr) / 10% (đúng 10tr → 10%, cap 300k), Y tế 2%, Giáo dục 2%; tổng kỳ khoá ở 500k (test 2 nhóm chạm trần).
- **TPBank EVO**: 1% / 5% / 10% theo tổng kỳ (5% đúng 10tr; 10% đúng 30tr), trần 500k/kỳ.
- **VIB OP2in1**: nước ngoài 5%, online 3%, còn lại 0,1%; trần chung 600k/kỳ (mọi rule tính vào trần, phân bổ theo thứ tự ngày giao dịch).
- **OCB JCB Platinum**: < 30tr → 7%/7% (cap nhóm 150k); đúng 30tr → 15%/15% (cap nhóm 300k).

**Hạn chế engine (đã cố định bằng test `_documented_limit`, xem comment trong test):**
- **VPBank** nhóm 4%: trần CHUNG 200k của combo được engine áp theo TỪNG danh mục (Cap 2 dùng `categoryId`) ⇒ hai danh mục cùng đạt trần sẽ ra 400k — khác trần nhóm 200k của chính sách.
- **HSBC**: luôn áp 6% (bậc 8% trả-lương không có cờ dữ liệu); trần đếm theo KỲ SAO KÊ (engine) thay vì THÁNG DƯƠNG LỊCH (chính sách).
- **TPBank/VIB** "online": engine không có cờ kênh ⇒ chỉ trả cho danh mục trong combo xấp xỉ (vd. thời trang mua online không được hoàn) — test chứng minh.
- **OCB** ≥ 7 giao dịch hợp lệ/kỳ: `SpendQualificationService` chỉ hỗ trợ điều kiện "danh mục/lĩnh vực khác đạt mức chi tiêu", KHÔNG có điều kiện theo SỐ GIAO DỊCH ⇒ 1 giao dịch duy nhất vẫn được hoàn (test cố định hành vi này).
- **BIDV**: điều kiện tổng DSGD ≥ 3.000.000đ/tháng và tổng trần 800k/600k (KH trả lương/Private-Elite) chưa mô hình — đang dùng cap bậc 500k.

**Kết luận**: không phát hiện bug engine, không cần sửa code engine. Điểm người vận hành cần biết: trần "chia sẻ chung" của combo VPBank được engine xử lý như "mỗi danh mục một trần riêng".