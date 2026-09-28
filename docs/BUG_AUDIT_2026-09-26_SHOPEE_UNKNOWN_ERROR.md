# BUG AUDIT — "Lỗi không xác định" khi tạo link Shopee (hoantien.xyz)

**Ngày audit:** 2026-09-26 (Sài Gòn, +07:00)
**Trạng thái:** READ-ONLY forensic audit — **không sửa code, không deploy, không thay đổi dữ liệu.** Chỉ phân tích log + code + git. Chưa được "fix" bằng bất kỳ câu lệnh nào.

---

## 1. Sự cố (Incident)

- Người dùng (user id 17) đang đăng nhập trên `https://hoantien.xyz/dashboard`, để tab mở **rất lâu** (cả ngày/đêm), sau đó quay lại, dán link `https://vn.shp.ee/jnvdLz7j` và submit.
- UI hiển thị dải đỏ với nội dung **`❌ Lỗi không xác định`** (dựa trên mô tả của người dùng; chưa đối chiếu bằng ảnh chụp — xem mục 19).
- Người dùng không bị đăng xuất; sau khi tự reload trang và submit lại thì **thành công** (link Shopee được tạo).

## 2. Môi trường (Environment)

| Thành phần | Giá trị (evidence) |
|---|---|
| Máy chủ | Windows (XAMPP), Apache `httpd.exe`, PHP |
| Reverse proxy | Cloudflare → `cloudflared tunnel run hoantien` (process 2084/2924) → Apache (mọi request ghi `::1`) |
| Laravel | HEAD `d5e6518f8a789aeee394fdb278c1ccdf9d2e26b2` |
| `.env` | `APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=https://hoantien.xyz`, `SESSION_DRIVER=database`, `SESSION_LIFETIME=10080` (7 ngày), `SESSION_COOKIE=hoantien_session_v2`, `SESSION_SECURE_COOKIE=true`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `LOG_CHANNEL=stack`, `LOG_LEVEL=debug` (không in ra secret) |

## 3. Trạng thái Git (current HEAD)

- `main` @ `d5e6518` "fix T2 v2 csrf self recovery" (2026-09-26 01:27:04 +07:00).
- Commit liên quan: `4efc75a` (2026-09-01) "fix ux het han phien ... khong loi khong xac dinh"; `5ab9ce3` (2026-09-23) "fix loi T1 va T2"; `d35b984` (2026-09-23) ShopeeFood SPF.
- Working tree dirty (sẵn có trước audit, không thuộc sự cố): `config/app.php`, `routes/web.php`; untracked: `app/Http/Controllers/Debug/T2TestRotateSessionController.php`, `tests/Feature/T2TestRotateSessionTest.php`, `docs/CODEMAP.md`.

## 4. Reproduction (cách tái hiện — KHÔNG thực hiện thật trong audit)

Quy trình tái hiện (dựa trên log, không fake dữ liệu):
1. Đăng nhập uid17, để tab Chrome `/dashboard` mở **không reload** qua nhiều ngày.
2. Trên thiết bị có session hợp lệ (không hết hạn), sau khi CSRF token của session bị xoay vòng (re-login/một tab khác), tab cũ vẫn giữ token đã render từ trước.
3. Người dùng quay lại và submit → POST mang token cũ → 419.
4. Nếu tab đang chạy code cũ (trước 01/09/2026) → hiển thị "Lỗi không xác định".

## 5. Luồng request (Frontend submit path)

- Tab cũ gọi `POST /link-requests` (route `link-requests.store`) với headers `Content-Type: application/json`, `X-Requested-With: XMLHttpRequest`, `X-CSRF-TOKEN: <token render từ trang>`.
- Middleware CSRF (`ValidateCsrfToken`) chạy **trước** controller → mismatch → **HTTP 419**, không đi vào controller, không ghi log `[Resolver]`/`[ENTER store]` nào.
- Sau reload thủ công, submit lại tại 16:09:47 → 200, và log `[Resolver] Short Link Resolved {"original":"https://vn.shp.ee/jnvdLz7j","resolved":"https://shopee.vn/product/91309965/28608029100?...","total_ms":2040}`. → **Toàn bộ pipeline Shopee hoạt động đúng khi CSRF hợp lệ.** Vấn đề không nằm ở Shopee/resolver.

## 6. Luồng backend (Backend flow)

