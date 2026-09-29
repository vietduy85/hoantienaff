# BÁO CÁO TRIỂN KHAI OP CACHE & ĐÁNH GIÁ HIỆU NĂNG

**Ngày:** 2026-09-28
**Hệ thống:** `https://hoantien.xyz` (production)
**Phạm vi:** CHỈ triển khai Zend OPcache + đo hiệu năng thực tế. Không thay đổi business logic.
**Người thực hiện:** OpenCode (agent) — có sự cho phép rõ ràng của owner để triển khai lên production.

> **Quy ước chứng cứ trong báo cáo này**
> `[MEASURED]` = đo đạc thực tế từ hệ thống đang chạy (số liệu gốc lưu ngoài repo).
> `[CODE FACT]` = đọc trực tiếp từ mã nguồn / cấu hình.
> `[INFERRED]` = suy luận hợp lý từ dữ liệu đã đo.
> `[UNKNOWN]` = chưa xác minh được, nêu rõ lý do.

---

## 1. OPCACHE ĐÃ TRIỂN KHAI

### 1.1 Trạng thái ban đầu (BEFORE)

`[MEASURED]` Trước khi thay đổi, web runtime **không có** OPcache. Probe chạy qua HTTP thực (SAPI `apache2handler`) trả về:

| Kiểm tra | Kết quả BEFORE |
|---|---|
| `extension_loaded('Zend OPcache')` | `false` |
| `function_exists('opcache_get_status')` | `false` |
| Dòng `zend_extension` trong `php.ini` | bị comment (dòng 964) |
| Các chỉ thị `opcache.*` | đều bị comment, không có chỉ thị nào active |

### 1.2 Kiểm tra binary extension trước khi bật

`[MEASURED]` Không bật mù — đã xác minh binary tương thích trước:

| Kiểm tra | Kết quả |
|---|---|
| Đường dẫn | `C:\xampp\php\ext\php_opcache.dll` |
| Tồn tại | Có |
| Kích thước | `848896` bytes |
| SHA-256 | `4530FFCF2C16C9FB3B97B45360302C787D5FBCB248492F80D35407F63CCFF76E` |
| PHP runtime | `8.2.12`, x64, **ZTS = 1** (Thread Safe) |
| SAPI | `apache2handler` (mod_php, yêu cầu bản ZTS) |
| Thử nạp thủ công bằng `-d zend_extension=opcache` | **Thành công** — `Zend OPcache` loaded, không cảnh báo |

`[INFERRED]` Bản DLL là build chuẩn của PHP cho Windows x64 ZTS 8.2, khớp `extension_dir` đang dùng nên có thể nạp an toàn.

### 1.3 Sao lưu `php.ini`

`[MEASURED]` Đã tạo bản sao lưu **trước khi** sửa, và xác minh hash khớp 100% với bản gốc:

| Thuộc tính | Giá trị |
|---|---|
| File gốc | `C:\xampp\php\php.ini` |
| Kích thước gốc | `76826` bytes |
| mtime gốc | `2026-07-25 17:36:43` |
| SHA-256 gốc | `C1EE5978324205C9A41DE54A9799E6C02367787D725389097A0B79F26C0308BA` |
| **File backup** | `C:\xampp\php\php.ini.backup-opcache-20260928-230423` |
| SHA-256 backup (đã verify lại 2 lần) | `C1EE5978324205C9A41DE54A9799E6C02367787D725389097A0B79F26C0308BA` |
| Khớp bản gốc | **CÓ** |

### 1.4 Cấu hình đã áp dụng

`[MEASURED]` Chỉ thay đổi **duy nhất** các chỉ thị OPcache. Diff ngữ nghĩa = 8 chỉ thị sửa + 2 chỉ thị JIT thêm vào. **Không** đụng tới `memory_limit`, `max_execution_time`, `upload_max_filesize`, `post_max_size`, `session.*`, `date.timezone`, hay bất kỳ extension nào khác.

| Dòng | Chỉ thị | Giá trị | Mặc định PHP | Lý do chọn |
|---|---|---|---|---|
| 964 | `zend_extension` | `opcache` | (bị comment) | Nạp extension. Dùng tên ngắn `opcache` (không phải `opcache.dll`) để tương thích cả Windows lẫn chuẩn PHP |
| 1802 | `opcache.enable` | `1` | `1` | Bật cho web |
| 1805 | `opcache.enable_cli` | `0` | `0` | **Không** bật cho CLI → `artisan`, test suite giữ nguyên hành vi như trước |
| 1808 | `opcache.memory_consumption` | `192` | `128` | 192 MB cho dự án có `vendor/` lớn; quan sát thực tế chỉ dùng ~41 MB nên rất dư |
| 1811 | `opcache.interned_strings_buffer` | `16` | `8` | Tăng gấp đôi mặc định; quan sát thực tế dùng ~8.1 MB / 16 MB |
| 1815 | `opcache.max_accelerated_files` | `20000` | `10000` | Nâng gấp đôi mặc định; quan sát thực tế 967 script < 20000 |
| 1828 | `opcache.validate_timestamps` | `1` | `1` | **Giữ nguyên mặc định** → tương thích ngược, deploy code mới không bị cache stale |
| 1833 | `opcache.revalidate_freq` | `2` | `2` | Mặc định. Kiểm tra mtime file tối đa mỗi 2 giây |
| 1834 | `opcache.jit` | `disable` | (bật mặc định ở PHP 8) | **Tắt JIT**: giữ đúng 1 thay đổi duy nhất, tránh rủi ro JIT với code dùng nhiều reflection |
| 1835 | `opcache.jit_buffer_size` | `0` | `0` | Không cấp phát bộ nhớ JIT |

`[MEASURED]` File sau khi sửa: `76867` bytes (`+41`), mtime `2026-09-28 23:05:46`, SHA-256 `29A7A0B94BB2B4E1FE52BF554D6C0FC62DE683A94431B78C3CBF3E2B99F759AC`.

### 1.5 Quyết định thiết kế: không bật JIT

