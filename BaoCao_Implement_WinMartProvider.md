# Báo cáo — Triển khai C7: WinMart Provider (Price Comparison)

Ngày: 17/09/2026
Trạng thái: HOÀN TẤT — toàn bộ kiểm tra đạt, chỉ dừng trước commit/push (theo yêu cầu).

## 1. Tóm tắt

Đã bổ sung nhà cung cấp thứ 4 `winmart` vào module **Price Comparison** (`/so-sanh-gia`), dùng
API catalog **public** của WinMart (không cần auth/API key):

- `POST https://api-crownx.winmart.vn/ss/api/v2/public/winmart/item-search`
- API là **store-scoped**: `storeGroupCode` + `storeNo` quyết định giá, tồn kho, availability.
  Store được cấu hình qua env (mặc định `1998` / `1535` — đã verify live, **không** tự chọn theo IP visitor).
- Trang sản phẩm public: `https://www.winmart.vn/products/{seoName}`.

Kết quả: từ 3 nhà cung cấp (Coop Online, Bách Hóa Xanh, Kingfoodmart) → **4 nhà cung cấp**.

## 2. Files đã thay đổi (9 tracked + 3 mới)

| File | Loại | Nội dung |
|---|---|---|
| `app/Services/PriceComparison/Providers/WinMartProvider.php` | **MỚI** | Provider C7 (search, getProduct, cache, error isolation) |
| `tests/Feature/PriceComparison/WinMartProviderTest.php` | **MỚI** | 42 tests toàn WinMart |
| `tests/Fixture/WinMartFixture.php` | **MỚI** | Fixture khớp cấu trúc API thật |
| `config/services.php` | Sửa | Thêm block `services.winmart` |
| `.env.example` | Sửa | `WINMART_API_BASE_URL`, `WINMART_STORE_GROUP_CODE`, `WINMART_STORE_NO` |
| `app/Providers/AppServiceProvider.php` | Sửa | Đăng ký provider vào tag `catalog-providers` |
| `app/Http/Controllers/Api/PriceComparisonController.php` | Sửa | Thêm `searchWinmart()` |
| `app/Http/Controllers/PriceComparisonController.php` | Sửa | Thêm `winmart` vào `RETAILER_SOURCES` + `SOURCE_RETAILERS` |
| `routes/web.php` | Sửa | Thêm route `/winmart` |
| `resources/views/price-comparison/index.blade.php` | Sửa | Thêm `winmart` vào `$supportedTabs` |
| `tests/.../PriceComparisonAllRetailerTest.php` | Sửa | 4 providers / 4 sources |
| `tests/.../PriceComparisonPageTest.php` | Sửa | Test unsupported retailer dùng `dmx` thay `winmart` |

Diff gọn: **49 insertions, 7 deletions** — chỉ các file liên quan WinMart/PriceComparison.

## 3. Phương pháp tích hợp

- **Registry**: đăng ký qua tag `catalog-providers` trong `AppServiceProvider`; `PriceComparisonManager`
  tiêu thụ tag (cùng cơ chế 3 provider cũ). Không hard-code provider mới trong manager.
- **Payload**: `{keyword, storeGroupCode, storeNo, applicationType: "Winmart", pageNumber, pageSize}`,
  `pageSize` được chặn tối đa 100 (API reject > 100).
- **getProduct**: không có endpoint detail public → resolve qua `item-search` theo keyword:
  (1) khớp sku chính xác; (2) nếu 0 kết quả, trích itemNo (`/^(\d{2,})/`) và search theo itemNo,
  chọn biến thể ưu tiên `quantityPerUnit == 1` (đơn vị nhỏ nhất).
- **Error isolation**: HTTP error / connection exception / malformed JSON / thiếu data →
  log warning + trả kết quả rỗng, không crash danh sách tổng.
- **Cache**: `price_comparison:winmart:search:{sgc}:{storeNo}:{keyword}:{page}:{perPage}` (TTL 300s)
  và `product:{sgc}:{storeNo}:{md5(sku)}` (TTL 600s) — key bao gồm store để không trộn giữa cửa hàng.
