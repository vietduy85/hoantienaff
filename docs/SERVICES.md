# Services

## Danh sách Services

| Service | File | Vai trò |
|---------|------|---------|
| AffiliateCacheService | `app/Services/AffiliateCacheService.php` | Cache affiliate data theo item_id + ngày |
| AffiliateService | `app/Services/AffiliateService.php` | Facade cho tất cả provider |
| AffiliateWorkerClient | `app/Services/AffiliateWorkerClient.php` | HTTP client gọi Node worker |
| CashbackCalculator | `app/Services/CashbackCalculator.php` | Tính toán cashback từ commission |
| ProductDataService | `app/Services/ProductDataService.php` | Lấy thông tin sản phẩm từ AddLiveTag API |
| ProviderFactory | `app/Services/ProviderFactory.php` | Factory phát hiện platform và tạo provider |
| UrlResolverService | `app/Services/UrlResolverService.php` | Resolve short link Shopee (s.shopee.vn) |
| ShopeeProvider | `app/Services/Providers/ShopeeProvider.php` | Tạo link affiliate Shopee |
| LazadaProvider | `app/Services/Providers/LazadaProvider.php` | Tạo link affiliate Lazada |
| TikTokProvider | `app/Services/Providers/TikTokProvider.php` | Tạo link affiliate TikTok |
| LongChauProvider | `app/Services/Providers/LongChauProvider.php` | Tạo link Long Châu |
| PharmacityProvider | `app/Services/Providers/PharmacityProvider.php` | Tạo link Pharmacity |
| TravelokaProvider | `app/Services/Providers/TravelokaProvider.php` | Tạo link Traveloka |
| AgodaProvider | `app/Services/Providers/AgodaProvider.php` | Tạo link Agoda |
| BookingProvider | `app/Services/Providers/BookingProvider.php` | Tạo link Booking.com |

---

## AffiliateCacheService

### Vai trò
Quản lý cache affiliate link theo ngày. Mỗi item_id được cache 1 lần/ngày.

### Luồng hoạt động
```
DashboardController::store()
  → cacheService->get(itemId)          # Kiểm tra cache
  → Nếu có: dùng luôn, set status=completed
  → Nếu không: gọi ProductDataService
              → cacheService->put(itemId, data)
              → (sau khi worker tạo link) cacheService->updateAffiliateUrl(itemId, url)
```

### Input
- `item_id` (int): ID sản phẩm Shopee
- `cache_date` (string): Ngày cache (Asia/Ho_Chi_Minh, format Y-m-d)

### Output
- `get()`: `?AffiliateCache` — Model hoặc null
- `put()`: `AffiliateCache` — Model đã tạo/update
- `updateAffiliateUrl()`: void

### Methods

| Method | Parameters | Returns | Mô tả |
|--------|-----------|---------|-------|
| `get()` | `int $itemId` | `?AffiliateCache` | Tìm cache theo item_id + cache_date |
| `put()` | `int $itemId, array $data` | `AffiliateCache` | Tạo hoặc update cache |
| `logMiss()` | `int $itemId` | void | Log cache miss (khi timing enabled) |
| `updateAffiliateUrl()` | `int $itemId, string $affiliateUrl` | void | Cập nhật affiliate_url sau khi worker hoàn thành |
| `extractItemId()` | `string $url` | `?int` | Trích xuất item_id từ URL |
| `getCacheDate()` | — | `string` | Trả về ngày cache hiện tại (Y-m-d) |

### Cache policy
- Mỗi item_id được cache 1 lần/ngày (theo giờ Việt Nam)
- `cache_date` được tính từ `now('Asia/Ho_Chi_Minh')->toDateString()`
- Hết ngày → cache miss, tạo cache mới
- Affiliate URL được update riêng (khi worker hoàn thành)

### Dependency
- `App\Models\AffiliateCache`
- `Illuminate\Support\Facades\Log`

---

## ProductDataService

### Vai trò
Gọi API AddLiveTag để lấy thông tin sản phẩm Shopee.

### Luồng hoạt động
```
DashboardController::store()
  → productData->getByUrl(resolvedUrl)
    → extractProductIds(url)     # Trích xuất item_id, shop_id
    → getByItemId(itemId, shopId) # Gọi API AddLiveTag
    → mapResponse(json)           # Map response thành array
```

### Input
- URL sản phẩm Shopee (đã resolve)

