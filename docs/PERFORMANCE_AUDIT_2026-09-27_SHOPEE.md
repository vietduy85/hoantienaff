# PERFORMANCE AUDIT — SHOPEE LINK CREATION FLOW (END-TO-END) — 2026-09-27

Scope: **CHỈ flow tạo affiliate link SHOPEE**. Không audit/sửa ShopeeFood. AUDIT ONLY — không sửa production code/quy tắc cache/DB/queue/config, không commit. Mọi con số gắn nhãn `MEASURED` / `INFERRED` / `UNKNOWN`.

---

## 1. Executive Summary

Người dùng cảm nhận thao tác (dán link Shopee → bấm "Tạo link" trên `aff.hoantien.xyz`) mất **~4–5s**.

Đã chứng minh bằng real HTTP (chạy trên **2 môi trường thật**: hosting `aff.hoantien.xyz` + home `hoantien.xyz` qua Apache real HTTP và browser-like request có session thật):

| Thành phần | Nhãn | Giá trị |
|---|---|---|
| Hosting (LiteSpeed) pre-controller stack (bootstrap+session+CSRF) | `MEASURED` | **0.14–0.24s** (401/419 probes) |
| Hosting GET / (render cả blade 404) | `MEASURED` | **0.10–0.12s** TTFB |
| Home real authed POST (invalid URL, full middleware+validation) | `MEASURED` | **0.49–0.72s** |
| Home Shopee cold POST (controller-only, rollback) | `MEASURED` | total_ms **814–1157ms** |
| Home Shopee cold POST (real endpoint, full, authed) | `MEASURED` | **1.70–1.82s** HTTP |
| Home Shopee resolver cold (external, 1 redirect) | `MEASURED` | **728–1103ms** (redirects=1) |
| product-data afterResponse (data.addlivetag) | `MEASURED` | **614–836ms** (hôm nay prod logs: 663–782ms) |
| Hosting→s.shopee.vn resolver timing | **`UNKNOWN`** | KHÔNG thể chạy code trên hosting |

**Kết luận bước đầu**: hosting **không chậm** (bootstrap 0.14–0.24s, render blade 0.1s). Toàn bộ phần "server-side + DB + cache" đã đo được đều **nhỏ**. Khoảng trống duy nhất có khả năng giải thích 4–5s nằm ở **hosting→Shopee resolver** (giá trị hosting chưa đo được) và/hoặc **concurrency bị nghẽn bởi afterResponse product-data chạy đồng bộ trên worker** sau mỗi POST cold. Frontend Shopee **không** chờ thêm gì sau POST (đã đọc code, `affiliate_url` luôn có trong body response).

`UNKNOWN — NEEDS VERIFICATION`: hosting→Shopee latency, hosting .env/OPcache/max_execution_time, hosting DB timing, hosting PHP SAPI.

---

## 2. EXACT USER FLOW (đã trace source — `MEASURED` bằng read công khai)

1. Trang `/dashboard` → `link-generator.blade.php` (`x-data` Alpine), ô input auto-submit `setTimeout(300ms)` sau khi dán hợp lệ (`@input`, line 317-327).
2. `submit()` → `post()` → `fetch('POST /link-requests', JSON, X-CSRF-TOKEN, X-Forensic-*)` (line 47-58, 143-157).
3. Route `POST /link-requests` — middleware `['auth','verified','forensic']` (routes/web.php:60-62).
4. `DashboardController@store` (line 42-70):
   - validate `original_url` required/string/max:2048 → nếu không phải ShopeeFood → validate `url`.
   - `$strategy = Setting::get('affiliate.dashboard.strategy','direct')` → **hiện tại = `direct`** (`MEASURED`).
   - → `DashboardCreateDirectLinkController@store`.
