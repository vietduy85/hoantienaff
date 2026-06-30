# File Reference

## Tổng quan

File này liệt kê TẤT CẢ file quan trọng trong project, vai trò, method chính, ai gọi, gọi đến đâu.

---

## AFFILIATE-WORKER

### `affiliate-worker/browser-extension/manifest.json`

- **Role**: Chrome Extension manifest (MV3)
- **Key fields**: `permissions`, `host_permissions`, `background`, `content_scripts`
- **Match patterns**: `https://affiliate.shopee.vn/offer/custom_link*`
- **Background**: Persistent script `background.js`

### `affiliate-worker/browser-extension/background.js`

- **Role**: Extension background script — poll Laravel API, manage tabs, dispatch jobs
- **Key functions**:
  - `startPolling()`: Poll jobs API every 3s
  - `stopPolling()`: Stop polling
  - `updatePopup()`: Update popup UI state
  - `loadConfig()`: Load apiUrl + token from storage
- **Calls**: `GET {apiUrl}/api/extension/jobs?token=...`, `POST {apiUrl}/api/extension/results?token=...`
- **Called by**: Chrome runtime (background service worker)
- **Calls to**: content.js (chrome.tabs.sendMessage)

### `affiliate-worker/browser-extension/content.js`

- **Role**: Content script injected into affiliate.shopee.vn — automate custom link creation
- **Key functions**:
  - `setReactValue(element, value)`: Set React-controlled input value
  - Message listener: `processJobs` — process batch of 5 URLs
- **Calls to**: DOM elements on affiliate.shopee.vn
- **Called by**: background.js (chrome.runtime.sendMessage)

### `affiliate-worker/browser-extension/popup.js`

- **Role**: Extension popup configuration UI
- **Key features**:
  - Config: apiUrl, token, isRunning toggle
  - Display: status, last poll time
- **Called by**: User clicking extension icon

### `affiliate-worker/browser-extension/popup.html`

- **Role**: Popup HTML UI

---

### `affiliate-worker/server.js`

- **Role**: Express HTTP server trên port 3001
- **Endpoints**: GET /health, POST /shopee/create-link, ...
- **Status**: Deprecated (CDP bị chặn)
- **Dependency**: express

---

## APP — Controllers

### `app/Http/Controllers/DashboardController.php`

- **Role**: Xử lý dashboard + link request submission
- **Methods**:
  | Method | Route | Mô tả |
  |--------|-------|-------|
  | `index()` | GET /dashboard | Hiển thị dashboard với pinned + recent links |
  | `store()` | POST /link-requests | Xử lý URL user gửi |
  | `togglePin()` | POST /link-requests/{id}/toggle-pin | Ghim/bỏ ghim |
- **Dependencies**:
  - `ProductDataService` — getByUrl()
  - `CashbackCalculator` — calculate()
  - `AffiliateCacheService` — get(), put(), logMiss(), extractItemId()
  - `UrlResolverService` — resolve()
- **Calls to**: Model LinkRequest, Services
- **Called by**: Routes (web.php)

### `app/Http/Controllers/Api/AffiliateJobController.php`

- **Role**: API endpoint cho browser extension — lấy jobs + nhận kết quả
- **Methods**:
  | Method | Route | Mô tả |
  |--------|-------|-------|
  | `jobs()` | GET /api/extension/jobs?token=... | Lấy pending jobs (limit 5) |
  | `result()` | POST /api/extension/results?token=... | Nhận kết quả từ extension |
- **Dependencies**: `AffiliateCacheService` — updateAffiliateUrl()
- **Auth**: Token query param

### `app/Http/Controllers/Api/LinkRequestController.php`

- **Role**: API trả chi tiết link request
- **Methods**: `show()` — GET /api/link-request/{id}
- **Auth**: Laravel session (auth middleware)

### `app/Http/Controllers/Auth/GoogleController.php`

- **Role**: Google OAuth login
- **Methods**:
  | Method | Route | Mô tả |
  |--------|-------|-------|
  | `redirect()` | GET /auth/google | Redirect Google |
  | `callback()` | GET /auth/google/callback | Xử lý callback |
- **Dependency**: Socialite

### `app/Http/Controllers/Auth/AuthenticatedSessionController.php`

- **Role**: Email/password login
- **Methods**: `create()`, `store()`, `destroy()`

### `app/Http/Controllers/ProfileController.php`

- **Role**: User profile CRUD
- **Methods**: `edit()`, `update()`, `destroy()`

---

## APP — Debug Controllers

### `app/Http/Controllers/Debug/ProviderController.php`

- **Role**: Test provider detection + link generation
- **Methods**: `index()`, `test()`
- **Dependency**: ProviderFactory

### `app/Http/Controllers/Debug/WorkerController.php`

- **Role**: Worker health check UI
- **Methods**: `index()`
- **Dependency**: AffiliateWorkerClient

