# Audit OPcache & Fast Mode (Shopee) — 2026-09-28

Tài liệu này gồm hai phần độc lập:

- **Phần A** — Audit OPcache trên **web SAPI thật** (không phải CLI), kèm benchmark
  OPcache OFF vs ON trên môi trường local thật (Apache + mod_php + MySQL + HTTPS).
- **Phần B** — Audit thiết kế và hiện thực **⚡ Chế độ nhanh (Fast Mode)** cho luồng
  tạo link Shopee, không thay đổi hành vi Normal Mode.

Nhãn nguồn dữ liệu dùng xuyên suốt:

| Nhãn | Ý nghĩa |
|---|---|
| `[ĐO ĐẠC]` | Số đo thực tế trong phiên này, có thể tái lập |
| `[SOURCE]` | Đọc trực tiếp từ mã nguồn / cấu hình hiện tại |
| `[SUY LUẬN]` | Suy ra từ dữ liệu đo, chưa chứng minh trực tiếp |
| `[CHƯA BIẾT]` | Không có quyền/dữ liệu để xác minh |

---

## 1. Kết luận nhanh

1. `[ĐO ĐẠC]` **Local web SAPI KHÔNG có OPcache.** `extension_loaded('Zend OPcache') = NO`
   trên cả Apache lẫn CLI. `php_opcache.dll` có trong thư mục ext nhưng dòng
   `zend_extension=opcache` bị comment trong `php.ini`.
2. `[CHƯA BIẾT]` **Trạng thái OPcache trên production `aff.hoantien.xyz` chưa xác minh được**
   — không có cPanel/SSH trong phiên này. Không được phán đoán.
3. `[ĐO ĐẠC]` **Bật OPcache là thay đổi hiệu năng lớn nhất trong toàn bộ phạm vi khảo sát**:
   median request giảm **82–87%** trên mọi kịch bản đo.
4. `[ĐO ĐẠC]` **Fast Mode giảm thời gian người dùng chờ kết quả ~47% (OPcache OFF) và
   ~64% (OPcache ON)**: median 1468ms → 772ms, và 856ms → 312ms.
5. `[SUY LUẬN]` Fast Mode **không** cải thiện đáng kể latency thuần của `POST /link-requests`
   (vì Normal Mode gọi ProductData trong `afterResponse`, tức *sau khi* response đã gửi
   đi). Lợi ích thật của Fast Mode nằm ở: (a) giao diện không phải polling, (b) worker
   không bị giữ thêm 582–739ms HTTP ngoài mỗi item chưa cache.
6. `[ĐO ĐẠC]` **Không có regression**: full suite `3 failed / 1133 passed` so với baseline
   `3 failed / 1121 passed` — đúng +12 test mới, cùng 3 lỗi cũ.

---

## 2. Phạm vi & phương pháp

### 2.1 Phạm vi

- Fast Mode: **chỉ Shopee**, chỉ luồng direct (`POST /link-requests`).
- Không đụng tới: TikTok, Lazada, ShopeeFood, Tiki, cashback engine, queue, schema,
  cấu hình deploy, hạ tầng.

### 2.2 Phương pháp đo

| Lớp | Công cụ |
|---|---|
| Mã nguồn | Đọc trực tiếp controller/service/view hiện tại (nguồn chân lý, ưu tiên hơn tài liệu cũ) |
| OPcache runtime | File `.php` chạy trong **web SAPI** (mod_php), có token guard, localhost-only |
| Benchmark HTTP | `curl.exe` + HTTPS thật, đã đăng nhập thật, cookie thật |
| Chi phí server-side | `AFFILIATE_TIMING` (đang bật) ghi `[CACHE-Timing] Refresh Cache` |
| Test hồi quy | `php artisan test` (SQLite in-memory + `RefreshDatabase`) |

### 2.3 Nguyên tắc an toàn đã áp dụng

- Không sửa database production.
- Không deploy, không commit.
- Đổi `php.ini` local **có backup và đã khôi phục**, đã xác minh bằng so sánh byte.
- Toàn bộ fixture benchmark (user tạm, cache row, file chẩn đoán) đã dọn sạch.

---

## 3. Môi trường đo

`[ĐO ĐẠC]`

| Hạng mục | Giá trị |
|---|---|
| PHP version | 8.2.12 (ZTS) |
| Web SAPI | `apache2handler` (mod_php) |
| CLI SAPI | `cli` |
| PHP binary (web) | `C:\xampp\apache\bin\httpd.exe` |
| php.ini dùng chung | `C:\xampp\php\php.ini` (qua `PHPINIDir C:/xampp/php`) |
| `PHP_INT_MAX` | `9223372036854775807` |
| `ini_loaded` / `ini_scanned` / `user_ini_file` | `false` (không có file nạp thêm) |
| `public/.user.ini` | không tồn tại |
| APP_ENV / APP_DEBUG | `local` / `true` |
| DB | MySQL `127.0.0.1:3306`, db `hoantienaff` |
| SESSION / CACHE / QUEUE | `database` / `database` / `database` |
| `SESSION_COOKIE` | `hoantien_session_v2`, `SESSION_SECURE_COOKIE=true` |
| Domain đo | `https://hoantien.xyz` (self-signed, dùng `-k`) |
| Strategy (đọc từ DB) | `affiliate.dashboard.strategy = direct` |
| Affiliate ID | `17342330566` |

### 3.1 Ghi chú quan trọng về `SESSION_SECURE_COOKIE=true`

