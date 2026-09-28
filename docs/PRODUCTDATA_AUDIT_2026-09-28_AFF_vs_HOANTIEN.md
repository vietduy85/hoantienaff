# AUDIT — VÌ SAO PRODUCT DATA SHOPEE KHÔNG CHẠY TRÊN aff.hoantien.xyz
Ngày: 2026-09-28 | Mode: AUDIT-ONLY (không sửa/refactor/commit/migration anywhere) | Scope: Shopee product data

Nhãn dùng: `MEASURED` (đã kiểm chứng) / `INFERRED` (suy luận từ bằng chứng) / `UNKNOWN` (chưa thể kiểm chứng từ đây).

---

## A. KẾT LUẬN NGUYÊN NHÂN

**API AddLiveTag KHÔNG lỗi, và Laravel trên aff CÓ gọi API và CÓ nhận productInfo đầy đủ (gồm imageUrl).**

- Chuỗi phía hoantien.xyz (chính repo này) chạy **end-to-end đúng** tới cả ảnh: resolver → MISS → `ProductDataService` → response có `imageUrl` → ghi `link_requests` + `affiliate_cache` → UI. (`MEASURED` cả bằng Laravel `Http` lẫn curl thô, cùng item `40372404434`.)
- Trên aff: log `[CACHE-Timing] Refresh Cache elapsed_ms=361` chứng minh closure `afterResponse()` **đã chạy** (log này nằm SAU `getByUrl()`); đồng thời **không có bất kỳ warning nào** `ProductDataService: HTTP error / non-success status / retrying / unrecoverable` ⇒ request từ aff HTTP 200 + `status=success`. (`MEASURED` qua log bạn đưa + đọc source.)
- API trả field `imageUrl` đầy đủ cho item `40372404434` (`MEASURED`). Không có cơ chế nào (env/config/key) khiến request của aff khác request của hoantien — `ProductDataService::API_URL` là **hằng số hard-code**; không có API key/header; không phụ thuộc `.env`. (`MEASURED` đọc source.)

**Vị trí thất bại đang nằm TRONG closure `afterResponse` — sau dòng log `[CACHE-Timing]`, tức ở phần GHI DATABASE (DashboardCreateDirectLinkController lines 147–193).** Kết luận phân loại:

- (A) API hỏng → **BỊ LOẠI** (`MEASURED`).
- (B) Laravel aff không gọi API → **BỊ LOẠI** (`[CACHE-Timing]` = đã gọi).
- (C) Request khác → **BỊ LOẠI** (query `item_id` duy nhất, API URL hằng số; hoantien & aff cùng mã/đã trả 200 success).
- (D) Parse sai → **BỊ LOẠI** (`MEASURED`: mapResponse ánh xạ `imageUrl→product_image`, `productName`, `price`... khớp keys API thực tế).
- (E) Nhận được nhưng mất image → **có thể xảy ra chỉ khi response aff thiếu `imageUrl`**; mọi bằng chứng hiện tại đều chống lại (cùng item trả đủ). LOW.
- (F) **Database/cache lưu sai trên aff → NGHI VẤN CHÍNH (`INFERRED`, dựa trên tiền lệ schema drift có thật: item_id phải đổi BIGINT bằng tay).** Nếu `link_requests`/`affiliate_cache` trên aff thiếu cột (vd `product_image`, `shop_name`, `sales`, `is_xtra`, `data_source`, `rating`, `seller_commission`, `shopee_commission`, `user_estimated_cashback`, `cashback_rate`) hoặc `item_id` vẫn INT → câu `LinkRequest::where('id',…)->update([...])` / `AffiliateCache::updateOrCreate([...])` **throw SQLSTATE**, closure chết ngầm ở terminate (sau khi response đã gửi), row giữ `status=processing`, không product fields.
- (G) Hệ quả UI: POST trả ngay `affiliate_url` ⇒ hộp "Link Hoàn Tiền" hiện nhưng tiền = "Chưa có dữ liệu", tên = "Sản phẩm", không ảnh; poll `/api/link-request/{id}` mãi `processing` ⇒ **không bao giờ render product name/image/price**. Khớp 100% với triệu chứng bạn mô tả. (`INFERRED` từ source; xem B.)

