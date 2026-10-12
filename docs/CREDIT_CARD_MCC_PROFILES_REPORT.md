# BÁO CÁO: HỒ SƠ MCC CHÍNH SÁCH THẺ TÍN DỤNG

> Ngày thực hiện: 2026-10-12 · DB: `hoantien_creditcard` (connection `creditcard`)
> Phạm vi: tạo hồ sơ MCC cho các chính sách thẻ hiện có, dựa trên tài liệu chính thức của ngân hàng.
> Không commit/push. Không sửa engine cashback, không sửa bảng/hồ sơ MCC #1 (LPBank) hiện có.

---

## 1. Tổng quan

Trước đợt này chỉ có **1 hồ sơ MCC** (id 1 – LPBank JCB Ultimate, template 11).
Sau khi tìm tài liệu chính thức và đối chiếu danh mục MCC master, đã tạo thêm **12 hồ sơ** (id 2–13, 14),
nâng tổng số lên **14 hồ sơ**.

| Template | Sản phẩm | Ngân hàng | Có danh sách MCC chính thức? | Hồ sơ | MCC included | MCC excluded |
|---|---|---|---|---|---|---|
| 2 | Sacombank Visa Uniq Platinum | Sacombank | Có | **3** | 6 | 8 |
| 3 | MB JCB Ultimate | MB | Có | **4** | 26 | 0 |
| 4 | VPBank StepUp Mastercard | VPBank | Có (online theo quy tắc) | **5** | 5 (online: quy tắc) | 11 |
| 5 | VPBank Lady Mastercard | VPBank | Có | **6** | 30 | 11 |
| 6 | VPBank Z Card JCB | VPBank | Có | **7** | 10 | 0 |
| 7 | VPBank Super Shopee Platinum Visa | VPBank | **Không** (chỉ tên/ĐVCNT) | — | — | — |
| 8 | MSB mDigi Mastercard | MSB | Có | **8** | 12 | 0 |
| 9 | MSB Visa Online | MSB | Có | **9** | 17 | 0 |
| 10 | VPBank Cake Freedom Visa | VPBank/CAKE | Có | **10** | 13 | 3 |
| 11 | LPBank Ultimate JCB | LPBank | Có | 1 (đã có) | 21 | 8 |
| 12 | BIDV Visa Cashback Online | BIDV | **Không** (chỉ theo tên ĐVCNT) | **11** | 0 | 13 (chỉ loại trừ) |
| 13 | SeABank SeA-Easy Visa | SeABank | Không (theo tên ĐVCNT) | **12** | 0 | 15 (chỉ loại trừ) |
| 14 | Shinhan Supreme Visa | Shinhan | Có (Phụ lục 1) | **13** | 17 | 0 |
| 15 | VIB Max Card Visa – Starter | VIB | Có (dạng trang web) | **14** | 79 | 2 |
| 16–18 | VIB Max Card Visa – Plus/Pro/Elite | VIB | (dùng chung hồ sơ 14) | — | — | — |
| 19 | VIB One Card Gold | VIB | Có (mục 2.12) | **2** | 63 | 0 |
| 20–21 | VIB One Card Platinum/Signature | VIB | (dùng chung hồ sơ 2) | — | — | — |
| 1 | Cashback phổ thông | — | Template chung, không gắn sản phẩm | — | — | — |

Ghi chú: bảng mã MCC master chỉ chứa các mã đã có sẵn; mọi MCC nêu trong tài liệu nhưng **chưa có trong master
sẽ không được thêm vào hồ sơ** và được ghi nhận ở mục 3.

---

## 2. Chi tiết hồ sơ đã tạo

### Template 2 — Sacombank Visa Uniq Platinum (hồ sơ 3)
Nguồn: *Quy định hoàn tiền thẻ tín dụng Visa UNIQ Platinum* (sacombank.com.vn).
- **Included:** Siêu thị & cửa hàng tiện lợi `5411, 5499, 5722`; Ứng dụng di chuyển `4121, 4789, 5969`
- **Excluded:** Chứng khoán `6211`; Sòng bạc `7800, 7801, 7802, 7995, 9406`; Mua vàng/trang sức `5944`; Thanh toán hóa đơn `4900`
- **Thiếu trong master:** `9751`