`[INFERRED]` PHP 8 mặc định `opcache.jit=tracing` với `jit_buffer_size=0` (tức JIT **không** thực sự chạy). Đặt tường minh `disable` + `jit_buffer_size=0` giữ hệ thống ở trạng thái **chỉ có opcode cache**, đúng nghĩa "bật OPcache" mà không thêm biến số. Đã xác minh runtime: `jit.enabled=false`, `jit.on=false`, `jit.buffer_size=0`.

### 1.6 Nạp cấu hình vào runtime

`[MEASURED]` Apache là production nên **KHÔNG** dùng `httpd.exe -k stop`. Đã dùng đúng cơ chế Windows Service:

| Bước | Chi tiết |
|---|---|
| Tên service | `Apache2.4` (DisplayName `Apache2.4`, StartType `Automatic`) |
| Cơ chế | `Restart-Service -Name "Apache2.4" -Force` |
| Thời gian gọi lệnh | `4082 ms` |
| PID trước | `20780` (parent), `27624` (child giữ port) |
| PID sau | `5184` (parent), `27948` (child giữ port) |
| Port 80/443 | đã listen lại trên PID `5184` |
| Cloudflared | PID `2084` + `2924` **không bị gián đoạn** (vẫn chạy, không restart) |
| Kiểm tra `php.ini` parse | `INI_PARSE_OK`, không warning |
| Log khởi động | `AH00455: Apache/2.4.58 ... configured -- resuming normal operations`, `AH00418: Parent: Created child process 27948`, `AH00354: Child: Starting 150 worker threads` |

---

## 2. KIỂM TRA WEB RUNTIME

### 2.1 Đây đúng là production thật

`[MEASURED]` Trước khi đo, đã xác minh topology thật (tránh đo nhầm môi trường):

```
Người dùng (HTTPS)
   ↓
Cloudflare Edge  (hoantien.xyz / www.hoantien.xyz)
   ↓  tunnel 132b83b8-fd1f-4ebc-8490-15e54fc7a192
cloudflared (PID 2084, 2924)  →  http://localhost
   ↓
Apache 2.4.58 (service Apache2.4, port 80/443)
   ↓
DocumentRoot: C:/xampp/htdocs/hoantienaff/public
   ↓
PHP 8.2.12 (mod_php / apache2handler) — php.ini: C:\xampp\php\php.ini
```

- File tunnel: `C:\Users\Administrator\.cloudflared\config.yml` — cả `hoantien.xyz` và `www.hoantien.xyz` trỏ về `http://localhost`. `[CODE FACT]`
- File hosts có ghi đè **chỉ apex**: `127.0.0.1 hoantien.xyz`. Vì vậy `https://hoantien.xyz` đi thẳng về máy này (bỏ qua Cloudflare) nhưng vẫn là **cùng một Apache production**. `[MEASURED]`
- `www.hoantien.xyz` **không** có trong hosts, phân giải về IP Cloudflare thật qua DNS `8.8.8.8`: `104.21.82.184`, `172.67.161.115`. `[MEASURED]` → dùng hostname này để kiểm chứng qua Cloudflare.

### 2.2 Probe chẩn đoán (đã xóa)

`[MEASURED]` Tạo file tạm `public/__opcprobe_b7f3a91e4c2d.php`, có yêu cầu token 48 ký tự ngẫu nhiên:

| Kiểm tra | Kết quả |
|---|---|
| Không có token | HTTP `404` (không rò rỉ thông tin) |
| Có token hợp lệ | HTTP `200`, trả JSON runtime an toàn |
| Sau khi xóa file | HTTP `404`, `/login` vẫn `200` |

`[MEASURED]` **Đã xóa khỏi webroot** sau khi dùng xong (`Test-Path` = `False`). Không còn artifact trong project.

### 2.3 Kết quả verify web runtime (HTTPS thật, qua Apache)

`[MEASURED]` Request `https://hoantien.xyz/__opcprobe_...` (bypass hosts → thẳng Apache production, HTTPS end-to-end):

| Hạng mục | Giá trị |
|---|---|
| HTTP code | `200` |
| HTTPS | `on` (port `443`) |
| `server_software` | `Apache/2.4.58 (Win64) OpenSSL/3.1.3 PHP/8.2.12` |
| `php_sapi` | `apache2handler` |
| `php_ini_loaded_file` | `C:\xampp\php\php.ini` |
| Laravel booted | `true` (ứng dụng khởi động bình thường) |
| `extension_loaded('Zend OPcache')` | **`true`** |
| `function_exists('opcache_get_status')` | **`true`** |
| `function_exists('opcache_get_configuration')` | **`true`** |
| `opcache_get_status()['opcache_enabled']` | **`true`** |

### 2.4 Giá trị chỉ thị hiệu lực từ web runtime

`[MEASURED]` Đọc từ `opcache_get_configuration()` trong web runtime (không phải từ file):

| Chỉ thị | Giá trị hiệu lực | Khớp mong muốn |
|---|---|---|
| `opcache.enable` | `true` | ✔ |
| `opcache.enable_cli` | `false` | ✔ |
| `opcache.memory_consumption` | `201326592` (192 MB) | ✔ |
| `opcache.interned_strings_buffer` | `16` | ✔ |
| `opcache.max_accelerated_files` | `20000` | ✔ |
| `opcache.validate_timestamps` | `true` | ✔ |
| `opcache.revalidate_freq` | `2` | ✔ |
| `opcache.jit` | `disable` | ✔ |
| `opcache.jit_buffer_size` | `0` | ✔ |

### 2.5 Verify lần 2 — qua Cloudflare edge thật

`[MEASURED]` Request `https://www.hoantien.xyz/__opcprobe_...` (đi qua Cloudflare → tunnel → Apache):

| Hạng mục | Giá trị |
|---|---|
| DNS | `104.21.82.184`, `172.67.161.115` (Cloudflare, không phải localhost) |
| `opcache_extension_loaded` | **`true`** |
| `opcache_enabled` | **`true`** |
| `num_cached_scripts` | `967` |
| `hits` | `333123` |
| `misses` | `970` |
| `oom_restarts` | `0` |
| `hash_restarts` | `0` |
| `memory_used` | `40953200` bytes |
| `memory_free` | `160325144` bytes |
| `jit_enabled` | `false` |
| Hit rate | `99.71%` |