### `app/Http/Controllers/Debug/PlaywrightController.php`

- **Role**: Test Playwright connectivity
- **Methods**: `index()`
- **Dependency**: AffiliateWorkerClient

### `app/Http/Controllers/Debug/ShopeeLoginController.php`

- **Role**: Shopee session management + login testing
- **Methods**: `index()`, `login()`, `loginInteractive()`, `sessionTest()`, `dashboardTest()`, `profileTest()`
- **Dependency**: AffiliateWorkerClient

### `app/Http/Controllers/Debug/CookieDebugController.php`

- **Role**: Debug cookies + session config
- **Methods**:
  - `index()`: Dump all cookies, headers, session, config
  - `setCookie()`: Set test session + dump config

---

## APP — Models

### `app/Models/User.php`

- **Role**: User model + roles (Spatie Permission)
- **Relationships**: linkRequests, transactions, withdrawals, clicks, purchases, merchants
- **Methods**: `isAdmin()`, `isMerchant()`, `isAffiliate()`, `isMember()`, `generateReferralCode()`
- **Boot events**: Auto-generate referral_code on creating

### `app/Models/LinkRequest.php`

- **Role**: Link request model
- **Relationships**: user (BelongsTo)
- **Scopes**: pending, processing, completed, pinned, forUser
- **Accessors**: `getShortUrlAttribute()`
- **Statuses**: pending, processing, completed, rejected

### `app/Models/AffiliateCache.php`

- **Role**: Affiliate cache model
- **Table**: `affiliate_cache`
- **PK**: `item_id` (not auto-increment)
- **Fields**: cache_date, product data, cashback, affiliate_url

### `app/Models/Campaign.php`

- **Role**: Campaign model (chưa dùng)
- **Relationships**: merchant, category, clicks, purchases

### `app/Models/CampaignCategory.php`, `Click.php`, `Merchant.php`, `Purchase.php`, `Transaction.php`, `Withdrawal.php`, `Setting.php`

- **Role**: Mô hình business logic (hầu hết chưa có Controller/Service dùng)

---

## APP — Services

### `app/Services/AffiliateCacheService.php`

- **Role**: Cache management
- **Methods**: `get()`, `put()`, `logMiss()`, `updateAffiliateUrl()`, `extractItemId()`, `getCacheDate()`
- **Calls to**: Model AffiliateCache
- **Called by**: DashboardController, AffiliateJobController

### `app/Services/ProductDataService.php`

- **Role**: Fetch product data from AddLiveTag API
- **Methods**: `getByUrl()`, `getByItemId()`, `extractProductIds()`, `mapResponse()`
- **Calls to**: API data.addlivetag.com
- **Called by**: DashboardController

### `app/Services/CashbackCalculator.php`

- **Role**: Calculate cashback from commission
- **Methods**: `calculate()`
- **Called by**: DashboardController

### `app/Services/UrlResolverService.php`

- **Role**: Resolve short links (s.shopee.vn)
- **Methods**: `resolve()`, `isShortLink()`, `expandShortUrl()`
- **Called by**: DashboardController

### `app/Services/ProviderFactory.php`

- **Role**: Detect platform + return provider
- **Methods**: `detectPlatform()`, `getProvider()`, `getAllProviders()`
- **Called by**: Debug ProviderController
- **Dependency**: Tagged providers array

### `app/Services/AffiliateService.php`

- **Role**: Facade cho provider system (hiện không dùng trong controller)
- **Methods**: `generateLink()`

### `app/Services/AffiliateWorkerClient.php`

- **Role**: HTTP client to Node worker
- **Methods**: `health()`, `createLink()`, `ping()`, `testPlaywright()`, `shopeeLogin()`, ...
- **Calls to**: http://127.0.0.1:3001
- **Called by**: Debug WorkerController, ShopeeLoginController, PlaywrightController, ShopeeProvider

### `app/Services/Providers/ShopeeProvider.php`

- **Role**: Shopee affiliate link generation
- **Methods**: `createLink()`, `supportedPlatform()`
- **Calls to**: AffiliateWorkerClient::createLink()

### `app/Services/Providers/LazadaProvider.php`

### `app/Services/Providers/TikTokProvider.php`

### `app/Services/Providers/LongChauProvider.php`

### `app/Services/Providers/PharmacityProvider.php`

### `app/Services/Providers/TravelokaProvider.php`

### `app/Services/Providers/AgodaProvider.php`

### `app/Services/Providers/BookingProvider.php`

- **Role**: Platform-specific providers (tất cả đều fake link trừ Shopee)
- **Methods**: `createLink()`, `supportedPlatform()`

---

## APP — Others

### `app/Enums/Platform.php`

- **Role**: Platform enum
- **Values**: SHOPEE, LAZADA, TIKTOK, LONG_CHAU, PHARMACITY, TRAVELOKA, AGODA, BOOKING, OTHER
- **Method**: `label()` — trả về tên hiển thị

