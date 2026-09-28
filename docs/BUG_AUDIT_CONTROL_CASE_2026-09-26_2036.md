# Control Case — 20:36 Shopee Link Success

> Audit read-only. Không sửa code / DB / .env / session.
> HEAD: `d5e6518`. Thuộc tính: CHROME CriOS/154.0.8037.55 / iPhone OS 26_6_2 / uid=17 (Duy Tú).
> Mục đích: **control case đối chứng** với incident #1 (16:08) và #2 (16:36) 26/09/2026.

## 1. Incident / Control Scenario

Người dùng thực hiện vào ~17:08:

1. Tắt hoàn toàn Chrome.
2. Mở lại Chrome.
3. Đăng xuất hoantien.xyz.
4. Truy cập lại.
5. Đăng nhập lại (Google OAuth).
6. Sau đó không tạo link trong thời gian dài.
7. ~20:36 quay lại, nhập `https://vn.shp.ee/mSq3kZ5j`, bấm "Tạo Link Ngay".
8. **Link tạo THÀNH CÔNG** (HTTP 200) — ngược với POST 16:36:53 → 419.

## 2. Exact Timestamp

| Sự kiện | Timestamp (Apache/forensic) |
|---|---|
| POST /link-requests | **26/Sep/2026 20:36:24 +0700** (Apache, 200, 241B) |
| Forensic link_request_post | **2026-09-26T20:36:25.609789+07:00**, response_status=200 |
| DB row link_request | `created_at = 2026-09-26 20:36:24`, **id = 2730** |

## 3. Chrome Restart Evidence

Server evidence cho quãng **17:06:05 → 17:07:35** (khoảng trống ~90 giây giữa `GET /login` 17:06:05 và `GET /` 17:07:35):

- 17:05:53 — POST /logout ×3 (302) từ dashboard (các tab vẫn mở).
- 17:05:55, 17:06:05 — GET /login (cửa sổ Chrome sau logout hiển thị trang login).
- **17:07:35 — GET / HTTP/1.1, 200, 20224B, ref `-`** → đây chính là lần **mở lại Chrome + gõ địa chỉ** (`ref=-`, không referer, trang chủ).
- 17:08:10 → 17:08:12 — GET /auth/google → /auth/google/callback (302) → **đăng nhập Google lần 2**.

Kết luận: Chrome restarted giữa 17:07:3x–17:08:1x. **Dấu hiệu server xác nhận restart** = cửa sổ mới gõ URL (ref `-`) tại 17:07:35, không phải restore.

## 4. Logout/Login Evidence