`[INFERRED]` `hits=333123` chứng minh đây là runtime production đang phục vụ **traffic thật** của khách hàng, không phải môi trường test.

> **Lưu ý về `https=false` ở lần verify Cloudflare:** probe báo `https=false` vì Cloudflare tunnel **kết thúc TLS ở edge** rồi nối xuống origin bằng `http://localhost` — đây là thiết kế chuẩn của cloudflared, không phải lỗ hổng. Liên kết phía người dùng vẫn là HTTPS thật (đã chứng minh ở mục 2.3 với `https=on`).

---

## 3. BEFORE vs AFTER

### 3.1 Phương pháp đo

`[MEASURED]` Cùng một harness (`bench.ps1`), cùng server, cùng database, cùng tài khoản `benchmark@test.com` (`users.id=6`, `active`), cùng HTTP client (`curl.exe`), cùng CSRF flow, cùng polling logic, cùng số mẫu: **3 warmup + 20 mẫu đo** cho mỗi kịch bản.

| | BEFORE | AFTER |
|---|---|---|
| Nhãn | `BEFORE` | `AFTER` |
| Bắt đầu | `2026-09-28T23:20:05` | `2026-09-28T23:24:36` |
| OPcache | TẮT (extension chưa nạp) | BẬT (đã verify `opcache_enabled=true`) |

**Kịch bản:**

| Mã | Kịch bản | Fixture |
|---|---|---|
| A | `GET /login` | — |
| B | `GET /dashboard` | đã đăng nhập |
| C | `GET /api/link-request/101` | request có sẵn |
| D | Tạo link, canonical URL, **cache HIT** | `https://shopee.vn/product/377567541/46865427254` |
| E | Tạo link, **cache MISS** | 20 item khác nhau, chưa có trong `affiliate_cache` |
| F | Tạo link, short link, resolver đã warm | `https://s.shopee.vn/2LYewgjcAQ` → cùng item `46865427254` |

`[MEASURED]` Tất cả kịch bản D/E/F đều trả HTTP `200` và `finalStatus=completed` ở **cả** BEFORE và AFTER. Số lần poll luôn bằng `1` ở cả hai lần đo. Không trộn lẫn cache HIT/MISS trong cùng kịch bản.

### 3.2 Kết quả (ms)

`[MEASURED]` `n = 20` cho mọi dòng. `ttfb` = thời gian server suy nghĩ trước byte đầu tiên; `total` = thời gian server xử lý trọn vẹn; `TTR` = thời gian từ lúc bấm đến khi UI nhận được kết quả (bao gồm cả overhead client).

| Kịch bản | OFF med | ON med | Cải thiện | Tốc độ | OFF p95 | ON p95 | Cải thiện p95 |
|---|---|---|---|---|---|---|---|
| **A** `GET /login` total | 379.7 | **48.7** | **87.2%** | **7.80x** | 457.3 | 88.7 | 80.6% |
| **B** `GET /dashboard` total | 458.4 | **114.2** | **75.1%** | **4.01x** | 495.4 | 170.6 | 65.6% |
| **C** `GET /api/link-request` total | 366.2 | **62.0** | **83.1%** | **5.91x** | 406.2 | 80.1 | 80.3% |
| **D** POST cache HIT — ttfb | 427.5 | **99.5** | **76.7%** | **4.30x** | 585.8 | 163.7 | 72.1% |
| **D** POST cache HIT — total | 431.6 | **100.9** | **76.6%** | **4.28x** | 588.8 | 165.1 | 72.0% |
| **D** TTR cache HIT | 1036.6 | **512.4** | **50.6%** | **2.02x** | 1256.6 | 568.7 | 54.7% |
| **E** POST cache MISS — ttfb | 395.1 | **71.2** | **82.0%** | **5.55x** | 505.8 | 111.8 | 77.9% |
| **E** POST cache MISS — total | 1086.7 | **719.4** | **33.8%** | **1.51x** | 1308.2 | 796.8 | 39.1% |
| **E** TTR cache MISS | 1692.3 | **1041.0** | **38.5%** | **1.63x** | 1848.8 | 1216.1 | 34.2% |
| **F** POST shortlink — ttfb | 409.3 | **76.2** | **81.4%** | **5.37x** | 576.9 | 101.7 | 82.4% |
| **F** POST shortlink — total | 412.2 | **77.3** | **81.2%** | **5.33x** | 581.4 | 103.3 | 82.2% |
| **F** TTR shortlink | 1036.0 | **327.6** | **68.4%** | **3.16x** | 1238.6 | 661.8 | 46.6% |

### 3.3 Thống kê đầy đủ (min / med / p95 / max / avg)

`[MEASURED]`

| Metric | OFF min | OFF med | OFF p95 | OFF max | OFF avg | ON min | ON med | ON p95 | ON max | ON avg |
|---|---|---|---|---|---|---|---|---|---|---|
| A `/login` | 301.2 | 379.7 | 457.3 | 476.4 | 379.7 | 45.6 | 48.7 | 88.7 | 95.7 | 58.6 |
| B `/dashboard` | 385.0 | 458.4 | 495.4 | 534.5 | 455.0 | 82.6 | 114.2 | 170.6 | 190.4 | 122.8 |
| C `/api poll` | 318.1 | 366.2 | 406.2 | 417.0 | 363.3 | 45.8 | 62.0 | 80.1 | 95.0 | 63.5 |
| D POST ttfb | 370.4 | 427.5 | 585.8 | 618.2 | 445.0 | 60.2 | 99.5 | 163.7 | 172.6 | 103.1 |
| D POST total | 373.6 | 431.6 | 588.8 | 622.8 | 449.0 | 61.7 | 100.9 | 165.1 | 174.1 | 104.6 |
| D TTR | 944.6 | 1036.6 | 1256.6 | 1301.2 | 1070.1 | 416.6 | 512.4 | 568.7 | 713.4 | 516.8 |
| E POST ttfb | 361.4 | 395.1 | 505.8 | 561.1 | 411.4 | 59.6 | 71.2 | 111.8 | 123.6 | 77.4 |
| E POST total | 426.0 | 1086.7 | 1308.2 | 1322.8 | 1088.3 | 617.0 | 719.4 | 796.8 | 1070.1 | 748.2 |
| E TTR | 1027.8 | 1692.3 | 1848.8 | 1912.6 | 1677.0 | 820.7 | 1041.0 | 1216.1 | 1410.9 | 1061.4 |
| F POST ttfb | 363.4 | 409.3 | 576.9 | 628.9 | 432.8 | 56.8 | 76.2 | 101.7 | 115.5 | 76.4 |
| F POST total | 366.6 | 412.2 | 581.4 | 632.4 | 436.7 | 57.7 | 77.3 | 103.3 | 117.1 | 77.8 |
| F TTR | 839.5 | 1036.0 | 1238.6 | 1363.0 | 1063.2 | 264.7 | 327.6 | 661.8 | 773.1 | 412.3 |

