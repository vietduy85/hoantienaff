# Rà soát dữ liệu Thẻ tín dụng (chính sách, MCC, phí) & thuật toán tối ưu bộ thẻ

> Ngày: 2026-10-31. Đọc kèm: `CREDIT_CARD_README.md` (mục lục + checklist).
> Backup trước khi ghi: `storage/app/backups/credit-card/MANIFEST_2026-10-31_131157.txt`.

## 1. Tóm tắt

| Hạng mục | Kết quả |
|---|---|
| Dữ liệu live | 35 template hệ thống, 29 hồ sơ MCC, 159 dòng MCC, 586 mã gắn dòng (166 mã khác nhau), 293 mã master, 145 bậc hoàn tiền (thêm 1 trong đợt này) |
| Toàn vẹn MCC | Không có mã mồ côi (mọi mã gắn dòng đều có trong master); không có mã vừa included vừa excluded trong cùng hồ sơ |
| Đã sửa dữ liệu | (1) Bổ sung bậc 12% Shopee/ShopeeFood cho **VPBank S Rewards** (dòng included duy nhất thiếu bậc). (2) Phí thường niên **HSBC Cash Back** thẻ chính 880.000 |
| Đã sửa code | Ghi chú "Danh mục chưa có thẻ hoàn tiền…" của trang tối ưu: trước lặp một câu chung cho mỗi danh mục và không nêu tên; nay gộp thành 1 câu có tên danh mục |
| Thuật toán tối ưu | Chạy đúng, nhanh (0–10 giây trên dữ liệu thật, không hết thời gian). Điểm yếu lớn nhất: **không tính phí thường niên** (§3.2) |

## 2. Rà soát dữ liệu

### 2.1 Thay đổi đã thực hiện