- `routes/web.php` → `DashboardController`/strategy direct → `UrlResolverService` → các `Strategy`; POST tạo `LinkRequest` + job; pól `GET /api/link-request/{id}` lấy trạng thái.
- Ở thời điểm 16:08:10 request **không vào controller** (CSRF block trước). Không có `link_requests` row mới từ 16:08 (không chạy fake để xác nhận DB — suy từ không có log controller).
- `laravel.log` cửa sổ 16:07–16:10 chỉ có polling `/api/extension/jobs` (127.0.0.1, mỗi ~2s) và, từ 16:09:49, cụm `[Resolver]`/`[CACHE]` của retry thành công.

## 7. Xử lý lỗi phía frontend (3 thế hệ code)

| Thời đại code | Xử lý khi 419 | Kết quả UI |
|---|---|---|
| Trước `4efc75a` (trước 01/09/2026) | `r.json()` (không bắt lỗi parse) | Nếu body là JSON (debug) → `!data.success` → **"Lỗi không xác định"** (fallback `link-generator.blade.php`). Nếu body HTML → rơi vào `.catch` → "Không thể kết nối máy chủ" |
| `4efc75a` (01/09) | `if (r.status===401\|\|419)` → "Phiên đăng nhập đã hết hạn" + redirect `/login` sau 1.2s | Không phải "Lỗi không xác định" |
| `5ab9ce3` (T2 v1) + `d5e6518` (T2 v2, hiện tại) | 419 → `ensureFreshCsrf(true)` (GET `/csrf-token`) → retry đúng 1 lần → nếu vẫn 401/419 → `showSessionExpired()` | Không phải "Lỗi không xác định" |

**Kết luận mục 7:** Message "Lỗi không xác định" ở sự cố này chỉ khớp thế hệ **trước `4efc75a`** khi body 419 là JSON debug (`data.message` = "CSRF token mismatch.", không có `success`/`error`) → fallback string được render. Thời đại code này cũng **không** gọi `/csrf-token` — khớp với Apache log (không có GET `/csrf-token` giữa 16:08:09 và 16:09:18). → **Tab bị cũ ≥ 01/09/2026.**

## 8. Phân tích CSRF/session (Lõi sự cố)

Evidence forensic (file `csrf-forensic-2026-09-2[56].log`, uid17):

| Thời điểm | ev | session_id_fp | session_token_fp | page_token_fp | request_token_fp | auth_state |
|---|---|---|---|---|---|---|
| 25/09 11:25:04 | `csrf_failure` | `03a5d9026e` | `0d1d2ef645` | `0d1d2ef645` | **`7035c67915`** | `login_web_present` |
| 26/09 16:08:10 | `csrf_failure` | `03a5d9026e` | `0d1d2ef645` | `0d1d2ef645` | **`7035c67915`** | `login_web_present` |

- **Cùng một session, cùng server token, cùng request token cũ, cùng uid17, vẫn đăng nhập** ở cả 2 lần fail (cách nhau ~28h).
- `request_token 7035c67915 ≠ session_token 0d1d2ef645` → `ValidateCsrfToken` fail → 419. Session **không** hết hạn, **không** logout, recalled cookie present.
- Token `7035c67915` là CSRF token của session **tại thời điểm tab Chrome được render**; sau đó session 03a5d9026e xoay token sang `0d1d2ef645` (điển hình do re-login/regeneration; có bằng chứng churn session của uid17 trong cửa sổ 25/09 ~11:25–11:26 gồm `eb11a8c9f1/f90f713b84` rồi quay lại `03a5d9026e/0d1d2ef645`).
- Tab Chrome **không bao giờ reload** trong khoảng đó (Apache: không có GET `/dashboard` hay `/csrf-token` từ UA CriOS giữa 25/09 11:25:04 và 26/09 16:08:09) → giữ nguyên token cũ → hỏng mỗi lần submit từ tab đó.

**Phân loại: CSRF mismatch do CSRF token render-bị-cũ trong tab mở lâu — CONFIRMED** (dựa trên fingerprint khớp chính xác 3 trường).

## 9. Phân tích Laravel session

- Driver `database`; `SESSION_LIFETIME=10080` phút = 7 ngày; cookie `hoantien_session_v2`, secure=true.
- Session `03a5d9026e` còn hiệu lực tại 16:08 (auth_state `login_web_present`, recaller present) → **không** do hết hạn session.
- Không có rotation tại đúng 16:08 (session id và server token không đổi giữa 25/09 11:25 và 26/09 16:08) → **giả thuyết "session bị rotate khiến token đổi tại lúc submit" = NOT SUPPORTED.**
- Nguyên nhân token đổi nằm ở khoảng **trước 25/09 11:25** (window re-auth 25/09 11:25–11:26), tức tab đã cũ sẵn trước lần fail đầu tiên.

