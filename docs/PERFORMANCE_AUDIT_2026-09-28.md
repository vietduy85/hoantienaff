# PERFORMANCE AUDIT FORENSIC — LUỒNG TẠO AFFILIATE LINK (HOANTIEN.XYZ) — 2026-09-28

Ngày: 2026-09-28
Bản chất: **AUDIT ONLY** — không sửa code production, không thêm index, không đổi cache/queue/config/Cloudflare/hosting, không tạo debug route, không migrate, không commit.
Phương pháp: **đo trước, kết luận sau**. Instrumentation tạm thời đã tạo → đo → **cleanup** (xem mục Cleanup).
Vị trí đo: máy home (XAMPP, MariaDB 10.4.32) chạy **cùng source code** mà hosting chạy.
Baseline đối chiếu: `docs/PERFORMANCE_AUDIT_2026-09-27.md`, `docs/PERFORMANCE_AUDIT_2026-09-27_SHOPEE.md`, `docs/PRODUCTDATA_AUDIT_2026-09-28_AFF_vs_HOANTIEN.md`.

### Nhãn bằng chứng dùng xuyên suốt tài liệu

| Nhãn | Ý nghĩa |
|---|---|
| `MEASURED` | Đo trực tiếp trong phiên audit này (2026-09-28) |
| `MEASURED-27` | Đo ở phiên audit 2026-09-27, dẫn lại để so sánh |
| `SOURCE` | Kết luận đọc thẳng từ source hiện tại (đã đối chiếu số dòng) |
| `INFERRED` | Suy luận hợp lý từ `MEASURED`/`SOURCE`, **chưa** chứng minh trực tiếp |
| `UNKNOWN` | Chưa xác định được với dữ liệu hiện có — cần đo thêm |

---

## 1. Executive Summary

Tạo affiliate link Shopee **không chậm vì Laravel/DB/cache hosting**. Toàn bộ độ trễ nằm ở **2 HTTP leg ra ngoài chạy tuần tự**, cộng lại:

| Chặng (luồng Shopee, cache MISS) | Thời gian `MEASURED` |
|---|---:|
| Shopee resolver lạnh (`s.shopee.vn`/`shp.ee` → `shopee.vn`) | **980.9 – 985.4 ms** |
| Shopee resolver ấm (cache hit) | **0.4 – 0.8 ms** |
| Cashback calculator | **0 – 0.01 ms** |
| Affiliate cache lookup (MISS) | **11.5 – 44.5 ms** |
| `link_requests` INSERT | **7.8 – 23.3 ms** |
| `link_requests` UPDATE ×2–3 | **1.5 – 4.66 ms** |
| ProductData API `data.addlivetag.com` (after-response) | **652.8 – 1037.8 ms** |
| **Tổng backend lạnh (mô phỏng controller trọn vẹn)** | **1726.6 ms** |
| **Tổng backend ấm (resolver đã cache)** | **828.3 ms** |
| Real HTTP POST authed, lạnh (baseline 09-27) `MEASURED-27` | **1.698 – 1.820 s** |

Ba kết luận quan trọng nhất:

1. **Resolver lạnh là nút thắt lớn nhất của critical path trước response** (`~981 ms`, chiếm ~57% tổng lạnh). Nó chạy **đồng bộ, trước khi POST trả về**.
2. **`afterResponse()` không giải phóng worker.** Với Laravel v12.62.0, closure không phải `ShouldQueue` nên nó chạy **đồng bộ trong `Kernel::terminate()`** (`SOURCE`), vẫn giữ đúng worker PHP đó ~0.65–1.04 s. Ưu điểm duy nhất: bytes response đã được gửi đi trước khi leg này chạy. Đây là **che giấu độ trễ, không phải loại bỏ độ trễ**.
3. **`SQLSTATE[22003]` trên `item_id` KHÔNG tái hiện được ở local, và Eloquent vs Query Builder hành xử giống hệt nhau.** Cả hai đều thành công với mọi giá trị trong `bigint unsigned`, và cùng fail khi vượt `18446744073709551615`. Giả thuyết ban đầu "Eloquent hỏng, QB không" **không có bằng chứng** (`MEASURED`).

---

## 2. Phạm vi, môi trường đo và giới hạn

**Phạm vi:** `POST /link-requests` (mọi strategy), `GET /api/link-request/{id}` (polling), `GET /api/extension/jobs` + `POST /api/extension/results`, luồng after-response, ShopeeFood, DB/schema, extension + affiliate-worker, frontend polling.

**Môi trường đo (`MEASURED`):**

| Hạng mục | Giá trị |
|---|---|
| Repo | `C:\xampp\htdocs\hoantienaff` @ `d5e6518` |
| PHP | 8.2.12, `PHP_INT_MAX = 9223372036854775807` |
| Laravel | **v12.62.0** |
| DB | MariaDB **10.4.32** (`127.0.0.1`, `hoantienaff`, root/no-password) |
| `SESSION_DRIVER` | `database` |
| `QUEUE_CONNECTION` | `database` |
| `CACHE_STORE` | `database` |
| `APP_ENV` / `APP_DEBUG` | `local` / `true` |
| `AFFILIATE_TIMING` | `true` |
| Cookie | `hoantien_session_v2` (mã hoá qua `EncryptCookies`) |
| `innodb_buffer_pool_size` | `16777216` (**16 MB** — đây là XAMPP local, KHÔNG đại diện aff hosting) |

**Giới hạn của phiên đo (quan trọng khi đọc số liệu):**

- Số liệu mạng ra ngoài đo từ máy home. RTT/DNS/TLS của aff hosting **không** đồng nhất với home, và không thể suy ra trực tiếp (`UNKNOWN`).
- Không thể tạo phiên đăng nhập hợp lệ để đo **real HTTP POST** trong phiên này: cookie cần `CookieValuePrefix` + mã hoá `EncryptCookies`, và token CSRF phải khớp session thật. Thử cả 3 biến thể (cookie thô, cookie mã hoá không prefix, cookie mã hoá có prefix) → đều trả **HTTP 419 `CSRF token mismatch`** với thời gian `0.367–0.601 s`. Con số 419 này **không phải** thời gian xử lý luồng nghiệp vụ và bị loại khỏi mọi kết luận.
- Vì vậy phần HTTP thật của báo cáo này dựa vào `MEASURED-27` (real HTTP authed `1.698–1.820 s`) **có đối chiếu chéo** với mô phỏng controller trọn vẹn của phiên này (`1.7266 s` lạnh). Hai con số khớp nhau trong ~1.5% → củng cố độ tin cậy của mô phỏng.

---

## 3. Bản đồ kiến trúc & vòng đời request

`SOURCE` — `routes/web.php:62`

