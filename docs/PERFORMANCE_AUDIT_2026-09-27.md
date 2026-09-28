# PERFORMANCE AUDIT FORENSIC — SO SÁNH HOANTIEN.XYZ vs AFF.HOANTIEN.XYZ
Ngày: 2026-09-27 (~12:00 UTC+7)
Bản chất: AUDIT ONLY — không sửa code production, không thêm index, không đổi cache/queue/config/Cloudflare/hosting.
Phương pháp: đo (không đoán). Instrumentation tạm thời đã tạo → đo → **cleanup** (git status sạch, xác nhận cuối tài liệu).
Vị trí đo: máy home (chính là origin của hoantien.xyz) qua curl; backend-pipeline chạy bằng Laravel CLI trên home (cùng source code hosting chạy).

---

## 1. Executive Summary

**Tạo affiliate link trên aff.hoantien.xyz vẫn mất 3–4 giây KHÔNG phải vì hosting/Laravel/DB/cache của hosting chậm.** Hosting (LiteSpeed) xử lý một request ~**75–80 ms** (TTFB 155–170 ms, trừ network ~80–100 ms). DB ~**0.2–5 ms/query**, cache backend là database nhưng chỉ 5–30 ms/request.

3–4 giây đó nằm ở **dãy HTTP ra ngoài của flow tạo link, chạy TUẦN TỰ TRƯỚC KHI POST trả về** (đo được, từ chính code hosting đang chạy):

| Leg external (đo trên cùng code, máy home) | duration đo được |
|---|---:|
| Shopee resolver lạnh (`resolve` s.shopee.vn → shopee.vn) | **~930 ms** |
| Shopee resolver ấm (cache hit) | ~3 ms |
| Product-data API (sau response, polling) | ~870 ms |
| ShopeeFood pipeline short-link `/u/{code}`: 301 + 200 (2 hop) | **~1.05–1.1 s** |
| ShopeeFood OpenAI-graph fetch (`shopeefood.vn` shop page) | **~0.5–0.74 s** |
| ShopeeFood store API (`data.addlivetag.com/.../store.php`) | **~0.58–0.72 s / lần**, khi rỗng bị gọi **2 lần** |

⇒ ShopeeFood short-link: pipeline 1.1 s + OG 0.5–0.7 s + store 0.6–0.7 s (×2) ≈ **2.6–2.9 s backend**, cộng network/Laravel ≈ **3–4 s** người dùng thấy được. Shopee product: POST ~0.95 s lạnh (resolver) cộng background ~0.9 s; hiếm khi tới 3–4 s trừ khi chạm timeout 6 s của resolver.

---

## 2. Network architecture (đo thực tế, P1)

| | hoantien.xyz | aff.hoantien.xyz |
|---|---|---|
| A record public (query 1.1.1.1 / 8.8.8.8) | `104.21.82.184`, `172.67.161.115` (**Cloudflare anycast**) | `103.124.95.230` (**hosting IP, không phải Cloudflare IP**) |
| Proxy? | **CÓ** — IP public thuộc dải Cloudflare | **KHÔNG** — DNS-only, A trỏ thẳng hosting |
| Tunnel | **CÓ** — cloudflared (2 process: pid 2084, 2924) trên máy home, nhận vào Apache (:80/:443) | **KHÔNG** |
| Origin khi proxy | máy home (Apache/XAMPP) | 103.124.95.230 (web hosting) |
| hosts file trên máy home | override `hoantien.xyz → 127.0.0.1` (xem caveat bên dưới) | không override |
| Server hiện lộ ra | `Server: cloudflare` → sau CF là Apache/2.4.58 (Win64) PHP/8.2.12 | `Server: LiteSpeed`, `alt-svc: h3=":443"` |

**Luồng request thực tế:**
- `https://hoantien.xyz`: Browser → DNS(CF) → **Cloudflare proxy** (104.21.82.184/172.67…) → Cloudflare Tunnel → máy nhà → Apache/XAMPP → Laravel.
- `https://aff.hoantien.xyz`: Browser → DNS → **thẳng hosting 103.124.95.230** → LiteSpeed → Laravel. Cloudflare chỉ là DNS resolver khi traffic DNS-only.