| Thời điểm | Bằng chứng server |
|---|---|
| 17:04:53 | POST /logout ×3 → 302 (session `03a5d9026e` bị hủy sau đó) |
| 17:04:54 | GET / (guest landing 200) |
| 17:04:57–58 | GET /auth/google → callback (302) = **login #1** |
| 17:05:00 | GET /dashboard 200 (64188B) → session MỚI `23064894f8` |
| 17:05:53–54 | POST /logout ×3–4 → 302 (logout #2) |
| 17:08:10–12 | GET /auth/google → callback = **login #2 (Google)** |
| 17:08:14 | GET /dashboard 200 (64227B) → session MỚI `44eb6ce964` |

**recaller (remember_token) đổi**: trước logout recaller_fp=`b6d61e35a0` (session cũ `03a5d9026e`); sau login#2 recaller_fp=`033a5b7169` (session `44eb6ce964`). → Auth::login($user, true) team AC (remember) đã **tạo recaller & session mới**.

## 5. Session Lineage

| Trạng thái | Session FP | Server token FP | Auth | Ghi chú |
|---|---|---|---|---|
| Trước logout | `03a5d9026e` | `0d1d2ef645` | login_web_present | session của 2 incident 16:08/16:36 |
| Sau logout 17:04:53 | (đã hủy) | — | guest | session cũ bị xóa bởi POST /logout |
| Sau login#1 17:05:00 | `23064894f8` | `d9983e5f16` | login_web_present | session mới #1 |
| Sau logout#2 17:05:53 | (đã hủy) | — | guest | |
| **Sau login#2 17:08:14** | **`44eb6ce964`** | **`1143d3260f`** | login_web_present | **→ session duy nhất từ 17:08 tới 20:36** |
| **20:36:25 POST** | **`44eb6ce964`** | **`1143d3260f`** | login_web_present | **= cùng session/token 17:08** |

Xác nhận DB: row `sessions.id = "WrBnBRkYysh9PtQsEvWGjomlhMwFzi6H50rhLX7u"`, `user_id=17`, ip `116.111.185.231`, UA CriOS/154.
`fp(id) = sha256(id)[0:10] = 44eb6ce964` → **khớp chính xác forensic** (session FP 20:36).
`last_activity = 1790432168` = 26/09 21:16:08 +07 (= csrf-token probe cuối cùng 21:16:08).

## 6. CSRF Token Lineage

| Time | Event | Session FP | Token FP | Meaning |
|---|---|---|---|---|
| 16:08:10 | csrf_failure #1 | 03a5d9026e | server 0d1d2ef645 vs request 7035c67915 | token cũ/lạc hậu |
| 16:36:54 | csrf_failure #2 | 03a5d9026e | server 0d1d2ef645 vs request 7035c67915 | token cũ/lạc hậu |
| 17:04:53 | logout | session cũ hủy | token cũ hủy | |
| 17:05:00 | login#1 + dashboard | 23064894f8 | d9983e5f16 | token mới #1 |
| 17:08:14 | **login#2 + dashboard** | **44eb6ce964** | **1143d3260f** | **token mới #2** |
| 17:08:15 | GET /csrf-token (probe) | 44eb6ce964 | 1143d3260f | refresh probe (không đổi token) |
| 18:55:24 | GET /csrf-token (probe) | 44eb6ce964 | 1143d3260f | tab còn sống, probe resume |
| 20:36:20 | GET /csrf-token (probe) | 44eb6ce964 | 1143d3260f | probe sau khi render (không đổi token) |
| **20:36:25** | **POST /link-requests** | **44eb6ce964** | **request = 1143d3260f** | **matching hoàn hảo** |

So sánh parse gốc 20:36:25:
`request_token_fp=1143d3260f == page_token_fp=1143d3260f == session_token_fp=1143d3260f` → **token hợp lệ, không cần refresh**.

## 7. Document/Page Lineage

| Time | Event | Page |
|---|---|---|
| 17:08:14 | GET /dashboard 200 64227B | PAGE-b5fe6a383c7200b5 |
| 18:55:24 | GET /csrf-token (probe từ tab 17:08) | (tab vẫn mở, T2 probe) |
| **20:36:18** | **GET /dashboard 200 64253B, ref `-`** | **document MỚI server-render** |
| 20:36:19 | dashboard_render | **PAGE-d0eff1942120cec1** |
| 20:36:19 | asset app-YANisdxd.js / app-Bmk6Ric9.css (304) | đúng build T2 V2 HEAD d5e6518 |
| 20:36:25 | link_request_post | PAGE-d0eff1942120cec1 (CONFIRMED) |

→ 20:36 KHÔNG phải document 17:08. User **gõ địa chỉ tại 20:36:18** (ref `-`) → server render document mới.

## 8. T2 V2 Evidence

- POST 20:36 mang header `X-Forensic-Page-Id` + `X-Forensic-T2-Flow-Id` (code: `resources/views/dashboard/partials/link-generator.blade.php:153-154`).
- Forensic 20:36:25: `page_instance_id="PAGE-d0eff1942120cec1"`, `t2_flow_id="T2-muifnym6-jvs2qz"`, `response_status=200`.
- **Counter-check base36**: `T2-muifnym6...` → `base36("muifnym6")=1790429783550 ms` = **2026-09-26 20:36:23.550 +0700** → t2_flow_id được sinh tại đúng `submit()` (blade.php:50) ngay trước POST. → **CONFIRMED: document 20:36 chạy T2 V2.**

Trái ngược hoàn toàn với 16:36: `page_instance_id=""`, `t2_flow_id=""` (document legacy/pre-T2).

## 9. GET /dashboard Evidence

- **CÓ.** `20:36:18 GET /dashboard 200 64253B ref=-` từ CriOS/154 (trước POST 6 giây).
- Đây là render **server-side** = document mới trả token hiện tại `1143d3260f` trong `<meta name=csrf-token>`.
- Không có dashboard-render nào sau 17:08:14 và trước 20:36:18 (kiểm tra 19:00→20:36 = 0 request device).

## 10. GET /csrf-token Evidence

Phân loại theo mục 7 của directive:

- 17:08:15 — probe ngay sau dashboard load (B: T2 initialization/resume probe).
- 18:55:24 — probe resume khi tab được focus/visibility (C).
- **20:36:20 — probe 2 giây sau dashboard render 20:36:18** (B: init probe). Token trả về vẫn `1143d3260f` (không đổi).
- 20:44:55, 20:46:36, 21:16:08 — probe resume sau POST (C) — khớp `last_activity` của session.

Không có GET /csrf-token do **419**; POST 20:36 thành công ngay lần đầu → **T2 không cần recovery**.

## 11. POST /link-requests Evidence

```
20:36:24 POST /link-requests 200 241B ref=https://hoantien.xyz/dashboard (CriOS/154)
20:36:25 forensic link_request_post: rTok=pTok=sTok=1143d3260f, PAGE-d0eff1942120cec1, T2-muifnym6-jvs2qz, 200
20:36:25 laravel.log [Resolver] Attempt 1 {"result":"success","ms":813,"redirects":1}
20:36:25 laravel.log [Resolver] Short Link Resolved {"original":"https://vn.shp.ee/mSq3kZ5j","resolved":"https://shopee.vn/product/14061521/28368296525?d_id=8ae5b&uls_trackid=56nsm2lt00lu&utm_content=2XTyRsDPidip5D1s7JgZYENov9yh","total_ms":833}
20:36:25 [CACHE] {"item_id":28368296525,"status":"MISS","cache_date":"2026-09-26"}
20:36:25 [CACHE] ProductData URL {"url":"https://shopee.vn/product/14061521/28368296525","item_id":28368296525}
20:36:26 [CACHE-Timing] Refresh Cache {"item_id":28368296525,"elapsed_ms":918}
20:36:27 GET /api/link-request/2730 200 620B (poll UI refresh)
```

## 12. Shopee Resolver Evidence

- `https://vn.shp.ee/mSq3kZ5j` → `https://shopee.vn/product/14061521/28368296525` (d_id=8ae5b, uls_trackid=...), **resolved success** 833ms.
- **DB row id=2730** `link_requests`:
  - `item_id=28368296525`
  - `product_name = "Bộ 2 Tô Thủy Tinh Luminarc Diwali Trắng 18cm - LUDIN3975"` (khớp screenshot user)
  - `product_price=189000`
  - `estimated_cashback=14175.00`, `user_estimated_cashback=6378.00` (khớp "hoàn khoảng 6.378đ")
  - `cashback_rate=0.50`, `status=completed`, `created_at=2026-09-26 20:36:24`
- → Request thành công **thật sự**, dữ liệu sản phẩm & hoàn tiền đúng UI.

## 13. Frontend Flow

```
submit() (blade.php:47-58)
  → t2FlowId = T2-<base36(ts)>-<rand>           (20:36:23.550)
  → POST /link-requests (fetch, headers X-CSRF-TOKEN / X-Forensic-Page-Id / X-Forensic-T2-Flow-Id)
  → response 200 success
  → post_success → requestId=2730
  → startPolling() → GET /api/link-request/2730 (620B)  → UI render kết quả
Không preflight/probe bổ sung: token đã đúng từ đầu → KHÔNG nhánh 419/recovery.
```

## 14. Backend Flow

```
POST /link-requests (web.php:62, middleware 'forensic')
  → VerifyCsrfToken: PASS (token khớp session; không 419)
  → ForensicObserver (app/Http/Middleware/ForensicObserver.php:42-50):
      link_request_post: page_instance_id=PAGE-d0eff1942120cec1, t2_flow_id=T2-muifnym6-jvs2qz, response_status=200
  → DashboardController@store → AffiliateLinkService → ShortLinkResolver
      [Resolver] Attempt 1 success ms=813 redirects=1
      [Resolver] Short Link Resolved mSq3kZ5j → shopee product (total_ms=833)
  → Product data proxy: [CACHE] MISS 28368296525 → [CACHE-Timing] Refresh Cache 918ms
  → LinkRequest created id=2730 (completed, cashback 6378)
  → 241B JSON success
```

## 15. Comparison With 16:36 Failure

| Item | 16:36 FAIL | 20:36 SUCCESS |
|---|---|---|
| URL | 8D683XZE | mSq3kZ5j |
| HTTP | **419** | **200** |
| Session FP | 03a5d9026e | 44eb6ce964 |
| Session token FP | 0d1d2ef645 (server) | 1143d3260f |
| Page token FP | 0d1d2ef645 (server) | 1143d3260f |
| **Request token FP** | **7035c67915 (KHÔNG khớp)** | **1143d3260f (khớp)** |
| page_instance_id | **rỗng** | **PAGE-d0eff1942120cec1** |
| t2_flow_id | **rỗng** | **T2-muifnym6-jvs2qz** |
| GET /dashboard (gần nhất trước POST) | 17:08:14 → nhưng POST từ **document cũ 16:09:18**? → document LEGACY | **20:36:18 (server-render mới, +6s)** |
| GET /csrf-token (trước POST) | không (chỉ probe cũ 18:55) | **20:36:20 probe (không đổi token)** |
| T2 V2 marker | **KHÔNG** | **CÓ (page + t2 header)** |
| JS era | Legacy (không T2) | T2 V2 (app-YANisdxd.js) |
| Controller reached | KHÔNG (chặn ở VerifyCsrfToken → 419) | CÓ (link_request_post 200) |
| Resolver | không có | success (mSq3kZ5j → product) |
| UI | lỗi (không tạo được) | "Link Hoàn Tiền", 6378đ |

**Khác biệt cốt lõi**: POST 16:36 sinh ra từ **document legacy** mang token cũ `7035c67915` (không khớp server `0d1d2ef645`) và **không có T2 markers**. POST 20:36 sinh ra từ **document T2 V2 mới** (server-render 6 giây trước) mang token hiện tại → khớp → 200.

## 16. Control Case Classification

→ **A. T2 V2 có mặt và hoạt động** (page_instance_id + t2_flow_id present, POST 200).
Đồng thời **không cần recovery** (B): token đã đúng ngay lần đầu; GET /csrf-token 20:36:20 là init probe, không phải 419-recovery.

## 17. Conclusions

1. **Logout/login tạo session + CSRF token mới** (CONFIRMED): session `44eb6ce964`/token `1143d3260f` sau login#2 17:08, reused recaller mới `033a5b7169`; session cũ `03a5d9026e`/`0d1d2ef645` bị hủy ở logout.
2. **Token 20:36 = token mới sau login#2 tối đa (không phải token cũ)** — `1143d3260f` sinh lúc 17:08, ổn định tới 20:36, được quảng bá trong render 20:36:18.
3. **Document 20:36 là server-render mới (age ≈ 6s)**, không phải tab restore: GET /dashboard 20:36:18 ref `-` + dashboard_render `PAGE-d0eff1942120cec1`.
4. **T2 V2 thực sự hoạt động**: t2_flow_id `T2-muifnym6-jvs2qz` đúng quy cách JS; base36 = 20:36:23.550 = submit-time; token khớp 3 lớp → POST 200 lần đầu.
5. **Control case xác nhận giả thuyết từ BUG_AUDIT_DOCUMENT_LINEAGE**: nguyên nhân 16:36 là document legacy (JS cũ, không T2, token cũ `7035c67915`) chết trong Chrome sau khi token server rotate. Khi Chrome được khởi động sạch + login lại + document mới render → T2 V2 chạy đúng, thành công.
6. Không có hiện tượng "refresh cần thiết": token mới đã khớp ngay tại meta render.

## 18. Remaining Unknowns

- Lý do document legacy tự sinh ra (chưa đủ dữ kiện client-side; cần JS console để phân biệt tab duplicate vs bfcache) — UNKNOWN / NEEDS VERIFICATION.
- Chrome có tận dụng bfcache khi user "tắt/mở" hay không (không có evidence server để phân biệt; log khớp hành vi "tab cũ + token cũ" nhưng cơ chế chính xác chưa xác định được phiên bản cụ thể) — UNKNOWN.
- Session `WrBnBRkYysh9...` (44eb6ce964): các POST sau 20:36 không được khảo sát (ngoài probes), chưa kiểm tra các link tạo thêm sau 21:16.

---

| Question | Answer | Status | Evidence |
|---|---|---|---|
| POST 20:36 | POST /link-requests CriOS/154 | CONFIRMED | Apache `20:36:24 200 241B` |
| HTTP status | 200 | CONFIRMED | Apache + forensic 20:36:25 |
| Session FP | `44eb6ce964` | CONFIRMED | forensic; DB session row sha256=`44eb6ce964` |
| Session token | `1143d3260f` | CONFIRMED | forensic session_token_fp |
| Page token | `1143d3260f` | CONFIRMED | forensic page_token_fp (render token) |
| Request token | `1143d3260f` | CONFIRMED | forensic request_token_fp |
| Token match | rTok == pTok == sTok (khớp 100%) | CONFIRMED | forensic 20:36:25 |
| GET /dashboard after login | CÓ: 17:05:00, 17:08:14, 20:36:18 (server 200) | CONFIRMED | Apache |
| GET /csrf-token | CÓ (17:08:15, 18:55:24, 20:36:20 probes) | CONFIRMED | Apache; không phải recovery |
| page_instance_id | `PAGE-d0eff1942120cec1` | CONFIRMED | forensic 20:36:19 + 20:36:25 |
| t2_flow_id | `T2-muifnym6-jvs2qz` | CONFIRMED | forensic 20:36:25; base36=20:36:23.550 |
| T2 V2 present | CÓ | CONFIRMED | page+t2 header, blade.php:153-154 |
| T2 recovery executed | KHÔNG (không cần; POST 200 lần đầu) | CONFIRMED | không có 419; csrf-token 20:36:20 là init probe |
| Document age | render 20:36:18 → POST 20:36:24 = **6s** | CONFIRMED | Apache timestamps |
| Shopee resolver | SUCCESS (mSq3kZ5j → 28368296525), DB id=2730 cashback 6378 | CONFIRMED | laravel.log + DB |
| Root explanation | Document mới T2 V2 + token mới sau login khớp server → 200 | CONFIRMED | toàn bộ chuỗi evidence |