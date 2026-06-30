# API

## Tổng quan

- Base URL: `https://hoantien.xyz`
- Format: Form submit (POST) + JSON (API endpoints)
- Auth: Laravel session (web routes) + Token (API extension routes)

## Routes

### Web Routes (không auth)

| Method | Path | Controller@Method | Middleware | Mô tả |
|--------|------|-------------------|------------|-------|
| GET | `/` | Closure | web | Welcome page (hoặc redirect dashboard nếu đã login) |
| GET | `/auth/google` | GoogleController@redirect | web | Redirect Google OAuth |
| GET | `/auth/google/callback` | GoogleController@callback | web | Callback Google OAuth |

### Auth Routes (Laravel Breeze — require `routes/auth.php`)

| Method | Path | Controller@Method | Mô tả |
|--------|------|-------------------|-------|
| GET | /login | AuthenticatedSessionController@create | Form login |
| POST | /login | AuthenticatedSessionController@store | Xử lý login |
| POST | /logout | AuthenticatedSessionController@destroy | Logout |
| GET | /register | RegisteredUserController@create | Form register |
| POST | /register | RegisteredUserController@store | Xử lý register |
| GET | /forgot-password | PasswordResetLinkController@create | Form quên mật khẩu |
| POST | /forgot-password | PasswordResetLinkController@store | Gửi email reset |
| GET | /reset-password/{token} | NewPasswordController@create | Form reset password |
| POST | /reset-password | NewPasswordController@store | Xử lý reset |
| GET | /verify-email | EmailVerificationPromptController@__invoke | Nhắc xác thực email |
| POST | /verify-email | EmailVerificationNotificationController@store | Gửi lại email |
| GET | /verify-email/{id}/{hash} | VerifyEmailController@__invoke | Xác thực email |
| GET | /confirm-password | ConfirmablePasswordController@show | Form xác nhận password |
| POST | /confirm-password | ConfirmablePasswordController@store | Xác nhận password |
| PUT | /password | PasswordController@update | Đổi password |

### Protected Routes — auth + verified

| Method | Path | Controller@Method | Mô tả |
|--------|------|-------------------|-------|
| GET | `/dashboard` | DashboardController@index | Dashboard chính |
| POST | `/link-requests` | DashboardController@store | Gửi URL tạo affiliate link |
| POST | `/link-requests/{linkRequest}/toggle-pin` | DashboardController@togglePin | Ghim/bỏ ghim link |

#### POST /link-requests

**Request**:
```
Content-Type: application/x-www-form-urlencoded
_body: original_url=https://shopee.vn/...
```

**Response** (JSON khi expectsJson):
```json
{
    "success": true,
    "request_id": 123,
    "platform": "Shopee"
}
```

**Response** (redirect):
```
Redirect to /dashboard with flash message "Đã nhận link. Đang tạo affiliate link..."
```

**Validation**:
- `original_url`: required, url, max:2048

**Luồng xử lý**:
```
DashboardController@store
  → detectPlatform(url)
  → LinkRequest::create (status=pending cho Shopee, completed cho khác)
  → Nếu Shopee:
    → UrlResolverService::resolve(url)
    → AffiliateCacheService::extractItemId(resolvedUrl)
    → AffiliateCacheService::get(itemId)
    → Nếu cache HIT: update link_request từ cache
    → Nếu cache MISS:
      → ProductDataService::getByUrl(resolvedUrl)
      → CashbackCalculator::calculate(commission, price)
      → AffiliateCacheService::put(itemId, data)
```

#### POST /link-requests/{linkRequest}/toggle-pin

**Request**:
```
POST /link-requests/123/toggle-pin
```

**Response**: Redirect to dashboard

**Rules**:
- Only owner can toggle
- Max 5 pinned links
- Toggle on → set is_pinned=true, pinned_at=now
- Toggle off → set is_pinned=false, pinned_at=null

### Protected Routes — auth (no verified required)