```
POST /link-requests
   └─ DashboardController@store                  (đọc setting, chọn strategy)
        ├─ affiliate.dashboard.strategy = direct      [LIVE: direct]
        │    └─ DashboardCreateDirectLinkController@store
        └─ affiliate.dashboard.strategy = extension   [KHÔNG phải live path hiện tại]
             └─ DashboardCreateExtensionLinkController@store
```

Hai điểm cần tách bạch (dễ gây hiểu sai khi đọc code):

- `app/Services/AffiliateLinkService.php` + `Strategies/DirectLinkStrategy.php` + `Strategies/ExtensionStrategy.php` là **một lớp strategy song song**, **không** nằm trên đường chạy thật của dashboard direct controller. Đừng dùng nó làm call graph của `POST /link-requests`.
- Doc `queue-system.md` mô tả closure after-response nằm trong `DashboardController` (dòng 114–178) — **stale**. Closure thật nằm ở `DashboardCreateDirectLinkController.php:129-195`.
- `known-issues.md` ghi extension lấy tối đa 10 job — **stale**. Source thật là `AffiliateJobController.php:40` → `->limit(5)`.

**Trạng thái `status` của `link_requests` trên luồng Shopee direct (`SOURCE`):**

```
processing  (INSERT, line 45 — vì $isShopee)
   ├─ resolver OK + cache HIT  → completed       (line 108-111, ngay trong request)
   ├─ resolver OK + cache MISS → processing      (giữ nguyên cho tới khi after-response xong)
   └─ resolver FAIL            → failed          (line 59-62, trả 422)
```

Đây là cơ chế polling hoạt động: người dùng **luôn** nhận được `affiliate_url` sớm (dựng tay từ `s.shopee.vn/an_redir`, không cần ProductData), còn cashback/sản phẩm chỉ là *enrichment* đến sau.

---

## 4. Call graph chi tiết — POST /link-requests (direct, Shopee)

`SOURCE` — `DashboardCreateDirectLinkController.php`