Caveat: máy home override hosts nên ko thể đo DNS thật của hoantien.xyz từ đây; DNS công khai = Cloudflare. aff public DNS lookup từ máy home ≈ 10–35 ms.

## 3. DNS architecture (P1)
- `aff.hoantien.xyz`: A → 103.124.95.230, DNS-only. Không có CNAME/worker/tunnel. TLS certificate **KHÔNG xác thực được bằng curl/schannel** trên máy home (`SEC_E_UNTRUSTED_ROOT`), nhưng HTTPS vẫn hoạt động — ghi nhận để kiểm tra chain certificate, không phải nguyên nhân latency.
- `hoantien.xyz`: A/AAAA → Cloudflare (proxied), SOA ns `chris.ns.cloudflare.com`.

## 4. Hosting measurements (P2/P3/P16) — TTFB & total (ms)

`curl -w` từ máy home, nhiều lần, `--max-time 25`. n = số mẫu.

| Nhóm | n | min | median | avg | p95 | max |
|---|---:|---:|---:|---:|---:|---:|
| ­AFF-ROOT (GET /) | 9 | 146 | **170** | 167 | 187 | 187 |
| AFF-CSRF302 (GET /csrf-token, 302) | 5 | 149 | **171** | 168 | 183 | 183 |
| AFF-LOGIN (GET /login) | 5 | 153 | **155** | 236* | 521* | 521* |
| HOME-TUN-ROOT (GET / qua CF tunnel) | 5 | 696 | **726** | 737 | 782 | 782 |
| HOME-TUN-CSRF302 | 5 | 652 | **708** | 706 | 758 | 758 |
| HOME-TUN-LOGIN | 5 | 676 | **724** | 739 | 817 | 817 |
| HOME-LOCAL-CSRF302 (không tunnel, thẳng Apache) | 5 | 321 | **401** | 411 | 531 | 531 |
| HOME-LOCAL-LOGIN | 5 | 339 | **420** | 398 | 451 | 451 |

\* mẫu kế tiếp theo (thường ~155ms; 521ms do cold).
Kết luận tách lớp:
- **Network + TLS tới hosting** ≈ 80–100 ms (TCP ~45 ms + TLS ~40–55 ms).
- **Application processing hosting (LiteSpeed+Laravel+session)** ≈ 155–170 − 90 ≈ **60–80 ms**.
- **Home processing** (localhost, không TLS/tunnel) ≈ **~400 ms** ⇒ hosting nhanh hơn home tầm **5×** mỗi request.
- Cloudflare tunnel + proxy hiện cộng **~300 ms** cho hoantien.xyz (700–740 vs 400), không phải 1 s như ghi nhận cũ.

## 5. Home/XAMPP measurements
- Apache/2.4.58 (Win64) PHP/8.2.12 (ZTS). XAMPP php.ini: `;zend_extension=opcache` bị **comment** ⇒ **OPcache TẮT** trên home (đóng góp lớn cho ~400 ms bootstrap; không có realpath/config/route cache: `bootstrap/cache` chỉ có packages.php/services.php).
- Laravel 12.62.0, `APP_ENV=local`, `APP_DEBUG=true`, timezone Asia/Ho_Chi_Minh.
- Session cookie `hoantien_session_v2` — **giống hệt hosting** (verified qua Set-Cookie cả 2 phía).
- Compress: home (qua CF) trả `Content-Encoding: br`; hosting cũng `br` ⇒ không chênh lệch.
- HTTP version thực tế: curl build hiện chỉ hỗ trợ HTTP/1.1 đã negotiated; hosting quảng cáo HTTP/3 (alt-svc h3), home sau Cloudflare cũng h2/h3. Không xác minh h2 được bằng build curl này (limitation).

