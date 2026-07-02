# API Endpoints

## Public Routes

| Method | URI | Controller | Description |
|--------|-----|------------|-------------|
| GET | `/` | Closure | Welcome page or dashboard redirect |
| GET | `/auth/google` | `GoogleController@redirect` | Google OAuth redirect |
| GET | `/auth/google/callback` | `GoogleController@callback` | Google OAuth callback |

## Authentication Routes (`routes/auth.php`)

All auth routes use Laravel Breeze scaffolding:

| Method | URI | Name |
|--------|-----|------|
| GET / POST | `/register` | `register` |
| GET / POST | `/login` | `login` |
| POST | `/logout` | `logout` |
| GET / POST | `/forgot-password` | `password.request` |
| GET / POST | `/reset-password/{token}` | `password.reset` |
| GET | `/verify-email` | `verification.notice` |
| GET | `/verify-email/{id}/{hash}` | `verification.verify` |
| POST | `/email/verification-notification` | `verification.send` |
| GET / POST | `/confirm-password` | `password.confirm` |
| PUT | `/password` | `password.update` |

## Authenticated Routes

| Method | URI | Controller | Name | Middleware |
|--------|-----|------------|------|------------|
| GET | `/dashboard` | `DashboardController@index` | `dashboard` | `auth,verified` |
| POST | `/link-requests` | `DashboardController@store` | `link-requests.store` | `auth,verified` |
| POST | `/link-requests/{link}/toggle-pin` | `DashboardController@togglePin` | `link-requests.toggle-pin` | `auth,verified` |
| GET | `/profile` | `ProfileController@edit` | `profile.edit` | `auth` |
| PATCH | `/profile` | `ProfileController@update` | `profile.update` | `auth` |
| DELETE | `/profile` | `ProfileController@destroy` | `profile.destroy` | `auth` |

## Extension API Routes (CSRF Exempt)

These routes are exempt from CSRF verification (configured in `bootstrap/app.php`). Authenticated via `token` query parameter.

### `GET /api/extension/jobs?token=...`

**Controller:** `Api\AffiliateJobController@jobs`

Polls pending jobs for the browser extension.

**Response:**
```json
{
  "jobs": [
    {
      "id": 1,
      "original_url": "https://shopee.vn/product/...",
      "item_id": 123456789
    }
  ]
}
```

- Returns up to 5 pending jobs (configurable in controller)
- Marks returned jobs as `processing`
- Only returns jobs with status `pending`
- Jobs from user whose token matches `config('services.affiliate_extension.token')`

### `POST /api/extension/results?token=...`

**Controller:** `Api\AffiliateJobController@result`

Posts results back from the browser extension.

**Request body:**
```json
{
  "results": [
    {
      "id": 1,
      "affiliate_url": "https://shortlink.shopee.vn/...",
      "status": "completed"
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "processed": 1
}
```

- Updates `LinkRequest` with `affiliate_url` and marks as `completed`
- If `status` is `failed`, marks LinkRequest as `failed`
- Also updates `AffiliateCache.affiliate_url` via `AffiliateCacheService::updateAffiliateUrl()`

### `GET /api/link-request/{id}`

**Controller:** `Api\LinkRequestController@show`

Returns a single LinkRequest for dashboard polling.

**Headers:** Must include `X-Requested-With: XMLHttpRequest`

**Response:**
```json
{
  "id": 1,
  "status": "completed",
  "affiliate_url": "https://shortlink.shopee.vn/...",
  "user_estimated_cashback": 15000,
  "platform": "Shopee",
  "product_name": "...",
  "product_price": 100000,
  "product_image": "...",
  "shop_name": "...",
  "original_url": "...",
  "is_pinned": false,
  "created_at": "..."
}
```

- Scoped to authenticated user
- Used by Alpine.js `startPolling()` in `link-generator.blade.php`

## Debug Routes

| Method | URI | Description |
|--------|-----|-------------|
| GET | `/debug/provider` | Provider detection tests |
| POST | `/debug/provider` | Test specific provider |
| GET | `/debug/worker` | Worker connection health check |
| GET | `/debug/playwright` | Playwright test (deprecated) |
| GET | `/debug/shopee-login` | Shopee session management UI |
| POST | `/debug/shopee-login/check` | Check login status |
| POST | `/debug/shopee-login/interactive` | Interactive login |
| POST | `/debug/shopee-login/session-test` | Test session |
| POST | `/debug/shopee-login/dashboard-test` | Test dashboard access |
| POST | `/debug/shopee-login/profile-test` | Test profile access |
| GET | `/debug/cookies` | View/debug cookies |
| GET | `/debug/set-cookie` | Set test cookie |

## CSRF Exemption

All `/api/*` routes are CSRF exempt, configured in `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: [
        'api/*',
    ]);
})
```

Used by:
- Browser extension (no CSRF cookie available)
- Dashboard Alpine.js polling (`/api/link-request/{id}` uses `X-Requested-With` header instead)

## Authentication for Extension

Extension uses `token` query parameter, compared against `config('services.affiliate_extension.token')` (from `.env` `AFFILIATE_EXTENSION_TOKEN`).

Default token: `hoantien-affiliate-extension-2026`
