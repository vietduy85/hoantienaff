# Deployment Guide

## Prerequisites

- PHP ^8.2
- Composer
- MySQL
- Node.js (for Chrome extension development only)
- Chrome browser (for extension)

## Laravel Application

### Environment Setup

```bash
cp .env.example .env
# Edit .env with your database credentials, app URL, etc.
php artisan key:generate
```

### Key .env Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_URL` | Application URL | `http://localhost` |
| `APP_ENV` | Environment | `production` |
| `APP_DEBUG` | Debug mode | `false` |
| `AFFILIATE_EXTENSION_TOKEN` | Shared secret for extension API | `hoantien-affiliate-extension-2026` |
| `AFFILIATE_TIMING` | Enable timing logs | `false` |

### Database

```bash
php artisan migrate
php artisan db:seed
```

This creates all tables and seeds:
- Admin user: `admin@hoantien.local` / `password`
- Roles: Admin, Merchant, Affiliate, Member
- Permissions: users, campaigns, cashback, withdrawals, settings

### Web Server

Point document root to `public/`.

**For Apache:** Ensure `mod_rewrite` is enabled and `.htaccess` in `public/` is honored.

**For Nginx:**
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### CSRF Exemption

The `/api/*` routes are CSRF exempt. This is configured in `bootstrap/app.php`:
```php
$middleware->validateCsrfTokens(except: ['api/*']);
```

## Chrome Extension

### Loading in Chrome (Development)

1. Open `chrome://extensions/`
2. Enable "Developer mode" (toggle in top-right)
3. Click "Load unpacked"
4. Select `affiliate-worker/browser-extension/` directory

### Configuration

After loading the extension:
1. Click the extension icon → popup opens
2. Set API URL to your Laravel app URL (e.g., `https://hoantien.xyz`)
3. Set token to `AFFILIATE_EXTENSION_TOKEN` from `.env`
4. Click Save

### Production Deployment

The extension must be:
1. Packaged as a `.zip` via `chrome://extensions/` → "Pack extension"
2. Uploaded to Chrome Web Store for distribution
3. Or distributed via Group Policy for managed devices

**Host permissions** in `manifest.json` must match your production domain:
```json
"host_permissions": [
    "https://affiliate.shopee.vn/*",
    "https://yourdomain.com/*"
]
```

Shopee tab must be open and logged into the affiliate account (`affiliate.shopee.vn/offer/custom_link`).

## Node.js Worker (DEPRECATED)

The Express server on port 3001 is no longer used for link creation (Playwright approach blocked). It can still run for diagnostics:

```bash
cd affiliate-worker
npm install
npm start   # or: node server.js
```

## Monitoring

- **Queue:** `failed_jobs` table tracks any async job failures
- **Logs:** `storage/logs/laravel.log`
- **Extension:** Service worker console logs (accessible via `chrome://extensions/` → service worker link)
- **Timing:** Set `AFFILIATE_TIMING=true` in `.env` to log [CACHE], [Resolver], [CACHE-Timing] entries

## Troubleshooting

See `docs/troubleshooting.md` for common issues.