## 6. Laravel timing (P5/P12/P14)
Đo được: hosting bootstrap+session+view ≈ **60–80 ms**; home ≈ **400 ms**. Hosting không phải bottleneck.
Không thể đo trực tiếp hosting APP_DEBUG / config cache (no SSH). Khuyến nghị một dòng kiểm tra thủ công (xem §17).

## 7. External HTTP timing (P6) — TỪNG request, đo bằng Laravel Http event hooks (hostname/path/status/ms; sanitized URL, không in secret)

Đo backend bằng command tạm thời (cleanup xong) chạy đúng service code paths trên home:

| # | External call | status | ms đo được |
|---|---|---|---|
| 1 | Shopee resolver lạnh (cURL s.shopee.vn follow) | 200 | **939** |
| 2 | Shopee resolver ấm (cache hit) | — | **3** |
| 3 | `data.addlivetag.com /product-data/product-data.php?item_id=` | 200 | **868** |
| 4 | `shopeefood.vn /u/TvVxfoK` (301) | 301 | **512–624** |
| 5 | `shopeefood.vn/now-food/shop/11497?...` (sau hop) | 200 | **485–519** |
| 6 | `shopeefood.vn/now-food/shop/11497` (OpenGraph target) | 200 | **519–739** |
| 7 | `data.addlivetag.com/shopeefood/store.php?restaurant_id=` | 200 | **558–719** |

So sánh raw curl (cùng máy): shopeefood.vn ~260–345 ms, store.php ~307–369 ms, product-data ~287–317 ms. PHP app (Guzzle+cURL TLS/header riêng) chậm hơn ~2× so với curl raw — do new TLS handshake + body parse.

Điểm quan trọng:
- **Store API trả `{"status":"ok","count":0,"data":[]}`** cho restaurant 11497 ⇒ tên null ⇒ **không bao giờ lưu cache** ⇒ mỗi lần tạo link lại gọi external (~0.6–0.7 s).
- ShopeeFoodPreviewService (L117) gọi `storeName` lần 1 cho restaurant_id từ resolved, KO THÀNH (null) lại gọi **lần 2** (L129–131) ⇒ khi API rỗng, store bị gọi 2 lần liên tiếp.
- OG fetch target `/now-food/shop/…` không có og:title/og:image cho UA iPhone ⇒ null ⇒ **không cache** ⇒ gọi lại mỗi lần.
- ShopeeFood `/u/{code}` pipeline KHÔNG cache kết quả resolve (chỉ spf path có cache) ⇒ mỗi lần 2 hop.

Raw curl cross-check external endpoints (máy home → internet, 3 mẫu mỗi loại): s.shopee.vn 301 ≈ 266–289 ms; shopeefood.vn/u 259–373 ms; shopeefood.vn/shop 259–268 ms; store.php 307–369 ms; product-data 287–317 ms.

## 8. Shopee timing (P7 phần liên quan)
Shopee product flow (DashboardCreateDirectLinkController@store):
- POST sync: INSERT + resolver (cold ~939 ms / warm ~3 ms) + affiliate_cache get (1 SELECT) + 2 UPDATE ≈ cold **~945 ms**, warm **~30 ms**.
- Sau response (`dispatch(closure)->afterResponse()`): product-data 868 ms → UPDATE status=completed + put affiliate_cache. Người dùng thấy link ngay (affiliate_url trả trong POST); metadata điền sau ~0.9 s qua polling.
- Resolver có TIMEOUT 6 s / CONNECT 4 s, max 3 attempt (retry 5xx 1 lần, usleep 300/500 ms) ⇒ nếu s.shopee.vn chậm/anti-bot có thể tăng vọt tới ~6–12 s. Chưa quan sát được trường hợp này trong cửa sổ đo (mọi sample trả nhanh).

## 9. ShopeeFood timing (timing trong flow tạo link)
Đo full backend controller-equivalent (rollback, không ghi DB — verified):