1. **VPBank S Rewards Mastercard** (`vpbank-s-rewards-mastercard`, hồ sơ #23): dòng
   "Mua sắm online - Shopee, ShopeeFood" không có bậc hoàn tiền nào, trong khi ghi chú hồ sơ (cùng tài liệu
   VPBank) nêu rõ: Bậc 2 (tổng chi kỳ ≥ 5 triệu) được 12% Shopee/ShopeeFood, trần chung 2.400.000 điểm/kỳ
   cho nhóm 12% + 4%. Đã thêm vào `database/data/credit_card_mcc_row_benefits.php` và chạy importer
   (kết quả: tạo mới 1, cập nhật 0, gỡ 0).
2. **HSBC Cash Back** (hồ sơ #28): `annual_fee_primary = 880.000`. Nguồn chính thức:
   <https://www.hsbc.com.vn/en-vn/credit-cards/compare/cashback> ("Primary Card: VND880,000",
   "Supplementary Card: Free", miễn phí năm đầu cho chủ thẻ chính mới). Thẻ phụ "Free" ⇒ để NULL theo luật
   không điền 0; trang không nêu điều kiện chi tiêu miễn phí các năm sau ⇒ ngưỡng để NULL.
   Lưu ý: biểu phí PDF 01/2024 của HSBC ghi 800.000/400.000 (cũ); trang sản phẩm là nguồn mới hơn.

### 2.2 Phí thường niên còn NULL

| Hồ sơ | Thẻ | Lý do |
|---|---|---|
| #2, #14 | VIB One Card, VIB Max Card | Hồ sơ dùng chung nhiều hạng, phí khác nhau theo hạng. Cần bảng phí theo hạng (đề xuất §4) |
| #4, #17 | MB JCB Ultimate, MB JCB Platinum | Biểu phí MB render bằng JS; chỉ có nguồn thứ cấp |
| #12 | SeABank SeA-Easy | PDF lỗi font; mới ghi được ngưỡng miễn phí 70 triệu |
| #19, #25 | VPBank Flex, TPBank EVO | Ngân hàng ghi "miễn phí" ⇒ NULL theo luật |
| #22 | Cake 2in1 Premium | Là phí gói (300.000/3 kỳ), không phải phí thường niên |
| **#29** | **VPBank Super Shopee Platinum** | **Mới phát hiện.** Biểu phí VPBank cập nhật 19/10/2026 có thể ghi 599.000, ngưỡng 120 triệu, nhưng PDF không trích được chữ ⇒ chưa xác minh, để NULL. Cần mở tay file `Cap-nhat-bieu-phi-the-tin-dung-tai-VPBANK---Khoi-KHCN-20261019-tt.pdf` trên vpbank.com.vn |

### 2.3 Lệch giữa blueprint (engine) và hồ sơ MCC (tra cứu)

So tỷ lệ cao nhất của từng template ở hai lớp (bậc `bonus` không tính):

| Template | Blueprint | Hồ sơ MCC | Đánh giá |
|---|---|---|---|
| VIB Max Card (15–18), VIB One Card (19–21) | 0,5–10% / 3–9% theo hạng | 10% / 9% | **Đúng thiết kế.** Hồ sơ dùng chung nhiều hạng, gắn vào template hạng thấp nhất |
| MSB mDigi (8) | 20% | nhãn "tới 20%" (không có số) | Đúng, chỉ do cách ghi nhãn |
| Shinhan 365 Cashback (27) | 10% | 10% và 12% | Đúng. 12% chỉ áp dụng khi đồng thời có thẻ ghi nợ Be-SAFE; engine dùng mức cơ bản |
| **VPBank Flex (26)** | **8%**, trần 500k | **15% điểm**, trần 1.000.000 điểm | **Cần xác nhận.** Nếu 1 điểm LynkiD ≈ 0,5đ thì 15% điểm ≈ 7,5% tiền; nếu 1 điểm ≈ 1đ thì blueprint đang thấp hơn tài liệu |
| **VPBank S Rewards (30)** | Shopee 12% trần 400k/danh mục; nhóm 4% trần 200k; không có trần chung | Trần **chung** 2.400.000 điểm cho 12% + 4% | **Cần xác nhận.** Hai trần riêng trong blueprint (tổng 600k) chặt hơn tài liệu. Cần đối chiếu Bảng 2 của thể lệ |
| VPBank Lady (5), StepUp (4), OCB JCB Plat (35), BIDV 360 (32) | trần bậc cao hơn | trần dòng thấp hơn | Bình thường: blueprint ghi trần cả bậc, hồ sơ ghi trần từng nhóm |

Truy vấn tái lập (connection `creditcard`):

```sql
SELECT t.id, t.name, MAX(tc.cashback_percent) bp_max, MAX(pt.max_cashback_per_period) bp_cap
FROM credit_card_policy_templates t
JOIN credit_card_policies p ON p.template_id = t.id AND p.user_card_id IS NULL AND p.status = 'active'
JOIN credit_card_policy_tiers pt ON pt.policy_id = p.id
LEFT JOIN credit_card_policy_tier_categories tc ON tc.tier_id = pt.id AND tc.is_enabled = 1
WHERE t.scope = 'system' GROUP BY t.id, t.name;
-- so với MAX(b.cashback_percent) của credit_card_policy_mcc_row_benefits theo policy_template_id
```

### 2.4 Các điểm dữ liệu khác (chưa sửa)

- **MCC master**: cả 293 mã ở trạng thái `unverified` và chưa có `keywords`. Không ảnh hưởng tra cứu theo
  hồ sơ (trạng thái này chỉ dùng cho bảng mapping cũ, hiện có 0 dòng), nhưng ô tìm theo từ khoá sẽ chỉ khớp
  mô tả. Đề xuất: nạp lại master có `keywords` (`credit-card:import-mcc` hỗ trợ cột từ khoá).
- **Sacombank Platinum Amex** (#16): tài liệu áp dụng dải MCC 3000–3836 (hãng bay, thuê xe, khách sạn)
  nhưng master chưa có dải này nên dòng du lịch nước ngoài để trống mã.
- **58 dòng không có mã MCC**: đa số hợp lệ, vì tài liệu xác định theo merchant/MID (Shopee) hoặc mô tả
  chung (HSBC, Super Shopee). Không cần sửa.
- **Z Card JCB** (#7): dòng "Ngành hàng khác" có bậc 0% (đúng nếu thể lệ không hoàn phần còn lại; nên đối
  chiếu trang 9.2). **VIB Online Plus** (#26): dòng card-on-file có trần 100k nhưng không có tỷ lệ.
- **Thẻ người dùng**:
  - 3 thẻ chưa gắn chính sách: `BIDV JCB HYRID` (user 24), `Chi tiêu tài khoản` (user 5), `Shinhan Xanh` (user 12). Những thẻ này không bao giờ xuất hiện trong tối ưu.
  - Thẻ #13 `Shopee` (user 17) có 2 chính sách đang hiệu lực từ 2 template khác nhau (Sacombank Uniq và VPBank Super Shopee), nhiều khả năng là gắn nhầm.

  Đây là dữ liệu của người dùng nên chưa sửa.

## 3. Thuật toán tối ưu bộ thẻ (`PortfolioOptimizerService`)

### 3.1 Cách hoạt động

Heuristic nhiều pha, không phải quy hoạch nguyên chính xác:
shortlist ứng viên theo giá trị và tỷ lệ → "rót nước" từng danh mục theo các mốc trần/bậc → cải thiện cục bộ
→ bù ngưỡng chi tiêu tối thiểu bằng chi tiêu nền (filler) → thử kích hoạt điều kiện đặc biệt → sinh nhiều cấu
hình theo tiêu chí → loại trùng → trả tối đa 3 phương án. Mọi số tiền hoàn đều tính qua `CashbackCalculator`
(cùng engine với sao kê), nên kết quả nhất quán với phần còn lại của module.

### 3.2 Chạy thử trên dữ liệu thật

| Kịch bản | Thời gian | Kết quả |
|---|---|---|
| 8 danh mục, tất cả thẻ, cân bằng | 3,5 giây | 2 phương án, hoàn 2,45 triệu / 22 triệu |
| 28 danh mục, tối đa 5 bản/sản phẩm | 7,0 giây | 3 phương án |
| Ưa thẻ mới, 3 bản/sản phẩm | 10,1 giây | Lâu nhất, vẫn xa ngân sách 110 giây |
| Người dùng chưa có thẻ, 1 danh mục 50 triệu | 0,4 giây | Chia cho 3 thẻ để tận dụng trần |
| Chỉ thẻ của tôi, thẻ không có chính sách | 0 giây | 0 phương án, ghi chú nay nêu đúng tên danh mục |

Test `CardPortfolioOptimizerTest`: 80 passed.

### 3.3 Vướng mắc và đề xuất (xếp theo mức ưu tiên)

1. **Không trừ phí thường niên (quan trọng nhất).** Ví dụ thật: phương án dùng 5 thẻ, trong đó có 2 thẻ
   Sacombank Uniq (599k/năm mỗi thẻ) để lấy thêm 300k/tháng. Thẻ mới với phí cao có thể thắng thẻ miễn phí
   chỉ vì vài chục nghìn hoàn tiền.
   - Đề xuất: thêm chỉ số **"hoàn tiền ròng/năm" = hoàn/tháng × 12 − phí thường niên** (bỏ phí nếu mức chi
     ×12 ≥ ngưỡng miễn phí). Hiển thị cạnh tổng hoàn và dùng làm tiêu chí xếp hạng, tuỳ chọn hoặc mặc định.
   - Chỉ áp cho thẻ **mới**, vì thẻ đang sở hữu đã trả phí.
   - Phí hiện nằm ở hồ sơ MCC: cần nối template → hồ sơ (chủ hoặc liên kết). Thẻ nhiều hạng (VIB) cần
     bảng phí theo hạng.
2. **Chi tiêu "chưa phân bổ" bị bỏ ngoài.** Khi giới hạn số thẻ, các danh mục không có thẻ nào hoàn > 0 bị để
   "unallocated" (ví dụ 14–20 triệu). Thực tế người dùng vẫn phải quẹt bằng một thẻ nào đó.
   - Đề xuất: tự đặt phần này lên thẻ đã chọn có fallback cao nhất, hoặc thẻ giúp đạt ngưỡng bậc.
   - Ít nhất nên hiển thị gợi ý "quẹt thẻ X cho phần còn lại".
3. **Thẻ sở hữu thiếu chính sách bị im lặng loại bỏ.** Nên hiện cảnh báo "Thẻ A chưa gắn chính sách nên không
   được tính" trong `notes`.
4. **Điểm thưởng tính như tiền 1:1** (Flex, UOB One, S Rewards, BIDV 360). Nên có hệ số quy đổi điểm → VND ở
   cấp template (mặc định 1), để engine và tra cứu khớp nhau và giải quyết lệch §2.3.
5. **Hạng thẻ VIB là 7 template riêng.** Tối ưu có thể chọn đồng thời Max Plus và Max Elite như 2 sản phẩm
   khác nhau, trong khi thực tế một khách chỉ có một hạng. Nên gom theo nhóm sản phẩm khi đếm "thẻ mới".
6. **Kết quả là heuristic.** Không bảo đảm tối ưu toàn cục. Trên dữ liệu hiện tại các tiêu chí khác nhau cho
   cùng phương án tốt nhất, nên có thể yên tâm, nhưng cần ghi rõ trên giao diện là "ước tính".
7. Phạm vi `all` cho kết quả thấp hơn `compare` (4,20 so với 4,64 triệu, 28 danh mục). Lý do: `all` bỏ thẻ
   sở hữu không thuộc template hệ thống. Đúng thiết kế, nhưng nhãn phạm vi nên nói rõ.

## 4. Việc nên làm tiếp

1. Xác nhận với tài liệu gốc: tỷ lệ/trần VPBank Flex và S Rewards (§2.3); phí Super Shopee (§2.2).
2. Thêm "hoàn tiền ròng sau phí" vào tối ưu (§3.3.1). Đây là cải tiến có giá trị nhất cho người dùng.
3. Bảng phí theo hạng cho hồ sơ dùng chung (VIB) — đã đề xuất ở WORKFLOW Phần I.4.
4. Gợi ý thẻ cho phần chi tiêu chưa phân bổ; cảnh báo thẻ thiếu chính sách.
5. Nạp keywords + dải MCC 3000–3836 vào master.