5. `DashboardCreateDirectLinkController@store` (line 31-314):
   - `detectPlatform` → Shopee (chứa `shopee|shp.ee`).
   - `LinkRequest::create(['status' => 'processing'])` — **INSERT DB**.
   - `$this->urlResolver->resolve($url)` — **OUTBOUND HTTP cURL** (điểm extern duy nhất TRƯỚC response).
     - `expandShortUrl`: `CURLOPT_FOLLOWLOCATION=true, MAXREDIRS=10, TIMEOUT=6, CONNECTTIMEOUT=4`, `delays=[0,300000,500000]` (retry: usleep 0.3s/0.5s; 5xx retry-once; curl-errno retryable retry; 4xx non-retryable) — $attempt tối đa 3.
     - cache resolver `shopee_resolve:<md5 full-url>`, TTL 86400s → **query string khác nhau ⇒ cache key khác ⇒ MISS** (`INFERRED` từ `cacheKey()` dùng `md5($url)`).
   - `extractItemId($resolvedUrl)` → `cacheService->get($itemId)` — **SELECT affiliate_cache** (cache_date = hôm nay).
     - HIT → update LinkRequest 20 cột (1 UPDATE) + `buildAffiliateUrl()` + update status completed (UPDATE 2) → response.
     - **MISS** → `logMiss` + update item_id + `buildAffiliateUrl` + update affiliate_url → **dispatch(closure)->afterResponse()** → response (status vẫn `processing`).
   - Response JSON luôn có: `success, request_id, platform, affiliate_url, status`.
6. **afterResponse()** — `Illuminate\Bus\Dispatcher::dispatchAfterResponse` → `container->terminating` → **dispatchSync, chạy đồng bộ khi kernel terminate SAU response** (`MEASURED` bằng đọc source vendor; trước đó đã xác minh). Closure: `ProductDataService::getByUrl` (HTTP data.addlivetag, Http::retry(2,500), timeout 10, connect 5) → update LinkRequest → `cacheService->put`.
7. Frontend sau POST (link-generator line 191-205): `if (data.affiliate_url) { this.result = data; this.loading = false; }` → **UI "Link Hoàn Tiền" hiện NGAY khi POST trả**, KHÔNG chờ product-data/polling. `startPolling()` chạy nền: GET `/api/link-request/{id}` mỗi 300ms (<3s), 800ms (<8s), 2000ms (còn lại), gắn metadata khi `status==='completed'` (`MEASURED` đọc code).
8. CSRF recovery: nếu 419 → GET `/csrf-token` (1 lần, single-flight) → retry POST đúng 1 lần (line 168-182).

**Trả lời 10 câu P1**:
1. POST bắt đầu: `routes/web.php:62` → `DashboardController@store`.
2. Controller: `DashboardController::store` → (direct strategy) `DashboardCreateDirectLinkController::store`.
3. Service: Shopee direct path **KHÔNG** đi qua `AffiliateLinkService` (chỉ extension/admin dùng: `DashboardCreateExtensionLinkController:181`, `Admin\AffiliateShortLinkController:43`, `ShopeeSearchJobService:55`). Path trong controller inline: `UrlResolverService` + `AffiliateCacheService` + `ProductDataService` (trong closure).
4. Strategy: `direct` (Setting DB = `direct`).
5. Resolver: `UrlResolverService::resolve` (cURL).
6. Outbound HTTP TRƯỚC response: **đúng 1**: s.shopee.vn chuỗi redirect (1-2 hop). SAU response: product-data (data.addlivetag, 0.6–0.8s).
7. Request trước response: resolver. 8. Sau response: product-data. 9. Frontend không chờ request khác sau POST (chỉ polling nền). 10. UI hiện "Link hoàn tiền" sau **chính response POST** (affiliate_url có sẵn).

---

## 3. Browser / Network Timeline

Không thể chạy DevTools trong môi trường này → **`UNKNOWN`** về phần network người dùng. Code frontend đã định lượng (`MEASURED`): POST → (không chờ gì) → `loading=false` ngay khi `affiliate_url` có. Do đó **user-perceived ≈ thời gian POST + 300ms auto-submit delay + network**.

## 4. Real POST /link-requests timing (real HTTP, authed session thật user 6)

Session DB thật mints (temp command, đã xóa). Đo:

| Case | Env | Nhãn | Giá trị |
|---|---|---|---|
| POST invalid URL → 422 (full auth+verified+forensic+CSRF+session+validation) | home HTTP | `MEASURED` | 0.487/0.553/0.557/0.648/0.717s (med **0.557**) |
| POST invalid URL → 422 | home **qua tunnel** | `MEASURED` | 1.002, 1.003s |
| POST valid Shopee HIT (warm cache) → 200 completed | home HTTP | `MEASURED` | **0.643s** |
| POST valid Shopee HIT → 200 | home **qua tunnel** | `MEASURED` | **1.091s** |
| POST valid Shopee COLD (resolver cold, MISS) → 200 completed | home HTTP | `MEASURED` | **1.698, 1.820s** |
| Unauthed POST no token → 419 (hosting base) | aff hosting | `MEASURED` | 0.167–0.236s |
| Authed POST thật on AFF | aff hosting | **`UNKNOWN`** | không có session hosting |