## 10. Phân tích Shopee URL / strategy

- `https://vn.shp.ee/jnvdLz7j` (short link) → `UrlResolverService` → 1 redirect → product `https://shopee.vn/product/91309965/28608029100?...` (log 16:09:49). Time: 2 s, rr1 redirect — bình thường.
- Tại thời điểm 16:08 request **không** chạm tới resolver. Không có lỗi provider trong `laravel.log` cửa sổ sự cố.
- **Shopee/provider/resolver = NOT SUPPORTED.**

## 11. API ngoài / gọi outbound

- Không có gọi outbound nào tại thời điểm 16:08 (CSRF block trước controller). Retry thành công không xuất hiện lỗi mạng/3rd-party.
- Lưu ý (không liên quan sự cố): token poller `/api/extension/jobs?token=hoantien-affiliate-extension-2026` xuất hiện trần trong URL Apache log — xem mục 23.

## 12. Vòng đời tab / trình duyệt

- iOS 26_6_2, Chrome (CriOS/153 → CriOS/154), PWA có `manifest.webmanifest` (bằng chứng tại 25/09 11:25:00 / 11:23:23 favicon).
- Với tab nền trên iOS, `visibilitychange` thường không bắn khi màn hình khóa / app bị suspend; BFCache có thể khôi phục DOM cũ (kể cả hàng JS chưa chạy lại). Tab ở lại trạng thái đúng thời điểm render cũ → token + script cũ.
- Sự nhất quán fingerprint (request token như nhau ở 2 ngày cách nhau ~28h) buộc phải là **cùng một trang web/DOM chưa bao giờ được nạp lại** → CONFIRMED cho "tab mở lâu, không reload".

## 13. Cloudflare-cache / PWA / service worker

- Cache ở Cloudflare/Kernel chưa quan sát trực tiếp trong audit (chỉ đọc log Apache phía sau). Không có bằng chứng SW lưu HTML cho `/dashboard`.
- `manifest.webmanifest` cho thấy trang có cấu hình PWA; **VẬN HÀNH KHÔNG bị ảnh hưởng** bởi cache theo bằng chứng (sau reload, trang mới nhận token mới đúng).
- **Cloudflare-cache = UNKNOWN/NEEDS VERIFICATION** (không có log X-Cache; không phải yếu tố gây sự cố theo chuỗi token fingerprint).*

## 14. Phân tích access log (Apache)

- `C:\xampp\apache\logs\access_backup_20260805.log` (active, 1.4 GB; `access.log` = 0 bytes).
- 26/09 16:08:09 `POST /link-requests` → **419**, 13,333 B, referer `https://hoantien.xyz/dashboard`, UA `CriOS/154.0.8037.55 iPhone OS 26_6_2`.
- Giữa 16:08:09 và 16:09:18: **không có** GET `/csrf-token`, **không có** GET `/dashboard` từ thiết bị này → recovery T2 không chạy → tab dùng code cũ.
- 16:09:18 `GET /dashboard` referer `-` (reload thủ công); 16:09:19 + 16:09:43 `GET /csrf-token` 200; 16:09:47 `POST /link-requests` 200 (thành công); 16:10:44 dashboard (Safari iPhone 18_7); 16:10:50 POST 200.
- 25/09 11:25:04: `POST /link-requests` **419**, 13,331 B, cùng UA CriOS — lần fail đầu, không có `/csrf-token` theo sau.

## 15. Phân tích forensic + app log

- `csrf-forensic-2026-09-26.log` (273 events parse được): **1 và chỉ 1** `csrf_failure` trong ngày (16:08:10.262) cho real user; các `link_request_post` khác đều 200 (uid65 16:01:52, uid17 16:09:49 / 16:10:50; phần còn lại là noise Symfony test 165×200 / 27×422).
- `laravel.log`: cửa sổ 16:07–16:10 chỉ có polling jobs (127.0.0.1); **không** có exception/`[Resolver]`/store log nào quanh 16:08 → xác nhận 419 bị chặn ở middleware.
- 16:09:49 `[Resolver] Attempt 1{"result":"success","ms":2026,"redirects":1}` + Short Link Resolved = retry sau reload → pipeline OK.

## 16. So sánh T2 V2 — vì sao self-recovery không chạy

