# TODO / Known Issues

## Critical Issues

### 1. iPhone Safari không lưu Session Cookie

- **Mô tả**: iPhone Safari không lưu `XSRF-TOKEN` và `hoan-tien-mua-sam-session` cookies
- `remember_web` cookie từ login cũ vẫn gửi được
- **Triệu chứng**: POST /login → 419 CSRF token mismatch
- **Tác động**: Không thể login hoặc submit form từ iPhone Safari/Chrome
- **Debug endpoint**: `/debug/cookies` so sánh laptop vs iPhone
- **Nguyên nhân khả quan**:
  - `SESSION_SECURE_COOKIE=true` + `SESSION_SAME_SITE=lax` — iOS Safari ITP
  - Cookie value encrypted quá lớn
  - Cloudflare Tunnel response header modification
- **Status**: TODO — Đang điều tra

### 2. CSRF Token Mismatch trên POST

- **Mô tả**: 419 error khi submit form
- **Liên quan**: iPhone cookie issue
- **Cũng có thể xảy ra** khi session database bị xóa hoặc timeout

## Browser Extension Issues

### 3. Content Script gửi job đến tất cả tab

- **File**: `affiliate-worker/browser-extension/background.js`
- **Mô tả**: `chrome.tabs.query({})` không filter domain → gửi job đến tab không phải Shopee
- **Fix**: Cần filter `url: 'https://affiliate.shopee.vn/*'`

### 4. Content Script không kiểm tra element tồn tại

- **File**: `affiliate-worker/browser-extension/content.js`
- **Mô tả**: Cần kiểm tra textarea/input tồn tại trước khi set value
- **Risk**: Nếu Shopee thay đổi UI, script sẽ fail silently

### 5. Thiếu retry mechanism

- **Mô tả**: Khi job fail (CAPTCHA, network error), không có retry
- **Ảnh hưởng**: Link request stuck ở status "processing"
- **Fix**: Retry limit + fallback timeout

### 6. No rate limiting

- **Mô tả**: Content script xử lý batch 5 URLs không có delay giữa các request
- **Risk**: Shopee có thể ban account nếu request quá nhanh

### 7. CAPTCHA handling incomplete

- **Mô tả**: Khi phát hiện CAPTCHA, script chỉ report fail nhưng không có cơ chế thông báo user
- **Cần**: Thông báo user cần giải CAPTCHA thủ công

## Fake Providers

### 8. Hầu hết Provider trả link fake

- **Danh sách**: Lazada, TikTok, LongChâu, Pharmacity, Traveloka, Agoda, Booking
- **Mô tả**: Các provider này trả link `domain/affiliate/{encoded_url}` không phải link thật
- **Cần implement**: Tích hợp API affiliate thực tế cho từng platform

## Code Quality

### 9. ProductDataService thiếu local cache

- **File**: `app/Services/ProductDataService.php`
- **TODO**: Thêm cache layer (Redis với 24h TTL) cho frequently accessed products
- **Hiện tại**: Mỗi request cache MISS gọi AddLiveTag API

### 10. URL Resolver không có cache

- **File**: `app/Services/UrlResolverService.php`
- **TODO**: Cache kết quả resolve short link (URL mapping thường không đổi)
- **Hiện tại**: Mỗi short link đều follow redirect từ đầu

### 11. DashboardController quá lớn

- **File**: `app/Http/Controllers/DashboardController.php`
- **store() method**: ~150 dòng — quá nhiều logic
- **Cần refactor**: Tách logic store() thành Service riêng

### 12. DetectPlatform duplicate logic

- **File**: `app/Http/Controllers/DashboardController.php` — detectPlatform()
- **File**: `app/Services/ProviderFactory.php` — detectPlatform()
- **Issue**: Cùng logic detect platform nhưng implement riêng biệt
- **Fix**: Dùng ProviderFactory trong DashboardController

### 13. CashbackCalculator không lưu log

- **Mô tả**: Không có audit trail cho tính toán cashback
- **Cần**: Log chi tiết từng bước tính toán (commission → net → user)

## Database

### 14. Nhiều bảng chưa sử dụng

- **Bảng**: campaigns, campaign_categories, merchants, clicks, purchases, transactions, withdrawals
- **Mô tả**: Đã migration nhưng chưa có Controller/Service sử dụng
- **Cần**: Implement UI cho các tính năng này hoặc drop nếu không dùng

### 15. Thiếu index cho affiliate_cache

- **Mô tả**: `affiliate_cache` không có index trên `cache_date`
- **Ảnh hưởng**: Query `WHERE item_id=? AND cache_date=?` chỉ dùng được PK index (item_id)

## Security

### 16. API Extension token trong query param

- **Mô tả**: Token được gửi trong URL query param
- **Risk**: Token có thể bị log trong server logs, browser history
- **Cần**: Chuyển sang header Authorization hoặc POST body

### 17. Debug routes không auth

- **Mô tả**: Tất cả debug routes (`/debug/*`) không yêu cầu auth
- **Risk**: Thông tin hệ thống có thể bị leak
- **Fix**: Thêm IP restriction hoặc auth middleware

### 18. SESSION_DOMAIN=null trong .env

- **Mô tả**: `.env` có `SESSION_DOMAIN=null` (string)
- **Laravel parsing**: Dotenv parser chuyển `null` thành PHP null → hoạt động đúng
- **Nhưng**: Có thể gây nhầm lẫn cho dev mới

## Performance

### 19. Không có CDN cho static assets

- **Mô tả**: CSS, JS, images serve trực tiếp từ origin server
- **Cần**: Cấu hình Cloudflare cache cho static assets

### 20. Session database query mỗi request

- **Mô tả**: Session driver là database → mỗi request đều query sessions table
- **Cần**: Cân nhắc Redis cho session nếu scale

## DevOps

### 21. Thiếu CI/CD

- **Mô tả**: Không có pipeline tự động
- **Cần**: GitHub Actions cho test + deploy

### 22. Không có staging environment

- **Mô tả**: Chỉ có production environment
- **Risk**: Thay đổi trực tiếp trên production

### 23. Phụ thuộc vào local XAMPP

- **Mô tả**: Server chạy XAMPP (Windows)
- **Risk**: Khó scale, không phù hợp production

## Testing

### 24. Thiếu unit tests

- **Mô tả**: Chưa có test cho Services
- **Cần**: Test cho CashbackCalculator, UrlResolverService, ProductDataService

### 25. Thiếu feature tests

- **Mô tả**: Chưa có test cho HTTP endpoints
- **Cần**: Test cho POST /link-requests, GET /dashboard
