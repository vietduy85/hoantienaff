# Development Setup

## Local Environment

### Requirements
- PHP ^8.2
- Composer
- MySQL (or MariaDB)
- Node.js (for extension testing)
- Chrome browser
- Git

### Steps

```bash
# 1. Clone repository
git clone <repo-url> hoantienaff
cd hoantienaff

# 2. Install PHP dependencies
composer install

# 3. Install Node dependencies (for Vite/Breeze)
npm install

# 4. Environment
cp .env.example .env
php artisan key:generate

# Edit .env:
# - DB_DATABASE, DB_USERNAME, DB_PASSWORD
# - APP_URL=http://localhost:8000 (for artisan serve)
# - AFFILIATE_EXTENSION_TOKEN=your-token-here

# 5. Database
php artisan migrate
php artisan db:seed

# 6. Run the dev server
php artisan serve
```

### Loading the Extension

1. Open `chrome://extensions/`
2. Enable "Developer mode"
3. Click "Load unpacked"
4. Select `affiliate-worker/browser-extension/`

### Extension Configuration

1. Click extension icon → popup
2. API URL: `http://localhost:8000` (matching `php artisan serve`)
3. Token: same as `AFFILIATE_EXTENSION_TOKEN` in `.env`
4. Click Save

### Debugging Tools

**Laravel timing logs:**
```bash
# In .env, set:
AFFILIATE_TIMING=true
```

**Extension logs:**
- Background: `chrome://extensions/` → Find extension → "Service Worker" link → Console tab
- Content script: Open DevTools on `affiliate.shopee.vn` → Console tab

**Benchmark command:**
```bash
php artisan benchmark:affiliate {url?}
```

**Debug routes:**
- `/debug/provider` — Test provider detection and link creation
- `/debug/worker` — Check worker health
- `/debug/shopee-login` — Shopee session testing

### Running Workers

**Artisan Queue Worker** (if using queue for async jobs):
```bash
php artisan queue:work
```

**Extension Worker:**
The extension itself is the "worker". It polls the API automatically when loaded in Chrome.

## Project Structure for Development

```
hoantienaff/
├── app/
│   ├── Console/Commands/       # Artisan commands
│   ├── Enums/                  # Platform enum
│   ├── Http/
│   │   ├── Controllers/        # Web + API + Debug controllers
│   │   │   ├── Api/            # Extension API endpoints
│   │   │   └── Debug/          # Debug/test controllers
│   │   └── Requests/           # Form requests
│   ├── Models/                 # Eloquent models
│   ├── Providers/              # Service providers
│   └── Services/               # Business logic
│       └── Providers/          # Platform-specific providers
├── affiliate-worker/
│   ├── browser-extension/      # Chrome extension (ACTIVE)
│   │   ├── manifest.json
│   │   ├── background.js
│   │   ├── content.js
│   │   ├── popup.html
│   │   └── popup.js
│   └── playwright/             # DEPRECATED - CDP approach
├── config/                     # Laravel config
├── database/
│   ├── migrations/             # 22 migrations
│   └── seeders/                # DB seeders
├── resources/views/            # Blade templates (38 files)
└── routes/                     # Route definitions
```

## Common Development Tasks

### Adding a New Provider

1. Create provider class in `app/Services/Providers/` implementing `AffiliateProviderInterface`
2. Register in `AppServiceProvider` tag list
3. Add URL pattern to `ProviderFactory::detectPlatform()`

### Modifying the Extension

1. Edit `background.js` → reload extension in `chrome://extensions/`
2. Edit `content.js` → refresh `affiliate.shopee.vn` tab
3. Changes take effect immediately after reload

### Testing Race Conditions

To test the parallel Worker + AddLiveTag flow:
1. Create a LinkRequest for a new product (cache MISS)
2. Watch `afterResponse()` execute in Laravel logs
3. Watch extension poll and process in extension console
4. Verify both complete without data loss