### Template 3 — MB JCB Ultimate (hồ sơ 4)
- **Y tế:** `4119, 5047, 5122, 5912, 5975, 5976, 8011, 8021, 8031, 8041, 8042, 8043, 8049, 8050, 8062, 8071, 8099`
- **Giáo dục:** `8211, 8220, 8241, 8244, 8249, 8299, 8351`
- **Bảo hiểm:** `5960, 6300`
- **Thiếu trong master:** `8044, 8082, 7295`

### Template 4 — VPBank StepUp Mastercard (hồ sơ 5)
- **Chi tiêu online:** định nghĩa theo quy tắc (tất cả giao dịch online, trừ `5960`, `6300`, các mã loại trừ và Shopee/ShopeeFood) — không có dòng mã cố định.
- **Ăn uống:** `5811, 5812, 5813, 5814, 5921`
- **Loại trừ:** `6011, 5172, 5541, 5542, 5983, 4812, 5271, 4814, 4816, 4899, 4900` (+ loại trừ giao dịch qua VPBank NEO)

### Template 5 — VPBank Lady Mastercard (hồ sơ 6)
- **Bảo hiểm trực tuyến:** `6300, 5960`
- **Giáo dục:** `8211, 8220, 8244, 8249, 8299, 8241`
- **Chăm sóc sắc đẹp:** `9405, 7230, 7297, 7298, 5977`
- **Y tế:** `8021, 8042, 8043, 8062, 8071, 8099, 5047, 5122, 8011, 4119, 5912, 5976, 8041, 0742, 8049, 8050`
- **Siêu thị:** `5411`
- **Loại trừ:** `6011, 5172, 5541, 5542, 5983, 4812, 5271, 4814, 4816, 4899, 4900` (+ VPBank NEO)

### Template 6 — VPBank Z Card JCB (hồ sơ 7)
- **Ăn uống:** `5811, 5812, 5813, 5921`
- **Giải trí & Fitness:** `5734, 5735, 7832, 7997`
- **Di chuyển & đặt đồ ăn:** `4121, 5814`

### Template 8 — MSB mDigi Mastercard (hồ sơ 8)
- **Ăn uống:** `5812, 5813, 5814`
- **Du lịch:** `4722, 7011`
- **Sản phẩm số:** `5815, 5816, 5817, 5818, 4899, 7829, 7841`

### Template 9 — MSB Visa Online (hồ sơ 9)
- **Thương mại điện tử:** `4814, 5262, 5399, 5411, 5611, 5691, 5722, 5732, 5734, 5817, 5999, 7298, 7311, 7372, 5499`
- **Mua vé xem phim online:** `7832, 5411`

### Template 10 — VPBank Cake Freedom Visa (hồ sơ 10)
- **Hoàn tiền cố định:** `5262, 4722, 5611`
- **Hoàn tiền linh hoạt (chọn 2 trong 9):** `5411, 5812, 5814, 4121, 5499, 6300, 7011, 8999, 4511, 7832`
- **Không tính vào chi tiêu tối thiểu:** `4900, 4111, 5541`

### Template 12 — BIDV Visa Cashback Online (hồ sơ 11)
- Tài liệu **không công bố** danh sách MCC được hoàn tiền (xác định theo tên ĐVCNT: Shopee, TikTokShop, các giao dịch online khác).
- Chỉ ghi nhận **loại trừ:** `4900, 4899, 4814, 4816, 6011, 6211, 6050, 9311, 6051, 7801, 7995, 6012, 6540, 6532, 6533, 6538, 4829`
- **Thiếu trong master:** `6050, 6532, 6533, 6538`

