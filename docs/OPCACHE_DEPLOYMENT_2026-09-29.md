# BÁO CÁO TRIỂN KHAI OPcache — 2026-09-29

**Mục tiêu:** Đổi cấu hình OPcache trên production `hoantien.xyz` sang bộ giá trị thận trọng được yêu cầu
(`memory_consumption=128`, `max_accelerated_files=10000`, `save_comments=1`), restart Apache, và xác minh
thông qua **web SAPI thật** (`apache2handler`) chứ không phải CLI.

**Kết quả cuối: PASS** (xem [11. Phán quyết](#11-phán-quyết))

> **Lưu ý quan trọng về BEFORE:** BEFORE của lần này **KHÔNG phải OPcache tắt**.
> OPcache đã được bật từ một lần triển khai được-authorize trước đó ở mức `192 MB / 20000 files`.
> Vì vậy so sánh BEFORE/AFTER ở đây là `192→128 MB` và `20000→10000 files`, **không phải** off→on.
> Dữ liệu off→on thật nằm trong báo cáo `docs/OPCACHE_DEPLOYMENT_AND_PERFORMANCE_2026-09-28.md`.

---

## 1. Environment

| Hạng mục | Giá trị |
|---|---|
| PHP | **8.2.12** (x64, **ZTS / Thread Safety = on**) |
| PHP SAPI (web) | **`apache2handler`** (xác minh qua HTTP, không phải CLI) |
| Apache | **2.4.58 (Win64)**, OpenSSL 3.1.3 |
| php.ini đang dùng | **`C:\xampp\php\php.ini`** |
| Nguồn xác định php.ini | `httpd-xampp.conf:41` → `PHPINIDir "C:/xampp/php"` |
| Laravel | **12.62.0** (`composer.json` yêu cầu `^12.0`) |
| `php_opcache.dll` | `C:\xampp\php\ext\php_opcache.dll`, 848,896 bytes, SHA-256 `4530FFCF2C16C9FB3B97B453603C787D5FBCB248492F80D35407F63CCFF76E` |
| DocumentRoot | `C:/xampp/htdocs/hoantienaff/public` (khớp vhost) |
| Vhost | `ServerName hoantien.xyz`, `:80` và `:443` |
| Topology | `https://hoantien.xyz` → Cloudflare → `cloudflared` → `http://localhost` → Apache vhost → `public/` |
| Apache service | `Apache2.4`, `Running`, `StartMode = Automatic` |
| `php --ini` | Chỉ `C:\xampp\php\php.ini`; **không có** scan directory bổ sung |
| `PHPRC` / `PHP_INI_SCAN_DIR` | **Không được đặt** ở machine/user/process env |
| Múi giờ | OS local = UTC+7; `date.timezone` PHP = `Europe/Berlin` (UTC+2) |

**Về múi giờ:** probe dùng `date()` nên các chuỗi `start_time` hiển thị theo `Europe/Berlin`.
`2026-09-29 03:16:07` (probe) = `2026-09-29 01:16:07` UTC = **`2026-09-29 08:16:07` OS local** — khớp
đúng thời điểm restart. Mốc thời gian authoritative trong báo cáo này là **giờ OS local**.

---

## 2. BEFORE

Đo bằng probe web `public/opcache-check.php` (đã xóa sau khi dùng), qua `https://hoantien.xyz`.

### 1.1. `ini_get()` trên web SAPI

| Directive | BEFORE | Đánh giá |
|---|---|---|
| `opcache.enable` | `1` | OK |
| `opcache.enable_cli` | `0` | OK |
| `opcache.memory_consumption` | **`192`** | ❌ sai spec (cần `128`) |
| `opcache.interned_strings_buffer` | `16` | OK |
| `opcache.max_accelerated_files` | **`20000`** | ❌ sai spec (cần `10000`) |
| `opcache.validate_timestamps` | `1` | OK |
| `opcache.revalidate_freq` | `2` | OK |
| `opcache.save_comments` | `1` | ⚠️ đúng giá trị nhưng là **default**, chưa có dòng tường minh |
| `opcache.jit` | `disable` | ⚠️ tương đương về hành vi, nhưng spec yêu cầu `off` |
| `opcache.jit_buffer_size` | `0` | OK |

### 1.2. `opcache_get_status()` (cache dài hạn đã ấm)

| Metric | BEFORE |
|---|---|
| `opcache_enabled` | `true` |
| `cache_full` | `false` |
| `num_cached_scripts` | **1052** |
| `hits` / `misses` | **12,758,430** / 1,062 |
| `opcache_hit_rate` | 99.99% |
| `oom_restarts` | **0** |
| `hash_restarts` | 0 |
| `manual_restarts` | 0 |
| `blacklist_misses` | 0 |
| `memory_used` | **42,132,784 B (40.19 MB)** |
| `memory_free` | 159,145,560 B (151.80 MB) |
| `memory_wasted` | 0 |
| `jit` enabled / on / buffer | `false` / `false` / `0` |
| `start_time` | `2026-09-28 18:22:45` (Berlin) = **2026-09-28 23:22:45 OS local** |

### 1.3. Số liệu HTTP (nhẹ, 5 mẫu mỗi endpoint — **không** phải benchmark lớn)

| Endpoint | min | median | max |
|---|---|---|---|
| `/login` | 53.6 ms | **53.7 ms** | 155.1 ms |
| `/dashboard` (có cookie) | 63.6 ms | **70.5 ms** | 94.5 ms |

### 1.4. Tạo link (1 record: `id=3274`)

| Metric | BEFORE |
|---|---|
| HTTP | `200` |
| TTFB | 87.211 ms |
| Total (curl) | **841.266 ms** |
| Total (stopwatch) | 945.6 ms |
| Trạng thái ban đầu | `processing` → `completed` |
| Poll | `200`, 65.752 ms, `completed` + đầy đủ product data |
| Cache state | `affiliate_cache` **MISS** (mới rollover sang `2026-09-29`) → chạy **full normal flow** có phân giải product |

Giá trị trả về: `price=293000`, `est cashback=7325.00`, `user cashback=3296.00`, `rate=0.50`.

### 1.5. Baseline OPcache-off thật (tham chiếu, từ lần trước)

| Flow | OFF | ON |
|---|---|---|
| `/login` | 379.7 ms | 48.7 ms |
| `/dashboard` | 458.4 ms | 114.2 ms |
| Poll | 366.2 ms | 62.0 ms |
| Cache HIT POST | 431.8 ms | 101.7 ms |
| Cache MISS POST | 1088.6 ms | 724.3 ms |
| Short-link POST | 413.9 ms | 77.4 ms |
| Server-side cache-HIT TTR proxy | 798.0 ms | 163.7 ms |

Nguồn: `docs/OPCACHE_DEPLOYMENT_AND_PERFORMANCE_2026-09-28.md` (toàn bộ sample HTTP 200, `completed`).

---

## 3. Changes

**File duy nhất bị thay đổi: `C:\xampp\php\php.ini`** — đúng **4 dòng**.

| Dòng | Trước | Sau |
|---|---|---|
| 1808 | `opcache.memory_consumption=192` | `opcache.memory_consumption=128` |
| 1815 | `opcache.max_accelerated_files=20000` | `opcache.max_accelerated_files=10000` |
| 1834 | `opcache.jit=disable` | `opcache.jit=off` |
| 1842 | `;opcache.save_comments=1` | `opcache.save_comments=1` |

### 3.1. Xác thực directive TRƯỚC khi sửa (không giả định)

Tất cả 10 directive trong spec được nạp thử với PHP 8.2.12 trước khi đụng vào file:

```
DIRECTIVE                        WANT     READBACK     STATUS
opcache.enable                   1        '1'          SUPPORTED
opcache.enable_cli               0        '0'          SUPPORTED
opcache.memory_consumption       128      '128'        SUPPORTED
opcache.interned_strings_buffer  16       '16'         SUPPORTED
opcache.max_accelerated_files    10000    '10000'      SUPPORTED
opcache.validate_timestamps      1        '1'          SUPPORTED
opcache.revalidate_freq          2        '2'          SUPPORTED
opcache.save_comments            1        '1'          SUPPORTED
opcache.jit_buffer_size          0        '0'          SUPPORTED
```

`opcache.jit=off` được PHP chấp nhận chính thức — thông báo lỗi của PHP liệt kê:
`Should be "disable", "on", "off", "tracing", "function" or 4-digit number`.

**Negative control** (để chứng minh việc "không có warning" là có ý nghĩa):
`opcache.jit=bogus_xyz` → PHP **có** warning. Vậy validator thực sự đang hoạt động.

### 3.2. Phân tích hành vi thực tế của `opcache.jit` (đo, không đoán)

Đo với `jit_buffer_size=64M` để JIT **thật sự có thể kích hoạt**:

| `opcache.jit` | `ini_get()` | `status.jit_enabled` | `status.jit_on` | buffer thực tế |
|---|---|---|---|---|
| `off` | `''` | **false** | false | **0** |
| `disable` | `'disable'` | **false** | false | 0 |
| `0` | `'0'` | **true** ← | false | **67,108,048 (64 MB)** ⚠️ |
| `tracing` | `'tracing'` | true | true | 67,108,048 |
| `on` | `'1'` | true | true | 67,108,048 |

Kết luận:
- `off` **được PHP honor đúng** — JIT tắt thật, buffer = 0.
- `ini_get('opcache.jit')` trả `''` với chữ `off` là ** quirks báo cáo của PHP**, không phải lỗi cấu hình.
  Vì vậy mốc authoritative là `status.jit_enabled = false`, không phải chuỗi `ini_get`.
- **Cảnh báo quan trọng:** nếu dùng `0` thay vì `off`, PHP **vẫn cấp phát 64 MB JIT buffer** →
  tốn 64 MB RAM vô ích. Spec dùng `off` là đúng và an toàn hơn. **Không** tự đổi sang giá trị khác.

### 3.3. Kiểm tra trùng lặp

| Kiểm tra | Kết quả |
|---|---|
| `zend_extension=opcache` (dòng 964) | **đúng 1 lần** |
| Số file `php.ini` dưới `C:\xampp\php` | 1 (`php.ini`; 2 file mẫu `-development`/`-production`) |
| Scan directory bổ sung | không có |
| Mỗi directive `opcache.*` uncommented | **đúng 1 lần** (10/10) |
| Comment cũ còn sót của 4 directive đã sửa | **0** |

### 3.4. Đối chiếu file

| Metric | Trước | Sau |
|---|---|---|
| Số dòng | 2022 | 2022 (delta 0) |
| Kích thước | 76,867 B | **76,862 B** (−5 B, khớp chính xác 4 chỉnh sửa) |
| SHA-256 | `29A7A0B94BB2B4E1FE52BF554D6C0FC62DE683A94431B78C3CBF3E2B99F759AC` | `8FF4511CBABBEB1C8C5B2AE5D1AD0B9AAEB610BDD058BD74AFF67A0D825A4B54` |

Diff cấp dòng: **đúng 4 dòng thay đổi, không có dòng nào khác.**

### 3.5. Sau khi sửa (validate bằng chính php.ini mới)

```
LOADED=C:\xampp\php\php.ini
opcache.memory_consumption   = '128'
opcache.max_accelerated_files= '10000'
opcache.save_comments        = '1'
opcache.jit                  = ''
opcache.jit_buffer_size      = '0'
cfg.memory_consumption       = 134217728 bytes (128 MB)
cfg.max_accelerated_files    = 10000
cfg.save_comments            = true
status.jit_enabled           = false
status.jit_buffer_size       = 0
status.opcache_enabled       = true
```

**Không có warning / notice / deprecated nào.**

---

## 4. Restart

Lệnh duy nhất được dùng (đúng yêu cầu, **không** dùng `httpd.exe -k stop`):

```powershell
Restart-Service -Name "Apache2.4" -Force
```

| Metric | Trước restart | Sau restart |
|---|---|---|
| Service | Running | **Running** |
| Parent PID | 5184 | **20680** |
| Child PID | 27948 | **26592** |
| PID giữ port 80/443 | 5184 | **20680** |
| Start time (OS local) | 2026-09-28 23:22:45 | **2026-09-29 08:16:07** |
| PID cũ còn sống | — | **không** (đã chết hẳn) |

`C:\xampp\apache\logs\error.log` — chỉ ghi thêm **1,766 bytes**, toàn bộ là notice bình thường:

```
AH00422: Parent: Received shutdown signal -- Shutting down the server.
AH00364: Child: All worker threads have exited.
AH00430: Parent: Child process 27948 exited successfully.
AH00455: Apache/2.4.58 (Win64) OpenSSL/3.1.3 PHP/8.2.12 configured -- resuming normal operations
AH00418: Parent: Created child process 26592
AH00354: Child: Starting 150 worker threads.
```

**Không có** PHP startup warning, không có lỗi module, không có lỗi config.

Hai dòng `ssl:warn AH01909 ... www.example.com:443` là của vhost mặc định `www.example.com` của
XAMPP, **đã tồn tại từ trước và không liên quan** đến thay đổi này (vhost production là `hoantien.xyz`).

---

## 5. AFTER

Đo lại qua **web SAPI thật** (`https://hoantien.xyz/opcache-check.php?k=<token>`), probe đã xóa sau đó.

### 5.1. Xác nhận đúng web SAPI + đúng php.ini

| Metric | AFTER |
|---|---|
| `PHP_SAPI` | **`apache2handler`** |
| `PHP_VERSION` | 8.2.12 |
| `php_ini_loaded_file()` | **`C:\xampp\php\php.ini`** |
| `php_ini_scanned_files` | (rỗng — không có ini bổ sung) |
| `server_software` | `Apache/2.4.58 (Win64) OpenSSL/3.1.3 PHP/8.2.12` |
| `https` / port / server_name | `true` / `443` / `hoantien.xyz` |
| `DOCUMENT_ROOT` | `C:/xampp/htdocs/hoantienaff/public` |
| `zend_thread_safe` | `true` |
| `extension_loaded('Zend OPcache')` | **`true`** |
| `function_exists('opcache_get_status')` | `true` |

### 5.2. `ini_get()` + `opcache_get_configuration()` — đối chiếu 100% với spec

| Directive | Spec | `ini_get()` (web) | `get_configuration()` | Khớp |
|---|---|---|---|---|
| `opcache.enable` | `1` | `1` | `true` | ✅ |
| `opcache.enable_cli` | `0` | `0` | `false` | ✅ |
| `opcache.memory_consumption` | `128` | `128` | `134217728` (128 MB) | ✅ |
| `opcache.interned_strings_buffer` | `16` | `16` | `16` | ✅ |
| `opcache.max_accelerated_files` | `10000` | `10000` | `10000` | ✅ |
| `opcache.validate_timestamps` | `1` | `1` | `true` | ✅ |
| `opcache.revalidate_freq` | `2` | `2` | `2` | ✅ |
| `opcache.save_comments` | `1` | `1` | `true` | ✅ |
| `opcache.jit` | `off` | `''` (xem 3.2) | `''` | ✅ (honor đúng) |
| `opcache.jit_buffer_size` | `0` | `0` | `0` | ✅ |

### 5.3. `opcache_get_status()` — ngay sau restart (cache lạnh)

| Metric | AFTER (cold) |
|---|---|
| `opcache_enabled` | **`true`** |
| `cache_full` | **`false`** |
| `restart_pending` / `restart_in_progress` | `false` / `false` |
| `num_cached_scripts` | 610 |
| `num_cached_keys` / `max_cached_keys` | 1,190 / 16,229 |
| `hits` / `misses` | 6,711 / 610 |
| `opcache_hit_rate` | 91.67% (cache vừa khởi động) |
| `oom_restarts` | **0** |
| `hash_restarts` | **0** |
| `manual_restarts` | 0 |
| `blacklist_misses` | 0 |
| `memory_used` | **33,022,120 B (31.49 MB)** |
| `memory_free` | 101,195,608 B (96.51 MB) |
| `memory_wasted` / `current_wasted_percentage` | 0 B / 0% |
| `jit` enabled / on / buffer | **`false` / `false` / `0`** |
| `start_time` | `2026-09-29 03:16:07` (Berlin) = **2026-09-29 08:16:07 OS local** |

### 5.4. `opcache_get_status()` — sau khi đã ấm (sau lưu lượng test)

| Metric | AFTER (warm) |
|---|---|
| `num_cached_scripts` | **804** |
| `hits` / `misses` | 61,370 / 804 |
| `opcache_hit_rate` | **98.71% → 98.97%** |
| `memory_used` | **35.18 MB / 128 MB (27.5%)** |
| `memory_wasted` | 0 MB |
| `cache_full` | **`false`** |
| `oom_restarts` / `hash_restarts` / `manual_restarts` | **0 / 0 / 0** |
| `blacklist_misses` | 0 |
| `jit` enabled / buffer | `false` / `0` |

### 5.5. Headroom so với giới hạn mới

| Tài nguyên | Đang dùng | Giới hạn | Mức sử dụng |
|---|---|---|---|
| Scripts | 804 | 10,000 | **8.0%** |
| Memory | 35.18 MB | 128 MB | **27.5%** |

Kết luận: giảm `192→128 MB` và `20000→10000` vẫn còn **rất nhiều dư địa**, `cache_full=false`,
`oom_restarts=0` → không có áp lực bộ nhớ nào.

### 5.6. Ghi chú

`current_allocated`, `peak_memory`, `expunged` **không được expose** trong cấu trúc status của bản
PHP build này (`JSON_PARTIAL_OUTPUT_ON_ERROR` bỏ qua chúng). Đây là hạn chế của phiên bản, **không phải lỗi**.

---

## 6. Application verification

### 6.1. Trang public

| Test | Kết quả |
|---|---|
| `GET /login` | **200** (TTFB 148.2 ms, total 149.2 ms) |
| `GET /register` | **200** (TTFB 87.8 ms, total 89.3 ms) |
| `GET /` | **200** |

### 6.2. Xác thực đăng nhập

Bằng chứng xác định (dashboard là SPA Vite nên HTML ban đầu không chứa chữ "logout"):

| Request | Kết quả |
|---|---|
| `GET /dashboard` **không** cookie | **`302`** (chuyển hướng về login) |
| `GET /dashboard` **có** cookie phiên | **`200`** |

→ Phiên thực sự đã đăng nhập.

**Cách xác thực được dùng (cần nói rõ):** dùng **phiên test có sẵn** cho tài khoản test chuyên dụng
`users.id=6 / benchmark@test.com` (`status=active`), tạo qua chính cơ chế session của ứng dụng
(`session.driver = database`). Phiên này **sống sót qua Apache restart**.
Không có mật khẩu nên **không** thực hiện POST đăng nhập bằng mật khẩu — spec cho phép dùng phiên test
có sẵn nếu ghi rõ, và việc này được nêu ở đây.

### 6.3. Tạo link (1 record: `id=3275`)

| Metric | AFTER |
|---|---|
| HTTP | **`200`** |
| TTFB | 236.026 ms |
| Total | **237.544 ms** |
| Trạng thái trả về | **`completed`** (xử lý đồng bộ) |
| `affiliate_url` | `https://s.shopee.vn/an_redir?origin_link=...&affiliate_id=17342330566&sub_id=benchmark` |
| Poll | `200`, 120.023 ms, `completed` |

Giá trị trả về **giống hệt** BEFORE: `price=293000`, `est cashback=7325.00`,
`user cashback=3296.00`, `rate=0.50`, `product_name` và `shop_name` khớp.

### 6.4. Luồng phụ thuộc

- **Không tồn tại** bảng tên `product_data` (kiểm tra `SHOW TABLES`: chỉ có `affiliate_cache`,
  `cache`, `cache_locks`, `link_requests`). Dữ liệu product/affiliate được lưu trong **`affiliate_cache`**.
- `affiliate_cache` cho `item_id=46865427254` → **HIT**, `cache_date=2026-09-29`,
  `data_source` hiện diện, `last_affiliate_created_at` được set → luồng cache + ghi cache hoạt động.

### 6.5. Log ứng dụng

So sánh `storage/logs/laravel.log` theo **byte offset** trước/sau cụm test AFTER:

| Metric | Giá trị |
|---|---|
| Offset trước | 153,906,704 B |
| Offset sau | 153,911,412 B |
| Nội dung mới | +4,708 B, 38 dòng non-empty |
| **`ERROR` / `CRITICAL` / `ALERT` / `EMERGENCY` / `WARNING`** | **0** ✅ |

Nội dung mới chỉ gồm dòng `local.DEBUG` từ browser extension affiliate poll
`[ENTER jobs] / [RETURN jobs] count=0` — hoạt động bình thường, không phải lỗi.

**Minh bạch:** 2 dòng `ERROR` có trong log *trước* offset (`08:14:18` —
`Table 'hoantienaff.product_data' doesn't exist` và `Undefined property: stdClass::$id`) là do
**chính các script chẩn đoán ad-hoc của tôi** chạy qua bootstrap Laravel gây ra, **không phải lỗi ứng dụng**.

### 6.6. Fast Mode & integrity (PHẦN 11)

5 file phải **byte-identical**:

| File | Kết quả |
|---|---|
| `app/Http/Controllers/DashboardCreateDirectLinkController.php` | UNCHANGED |
| `resources/views/dashboard/partials/link-generator.blade.php` | UNCHANGED |
| `tests/Feature/FastModeTest.php` | UNCHANGED |
| `routes/web.php` | UNCHANGED |
| `config/app.php` | UNCHANGED |

`FastModeTest`: **12 passed, 70 assertions** — khớp baseline.

### 6.7. Timestamp validation (PHẦN 12) — **PASS**

Thực hiện trên chính probe tạm (không đụng file ứng dụng nào):

1. Marker trước: rỗng
2. Sửa nội dung + mtime probe → `TSVALID-081837` (mtime `08:18:37`)
3. Chờ **4 giây** (> `revalidate_freq=2`), **không** restart Apache, **không** reset OPcache
4. Marker sau: **`TSVALID-081837`** ✅

| Metric | Giá trị |
|---|---|
| `validate_timestamps` | `1` |
| `revalidate_freq` | `2` |
| `opcache_enabled` sau test | `true` |
| `opcache start_time` | **không đổi** → không bị reset |
| httpd PIDs | **20680, 26592 — không đổi** |

→ File đã sửa được biên dịch lại và phục vụ đúng. Cấu hình thận trọng này có tác dụng thực tế:
code sửa trong `resources/views/` **không bị serve bản cache cũ**.

### 6.8. Dữ liệu đã tạo / đã xóa

| Loại | Số lượng | Ghi chú |
|---|---|---|
| `link_requests` mới | **2** (`id=3274` BEFORE, `id=3275` AFTER) | tài khoản test `user_id=6`; mức tối thiểu để có số liệu link ở cả 2 pha theo yêu cầu |
| Session row | 1 | tài khoản test |
| **Dữ liệu bị xóa** | **0** | không xóa bất kỳ dữ liệu thật nào |

Không tạo benchmark fixture mới. Không xóa `link_requests` id `100`/`101`.
Các dữ liệu benchmark từ lần trước (`user 6`, 141 `link_requests`, 15 sessions, 69 `affiliate_cache`)
**được giữ nguyên**.

### 6.9. Các lỗi do chính tôi gây ra (không phải lỗi ứng dụng)

Để minh bạch, các lỗi sau xuất hiện trong quá trình làm việc nhưng **không phải hư hỏng sản phẩm**:

- `GET /api/user` → 404 và `GET /api/link-requests` → 404: tôi **đoán sai tên route**.
  Route thật là `POST /link-requests` và `GET /api/link-request/{id}` — cả hai đều đã xác minh hoạt động.
- `Auth::find()` → `BadMethodCallException`: `SessionGuard` không có method `find` — lỗi script.
- `Undefined property: stdClass::$id`: giả định sai cột `id` của bảng `affiliate_cache` — lỗi script.
- `Table 'hoantienaff.product_data' doesn't exist`: bảng này không tồn tại — lỗi script.

---

## 7. Performance

### 7.1. Cảnh báo về cách đọc số liệu

Phần này **không** chứng minh được cải thiện hiệu năng, và **không** nên đọc là hồi quy.
Lý do:

1. BEFORE/AFTER ở đây là `192→128 MB` (OPcache **đã bật** ở cả hai pha), **không phải** off→on.
2. BEFORE chạy trên cache đã ấm rất lâu (12.7 triệu hit, 1052 scripts); AFTER đo ngay sau restart
   nên bắt đầu lạnh (610 scripts).
3. **n = 5 và n = 8 quá nhỏ** để đưa ra bất kỳ kết luận thống kê nào.
4. Nhiễu host rõ ràng: có outlier 307.6 ms (AFTER) và 155.1 / 224.2 ms (BEFORE).
5. Cache AFTER khi đo vẫn chưa ấm hoàn toàn (804 vs 1052 scripts).

### 7.2. Số đo

| Endpoint | BEFORE (n=5) median | AFTER cold (n=5) median | AFTER warm (n=8) median |
|---|---|---|---|
| `/login` | 53.7 ms | 62.6 ms | 68.4 ms (min 59.5, max 88.1) |
| `/dashboard` | 70.5 ms | 86.5 ms | 80.2 ms (min 74.7, **max 307.6**) |

Median AFTER cao hơn BEFORE khoảng 15–16 ms. Với lý do ở 7.1, chênh lệch này **không quy kết được cho
cấu hình mới** và nằm trong vùng nhiễu của mẫu nhỏ.

### 7.3. Vì sao về mặt cấu trúc không có penalty steady-state

| Yếu tố | Phân tích |
|---|---|
| `memory_consumption` | Là **trần cấp phát**, không phải kích thước tập làm việc. Mức dùng thật 35.18 MB nằm xa dưới cả 128 và 192; `cache_full=false`, `oom_restarts=0`. Giảm trần **không đổi tốc độ thực thi**. |
| `max_accelerated_files` | 10,000 so với 804 script thật = **8% sử dụng**. Giới hạn **không bao giờ chạm**, nên không ảnh hưởng cache hit. |
| `save_comments=1` | Đây là **default** của PHP; trước và sau đều là `1`. Không thay đổi hành vi. |
| `jit=off` | Trước (`disable`) và sau (`off`) đều tắt JIT thật. Không thay đổi hành vi. |

→ **Không có thay đổi nào trong 4 chỉnh sửa có thể gây hồi quy hiệu năng steady-state.**

### 7.4. Lợi ích thật sự vẫn được giữ nguyên

Thứ thực sự tạo ra lợi ích là **bật OPcache**, không phải con số 192 hay 128. Lợi ích off→on đã đo
và **không bị thay đổi** bởi lần cấu hình này:

| Flow | OFF | ON | Cải thiện |
|---|---|---|---|
| `/login` | 379.7 ms | 48.7 ms | **−87%** |
| `/dashboard` | 458.4 ms | 114.2 ms | **−75%** |
| Poll | 366.2 ms | 62.0 ms | **−83%** |
| Cache HIT POST | 431.8 ms | 101.7 ms | **−76%** |
| Cache MISS POST | 1088.6 ms | 724.3 ms | −33% |
| Short-link POST | 413.9 ms | 77.4 ms | **−81%** |
| Server-side cache-HIT TTR proxy | 798.0 ms | 163.7 ms | **−79%** |

### 7.5. Link creation — **không** so sánh được

| Phase | `id` | Cache state | Total |
|---|---|---|---|
| BEFORE | 3274 | **MISS** (full normal flow, có phân giải product) | 841.3 ms |
| AFTER | 3275 | **HIT** (BEFORE đã làm ấm cache) | 237.5 ms |

Chênh lệch ~604 ms là do **trạng thái cache**, **không phải** do OPcache. Không được dùng để kết luận
tăng tốc.

### 7.6. Đánh đổi được chấp nhận

| Mất đi | Đổi lại |
|---|---|
| 64 MB trần bộ nhớ OPcache (192→128) | 0 restart, 0 OOM; mức dùng 27.5% |
| 10,000 slot script dư thừa (20000→10000) | 0 ảnh hưởng; mức dùng 8.0% |

Cấu hình mới **thận trọng hơn** (ít bộ nhớ hơn) mà vẫn dư địa rất lớn.

---

## 8. Files changed

### 8.1. Thay đổi vận hành

| File | Thay đổi |
|---|---|
| `C:\xampp\php\php.ini` | **4 dòng** (1808, 1815, 1834, 1842) |

### 8.2. Đã tạo rồi xóa (tạm)

| File | Trạng thái |
|---|---|
| `C:\xampp\htdocs\hoantienaff\public\opcache-check.php` | **đã xóa**; xác minh `404` cả khi có lẫn không có token |
| `spec-check.php`, `spec-validate.php`, `jit-probe.php`, `jitread.php`, `sidecheck.php` | đã xóa |
| `auth.json`, `csrf.txt`, `body1.json`, `body2.json`, `poll1.json`, `poll2.json` | đã xóa (chứa session/secret) |

Kiểm tra webroot sau cleanup: **không còn file `*opc*` hay `*probe*`** trong `public/`.

### 8.3. Repo git

`git status --porcelain`:

```
?? docs/OPCACHE_DEPLOYMENT_AND_PERFORMANCE_2026-09-28.md
?? docs/OPCACHE_DEPLOYMENT_2026-09-29.md
```

- **Không có** file tracked nào bị sửa.
- **Không có** thay đổi source app, route, view, config app, migration, `.env`, hay schema.
- 2 file untracked đều là báo cáo tài liệu (1 file tồn tại từ lần trước, 1 file của lần này).
- **Không commit** (không được yêu cầu).

### 8.4. Bằng chứng được giữ lại có chủ đích

`bench-BEFORE.json`, `bench-AFTER.json`, `analyze.php` — dữ liệu lịch sử, **không** phải file debug;
được giữ vì báo cáo 2026-09-28 tham chiếu tới chúng.

---

## 9. Backup location

| File | Size | SHA-256 |
|---|---|---|
| `C:\xampp\php\php.ini.backup-opcache-20260929-081452` | 76,867 B | `29A7A0B94BB2B4E1FE52BF554D6C0FC62DE683A94431B78C3CBF3E2B99F759AC` |
| `C:\xampp\php\php.ini.backup-opcache-20260928-230423` | 76,826 B | `C1EE5978324205C9A41DE54A9799E6C02367787D725389097A0B79F26C0308BA` |

- Backup **mới** được tạo **trước** khi sửa và xác minh **byte-identical** với `php.ini` trước thay đổi.
- Backup cũ (2026-09-28) **không bị ghi đè**, vẫn nguyên vẹn.
- File `php.ini` hiện tại: `8FF4511CBABBEB1C8C5B2AE5D1AD0B9AAEB610BDD058BD74AFF67A0D825A4B54` (76,862 B).

---

## 10. Rollback procedure

### 10.1. Quay lại cấu hình 192 MB / 20000 (trước lần đổi này)

```powershell
# 1. Sao lưu trạng thái hiện tại (tuỳ chọn, để an toàn)
Copy-Item "C:\xampp\php\php.ini" "C:\xampp\php\php.ini.pre-rollback-$(Get-Date -Format yyyyMMdd-HHmmss)"

# 2. Khôi phục backup của lần đổi này
Copy-Item "C:\xampp\php\php.ini.backup-opcache-20260929-081452" "C:\xampp\php\php.ini" -Force

# 3. Restart (duy nhất được phép)
Restart-Service -Name "Apache2.4" -Force

# 4. Xác minh
Get-Content "C:\xampp\php\php.ini" | Select-String "opcache.(enable|memory_consumption|max_accelerated_files|save_comments|jit)="
Get-CimInstance Win32_Process -Filter "Name='httpd.exe'" | Select-Object ProcessId
Get-NetTCPConnection -State Listen -LocalPort 80,443
```

Xác minh lại qua web SAPI bằng cách tạo lại probe token tạm, hoặc chạy:

```bash
curl -s -o NUL -w "login=%{http_code}\n" https://hoantien.xyz/login
curl -s -o NUL -w "register=%{http_code}\n" https://hoantien.xyz/register
```

### 10.2. Quay lại trạng thái OPcache **tắt hoàn toàn**

```powershell
Copy-Item "C:\xampp\php\php.ini.backup-opcache-20260928-230423" "C:\xampp\php\php.ini" -Force
Restart-Service -Name "Apache2.4" -Force
```

Backup 2026-09-28 là trạng thái trước khi bật OPcache lần đầu
(`zend_extension=opcache` bị comment, `opcache.enable=0`).

### 10.3. Rollback khẩn cấp nếu Apache không lên

```powershell
Get-Content "C:\xampp\apache\logs\error.log" -Tail 50
& "C:\xampp\apache\bin\httpd.exe" -t          # kiểm tra syntax cấu hình
Copy-Item "C:\xampp\php\php.ini.backup-opcache-20260929-081452" "C:\xampp\php\php.ini" -Force
Restart-Service -Name "Apache2.4" -Force
```

### 10.4. Nếu cần reset OPcache mà không restart Apache

```powershell
# yêu cầu một URL tự tạo (KHÔNG dùng cho production hiện tại - endpoint này đã bị xóa)
# curl http://hoantien.xyz/opcache-reset.php
```

> **Lưu ý:** hiện tại `validate_timestamps=1` + `revalidate_freq=2` nên sửa code không cần reset.
> Chỉ cần reset khi thay đổi **cấu hình** OPcache mà không muốn restart Apache.

---

## 11. Phán quyết

# ✅ PASS

### Yêu cầu bắt buộc — đạt

| # | Yêu cầu | Kết quả |
|---|---|---|
| 1 | `opcache.enable=1` | ✅ `true` |
| 2 | `opcache.memory_consumption=128` | ✅ `128` / `134217728` B |
| 3 | `opcache.max_accelerated_files=10000` | ✅ `10000` |
| 4 | `opcache.validate_timestamps=1` | ✅ `true` |
| 5 | `opcache.revalidate_freq=2` | ✅ `2` |
| 6 | `opcache.save_comments=1` | ✅ `true` |
| 7 | `opcache.jit=off` | ✅ `status.jit_enabled=false` |
| 8 | `opcache.jit_buffer_size=0` | ✅ `0` |
| 9 | `opcache.enable_cli=0` | ✅ `false` |
| 10 | `opcache.interned_strings_buffer=16` | ✅ `16` |
| 11 | `zend_extension=opcache` | ✅ đúng 1 lần, không trùng |
| 12 | Restart sạch | ✅ PID `5184/27948` → `20680/26592`, log chỉ có notice |
| 13 | Web SAPI thật | ✅ `apache2handler` + `php_ini_loaded_file` đúng |
| 14 | `/login` | ✅ 200 |
| 15 | `/register` | ✅ 200 |
| 16 | Đăng nhập tài khoản test | ✅ 302 vs 200 (dùng phiên test có sẵn, đã nêu rõ) |
| 17 | Tạo link bình thường | ✅ `id=3275` completed, đầy đủ dữ liệu |
| 18 | `/dashboard` | ✅ 200 |
| 19 | Lỗi mới trong log | ✅ 0 ERROR/CRITICAL/WARNING |
| 20 | Fast Mode không đổi | ✅ 12 passed / 70 assertions, 5 file byte-identical |
| 21 | Timestamp validation | ✅ PASS |
| 22 | Dọn file tạm | ✅ probe 404, webroot sạch, secret đã xóa |
| 23 | Không sửa business logic | ✅ 0 file tracked bị sửa |

### Điều kiện dừng — không phát sinh

| Điều kiện dừng | Trạng thái |
|---|---|
| `php_opcache` không tồn tại | ❌ không xảy ra — extension tải thành công |
| Apache restart lỗi | ❌ không xảy ra |
| `php.ini` không đúng file web đang dùng | ❌ không xảy ra — đã xác nhận `C:\xampp\php\php.ini` |
| Laravel không boot được | ❌ không xảy ra |
| Đăng nhập / tạo link lỗi | ❌ không xảy ra |
| `oom_restarts` > 0 hoặc `cache_full=true` | ❌ không xảy ra (`0` / `false`) |

### Điểm cần lưu ý khi đọc báo cáo này

1. **BEFORE không phải OPcache-off.** Lần đổi này tinh chỉnh `192→128 MB` và `20000→10000 files`
   trên nền OPcache **đã bật**. Lợi ích off→on nằm ở báo cáo 2026-09-28.
2. **Không có kết luận hiệu năng nào từ BEFORE/AFTER ở đây.** Mẫu n=5/8 quá nhỏ, BEFORE ấm hơn
   AFTER, có outlier 307 ms. Median AFTER cao hơn ~15 ms nhưng **không quy kết được cho cấu hình**;
   về cấu trúc cả 4 thay đổi đều không thể gây hồi quy steady-state (mục 7.3).
3. **Chênh lệch 841 ms → 237 ms khi tạo link là do cache HIT vs MISS**, không phải do OPcache.
4. **`ini_get('opcache.jit')` trả `''` với giá trị `off`** là quirks báo cáo của PHP 8.2.12.
   Mốc authoritative là `opcache_get_status()['jit']['enabled'] === false` (đã kiểm tra = `false`).
   Giá trị `0` **không** được dùng thay thế vì sẽ cấp phát 64 MB JIT buffer không cần thiết.
5. **Xác thực bằng phiên test có sẵn**, không phải POST mật khẩu (không có mật khẩu). Đã nêu rõ ở 6.2.
6. **2 record `link_requests` mới** (`3274`, `3275`) trên tài khoản test `user_id=6` — mức tối thiểu
   để có số liệu link ở cả hai pha. **Không xóa dữ liệu nào.**
7. **2 dòng `ERROR` trong `laravel.log` trước offset là do script chẩn đoán của tôi**, không phải
   lỗi ứng dụng (mục 6.5). Một số lỗi khác trong quá trình làm việc cũng do script của tôi
   (đoán sai route, giả định sai cột) — liệt kê ở mục 6.9.
8. **Đã tạo 2 `link_requests`** nhưng **không tạo benchmark fixture mới**; dữ liệu benchmark lần trước
   được giữ nguyên.
9. **Dữ liệu tồn đọng của phiên trước:** `link_requests` id `100`/`101` từng bị xóa nhầm và không
   khôi phục được — vấn đề lịch sử, **không** nằm trong phạm vi lần thay đổi này.
10. **`ssl:warn AH01909` cho `www.example.com`** là cảnh báo tồn tại sẵn của vhost mặc định XAMPP,
    không liên quan `hoantien.xyz`.

### Trạng thái production cuối cùng

- Apache `2.4.58` chạy, PID `20680` / `26592`, port 80/443 OK.
- OPcache **đang hoạt động** với đúng bộ giá trị spec trên **web SAPI thật**.
- 804 scripts cached, hit rate ~99%, `cache_full=false`, `oom_restarts=0`.
- JIT tắt, JIT buffer 0.
- Ứng dụng hoạt động bình thường: `/`, `/login`, `/register` → 200; `/dashboard` → 200 khi có phiên.
- Fast Mode nguyên vẹn, 12/12 test pass.
- Không còn file debug/fixture tạm trong webroot.