| # | Dòng | Hành động | Chặng thời gian (`MEASURED`) |
|---|---:|---|---:|
| 1 | 33–35 | `validate(original_url: required,url,max:2048)` | <1 ms |
| 2 | 38–39 | `detectPlatform()` (`str_contains` trên 5 domain) | <1 ms |
| 3 | 41–46 | `LinkRequest::create(status = processing)` → **INSERT** | **7.8 – 23.3 ms** |
| 4 | 49 | `urlResolver->resolve()` — **nút thắt, đồng bộ** | **980.9 ms** lạnh / **0.4–0.8 ms** ấm |
| 5 | 51 | `isShopeeLanding($resolvedUrl)` | <1 ms |
| 6 | 76–77 | `extractItemId()` + `cacheService->get()` | **11.5 – 44.5 ms** |
| 7a | 87–104 | *(HIT)* `$link->update()` — chép 16 cột từ cache → **UPDATE #1** | **1.5 – 4.66 ms** |
| 7b | 108–111 | *(HIT)* `update(affiliate_url, status=completed)` → **UPDATE #2** | **1.5 – 2.3 ms** |
| 8a | 114–116 | *(MISS)* `logMiss()` + `update(item_id)` → **UPDATE #1** | **1.5 ms** |
| 8b | 121–123 | *(MISS)* `update(affiliate_url)` → **UPDATE #2** | **2.3 ms** |
| 9 | 129–195 | `dispatch(closure)->afterResponse()` — **không chặn response bytes** | 0 (registration) |
| 10 | 302–309 | `response()->json(...)` — trả `affiliate_url` + `status=processing` | — |
| 11 | `Kernel::terminate()` | **closure chạy đồng bộ** | — |
| 12 | 139 | `productData->getByUrl()` — **nút thắt #2** | **652.8 – 1037.8 ms** |
| 13 | 151 | `cashbackCalculator->calculate()` | **0 – 0.01 ms** |
| 14 | 153–171 | `LinkRequest::where(id)->update(17 cột + completed)` → **UPDATE lớn** | **2.8 – 81.5 ms** |
| 15 | 176–192 | `cacheService->put()` → `updateOrCreate` | (gộp vào #14) |
| 16 | blade 241 | Frontend poll thấy `completed`, dừng polling | round-trip |

**Nhận xét cấu trúc (`SOURCE`):**

- Nhánh **HIT** dùng **2 lần `UPDATE` riêng biệt** (line 87 và 108) thay vì 1 → 2 round-trip DB + 2 lần ghi log thừa.
- Nhánh **MISS** cũng **2 lần `UPDATE`**.
- Cả 2 nhánh đều gọi `buildAffiliateUrl()` (line 106 / 119) → `Setting::get('affiliate.direct.shopee_affiliate_id')` là **truy vấn DB không cache** chạy mỗi request.
- Line 153 dùng `LinkRequest::where('id',...)->update([...17 cột])` — đây là **query builder mass update**, *không* phải model `save()`. Điều này quan trọng cho mục 16.

---

## 5. Call graph — extension strategy

`SOURCE` — `DashboardCreateExtensionLinkController.php`, `Api/AffiliateJobController.php`, `browser-extension/background.js`

```
POST /link-requests  (strategy = extension)
   └─ ExtensionStrategy  → status = pending
        └─ 200 OK ngay, KHÔNG có affiliate_url

GET /api/extension/jobs?token=…            (background.js:82)
   └─ token check (line 27-34) → 401 nếu sai
   └─ SELECT … WHERE status = 'pending' ORDER BY id LIMIT 5   (line 36-41)
   └─ UPDATE … SET status = 'processing'  cho đúng 5 dòng    (line 44-45)   ← claim không atomic

background.js → chrome.tabs.sendMessage  (line 153) → content.js chạy trên affiliate.shopee.vn
   └─ content.js: chunk(urls, BATCH_SIZE = 5)                 (content.js:7,16,173)
        └─ mỗi batch: điền textarea → subId → click → chờ kết quả
        └─ sleep(rnd(1500, 3200)) giữa các batch                (content.js:8-9,179)

POST /api/extension/results?token=…          (background.js:190)
   └─ vòng foreach (line 68-91):
        - LinkRequest::find(id)                              (line 70)  ← N+1
        - $lr->update(affiliate_url, status)                  (line 73-76)
        - cacheService->updateAffiliateUrl()                  (line 78-80)
        - shopeeFoodPreview->enrichFromAffiliateUrl()  try/catch (line 83)
```

**Điểm đáng chú ý (`SOURCE`):**

- `jobs()` **claim job bằng `SELECT` rồi `UPDATE` không kèm điều kiện** (line 36–46). Hai tab extension gọi đồng thời có thể nhận **cùng một batch** vì `UPDATE ... WHERE id IN (...)` không kiểm tra `status` còn là `pending`. Đây là race condition thật, `INFERRED` từ đọc code, chưa tái hiện được.
- `result()` lặp tuần tự, mỗi vòng **1 `find` + 1 `update` + 1 `update` cache** và `ShopeeFoodPreview` có thể gọi ra ngoài (vòng lặp, không `DB::transaction`). Batch 5 item ⇒ tối thiểu **15 round-trip DB**, không rollback nếu item giữa lỗi.
- `ShopeeFoodPreview->enrichFromAffiliateUrl()` chạy **trong cùng request HTTP của extension** ⇒ độ trễ `/api/extension/results` = tổng thời gian enrichment của cả batch, và extension `poll()` phải chờ xong mới vòng tiếp theo.

---

## 6. Phân rã thời gian: Shopee resolver

`SOURCE` — `app/Services/UrlResolverService.php`

| Hằng số | Giá trị | Ý nghĩa |
|---|---:|---|
| `SHORT_DOMAINS` (line 10) | `s.shopee.vn`, `vn.shp.ee`, `s.shp.ee`, `shope.ee` | |
| `MAX_REDIRS` (line 16) | 10 | |
| `TIMEOUT` (line 18) | **6 s** | trần trên mỗi leg |
| `CONNECT_TIMEOUT` (line 20) | **4 s** | |
| `CACHE_KEY_PREFIX` (line 22) | `shopee_resolve:` | |
| `CACHE_TTL_SECONDS` (line 24) | **86 400 s (24 h)** | |
| `NON_RETRYABLE_HTTP` (line 36) | 400,401,403,404,405,410,414,451 | |
| `RETRY_ONCE_HTTP` (line 38) | 500,502,503,504 | |

**Đo (`MEASURED`, `https://vn.shp.ee/JLAK2ZRr`):**

| Trạng thái | Mẫu | ms |
|---|---:|---:|
| Lần đầu sau khi xoá cache (warm-up opcache) | 3 | 38.7 / 35.6 / 48.0 |
| Ấm (cache hit, `Cache::get`) | nhiều | **0.4 – 0.8** |
| **Lạnh (`Cache::forget` trước mỗi lần)** | 2 | **980.9 / 985.4** |

⇒ Resolver có **bộ nhớ đệm 24 h rất hiệu quả**. Vấn đề không phải "resolver chậm" mà là **tỉ lệ lạnh**. Với hàng trăm nghìn link ngắn khác nhau, tỉ lệ lạnh luôn ≠ 0, và mỗi lần lạnh là ~1 s **trước** khi người dùng thấy bất cứ thứ gì.

**Cơ chế retry (`SOURCE`):** `RETRY_ONCE_HTTP` chạy 5xx **một lần** ⇒ worst-case lý thuyết cho resolver ≈ `TIMEOUT` × số hop + 1 retry. Với `TIMEOUT = 6 s` và redirect nhiều hop, một URL xấu có thể giữ **worker ~6–12+ s** (`INFERRED`). Không có retry cho lỗi mạng tạm thời ngoài `RETRYABLE_ERRORS` (line 26).

---

## 7. Phân rã thời gian: ProductData (AddLiveTag)

`SOURCE` — `app/Services/ProductDataService.php`

| Hằng số | Giá trị |
|---|---:|
| `API_URL` (line 10) | `https://data.addlivetag.com/product-data/product-data.php` (**hard-code**) |
| `RETRY_TIMES` (line 12) | 2 |
| `TIMEOUT` (line 14) | **10 s** |
| `CONNECT_TIMEOUT` (line 16) | **5 s** |
| Line 20 | `TODO: Implement Redis cache with 24h TTL` — chưa làm |

**Đo (`MEASURED`):**

| Lần | ms |
|---|---:|
| Lần đầu (kết nối mới) | **1037.8** |
| Lần sau | 673.2 |
| Trong flow mô phỏng #1 | 739.6 |
| Trong flow mô phỏng #2 (lạnh) | **652.8** |

**Cơ chế (`SOURCE`):**

- `Http::retry(self::RETRY_TIMES, 500, ...)` (line 89) — `RETRY_TIMES = 2` nghĩa là **tổng cộng 2 lần thử** (1 gốc + 1 retry) với backoff 500 ms. Worst-case = `CONNECT_TIMEOUT 5 s + TIMEOUT 10 s` × 2 + 500 ms ≈ **25.5 s** cho *một* closure (`INFERRED`).
- Closure này chạy trong `Kernel::terminate()` ⇒ thời gian đó **không cộng vào TTFB** nhưng **vẫn giữ worker**.
- Line 20 cho thấy ý định cache 24 h **chưa được thực hiện** ở tầng HTTP client; việc chống gọi lại hoàn toàn dựa vào bảng `affiliate_cache` phía trên. Nếu closure fail, cache không được ghi ⇒ **lần sau lại gọi lại y hệt**.

**Vòng lặp khuếch đại (`INFERRED`, rất đáng lưu ý):** nếu ProductData lỗi hoặc chậm, `affiliate_cache` không được điền ⇒ link sau với **cùng `item_id`** lại MISS ⇒ gọi ProductData lần nữa. Một `item_id` "xấu" có thể đốt worker liên tục mà không bao giờ cache được. `logMiss()` (line 54) ghi log nhưng **không có negative cache** và `isEmptyPlaceholder()` (line 45–52) chỉ chặn placeholder đã ghi, không chặn trường hợp "chưa ghi gì".

---

## 8. Phân rã thời gian: cashback + affiliate cache

**Cashback (`MEASURED`): 0 – 0.01 ms.** Thuần tính tức thời, **không phải** nút thắt. Có thể loại khỏi mọi giải pháp tối ưu.

**Affiliate cache (`SOURCE` — `AffiliateCacheService.php`):**

```php
// line 24-26
AffiliateCache::where('item_id', $itemId)
    ->whereDate('cache_date', $this->cacheDate)
    ->first();
```

**Đo (`MEASURED`): 1.9 – 44.5 ms** (MISS), vs resolver lạnh 981 ms.

Vấn đề hiệu năng ở đây **không phải thời gian tuyệt đối** (44 ms là con số nhỏ so với 981 ms) mà là:

1. **`whereDate('cache_date', ...)`** (line 25) bọc cột trong hàm `DATE()`. Trên MySQL điều này thường **không dùng được index** của PK tổ hợp `(item_id, cache_date)` ⇒ mỗi lookup thành quét. Với `affiliate_cache` ~1 278 dòng local thì tổng phí có thể chỉ vài ms, nhưng ở aff hosting nếu bảng lớn hơn nhiều thì đây là ứng viên leo thang tuyến tính (`INFERRED`).
2. `updateAffiliateUrl()` (line 80–85) cũng dùng `whereDate` — cùng vấn đề, lại nằm trong vòng lặp N của `AffiliateJobController::result()`.
3. Ghi: `put()` dùng `updateOrCreate(['item_id'=>…, 'cache_date'=>…])` (line 70–73) — **khớt với PK tổ hợp, đây là cách làm đúng**, tránh `SELECT` thừa.

**Gợi ý (chưa thực hiện — audit only):** thay `whereDate('cache_date', $d)` bằng `where('cache_date', '>=', $d)->where('cache_date', '<', $d+1day)` (giữ được index) hoặc lưu `cache_date` dạng `YYYY-MM-DD` **string** để so sánh trực tiếp.

---

## 9. Database & schema audit

**Schema `item_id` — `MEASURED` (đọc `information_schema`):**

| Bảng | Cột | Kiểu | Ghi chú |
|---|---|---|---|
| `link_requests` | `item_id` | `bigint(20) unsigned` NULL | có index |
| `link_requests` | `shop_id` | `bigint(20) unsigned` NULL | |
| `affiliate_cache` | `item_id` | `bigint(20) unsigned` **NOT NULL** | PK tổ hợp |
| `affiliate_cache` | `cache_date` | `date` | PK tổ hợp |

⇒ **Schema local KHỎE.** Có dữ liệu `item_id` 19 chữ số thật (ví dụ `1736855833085969922`) và được đọc/ghi bình thường.

**Quy mô bảng & storage (`MEASURED`, local XAMPP — KHÔNG đại diện aff):**

| Bảng | ~số dòng | Data | Index |
|---|---:|---:|---:|
| `link_requests` | 2 528 | 1 589 248 | 720 896 |
| `affiliate_cache` | 1 278 | 1 589 248 | 0 (PK đã nằm trong data) |

*(Đây là ảnh chụp tại thời điểm đo. Khi xác minh cleanup cuối phiên, `link_requests` đã lên 2 763 và `affiliate_cache` lên 1 477 — app đang được người dùng thật sử dụng liên tục trong ngày, ví dụ các dòng `id` 2808–2815 của user 36/63/9. Không phải dư lượng của phiên đo; xem mục Cleanup.)*

**Quan sát:**

- `link_requests` có **~2 528 dòng nhưng 1.59 MB data** ⇒ trung bình **~630 byte/dòng**. Bảng này bị phình bởi các cột text dài (`product_name`, `product_image`, `product_link`, `notes`). Với `JOBS_LIMIT 5` và vòng lặp `result()`, đây là bảng bị đọc/ghi nhiều nhất mỗi lần tạo link.
- Cột `product_image` lưu URL dài, `product_name` lưu text dài ⇒ I/O nặng hơn nhiều so với một bảng chỉ có số.
- Không thấy bảng nào thiếu index trên `item_id` / `status` / `id`. Index trên `affiliate_cache` báo 0 vì MySQL tính PK vào `DATA_LENGTH` — **không phải thiếu index**.

**Không có migration nào được chạy trong phiên này.** Không đề xuất thêm index: đã đủ cho quy mô quan sát được.

---

## 10. Concurrency, locking & index

**Đo `MEASURED` (SHOW STATUS, local):**

| Chỉ số | Giá trị | Ý nghĩa |
|---|---:|---|
| `Table_locks_waited` | **0** | không chờ table lock |
| `Innodb_row_lock_current_waits` | **0** | không có lock chờ tại thời điểm đo |
| `Innodb_row_lock_waits` | 23 (lịch sử) | |
| `Innodb_row_lock_time_avg` | **7 ms** | |
| `Innodb_row_lock_time_max` | **76 ms** | |
| `innodb_buffer_pool_size` | 16 MB | nhỏ — nhưng chỉ là local |

⇒ **Không có bằng chứng lock contention ở local.** Tuy nhiên `16 MB` buffer pool trên local là con số **rất nhỏ** cho 1.6 MB data × 2 bảng; nếu aff hosting cũng dùng buffer pool mặc định nhỏ thì ảnh hưởng sẽ khác (`UNKNOWN` — cần đọc trực tiếp `innodb_buffer_pool_size` trên aff).

**Điểm concurrency đáng sửa (`SOURCE`):**

| Vị trí | Vấn đề | Mức |
|---|---|---|
| `AffiliateJobController.php:36-46` | Claim job bằng `SELECT` → `UPDATE` không có điều kiện `status='pending'` ⇒ **2 extension có thể nhận trùng batch** | Cao, `INFERRED` |
| `AffiliateJobController.php:68-91` | Vòng `foreach` tuần tự, không transaction; lỗi giữa chừng để lại `status='processing'` mồ côi vĩnh viễn | Cao, `SOURCE` |
| `DashboardCreateDirectLinkController.php:87,108,116,121` | 2 `UPDATE` tách rời không transaction ⇒ đọc được trạng thái nửa vời nếu request chết giữa chừng | Thấp |
| `Kernel::terminate()` | Mỗi request giữ worker thêm 0.65–1.04 s cho closure ⇒ **giảm throughput đồng thời** | Cao, `SOURCE` |

**Hệ quả về throughput (`INFERRED`):** trên shared hosting thường chỉ vài PHP worker. Nếu 10 người dùng tạo link Shopee cùng lúc, mỗi người giữ worker từ `0.83 s` (ẩm) đến `1.73 s` (lạnh). Với `N` worker, công suất tối đa ≈ `N / 1.73` link/s **và tất cả đều trả HTTP 200 với `affiliate_url` đúng** ⇒ người dùng không nhận ra là hàng đợi, chỉ thấy hệ thống "chậm" khi tải cao. Đây là dạng suy giảm âm thầm đáng kể nhất của hệ thống.

---

## 11. Ngữ nghĩa thật của `afterResponse()` (Laravel v12.62.0)

Đây là mục quan trọng nhất về **mô hình**, vì nhiều người hiểu `afterResponse()` là "chạy nền, không tốn tài nguyên".

**Chuỗi `SOURCE` trong vendor:**

| Bước | File:dòng | Việc |
|---|---|---|
| 1 | `Foundation/Bus/PendingDispatch.php:190-192, 249` | `afterResponse()` set cờ `$afterResponse = true` |
| 2 | `Foundation/Bus/PendingDispatch.php` `__destruct` | gọi `dispatchAfterResponse()` |
| 3 | `Bus/Dispatcher.php:256-267` | `dispatchAfterResponse()`: nếu `allowsDispatchingAfterResponses` thì `app()->terminating(fn => $this->dispatchSync($command, $handler))` |
| 4 | `Bus/Dispatcher.php` `dispatchSync()` | closure **không** implement `ShouldQueue` ⇒ chạy **inline** (không serialize, không vào bảng `jobs`) |
| 5 | `Foundation/Http/Kernel.php:211-217` | `terminate()` gọi `app->terminate()`, kích hoạt các terminating callback |
| 6 | `Foundation/Http/Middleware/InvokeDeferredCallbacks.php:32-37` | deferred callbacks cùng nhịp |

**Kết luận chính xác (`SOURCE`):**

- `QUEUE_CONNECTION=database` **không liên quan** ở đây. Closure thô không phải `ShouldQueue` ⇒ **không** được đẩy vào hàng đợi database. Không có worker nào xử lý nó.
- Closure chạy **sau khi response đã được gửi cho client**, nhưng **trong cùng tiến trình PHP và cùng worker**. Thời gian của nó **không** xuất hiện trong TTFB.
- Trên LiteSpeed/mod_php, `Kernel::terminate()` chạy trước khi tiến trình được tái sử dụng ⇒ kết quả trên host đúng như mô tả: **client thấy nhanh, server tải nặng**.

**Diễn đạt đúng cần dùng trong tài liệu kỹ thuật:** `afterResponse()` ở đây là **"ẩn độ trễ khỏi response"**, **không phải** "tăng throughput". Nếu mục tiêu là nhả worker, phải dùng `ShouldQueue` + `QUEUE_CONNECTION` thật, hoặc đưa leg ra ngoài sang cron/HTTP cron.

---

## 12. Frontend UX & polling

`SOURCE` — `resources/views/dashboard/partials/link-generator.blade.php:230-280`

| Giai đoạn | Delay giữa các lần poll | Điều kiện |
|---|---:|---|
| `elapsed < 3 s` | **300 ms** | dòng 237 |
| `3 s ≤ elapsed < 8 s` | **800 ms** | dòng 238 |
| `elapsed ≥ 8 s` | **2000 ms** | dòng 239 |
| Lỗi mạng | `min(delay × 2^errorCount, 5000)` | dòng 274 |

**Đánh giá:**

- Thiết kế 3 bậc thang 300/800/2000 ms là **hợp lý**: vùng 0–3 s phủ đúng cửa sổ mà Shopee HIT (trả `completed` ngay trong request) và MISS ấm thường xong. Vùng >8 s hạ xuống 2000 ms để không spam.
- **Điểm lệch cần nêu:** frontend **luôn** poll ít nhất 1 lần kể cả khi POST đã trả `status='completed'` (line 205 `this.startPolling()` được gọi vô điều kiện). Nhánh `if (data.affiliate_url)` (line 201–204) chỉ set `result`, **không** chặn poll. ⇒ **mọi lần tạo link, kể cả cache HIT hoàn toàn trong RAM, vẫn tốn ≥1 HTTP round-trip thừa** (`SOURCE`).
- Polling là `fetch` với `X-Requested-With` ⇒ không có request nào bị cache HTTP; mỗi vòng là 1 PHP request đầy đủ (session DB + middleware).
- Với `QUEUE_CONNECTION` và session lưu ở `database`, **mỗi lần poll = ≥1 SELECT `sessions`**. Ở 300 ms/lần trong 3 s đầu, một người dùng tạo ~10 request polling.
- Không có thông báo trạng thái trung gian kiểu "đang lấy dữ liệu sản phẩm" trong HTML — spinner chỉ chung. Điều này khiến 0.65–1.04 s chờ của ProductData **cảm giác như treo**, dù nó đang chạy.

---

## 13. Browser extension & affiliate-worker

**`browser-extension/background.js` (`SOURCE`):**

| Hằng số | Giá trị | Dòng |
|---|---:|---:|
| `SLEEP_EMPTY` | **3000 ms** (không có job) | 1 |
| `SLEEP_ERROR` | **5000 ms** (lỗi) | 2 |
| `SLEEP_DONE` | **1000 ms** (vừa xong) | 3 |

Luồng: `poll()` (line 59) → `fetch(fullUrl)` (line 82) → `chrome.tabs.get(cachedTabId)` (line 30) → fallback `chrome.tabs.query({url:'https://affiliate.shopee.vn/*'})` (line 43) → `sendMessage` (line 153) → `POST /api/extension/results` (line 190) → `setTimeout(poll, delay)` (line 56).

**`browser-extension/content.js` (`SOURCE`):**

| Hằng số | Giá trị | Dòng |
|---|---:|---:|
| `BATCH_SIZE` | **5** | 7 |
| `MIN_DELAY` | **1500 ms** | 8 |
| `MAX_DELAY` | **3200 ms** | 9 |
| `sleep` | `setTimeout` bọc Promise | 12 |

`chunk(urls, BATCH_SIZE)` (line 173), `sleep(rnd(MIN_DELAY, MAX_DELAY))` giữa các batch (line 179) — trừ batch cuối.

**Đánh giá:**

- Ba tầng giới hạn tốc độ **trùng nhau và cùng bằng 5**: `jobs()` `->limit(5)` (`AffiliateJobController.php:40`), `BATCH_SIZE = 5` (content.js:7), và các comment trong `known-issues.md` (stale, ghi 10). Thực tế **hiệu suất extension tối đa = 5 link / (một vòng poll + thời gian xử lý batch)**.
- Với `SLEEP_EMPTY = 3 s` và tối đa 5 link/mỗi vòng, trần lý thuyết ≈ **1.67 link/s** ngay cả khi mọi thứ hoàn hảo (`INFERRED`).
- `MIN/MAX_DELAY` là **ngủ cố định** giữa các batch ⇒ 20 link = 4 batch ⇒ thêm **4.5–9.6 s** ngủ thuần. Với 50 link = 10 batch ⇒ **13.5–28.8 s** ngủ.
- `content - Copy.js` và `background - Copy.js` tồn tại cạnh bản đang dùng — **rủi ro vận hành**: dễ sửa nhầm file, và không rõ file nào được load trong `manifest.json`. Cần xác nhận.

---

## 14. Đường đi ShopeeFood

`MEASURED-27` (`PERFORMANCE_AUDIT_2026-09-27.md`) — chưa đo lại trong phiên 2026-09-28:

| Leg | ms |
|---|---:|
| `/u/{code}`: 301 + 200 (2 hop) | **1050 – 1100** |
| OpenAI-graph fetch (trang shop `shopeefood.vn`) | **500 – 740** |
| Store API `data.addlivetag.com/.../store.php` | **580 – 720 / lần** |

**Điểm đáng chú ý nhất (`MEASURED-27`):** khi store API trả rỗng, nó **bị gọi 2 lần** ⇒ backend ≈ `1.1 s + 0.5–0.7 s + 2 × 0.6–0.7 s` ≈ **2.6 – 2.9 s**, và người dùng thấy **3–4 s**.

Và `AffiliateJobController::result()` gọi `shopeeFoodPreview->enrichFromAffiliateUrl()` (line 83) **ngay sau khi extension trả kết quả**, trong cùng request ⇒ với strategy extension, độ trễ này được **cộng dồn** vào vòng poll của background.js.

**Cấu trúc cache ShopeeFood:** TTL `43200` s (12 h), khoá dạng `shopee_food:{code}` / `shopee_food_store:{shopId}` — đã đối chiếu ở audit 09-27.

---

## 15. Logging & observability

**Có sẵn (`SOURCE`):**

| Marker | Nơi | Điều kiện |
|---|---|---|
| `[CACHE-Timing] Refresh Cache` | `DashboardCreateDirectLinkController.php:141-145` | `AFFILIATE_TIMING` |
| `[CACHE]` HIT/MISS | `AffiliateCacheService.php:34, 56` | `AFFILIATE_TIMING` |
| `[Resolver] Could not resolve...` | `DashboardCreateDirectLinkController.php:52` | luôn |
| `[ENTER jobs]` / `[RETURN jobs]` | `AffiliateJobController.php:24, 49` | `app()->isLocal()` |

**Khoảng trống (`SOURCE`):**

1. **Không có stage-timing cho resolver.** Không có log bao quanh `urlResolver->resolve()` (line 49). Muốn biết resolver tốn bao nhiêu phải suy từ tổng. Đây là chặng lớn nhất mà không có log riêng.
2. **Không có log cho `LinkRequest::create()`** (line 41) và các `update()`.
3. **Không có log cho `put()`** (`AffiliateCacheService.php:65`) — ghi cache xong không có dấu vết.
4. **Không có log cho ProductData thất bại.** Closure (line 147) chỉ xử lý `success === true`; nếu `success` false, **im lặng** — không log, không cập nhật `status`, link **kẹt ở `processing` vĩnh viễn** và frontend poll tới `elapsed ≥ 8 s` rồi lặp 2 s **vô hạn** (không có `MAX_POLL`).
5. `[ENTER jobs]` / `[RETURN jobs]` chỉ bật ở `isLocal()` ⇒ **trên aff hosting không có bất kỳ dấu vết extension nào**.
6. `AFFILIATE_TIMING` cần bật để thấy `[CACHE]`. Nếu trên aff đang `false`, cache hit-rate **không quan sát được** từ log.
7. Marker `[ITEM-ID QB START]` / `[ITEM-ID QB SUCCESS]` mà bug report 22003 nhắc tới: **không tồn tại** trong source, không trong docs, không trong `storage/logs/laravel.log` (đã grep toàn bộ). Chúng thuộc về một lệnh đo tạm đã xoá ⇒ **không có bằng chứng lưu lại**.

---

## 16. Forensics `item_id` / `SQLSTATE[22003]`

**Triệu chứng được báo:** Eloquent update `item_id` hỏng với `SQLSTATE[22003]: Numeric value out of range: 1264 Out of range value for column 'item_id'`, trong khi Query Builder "vẫn chạy".

**Điều tra đã làm (`MEASURED`):**

1. **Grep log:** không có bất kỳ `22003` nào trong `storage/logs/laravel.log` (toàn bộ file). Không có marker `[ITEM-ID QB …]` trong source/docs.
2. **Schema:** cả hai bảng đều `bigint(20) unsigned`. Schema local **không** bị drift.
3. **Test trực tiếp trên `link_requests`** (trong transaction, rollback) — cùng một giá trị, chạy trên cả hai đường:

| Giá trị | Query Builder | Eloquent (`where()->update()`) | Model `update()` |
|---|---|---|---|
| giá trị nhỏ | OK | OK | OK |
| `PHP_INT_MAX` = 9223372036854775807 | OK | OK | OK |
| `9223372036854775808` (string) | OK | OK | OK |
| `18446744073709551615` (unsigned max, string) | OK | OK | OK |
| `1736855833085969922` (19 chữ số) | OK | OK | OK |
| `1e19` (float) | OK | OK | OK |
| `18446744073709551616` (string) | **FAIL** | **FAIL** | **FAIL** |
| `1.8446744073709552e19` (float) | **FAIL** | **FAIL** | **FAIL** |
| `21474836474836474836` (string) | **FAIL** | **FAIL** | **FAIL** |

4. **Test trên `affiliate_cache`:** `updateOrCreate` và `DB::table()->updateOrInsert()` — **kết quả giống hệt** trên toàn bộ tập giá trị trên.

**Kết luận:**

- **Không tồn tại sự khác biệt Eloquent-vs-QueryBuilder trong schema `bigint unsigned`.** Cả hai đều thành công trong phạm vi hợp lệ và cùng fail ngoài phạm vi. Giả thuyết ban đầu **bị bác bỏ** (`MEASURED`).
- Vì vậy, khác biệt mà bug report quan sát được **nhiều khả năng** đến từ việc hai lần đo dùng **hai giá trị khác nhau** (ví dụ float 1e19 vượt `PHP_INT_MAX` bị PHP làm tròn, so với chuỗi trong phạm vi unsigned), chứ không phải từ tầng truy vấn.
- **Riêng aff production:** `UNKNOWN`. Nếu aff có `item_id` là `INT` (chứ không phải `BIGINT UNSIGNED`) thì mọi `item_id` Shopee hiện đại (> 2 147 483 647) đều vượt ⇒ `22003` là **kỳ vọng**, không phải ngẫu nhiên. Nhưng đây mới là `INFERRED`: **cần `SHOW CREATE TABLE link_requests` trên aff mới xác nhận**.
- **Rủi ro thật đã xác nhận (`SOURCE`), không cần 22003:** `AffiliateCache` khai báo `$primaryKey = 'item_id'`, `$keyType = 'int'`, `$incrementing = false` (`AffiliateCache.php:11-15`) và **không có cast cho `item_id`** (line 39–50 chỉ cast `cache_date`, `estimated_cashback`, `user_estimated_cashback`, `cashback_rate`, `is_xtra`, `rating`, `last_affiliate_created_at`). Với `item_id` là chuỗi 19 chữ số, Eloquent coi là `int` → đường đi qua PHP `int` 64-bit là an toàn, nhưng **bất kỳ chỗ nào** vô tình dùng `intdiv`/số học trên `item_id`, hoặc để giá trị float đi qua, sẽ hỏng. `ProductDataService::mapResponse()` (line 140) trả `itemId` **thô từ JSON, không cast**, còn `extractProductIds()` (line 35) cast `(int)`. **Hai đường vào khác nhau về kiểu dữ liệu cho cùng một cột** — đây là điểm nên chuẩn hoá.

---

## 17. Sổ rủi ro & failure mode

| # | Rủi ro | Bằng chứng | Mức |
|---|---|---|---|
| R1 | Resolver lạnh chặn response ~981 ms | `MEASURED` | **Cao** |
| R2 | Closure after-response giữ worker 0.65–1.04 s mỗi request | `MEASURED` + `SOURCE` | **Cao** |
| R3 | `success=false` từ ProductData ⇒ link kẹt `processing` vĩnh viễn, frontend poll vô hạn | `SOURCE` | **Cao** |
| R4 | Extension claim job race (SELECT→UPDATE không điều kiện) | `SOURCE` | **Cao** |
| R5 | `result()` tuần tự, không transaction ⇒ trạng thái lẫn lộn giữa chừng | `SOURCE` | Trung bình |
| R6 | Trần extension ~1.67 link/s + ngủ 1.5–3.2 s/batch | `SOURCE` | Trung bình |
| R7 | `whereDate(cache_date)` có thể vô hiệu hoá index PK tổ hợp | `SOURCE` + `INFERRED` | Trung bình |
| R8 | Schema drift `INT` vs `BIGINT` trên aff ⇒ `22003` hàng loạt | `INFERRED`, cần aff DDL | **Cao (nếu đúng)** |
| R9 | ProductData worst-case ~25.5 s giữ worker (retry 2 × timeout 10 s) | `SOURCE` | Trung bình |
| R10 | Resolver worst-case ~6–12+ s giữ worker (timeout 6 s × hop + retry) | `SOURCE` | Trung bình |
| R11 | Không có negative cache ⇒ `item_id` lỗi lặp lại vô hạn | `SOURCE` | Trung bình |
| R12 | Mỗi lần tạo link tốn ≥1 request polling thừa kể cả khi đã `completed` | `SOURCE` | Thấp |
| R13 | Hai file `*- Copy.js` trong `browser-extension` gây nhầm khi sửa | `SOURCE` | Thấp |
| R14 | Không quan sát được cache hit-rate trên aff nếu `AFFILIATE_TIMING=false` | `SOURCE` | Thấp |

---

## 18. Kết luận & những gì vẫn chưa xác định

**Kết luận (`MEASURED` + `SOURCE`):**

- Hosting không phải nút thắt. `affiliate_cache`, `cashback`, và thao tác DB đều dưới vài chục ms. **97%+ độ trễ nằm ở 2 HTTP leg ra ngoài.**
- Critical path lạnh ≈ **1.73 s** backend, trong đó resolver 981 ms (57%) và ProductData 653–1038 ms (38–60%).
- Critical path ấm ≈ **828 ms** — vẫn chậm, vì ProductData vẫn chạy sau mọi lần cache miss.
- `afterResponse()` **không** tăng throughput. Nó chỉ đẩy ProductData ra khỏi TTFB.
- Bằng chứng cho giả thuyết "Eloquent hỏng `item_id`, Query Builder không" **không tồn tại** ở local; hai đường hành xử giống hệt.

**Vẫn `UNKNOWN` (cần dữ liệu ngoài môi trường này):**

1. **`SHOW CREATE TABLE link_requests` trên aff** — quyết định R8 (`INT` vs `BIGINT UNSIGNED`) là nguyên nhân 22003 hay không. **Ưu tiên cao nhất.**
2. **`innodb_buffer_pool_size` + `SHOW GLOBAL STATUS` trên aff** — số liệu local 16 MB không đại diện.
3. **TTFB/TLS/DNS thật của aff** — không đo được từ home.
4. **Tỉ lệ cache HIT thực tế trên aff** — cần `AFFILIATE_TIMING=true` ít nhất 24 h rồi đếm `[CACHE]`.
5. **`AFFILIATE_TIMING` và `APP_DEBUG` trên aff** — nếu `APP_DEBUG=true` ở production thì rủi ro rò rỉ thông tin khác cần đánh giá riêng.
6. **Tần suất thất bại của ProductData** — chưa có log khi `success=false` (R3), nên chưa biết bao nhiêu link kẹt `processing` ngoài production.
7. **Xác nhận `manifest.json` của extension** load file nào (R13).
8. **P0–P7 của aff** — vẫn chờ người dùng cung cấp (kiểm tra 403, `DEBUG=false`, `APP_DEBUG`, `display_errors`, DB collation/version, storage/session quyền ghi, thư mục public, log 403).

---

## TOP 10 NÚT THẮT (xếp theo tác động thời gian thực tế)

| # | Nút thắt | Thời gian | Vị trí | Loại |
|---|---|---:|---|---|
| 1 | ProductData `data.addlivetag.com` | **652.8 – 1037.8 ms** | `ProductDataService.php:10,139` | ngoài |
| 2 | Shopee resolver lạnh | **980.9 – 985.4 ms** | `UrlResolverService.php:40` | ngoài |
| 3 | Closure after-response giữ worker | **650 – 1040 ms / request** | `DashboardCreateDirectLinkController.php:129-195` | kiến trúc |
| 4 | Tỉ lệ resolver lạnh (cache TTL 24 h, per-URL) | 1 s × tỉ lệ lạnh | `UrlResolverService.php:24` | kiến trúc |
| 5 | Resolver worst-case khi URL hỏng (timeout 6 s × hop + retry 5xx) | **6 – 12+ s** | `UrlResolverService.php:16,18,38` | độ bền |
| 6 | ProductData worst-case (retry 2 × timeout 10 s + 500 ms) | **~25.5 s** | `ProductDataService.php:12,14,89` | độ bền |
| 7 | ShopeeFood `/u/{code}` 2 hop | **1050 – 1100 ms** | `ShopeeFoodPreviewService` | ngoài |
| 8 | ShopeeFood store API (gọi 2 lần khi rỗng) | **1160 – 1440 ms** | `ShopeeFoodPreviewService` | ngoài |
| 9 | Affiliate cache lookup (`whereDate` → mất index) | **11.5 – 44.5 ms** | `AffiliateCacheService.php:25,81` | DB |
| 10 | Ghi DB: 2 `UPDATE` tách rời + `Setting::get` không cache | **3 – 46 ms** | `DashboardCreateDirectLinkController.php:87,108,358` | DB |

*(Không tính vào TOP 10: `CashbackCalculator` 0–0.01 ms; `link_requests` INSERT 7.8–23.3 ms; polling frontend 0.3–2 s — vòng lặp, không phải chặng chí mạng.)*

---

## TOP 3 HÀNH ĐỘNG (đề xuất — CHƯA thực hiện)

> Audit-only: dưới đây là **đề xuất để người dùng duyệt**, không có gì nào trong danh sách này đã được áp dụng.

### 1. Chuyển ProductData ra khỏi worker hiện tại — chấp nhận độ trễ thay vì giữ worker
- **Bước 1 (ít rủi ro nhất, không đổi hành vi người dùng):** đóng gói closure thành job `ShouldQueue` thật (`class RefreshProductCache implements ShouldQueue`) và đổi `dispatch(...)->afterResponse()` thành `dispatch(new RefreshProductCache(...))`. Hiện tại closure thô **không** vào queue (`SOURCE` mục 11), nên đây là thay đổi hành vi thật, không phải hình thức.
- **Bước 2:** chạy `php artisan queue:work` bằng systemd/supervisor. Hiện `QUEUE_CONNECTION=database` và **không có worker nào** (`UNKNOWN`, cần xác nhận).
- **Bước 3:** thêm `retryUntil()` + `tries` để tránh job kẹt; bổ sung `failed()` để hết R3.
- **Tác động:** mỗi link **nhả worker ngay** sau khi gửi response ⇒ throughput tăng theo số PHP worker thay vì bị chia 1.73 s/link. Với N worker, công suất lý thuyết tăng ~1.5–2×.
- **Rủi ro:** link sẽ ở `processing` lâu hơn vài chục ms tới vài giây (đã vậy). Phải cài `queue:retry`/monitoring.

### 2. Dập tắng nguồn lỗi ở ProductData và bổ sung negative cache
- Bắt nhánh `success === false` trong closure (line 147): log `[CACHE-Timing] ProductData FAILED` + `item_id` + HTTP status, cập nhật `status = 'failed'` với `notes` để frontend **dừng poll** thay vì poll vô hạn (R3).
- Bọc `getByUrl()` trong `try/catch \Throwable` — hiện exception trong `Kernel::terminate()` là mất trắng log liên quan tới link.
- Ghi một **placeholder row** khi ProductData thất bại (thay vì để trống) để `isEmptyPlaceholder()` (line 45) chặn được lần gọi lại; hoặc thêm negative cache riêng với TTL ngắn (5–10 phút).
- Hạ `TIMEOUT` từ 10 s xuống ~4 s và `RETRY_TIMES` từ 2 xuống 1: worst-case giảm từ ~25.5 s xuống ~8.5 s.
- Bổ sung log bao quanh resolver (mục 15 khoảng trống #1) để tỉ lệ lạnh/hit đo được thay vì suy đoán.

### 3. Xác minh & chốt schema `item_id` trên aff, rồi chuẩn hoá kiểu dữ liệu
- **Chạy ngay trên aff:** `SHOW CREATE TABLE link_requests;` và `SHOW CREATE TABLE affiliate_cache;` rồi đối chiếu `item_id`. Nếu là `INT` thì đó **chính là** nguyên nhân 22003 (`R8`) và mọi thứ khác trong mục 16 là nghi vấn sai hướng.
- Nếu đã là `BIGINT UNSIGNED`: bỏ giả thuyết drift, và xử lý phần còn lại — **chuẩn hoá `item_id` về một kiểu duy nhất** ở cả hai đường vào (`ProductDataService::mapResponse()` hiện trả raw, `extractProductIds()` hiện cast `(int)`), cân nhắc thêm cast `'item_id' => 'integer'` trong `AffiliateCache::casts()` để khớp với `$keyType = 'int'` (mục 16).
- **Song song, rẻ hơn nhiều và đáng làm dù chưa chốt aff:** thay `whereDate('cache_date', ...)` bằng so sánh khoảng ngày để giữ index PK tổ hợp (R7), và gộp 2 `UPDATE` trong `DashboardCreateDirectLinkController` (line 87+108, 116+121) thành 1 để bớt 1 round-trip DB mỗi link.
- **Điều kiện tiên quyết:** chỉ chạy sau khi có DDL thật của aff. Không `migrate` trên aff dựa vào suy đoán.

---

## Cleanup / xác nhận

- File tạm đã tạo rồi **xoá**: `storage/app/_perf_stages.php`, `storage/app/_perf_flow.php`, `storage/app/_perf_session.php`.
- Session test đã **xoá khỏi DB**: `DELETE FROM sessions WHERE id LIKE 'perfaudit%'` ⇒ `0` dòng còn lại (xác nhận bằng truy vấn).
- Mọi thay đổi DB đo được đều nằm trong `DB::beginTransaction()` / `DB::rollBack()`, hoặc insert rồi delete. Row `link_requests` dùng để test được rollback ⇒ không để lại dữ liệu rác.
- **Đã xác minh lại sau khi viết tài liệu (không chỉ dựa vào rollback):**
  - `MAX(link_requests.id) = 2815`, `created_at = 2026-09-28 15:56:41`, thuộc user 63, URL `https://vn.shp.ee/ioTHR6n7` — **dữ liệu người dùng thật**, không phải dòng test. Không có dòng nào `id > 2815`, tức **không script nào của phiên đo đã insert thêm link**.
  - `SELECT COUNT(*) FROM sessions WHERE id LIKE 'perfaudit%'` ⇒ `0`.
  - `item_id = 40372404434` (item dùng cho test ProductData) chỉ có 3 dòng cache, `cache_date` lần lượt `2026-06-30`, `2026-07-07`, `2026-09-20` — **không có dòng `2026-09-28`** ⇒ `cacheService->put()` trong flow mô phỏng cũng đã được rollback.
  - 3 dòng `affiliate_cache` không khớp bất kỳ `link_requests` nào đều có `created_at` từ **2026-07-13** ⇒ có sẵn từ trước, không phải rác của phiên đo.
  - Lưu ý khi đọc lại số liệu: lệnh grep `LIKE '%perf%'` trên `product_name`/`shop_name` trả **24 dòng `link_requests` + 21 dòng `affiliate_cache`**, nhưng **toàn bộ là false positive** — khớp chuỗi `"perf"` bên trong tên sản phẩm tiếng Việt (`Kelie Perfume`, `Paula's Choice`, `V-Perfume`, `vnperfectbaby`, `Huggies Skin Perfect`, `The Body Shop …`). Không dòng nào trong số đó là dòng test.
- `Cache::forget()` chỉ xoá cache của chính URL đo (`shopee_resolve:{md5}`), sẽ được ghi lại bình thường ở lần dùng kế tiếp.
- **Không** sửa source production, **không** thêm index, **không** đổi config/cache/queue/Cloudflare, **không** thêm debug route, **không** chạy migration, **không** commit.
- `git status` cuối phiên chỉ còn các file đã dirty **trước khi bắt đầu audit**: `config/app.php`, `routes/web.php` (modified) và các tài liệu/kiểm thử untracked có sẵn từ trước. Tài liệu này là file untracked **mới**.