| Case | create | pipeline | preview (OG+store) | build+update | TỔNG backend |
|---|---:|---:|---:|---:|---:|
| SF **direct** `/now-food/shop/11497` | 6 ms | 2 ms | **1 775 ms** (OG 519 + store 591 + store 558) | 23 ms | **~1.8 s** |
| SF **short** `/u/TvVxfoK` | 10 ms | **1 095 ms** (2 hop) | **645 ms** (store 580; OG cache-hit) | 50 ms | **~1.8–2.9 s** ⇒ **2.6–2.9 s** khi OG/store chưa warm |

Kết luận ShopeeFood: **POST trả về sau ~1.8–2.9 s** vì toàn bộ external chạy đồng bộ trước response — đây là nguồn "3–4 giây" chính.

## 10. Database (P7/P10)
- Server home: MariaDB **10.4.32-MariaDB**, host 127.0.0.1. `select 1` x10 = **avg 0.16–0.28 ms**.
- Index (verified qua `show index` / `explain`):
  - `link_requests`: PRIMARY(id), idx(user_id), idx(status), idx(platform), idx(user_id,status), idx(user_id,is_pinned), idx(item_id), idx(shop_id). Query nhà: dash `WHERE user_id ORDER BY id LIMIT 5` → ref key user_id idx, rows 176 (đủ tốt cho bài toán này).
  - `affiliate_cache`: PK(item_id, cache_date). `item_id=? AND date(cache_date)=?` → ref PRIMARY. ✓
  - `cache`: PK(key), idx(expiration). ✓ `sessions`: PK(id), idx(user_id), idx(last_activity). ✓
- Trong flow tạo link (đo): INSERT ~0.9–2 ms; UPDATE ~0.4–1.1 ms; cache SELECT (database store) **1.4–39 ms** (đôi khi 18–39 ms trên home). Tổng DB/request ≈ 5–30 ms — không phải bottleneck.
- EXPLAIN: các hot query đều dùng index (const/ref). Không cần index mới.

## 11. Cache (P8)
- Home: `CACHE_STORE=database` ⇒ cache đọc vào DB (`select * from cache`), mỗi lần 1.4–39 ms. Hosting: không đọc được .env (cần verify thủ công). SESSION_DRIVER=database cả 2 phía (verify cookie name giống nhau).
- Cache HIT vs MISS đo được:
  - Resolver: HIT ~3 ms, MISS ~939 ms (external). Sau miss có 1 upsert cache (~2.6 ms).
  - ShopeeFood OG/store: cache KHÔNG hoạt động cho restaurant rỗng (null không được put) ⇒ thực tế luôn MISS ⇒ luôn external (~0.5–0.7 s) ⇒ **cache là điểm phụ thuộc bị bỏ ngỏ**, không phải "cache chậm".
- Case quan trọng: cache hit VẪN gọi external? → Resolver/OG/store đọc cache trước rồi mới gọi external ⇒ đúng thứ tự. Nhưng store/OG null ⇒ không cache được ⇒ hiệu quả zero. (Chính là một optimization candidate §16.)

## 12. Queue (P15)
- `QUEUE_CONNECTION=database` (home). Bảng `jobs` có 2 dòng tồn đọng (không có worker chạy nền trên home).
- QUAN TRỌNG: `dispatch($closure)->afterResponse()` KHÔNG vào queue. Xác minh source: `Illuminate\Bus\Dispatcher::dispatchAfterResponse()` → `$this->container->terminating(fn => $this->dispatchSync(...))`. Closure không phải ShouldQueue ⇒ chạy **đồng bộ ngay khi app terminating — SAU KHI response đã flush**.
  ⇒ Product-data (0.87 s) không tăng TTFB của POST, nhưng chiếm PHP worker tới khi xong. Nếu nhiều request đồng thời, worker bị nghẽn (contention). Khuyến nghị: đưa sang real queue worker (không làm trong audit này).

