# Cấu trúc thư mục

## Tổng quan

```
C:\xampp\htdocs\hoantienaff\
├── affiliate-worker/          # Node.js worker (Browser Extension + Playwright CDP)
│   ├── browser-extension/     # ✅ ĐANG DÙNG — Chrome Extension MV3
│   │   ├── manifest.json      #   Manifest V3, match affiliate.shopee.vn
│   │   ├── background.js      #   Poll API mỗi 3s, quản lý tab, gửi job
│   │   ├── content.js         #   Inject vào custom link page, thao tác form
│   │   ├── popup.html         #   UI popup extension
│   │   ├── popup.js           #   Cấu hình API URL + token
│   │   └── icons/             #   Icon extension
│   ├── playwright/            # ❌ DEAD — Shopee chặn DevTools Protocol
│   │   └── cdp/               #   ChromeManager, AffiliateNavigator, v.v.
│   ├── storage/               #   Shopee state, debug dumps
│   ├── tests/                 #   Test scripts
│   ├── server.js              #   Express API (:3001) cho Laravel gọi
│   ├── package.json
│   └── .env                   #   Token xác thực worker
│
├── app/                       # Laravel application
│   ├── Console/               #   Artisan commands
│   ├── Contracts/             #   Interfaces
│   │   └── AffiliateProviderInterface.php
│   ├── Enums/                 #   PHP Enums
│   │   └── Platform.php       #   Platform: Shopee, Lazada, TikTok, ...
│   ├── Http/
│   │   ├── Controllers/       #   HTTP controllers
│   │   │   ├── Auth/          #   Authentication controllers
│   │   │   ├── Api/           #   API controllers (extension, link request)
│   │   │   └── Debug/         #   Debug controllers
│   │   ├── Middleware/        #   (empty — Laravel 11 dùng bootstrap/app.php)
│   │   └── Requests/          #   Form requests
│   ├── Models/                #   Eloquent models
│   ├── Providers/             #   Service providers
│   │   └── AppServiceProvider.php
│   └── Services/              #   Business logic services
│       └── Providers/         #   Platform-specific affiliate providers
│
├── bootstrap/                 # Laravel bootstrap
│   └── app.php                #   Middleware, CSRF config
│
├── config/                    # Laravel config files
│   ├── app.php                #   APP_NAME, APP_URL, timezone, ...
│   ├── auth.php               #   Auth guards, providers
│   ├── cache.php              #   Cache driver (database/file/redis)
│   ├── database.php           #   DB connections (MySQL default)
│   ├── filesystems.php        #   Filesystem disks
│   ├── logging.php            #   Log channels (stack/single/daily)
│   ├── mail.php               #   Mail drivers
│   ├── permission.php         #   Spatie Permission config
│   ├── queue.php              #   Queue driver (database)
│   ├── services.php           #   3rd-party: Google, affiliate_worker, extension
│   └── session.php            #   Session driver, cookie, same_site, secure
│
├── database/                  # Database
│   ├── migrations/            #   22 migration files
│   ├── factories/             #   Model factories
│   └── seeders/               #   Database seeders
│
├── resources/                 # Frontend resources
│   └── views/                 #   Blade templates
│       ├── auth/              #   Login, register, password reset
│       ├── components/        #   Blade components (UI)
│       ├── dashboard/         #   Dashboard partials
│       ├── debug/             #   Debug pages (worker, playwright, login, provider)
│       ├── layouts/           #   App & guest layouts
│       └── profile/           #   Profile pages
│
├── routes/                    # Route definitions
│   ├── web.php                #   Web routes
│   ├── auth.php               #   Auth routes (Laravel Breeze)
│   └── console.php            #   Artisan console commands
│
├── public/                    # Web root
│   └── index.php              #   Entry point
│
├── storage/                   # Storage
│   ├── app/                   #   Application storage
│   ├── framework/             #   Cache, sessions, views
│   └── logs/                  #   Log files
│
├── tests/                     # Unit & Feature tests
│
├── vendor/                    # Composer dependencies
├── node_modules/              # NPM dependencies
│
├── .env                       # Environment variables
├── .env.example               # Environment example
├── composer.json
├── package.json
├── vite.config.js
├── tailwind.config.js
│
├── start-worker.bat           # Start Node worker (production)
├── start-worker-debug.bat     # Start Node worker (debug)
├── start-chrome-cdp.bat       # Start Chrome with CDP
├── start-affiliate-system.bat # Start all affiliate system
├── test-worker.bat            # Test worker connectivity
├── create-link.bat            # Manual link creation test
│
├── README.md                  # Project README
├── PROJECT_CONTEXT.md         # Context cho AI
└── product-data-api.md        # API documentation
```

## Vai trò từng thư mục

| Thư mục | Vai trò |
|----------|---------|
| `affiliate-worker/` | Worker độc lập chạy Node.js. Chứa browser extension và (deprecated) Playwright CDP. |
| `app/Http/Controllers/` | Xử lý HTTP request, gọi Service, trả response. |
| `app/Http/Controllers/Api/` | API endpoint cho browser extension (jobs, results). |
| `app/Http/Controllers/Debug/` | Debug tools: worker health, cookie, playwright, shopee login. |
| `app/Models/` | Eloquent models, relationships, scopes. |
| `app/Services/` | Business logic: cache, product data, cashback, URL resolver. |
| `app/Services/Providers/` | Platform-specific affiliate link generation (Shopee, Lazada, ...). |
| `config/` | Application configuration: session, services, database, cache. |
| `database/migrations/` | Database schema definitions (22 migrations). |
| `resources/views/` | Blade templates (39 files) — UI components + layouts. |
| `routes/` | Route definitions: web + auth + console. |
| `public/` | Web root — all requests enter here via index.php. |
| `storage/` | Logs, cache, compiled views. |