### Template 13 — SeABank SeA-Easy Visa (hồ sơ 12)
- Tài liệu **không công bố** danh sách MCC được hoàn tiền (nhóm ưu đãi cao xác định theo tên ĐVCNT: SHOPEE, ITUNES, AMAZON, ALIEXPRESS).
- Chỉ ghi nhận **loại trừ:** `5541, 5542, 9211, 9222, 9311, 9399, 9402, 9405, 4111, 4112, 4131, 4784, 4789, 4900, 7311`

### Template 14 — Shinhan Supreme Visa (hồ sơ 13)
- **Thương mại điện tử:** `5262`
- **Điện máy:** `5732, 5722`
- **Giáo dục:** `8211, 8220, 8241, 8244, 8249, 8299`
- **Nhà sách:** `5942, 5192`
- **Bệnh viện:** `8011, 8062, 8099, 9399`
- **Bảo hiểm:** `5960, 6300, 6381, 6399`
- **Thiếu trong master:** `6381, 6399`

### Template 15 — VIB Max Card Visa (hồ sơ 14, dùng chung 4 hạng)
- **Mua sắm:** danh sách dài (đã lọc theo master, thiếu `5301, 5298, 5296, 0046`)
- **Du lịch:** `7011, 4511, 4722` (dải hàng không/sân bay `3000–3838`, `3998`, `0070`, `0010` chưa có trong master)
- **Ẩm thực:** `5814, 5813, 5812, 5811`
- **Giải trí:** `5815, 5816, 7829, 7832, 7841, 7911, 7922, 7929, 7932, 7933, 7941, 7991–7994, 7996–7999`
- **Giao dịch trực tuyến:** định nghĩa theo quy tắc (trừ MCC `6300`, `7399`) — không có dòng mã cố định.
- **Loại trừ:** `4900, 4814` (+ phí bảo hiểm, quảng cáo trực tuyến, giao dịch QR, thanh toán hóa đơn, bất động sản)

### Template 19 — VIB One Card (hồ sơ 2, dùng chung 3 hạng)
- **Mua sắm:** danh sách dài (đã lọc theo master, thiếu `5301, 5298, 5296, 0046`)
- **Du lịch:** `7011, 4511, 4722` (dải `3000–3838`, `3998`, `0070`, `0010` chưa có trong master)
- **Giáo dục:** `8211, 8220, 8241, 8244, 8249, 8299`
- **Bảo hiểm:** `6300`
- **Chi tiêu nước ngoài:** định nghĩa theo kênh POS/Contactless + ngoại tệ (không có MCC) — không có dòng mã.

---

## 12. Trường hợp CHƯA ĐỦ CĂN CỨ

| Template | Sản phẩm | Lý do |
|---|---|---|
| 7 | VPBank Super Shopee Platinum Visa | Thể lệ chính thức chỉ nêu **tên danh mục/ĐVCNT** (Shopee, ShopeeFood, ăn uống, siêu thị Co.op/BigC/Lottemart/Winmart, Grab/Be), **không công bố mã MCC**; chỉ dẫn chung "theo bảng mã MCC của Visa". |
| 1 | Cashback phổ thông | Template danh mục chung (không gắn một sản phẩm thẻ cụ thể) nên không có tài liệu MCC riêng. |

Không tạo hồ sơ cho các trường hợp này (tránh suy đoán MCC).

---

## 13. Danh mục MCC thiếu trong master (ghi nhận, KHÔNG tự thêm)