**Khẳng định giới hạn đúng đắn của bạn:** "Chưa thể kết luận API hỏng". API không hỏng. **Cần 1–2 lệnh đọc-log/SELECT trên aff để chốt F** (xem mục "LỆNH CẦN CHẠY TRÊN AFF" bên dưới — không sửa gì).

---

## B. BẰNG CHỨNG

1. `ProductDataService` — API URL hằng số, GET `?item_id=…`, retry 2×500ms, timeout 10, connect 5, không header/key/proxy/middleware/cache-response:
   - `app/Services/ProductDataService.php:10` (`API_URL`), `:12-16` (retry/timeout), `:96-98` (query `item_id`), `:140-163` (`mapResponse`: `product_image = $info['imageUrl']`, `product_name = productName`, `product_price = price`, `commission = commission`).
2. Gọi thực tế bằng **chính Laravel Http của app** (tinker, không sửa source) với `https://shopee.vn/product/325938180/40372404434`:
   - `success=true`, `item_id=40372404434`, `shop_id=325938180`, `product_name="Túi Vải…"`, `product_price=45000`, `commission=1125`, `product_image=https://cf.shopee.vn/file/vn-11134207-820l4-mf9ercd3ipe11a`, `product_link=…`, `shop_name="Aó Trùm MáyGiặt Nhà Shin TPHCM"`, `sales=22`. elapsed=985ms.
3. Gọi thô curl cùng query: `status=success`; `productInfo` keys gồm `itemId, shopId, productName, productPrice?… price, sales, imageUrl, productLink, rating, commission, …`; `imageUrl=https://cf.shopee.vn/file/vn-11134207-820l4-mf9ercd3ipe11a`.
4. Image URL load được (200, 381KB, 0.92s) — chặn ảnh không phải nguyên nhân ở env này.
5. Log aff bạn đưa: `[CACHE] … status=MISS` → `[CACHE] ProductData URL … item_id=40372404434` → `[CACHE-Timing] Refresh Cache … elapsed_ms=361`. Không có warning `ProductDataService:*` ⇒ request thành công (B1-E).

   ⇒ **Thứ tự chứng cứ khớp `DashboardCreateDirectLinkController`:** `:76-77` (extractItemId + get cache MISS), `:113-114` (logMiss), `:129-139` (log ProductData URL + gọi API), `:141-145` (log CACHE-Timing **ngay sau getByUrl**), `:147-193` (**DB update ×2 — chưa được chứng minh thành công trên aff**).
6. Schema đúng chuẩn (bản này / hoantien.xyz) — local `SHOW COLUMNS`:
   - `link_requests`: `item_id` unsignedBigInteger, `product_name`, `product_price` unsignedBigInteger, `seller_commission` unsignedBigInteger, `shopee_commission` unsignedBigInteger, `rating` decimal(3,2), `is_xtra` boolean, `product_image` string, `shop_name` string, `sales` unsignedInt, `data_source` string, `shop_id` unsignedBigInteger. (`MEASURED`; migration `2026_06_27_161841_add_product_data_to_link_requests_table.php`, `2026_06_27_170358…`.)
   - `affiliate_cache`: đầy đủ `product_image, shop_name, sales, is_xtra, data_source…`, PK `(item_id, cache_date)` unsignedBigInteger. (`MEASURED`.)
