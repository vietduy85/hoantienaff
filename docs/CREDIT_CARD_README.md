# Module Thẻ tín dụng — Điểm bắt đầu (đọc file này trước)

> Cập nhật: 2026-10-31. File này là **mục lục + checklist**. Nó không lặp lại chi tiết; mỗi bước trỏ tới
> tài liệu gốc đã kiểm chứng. Khi có mâu thuẫn, **mã nguồn và DB live** là chuẩn, sau đó tới
> `CREDIT_CARD_MCC_WORKFLOW.md` và `CREDIT_CARD_POLICY_DEVELOPMENT_GUIDE.md`.

## 1. Bức tranh tổng thể: 3 lớp dữ liệu tách biệt

| Lớp | Bảng chính | Ai đọc | Ghi chú |
|---|---|---|---|
| **Blueprint chính sách (engine)** | `credit_card_policy_templates` → `credit_card_policies` (`user_card_id = NULL`) → `credit_card_policy_tiers` → `credit_card_policy_tier_categories` (+ `..._transaction_caps`, combo) | `CashbackCalculator`, `PortfolioOptimizerService`, `CardRecommendationService`, sao kê, báo cáo | **Chỉ lớp này quyết định số tiền hoàn** trong tổng quan, sao kê và tối ưu thẻ. |
| **Hồ sơ MCC (tra cứu)** | `credit_card_policy_mcc_profiles` → `..._rows` (included/excluded) → `..._row_codes`, `..._documents`, `..._row_benefits` | `CardPolicyLookupService` (tab Tra cứu) | Chỉ phục vụ tra cứu; **không** đi vào engine. |
| **Thông tin thẻ / phí** | `annual_fee_primary`, `annual_fee_supplementary`, `annual_spend_for_annual_fee_waiver` trên hồ sơ MCC | Tra cứu | Thuật toán tối ưu **chưa dùng** phí (xem báo cáo audit §3). |

Kết nối DB: `creditcard` (database `hoantien_creditcard`). Mọi thao tác ghi phải backup trước:
`php artisan credit-card:backup` (chỉ đọc, xuất CSV + MANIFEST vào `storage/app/backups/credit-card/`).

## 2. Checklist thêm MỘT thẻ mới (đầu-cuối)

1. **Nghiên cứu nguồn chính thức** (thể lệ, biểu phí). Xác minh đúng tên thẻ, mạng lưới, hạng.
   Quy tắc: `CREDIT_CARD_POLICY_DEVELOPMENT_GUIDE.md` §2.
2. **Tạo System Template + blueprint** (bậc, quy tắc theo danh mục, fallback, trần), qua seeder
   `CreditCardSeeder::seedResearchedTemplates` hoặc màn hình admin chính sách hệ thống.
   Quy ước cấu hình: §3.1 (mỗi bậc đúng 1 fallback, trần nhóm ở `PolicyTier.max_cashback_per_period`,
   trần/danh mục ở `max_cashback_per_category_per_period`, min tổng chi ở từng bậc).
   Seed lên DB thật: §4 (code ≠ dữ liệu live).
3. **Tạo hồ sơ MCC** tại `/credit-card/mcc/create`: dòng included/excluded, mã MCC (phải có trong master
   `credit_card_mcc_codes`), đính kèm PDF gốc. Quy trình 12 bước: `CREDIT_CARD_MCC_WORKFLOW.md` Phần B.
   Nếu tài liệu xác định theo merchant (Shopee, MID) thay vì MCC, dòng được phép **không có mã**.
4. **Nạp bậc hoàn tiền theo tài liệu**: thêm khoá `slug` template vào
   `database/data/credit_card_mcc_row_benefits.php` (khoá cấp 2 = `category_name` đúng y hệt dòng included),
   rồi chạy `php artisan credit-card:import-mcc-row-benefits` (idempotent; báo số bậc tạo/cập nhật/gỡ).
   Quy tắc: WORKFLOW Phần E. Điểm thưởng ghi `cap_unit = 'điểm'` và ghi tỉ lệ quy đổi trong ghi chú.
5. **Phí thường niên**: 3 trường trên hồ sơ. Chỉ nguồn chính thức; **không điền 0** cho "miễn phí";
   thẻ nhiều hạng có phí khác nhau ⇒ để NULL. Quy tắc: WORKFLOW Phần I, GUIDE §2.2.
6. **Kiểm tra nhất quán blueprint ↔ hồ sơ MCC** (tỷ lệ cao nhất, trần, ngưỡng). Chênh lệch phải có
   lý do được ghi lại (ưu đãi có điều kiện, điểm thưởng quy đổi, hồ sơ dùng chung nhiều hạng).
   Truy vấn mẫu ở `CREDIT_CARD_DATA_AND_OPTIMIZER_AUDIT_2026-10-31.md` §2.3.
7. **Kiểm chứng**: tra một mã MCC ở `/thetindung/tra-cuu`; chạy tối ưu ở
   `/thetindung/so-sanh` với chi tiêu giả định có danh mục của thẻ mới; chạy test
   `php artisan test tests/Feature/CreditCard`.

## 3. Bản đồ tài liệu

| Muốn làm gì | Đọc |
|---|---|
| Hiểu kiến trúc, model, service | `CREDIT_CARD_POLICY_DEVELOPMENT_GUIDE.md` §1, `CREDIT_CARD_PHASE_1A_ARCHITECTURE_REVIEW.md` |
| Tạo/sửa chính sách hoàn tiền (blueprint) | `CREDIT_CARD_POLICY_DEVELOPMENT_GUIDE.md` §2–6, `CREDIT_CARD_SYSTEM_POLICY_ADMIN_REPORT.md` |
| Tạo/sửa hồ sơ MCC, nạp bậc hoàn tiền | `CREDIT_CARD_MCC_WORKFLOW.md` Phần B, D, E |
| Quy tắc hiển thị tra cứu MCC / danh mục | `CREDIT_CARD_MCC_WORKFLOW.md` Phần F, J |
| VIB dùng chung một hồ sơ cho nhiều hạng | `CREDIT_CARD_MCC_WORKFLOW.md` Phần G, `CREDIT_CARD_MCC_VIB_PRODUCT_GROUPING_REPORT.md` |
| Phí thường niên | `CREDIT_CARD_MCC_ANNUAL_FEES_REPORT.md`, WORKFLOW Phần I |
| Combo danh mục | `CREDIT_CARD_CATEGORY_COMBO_NORMALIZATION_PLAN.md` |
| Thuật toán tối ưu bộ thẻ | Docblock `app/Services/CreditCard/Optimizer/PortfolioOptimizerService.php`, `CREDIT_CARD_DATA_AND_OPTIMIZER_AUDIT_2026-10-31.md` §3 |
| Lỗi đã gặp | WORKFLOW Phần H, `CREDIT_CARD_MSB_ONLINE_800K_CAP_INVESTIGATION.md` |
| Kết quả rà soát gần nhất | `CREDIT_CARD_DATA_AND_OPTIMIZER_AUDIT_2026-10-31.md` |

## 4. Lệnh hay dùng

```bash
php artisan credit-card:backup                      # backup CSV (chỉ đọc)
php artisan credit-card:import-mcc-row-benefits     # nạp bậc hoàn tiền theo dòng MCC (idempotent)
php artisan credit-card:import-mcc                  # nạp MCC master + mapping (idempotent)
php artisan credit-card:migrate:status              # trạng thái migration DB thẻ
php artisan test tests/Feature/CreditCard           # bộ test module
```