Ghi chú: trên mod_php (XAMPP), body cold POST về máy client mang `status:completed` ⇒ afterResponse product-data (0.6–0.8s) chạy **xong trước khi body được flush đủ** — POST home cold ≈ 1.7–1.8s là tổng (resolver + build + afterResponse + bootstrap). Hosting LiteSpeed thường stream sớm hơn ⇒ `INFERRED` client thấy sớm hơn trên aff.

## 5. Laravel timing (hosting vs home) — real HTTP

| Probe | aff hosting | home http |
|---|---|---|
| GET / (404 blade) TTFB | 0.098–0.121s | (home ~0.4s đo trước) |
| GET /csrf-token unauth → 401 | 1st **0.570** (lazy), sau **0.141–0.206** | — |
| POST /link-requests no-token → 419 | **0.167–0.236** | — |
| Authed POST 422 (toàn bộ stack) | `UNKNOWN` (needs session) | **0.487–0.717** |

Hosting xử lý PHP/Laravel **nhanh gấp ~2–3× home**; bootstrap framework không phải bottleneck.

## 6. Shopee resolver timing

Resolver cache: table `cache` (prefix `hoan-tien-mua-sam-cache-`), key `shopee_resolve:md5(url)`, TTL 86400.

| Runtime | Nhãn | min/med/max |
|---|---|---|
| Resolver cold (cURL chain, attempt success, redirects=1) | `MEASURED` (home) | **685/856/1103ms** (app log) |
| Resolver warm (cache HIT) | `MEASURED` (home) | **7–47ms** |
| Raw cURL -L s.shopee.vn/1Agt7xNF0 (200, redirs=1) | `MEASURED` (home) | 580–664ms tot |
| Hop 1 (301 SGW → opaanlp, kèm credential_token) | `MEASURED` | dns 49 + tcp 95 + tls 160 + ttfb 232 ≈ **232ms** |
| Resolver trên HOSTING | **`UNKNOWN`** | — |

Cấu hình resolver: `TIMEOUT=6`, `CONNECT_TIMEOUT=4`, `MAXREDIRS=10`, retry delays `[0,300000,500000]` µs, retry-once cho 5xx, non-retryable 4xx `[400,401,403,404,405,410,414,451]`, retryable errnos: RESOLVE/CONNECT/PARTIAL/TIMEDOUT/GOT_NOTHING/SEND/RECV, `CURLOPT_FOLLOWLOCATION=true`. Không có `allow_redirects=guzzle`; dùng cURL. **Không thấy retry/thất bại trong mọi lần đo (luôn attempt 1 success).**

## 7. Affiliate cache

- Backend: **DB `affiliate_cache`** (table `affiliate_cache`, không phải `affiliate_caches`), match `item_id + cache_date=hôm nay (Asia/Ho_Chi_Minh)`.
- Query: `where item_id … whereDate` — `MEASURED` 2–76ms (home). DB MariaDB home: INSERT ~1–2ms baseline.
- Key: `item_id + cache_date`; placeholder rỗng (product_name/price/cashback/shop_id đều null) bị coi là MISS.
- **Query string khác nhau ⇒ resolver MISS** (md5 toàn URL) nhưng affiliate cache **vẫn HIT** nếu cùng item trong ngày.
- Call 1 lần trong POST. HIT: 1 SELECT + 2 UPDATE; MISS: logMiss + 2 UPDATE + (sau response) product-data → put (UPDATE/INSERT).
- Đã verify không "cache lookup bị gọi nhiều lần" trong POST.

## 8. Product-data (sau response)