7. hoantien.xyz hôm nay (DB local): nhiều row `affiliate_cache` có ảnh; `link_requests` gần đây `completed` + ảnh (id 2787–2793) — mạch ghi OK. (`MEASURED`.)
8. Env local: PHP 8.2.12 (CLI), curl/openssl/json/mbstring/fileinfo/PDO/pdo_mysql/gd đủ; Guzzle 7.12.1; Laravel v12.62.0; không có config cache (`bootstrap/cache` chỉ packages/services); `.env` KHÔNG có biến product-data (API_URL không env-driven). (`MEASURED`.)
9. Git local: branch `main`, HEAD `d5e6518` — **cần user xác nhận commit trên aff** (`INFERRED`: log aff khớp code hiện tại → khả năng cao CÙNG commit).
10. `routes/web.php:108` `GET /api/link-request/{id}` — `Api\LinkRequestController@show` trả `product_name/product_image/product_price/shop_name` (nếu row `completed`). Frontend `link-generator.blade.php:241-279` poll đến khi `completed`, `:390-402` hiện ảnh khi `result.product_image` — **thiếu product_image ⇒ blade hiển thị placeholder + tên mặc định**. (`MEASURED`.)

**Bảng khả năng ưu tiên sau khi đã loại A–E:**
| # | Khả năng | Xác suất | Cách chốt (không sửa gì) |
|---|---|---|---|
| F1 | aff DB thiếu cột mới / `item_id` vẫn INT ⇒ closure throw SQLSTATE | **CAO** (đã có tiền lệ schema drift thật) | `SHOW CREATE TABLE` + đọc laravel.log ngay dưới dòng CACHE-Timing |
| F2 | HTTP gọi từ aff trả 200 nhưng `status≠success` hoặc thiếu `imageUrl` (response đặc thù IP) | THẤP | chạy curl chính xác trên aff server, check key `imageUrl` |
| G | Backend OK, blade/JS/build cũ trên aff | THẤP | so sánh commit + asset build |

---

## C. SO SÁNH hoantien.xyz VS aff.hoantien.xyz

| Thành phần | hoantien.xyz (repo này/home) | aff.hoantien.xyz | Khác? |
|---|---|---|---|
| Git commit | `d5e6518` (`main`) | `UNKNOWN` (cần `git rev-parse HEAD` trên aff) | cần xác nhận |
| PHP | 8.2.12 CLI | `UNKNOWN` (cần `php -v`) | ? |
| Laravel | v12.62.0 | `UNKNOWN` (`composer show laravel/framework`) | ? |
| Guzzle | 7.12.1 | `UNKNOWN` | ? |
| PHP ext curl/openssl | có (CLI) | `UNKNOWN` (`php -m`) | ? |
| APP_ENV / APP_URL | local / config cache KHÔNG tồn tại | `UNKNOWN` | ? |
| API URL product-data | HẰNG SỐ trong `ProductDataService.php:10` — KHÔNG đọc từ .env/config | HẰNG SỐ (cùng file, nếu cùng commit) | **KHÔNG** (đã loại B1-E; không env-driven) |
| API key / header product-data | Không có | Không có (giả định cùng code) | **KHÔNG** |
| Cache driver | database | `UNKNOWN` | ? |
| Product-data request thực tế | HTTP 200, `status=success`, có `imageUrl` | HTTP 200 + `success` (không warning + 361ms) | **VỀ CƠ BẢN KHÔNG** |
| DB `link_requests` schema | item/…/product_image/… đầy đủ | **`UNKNOWN — CẦN `SHOW CREATE TABLE`** | **CHÌA KHOÁ** |
| DB `affiliate_cache` schema | đầy đủ, PK (item_id, cache_date) | **`UNKNOWN`** | **CHÌA KHOÁ** |
| afterResponse ghi DB | thành công (row completed + ảnh) | **UNKNOWN — nghi ngờ FAIL** | **CHÌA KHOÁ** |
| Frontend blade/JS | hiện ảnh/tên/giá | `UNKNOWN` (cần kiểm tra sau khi khớp data) | ? |

> Luật quan trọng bạn nêu: "**Không được kết luận A nếu chưa kiểm tra B–G**" — chúng tôi đã thực hiện đúng: **A–E bị loại, F là điểm nghi vấn, G là hệ quả.** Chưa chốt được F vì không có quyền đọc DB/log của aff.

---

## D. LỆNH CẦN CHẠY TRÊN AFF (chỉ đọc — KHÔNG sửa gì) + CÁCH FIX ĐỀ XUẤT

### D.0 Chạy trên aff trước khi chốt (4 lệnh đọc):
```
1) storage/logs/laravel.log — mở 30 dòng NGAY DƯỚI dòng "[CACHE-Timing] Refresh Cache item_id = 40372404434"
   (kỳ vọng nếu F1: "SQLSTATE[42S21] Column already exists"/"duplicate column"/"Data truncated"/"Unknown column 'product_…'"; đây là bằng chứng chốt)
