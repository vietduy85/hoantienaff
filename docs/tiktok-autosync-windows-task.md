# TikTok Auto Sync — Windows Task Scheduler

> **ĐÃ GỘP (2026-10-14).** TikTok **không còn task riêng**. Task
> `HoanTien-TikTok-Sync` đã **DISABLED** (giữ lại, không xóa, để rollback).
> TikTok nay chạy là **bước [1/5]** trong `affiliate:sync-all` — entry point
> lập lịch DUY NHẤT: `WindowScheduleSync` → `php artisan affiliate:sync-all`
> **mỗi 3 giờ** (00/03/06/09/12/15/18/21). Xem `docs/CODEMAP.md` §6g.
> Các mục bên dưới mô tả thiết lập standalone gốc và vẫn đúng cho các lần chạy
> **thủ công** `affiliate:tiktok-sync --sync`.

## Architecture (hiện tại — đã gộp)

```
Windows Task Scheduler  (WindowScheduleSync — mỗi 3 giờ)
        |
        v
C:\xampp\php\php.exe artisan affiliate:sync-all
        |
        +--> [1/5] TikTok Sync          <-- tài liệu này
        +--> [2/5] Lazada Sync
        +--> [3/5] Lazada Finalize      (chỉ khi Lazada Sync thành công)
        +--> [4/5] ShopeeFood Sync
        +--> [5/5] Shopee/AddLiveTag API Sync
        |
[1/5] -> AffiliateSyncAll::syncTikTok()
        -> TikTokOrderSyncService::run(creditWallet: true)
        |
        +--> RioHub API
        +--> import/update affiliate_order_items
        +--> User mapping
        +--> cashback NET
        +--> wallet credit/reversal
        +--> last_tiktok_sync_at
```

```
(Legacy — task standalone, nay KHÔNG còn lập lịch)
Windows Task Scheduler  (HoanTien-TikTok-Sync — mỗi 3 giờ)  [DISABLED]
        |
        v
C:\xampp\php\php.exe artisan affiliate:tiktok-sync --sync
```

Laravel Scheduler (`everyThreeHours()` / `schedule:run` / `schedule:work`) **KHÔNG được dùng** cho affiliate auto sync.
Windows Task Scheduler (`WindowScheduleSync`) chịu trách nhiệm tần suất 3 giờ.

Kiểm chứng: `php artisan schedule:list` báo `No scheduled tasks have been defined.` — là EXPECTED.

Admin/Operator cũng có thể sync qua menu "Đồng bộ đơn hàng TikTok". Cả hai entry (Windows command + Admin POST) dùng **cùng** `TikTokOrderSyncService` và **cùng** lock.

---

## Thông số server thực tế (đã kiểm tra)

| Thông số | Giá trị |
|---|---|
| PHP executable | `C:\xampp\php\php.exe` |
| Project root | `C:\xampp\htdocs\hoantienaff` |
| Working directory (Start in) | `C:\xampp\htdocs\hoantienaff` |
| Laravel storage log | `C:\xampp\htdocs\hoantienaff\storage\logs\laravel.log` |

---

## Cấu hình Task trong Windows Task Scheduler

### 1. Tạo task

1. Mở **Task Scheduler** (Win + R → `taskschd.msc`).
2. Ngăn **Actions** → **Create Task...**.
3. **General** tab:
   - Name: `HoanTien - TikTok Sync`
   - Nếu dùng tài khoản service: chọn **"Run whether user is logged on or not"**.
   - Bỏ chọn **"Run with highest privileges"** nếu không cần.
4. **Triggers** tab → **New...**:
   - Begin the task: `On a schedule` → **Daily**
   - **Repeat task every:** `3 hours`
   - **for a duration of:** `Indefinitely`
   - OK.
5. **Actions** tab → **New...**:
   - Action: `Start a program`
   - **Program/script:** `C:\xampp\php\php.exe`
   - **Add arguments (optional):** `artisan affiliate:tiktok-sync --sync`
   - **Start in (optional):** `C:\xampp\htdocs\hoantienaff`
   - OK.
6. **Conditions** tab:
   - Bỏ chọn `Start the task only if the computer is on AC power`.
   - **Settings** tab: bật `If the task fails, restart every` (tùy chọn, ví dụ 5 phút) nếu muốn tự retry sau lỗi.
7. OK để lưu.

### 2. Lưu ý quyền filesystem

User chạy task phải có quyền ghi vào:

- `C:\xampp\htdocs\hoantienaff\storage\` (`logs/`, `framework/`)
- `C:\xampp\htdocs\hoantienaff\bootstrap\cache\`

Laravel cần tạo/ghi file log và cache ở những thư mục này. Không tự thay đổi quyền Windows khi chưa được yêu cầu — chỉ đảm bảo account chạy task có quyền.

### 3. KHÔNG dùng

- ❌ `schedule:run`
- ❌ `schedule:work`
- ❌ Task chạy mỗi phút (frequency do Windows Task Scheduler đảm bảo 3 giờ)

---

## Exit code (để Windows biết task thất bại)

| Kết quả | Exit code |
|---|---|
| Sync thành công | `0` |
| Lỗi API / auth / config / hệ thống, hoặc lock bị giữ | khác `0` (1) |

Windows Task Scheduler -> **History** / **Last Run Result** hiển thị exit code. `0x0` = thành công; khác `0x0` = thất bại.

---

## Kiểm tra sau khi deploy (CHỈ KHI ĐƯỢC XÁC NHẬN)

> ⚠️ Test bằng nút **Run** của Windows Task Scheduler sẽ chạy **command production thật** (gọi RioHub, ghi DB, credit wallet).
> Chỉ thực hiện trên production **sau khi chủ project xác nhận**.

1. Mở **Task Scheduler**.
2. Chọn task `HoanTien - TikTok Sync`.
3. Click **Run** (ngăn Actions) để test thủ công.
4. Kiểm tra **Last Run Result** (mong đợi `0x0`).
5. Kiểm tra Laravel log: `storage\logs\laravel.log` — tìm `[TikTok Sync] scheduled_windows started / completed / failed`.
6. Kiểm tra `last_tiktok_sync_at` của dòng TikTok trong `affiliate_order_items`.
7. Kiểm tra sync result trong log (orders fetched, inserted, updated, skipped, credits, reversals, errors).

---

## Command manual (không qua Task Scheduler)

```powershell
cd C:\xampp\htdocs\hoantienaff
C:\xampp\php\php.exe artisan affiliate:tiktok-sync --sync
```

Lưu ý: phải chạy từ project root để Laravel nạp đúng `.env`, `vendor`, `bootstrap`, `storage`.

---

## Logging

Mỗi sync ghi `sync_type`:

| Entry | sync_type |
|---|---|
| Windows Task | `scheduled_windows` |
| Admin menu (role Admin) | `manual_admin` |
| Admin menu (role Operator) | `manual_operator` |

Log ghi: `started_at`, `finished_at`, `duration`, `fetched`, `inserted`, `updated`, `skipped`, `credits`, `reversals`, `errors`.

KHÔNG log API key / access token / Authorization header.