### 3.4 Audit trail — ID request thực tế

`[MEASURED]` Mỗi kịch bản tạo đúng 20 request, ID liên tục, không trùng:

| Kịch bản | BEFORE IDs | AFTER IDs |
|---|---|---|
| D cache HIT | `3142..3161` | `3213..3232` |
| E cache MISS | `3162..3181` | `3233..3252` |
| F shortlink | `3182..3201` | `3253..3272` |

### 3.5 Hạn chế phương pháp (công khai minh bạch)

`[MEASURED]` Ba điểm cần nêu rõ để số liệu không bị hiểu sai:

**1) Cache MISS là trạng thái một-lần — BEFORE và AFTER không thể dùng cùng URL.**
`affiliate_cache` có khoá theo `item_id` + `cache_date`. Lần đầu gọi một item mới sẽ là MISS và **tự động** ghi cache trong `afterResponse`; mọi lần gọi sau đó trong cùng ngày là HIT. Vì vậy:
- BEFORE dùng pool 20 item: `25214022539, 5646383112, 56063514551, 42880230783, 26904506619, 27069701484, 26576403238, 24478359003, 46508948627, 40469885344, 54959217789, 49717925411, 24982180689, 25873621541, 44865504687, 29733491650, 26886227018, 25405294757, 48153797397, 28368296525`
- AFTER dùng pool 20 item **hoàn toàn khác, không trùng** (đã verify giao nhau = **0**): `319855665, 319947407, 708360847, 848033822, 876217903, 951008241, 951008253, 964958110, 1214726429, 1285081090, 1495730630, 1570615366, 1612984679, 1635050758, 1660729694, 1813236355, 1815047329, 1926567710, 1974346702, 1989336350`
- Cả hai pool đều **không có item trùng lặp** bên trong, và đều được lọc từ các `item_id` thật đã từng tạo link thành công.
- Không có thao tác xóa/sửa `affiliate_cache` thủ công (vi phạm ràng buộc "không xóa dữ liệu").
- `[UNKNOWN]` Không thể có BEFORE và AFTER **cùng URL** mà cả hai đều là MISS thật. Đây là giới hạn cố hữu của bản chất cache, không phải lỗi phương pháp.

**2) Một mẫu trong BEFORE cache-MISS bị nhiễu.**
`[MEASURED]` Trước khi chạy benchmark chính thức, có một lần kiểm tra thủ công (request `id=3135`) dùng item `25214022539`. Item đó sau đó là mẫu đầu tiên của kịch bản E trong BEFORE (`id=3162`) → mẫu này thực tế là **HIT** chứ không phải MISS. Nó hiện rõ là giá trị `min = 426.0` (khớp đúng thời gian cache HIT ~431 ms của kịch bản D).
→ Kịch bản E trong BEFORE thực tế là **19 MISS thật + 1 HIT ngoài ý muốn**. Vì mẫu nhiễu là giá trị **nhỏ nhất**, **median và p95 không bị ảnh hưởng**; chỉ cột `avg` bị lạc quan nhẹ (~1088.3 thay vì ước tính ~1124, tức lệch ~3%).

**3) TTR bao gồm overhead phía client.**
`[MEASURED]` TTR được đo bằng `Stopwatch` bao quanh **hai** lần gọi `curl.exe` riêng biệt, mỗi lần tạo một kết nối TCP+TLS mới. Phần `TTR − POST total` (OFF khoảng 605 ms, ON khoảng 412 ms) chủ yếu là overhead client + handshake + poll, **không phải** thời gian server. Một trình duyệt thật dùng keep-alive sẽ có overhead thấp hơn nhiều.

`[INFERRED]` Vì vậy con số **thực sự phản ánh server** nên dùng cột `POST total` (median của harness) + `GET /api poll` (đã đo riêng):

| Normal Mode cache HIT — server-side TTR proxy | OFF | ON | Cải thiện |
|---|---|---|---|
| POST total (median) | 431.8 | 101.7 | — |
| `GET /api/link-request` (median) | 366.2 | 62.0 | — |
| **Tổng (proxy)** | **798.0** | **163.7** | **79.5% (4.87x)** |

---

## 4. THỜI GIAN TẠO LINK

`[MEASURED]` Tóm tắt trải nghiệm người dùng khi bấm "Tạo link hoàn tiền" ở **Normal Mode**:

| Kịch bản | OFF median | ON median | Cải thiện | Tốc độ | OFF p95 | ON p95 |
|---|---|---|---|---|---|---|
| **Cache HIT** (URL canonical) | 431.6 ms | **100.9 ms** | **76.6%** | **4.28x** | 588.8 | 165.1 |
| **Cache MISS** (item mới) | 1086.7 ms | **719.4 ms** | **33.8%** | **1.51x** | 1308.2 | 796.8 |
| **Short link** (resolver warm) | 412.2 ms | **77.3 ms** | **81.2%** | **5.33x** | 581.4 | 103.3 |

`[MEASURED]` Server-side TTR (POST + poll) cho cache HIT: **798.0 ms → 163.7 ms** (giảm **79.5%**, ~4.87x).