## 13. Frontend / polling (P9/P11)
`resources/views/dashboard/partials/link-generator.blade.php`:
- POST `link-requests.store` (fetch, X-CSRF, JSON). Xử lý 419: GET /csrf-token rồi retry 1 lần.
- Sau POST success với `affiliate_url` → `result` set + `loading=false` (spinner ẩn) + `startPolling()`.
- Poll delays: elapsed **<3 s → 300 ms**, <8 s → 800 ms, sau đó 2000 ms; nếu response lỗi → backoff ×2 up to 5000 ms. Poll = GET `/api/link-request/{id}` (auth, 1 SELECT, ~5–15 ms).
- Người dùng "thấy":
  - Shopee product (có affiliate_url ngay): spinner ẩn sau **~1 s** (POST lạnh); link hiện, metadata đầy đủ sau **~1.2–2 s** (poll bắt được completed).
  - ShopeeFood thành công: spinner ẩn sau **POST (~1.8–2.9 s)**, + 1 poll ≤300 ms.
  - ShopeeFood fail (affiliate_url null): loading giữ tới khi poll thấy `failed` ⇒ POST + ≤300 ms ≈ **~3.1 s**.
- Polling KHÔNG tạo thêm delay đáng kể (poll ~10 ms, interval tối thiểu 300 ms) — nó chỉ chờ background job.

