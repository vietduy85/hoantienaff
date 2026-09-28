# Forensic Audit — Sự cố #2: `POST /link-requests` 419 — `https://vn.shp.ee/8D683XZE` (26/09/2026)

> **Phạm vi:** CHỈ ĐỌC (read-only). Không sửa code, không rotate token, không logout, không xoá log, không deploy.
> **Nguyên tắc:** Không giả định CSRF/Session/Shopee. Mọi kết luận dựa trên: Apache access log, `laravel.log`, `csrf-forensic-2026-09-26.log`, mã nguồn tại HEAD `d5e6518`, và timeline từng request.
> **Phân loại:** `CONFIRMED` / `LIKELY` / `POSSIBLE` / `NOT SUPPORTED` / `UNKNOWN — NEEDS VERIFICATION`.

---

## 1. Mô tả sự cố

22h36 ngày 26/09/2026, user **id=17** (iPhone OS 26_6_2, Chrome **CriOS/154.0.8037.55**) tại `https://hoantien.xyz/dashboard` nhập Link Shopee **`https://vn.shp.ee/8D683XZE`**, bấm "Tạo link", UI báo **`❌ Lỗi không xác định`**. Người dùng khai báo **đã tắt hẳn Chrome và mở lại** trước đó. Đây là lần thứ 2 trong ngày cùng một tài khoản/thiết bị gặp đúng lỗi này (sự cố #1 lúc 16:08:10).

## 2. Mốc thời gian chính xác (KHÔNG dựa ảnh chụp)

Lấy theo **Apache access log** (`access_backup_20260805.log`) + **CSRF forensic** (`csrf-forensic-2026-09-26.log`):

| Nguồn | Giá trị |
|---|---|
| Apache — request đi | `26/Sep/2026:16:36:53 +0700` `POST /link-requests HTTP/1.1` → `419` `13331` byte, referer `https://hoantien.xyz/dashboard`, UA `CriOS/154.0.8037.55` |
| Laravel — CSRF forensic log | `2026-09-26 16:36:54.024483+07:00` `ev:"csrf_failure"`, `response_status:419` |
| Ảnh chụp người dùng | ~16:36 (khớp) |

**Kết luận: `CONFIRMED` — POST đi lúc 16:36:53, thất bại CSRF lúc 16:36:54.**

## 3. Bằng chứng "đóng hẳn và mở lại Chrome"

Toàn bộ lưu lượng **CriOS/154.0.8037.55** trong ngày 26/09 (Apache, 12 request — danh sách đầy đủ):

```
16:08:06 GET  /manifest.webmanifest       200 2418  ref=dashboard
16:08:06 GET  /apple-touch-icon.png       304      -
16:08:09 POST /link-requests             419 13331 ref=dashboard   (sự cố #1)
16:09:18 GET  /dashboard                  200 64095 ref=-
16:09:19 GET  /csrf-token                 200 52    ref=dashboard
16:09:19 GET  /favicon.ico                304      -
16:09:43 GET  /csrf-token                 200 52    ref=dashboard
16:09:47 POST /link-requests             200 241    ref=dashboard   (thành công sau reload)
16:09:51 GET  /api/link-request/2723     200 728    ref=dashboard
16:22:40 GET  /csrf-token                 200 52    ref=dashboard   (probe T2 của tab 16:09:18)
16:36:49 GET  /manifest.webmanifest       200 2418  ref=dashboard   (đánh dấu page được kích hoạt lại)
16:36:53 POST /link-requests             419 13331 ref=dashboard   (sự cố #2)
```

**Phát hiện quan trọng (`CONFIRMED`):**
- Không có **một** `GET /dashboard` nào từ Chrome sau `16:09:18` cho tới `16:36:53`. Trang mà POST 16:36:53 gửi lên **không được server render lại** sau khi xoay token.
- Không có `GET /favicon.ico`, không có asset asset nào, không có `GET /csrf-token` ở 16:36 — tức Chrome **không thực hiện page-load qua mạng** ở 16:36; document được phục hồi từ trạng thái local (iOS session snapshot / back-forward cache / resume process).
- `16:36:49 manifest.webmanifest` (referer `dashboard`) là dấu hiệu duy nhất của việc page được (đưa vào) kích hoạt lại ~4 giây trước POST — phù hợp kịch bản "mở lại Chrome, tab được khôi phục".

## 4. HEAD hiện tại

```
d5e6518 fix T2 v2 csrf self recovery
d35b984 fix ShopeeFood SPF shortlink restaurant ID
5ab9ce3 fix loi T1 va T2
```

`git status --short`:
```
 M config/app.php
 M routes/web.php
?? app/Http/Controllers/Debug/T2TestRotateSessionController.php
?? tests/Feature/T2TestRotateSessionTest.php
?? docs/CODEMAP.md
?? docs/BUG_AUDIT_2026-09-26_SHOPEE_UNKNOWN_ERROR.md      (báo cáo sự cố #1)
```
Không file nào liên quan sự cố bị thay đổi bởi audit.

## 5. Request timeline (session `03a5d9026e`, uid17, thiết bị iOS 26_6_2 + iOS 18_7)

| Giờ | Browser | Request | Kết quả | Bằng chứng forensic |
|---|---|---|---|---|
| 05:44:46 | Safari 26.6.1 | GET /dashboard | 200 | render `PAGE-618eb6f0e66ceafc`, token `0d1d2ef645` |
| 05:44:52 | Safari | POST | 200 | `T2-muhjte2p-u6klfn` |
| 10:40:32 | Safari | GET /dashboard | 200 | render `PAGE-eb24826cdc8f2f6d` |
| 10:40:38 | Safari | POST | 200 | `T2-muhudqyk-u0h15o` |
| **16:08:10** | **CriOS** | **POST** | **419** | **csrf_failure, token `7035c67915`, page/t2 rỗng (sự cố #1)** |
| 16:09:18 | CriOS | GET /dashboard | 200 | render `PAGE-e4a5db9c474e115d`, token `0d1d2ef645` |
| 16:09:19 / 16:09:43 | CriOS | GET /csrf-token | 200 | init + probe (T2 V2) |
| 16:09:47 | CriOS | POST | 200 | `T2-mui653lw-n4pnz8` — **T2 tự phục hồi thành công** |
| 16:09:51 | CriOS | GET /api/link-request/2723 | 200 | poll |
| 16:10:44 | Safari | GET /dashboard | 200 | render `PAGE-9fdf3d54c57963be` (cùng session) |
| 16:10:50 | Safari | POST | 200 | `T2-mui66g5q-najpxi` |
| 16:22:40 | CriOS | GET /csrf-token | 200 | probe → tab T2 (bản render 16:09:18) còn sống |
| 16:36:49 | CriOS | GET /manifest.webmanifest | 200 | page được kích hoạt lại |
| **16:36:53** | **CriOS** | **POST /link-requests (8D683XZE)** | **419** | **csrf_failure, token `7035c67915`, page/t2 rỗng (sự cố #2)** |
| 16:37:49 | Safari | GET /dashboard | 200 | render `PAGE-1a034a79c607ac85` |
| 16:37:53 | Safari | POST (8D683XZE) | 200 | `T2-mui758je-h9kzsq` |
| 16:37:55 | (server) | [Resolver] | OK 2217ms | `8D683XZE → shopee.vn/product/75063036/23941308288` |
| 16:38:12 | Safari | GET /dashboard | 200 | render `PAGE-baec74f70d8e82d9` (ref /wallet) |

## 6. Luồng frontend

- Trang dashboard chứa code inline (Alpine `x-data`) trong biến thể mới nhất (T2 V2) — nhưng **chỉ nếu document được render bởi server ở HEAD `d5e6518`**.
- Current T2 V2: `getErrorMessage(data)` trả về `errors → message → error` (blade `link-generator.blade.php` ~dòng 129–141), dòng 195 fallback `this.getErrorMessage(data) || 'Lỗi không xác định'`; khi nhận 419 → `ensureFreshCsrf(true)` (GET /csrf-token) → retry 1 lần → 419 lần 2 → `showSessionExpired()` (`Phiên đăng nhập đã hết hạn`); `bindResumeProbe` trên `visibilitychange/focus/pageshow` (throttle).
- **Sự cố #2 KHÔNG chạy T2:** forensic ghi `page_instance_id:""`, `t2_flow_id:""` — POST **không** mang header `X-Forensic-Page-Id`/`X-Forensic-T2-Flow-Id`, tức JS gửi POST là code **trước ngày 23/09 (pre-`5ab9ce3`)**, và sau 419 **không** có bất kỳ `GET /csrf-token` nào (không có `ensureFreshCsrf`).
- Mã era trước `4efc75a` (01/09): `.then(r => r.json()).then(data => { if (!data.success) this.error = data.error || 'Lỗi không xác định'; ...})` — không kiểm tra status, không bắt lỗi parse. Với body JSON debug 419 → `data.success` undefined → hiện **`Lỗi không xác định`**. `CONFIRMED` cho chuỗi UI.

## 7. Luồng backend

1. `routes/web.php` → `POST /link-requests` (web route) → middleware nhóm web (trong đó có **`VerifyCsrfToken`** trước controller).
2. 16:36:53 → **419 ngay tại CSRF middleware**, controller `store()` **KHÔNG chạy**:
   - `laravel.log` 16:35–16:39 **không có** entry `[Resolver]`, `[CACHE]`, `link_request_post` tại 16:36:53/54 — chỉ có các entry 16:37:55–56 từ lần Safari gửi lại.
   - Forensic chỉ ghi `ev:"csrf_failure"` → xác nhận chặn trước middleware.
3. Khi vượt CSRF (16:37:55 Safari): `[Resolver] Short Link Resolved` thành công → pipeline tốt.

## 8. HTTP status

- Response của POST hỏng: **`419`** (`13331` byte — trang debug JSON của Laravel, `APP_DEBUG=true`). `CONFIRMED` từ Apache.
- Toàn ngày 26/09 chỉ có **đúng 2 POST 419**: `16:08:09` (sự cố #1) và `16:36:53` (sự cố #2) — cùng uid17, cùng Chuỗi CriOS/154, cùng kích thước `13331`. Mọi POST khác đều `200`.
- Không có bất kỳ `401/403/422/429/500/502/503/302` cho action này trong ngày. Nếu là `200` với `success=false` → frontend hiện theo `data.error`; không xảy ra.

## 9. Phân tích CSRF

Forensic `16:36:54.024483`:
```json
{"ev":"csrf_failure","method":"POST","uri":"link-requests","route":"link-requests.store",
 "session_id_fp":"03a5d9026e","session_token_fp":"0d1d2ef645","page_token_fp":"0d1d2ef645",
 "request_token_fp":"7035c67915","auth_state":"login_web_present","user_id":17,
 "recaller_present":true,"ip_fp":"02d444c699","ua":"CriOS/154.0.8037.55 (iPhone OS 26_6_2)",
 "referer":"https://hoantien.xyz/dashboard","page_instance_id":"","t2_flow_id":"","response_status":419}
```
- `request_token_fp` **`7035c67915` ≠** `session_token_fp` `0d1d2ef645` → token trong request là token **đã bị xoay/từ kỳ cũ** (token mà session này dùng **trước khi rotate** — bằng chứng đã thấy `7035c67915` cũng xuất hiện trong sự cố #1 lúc 16:08:10). `CONFIRMED`.
- `7035c67915` là **page token của một document cũ** (render khi session còn dùng token đó), **không phải** token trang render 16:09:18 (`PAGE-e4a5db9c474e115d` mang `0d1d2ef645`).
- Response `419` + `13331` byte = page debug JSON (`{"message":"CSRF token mismatch."...}`) vì `APP_DEBUG=true`.

**Các case A–G:**
- **A — Session hợp lệ + token cũ (STALE):** chính xác. Session `03a5d9026e` hợp lệ (uid17, login_web_present, recaller present), server token `0d1d2ef645`, request token `7035c67915` (cũ) → 419. **CONFIRMED — đây là case của sự cố.**
- **B — Session hết hạn:** loại. `SESSION_LIFETIME=10080` (7 ngày), session vẫn dùng được 16:37:53 (Safari, 200). **NOT SUPPORTED.**
- **C — Session xoay ngay trước POST:** loại. Không event xoay nào cho `03a5d9026e` trong cả ngày (xem Mục 10); token `0d1d2ef645` ổn định 05:44→16:38. **NOT SUPPORTED.**
- **D — Token đúng nhưng vẫn 419:** loại. Token gửi khác token server (thuộc kỳ cũ). **NOT SUPPORTED.**
- **E — Bug JS gửi sai token:** không có bug trong code hiện tại (T2 gửi token lấy từ `/csrf-token` + header mới); code gửi ở đây là code **cũ** mang token nhúng cũ. Đây là hệ quả của document cũ, không phải bug mới. **POSSIBLE (mô tả cơ chế), không phải bug backend.**
- **F — Nhiều tab / nhiều browser:** **CONFIRMED** — cùng session `03a5d9026e` dùng bởi **CriOS** (16:08/16:09/16:36) và **Safari** (05:44/10:40/16:10/16:37); trên cùng thiết bị có ≥2 instance Chrome (tab legacy `7035c67915` và tab T2 render 16:09:18 còn probe 16:22:40).
- **G — Cookie session không gửi:** loại. `recaller_present:true`, `auth_state:login_web_present`, `session_id_fp` khớp → cookie `hoantien_session_v2` (Secure) vẫn được gửi và session resolved. **NOT SUPPORTED.**

## 10. Phân tích session (rotation audit ≥60 phút trước 16:36 → mở rộng 24h)

- Forensic toàn ngày: **không có** bất kỳ event `t2_test_rotate`/xoá session/logout nào liên quan `03a5d9026e`. Test rotate (`__t2-test/rotate-session`) chỉ thuộc session khác (12:31 uid5, 11:48–11:50 testing).
- `03a5d9026e` + token `0d1d2ef645` xuất hiện lần đầu 05:44:46 (Safari), ổn định xuyên 10:40, 16:08, 16:09, 16:36, 16:37, 16:38. **KHÔNG xoay token** quanh 16:36.
- Không có hành động logout → sau đó re-login (không có event auth mới).

**Kết luận: Session hợp lệ trong suốt thời điểm sự cố; lỗi không phải do session hết hạn/xoay. `NOT SUPPORTED` cho "Session expired/rotated".**

## 11. Phân tích xác thực (auth)

- `auth_state:"login_web_present"`, `user_id:17`, `recaller_present:true`, `recaller_fp:"b6d61e35a0"` → user vẫn đăng nhập trong session này.
- User không bị đăng xuất ở bất kỳ request nào trong dãy. **NOT SUPPORTED** cho lỗi xác thực.

## 12. Phân tích cache / trình duyệt / PWA

- **Service Worker** (`public/sw.js`): chiến lược rõ ràng — chỉ cache asset tĩnh (regex `.(css|js|png|...)`); **HTML/navigation và `/csrf-token` là network-only** (`request.mode === 'navigate'` → return; path `/csrf-token` → return). Vì vậy **SW KHÔNG thể** phục vụ `/dashboard` cũ từ cache. `sw.js` được đăng ký trong `resources/js/app.js` (khi `__PWA_ENABLED !== false`). **SW KHÔNG phải nguyên nhân phục vụ document cũ. `NOT SUPPORTED`.**
- `manifest.webmanifest`: `start_url "/"`, có shortcuts `Tạo link → /dashboard`; request manifest lúc 16:36:49 khớp việc (re)activation/kích hoạt PWA.
- **Cơ chế hợp lý nhất** cho việc document cũ (token `7035c67915`, JS pre-T2) sống sót qua "đóng hẳn và mở lại":
  - (M1) iOS Chrome resume process: tab không bị kill thực sự (app rời nền, bị iOS giữ trạng thái), khi mở lại page hiện nguyên trạng **không cần load lại**.
  - (M2) Chrome for iOS session-restore: khi một WKWebView trở lại với page được ghim, page được khôi phục từ snapshot/back-forward cache cục bộ, chưa network nếu tab không được focus-load (lazy reload) — người dùng thao tác ngay.
  Cả 2 đều **không thể nhìn thấy từ phía server**; quan sát server giống hệt nhau: **không render mới, không /csrf-token, POST ngay với token cũ**. → Không phân biệt được M1/M2 từ log → gắn nhãn `UNKNOWN — NEEDS VERIFICATION` cho *cơ chế* khôi phục, nhưng *hệ quả* thì `CONFIRMED`.

## 13. Phân tích Cloudflare

- Mọi client trong access log đều là `::1` (xuyên **cloudflared tunnel tới Apache**); không thấy header/bằng chứng chặn-cache của Cloudflare; CF chỉ là reverse proxy.
- Không có dấu hiệu Cloudflare giữ cache HTML hay chặn POST (POST đã tới Apache và Laravel).
- **Không có bằng chứng Cloudflare can thiệp. `NOT SUPPORTED`.**

## 14. Phân tích Shopee resolver

- URL `https://vn.shp.ee/8D683XZE` **không hề được gọi resolver** tại 16:36:53 vì CSRF chặn trước controller (không entry `[Resolver]` trong `laravel.log` 16:35–16:39 tại 16:36).
- Cùng URL gửi lại từ Safari 16:37:55: `[Resolver] Short Link Resolved {"original":"https://vn.shp.ee/8D683XZE","resolved":"https://shopee.vn/product/75063036/23941308288?d_id=8ae5b&uls_trackid=56nrad5f00jf&utm_content=2XTyRsDPiH5fJxxQ78pCxPZY6aoy","total_ms":2217}` → **resolver + shortlink hoạt động bình thường.**
- Shopee **không liên quan** tới lỗi UI này. **NOT SUPPORTED.**

## 15. Phân tích external API (Shopee product data)

- 16:37:56: `[CACHE] {"item_id":23941308288,"status":"MISS","cache_date":"2026-09-26"}` → `[CACHE] ProductData URL {...}` → `[CACHE-Timing] Refresh Cache {"elapsed_ms":921}` — pipeline API bên ngoài hoạt động, thậm chí tạo cache.
- Không application-error/exception trong `laravel.log` quanh sự cố. **NOT SUPPORTED** cho lỗi external API.

## 16. Phân tích middleware

- Nhánh 419 được ném tại `VerifyCsrfToken` (Laravel `Middleware\VerifyCsrfToken` trả 419) **trước khi** `LinkRequestController@store` chạy — backup bằng: forensic chỉ có `csrf_failure`, không có `link_request_post`, không `[Resolver]`, không `[CACHE]`, không exception log tại 16:36.
- Route `link-requests.store` nằm trong nhóm `web` (session + csrf). Thứ tự middleware chuẩn Laravel, không ghi đè.

## 17. Phân tích T2 V2 (cơ chế tự phục hồi)

- T2 V2 (HEAD `d5e6518`) **đã từng tự phục hồi thành công** trong sự cố #1 trên đúng thiết bị này: 16:09:43 `GET /csrf-token` (bởi probe/reload) → 16:09:47 POST 200 `T2-mui653lw-n4pnz8` sau khi document được render lại (`PAGE-e4a5db9c474e115d`).
- Tại 16:36:54 **T2 không thể chạy** vì document thực hiện POST là **document pre-T2** (không có header forensic, không tự `ensureFreshCsrf`). Đây là lý do không thấy `/csrf-token` sau 16:36:53.
- `bindResumeProbe` (visibility/focus/pageshow) chỉ nằm trong code T2 — page legacy không có cơ chế nào để "tự chữa".

**Kết luận: T2 V2 KHÔNG lỗi; nó không được kích hoạt vì code gửi POST là code cũ. `CONFIRMED` (không chạy), `NOT SUPPORTED` (lỗi T2).**

## 18. So sánh sự cố #1 vs #2

| Thuộc tính | Sự cố #1 (26/09 16:08) | Sự cố #2 (26/09 16:36) |
|---|---|---|
| Timestamp (server) | Apache 16:08:09; forensic 16:08:10.262 | Apache 16:36:53; forensic 16:36:54.024 |
| User | 17 | 17 |
| UA | CriOS/154.0.8037.55 (iPhone OS 26_6_2) | CriOS/154.0.8037.55 (iPhone OS 26_6_2) |
| IP fp | `02d444c699` | `02d444c699` |
| Session FP | `03a5d9026e` | `03a5d9026e` |
| Session token FP (server) | `0d1d2ef645` | `0d1d2ef645` |
| Page token FP | `0d1d2ef645` | `0d1d2ef645` |
| Request token FP (gửi đi) | **`7035c67915`** (cũ) | **`7035c67915`** (cũ — GIỐNG HỆT) |
| HTTP status / bytes | 419 / 13331 | 419 / 13331 |
| GET /dashboard trước đó | 16:08:06 manifest; không GET /dashboard | có 16:09:18 (render T2) — nhưng POST không từ document đó |
| GET /csrf-token trước đó | không (16:08–16:09) | không (16:22:40 là của tab T2 khác) |
| Controller reached | Không (chặn CSRF) | Không (chặn CSRF) |
| Resolver reached | Không | Không (OK khi thử lại Safari 16:37:55) |
| UI error | `❌ Lỗi không xác định` | `❌ Lỗi không xác định` |
| page_instance_id / t2_flow_id | `"" / ""` | `"" / ""` |

→ **Cùng một bộ dấu vân tay, cùng document cũ (token `7035c67915`, JS pre-T2), cùng session — sự cố #2 = tái diễn của sự cố #1 sau khi tab được khôi phục mà không được server render lại.**

## 19. Root cause

`CONFIRMED`:

> **Trang dashboard mà Chrome gửi POST tại 16:36:53 là một DOCUMENT CŨ (kỳ trước khi token xoay): token CSRF nhúng `7035c67915` đã bị server thay bằng `0d1d2ef645`; JS gắn trong document là bản pre-T2/pre-4efc75a. Sau khi user "đóng hẳn và mở lại" Chrome, Chrome khôi phục/trình làn tab này TỪ TRẠNG THÁI LOCAL mà KHÔNG render lại từ server (không có GET /dashboard), nên token vẫn là token cũ → `POST /link-requests` bị `419 CSRF token mismatch` → JS legacy không có nhánh 401/419 cũng không có T2-recovery, và với body JSON debug nó rơi vào fallback hiển thị `❌ Lỗi không xác định`.**

- Không liên quan: session/auth (hợp lệ), resolver/Shopee/API ngoài (chạy ngay sau đó trong Safari), Cloudflare, SW/PWA cache (sw.js không cache HTML), rate-limit.
- Nguyên nhân gốc về sản phẩm: **token CSRF được nhúng tĩnh vào HTML và có giá trị vô thời hạn trong document cũ; không có cơ chế nào ở document cũ phát hiện token hết hiệu lực sau khi xoay.** T2 V2 đã sửa cho document MỚI; document cũ chưa thể được "chữa" vì nó không chạy code mới.

## 20. Bằng chứng (evidence)

| # | Bằng chứng | Nguồn |
|---|---|---|
| E1 | POST `16:36:53 /link-requests` 419 13331, referer dashboard, UA CriOS/154 | `access_backup_20260805.log` |
| E2 | `csrf_failure` `16:36:54.024483`, token request `7035c67915` ≠ server `0d1d2ef645`, uid17, login_web_present, page/t2 rỗng | `csrf-forensic-2026-09-26.log` |
| E3 | Không có GET /dashboard/asset/csrf-token từ CriOS giữa 16:09:51 và 16:36:49 (chỉ 16:22:40 csrf-token + 16:36:49 manifest) | Apache (12 dòng CriOS/154) |
| E4 | Document gửi 419 không phải trang render 16:09:18 (`PAGE-e4a5db9c474e115d` mang token `0d1d2ef645` + T2) | forensic D270–271 |
| E5 | Chỉ 2 POST 419 cả ngày (16:08:09, 16:36:53), cùng kích thước 13331, cùng uid17 | Apache |
| E6 | Không entry backend tại 16:36 (không [Resolver]/[CACHE]/exception); controller không chạy | `laravel.log` |
| E7 | URL `8D683XZE` resolve OK từ Safari 16:37:55 (2217ms) | `laravel.log [Resolver]` |
| E8 | sw.js network-only cho HTML/navigation/csrf-token | `public/sw.js` |
| E9 | `SESSION_LIFETIME=10080`, `SESSION_DRIVER=database`, cookie `hoantien_session_v2` Secure | `.env` |
| E10 | Head `d5e6518`; code era: 86c8163 (pre-fix) → 4efc75a (nhánh 401/419) → d5e6518 (T2 V2) | git |
| E11 | T2 V2 tự phục hồi thành công trên chính thiết bị này lúc 16:09:47 (`T2-mui653lw`) | forensic D271 |

## 21. Bằng chứng CÒN THIẾU / chưa thể xác minh

- **Cơ chế chính xác phía iOS Chrome** đưa document cũ trở lại (M1 resume-process hay M2 session-restore snapshot) — server không quan sát được → `UNKNOWN — NEEDS VERIFICATION` (cần xác nhận trên thiết bị: `chrome://net-export`, WKWebView logs, hoặc test lại có kiểm soát).
- Headers response thực tế của `/dashboard` (Cache-Control) — Apache không log header; không lấy từ add-header config.
- Trạng thái JS heap của tab lúc 16:36 (chỉ suy luận từ signal mạng).
- Ảnh chụp màn hình (chưa có) — chỉ khớp giờ ≈16:36.

## 22. Đề xuất sửa (không thực hiện trong audit)

1. **Phát hiện document cũ ở frontend:** so sánh token nhúng trong DOM với token new nhất nhận từ request — nếu khác khi submit → chặn submit, lấy token mới (`GET /csrf-token`), thay token, retry tự động (đưa cơ chế T2 về **cả trang legacy** — ví dụ đoạn script nhỏ trong `<head>` thực hiện `ensureFreshCsrf` khi resume).
2. **`pageshow`/`visibilitychange` probe trên mọi page** (không chỉ T2) để phục hồi trước khi user thao tác.
3. **Xoay token mạnh hơn:** (nếu sản phẩm quyết tâm) token nhúng có thời gian sống ngắn + tự động rotate qua event — nhưng cần cân nhắc UX.
4. **Resp 419 chuẩn hóa JSON** + frontend xử lý đúng cho MỌI era JS đang được cache ở người dùng: nhánh `r.status===419/401` không phụ thuộc version.
5. **Gỡ bỏ fallback "Lỗi không xác định"**: thay bằng message kỹ thuật rõ ràng + nút "Thử lại/Refresh" để giảm mơ hồ.
6. (Chuyên mục riêng) **lộ token extension poller** trong query string — đưa vào header/auth.

## 23. Đề xuất test

- Test E2E: mở dashboard → xoay session/token phía server (dùng endpoint test `__t2-test/rotate-session`) → resume tab mà không reload (simulate mobile) → submit → phải tự lấy token mới → POST 200.
- Test regression: reload-after-rotate (sự cố #1 kịch bản đã sửa) vẫn hoạt động (đã có `tests/Feature/T2TestRotateSessionTest.php`).
- Test đa browser cùng session (Chrome tab cũ + Safari) submit liên tiếp.
- Test legacy era JS (mock response 419 JSON/HTML) để khẳng định chuỗi UI.

## 24. Đề xuất monitoring

- Alert khi `csrf_failure` từ cùng `user_id`/`ip_fp`/`session` lặp lại >1 lần trong 1 giờ.
- Theo dõi tỷ lệ doc cũ: đếm `POST` thiếu `X-Forensic-Page-Id` (page/t2 rỗng) từ user đăng nhập → nếu cao, cần refresh cưỡng bức.
- Ghi nhật ký `Cache-Control` của `/dashboard` để phục vụ điều tra sau này.

## 25. Rủi ro

- Thấp–Trung bình: sai lệch giữa nhận thức người dùng ("đã tắt hẳn") và thực tế client (tab resume) — có thể dẫn đến kỳ vọng sai về fix. Cần truyền thông đúng rằng lỗi nằm ở **document cũ giữ token đã xoay**, không phải backend/session/Shopee.
- Nuance: token `7035c67915` bị giữ vô hạn trong tab cũ là dấu hiệu UX mệt mỏi với user; nếu không có probe/buffer, user sẽ tái lặp gặp lỗi.
- Không có rủi ro bảo mật ngoài lộ token extension (đã ghi chú).

---

## BẢNG KẾT LUẬN CUỐI

| Mục | Status | Bằng chứng |
|---|---|---|
| Browser reload/restart evidence | **CONFIRMED (không render lại sau restart)** | Không GET /dashboard từ CriOS giữa 16:09:18→16:36:53; chỉ manifest 16:36:49 (E3) |
| POST /link-requests reached server | **CONFIRMED** | Apache `16:36:53 POST /link-requests 419 13331` (E1) |
| HTTP status | **CONFIRMED 419** | Apache 419; forensic response_status:419 (E1/E2) |
| CSRF mismatch | **CONFIRMED** | request `7035c67915` ≠ session `0d1d2ef645`; csrf_failure (E2) |
| Session expired | **NOT SUPPORTED** | Session `03a5d9026e` hợp lệ; Safari 200 16:37:53; LIFETIME 10080 (E6/E9) |
| Session rotated | **NOT SUPPORTED** | Token `0d1d2ef645` ổn định cả ngày; không rotate gần 16:36 (forensic toàn ngày) |
| Auth failure | **NOT SUPPORTED** | `login_web_present`, uid17, recaller present (E2) |
| Frontend error | **CONFIRMED** — JS legacy hiện `Lỗi không xác định` | page/t2 rỗng; không csrf-token sau 419; era pre-4efc75a fallback (E2/E4, code 86c8163) |
| Shopee resolver failure | **NOT SUPPORTED** | `8D683XZE` resolve OK 16:37:55 (2217ms) (E7) |
| External API failure | **NOT SUPPORTED** | `[CACHE]` MISS→refresh 921ms OK 16:37:56 (E7/laravel.log) |
| Rate limit | **NOT SUPPORTED** | Không 429; cả ngày không có (E5) |
| Cloudflare/cache | **NOT SUPPORTED** | Mọi client `::1` tunnel; không cache HTML (E8, access log) |
| SW/PWA | **NOT SUPPORTED** (gây document cũ) | sw.js network-only HTML/navigation/csrf (E8) |
| T2 recovery executed | **NOT SUPPORTED** (lần 16:36 — code gửi là legacy); nhưng T2 recovery ĐÃ chạy đúng lúc 16:09:47 | Không header t2; không csrf-token sau 419 (E2/E4); T2-mui653lw 16:09:47 (E11) |
| **Root cause** | **CONFIRMED** — Stale CSRF token `7035c67915` trong document pre-T2 được Chrome khôi phục từ local KHÔNG qua server render lại sau khi token xoay → POST 419 → UI legacy `Lỗi không xác định`; tái diễn đúng signature sự cố #1 | E1–E11 |

---

## Trả lời nhanh 10 câu hỏi

1. **Kịch bản chính xác?** Chrome (trên iPhone, iOS 26_6_2) đang giữ 1 tab dashboard RENDER TỪ KỲ token `7035c67915` (trước khi xoay), JS pre-T2. Sau khi "đóng hẳn → mở lại", tab được khôi phục từ local (không load lại server). User dán `https://vn.shp.ee/8D683XZE`, bấm tạo link → POST bằng token cũ → 419.
2. **Lỗi tầng nào?** Tầng **client/browser document cũ** — không phải server, không phải database, không phải network (POST tới server bình thường).
3. **Token request đã gửi?** `7035c67915` (token cũ kỳ trước rotate); server hiện giữ `0d1d2ef645`.
4. **Vì sao hiện "Lỗi không xác định"?** JS legacy trong document cũ: không kiểm tra status 419, không nhánh 401/419, không T2; với body JSON debug → `!data.success` → fallback "Lỗi không xác định".
5. **Vì sao T2 V2 không tự phục hồi?** T2 V2 không hề nằm trong document này (pre-T2). Cơ chế self-recovery của T2 V2 đã chứng minh hoạt động đúng lúc 16:09:47 ngay sự cố #1.
6. **Session hết hạn/xoay?** Không. Session `03a5d9026e` + token `0d1d2ef645` ổn định từ 05:44; không xoay/logout quanh 16:36.
7. **Cloudflare/Cache/SW/PWA?** Không liên quan: sw.js network-only cho HTML/navigation/csrf-token; không cache document.
8. **Resolver/API Shopee lỗi?** Không. Cùng URL resolve thành công từ Safari 16:37:55 (2217ms) + cache product 921ms.
9. **Vì sao Chrome "đóng hẳn" mà token vẫn cũ?** Vì tab được Khôi phục từ trạng thái local (resume process / session-restore snapshot) mà không thực hiện GET /dashboard nào — server không hề render lại để cập nhật token vào document.
10. **Hướng xử lý?** (xem Mục 22) — đưa probe/ensureFreshCsrf về mọi page kể cả legacy, thay token trong DOM trước submit, chuẩn hóa 419 JSON + xử lý theo mọi era JS, thay fallback "Lỗi không xác định" bằng message rõ ràng + nút thử lại.