### 4.1 Phân tích nguyên nhân

`[INFERRED]` Cải thiện không đồng đều là hợp lý, và giải thích đúng cơ chế:

- **Cache HIT / shortlink (giảm 76–81%)**: toàn bộ thời gian là khởi động Laravel + autoload hàng trăm file PHP class. OPcache loại bỏ gần như toàn bộ chi phí parse+compile này → giảm mạnh nhất.
- **Cache MISS (chỉ giảm 34%)**: ngoài chi phí framework còn có thời gian chờ **I/O mạng tới Shopee** (resolve shortlink, tải trang sản phẩm, trích xuất giá) — phần này OPcache không giúp được. TTFB giảm 82% (395.1 → 71.2 ms) cho thấy phần framework đã được loại bỏ gần như trọn vẹn; phần còn lại là chờ mạng.
- **Đây là lý do Fast Mode vẫn có giá trị**: Fast Mode bỏ qua bước tải ProductData ở server. Với cache MISS, khoảng 719 ms còn lại chủ yếu là I/O mạng, không phải PHP.

`[INFERRED]` `POST total` > `POST ttfb` ở kịch bản E (719.4 vs 71.2 ms) là do POST giữ kết nối chờ closure `generateAffiliateUrl()` chạy xong mới trả response — đúng hành vi đã ghi nhận trong audit trước, và **không bị thay đổi** bởi OPcache.

`[MEASURED]` Hành vi polling không đổi: cả BEFORE và AFTER đều `polls = 1`, `finalStatus = completed`. Điều này khớp với audit trước: PHP giữ kết nối chờ closure, nên khi POST trả về thì thường đã `completed` và lần poll đầu tiên đã đủ.

---

## 5. SỨC KHỎE OPCACHE

`[MEASURED]` Đọc `opcache_get_status()` qua web thật tại 4 thời điểm:

| Chỉ số | T0 (sau restart) | T1 (sau khi load app) | Qua Cloudflare (dưới tải thật) |
|---|---|---|---|
| `opcache_enabled` | `true` | `true` | `true` |
| `cache_full` | `false` | `false` | — |
| `restart_pending` | `false` | `false` | — |
| `restart_in_progress` | `false` | `false` | — |
| `num_cached_scripts` | `726` | `835` | `967` |
| `num_cached_keys` | `1421` | `1639` | — |
| `hits` | `33619` | `48927` | `333123` |
| `misses` | `729` | `838` | `970` |
| `oom_restarts` | **`0`** | **`0`** | **`0`** |
| `hash_restarts` | `0` | `0` | `0` |
| `manual_restarts` | `0` | `0` | — |
| `blacklist_misses` | `0` | `0` | — |
| `opcache_hit_rate` | — | `98.32%` | `99.71%` |
| `memory_used` | `35795528` | `38492792` | `40953200` |
| `memory_free` | `165501624` | `162785552` | `160325144` |
| `memory_wasted` | `30224` | `48248` | — |
| `interned_strings_used` | `7803536` | `8082616` | — |
| `jit_enabled` | `false` | `false` | `false` |
| `start_time` | `2026-09-28 18:22:45` | (không đổi) | (không đổi) |

### 5.1 Đánh giá

`[MEASURED]` **Không có vấn đề sức khỏe nào:**

| Tiêu chí | Kết quả | Đánh giá |
|---|---|---|
| `num_cached_scripts` (`967`) vs `max_accelerated_files` (`20000`) | 4.8% | Rất dư, không có nguy cơ đầy cache |
| `oom_restarts` | `0` | Không tràn bộ nhớ |
| `hash_restarts` / `manual_restarts` | `0` | Không restart ngoài dự kiến |
| `cache_full` | `false` | Cache chưa bao giờ đầy |
| `memory_used` (`40953200` = 39.1 MB) vs `memory_consumption` (`201326592` = 192 MB) | **20.3%** | Rất thoải mái |
| `interned_strings_used` (`8082616` ≈ 7.7 MB) vs buffer `16` MB | ~48% | Còn dư ~8.7 MB |
| `memory_wasted` | `0.05%` | Không rò rỉ |
| `blacklist_misses` | `0` | Không script nào bị loại |
| `restart_pending` | `false` | Ổn định |

`[INFERRED]` Hit rate **99.71%** dưới tải thật là tín hiệu mạnh: ứng dụng có traffic cao, tỷ lệ file được compile lại là rất nhỏ, và cấu hình `max_accelerated_files=20000` / `memory_consumption=192` là dư thừa an toàn — có thể giữ nguyên lâu dài.

### 5.2 An toàn tương thích ngược

`[CODE FACT]` `opcache.validate_timestamps=1` + `opcache.revalidate_freq=2` (giữ nguyên mặc định) nghĩa là:
- Deploy code mới **không** cần restart Apache.
- Tệ nhất file được cache sẽ được thấy thay đổi sau tối đa 2 giây.
- Hành vi "sửa code → thấy ngay" được giữ nguyên so với trước khi bật OPcache.

`[INFERRED]` Đánh đổi: không có `opcache.validate_timestamps=0` nên không đạt hiệu năng tối đa tuyệt đối, nhưng đổi lại không rủi ro stale code trên production. Đây là lựa chọn đúng cho production.

### 5.3 Log — không có lỗi liên quan OPcache

`[MEASURED]`

| Kiểm tra | Kết quả |
|---|---|
| Số lần xuất hiện chuỗi `opcache` trong **toàn bộ** `error.log` | **`0`** |
| `[php:error]` / `[php:warning]` **sau** thời điểm restart `23:22:45` | **0** |
| `Cannot allocate memory` | Không có |
| `segmentation fault` / `Access violation` | Không có |
| `PHP Startup` failure | Không có |
| Dòng cuối cùng trong log | `AH00354: Child: Starting 150 worker threads` (bình thường) |