### Output
```json
{
    "success": true,
    "item_id": 123456789,
    "shop_id": 987654321,
    "product_name": "Tên sản phẩm",
    "product_price": 250000,
    "commission": 12500,
    "seller_commission": 10000,
    "shopee_commission": 2500,
    "rating": 4.5,
    "sales": 1000,
    "product_image": "https://...",
    "product_link": "https://...",
    "shop_name": "Tên shop",
    "is_xtra": false,
    "data_source": "api"
}
```

### Methods

| Method | Parameters | Returns | Mô tả |
|--------|-----------|---------|-------|
| `getByUrl()` | `string $url` | `array` | Entry point — extract ID, gọi API |
| `getByItemId()` | `int $itemId, ?int $shopId` | `array` | Gọi AddLiveTag API, có retry (2 lần) |
| `extractProductIds()` | `string $url` | `?array` | Parser URL → item_id & shop_id |

### Extract product IDs
Hỗ trợ các format URL Shopee:
- Query param: `?item_id=...&shop_id=...`
- Path: `/product/{shop}/{item}`
- Path: `/opaanlp/{shop}/{item}`
- Domain: `-i.{shop}.{item}`

### API AddLiveTag
- Endpoint: `https://data.addlivetag.com/product-data/product-data.php`
- Params: `item_id` (int)
- Timeout: 10s
- Retry: 2 lần, delay 500ms
- Cache: API có sẵn cache 24h (phía AddLiveTag)

### Dependency
- `Illuminate\Support\Facades\Http`

### TODO
- Thêm local cache layer cho frequently accessed products
- Implement Redis cache với 24h TTL
- Repository pattern abstraction

---

## CashbackCalculator

### Vai trò
Tính toán cashback cho user dựa trên commission và product price.

### Thuật toán

```
Input: estimated_cashback (commission), product_price

Nếu price <= 0 hoặc commission <= 0 → cashback_rate=50%, user_cashback=0

commission_rate = commission / price

  TH1: commission_rate >= 52% (0.52) → rate = 70%
  TH2: commission_rate >= 12% (0.12) → rate = 60%
  TH3: còn lại                      → rate = 50%

net_cashback = floor(commission * 0.90)    # Trừ 10% thuế
user_cashback = floor(net_cashback * rate)

Output: {
    cashback_rate: 0.50|0.60|0.70,
    user_estimated_cashback: int
}
```

### Constants

| Constant | Value | Mô tả |
|----------|-------|-------|
| `RATE_50` | 0.50 | Cashback rate thấp |
| `RATE_60` | 0.60 | Cashback rate trung bình |
| `RATE_70` | 0.70 | Cashback rate cao |
| `THRESHOLD_60` | 0.12 | Commission rate >= 12% → 60% |
| `THRESHOLD_70` | 0.52 | Commission rate >= 52% → 70% |

### Input
- `estimated_cashback` (float): Hoa hồng từ AddLiveTag
- `product_price` (float): Giá sản phẩm

### Output
- `cashback_rate` (float): 0.50, 0.60, hoặc 0.70
- `user_estimated_cashback` (int): Cashback user nhận (sau thuế)

---

## UrlResolverService

### Vai trò
Resolve short link Shopee (s.shopee.vn, vn.shp.ee) thành URL đầy đủ.

### Luồng hoạt động
```
DashboardController::store()
  → urlResolver->resolve(url)
    → isShortLink(url)             # Kiểm tra domain
    → expandShortUrl(url)          # Follow redirect (max 10 hops)
```

### Short domains
- `s.shopee.vn`
- `vn.shp.ee`
- (bao gồm subdomain)

### Retry strategy

| Attempt | Delay | Điều kiện |
|---------|-------|-----------|
| 1 | 0ms | Try đầu |
| 2 | 300ms | Nếu retryable error (timeout, DNS, network) |
| 3 | 500ms | Nếu retryable error |

### Error handling
- **Retryable errors**: CURLE_COULDNT_RESOLVE_HOST, COULDNT_CONNECT, PARTIAL_FILE, OPERATION_TIMEDOUT, GOT_NOTHING, SEND_ERROR, RECV_ERROR
- **Non-retryable HTTP**: 400, 401, 403, 404, 405, 410, 414, 451
- **Retry-once HTTP**: 500, 502, 503, 504 (retry 1 lần)
- **Null**: Nếu resolve thất bại → fallback về URL gốc

### CURL options
- Timeout: 2s
- Connect timeout: 1s
- Max redirects: 10
- User-Agent: Chrome 120 (Windows)

### Dependency
- `curl` (PHP extension)
- `Illuminate\Support\Facades\Log`