- T2 V2 hiện tại (d5e6518) sẽ: bắt 419 → `ensureFreshCsrf` (GET `/csrf-token`) → retry → 200. Nếu tab chạy code này thì lần 16:08 sẽ **tự thành công**, không bao giờ thấy "Lỗi không xác định".
- Thực tế không có GET `/csrf-token` → **tab chạy JS thế hệ trước T2** (>~01/09/2026, thực tế trước 01/09). Self-recovery không bao phủ những tab "hóa thạch" này — chúng không có `bindResumeProbe`.
- T2 v2 đã đúng hướng cho tab mới; nhưng **không đụng tới** tab cũ vì JS cũ không chứa logic mới.

## 17. Phân loại root-cause (classification)

| # | Pát biểu | Phân loại |
|---|---|---|
| 1 | POST /link-requests 26/09 16:08:09/10 bị 419 từ middleware CSRF | **CONFIRMED** |
| 2 | Nguyên nhân 419 = CSRF token mismatch: request token `7035c67915` ≠ session token `0d1d2ef645`, cùng session `03a5d9026e`, uid17, đã đăng nhập | **CONFIRMED** |
| 3 | Session không bị hết hạn / không bị logout tại thời điểm sự cố | **CONFIRMED** |
| 4 | Tab đã mở rất lâu, trang/DOM/token được render từ trước khi session rotate CSRF token; chưa bao giờ reload (fingerprint trùng 2 ngày) | **CONFIRMED** |
| 5 | Session bị rotate ngay tại lúc submit (16:08) | **NOT SUPPORTED** |
| 6 | Lỗi đến từ Shopee/provider/resolver | **NOT SUPPORTED** |
| 7 | UI "Lỗi không xác định" xuất phát từ fallback của code cũ (pre-4efc75a) khi body 419 là JSON debug | **LIKELY** (khớp chặt chuỗi code, nhưng body 419 HTML-vs-JSON chưa chụp trực tiếp — mục 19) |
| 8 | Token `7045`... trong query extension jobs gây sự cố này | **NOT SUPPORTED** |

**Root cause:** Trang dashboard trong tab Chrome của uid17 được render **trước 01/09/2026** (code tiền-fix, giữ CSRF token `7035c67915` từ thời điểm render). Session `03a5d9026e` sau đó xoay vòng CSRF token về `0d1d2ef645` (re-auth 25/09). Tab không bao giờ reload → mọi submit từ tab đó gửi token cũ → **HTTP 419. Session vẫn hợp lệ, vẫn đăng nhập**. Code cũ parse body lỗi theo kiểu legacy rồi render fallback **"Lỗi không xác định"**. Reload thủ công lấy token mới → retry thành công.

## 18. Evidence (bằng chứng)

- `storage/logs/csrf-forensic-2026-09-26.log` (dòng 16:08:10.262) và `csrf-forensic-2026-09-25.log` (11:25:04).
- `C:\xampp\apache\logs\access_backup_20260805.log`: 419 dòng 16:08:09 (13,333 B) / 25/09 11:25:04 (13,331 B); chuỗi csrf-token/reload/succ 16:09:18–16:09:47.
- `storage/logs/laravel.log` 16:07–16:10 (không exception quanh 16:08; `[Resolver]` 16:09:49; `[CACHE]` 16:10:50).
- Git: `git show 86c8163` (tiền `4efc75a`) — code có `r.json()` không bọc catch + fallback "Lỗi không xác định"; `git show 4efc75a` — thêm nhánh 401/419 "Phiên đăng nhập đã hết hạn".
- `resources/views/dashboard/partials/link-generator.blade.php:195` (fallback hiện tại) + `getErrorMessage(data)` (lines 129-141).
- Fingerprint sử dụng SHA-256 rút gọn 10 hex; **không** in raw value.

## 19. Bằng chứng còn thiếu / chưa xác nhận (UNKNOWN — NEEDS VERIFICATION)

- **Ảnh chụp UI lúc sự cố**: message "Lỗi không xác định" chỉ từ mô tả người dùng, chưa xác minh trực tiếp.
- **Định dạng body 419** (JSON debug ~13 KB vs HTML ~13 KB): kích thước khớp cả hai; chỉ kịch bản JSON giải thích được chính xác message → cần ghi lại `response.text()` của 419 ở tab cũ (thử nghiệm sau khi có dự án riêng, cấm fake trên prod).
- **Thời điểm render chính xác của tab cũ** và **thời điểm chính xác token xoay 7035c67915→0d1d2ef645**: chỉ giới hạn được "trước 25/09 11:25"; mốc render ≤ 01/09/2026 suy từ code era.
- Hành vi session `database` khi session id trùng nhau qua 2 ngày (retention/log `session_id: ROTATED`) chưa dựng lại chi tiết.