### `app/Contracts/AffiliateProviderInterface.php`

- **Role**: Interface cho tất cả provider
- **Methods**: `createLink(string $url): array`, `supportedPlatform(): Platform`

### `app/Providers/AppServiceProvider.php`

- **Role**: Service registration + boot logic
- **register()**: Tag providers, inject into ProviderFactory
- **boot()**: Force HTTPS scheme

---

## CONFIG

### `config/app.php`

- **Key values**: `name`, `env`, `debug`, `url`, `timezone` (Asia/Ho_Chi_Minh), `locale` (vi), `affiliate_timing`

### `config/session.php`

- **Key values**: driver, cookie, path, domain, secure, http_only, same_site, lifetime

### `config/services.php`

- **Key values**: Google OAuth, affiliate_worker.url, affiliate_extension.token

### `config/cache.php`

- **Key values**: default store (database), store configs

### `config/database.php`

- **Key values**: mysql connection config

### `config/permission.php`

- **Key values**: Spatie Permission config (models, table names, cache)

---

## BOOTSTRAP

### `bootstrap/app.php`

- **Role**: Laravel 11 application bootstrap
- **Middleware**: `trustProxies(at: '*')`, CSRF except `api/*`

---

## ROUTES

### `routes/web.php`

- **Role**: All web routes definitions
- **Groups**: public, auth, auth+verified, debug, api/extension, api/link-request
- **Requires**: `require __DIR__.'/auth.php'`

### `routes/auth.php`

- **Role**: Laravel Breeze auth routes (login, register, password, verify, confirm)

---

## DATABASE

### `database/migrations/`

22 migration files:
| File | Table | Purpose |
|------|-------|---------|
| 0001_01_01_000000 | users, sessions, password_reset_tokens | Base Laravel |
| 0001_01_01_000001 | cache, cache_locks | Cache |
| 0001_01_01_000002 | jobs, job_batches, failed_jobs | Queue |
| 2026_06_21_230746 | permissions, roles, ... | Spatie Permission |
| 2026_06_21_230747 | users (add affiliate fields) | Referral, wallet |
| 2026_06_21_230748 | campaign_categories | Campaign categories |
| 2026_06_21_230749 | merchants | Merchants |
| 2026_06_21_230750 | campaigns | Campaigns |
| 2026_06_21_230751 | clicks | Click tracking |
| 2026_06_21_230752 | purchases | Purchase tracking |
| 2026_06_21_230753 | transactions | Wallet transactions |
| 2026_06_21_230754 | withdrawals | Withdrawal requests |
| 2026_06_21_230755 | settings | Dynamic settings |
| 2026_06_21_230756 | link_requests | Link requests |
| 2026_06_22_005412 | link_requests (add pinned_at) | Pin feature |
| 2026_06_23_212536 | users (add google_id) | Google login |
| 2026_06_27_161841 | link_requests (add product data) | Product info |
| 2026_06_27_170358 | link_requests (add shop_id) | Shop info |
| 2026_06_29_111948 | link_requests (add cashback fields) | Cashback |
| 2026_06_29_120000 | affiliate_cache | Affiliate cache |

---

## VIEWS (resources/views/)

### Dashboard
- `dashboard.blade.php` — Main dashboard
- `dashboard/partials/greeting-card.blade.php` — Welcome card
- `dashboard/partials/link-generator.blade.php` — URL input form
- `dashboard/partials/affiliate-result.blade.php` — Result display
- `dashboard/partials/pinned-links.blade.php` — Pinned links list
- `dashboard/partials/recent-links.blade.php` — Recent links list

### Auth
- `auth/login.blade.php`, `register.blade.php`, `forgot-password.blade.php`, `reset-password.blade.php`, `verify-email.blade.php`, `confirm-password.blade.php`

### Debug
- `debug/worker.blade.php`, `debug/playwright.blade.php`, `debug/provider.blade.php`, `debug/shopee-login.blade.php`

### Layouts
- `layouts/app.blade.php` — Authenticated layout
- `layouts/guest.blade.php` — Guest layout
- `layouts/navigation.blade.php` — Navigation menu

### Components
- `components/application-logo.blade.php`
- `components/dropdown.blade.php`, `dropdown-link.blade.php`
- `components/nav-link.blade.php`, `responsive-nav-link.blade.php`
- `components/input-label.blade.php`, `text-input.blade.php`, `input-error.blade.php`
- Components buttons: primary, secondary, danger
- `components/auth-session-status.blade.php`, `components/modal.blade.php`
- `components/dashboard/platform-badge.blade.php`, `status-badge.blade.php`

### Profile
- `profile/edit.blade.php`
- `profile/partials/update-profile-information-form.blade.php`
- `profile/partials/update-password-form.blade.php`
- `profile/partials/delete-user-form.blade.php`