## 14. Hosting environment (P12/P13/P14) — what actually verifiable
- Web server: **LiteSpeed** (header `Server: LiteSpeed`), HTTP/1.1 keep-alive (timeout 5,max 100), `alt-svc h3` ⇒ hỗ trợ h2/h3 (client dependent). Compression: **Brotli**. Cookie: `hoantien_session_v2` + XSRF-TOKEN — config laravel (#session) trùng phía home.
- Không SSH hosting ⇒ KHÔNG đo được php -v, OPcache hit rate, memory_limit, max_execution_time, realpath cache, MySQL version, CACHE_STORE, APP_DEBUG, route/config cache của hosting. Ghi rõ ngay §17 như NEEDS-VERIFICATION.
- Khác biệt đã chứng minh giữa 2 phía: web server (LiteSpeed vs Apache), OPcache (không verify hosting; home tắt), bootstap/định vị xử lý (~75 ms vs ~400 ms). Tất cả **ủng hộ hosting nhanh hơn** ⇒ không giải thích 3–4 s.

## 15. Side-by-side (P10) — table yêu cầu

| Stage | hoantien.xyz | aff.hoantien.xyz | Difference |
|---|---:|---:|---:|
| DNS | hosts override (public ≈ 5–10 ms, CF) | lookup 10–35 ms | similar |
| TCP | ~70–85 ms (tới CF edge) | ~36–63 ms | home + ~40 ms |
| TLS | ~155–180 ms appconnect (qua CF) | ~73–103 ms | home + ~80 ms |
| TTFB | 651–794 ms (median 708–726) | 146–187 ms (median 168–171) | hosting ~4.2× nhanh hơn |
| Total GET | ~0.71–0.82 s | ~0.17–0.22 s | hosting ~4× nhanh hơn |
| POST total (reconstructed) | + ~300 ms (tunnel) so với hosting | external legs 0.95–2.9 s + ~180 ms | chênh chủ yếu do tunnel; cả hai đều external-bound |
| Laravel/processing | ~400 ms (no OPcache, debug on) | ~60–80 ms (LiteSpeed) | hosting ~5× nhanh hơn |
| DB | 0.2 ms baseline; 5–30 ms/request | không đo được (cần verify) | — |
| Cache | database store; 1–39 ms/query | không đo được | — |
| External HTTP | resolver 939 / PG 870 / SF hops 0.5s / OG 0.5–0.7s / store 0.6–0.7s | same code (remote không đo được) | giống — không phải hosting |
| Shopee (resolver) | 3 ms warm / 939 ms cold | *cần verify từ IP hosting* | — |
| Resolver | 3 ms warm / 939 ms cold | same | — |
| Polling | 300/800/2000 ms, poll ~10 ms | same code | same |
| User perceived | ~1–4 s | **~3–4 s** | hosting only khác ở POST external + network |

## 16. Root cause

**"TẠI SAO AFF.HOANTIEN.XYZ VẪN MẤT 3–4 GIÂY?"**

Phân loại:

1. **NETWORK — KHÔNG phải** (measured: setup hosting ≈ 80–100 ms; TTFB root 167 ms).
2. **HOSTING — KHÔNG phải** (measured: LiteSpeed xử lý ~60–80 ms/request, Brotli, root 167 ms).
3. **PHP — KHÔNG phải** (home PHP không OPcache nhưng đó là HOME; hosting xử lý nhanh).
4. **LARAVEL — KHÔNG phải trên hosting** (~60–80 ms). Trên HOME có đóng góp (~400 ms).
5. **DATABASE — KHÔNG phải** (0.2 ms baseline; index đủ; 5–30 ms/request).
6. **CACHE — KHÔNG phải là "cache chậm"**; nhưng cache ShopeeFood OG/store **vô hiệu** (null không lưu) là nguyên nhân gián tiếp làm external lặp lại.
7. **SHOPEE / EXTERNAL API — CÓ** (measured):
   - Measured: resolver cold **939 ms**; product-data **868 ms**; SF pipeline short-link **1.05–1.1 s**; OG **0.5–0.74 s**; store **0.58–0.72 s/lần** (có thể 2 lần = ~1.3 s).
   - Evidence: đo từng request bằng Http event hooks; gần như toàn bộ thời gian backend tạo link là chồng tích các external call.
   - Impact: ShopeeFood link creation POST = **1.8–2.9 s backend** → user-perceived 3–4 s (thành công ~POST + ≤0.3 s poll; lỗi ~POST + ≤0.3 s). Shopee product: ~0.95 s POST; timeout edge 6 s nếu Shopee chậm.
   - Confidence: **HIGH** (đo được trực tiếp từng leg trên chính source hosting chạy; các leg không phụ thuộc máy chủ app).
8. **RESOLVER — CÓ phần** (Shopee cold 939 ms; SF `/u` 2 hop 1.1 s). Confidence HIGH (measured).
9. **FRONTEND/POLLING — KHÔNG tạo delay đáng kể** (poll ~10 ms; min interval 300 ms). Confidence HIGH.
10. **CONFIG — trung tính/cần verify hosting** (APP_DEBUG, CACHE_STORE, OPcache, route/config cache hosting chưa đo được). Confidence MEDIUM.
11. **UNKNOWN — NEEDS VERIFICATION**: (a) latency từ IP hosting tới s.shopee.vn / shopeefood.vn / data.addlivetag.com có thể khác home; (b) hosting có OPcache/max_execution_time thực tế; (c) hosting có worker `127.0.0.1:3001` không (flow extension).

**Kết luận một câu:** 3–4 giây chủ yếu ở **EXTERNAL HTTP trong flow tạo link chạy đồng bộ trước response** (ShopeeFood: resolver hops 1.1 s + OG 0.5–0.7 s + store 0.6–0.7 s; Shopee: resolver lạnh ~0.9 s cộng product-data nền ~0.9 s) — **không phải hosting / Laravel / DB / cache của hosting** (hosting chỉ góp ~0.2 s).

## 17. Optimization candidates (KHÔNG thực hiện — để người dùng quyết)
1. ShopeeFood: cache NEGATIVE (lưu null 30–60 phút) để không gọi lại OG/store rỗng mỗi lần.
2. ShopeeFoodPreviewService: tránh call storeName lần 2 trùng (dùng lại kết quả lần 1).
3. ShopeeFood: có thể gửi preview (OG+store) xuống background/queue thay vì chặn POST (response có affiliate_url ngay) — tương tự mô hình Shopee product.
4. Shopee product: đưa product-data (0.87 s) sang real queue worker (QUEUE=database + queue:work) thay cho `afterResponse` đồng bộ để không chiếm PHP worker.
5. Resolver: giảm timeout (6 s) hoặc cache âm; cache kết quả `/u/{code}` như spf path.
6. Home: bật OPcache (`zend_extension=opcache`), config/route cache, đóng góp bootstrap ~400 ms → ~100 ms; hosting đã nhanh rồi.
7. Xác minh hosting APP_DEBUG=false (production), CACHE_STORE (nên dùng file/redis thay database nếu muốn), OPcache on.

## 18. Recommended next tests (sau khi duyệt)
1. Đo **2–3 POST thật có auth** trên aff (browser session do người dùng thực hiện) kèm chụp Network tab: TTFB POST + các poll; hoặc chạy cùng URL 2 lần để thấy cache-warm.
2. Measure hosting→external bằng cách ssh tới hosting chạy `curl -w` cho s.shopee.vn / shopeefood.vn / data.addlivetag.com (5 mẫu).
3. Hosting `php -i` (OPcache, realpath), `ls bootstrap/cache` (config/route cache), .env CACHE_STORE/QUEUE_CONNECTION/APP_DEBUG.
4. Kiểm tra worker `127.0.0.1:3001` trên hosting có chạy/đáp ứng không (flow extension-strategy).
5. Nếu muốn con số "POST total" chính xác từng env: đo browser-level timing trên cùng 1-2 URL chuẩn (cùng hash product) ở cả 2 domain.

## 19. Cleanup / xác nhận
- Instrumentation: tạo command tạm `app/Console/Commands/PerfAuditMeasureCommand.php` → đo → **đã xóa** (Test-Path = False).
- Không sửa file production nào. `git status --short` cuối audit:
```
 M config/app.php        (có từ trước, không phải audit này)
 M routes/web.php        (có từ trước)
?? app/Http/Controllers/Debug/T2TestRotateSessionController.php  (có từ trước)
?? tests/Feature/T2TestRotateSessionTest.php                     (có từ trước)
?? docs/BUG_AUDIT_*.md, docs/CODEMAP.md                           (có từ trước)
```
- `git diff --stat`: config/app.php +4, routes/web.php +7 — đều là thay đổi pre-existing của phiên trước, audit này không đụng.
- Không có INSERT thật: đo DB write dùng transaction + rollback; các SF/shopee-full cũng rollback. Cache table nhận thêm vài key resolver (hành vi app bình thường). Không chạm wallet/cashback.

## 20. Kết luận 5 câu
1. **aff.hoantien.xyz mất bao nhiêu ms ở network?** — ~80–100 ms cho TCP+TLS+request roundtrip nền; TTFB root 146–187 ms (median 168 ms).
2. **aff.hoantien.xyz mất bao nhiêu ms trong Laravel?** — ~60–80 ms/request (LiteSpeed; root TTFB trừ network). Home/hoantien.xyz: ~400 ms (Apache, không OPcache).
3. **mất bao nhiêu ms ở DB?** — ~0.2 ms/lệnh cơ bản; trong flow tạo link tổng 5–30 ms (cache-database SELECT 1.4–39 ms). Không phải bottleneck.
4. **mất bao nhiêu ms gọi Shopee/external?** — Shopee resolver lạnh ~939 ms (warm 3 ms); product-data ~868 ms; ShopeeFood pipeline (short) ~1.05–1.1 s; OG fetch 0.5–0.74 s; store API 0.58–0.72 s/lần (khi rỗng gọi 2 lần ≈ 1.3 s). Cộng dồn một lần tạo link = 1.6–2.9 s.
5. **mất bao nhiêu ms ở frontend/polling?** — poll ~10 ms/request, interval 300/800/2000 ms; không tạo delay nền đáng kể. Spinner ẩn ngay khi POST trả `affiliate_url`; chỉ chờ thêm ≤300 ms.

**"3–4 giây chủ yếu nằm ở ___EXTERNAL HTTP đồng bộ trong flow tạo link (ShopeeFood resolver+OG+store, và Shopee resolver/product-data)__"**

Evidence: bảng §7/§9 đo từng request external cùng status+duration; TTFB hosting 167 ms (hosting nhanh); DB <30 ms; các external legs cộng dồn 1.6–2.9 s khớp với cảm nhận 3–4 s của user.
Phần chưa đo được (IP hosting → external, hosting env chi tiết) được đánh dấu **UNKNOWN — NEEDS VERIFICATION** tại §16.8/§16.11.

---
*Audit-only. Không tối ưu gì trong phiên này.*