| Method | Path | Controller@Method | Mô tả |
|--------|------|-------------------|-------|
| GET | /profile | ProfileController@edit | Edit profile |
| PATCH | /profile | ProfileController@update | Update profile |
| DELETE | /profile | ProfileController@destroy | Delete account |

### Debug Routes (không auth)

| Method | Path | Controller@Method | Mô tả |
|--------|------|-------------------|-------|
| GET | /debug/provider | ProviderController@index | Form test provider |
| POST | /debug/provider | ProviderController@test | Test provider với URL |
| GET | /debug/worker | WorkerController@index | Worker health status |
| GET | /debug/playwright | PlaywrightController@index | Test Playwright |
| GET | /debug/shopee-login | ShopeeLoginController@index | Shopee login management |
| POST | /debug/shopee-login/check | ShopeeLoginController@login | Check shopee login |
| POST | /debug/shopee-login/interactive | ShopeeLoginController@loginInteractive | Interactive login |
| POST | /debug/shopee-login/session-test | ShopeeLoginController@sessionTest | Test session |
| POST | /debug/shopee-login/dashboard-test | ShopeeLoginController@dashboardTest | Test dashboard |
| POST | /debug/shopee-login/profile-test | ShopeeLoginController@profileTest | Test profile |
| GET | /debug/cookies | CookieDebugController@index | Dump cookie info |
| GET | /debug/set-cookie | CookieDebugController@setCookie | Set test cookie |

### API Extension Routes (token auth)

| Method | Path | Controller@Method | Mô tả |
|--------|------|-------------------|-------|
| GET | /api/extension/jobs?token=... | AffiliateJobController@jobs | Lấy pending jobs |
| POST | /api/extension/results?token=... | AffiliateJobController@result | Gửi kết quả |

#### GET /api/extension/jobs

**Auth**: Query param `token` (config `services.affiliate_extension.token`)

**Response**:
```json
{
    "jobs": [
        {
            "id": 123,
            "original_url": "https://shopee.vn/..."
        }
    ]
}
```

**Luồng**:
```
AffiliateJobController@jobs
  → Kiểm tra token
  → SELECT link_requests WHERE status=pending LIMIT 5
  → UPDATE status=processing WHERE id IN (...)
  → Return jobs list
```

#### POST /api/extension/results

**Auth**: Query param `token`

**Request**:
```json
{
    "results": [
        {
            "id": 123,
            "affiliate_url": "https://shopee.vn/affiliate/...",
            "status": "completed"
        }
    ]
}
```

**Response**:
```json
{
    "ok": true,
    "updated": 1
}
```

**Luồng**:
```
AffiliateJobController@result
  → Kiểm tra token
  → Với mỗi result: UPDATE link_requests SET affiliate_url, status
  → Nếu có item_id: cacheService->updateAffiliateUrl(item_id, url)
```

### API Web Routes (auth required)

| Method | Path | Controller@Method | Mô tả |
|--------|------|-------------------|-------|
| GET | /api/link-request/{id} | LinkRequestController@show | Chi tiết link request |

#### GET /api/link-request/{id}

**Auth**: Laravel session (auth middleware)

**Response**:
```json
{
    "id": 123,
    "status": "completed",
    "original_url": "https://...",
    "affiliate_url": "https://...",
    "estimated_cashback": 12500,
    "user_estimated_cashback": 6750,
    "cashback_rate": 0.60,
    "platform": "Shopee",
    "created_at": "2026-06-30T10:00:00Z"
}
```

## Middleware Summary

| Middleware | Route Group | Mô tả |
|-----------|-------------|-------|
| web | All routes | Session, CSRF, Cookie encryption |
| auth | Protected routes | Yêu cầu đăng nhập |
| verified | Dashboard routes | Yêu cầu email verified |
| Token (custom) | Extension routes | Kiểm tra query param `token` |

## CSRF

- CSRF protection enabled cho tất cả web routes (trừ `api/*`)
- Token được set trong cookie `XSRF-TOKEN` (SameSite=Lax, Secure)
- Blade `@csrf` directive trong form POST