- `MEASURED` temp route `/__perf-shopee/after` (chạy đúng `ProductDataService::getByUrl`): **836/683/614ms**.
- Prod logs hôm nay (real user flows) `[CACHE-Timing] Refresh Cache`: **663/734/736/764/782ms**.
- `Http::retry(2, 500)` + timeout 10 + connect 5 (data.addlivetag.com).
- Vị trí thực tế: **SAU** response (Bus `afterResponse` → sync tại terminate). Trên home mod_php: chạy xong trước khi client nhận trọn body (bằng chứng: body cold POST = `status:completed`). Frontend **không chờ** nó (đã nói ở §2).

## 9. DB timing (Shopee flow) — home `MEASURED`

| Phase | ms |
|---|---|
| DB tổng luồng flow (sum query time) | cold 7–52; warm 11–117; HIT 28–159 |
| LinkRequest INSERT | 41–103 (gồm Eloquent) |
| Resolver cache get + affiliate cache SELECT | 2–34 (cold) / 21–76 (HIT) |
| Session row (authed POST) | gộp trong 0.487–0.717s trọn gói |
| Hosting DB | **`UNKNOWN`** |

DB không thể đóng góp > ~0.1s cho 4–5 giây.

## 10 & 11. Frontend polling timing + spinner

- Spinner: `loading` set false khi `data.affiliate_url` tồn tại (Shopee luôn có) → **không có "await somethingElse"**. `INFERRED` (đọc toàn bộ blade).
- Poll: 300/800/2000ms; request ~10ms; kết quả `Object.assign` khi completed. Không ảnh hưởng spinner.
- 419 recovery path: +1 GET /csrf-token (hosting ~0.15s) + retry POST (`INFERRED` nếu có).
- Auto-generate `setTimeout(300ms)` trước POST.

## 12. Hosting environment

**`UNKNOWN — NEEDS VERIFICATION`** (không SSH/cPanel). Chỉ biết: `Server: LiteSpeed`, `Keep-Alive: timeout=5 max=100`, `alt-svc h3`, DNS-only A `103.124.95.230`, TLS cert bị `SEC_E_UNTRUSTED_ROOT` (client local). Không biết: PHP version/SAPI, OPcache, realpath, memory_limit, max_execution_time, curl ext, DNS resolver. Đo gián tiếp cho thấy PHP fast (blade 0.1s), nhưng **không suy ra được cấu hình**.

## 13. HTTP headers (aff, `MEASURED`)

`HTTP/1.1` (với `--resolve`); `Server: LiteSpeed`; `Connection: Keep-Alive`; `Keep-Alive: timeout=5, max=100`; `Cache-Control: private, no-cache, no-store, must-revalidate`; `Pragma: no-cache`; `Content-Type: text/html`; `alt-svc: h3=":443"`. Không `Content-Encoding` trên 404 (đã vô hiệu brotli). Không timing headers.

## 14. So sánh 3 case (n=5 mỗi case, home real HTTP, controller work — rollback, không sinh row)

| Metric | CASE A (resolver+aff HIT) | CASE B (resolver HIT, aff MISS) | CASE C (brand-new, cả 2 MISS) |
|---|---|---|---|
| HTTP total min/med/max (s) | 0.54/0.66/0.83 | 0.56/0.60/0.89 | **1.306/1.424/1.633** |
| Controller total_ms min/med/max | 113/141/235 | 94/108/271 | **814/923/1157** |
| Resolver ms | 7–47 | 10–19 | **728–1103** |
| Cache lookup ms | 21–76 | 2–29 | 2–34 |
| Create ms | 46–103 | 45–62 | 41–53 |
| build+update ms | 6–73 | 25–93 | 5–21 |
| db_total ms | 28–159 | 11–117 | 7–52 |

Cold vs warm: resolver chênh ~**700–1100ms**, là biến số lớn nhất.

## 15. Phân rã 4–5 giây (CRITICAL PATH — computed cho aff, authed, Shopee cold)

| Stage | ms | Nguồn |
|---|---|---|
| Browser → hosting (TCP+TLS+req) | ~30–80 | `INFERRED` (chưa đo ISP user) |
| Hosting pre-controller stack (auth+session+CSRF+validation) | 150–250 | `MEASURED` (419/401 probes) |
| LinkRequest INSERT | 40–70 | `MEASURED` home |
| **Shopee resolver external (hosting)** | **?** | **`UNKNOWN`** — home cold = 700–1100 |
| resolver + affiliate cache SELECT | 2–76 | `MEASURED` |
| build an_redir + UPDATEs + JSON | 30–120 | `MEASURED` |
| TTL POST (server, trước afterResponse) | **≈ 0.4s + resolver_hosting** | computed |
| Frontend auto-submit delay | 0–300 | `MEASURED` (code) |
| Frontend post-POST wait (Shopee) | 0 | `MEASURED` (code) |
| Product-data afterResponse | 600–800 (không chặn UX trên aff nếu host stream; chặn worker) | `MEASURED` home |