`[ĐO ĐẠC]` Do cookie bị gắn cờ `secure`, phiên chỉ được gửi lại qua **HTTPS**. Mọi
thử đo dùng `http://` trước đó đều rơi vào HTTP `419` vì browser/curl không gửi lại
cookie phiên. Đây là nguyên nhân gốc của các lần đo 419 trong audit trước, **không**
phải lỗi CSRF logic.

Ngoài ra token `XSRF-TOKEN` trong cookie jar bị URL-encode (`%3D%3D`), phải decode
mới dùng được làm header `X-XSRF-TOKEN`.

---

## 4. Phần A — Audit OPcache trên web SAPI

### 4.1 Cấu hình tĩnh

`[SOURCE]`

| Mục | Giá trị |
|---|---|
| `php_opcache.dll` | Có trong `C:\xampp\php\ext\` |
| `php.ini` dòng 964 | `;zend_extension=opcache` — **bị comment** |
| Các chỉ thị `opcache.*` | Đều bị comment, không có chỉ thị nào được đặt |
| `opcache.enable_cli` | comment (mặc định `0`) |

### 4.2 Runtime trên web SAPI (trước khi thay đổi)

`[ĐO ĐẠC]` chạy trong Apache/mod_php:

```
opcache_loaded           = NO
opcache_get_status: UNAVAILABLE (extension not loaded)
opcache_get_configuration: UNAVAILABLE
opcache.enable / memory_consumption / max_accelerated_files / ... = <not-set>
```

**Kết luận Phần A (local): OPcache đang TẮT hoàn toàn trên web SAPI.**
Các request phải parse lại toàn bộ mã PHP mỗi lần — đây là nguyên nhân gốc của
~500ms "sàn" thời gian request trong Phần 6.

### 4.3 Runtime sau khi bật (đo để benchmark, đã khôi phục)

`[ĐO ĐẠC]`

```
opcache_loaded           = YES
opcache_enabled          = true
opcache.memory_consumption        = 128   (MB)
opcache.max_accelerated_files     = 10000
opcache.optimization_level        = 0x7FFEBFFF
opcache.validate_timestamps       = 1
opcache.revalidate_freq           = 2   (giây)
opcache.jit                       = tracing
opcache.jit_buffer_size           = 0          <-- JIT thực tế KHÔNG chạy
opcache_get_status():  num_cached_scripts = 610
                        num_cached_keys    = 1190
                        hits               = 12203
                        misses             = 610
                        hit_rate           = 95.24 %
                        used_memory        = 23.5 MB / 128 MB
                        oom_restarts       = 0
```

`[SUY LUẬN]` Hit rate 95.24% là **cao và lành tính**. Nó cho thấy mã nguồn ổn định
và không có pattern `include`/`require` động hủy cache. `jit_buffer_size = 0` nghĩa là
JIT tracing được bật trên giấy nhưng không có bộ đệm nên không sinh mã JIT — nếu
muốn có lợi ích JIT phải cấp buffer riêng, **không** nên bật vội (xem §17).

---

## 5. Trạng thái OPcache trên production `aff.hoantien.xyz`

`[CHƯA BIẾT]` — không có bất kỳ đường truy cập cPanel/SSH/máy chủ production nào.

Những gì tài liệu cũ nói (LiteSpeed/cPanel) là **giả định lịch sử, chưa được xác minh
trong phiên này**, và không đủ để kết luận. Cần người dùng cung cấp một trong các
bằng chứng sau (mục §17.1):

1. Trang `phpinfo()`/`opcache_get_status()` trên chính domain aff.
2. `MultiPHP INI` → dấu tick "Zend OPcache" trong cPanel.
3. Output của `php -i` / `php -r 'var_dump(extension_loaded("Zend OPcache"));'` chạy trên
   đúng user FTP của aff (đúng SAPI, không phải CLI mặc định).
4. Hoặc public dir của aff có `.user.ini` chứa `zend_extension=opcache` không.

---

## 6. Benchmark OPcache OFF vs ON

### 6.1 Phương pháp

- HTTPS thật, đã đăng nhập thật bằng tài khoản benchmark tạm (đã xoá sau khi đo).
- Mỗi kịch bản có 3 request warm-up **không tính vào số liệu**.
- Số mẫu: **N = 20** cho kịch bản không gọi API ngoài; **N = 5** cho kịch bản có gọi
  resolver/ProductData thật (để giới hạn tải lên API bên thứ ba).
- Đơn vị: milliseconds. `time_total` của curl (đo từ client).
- Cùng bộ máy, cùng database, chỉ khác trạng thái OPcache.

### 6.2 Bảng kết quả

`[ĐO ĐẠC]`

| # | Kịch bản | N | OFF min | OFF med | OFF avg | OFF p95 | OFF max | ON min | ON med | ON avg | ON p95 | ON max | Δ median |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| A | `GET /login` (anon) | 20 | 424.9 | 534.4 | 551.4 | 667.4 | 685.0 | 50.0 | **75.5** | 88.2 | 159.7 | 166.7 | **−85.9%** |
| B | `GET /dashboard` (auth) | 20 | 508.7 | 690.0 | 700.5 | 824.8 | 869.7 | 83.2 | **116.2** | 137.4 | 209.1 | 217.1 | **−83.2%** |
| C | `POST /link-requests` **Fast** | 20 | 495.2 | 646.3 | 650.7 | 743.2 | 784.7 | 63.3 | **105.6** | 117.7 | 188.3 | 204.4 | **−83.7%** |
| D | `POST` Normal, cache HIT | 20 | 508.8 | 668.4 | 653.0 | 784.4 | 808.8 | 76.1 | **104.4** | 111.3 | 153.4 | 160.8 | **−84.4%** |
| E | `POST` Normal, cache MISS (ProductData thật) | 5 | 650.8 | 736.3 | 877.5 | 1494.3 | 1494.3 | 87.0 | **120.5** | 293.1 | 1017.0 | 1017.0 | **−83.6%** |
| F | `POST` **Fast**, cùng item miss của E | 20 | 506.3 | 643.9 | 650.6 | 760.7 | 764.8 | 62.9 | **102.8** | 105.2 | 142.4 | 153.2 | **−84.0%** |
| G | `POST` Normal, short link (resolver thật) | 5 | 668.5 | 757.4 | 1120.8 | 2642.0 | 2642.0 | 78.2 | **134.4** | 243.8 | 716.8 | 716.8 | **−82.3%** |
| H | `POST` **Fast**, short link (resolver thật) | 5 | 526.8 | 722.1 | 712.8 | 875.0 | 875.0 | 86.6 | **95.2** | 108.3 | 144.2 | 144.2 | **−86.8%** |

### 6.3 Nhận xét

1. `[ĐO ĐẠC]` OPcache giảm median **82–87%** trên *mọi* kịch bản, kể cả kịch bản
   không liên quan tới bất kỳ thay đổi nào của Fast Mode. Đây là phát hiện lớn nhất.
2. `[ĐO ĐẠC]` Sàn thời gian request giảm từ **~425–509ms** (OFF) xuống **~50–87ms** (ON).
   Sàn này là chi phí parse/compile mã PHP, đúng như dự đoán ở §4.2.
3. `[SUY LUẬN]` Vì Fast Mode chỉ là một nhánh rẽ nhỏ trong controller, **không thể** là
   nguyên nhân của mức giảm 6–7×. Nếu đóng góp của Fast Mode là lớn, ta sẽ thấy C
   và D (cùng stack, chỉ khác nhánh) lệch nhau đáng kể — thực tế chúng gần như bằng nhau
   ở cả hai trạng thái (xem §11).

---

## 7. Phần B — Fast Mode: thiết kế

### 7.1 Luồng Normal Mode hiện tại

`[SOURCE]` `DashboardCreateDirectLinkController@store`, nhánh Shopee:

```
validate
  -> LinkRequest::create(status = 'processing')      [ghi DB]
  -> UrlResolverService::resolve()                   [mạng nếu short link]
  -> isShopeeLanding()                               [giữ nguyên safety rule]
  -> AffiliateCacheService::extractItemId()          [thuần string parsing]
  -> AffiliateCacheService::get()                    [đọc DB]
       ├─ HIT  -> điền dữ liệu sản phẩm, status = completed, trả JSON
       └─ MISS -> buildAffiliateUrl()
                  -> dispatch(closure)->afterResponse()
                       -> ProductDataService::getByUrl()   [HTTP NGOÀI ~0.6–0.74s]
                       -> CashbackCalculator::calculate()
                       -> cập nhật link_requests
                  -> trả JSON status = processing
  -> Frontend poll /api/link-request/{id}  (300ms → 800ms → 2000ms)