---

## ProviderFactory

### Vai trò
Phát hiện platform từ URL và trả về provider tương ứng.

### Luồng hoạt động
```
AffiliateService::generateLink(url)
  → providerFactory->detectPlatform(url)   # Keyword matching
  → providerFactory->getProvider(url)       # Lấy provider từ DI container
  → provider->createLink(url)               # Tạo affiliate link
```

### Detect platform rules

| Keyword | Platform |
|---------|----------|
| `shopee` | Platform::SHOPEE |
| `lazada` | Platform::LAZADA |
| `tiktok` | Platform::TIKTOK |
| `nhathuoclongchau`, `longchau` | Platform::LONG_CHAU |
| `pharmacity` | Platform::PHARMACITY |
| `traveloka` | Platform::TRAVELOKA |
| `agoda` | Platform::AGODA |
| `booking` | Platform::BOOKING |

### Provider registration
Trong `AppServiceProvider::register()`:
```php
$this->app->tag([...providers...], 'affiliate-providers');
$this->app->when(ProviderFactory::class)
    ->needs('$providers')
    ->giveTagged('affiliate-providers');
```

### Dependency
- `$providers` (array tagged): Danh sách tất cả provider
- `App\Enums\Platform`

---

## AffiliateWorkerClient

### Vai trò
HTTP client gọi Node worker (Express trên port 3001).

### Luồng hoạt động
```
ShopeeProvider::createLink(url)
  → worker->createLink(url)
    → POST /shopee/create-link

Debug controllers → worker->health(), worker->ping(), ...
```

### Endpoints

| Method | Endpoint | Timeout | Mô tả |
|--------|----------|---------|-------|
| GET | /health | 15s | Kiểm tra worker health |
| POST | /shopee/create-link | 15s | Tạo affiliate link |
| GET | /playwright-test | 15s | Test Playwright |
| GET | /shopee/profile-test | 180s | Test Shopee profile |
| GET | /shopee/dashboard-test | 60s | Test Shopee dashboard |
| GET | /shopee/session-test | 15s | Test Shopee session |
| POST | /shopee-login | 15s | Login vào Shopee |
| POST | /shopee-login-interactive | 180s | Login interactive (QR) |

### Config
```php
// config/services.php
'affiliate_worker' => [
    'url' => env('AFFILIATE_WORKER_URL', 'http://127.0.0.1:3001'),
],
```

### Dependency
- `Illuminate\Support\Facades\Http`

---

## AffiliateService

### Vai trò
Facade cho toàn bộ hệ thống provider. Entry point cho link generation.

### Luồng hoạt động
```
DashboardController (không gọi trực tiếp — dùng ProviderFactory)
  → affiliateService->generateLink(url)
    → providerFactory->getProvider(url)
    → provider->createLink(url)
```

### Methods

| Method | Parameters | Returns | Mô tả |
|--------|-----------|---------|-------|
| `generateLink()` | `string $url` | `array` | Tạo affiliate link qua provider |

### Error handling
- `RuntimeException` → return array với `success=false`, message

### Dependency
- `ProviderFactory`

---

## Các Provider

### ShopeeProvider
- Worker gọi: `AffiliateWorkerClient::createLink()`
- Link thật (tạo qua Browser Extension / Node worker)
- Platform: `Platform::SHOPEE`

### LazadaProvider
- Fake link: `https://lazada.vn/affiliate/{encoded_url}`
- Platform: `Platform::LAZADA`

### TikTokProvider
- Fake link: `https://tiktok.com/affiliate/{encoded_url}`
- Platform: `Platform::TIKTOK`

### LongChauProvider
- Fake link: `https://nhathuoclongchau.com.vn/affiliate/{encoded_url}`
- Platform: `Platform::LONG_CHAU`

### PharmacityProvider
- Fake link: `https://pharmacity.vn/affiliate/{encoded_url}`
- Platform: `Platform::PHARMACITY`

### TravelokaProvider
- Fake link: `https://traveloka.com/affiliate/{encoded_url}`
- Platform: `Platform::TRAVELOKA`

### AgodaProvider
- Fake link: `https://agoda.com/affiliate/{encoded_url}`
- Platform: `Platform::AGODA`

### BookingProvider
- Fake link: `https://booking.com/affiliate/{encoded_url}`
- Platform: `Platform::BOOKING`

**Ghi chú**: Chỉ ShopeeProvider tạo link thật qua browser extension. Các provider khác dùng link fake (placeholder) — cần implement sau.