| Ngân hàng / Sản phẩm | MCC thiếu | Ghi chú |
|---|---|---|
| VIB (One Card & Max Card) | `5301, 5298, 5296, 0046`; dải `3000–3838`, `3998`, `0070`, `0010` | Danh mục master hiện không có nhóm mã hàng không/sân bay (không có mã nào trong dải 3xxx). |
| Shinhan Supreme | `6381, 6399` | Bảo hiểm premiums/default. |
| MB JCB Ultimate | `8044, 8082, 7295` | Y tế & giáo dục. |
| Sacombank Uniq | `9751` | — |
| BIDV Cashback Online | `6050, 6532, 6533, 6538` | Loại trừ. |
| LPBank JCB Ultimate (hồ sơ #1 cũ) | `8044, 8082, 6381, 6399, 7511, 6050` | Đã ghi từ đợt trước. |

Quy tắc áp dụng: mã không có trong master **không** được đưa vào hồ sơ; chỉ ghi nhận để bổ sung master sau (nếu có căn cứ).

---

## 14. Quy tắc & ràng buộc đã tuân thủ

- Chỉ ghi qua `PolicyMccProfileService` (một transaction trên connection `creditcard`).
- **Không** lưu tỷ lệ hoàn tiền vào hồ sơ (đọc từ blueprint chính sách) — ngoại lệ duy nhất là hồ sơ #1 (đã có trước, không đụng).
- **Không** tự tạo MCC master; **không** liên kết tên danh mục hồ sơ với `credit_card_categories`; **không** đụng engine cashback / `credit_card_policy_mcc_mappings`.
- Một mã không thể vừa included vừa excluded trong cùng hồ sơ (đã kiểm tra: 0 vi phạm).
- Tài liệu gốc lưu private (`local`, thư mục `credit-card-mcc/{profileId}`), không công khai.
- Hồ sơ VIB One Card (id 2) và VIB Max Card (id 14) **dùng chung** cho các hạng theo yêu cầu.

### Lưu ý (không phải lỗi)
- Blueprint các chương trình VIB dùng **combo danh mục** (tên danh mục ở tầng rule là NULL), nên tên danh mục trong hồ sơ MCC **không khớp** để suy ra tỷ lệ hoàn tiền khi tra cứu — hồ sơ VIB mang tính **đối chiếu tài liệu**.
- VIB công bố thể lệ dạng **trang HTML** (không có file PDF gốc cho Max Card); tệp đính kèm là bản in nội dung trang chính thức, đã ghi rõ trong hồ sơ.
- File PDF Shinhan trong `Downloads` giải nén "lỗi font" chỉ do codepage console; bản PDF gốc hợp lệ và đã đọc đúng Phụ lục 1.

---

## 15. Nguồn tài liệu chính thức (truy cập 2026-10-12)

| Sản phẩm | Nguồn |
|---|---|
| Sacombank Visa Uniq | sacombank.com.vn → `.../Quy-dinh-hoan-tien-the-tin-dung-VisaUNIQ.pdf` (PDF metadata 2026-02-02) |
| MB JCB Ultimate | mbbank.com.vn → `.../JCB/01/the-le-cvp-the-jcb-ultimate.pdf` |
| VPBank StepUp | vpbank.com.vn → `.../TnC-Step-Up-082025-.pdf` |
| VPBank Lady | vpbank.com.vn → `.../the-le-uu-dai-hoan-tien-danh-cho-chu-the-tin-dung-vpbank-lady-mastercard.pdf` |
| VPBank Z Card JCB | vpbank.com.vn → `.../website-the-le-uu-dai-tinh-nang-danh-cho-chu-the-tin-dung-vpbank-z-jcb.pdf` |
| VPBank Super Shopee | vpbank.com.vn → `.../tnc-super-shopee-032023.pdf` (không có MCC) |
| VPBank Cake Freedom | static.cake.vn → `.../dkdk_freedom_597307c4f8.pdf` |
| MSB mDigi | msb.com.vn → `.../TnC-hoan-tien-the-MC-mDigi-202604-1.pdf` |
| MSB Visa Online | msb.com.vn → `.../TnC-hoan-tien-the-Visa-Online-202605-1.pdf` |
| BIDV Cashback Online | bidv.com.vn → Phụ lục 02 Thể lệ tích lũy/đổi điểm Loyalty 2026 (GĐ2) |
| SeABank SeA-Easy | seabank.com.vn → "Thể lệ cashback thẻ tín dụng SeAEasy" |
| Shinhan Supreme | shinhan.com.vn → `.../Supreme Card/T&C_Point Accumulation Program_Supreme Card_VN.pdf` |
| VIB One Card | vib.com.vn → "Chương trình điểm thưởng/hoàn tiền thẻ tín dụng VIB", mục 2.12 |
| VIB Max Card | vib.com.vn/vn/max-card/diem-thuong-hoan-tien (HTML) |

---

## 16. Kiểm chứng

- `credit_card_policy_mcc_profiles`: 14 dòng (id 1–14).
- Không mã nào vừa included vừa excluded (0 vi phạm).
- Mọi mã trong hồ sơ đều tồn tại trong `credit_card_mcc_codes` (service chặn nếu không).
- Tài liệu đính kèm tồn tại trên disk `local`: profile 1 (2), 10 (2), còn lại mỗi hồ sơ 1 tệp.

---

## 17. Đợt bổ sung 2026-10-12: hồ sơ MCC 6 sản phẩm mới (Phase D)

| Profile id | Template (slug) | Hồ sơ | Inc dòng / MCC | Exc dòng / MCC | Bậc thưởng | Tài liệu đính kèm |
|---|---|---|---|---|---|---|
| 23 | `vpbank-s-rewards-mastercard` | VPBank S Rewards Mastercard – Thể lệ tích điểm (Điểm thưởng) | 4 / 7 | 1 / 11 | 3 (4%) | `vpbank_s_rewards.pdf` |
| 24 | `bidv-visa-cashback-360` | BIDV Visa Cashback 360 – Thể lệ tích điểm hoàn tiền | 3 / 15 | 0 / 0 | 4 (2/10/2/2) | `bidv_membership_rewards_2026.pdf` |
| 25 | `tpb-evo` | TPBank EVO – Thể lệ hoàn tiền thẻ tín dụng quốc tế | 0 / 0 | 1 / 9 | — | `tpb_evo_tnc.pdf` |
| 26 | `vib-online-plus-2in1` | VIB Online Plus 2in1 – Chương trình Điểm thưởng & Hoàn tiền (mục 2.6) | 0 / 0 | 0 / 0 | — | (không có — thể lệ dạng HTML chính thức; không tải PDF) |
| 27 | `ocb-jcb-platinum` | OCB JCB Platinum – Thể lệ chương trình ưu đãi (Phụ lục A) | 2 / 18 | 1 / 1 | 4 (7/15 × 2 dòng) | `ocb_jcb_platinum_update19.pdf` |

Ghi chú đợt bổ sung:

- **HSBC `hsbc-cash-back`: KHÔNG tạo hồ sơ** — ngân hàng không công bố danh mục MCC; trạng thái **CHƯA ĐỦ DỮ LIỆU**, chỉ có template (quyết định của chủ dự án).
- **NCC**: mọi mã đưa vào hồ sơ 23–27 đều đã tồn tại trong `credit_card_mcc_codes` (kiểm tra trước khi tạo, **0 mã thiếu qua master** — không có mục nào phải ghi nhận thêm).
- **TPBank EVO (25) và VIB Online Plus 2in1 (26) có 0 dòng included**: chương trình xác định theo **kênh giao dịch** (online / nước ngoài) chứ không theo MCC. Hồ sơ mang tính đối chiếu, loại trừ và ghi chú minh bạch — **không** cho tra cứu MCC tự động.
- **Bậc thưởng theo dòng** nạp qua `php artisan credit-card:import-mcc-row-benefits` từ `database/data/credit_card_mcc_row_benefits.php`: **11 bậc tạo mới** (VPBank 3, BIDV 4, OCB 4), idempotent.
- Danh sách MCC dòng Sức khỏe OCB khác BIDV (OCB: 4119, 5047, 5122, 5912, 5975, 5976, 8011, 8031, 8041, 8043, 8049 + 8 mã chung; BIDV: 7298, 8062, 8050, 8099, 8021, 8042, 8071) — giữ nguyên theo từng tài liệu, không gộp.
- Tổng `credit_card_policy_mcc_profiles` hiện tại: **27** (1–14 đợt đầu, 15–22 đợt nghiên cứu trước, 23–27 đợt này).