## 20. Khuyến nghị sửa (đề xuất — chưa thực hiện)

1. **Frontend:** thay vì chỉ phủ code mới, thêm vào middleware/archive: server trả JSON `{csrf_token}` kèm 419; tab cũ đang chạy bất kỳ JS nào vẫn gửi được token mới nếu `fetch` bắt `r.json().message === 'CSRF token mismatch.'` → gọi `/csrf-token` → retry. (Fallback đơn giản nhất: `location.reload()` sau 419 nếu không phải code mới.)
2. **Ngừng fallback "Lỗi không xác định"** gây hiểu lầm (kể cả phiên bản hiện tại): luôn ghi log `X-Forensic-*`/`response.status` kèm `post_business_error` để phân biệt 401/419/500.
3. Cân nhắc giảm `SESSION_LIFETIME` (10080 phút = 7 ngày) và bắt buộc reload khi cookie session id đổi? (Không bắt buộc; chỉ giảm khả năng tab cũ).
4. Dashboard nên `notify` khi tab quay lại từ trạng thái nền quá lâu (ví dụ: probe `visibilitychange` luôn gọi `/csrf-token`) — T2 v2 đã làm; đảm bảo JS cũ cũng bị override bằng cách trả header `X-Require-Reload` khi render trang mới.
5. Khiếu nại UX: nếu retry 1 lần vẫn 419 ở tab cũ, đừng hiện "Lỗi không xác định" — hiện nút "Tải lại để tiếp tục".

## 21. Test (chưa chạy — only if permitted)

Suggestion (chạy ở environment riêng):
- Unit: `ValidateCsrfToken` mismatch → expectsJson → debug JSON có key `message`, không có `success`.
- Frontend: hành vi của thiết bị cũ (pre-fix JS) trước 419 JSON → "Lỗi không xác định".
- Khôi phục chuỗi fingerprint qua `CsrfForensic` khi rotate session.

## 22. Monitoring

- Forensic đã ghi đủ `csrf_failure`; thêm alert khi `csrf_failure` tăng đột biến / xuất hiện lần 2+ của cùng fingerprint trong ≤ 48h.
- Đề xuất ghi thêm `page_render_ts`/`tab_age_seconds` vào event để định lượng tuổi tab.

## 23. Đánh giá rủi ro

- **Trong sự cố:** uid17 không mất dữ liệu, không tạo link nhầm — request bị chặn sạch trước controller; sau reload thành công. Rủi ro dữ liệu thấp.
- **An toàn khác:** token poller extension (đang dùng trong URL query của Apache log, hoạt động liên tục mỗi ~2s từ 127.0.0.1) — **rủi ro lộ token trong log/URL**; không phải nguyên nhân sự cố, nhưng khuyến nghị chuyển sang header `Authorization` và xoay token.
- `APP_DEBUG=true` + `APP_ENV=local` trên domain public → 419 debug trả stack trace (đúng bằng chứng kích thước). Khuyến nghị `APP_DEBUG=false` trên production.

---

## Final — Item | Status

| Item | Status |
|---|---|
| Request thất bại xác định (POST /link-requests 26/09 16:08:09) | CONFIRMED |
| HTTP status = 419 (CSRF) | CONFIRMED |
| CSRF mismatch: request `7035c67915` ≠ session `0d1d2ef645`, session `03a5d9026e` | CONFIRMED |
| Người dùng vẫn đăng nhập, session chưa hết hạn | CONFIRMED |
| Session bị rotate tại đúng 16:08 | NOT SUPPORTED |
| Tab mở lâu, page/token render cũ, chưa reload (fingerprint trùng 2 ngày) | CONFIRMED |
| T2 recovery không kích hoạt (không có /csrf-token GET) | CONFIRMED |
| "Lỗi không xác định" = fallback code cũ khi body 419 là JSON debug | LIKELY (logic chặt; body format NEEDS VERIFICATION) |
| Shopee/provider/resolver gây lỗi | NOT SUPPORTED |
| Cloudflare-cache gây lỗi | NOT SUPPORTED |
| Root cause: stale CSRF token trong tab lâu, session vẫn hợp lệ (419), UI legacy hiểu nhầm thành "lỗi không xác định" | CONFIRMED (chuỗi); UI message LIKELY |

*Ghi chú: mọi thay đổi trên repo trong quá trình audit là KHÔNG — chỉ thêm file báo cáo `docs/BUG_AUDIT_2026-09-26_SHOPEE_UNKNOWN_ERROR.md`.*