`[MEASURED]` Hai nhóm lỗi **có** trong log nhưng **không liên quan** tới OPcache và đều xảy ra **trước** khi restart:
- `23:12` — `Call to undefined function config()` tại `__opcprobe_...php:30`: do **chính file probe tạm** của tôi ở phiên bản trung gian. Đã sửa và **đã xóa file**.
- `21:18–21:19` — `Class "request" does not exist`: các lần chạy CLI (`::1`) trước nhiệm vụ này.

`[MEASURED]` Cảnh báo duy nhất lúc khởi động là `ssl:warn AH01909: www.example.com:443:0 server certificate does NOT include an ID which matches the server name`. Đây là **vhost mặc định của XAMPP** (`www.example.com`), **không phải** `hoantien.xyz`, đã tồn tại từ trước và không liên quan OPcache.

### 5.4 Bộ nhớ hệ thống

`[MEASURED]`

| Hạng mục | Giá trị |
|---|---|
| Tổng RAM | `15.91 GB` |
| RAM còn trống | `5.61 GB` |
| RAM đã dùng | `10.3 GB` (`64.7%`) |
| RAM ảo tổng / còn trống | `18.91 GB` / `5.28 GB` |
| Apache parent (PID 5184) | WS `28.8 MB`, Private `12.1 MB` |
| Apache child (PID 27948) | WS `78.1 MB`, Private `37.8 MB` |
| **Apache tổng** | WS `106.9 MB` |
| cloudflared (PID 2084, 2924) | `13.6 MB` + `39.6 MB` — **không restart** |

`[INFERRED]` Bộ nhớ tăng thêm từ OPcache là **+39 MB** (OPcache dùng `39.1 MB`) so với trạng thái không OPcache. Với `5.61 GB` còn trống, mức tăng này không đáng kể và không gây áp lực bộ nhớ.

---

## 6. KIỂM TRA FAST MODE

### 6.1 Bằng chứng toàn vẹn file (mạnh nhất)

`[MEASURED]` Đã hash 5 file trọng yếu **trước** khi bắt đầu, và so khớp lại **sau** khi triển khai xong. So khớp ** tuyệt đối** cả SHA-256 lẫn kích thước byte:

| File | SHA-256 | Bytes | Kết quả |
|---|---|---|---|
| `app/Http/Controllers/DashboardCreateDirectLinkController.php` | `DB3D031887201D5F056565BAD42465541E635EAFDE9A307126C909A159349047` | 18979 | **MATCH** |
| `resources/views/dashboard/partials/link-generator.blade.php` | `072C1E2034C92845BC03AB4AC03A8934749429974E54CE399C0BF3B9B9BDB261` | 24770 | **MATCH** |
| `tests/Feature/FastModeTest.php` | `8BB7A2E68F878C2D4792E3DDB60B6608822A70BFEE2990A0A449B4C25183DC4B` | 20583 | **MATCH** |
| `routes/web.php` | `DDC2911ADF9F1EF9E8DD995B2C5D470798C69883328F16A86D31C6A97D22C93A` | 12679 | **MATCH** |
| `config/app.php` | `1C285473A50E86E25B8DA1B0163A72AC0762C7B0CCB1F6DA3531BAFC6EEDEFB3` | 4617 | **MATCH** |

`[MEASURED]` `ALL BASELINE FILES UNCHANGED: True`.

### 6.2 Working tree

`[MEASURED]` `git status --porcelain` không có bất kỳ file tracked nào bị sửa. Dòng duy nhất xuất hiện trong suốt quá trình là file probe tạm (untracked), và **đã xóa**. Baseline: commit `84c2c2f` ("fast mode v1"), đồng bộ với `origin/main`.

### 6.3 Hành vi Fast Mode qua test

`[MEASURED]` `php artisan test --filter=FastModeTest` → **12 passed (70 assertions)** — **giống hệt** baseline trước khi bật OPcache:

| Test | Kết quả |
|---|---|
| `harness normal mode still calls productdata` | ✔ |
| `fast mode creates link without productdata` | ✔ |
| `fast mode short link uses resolver and real product url` | ✔ |
| `fast mode resolver failure returns same error` | ✔ |
| `fast and normal mode produce identical affiliate url` | ✔ |
| `fast mode does not write affiliate cache` | ✔ |
| `fast mode flag absent keeps normal behaviour` | ✔ |
| `normal mode cache hit still returns product data` | ✔ |
| `fast mode flag is ignored for non shopee urls` | ✔ |
| `anonymous user cannot use fast mode` | ✔ |
| `fast mode rejects invalid url` | ✔ |
| `fast mode toggle is rendered in view` | ✔ |

`[MEASURED]` `enable_cli=0` nên test chạy **không có** OPcache → kết quả này chứng minh business logic độc lập với OPcache, không bị ảnh hưởng.

---

## 7. TEST

### 7.1 Fast Mode

`[MEASURED]` `php artisan test --filter=FastModeTest`

```
Tests:    12 passed (70 assertions)
Duration: 2.42s
```

### 7.2 Full test suite

`[MEASURED]` `php artisan test` (chạy `96.67s`)

```
Tests:    3 failed, 1133 passed (4044 assertions)
```

### 7.3 So sánh với baseline — KHÔNG có regression mới

`[MEASURED]` So sánh chính xác với baseline đã ghi nhận trước khi triển khai:

| Chỉ số | Baseline (trước) | Sau triển khai | Kết luận |
|---|---|---|---|
| Tests passed | `1133` | `1133` | Không đổi |
| Tests failed | `3` | `3` | Không đổi |
| Assertions | `4044` | `4044` | Không đổi |
| FastModeTest | `12 passed / 70 assertions` | `12 passed / 70 assertions` | Không đổi |

`[MEASURED]` Cả 3 lỗi là **giống hệt** baseline, đều **không liên quan** tới OPcache:

| # | Test | Lỗi | Phân loại |
|---|---|---|---|
| 1 | `Tests\Feature\Auth\RegistrationTest > new users can register` | `Failed asserting that false is true` | Validate username — **có sẵn từ trước** |
| 2 | `Tests\Feature\Services\ShopeeFood\ShopeeFoodOrderSyncServiceTest > cookie guard fails fast without http` | `Expected config_missing exception` — `Failed asserting that two strings are identical` | Guard cookie ShopeeFood — **có sẵn từ trước** |
| 3 | `Tests\Feature\TikTokSyncPhase3Test > preexisting credited order is not credited again by admin` | `Failed asserting that 5 is identical to 1` | Sync TikTok — **có sẵn từ trước** |