Nếu `resolver_hosting ≈ 1s` ⇒ POST ≈ **1.3–1.5s** (không phải 4–5s). Muốn đạt 4–5s cần resolver_hosting ≈ **3.5–4.5s** (timeout/retry/chậm từ IP hosting) HOẶC nghẽn worker do các POST cold trước đó đang chạy product-data đồng bộ.

**"4–5 giây nằm ở đâu?"** → Tất cả phần đã đo được đều nhỏ; phần **CHƯA đo được duy nhất có cường độ đủ lớn là resolver từ hosting→s.shopee.vn**. Kết luận trung thực: **`UNKNOWN` cho đến khi đo được trên hosting** (hoặc có 1 DevTools từ người dùng).

## 16. Phân loại bottleneck

1. **SERVER BOTTLENECK**: KHÔNG — `MEASURED` hosting nhanh (0.14–0.24s stack, 0.1s blade). `INFERRED` không phải nguyên nhân.
2. **EXTERNAL SHOPEE BOTTLENECK**: **ỨNG VIÊN CHÍNH** — resolver cold 700–1100ms (home), retry/timeout có thể khuếch đại lên giây trên hosting; giá trị hosting `UNKNOWN`.
3. **FRONTEND / UX WAIT**: KHÔNG cho Shopee (`MEASURED` code), chỉ 0–300ms auto-submit.
4. `INFERRED` phụ trợ: afterResponse product-data (0.6–0.8s) chạy đồng bộ làm nghẽn worker PHP/LiteSpeed, gây độ trễ cho các POST kế tiếp khi traffic.

## 17. Proofs / UNKNOWN / Next

**Proven (MEASURED)**: hosting base stack nhanh; home cold POST thật 1.70–1.82s gồm resolver lạnh + afterResponse; resolver lạnh = 700–1100ms (redirects=1); cache affiliate HIT rẻ; DB home rẻ; frontend Shopee không chờ; product-data 614–836ms sau response.

**UNKNOWN — NEEDS VERIFICATION**:
- `resolver_hosting` → s.shopee.vn / addlivetag (CẦN chạy trên hosting).
- Hosting .env (APP_DEBUG/CACHE_STORE/OPcache), PHP SAPI, max_execution_time, hosting DB.
- Network thật của người dùng → aff.

**Cách đóng khoảng trống (đề xuất, không tự ý làm)**:
1. *Người dùng*: Chrome DevTools → F12 → Network (Preserve log) → dán link Shopee → Tạo link; ghi **`POST /link-requests` "Waiting (server response)"** và "Timing". Số này phân biệt server vs network ngay lập tức.
2. *Chạy trên hosting*: đặt 3 file temp (appendix) lên deployment hosting (FTP, không qua git) → POST `https://aff.hoantien.xyz/__perf-shopee/flow?url=<shopee-link>` → đọc JSON `t6_resolver_end_ms` = resolver_hosting. Sau test XÓA 3 file.

## 18. AUDIT RESULT

```
POST backend            = home cold thật 1698–1820ms (gồm resolver + afterResponse trên mod_php);
                          hosting: base stack 150–250ms MEASURED; resolver UNKNOWN
Shopee external         = resolver cold 685–1103ms; redirects=1; product-data 614–836ms (sau response)
frontend wait           = ~0s (Shopee) + 0–300ms auto-generate
user perceived (aff)    = UNKNOWN (chưa đo được theo cách kết luận; home tương đương 1.7–1.8s cold)
unexplained (tới 4–5s)  = cư trú ở hosting→Shopee resolver leg (hoặc retry/timeout/concurrency) — UNVERIFIED
```

Nếu tổng chưa khớp 4–5s: phần đo được chỉ giải thích ~1.5s; còn ~3s **chưa đo được** — cần bước 1/2 ở §17 để định lượng trước khi kết luận nguyên nhân.