- **UOM**: mỗi SKU/UOM là một dòng riêng, KHÔNG gộp/dedup.

## 4. Ánh xạ dữ liệu

| API (WinMart) | DTO |
|---|---|
| `id` (UUID) | `skuId` |
| `sku` | `sku` |
| `description` | `name` |
| `brandName` | `brand` |
| `mch5Name` | `category` |
| `salePrice` | `price` |
| `originPrice` | `originalPrice` |
| `discountRate` | `discountPercent` |
| `originPrice - salePrice` | `discountAmount` |
| `image` | `imageUrl` |
| `warehouse.availableQuantity` | `stock` |
| `publish && stock > 0` | `sellable` |
| `uomName` | `unit` |
| `seoName` | `slug` (validate an toàn segment) |
| — | `seller = "WinMart"`, `barcode = null`, `rawData = raw item` |
| URL | `https://www.winmart.vn/products/{seoName}?store={storeGroupCode}&warehouse={storeNo}` |

## 5. Cấu hình

`.env` (mặc định đã verify):
```
WINMART_API_BASE_URL=https://api-crownx.winmart.vn
WINMART_STORE_GROUP_CODE=1998
WINMART_STORE_NO=1535
```
Không đổi cơ sở dữ liệu, không có migration mới.

## 6. Kiểm thử

### 6.1 Unit/Feature mới — `WinMartProviderTest` (42 tests)
- source, search type/count, payload, pageSize cap 100, mapping đầy đủ, pagination.
- sellable 4 kịch bản, unit từ uomName, slug, seller, barcode null.
- error isolation + skip broken rows, cache (1 HTTP call khi lặp, key cách ly theo store).
- getProduct: khớp sku, ưu tiên đơn vị nhỏ, fallback itemNo, null khi unknown/HTTP error/empty.

### 6.2 Suite PriceComparison
**274 tests PASS (730 assertions)**.

### 6.3 Regression toàn dự án
```
Tests:    3 failed, 1036 passed (3650 assertions)
```
- So với baseline trước C7: **994 passed / 3 failed / 3569 assertions**.
- Tăng đúng **42 tests / 81 assertions** (WinMart + cập nhật test hiện có).
- 3 failures còn lại là **lỗi có sẵn từ trước**, không liên quan C7:
  1. `Auth\RegistrationTest` — "The user is not authenticated".
  2. `ShopeeFoodOrderSyncServiceTest` — cookie guard trả `invalid_json` thay vì `config_missing` (môi trường ENV).
  3. `TikTokSyncPhase3Test` — preexisting credited order (phụ thuộc dữ liệu có sẵn).

### 6.4 Live verification (API thật, store 1998/1535)

| Keyword | total | kết quả mẫu | |
|---|---|---|---|
| mì Hảo Hảo | 249 | Mì gà vàng 74g 4,700đ / Thùng 30 gói 135,700đ | ✓ |
| sữa Vinamilk | 539 | Sữa tươi TT Dừa 180ml 36,300đ | ✓ |
| nước mắm Nam Ngư | 683 | Chai 750ml 53,600đ | ✓ |
| dầu gội | 993 | Sunsilk 380ml 113,000đ | ✓ |

- Registry: **4 providers** (`coop_online, bach_hoa_xanh, kingfoodmart, winmart`). ✓
- `/api/price-comparison?keyword=mì Hảo Hảo` → `source=all`, `pagination.total=399`,
  sources: `coop_online total=36`, `bach_hoa_xanh total=26`, `kingfoodmart total=137`,
  `winmart total=249` (fetched=200). ✓
- `getProduct('10008453G1')` → Mì Hảo Hảo gà vàng 74g, price 4,700đ, sellable. ✓
- Toàn bộ live checks **PASS**.

## 7. Thay đổi DB / Migration

- **Không** có thay đổi database, không có migration mới.

## 8. Commit / Push

- **Chưa commit** (theo yêu cầu — dừng trước commit/push).
- **Chưa push**.
- Nếu được yêu cầu: tạo commit với đúng nội dung C7 (chỉ các file ở mục 2).