2) SHOW CREATE TABLE link_requests\G      → so cột với mục B.6
3) SHOW CREATE TABLE affiliate_cache\G   → so cột/PK
4) SELECT id,status,item_id,shop_id,product_name,product_image FROM link_requests ORDER BY id DESC LIMIT 8;
   SELECT * FROM affiliate_cache WHERE item_id = 40372404434;
   (kỳ vọng F1: row create ở status='processing', không product fields / item_id rỗng; affiliate_cache MISS.)
bổ sung nếu muốn khép C: git rev-parse HEAD, php -v, php -m, composer show laravel/framework guzzlehttp/guzzle
```

### D.1 Cách fix — XẾP THEO ƯU TIÊN (user đề nghị):
1. **DB schema (ưu tiên #1 nếu F1 được xác nhận):** chạy `php artisan migrate` trên aff để khớp ALL migrations đang thiếu (không phải chỉ item_id). Nếu cần thủ công một cột đang thiếu, ví dụ:
   ```sql
   ALTER TABLE link_requests
     ADD COLUMN product_image VARCHAR(255) NULL AFTER is_xtra,
     ADD COLUMN shop_name VARCHAR(255) NULL AFTER product_image,
     ADD COLUMN sales INT UNSIGNED NULL AFTER shop_name,
     ADD COLUMN data_source VARCHAR(255) NULL AFTER sales;
   ```
   (Chỉ đề xuất; KHÔNG tự chạy. Danh sách cột chuẩn lấy từ migrations `2026_06_27_161841…` + `2026_06_27_170358…`.)
2. **Sau khi schema khớp:** không cần sửa code — cache tự heal: request tiếp theo vẫn MISS (chưa có row) → closure chạy lại → ghi thành công → HIT. Để gỡ ngay row đang dính, chỉ cần tạo lại link hoặc chạy test `curl` lại item này.
3. Xác nhận lại bằng UI + `SELECT` thấy `completed` + ảnh.
4. Chỉ khi mọi thứ trên đã đúng mà data vẫn không hiện (remote), lúc đó mới soi frontend/build (G) — hiện tại chưa có dấu hiệu.

### D.2 (Chống chỉ định / không cần)
- **KHÔNG** cần thêm logger/probing nào (đã đủ evidence để chốt bằng 4 lệnh đọc).
- **Không nghĩ tới queue/QUEUE_CONNECTION** — đường dashboard dùng `dispatch(Closure)->afterResponse()` chạy ĐỒNG BỘ tại terminate, không qua queue worker. (`MEASURED`.)
- .env trên aff (APP_ENV/APP_DEBUG/CACHE_DRIVER) **không ảnh hưởng** tới việc lấy product data (API URL không env-driven) — chỉ ảnh hưởng giao diện lỗi/menu quản trị.

---

## E. TUÂN THỦ / KHÔNG LÀM

- ✅ Không sửa code, không refactor, không đổi logic, không commit/push, không chạy migration, không xóa cache/DB, không đổi `.env`, không đổi API key, không workaround, không thêm logging.
- Các file tạm dùng để đo (`storage/app/_perf*.php`) đã được xoá ngay sau khi chạy; không để lại trace. `git status` không đổi so với trước audit.
- Kết luận tôn trọng giới hạn: **F chưa được quan sát trực tiếp**; 4 lệnh ở D.0 sẽ biến `INFERRED` → `MEASURED` mà không cần sửa bất cứ gì.