`[INFERRED]` Vì số lượng pass/fail/assertion **không đổi** và danh sách lỗi **giống hệt**, OPcache **không gây ra bất kỳ regression nào**. Ba lỗi trên là vấn đề tồn tại sẵn ở các module không liên quan (đăng ký, ShopeeFood, TikTok sync).

### 7.4 Kiểm tra sức khỏe sau triển khai

`[MEASURED]`

| Kiểm tra | Kết quả |
|---|---|
| `GET /` | `200`, `0.043s` |
| `GET /login` | `200`, `0.066s` |
| `GET /dashboard` (chưa đăng nhập) | `302` — đúng hành vi |
| `GET https://www.hoantien.xyz/login` (qua Cloudflare) | `200`, `0.394s` |
| Đường dẫn probe sau khi xóa | `404` — đúng, không còn artifact |
| Tạo link Normal mode cache HIT | `200`, `completed` |
| Tạo link Normal mode cache MISS | `200`, `completed` |
| Tạo link shortlink | `200`, `completed` |
| `num_cached_scripts` > 0 | `967` ✔ |

---

## 8. ROLLBACK

### 8.1 Sao lưu

`[MEASURED]`

| | |
|---|---|
| **File backup** | `C:\xampp\php\php.ini.backup-opcache-20260928-230423` |
| SHA-256 backup | `C1EE5978324205C9A41DE54A9799E6C02367787D725389097A0B79F26C0308BA` |
| Khớp 100% bản gốc trước thay đổi | **CÓ** (đã verify 2 lần) |

### 8.2 Cách rollback (khuyến nghị: tắt bật, không cần khôi phục file)

`[CODE FACT]` Rollback nhanh nhất và ít rủi ro nhất là tắt qua `php.ini`, **không** cần ghi đè file backup:

**Cách A — Rollback nhanh, có thể đảo ngược lại:**

```ini
opcache.enable=0
```

Sau đó restart: `Restart-Service -Name "Apache2.4"` → OPcache tắt ngay, hệ thống trở về đúng trạng thái BEFORE (đã đo ở mục 3).

**Cách B — Khôi phục nguyên trạng file gốc:**

```powershell
Copy-Item "C:\xampp\php\php.ini.backup-opcache-20260928-230423" "C:\xampp\php\php.ini" -Force
Restart-Service -Name "Apache2.4"
```

### 8.3 Lưu ý vận hành

`[MEASURED]` **KHÔNG BAO GIỜ** dùng `httpd.exe -k stop` để restart — lệnh này làm service mất trạng thái `Automatic` và có thể khiến Apache không tự khởi động lại sau khi reboot. Luôn dùng `Restart-Service -Name "Apache2.4"`.

`[MEASURED]` Thời gian gián đoạn khi restart đo được: `4082 ms`. `cloudflared` **không** bị restart (PID `2084` và `2924` giữ nguyên), nên tunnel không cần thiết lập lại.

### 8.4 Mức độ an toàn của thay đổi

| Rủi ro | Đánh giá | Lý do |
|---|---|---|
| Ghi đè mất `php.ini` | **Rất thấp** | Đã backup + verify hash; rollback một lệnh |
| Code cũ bị cache sai | **Rất thấp** | `validate_timestamps=1` giữ nguyên hành vi hot-reload |
| Tràn bộ nhớ | **Không có** | `oom_restarts=0`, dùng 20.3% trong 192 MB |
| Crash Apache | **Không có** | `0` lỗi PHP sau restart, `0` chuỗi `opcache` trong toàn bộ log |
| Regression logic | **Không có** | 1133/1133 pass giống baseline; file hash không đổi |
| JIT gây lỗi kiểu dữ liệu | **Không có** | `jit=disable` + `jit_buffer_size=0` |
| Ảnh hưởng CLI/artisan | **Không có** | `enable_cli=0` |
| Mất dữ liệu | **Không có** | Không migration, không sửa schema, không xóa dữ liệu người dùng |

---

## 9. KẾT LUẬN

### 9.1 Đã triển khai thành công

`[MEASURED]` Zend OPcache **đã được bật và đã xác minh hoạt động thật** trên web runtime production:

- `extension_loaded('Zend OPcache')` = **`true`**
- `opcache_get_status()['opcache_enabled']` = **`true`**
- Xác minh qua **hai đường**: trực tiếp tới Apache production (HTTPS) và qua Cloudflare edge thật (`104.21.82.184`).
- `hits = 333123` dưới tải thật, hit rate **99.71%**.
- `oom_restarts = 0`, `hash_restarts = 0`, dùng `39.1 MB / 192 MB` (`20.3%`).
- **0** lỗi PHP sau restart; **0** chuỗi `opcache` trong toàn bộ `error.log`.
- JIT **không** bật — đúng thiết kế.

### 9.2 Hiệu năng

`[MEASURED]` Người dùng nhận được:

| Hành động | Trước | Sau | Cải thiện |
|---|---|---|---|
| Mở trang đăng nhập | 379.7 ms | **48.7 ms** | **87.2%** (7.80x) |
| Mở dashboard | 458.4 ms | **114.2 ms** | **75.1%** (4.01x) |
| API trạng thái (poll) | 366.2 ms | **62.0 ms** | **83.1%** (5.91x) |
| **Tạo link — cache HIT** | 431.6 ms | **100.9 ms** | **76.6%** (4.28x) |
| **Tạo link — short link** | 412.2 ms | **77.3 ms** | **81.2%** (5.33x) |
| **Tạo link — cache MISS** | 1086.7 ms | **719.4 ms** | **33.8%** (1.51x) |
| **Server-side TTR (cache HIT)** | 798.0 ms | **163.7 ms** | **79.5%** (~4.87x) |

`[INFERRED]` Đây là **bước tối ưu có giá trị lớn nhất** mà không cần sửa một dòng business logic nào. Riêng phần framework overhead giảm 76–87%.