```

### 7.2 Luồng Fast Mode

`[SOURCE]` Chèn ngay sau bước `extractItemId()`, trước khi đọc cache:

```
validate
  -> LinkRequest::create(status = 'processing')
  -> UrlResolverService::resolve()                   [GIỮ NGUYÊN]
  -> isShopeeLanding()                               [GIỮ NGUYÊN]
  -> AffiliateCacheService::extractItemId()          [GIỮ NGUYÊN]
  -> if (fast_mode) -> storeFastShopeeLink()
       -> buildAffiliateUrl()                        [CÙNG HÀM với Normal Mode]
       -> LinkRequest::update(affiliate_url, product_link, item_id, status=completed)
       -> JSON { affiliate_url, shopeedirect_url, item_id, status, fast_mode: true }
       -> KHÔNG đọc cache, KHÔNG ghi cache, KHÔNG ProductData,
          KHÔNG CashbackCalculator, KHÔNG dispatch afterResponse
  -> Frontend KHÔNG poll (return trước startPolling)
```

### 7.3 Các thứ cố ý KHÔNG làm trong Fast Mode

`[SOURCE]` — được chứng minh bằng `shouldNotReceive` trong `FastModeTest`:

| Không gọi | Bằng chứng test |
|---|---|
| `ProductDataService::getByUrl()` | `shouldNotReceive('getByUrl')` |
| `AffiliateCacheService::get()` | `shouldNotReceive('get')` |
| `AffiliateCacheService::put()` | `shouldNotReceive('put')` |
| `AffiliateCacheService::logMiss()` | `shouldNotReceive('logMiss')` |
| `CashbackCalculator::calculate()` | không có dispatch ⇒ closure không tồn tại |
| `dispatch(...)->afterResponse()` | không có terminating callback |
| AJAX ẩn / polling | `if (data.fast_mode) return;` **trước** `this.startPolling()` |

### 7.4 Quy tắc bất biến

`[SOURCE]`

1. **Cùng một quy tắc affiliate URL.** Fast Mode gọi đúng `buildAffiliateUrl()` mà
   Normal Mode dùng. Test `test_fast_and_normal_mode_produce_identical_affiliate_url`
   khẳng định hai chế độ cho ra **chuỗi affiliate URL giống hệt nhau** (cùng
   `affiliate_id`, `sub_id`, `origin_link`).
2. **Cùng resolver, cùng safety rule.** `resolve()` + `isShopeeLanding()` chạy trước,
   nên short link vẫn được resolve về landing URL thật trước khi tạo link — không tạo
   ra affiliate link sai gốc.
3. **Không đổi schema.** Dùng lại các cột có sẵn (`item_id`, `product_link`,
   `affiliate_url`, `status`). Không thêm migration.
4. **Không lưu cờ `fast_mode` vào DB.** Chế độ chỉ phản ánh ở response. Xem §15.3.

---

## 8. Phần B — Files thay đổi

`[ĐO ĐẠC]` `git diff --numstat`:

| File | + | − | Loại |
|---|---|---|---|
| `app/Http/Controllers/DashboardCreateDirectLinkController.php` | 76 | 1 | sửa |
| `resources/views/dashboard/partials/link-generator.blade.php` | 65 | 4 | sửa |
| `tests/Feature/FastModeTest.php` | mới | — | thêm (12 test) |

**Không có** thay đổi nào cho: migration, `config/*`, `routes/*`, `composer.json`,
`.env`, model, service khác, dependency mới.

> Các file `config/app.php` và `routes/web.php` hiện dirty trong working tree là
> **thay đổi có sẵn từ trước** (mục T2 debug), **không** thuộc Fast Mode và không
> bị đụng tới.

### 8.1 Chi tiết controller

`[SOURCE]`

- Thêm `$fastMode = $request->boolean('fast_mode');` (chỉ đọc request, không ảnh hưởng
  khi thiếu → `false`).
- Thêm 3 nhánh: `if ($fastMode) return $this->storeFastShopeeLink(...)` ngay trước
  `$this->cacheService->get($itemId)`.
- Thêm private method `storeFastShopeeLink()`.
- Thêm private helper `cleanShopeeProductUrl()` = `explode('?', $url)[0]`, và cho
  `buildAffiliateUrl()` dùng lại chính helper này. **Mục đích:** một nguồn sự thật duy
  nhất để Normal Mode và Fast Mode không lệch nhau về URL sản phẩm.
  Dòng `- $cleanUrl = explode('?', $resolvedUrl)[0];` → `+ $cleanUrl = $this->cleanShopeeProductUrl($resolvedUrl);`
  là **thay đổi thuần túy về hình thức, byte đầu ra không đổi** (xác nhận bằng test D).

---

## 9. Hợp đồng API Fast Mode

`[SOURCE]` JSON trả về (HTTP 200):

```json
{
  "success": true,
  "request_id": 1234,
  "platform": "Shopee",
  "affiliate_url": "https://shopee.vn/...&affiliate_id=17342330566&sub_id=tintuctonghop103&origin_link=...",
  "shopeedirect_url": "https://shopee.vn/product/59917031/56759033748",
  "item_id": 56759033748,
  "status": "completed",
  "fast_mode": true
}
```

| Trường | Ý nghĩa |
|---|---|
| `affiliate_url` | Link có tracking — dùng cho nút "Add giỏ / Mua ngay" và copy |
| `shopeedirect_url` | URL sản phẩm Shopee sạch (đã bỏ query) — dùng cho nút "Mở trang sản phẩm Shopee" |
| `item_id` | Số dạng số trong DB (cột `BIGINT`) |
| `status` | Luôn là `completed` (không cần vòng poll thứ hai) |
| `fast_mode` | Cờ để frontend biết dừng, không poll |

Lỗi resolver trong Fast Mode **giống hệt** Normal Mode: HTTP `422`,
`{"success": false, "error": "Không lấy được sản phẩm Shopee từ link rút gọn. Vui lòng thử lại."}`,
và `link_requests.status = failed` với `affiliate_url = NULL`.

---

## 10. Thay đổi giao diện

`[SOURCE]` `link-generator.blade.php`

### 10.1 Mobile-first

Toggle đặt ngay **dưới ô nhập link, trên nút submit** — vị trí tự nhiên cho ngón cái
trên màn hình nhỏ. Chỉ thấy khi cần, không chiếm chỗ khi người dùng chỉ muốn link.

```
┌──────────────────────────────────────┐
│ [ Dán link sản phẩm...          (x) ]│
├──────────────────────────────────────┤
│ ☑ ⚡ Chế độ nhanh                    │  <- mới
│   Tạo link nhanh, không tải thông     │
│   tin sản phẩm                        │
├──────────────────────────────────────┤
│ [      🚀 Tạo Link Ngay             ]│
└──────────────────────────────────────┘
```

Checkbox thật (`<input type="checkbox">` + `<label for>`) → bấm được cả vùng nhãn,
đạt chuẩn accessibility, không phụ thuộc JS. Có `x-bind:disabled="loading"`.

### 10.2 Kết quả

Fast Mode: ẩn card "bạn sẽ được hoàn ≈ Xđ" và ảnh sản phẩm; hiện banner hổ phách
"⚡ Link đã sẵn sàng" + nút **🛒 Mở trang sản phẩm Shopee**.
Normal Mode: UI **giữ nguyên 100%** như trước.

### 10.3 Mobile / an toàn hiển thị

- Cả hai nút h-12 (48px) / `max-[390px]:h-10`; nút Shopee mới cùng kích thước chạm.
- `x-show` (không phải `x-if`) ⇒ không phá vỡ cây DOM mà Alpine đang thao tác.
- Mọi URL escape qua `x-bind:href` ⇒ không có HTML injection từ dữ liệu API.
- `target="_blank"` đều kèm `rel="noopener noreferrer"`.

---

## 11. So sánh Normal Mode vs Fast Mode

### 11.1 Hành vi

| Hạng mục | Normal Mode | Fast Mode |
|---|---|---|
| Resolver + `isShopeeLanding` | ✅ | ✅ (không đổi) |
| `extractItemId` | ✅ | ✅ (không đổi) |
| Affiliate URL | `buildAffiliateUrl()` | `buildAffiliateUrl()` **cùng hàm** |
| Đọc `affiliate_cache` | ✅ | ❌ |
| Ghi `affiliate_cache` | ✅ (khi MISS) | ❌ |
| ProductData API | ✅ (khi MISS) | ❌ |
| Cashback calculator | ✅ | ❌ |
| `afterResponse` closure | ✅ (khi MISS) | ❌ |
| Số lần poll UI | ≥ 1 (khi MISS) | **0** |
| HTTP status | 200 | 200 |
| Ảnh sản phẩm / tên sản phẩm | ✅ | ❌ (cố ý) |
| Số tiền hoàn tiền | ✅ | ❌ (cố ý) |

### 11.2 Latency thuần của POST (cùng điều kiện)

`[ĐO ĐẠC]`

| Trạng thái | Normal (cache HIT) | Fast | Chênh lệch |
|---|---|---|---|
| OPcache OFF (median) | 668.4ms | 646.3ms | −22.1ms (−3.3%) |
| OPcache ON (median) | 104.4ms | 105.6ms | +1.2ms (nhiễu) |
| Normal (cache MISS) OFF | 736.3ms | 646.3ms | −90.0ms (−12.2%) |
| Normal (short link) ON | 134.4ms | 95.2ms | −39.2ms (−29.2%) |

`[SUY LUẬN]` Chênh lệch latency thuần **rất nhỏ và không nhất quán** (có kịch bản còn
chậm hơn). Lý do: Normal Mode gọi ProductData trong `afterResponse`, tức **sau khi
response đã được gửi cho client** — nên client không phải chờ. Bằng chứng: khoảng cách
giữa Normal-MISS và Fast chỉ ~90ms, trong khi log ghi ProductData mất **582–739ms**.

→ **Đừng kỳ vọng Fast Mode làm nhanh `POST` đi nửa giây.** Nó làm nhanh *trải nghiệm
người dùng* (§12) và *giảm tải server* (§13).

---

## 12. Benchmark quan trọng nhất — Time To Result (TTR)

Đây là chỉ số mà người dùng thật sự cảm nhận: thời gian từ lúc bấm submit đến khi thẻ
kết quả hiện đầy đủ. Script mô phỏng đúng vòng poll của UI
(`/api/link-request/{id}` mỗi 200ms cho tới khi `status` không còn `processing`).

`[ĐO ĐẠC]` N = 8 mỗi chế độ, cache bị xoá trước **mỗi** lần chạy để Normal Mode thật
sự gọi ProductData mỗi lần (không dựa vào cache tự làm ấm).

| OPcache | Chế độ | N | min | **median** | avg | max | Số lần poll |
|---|---|---|---|---|---|---|---|
| OFF | Normal | 8 | 1271ms | **1468ms** | 1490.1ms | 1862ms | 1 (luôn) |
| OFF | **Fast** | 8 | 662ms | **772ms** | 814.5ms | 1029ms | **0** |
| ON | Normal | 8 | 812ms | **856ms** | 955.5ms | 1611ms | 1 (luôn) |
| ON | **Fast** | 8 | 189ms | **312ms** | 315.2ms | 451ms | **0** |

### 12.1 Kết luận

1. `[ĐO ĐẠC]` **Fast Mode giảm thời gian chờ của người dùng ~47% (OFF) và ~64% (ON).**
   OPcache OFF: 1468ms → 772ms (−696ms). OPcache ON: 856ms → 312ms (−544ms).
2. `[ĐO ĐẠC]` Fast Mode **không bao giờ poll** (0 poll) — đúng yêu cầu "giao diện
   không polling". Normal Mode luôn cần ≥ 1 poll khi cache MISS.
3. `[ĐO ĐẠC]` p95 cải thiện mạnh: normal max 1862ms/1611ms → fast max 1029ms/451ms.
4. `[ĐO ĐẠC]` Fast Mode ổn định hơn nhiều (spread 662–1029 so với 1271–1862), vì
   không phụ thuộc độ trễ API bên thứ ba.
5. `[ĐO ĐẠC]` Hai tối ưu **cộng dồn**: Normal 1468ms → 312ms khi vừa bật OPcache vừa
   dùng Fast Mode (tổng −78.7%).

---

## 13. Ảnh hưởng phía server

### 13.1 Chi phí bị loại bỏ

`[ĐO ĐẠC]` Từ log `AFFILIATE_TIMING`, các lần gọi ProductData thật trong phiên này:

| item_id | elapsed_ms |
|---|---|
| 56759033748 | 582 |
| 56759033748 | 617 |
| 56759033748 | 638 |
| 40372404434 | 725 |
| (một mục khác) | 739 |

→ **582–739ms** công việc HTTP ngoài bị chiếm giữ bởi worker sau khi response đã gửi.

### 13.2 Vì sao đây vẫn quan trọng

`[SUY LUẬN]` Với mod_php trên Apache kiểu prefork, worker vẫn bận cho tới khi script
kết thúc. Người dùng thứ hai gửi request trong khoảng 600–740ms đó sẽ xếp hàng chờ
(cùng host, cùng PHP). Fast Mode cắt sạch khoảng thời gian đó **cho mọi request Shopee
mà người dùng chọn chế độ nhanh**.

### 13.3 Cảnh báo: tự làm ấm cache

`[ĐO ĐẠC]` Trong kịch bản E (N=5 Normal + cache MISS), chỉ **1** lần gọi ProductData
được ghi nhận — 4 lần sau là cache HIT vì request đầu đã điền cache.

Hệ quả quan trọng: **Normal Mode chỉ tốn 1 lần ProductData cho mỗi item mỗi ngày.**
Vì vậy:

- Fast Mode **không** phải giải pháp cho tình huống "mọi request đều chậm" — phần lớn
  request thực tế vốn đã là cache HIT và vốn đã nhanh.
- Fast Mode là **lựa chọn theo ý muốn người dùng** ("tôi chỉ cần link, không cần
  xem hoàn tiền"), và là **cơ chế phòng vệ** khi API bên thứ ba chậm hoặc chết.

---

## 14. Kết quả kiểm thử

### 14.1 Baseline trước khi thay đổi

`[ĐO ĐẠC]` `3 failed, 1121 passed (3974 assertions)`, 123.38s.

Ba lỗi có sẵn (không liên quan Fast Mode):

| # | Test | Lỗi |
|---|---|---|
| 1 | `Tests\Feature\Auth\RegistrationTest > new users can register` | expected true, got false |
| 2 | `Tests\Feature\Services\ShopeeFood\ShopeeFoodOrderSyncServiceTest > cookie guard fails fast without http` | `config_missing` vs `invalid_json` |
| 3 | `Tests\Feature\TikTokSyncPhase3Test > preexisting credited order is not credited again by admin` | expected 1, got 5 |

### 14.2 Sau khi thay đổi

`[ĐO ĐẠC]` `3 failed, 1133 passed (4044 assertions)`, 129.75s.

- **+12 test** (đúng bằng số test mới), **+70 assertion**.
- **Y hệt 3 lỗi cũ**, cùng thông điệp, cùng dữ kiện ⇒ **không có regression**.

### 14.3 Bộ test tập trung

`[ĐO ĐẠC]` `57 passed (269 assertions)` — gồm 12 test Fast Mode mới + 45 test hồi quy
link/dashboard/resolver có sẵn.

### 14.4 12 test Fast Mode

| Test | Bảo vệ điều gì |
|---|---|
| `test_harness_normal_mode_still_calls_productdata` | **Quan trọng**: chứng minh harness thật sự bắt được lời gọi ProductData. Nếu test này fail, mọi test "không gọi" bên dưới là pass giả. |
| `test_fast_mode_creates_link_without_productdata` | Không ProductData, không cache, dữ liệu enrichment null, `shopeedirect_url` đúng |
| `test_fast_mode_short_link_uses_resolver_and_real_product_url` | Short link → resolver dùng, `origin_link` là URL sản phẩm thật, không chứa `shp.ee`, đúng `affiliate_id`/`sub_id` |
| `test_fast_mode_resolver_failure_returns_same_error` | 422 giống hệt Normal Mode, `affiliate_url` NULL, `status=failed` |
| `test_fast_and_normal_mode_produce_identical_affiliate_url` | **Bất biến quan trọng nhất**: hai chế độ cho affiliate URL giống hệt |
| `test_fast_mode_does_not_write_affiliate_cache` | Không sinh row cache mới |
| `test_fast_mode_flag_absent_keeps_normal_behaviour` | Normal Mode vẫn gọi ProductData (`once()`), không có `fast_mode` trong response |
| `test_normal_mode_cache_hit_still_returns_product_data` | Cache HIT vẫn trả tên sản phẩm + tiền hoàn |
| `test_fast_mode_flag_is_ignored_for_non_shopee_urls` | Non-Shopee bỏ qua cờ, không gọi resolver |
| `test_anonymous_user_cannot_use_fast_mode` | 401, không tạo row |
| `test_fast_mode_rejects_invalid_url` | Validate trước, 422, không tạo row |
| `test_fast_mode_toggle_is_rendered_in_view` | UI có toggle, gửi cờ, và `if (data.fast_mode)` **đứng trước** `startPolling()` |

### 14.5 Phát hiện kỹ thuật đáng lưu ý

`[SOURCE]` Trong test, closure `afterResponse` chạy **đồng bộ ngay trong lời gọi HTTP**,
vì `MakesHttpRequests::call()` gọi `$kernel->terminate()` ngay sau khi xử lý. Điều này
rất có lợi: lời gọi ProductData luôn xảy ra trước khi test kết thúc nên bị Mockery bắt
chắc chắn.

Lưu ý: **không** gọi `app()->terminate()` thủ công trong test. `Application::terminate()`
**không** xoá danh sách callback, nên gọi thêm sẽ chạy lại closure và làm
`->once()` fail. Đã ghi rõ trong docblock của `FastModeTest`.

---

## 15. Rủi ro & giới hạn

### 15.1 Rủi ro đã biết

| # | Rủi ro | Mức | Giảm thiểu |
|---|---|---|---|
| R1 | Người dùng bật nhầm Fast Mode và tưởng sẽ thấy tiền hoàn | Thấp | UI ghi rõ "không tải thông tin sản phẩm" + banner kết quả nói rõ không tính hoàn tiền; mặc định **TẮT** |
| R2 | Fast Mode tạo nhiều row mà không lấp cache → Normal Mode sau đó vẫn phải gọi ProductData | Thấp | Chấp nhận có chủ đích; cache vẫn tự làm ấm bởi traffic Normal |
| R3 | Không có cờ `fast_mode` trong DB, nên `/api/link-request/{id}` không phân biệt được chế độ | Thấp | Fast Mode không poll nên không cần; client tái sử dụng link cũ sẽ thấy `status=completed` ngay |
| R4 | `item_id` ép về `int` | Rất thấp | **Rủi ro có sẵn**, không do Fast Mode: `extractItemId(): ?int` đã dùng ở Normal Mode. ID Shopee quan sát được 11 chữ số, xa `PHP_INT_MAX` |
| R5 | Đổi tracking/affiliate ID do "lệch logic" | **Đã loại** | Cùng gọi `buildAffiliateUrl()`; có test so sánh byte-for-byte giữa hai chế độ |

### 15.2 Giới hạn của phép đo

| # | Giới hạn |
|---|---|
| L1 | **Chỉ đo trên local XAMPP (mod_php, PHP 8.2.12)**, không phải production (LiteSeries/cPanel theo tài liệu cũ). Cấu hình khác có thể cho số khác. |
| L2 | HTTPS self-signed (`-k`), có overhead TLS nhỏ và bất thường. |
| L3 | N=5 cho kịch bản gọi API ngoài (giới hạn tải lên dịch vụ bên thứ ba) → p95/max của E, G, H kém chắc chắn hơn. |
| L4 | Chỉ có **2 item Shopee thật** dùng được, nên phải xoá cache thủ công trước mỗi lần chạy để ép cache MISS. |
| L5 | Đo bằng curl, không phải trình duyệt thật ⇒ không gồm thời gian render JS/Alpine, không gồm thao tác người dùng. |
| L6 | TTR mô phỏng vòng poll ở **200ms**; UI thật dùng lịch 300ms → 800ms → 2000ms nên TTR thật có thể cao hơn một chút (hậu quả: bằng chứng "nhanh hơn" chỉ mạnh hơn). |
| L7 | Không đo tải đồng thời (concurrency) ⇒ **không** kết luận được về throughput dưới tải nặng. |
| L8 | Chưa đo trên production ⇒ mọi khuyến nghị bật OPcache trên aff vẫn là **khuyến nghị, chưa phải sự kiện đã đo**. |

### 15.3 Điều Fast Mode cố ý **không** làm

Để người đọc không hiểu nhầm là "thiếu sót":

- Không đổi sơ đồ DB, không thêm cột `fast_mode`.
- Không thêm queue/worker/Horizon (sẽ là thay đổi hạ tầng, ngoài phạm vi).
- Không thêm cache riêng cho Fast Mode.
- Không hỗ trợ Fast Mode cho ShopeeFood/TikTok/Lazada/Tiki (ngoài phạm vi, và mỗi
  nền tảng có luồng riêng).

---

## 16. Rollback

### 16.1 Fast Mode (mã nguồn)

Không cần migration, không cần dọn dữ liệu. Ba lệnh:

```powershell
git checkout -- app/Http/Controllers/DashboardCreateDirectLinkController.php `
                resources/views/dashboard/partials/link-generator.blade.php
Remove-Item tests/Feature/FastModeTest.php
```

Cache views sau khi rollback: `php artisan view:clear && php artisan config:clear`.

Trạng thái Normal Mode trở lại **đúng như trước** — đã chứng minh bằng 45 test hồi quy
+ full suite.

### 16.2 Dữ liệu do Fast Mode tạo

Fast Mode tạo row `link_requests` với `status='completed'`, `product_name = NULL`,
`user_estimated_cashback = NULL`. **Đây là dữ liệu hợp lệ** và không gây hỏng: Normal
Mode vẫn đọc được. Câu lệnh dọn nếu khách muốn (chạy trên bản sao trước):

```sql
-- Xem trước:
SELECT id, user_id, created_at FROM link_requests
WHERE product_name IS NULL AND affiliate_url IS NOT NULL AND created_at >= '2026-09-28';

-- Xoá (nếu cần):
-- DELETE FROM link_requests WHERE product_name IS NULL AND affiliate_url IS NOT NULL;
```

Không có thay đổi nào lên `affiliate_cache` từ Fast Mode (đã assert bằng test).

### 16.3 OPcache (nếu đã bật trên production)

Bỏ tick `Zend OPcache` trong cPanel `MultiPHP INI`, hoặc xoá dòng
`zend_extension=opcache` trong `.user.ini` của public dir. Hiệu lực ngay, không cần
restart nếu dùng `.user.ini` (`user_ini_cache_ttl` mặc định 300s).

---

## 17. Khuyến nghị tiếp theo (CHƯA thực hiện)

Xếp theo mức đáng giá. **Không mục nào đã được thực hiện trong phiên này.**

### 17.1 P0 — Xác minh OPcache trên production aff

Không làm được từ máy dev. Cần một trong bằng chứng ở §5. Đây là việc có giá trị cao
nhất và rẻ nhất: nếu aff cũng đang TẮT như local, bật lên sẽ mang lại phần lớn
~83–87% cải thiện đã đo ở §6 mà **không đụng mã nguồn**.

### 17.2 P1 — Chuẩn hoá cấu hình OPcache nếu bật

Nếu quyết định bật, nên đặt tường minh thay vì để mặc định:

| Chỉ thị | Gợi ý | Vì sao |
|---|---|---|
| `opcache.enable` | `1` | rõ ràng |
| `opcache.enable_cli` | `0` | không tốn bộ nhớ cho CLI |
| `opcache.memory_consumption` | `256` | local chỉ dùng 23.5/128MB; 256 cho dư đệm, đặc biệt nếu có nhiều worker |
| `opcache.max_accelerated_files` | ≥ `20000` | local đạt 610 file; con số này có thể thấp hơn bạn nghĩ trên app lớn hơn |
| `opcache.validate_timestamps` | `1` (giữ) | an toàn khi còn deploy; cân nhắc `revalidate_freq=60` + tắt validate khi deploy ổn định |
| `opcache.jit_buffer_size` | **để 0** | xem §17.5 |

### 17.3 P2 — Preload các file nóng (tùy chọn, sau khi ổn định)

`opcache.preload` cho phép nạp sẵn vào shared memory, giúp giảm thêm cold request.
Chỉ nên làm khi deploy không còn thay đổi file thường xuyên, vì preload rẻ giữa các
request nhưng có chi phí cho mỗi lần restart.

### 17.4 P2 — Sửa 3 lỗi test tồn đọng

Không thuộc Fast Mode nhưng đang làm đỏ CI và làm giảm giá trị của việc phát hiện
regression thật:
- `RegistrationTest > new users can register`
- `ShopeeFoodOrderSyncServiceTest > cookie guard fails fast without http`
- `TikTokSyncPhase3Test > preexisting credited order is not credited again by admin`

### 17.5 P3 — Cân nhắc JIT, nhưng chưa vội

`[ĐO ĐẠC]` Local có `opcache.jit=tracing` nhưng `jit_buffer_size=0` ⇒ không JIT.
Bật JIT cần memory riêng và có thể **làm chậm** các app dùng nhiều reflection/closures
(đúng trường hợp Laravel). Chỉ thử khi: (a) OPcache đã bật ổn định, (b) có công cụ so
sánh trước/sau, (c) sẵn sàng rollback. **Không** coi là mặc định.

### 17.6 P3 — Cân nhắc hạ tầng thay cho micro-opt

Nếu sau khi bật OPcache mà vẫn cần thêm tốc độ, các hướng có giá trị lớn hơn nhiều:
prefork → event-driven (mod_php nặng), đưa việc nặng ra khỏi request, cache kết quả
ProductData ở tầng dài hạn. Đây là thay đổi hạ tầng, **ngoài phạm vi** yêu cầu hiện tại.

### 17.7 P3 — Đưa kịch bản benchmark vào tài liệu vận hành

Các con số ở §6 và §12 nên được đo lại định kỳ sau mỗi lần deploy lớn, vì sàn ~500ms
không có OPcache là thứ dễ quên nhất.

---

## 18. Phụ lục

### 18.1 Cách tái lập benchmark

```powershell
# 1. Backup rồi bật OPcache
Copy-Item C:\xampp\php\php.ini C:\temp\php.ini.backup
(Get-Content C:\xampp\php\php.ini -Raw) `
  -replace '(?m)^;zend_extension=opcache', 'zend_extension=opcache' `
  | Set-Content C:\xampp\php\php.ini -NoNewline
Restart-Service Apache2.4

# 2. Xác minh trên WEB SAPI (không dùng CLI)
#    -> file .php trong public/, in extension_loaded('Zend OPcache') + opcache_get_status()

# 3. Đo (đã đăng nhập thật qua HTTPS)
powershell -ExecutionPolicy Bypass -File bench_run.ps1 -Label opcache-on -Samples 20
powershell -ExecutionPolicy Bypass -File bench_ttr.ps1 -Samples 8

# 4. KHÔI PHỤC
Copy-Item C:\temp\php.ini.backup C:\xampp\php\php.ini -Force
Restart-Service Apache2.4
```

Ba bẫy đã gặp và đã xử lý (kinh nghiệm để lần sau):

1. `SESSION_SECURE_COOKIE=true` ⇒ phải dùng **HTTPS**, nếu không sẽ 419.
2. Cookie jar mã hoá `XSRF-TOKEN` thành `%3D%3D` ⇒ phải URL-decode trước khi gửi header.
3. PowerShell 5.1 làm hỏng dấu nháy kép trong tham số native ⇒ ghi body ra **file** rồi
   dùng `--data-binary @file`, không truyền `-d '{...}'`.

### 18.2 Fixture đã tạo và đã dọn

| Fixture | Vị trí | Trạng thái |
|---|---|---|
| User benchmark | local DB, `bench_fastmode@local.test` | **đã xoá** (2 lần dọn, tổng 166 link_requests + 5 sessions + 2 user) |
| Cache row benchmark | local DB, `affiliate_cache` hôm nay | **đã xoá** (6 row cũ hơn được giữ nguyên) |
| File chẩn đoán OPcache | `public/_ocdiag_tokenonly.php` | **đã xoá** |
| `php.ini` | `C:\xampp\php\php.ini` | **đã khôi phục, đã so sánh byte** |
| Cookie jar rơi rác | `.txt` ở thư mục gốc | **đã xoá** |
| Script benchmark | `%TEMP%\opencode\bench_*.php|ps1` | ngoài repo, không ảnh hưởng source |

Không có thay đổi nào lên production. Không có commit nào được tạo.

### 18.3 Dữ liệu thô

- `bench_opcache-off.csv`, `bench_opcache-on.csv` — 8 kịch bản × 5 thống kê
- `bench_ttr.csv` — 16 lần chạy TTR (8 normal + 8 fast) theo trạng thái OPcache

Các file này nằm ngoài repo tại `%TEMP%\opencode\`.

### 18.4 Mã nguồn tham chiếu

| Ký hiệu | Vị trí |
|---|---|
| Điểm vào | `routes/web.php` → `POST /link-requests` → `DashboardController@store` |
| Logic Fast Mode | `app/Http/Controllers/DashboardCreateDirectLinkController.php` — `storeFastShopeeLink()`, `cleanShopeeProductUrl()`, `buildAffiliateUrl()` |
| Resolver | `app/Services/UrlResolverService.php` — `resolve()`, `isShopeeLanding()` |
| Cache | `app/Services/AffiliateCacheService.php` — `get()`, `put()`, `extractItemId()` |
| API ngoài | `app/Services/ProductDataService.php` — `getByUrl()` |
| UI | `resources/views/dashboard/partials/link-generator.blade.php` |
| Test | `tests/Feature/FastModeTest.php` |