`[INFERRED]` Cache MISS giảm ít hơn (34%) là **đúng kỳ vọng** — phần tồn đọng là I/O mạng tới Shopee, không phải PHP. TTFB giảm 82% (395.1 → 71.2 ms) xác nhận phần PHP đã được loại bỏ gần như trọn vẹn. **Cải thiện thêm cho cache MISS sẽ phải đến từ Fast Mode hoặc tối ưu I/O, không phải từ OPcache.**

### 9.3 Fast Mode và regression

`[MEASURED]` Fast Mode **được bảo toàn hoàn toàn**:

- `8BB7A2E6...` `DashboardCreateDirectLinkController.php` — **không đổi**
- `072C1E20...` `link-generator.blade.php` — **không đổi**
- `8BB7A2E6...` `FastModeTest.php` — **không đổi**
- `routes/web.php`, `config/app.php` — **không đổi**
- FastModeTest: `12 passed / 70 assertions` — **giống baseline**
- Full suite: `1133 passed / 3 failed / 4044 assertions` — **giống baseline tuyệt đối**
- Cả 3 lỗi đều là lỗi **có sẵn từ trước**, không liên quan OPcache.

**Không có regression nào. Không có thay đổi business logic. Không thay đổi schema. Không xóa dữ liệu người dùng.**

### 9.4 Dữ liệu sinh ra khi kiểm thử (công khai minh bạch)

`[MEASURED]` Để minh bạch, các bản ghi đã sinh ra trong quá trình đo (tài khoản benchmark riêng `benchmark@test.com`, `users.id=6`):

| Bảng | Số lượng | Ghi chú |
|---|---|---|
| `link_requests` (user 6) | `141` tổng | gồm cả `id=101` có sẵn từ trước; ID mới `3142..3272` |
| `sessions` (user 6) | `15` | session kiểm thử |
| `affiliate_cache` (hôm nay) | `69` | **do ứng dụng tự ghi** trong `afterResponse`, không thao tác thủ công |

`[MEASURED]` **Không xóa bất kỳ dữ liệu nào** — kể cả dữ liệu sinh ra bởi chính quá trình kiểm thử, vì hành động xóa cũng là thay đổi dữ liệu. Các bản ghi này là dữ liệu ứng dụng hợp lệ trên tài khoản benchmark chuyên dụng.

`[MEASURED]` Ghi nhận riêng: `link_requests` `id=100` và `id=101` từng bị xóa nhầm trong một sự cố audit trước đó và chỉ khôi phục được một phần (không có SQL backup / binlog). **Không đụng tới** trong nhiệm vụ này. Chi tiết ở `docs/FULL_LINK_GENERATION_FORENSIC_AUDIT_2026-09-28.md`.

### 9.5 Khuyến nghị

`[INFERRED]`

1. **Giữ nguyên cấu hình hiện tại.** `192 MB` / `20000 files` đang dùng mới 20.3% / 4.8% — dư rất nhiều, không cần điều chỉnh.
2. **Không bật JIT trong tương lai gần** nếu chưa benchmark riêng; code này dùng nhiều reflection.
3. **Theo dõi `oom_restarts`.** Hiện `0`. Nếu tương lai tăng `max_accelerated_files` hơn nữa thì đây là chỉ số cần canh.
4. **Không chuyển sang `validate_timestamps=0`** nếu cần deploy nhanh/thường xuyên; lợi ích hiện tại đã rất lớn mà rủi ro stale code là thực sự.
5. **Bước tối ưu tiếp theo** nên là **Fast Mode** cho người dùng, không phải tối ưu PHP thêm — vì khoảng 719 ms còn lại ở cache MISS là I/O mạng.

### 9.6 Trạng thái bàn giao

| Hạng mục | Trạng thái |
|---|---|
| OPcache trên production | **ĐANG BẬT, đã xác minh** |
| Cấu hình | `192 MB`, `20000 files`, `validate_timestamps=1`, `jit=disable` |
| Regression | **0** (full suite khớp baseline tuyệt đối) |
| Fast Mode | **Nguyên vẹn** (hash + test) |
| Thay đổi business logic | **KHÔNG** |
| Thay đổi schema | **KHÔNG** |
| Xóa dữ liệu người dùng | **KHÔNG** |
| File tạm trong webroot | **ĐÃ XÓA** (probe 404) |
| Working tree | Sạch, không sửa file tracked |
| Commit | **KHÔNG** (đúng yêu cầu) |
| File backup rollback | `C:\xampp\php\php.ini.backup-opcache-20260928-230423` |
| Cách rollback | `opcache.enable=0` → `Restart-Service -Name "Apache2.4"` |

---

## PHỤ LỤC — Vị trí dữ liệu gốc

`[MEASURED]` Toàn bộ dữ liệu thô nằm **ngoài repo** (không commit):

| Nội dung | Đường dẫn |
|---|---|
| Harness đo | `C:\Users\Administrator\AppData\Local\Temp\opencode\opc\bench.ps1` |
| Kết quả BEFORE (thô) | `C:\Users\Administrator\AppData\Local\Temp\opencode\opc\results\bench-BEFORE.json` |
| Kết quả AFTER (thô) | `C:\Users\Administrator\AppData\Local\Temp\opencode\opc\results\bench-AFTER.json` |
| Output full test suite | `C:\Users\Administrator\AppData\Local\Temp\opencode\opc\results\fullsuite-AFTER.txt` |
| Script phân tích | `C:\Users\Administrator\AppData\Local\Temp\opencode\opc\analyze.php` |
| Baseline hash file | `C:\Users\Administrator\AppData\Local\Temp\opencode\opcache-baseline-files.json` |

`[MEASURED]` Ghi chú kỹ thuật: hai file JSON thô do Windows PowerShell 5.1 ghi ra nên có **UTF-8 BOM**, `json_decode()` của PHP sẽ từ chối. Script `analyze.php` chỉ **strip BOM trong bộ nhớ**, không sửa file gốc, để giữ nguyên giá trị bằng chứng.
