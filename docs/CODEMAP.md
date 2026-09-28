# HoanTien Affiliate — Codebase Map & Hosting Audit

> Created 2026-09-26 from a full read-only audit of `C:\xampp\htdocs\hoantienaff` at HEAD `d5e6518f8a789aeee394fdb278c1ccdf9d2e26b2` (branch `main`).
> This document is the single source of truth for any future AI/session; read it instead of re-auditing.
> Repo root has a large amount of junk (see §11); ignore it. The handful of `docs/*.md` (esp. `architecture.md`, `worker-extension.md`, `queue-system.md`, `api-endpoints.md`) are accurate; `docs - Copy/` at repo root is a stale superseded snapshot.

---

## 0. One-paragraph summary

A live Vietnamese cashback/affiliate platform (`https://hoantien.xyz`) built on **Laravel 12 / PHP 8.2 / MySQL**, currently served from a **home XAMPP machine (Windows)** that is also the *only* production host. The headline feature converts any Shopee / Lazada / TikTok / ShopeeFood / Long Châu / Pharmacity / Traveloka / Agoda / Booking URL into a tracked affiliate link with an estimated-cashback preview. Shopee short-link generation is done by a **Chrome MV3 extension** (`affiliate-worker/browser-extension/`) running in a **real logged-in `affiliate.shopee.vn` browser tab** that polls the site's API — Playwright/CDP was officially abandoned (Shopee detects it). Order/cashback reconciliation comes from **RioHub (TikTok), Lazada API, ShopeeFood partner API, and a Shopee order CSV import**, plus a manual/automated accounting layer (wallet ledger, withdrawals, bank-export). Google OAuth + spatie roles power sign-in and an admin panel. **PWA was built then disabled.** Everything runs on-device; nothing is scheduled via Laravel's scheduler (Windows Task Scheduler instead).

---

## 1. Architecture

```
Users (browser)                Home PC (Windows + XAMPP)                External services
┌─────────────────────┐        ┌───────────────────────────────────┐   ┌──────────────────────┐
│ hoantien.xyz        │        │ Apache (XAMPP) → Laravel 12 app   │   │ Shopee SPAs           │
│  Vue/Blade SPA      │──HTTP──│  bootstrap/app.php                │─ │  affiliate.shopee.vn  │
│  Breeze auth        │        │  MySQL `hoantienaff` (local)      │  │ data.addlivetag.com   │
│  Google OAuth       │        │  storage/logs, sessions (DB)      │─ │ ShopeeFood partner    │
└─────────────────────┘        └───────────┬───────────────────────┘  │ Lazada API (signature)│
                                           │                         │ RioHub vn (TikTok)    │
                            MV3 Chrome extension (HOME PC)            │ BHX/Coop/WinMart/KFM  │
                            poll jobs/results every few s            │ Brevo SMTP, Google    │
                            creates REAL Shopee custom link   ──────┘
                            inside the logged-in Shopee tab
```

Key design decisions (all documented in `docs/architecture.md`, still accurate):
- **Direct GraphQL** to Shopee = FAILED (HTTP 200 + empty body).
- **Playwright/CDP/Node worker (port 3001)** = BLOCKED (CAPTCHA). Code remains (`affiliate-worker/`, `server.js`, `.bat` launchers) but is **deprecated**.
- **MV3 extension** = ACTIVE link-generation backend. No server-side Chrome needed.
- The only "queue" is two `dispatch(closure)->afterResponse()` cache refreshers (see §8) — designed to need **no queue worker**.

### Component matrix ("what runs where")

| Component | Runs on | Can move to cPanel shared hosting? |
|---|---|---|
| Laravel app + MySQL | Home PC (current) | **Yes** — it is a standard LAMP app (`public/` docroot) |
| MV3 Chrome extension + logged-in Shopee tab | Home PC | **No — must stay on a machine with Chrome logged into `affiliate.shopee.vn/offer/custom_link`** |
| `affiliate-worker/storage/chrome-profile` (live Shopee session/cookies) | Home PC | **Never deploy/commit** |
| Deprecated Node worker `server.js` (port 3001) | Home PC | Not needed (deprecated) |
| Order-sync console commands | Home PC (Windows Task Scheduler) | **Yes via cron** on Linux, except ShopeeFood sync which uses live browser cookies |
| RioHub/Lazada/ShopeeFood/TikTok API integration | wherever DB is | Yes (outbound HTTPS) |

---

## 2. Hosting-readiness verdict (the core question)

`loop` source-deployment target is **PHP 8.2 + MySQL 8 on cPanel** (or any Apache/Nginx host). The Laravel app itself is hostable; **no embedded server, no OS-specific feature, and no long-running daemon is required at runtime** (the two `afterResponse()` closures run in-process; no `queue:work` needed if `QUEUE_CONNECTION` stays `sync`). Blockers are **configuration and secrets**, not code. Mandatory work before any move:

1. **Delete or gate the 13 public debug routes** (`/debug/*`, `/debug/set-cookie`, …) — currently reachable with **no auth** (§5, §10).
2. **Fix `.env`**: set `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL` accordingly, real DB creds (current: root with **empty password**), and **remove `T2_TEST_ENABLED=true`** (turns on `/__t2-test/rotate-session`).
3. **`trustProxies(at: '*')`** in `bootstrap/app.php` trusts every proxy/LB — acceptable only behind Cloudflare; otherwise restrict.
4. **Outbound HTTPS** required at runtime to: `riohub.vn/api/v1`, `api.lazada.vn/rest`, `api.bachhoaxanh.com/gw`, `data.addlivetag.com`, ShopeeFood partner API, `shopeefood.shopee.vn`, Shopee, Google. Verify the host's firewall allows it.
5. **Replace Windows Task Scheduler** with cron for `artisan affiliate:tiktok-sync --sync` (every 3h) and any other syncs. `routes/console.php` has **only** the `inspire` command — **nothing** is scheduled in Laravel (`schedule:list` is expected to be empty per `docs/tiktok-autosync-windows-task.md`).
6. **Secret handling**: live secrets exist in `.env` (ShopeeFood browser cookie `SHOPEEFOOD_COOKIE`, Brevo SMTP password, Google OAuth secret, RioHub API key, Lazada app secret + user token). The **ShopeeFood cookie belongs to a browser session exported from `affiliate.shopee.vn.json`** — it can silently expire; moving order-sync off the home PC means that cookie must be renewed remotely.
7. **STORAGE**: keep `storage/` + `storage/logs` writable (cPanel default is fine); run `php artisan migrate` + `db:seed`; point docroot at `public/` (built Vite assets: run `npm i && npm run build` or ship `public/build`).
8. **Queues**: the documented design works with `QUEUE_CONNECTION=sync`. Current `.env` sets **`database`** — flag if you keep it: you'd need a constant worker (cPanel can approximate via cron `artisan queue:work --once` or `queue:restart`, but that inches toward fragility). Recommend `sync` for this codebase.
9. **What MUST remain on the home PC / a personal machine**: the MV3 extension + a logged-in `affiliate.shopee.vn` tab — extension polls any deployed site automatically, so moving the site does **not** break link creation, as long as the new site's `AFFILIATE_EXTENSION_TOKEN` is updated in the extension popup.

---

## 3. Tech stack & dependencies

- **PHP ^8.2**, **Laravel ^12.0** (slim `bootstrap/app.php`, no `routes/api.php`, no `app/Console/Kernel.php`, **no `app/Jobs`**).
- **Composer**: `laravel/socialite ^5.28`, `laravel/tinker`, `phpoffice/phpspreadsheet ^5.9` (bank-export xlsx), `spatie/laravel-permission ^6.25`. Dev: `breeze`, `pail`, `pint`, `sail`, `phpunit ^11.5.50`.
- **Node/Vite**: `vite ^7`, `laravel-vite-plugin ^2`, `tailwindcss ^3.1` + `@tailwindcss/vite ^4`, `alpinejs`, `tom-select`. Build = `vite build`.
- **Drivers (production .env)**: `DB_CONNECTION=mysql` (local `hoantienaff`, root empty pw), `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `MAIL=smtp` → **Brevo relay** (`smtp-relay.brevo.com:587`). Redis configured but unused.
- **Runtime scripts/batch files** (home-PC orchestration): `start-affiliate-system.bat`, `start-chrome-cdp.bat`, `start-worker*.bat`, `test-worker.bat`, `create-link.bat` — CDP-era, deprecated.
- **PHPUnit**: `sqlite :memory:` + sync queues (tests run against a schema nothing in production uses; they don't neutralise `.env`'s `T2_TEST_ENABLED`/`AFFILIATE_TIMING`).

---

## 4. Directory map

```
config/        laravel + app.php (adds affiliate_timing, t2_test_enabled flags)
routes/        only auth.php, console.php, web.php
app/
  Console/Commands/  9 artisan commands (see §9)
  Contracts/         AffiliateProviderInterface, AffiliateLinkStrategy, BankExporterInterface
  Enums/Platform.php SHOPEE LAZADA TIKTOK LONG_CHAU PHARMACITY TRAVELOKA AGODA BOOKING OTHER
  Http/
    Controllers/     42 controllers (user, admin, api, auth, debug, T2 test)
    Middleware/      CaptureReferral, ForensicObserver (+ spatie aliases)
    Requests/        form requests
  Models/            16 Eloquent models
  Services/          ~120 files — the real brain (see below)
  Support/           CsrfForensic, AppKeyFlightRecorder, T2TestConfig, ...
  Providers/         AppServiceProvider (tagged DI), other providers
affiliate-worker/    MV3 extension + deprecated Node worker + storage/chrome-profile (LIVE Shopee session)
storage/app/         bank xlsx export files + temp/read_banks.php leftover
public/              Vite build (gitignored), sw.js/manifest (PWA disabled), ~7 MB icon PNGs
docs/                accurate docs (see §12); docs - Copy/ at ROOT = stale
database/            migrations (42), seeders (2), factories (6)
```

### Key service files (all under `app/Services/`)

- `ProductDataService.php` — Shopee product data via `data.addlivetag.com/product-data/product-data.php`; returns commission/price/rating/seller_commission etc. (`docs/product-data-api.md` at repo ROOT documents it; short-link expansion is unstable — use `item_id`).
- `AffiliateCacheService.php` + `AffiliateCache` model — daily product cache keyed by `item_id` (composite PK `(item_id, cache_date)`).
- `CashbackCalculator.php` — commission → user cashback + rate.
- `UrlResolverService.php` — expand short links (`s.shopee.vn`, `shp.ee`) → Shopee landing URL (SSRF-safe: only Shopee landing accepted).
- `AffiliateLinkService.php` + `Strategies/{DirectLinkStrategy,ExtensionStrategy}.php` — Shopee link strategy switch via `Setting` `affiliate.dashboard.strategy` / `affiliate.admin.strategy`.
- `ProviderFactory.php` + `Providers/*` (8 affiliate providers) — URL keyword → provider → `createLink()`; Shopee uses `AffiliateWorkerClient` (deprecated worker), others throw/direct.
- `RioHub/*` — `RioHubClient` (TikTok affiliate links + TikTok order sync), `RioHubResponse`.
- `Lazada/*` — `LazadaApiClient` (HMAC signature), `LazadaLinkEstimateService`, `LazadaOrderSyncService`, `LazadaFinalizeService`.
- `TikTok/*` — `TikTokLinkEstimateService` (RioHub-backed), `TikTokOrderSyncService`, `TikTokCashbackCalculator`.
- `ShopeeFood/*` — `ShopeeFoodClient` (partner API + cookie auth), `ShopeeFoodAffiliateLinkService` (deep-link builder), `ShopeeFoodPreviewService`, `ShopeeFoodOrderSyncService`, `ShopeeFoodUrlParser`.
- `PriceComparison/*` — `PriceComparisonManager` + 4 providers (CoopOnline, BachHoaXanh, Kingfoodmart, WinMart) — public search page `/so-sanh-gia`.
- `AffiliateSearchLinks/*` — 3 search-link providers (Shopee, Lazada, TikTok).
- `PromotionNews/*` — 4 providers (Coop, Bhx, Kingfoodmart, WinMart) → `/tin-tuc-khuyen-mai`.
- `WalletService.php`, `FinanceService.php`, `BankExportService.php` + `BankExports/ChuyenkhoantheobangkeExporter.php` (phpspreadsheet), `ReferralService.php`, `ShopeeCsvParser.php`.

---

## 5. HTTP surface (`routes/web.php` + `routes/auth.php`, 243 + lines)

| Group | Routes | Auth |
|---|---|---|
| Public | `/`, `/auth/google`, `/auth/google/callback`, `/check-username`, static pages (8), `/so-sanh-gia`, `/tin-tuc-khuyen-mai(/{source})`, 3× 301 redirects, `/up` health | none |
| **Debug (PUBLIC!) — must not deploy** | `GET/POST /debug/provider`, `/debug/worker`, `/debug/playwright`, `/debug/shopee-login` + POST `check`/`interactive`/`session-test`/`dashboard-test`/`profile-test`, `/debug/cookies`, `/debug/set-cookie` | **none** |
| Dashboard (auth+verified) | `GET /dashboard` (`forensic`), **`POST /link-requests`** (`forensic`) → `DashboardController@store`, `POST /link-requests/{lr}/toggle-pin`, `GET /csrf-token` (419 self-recovery), `POST /__t2-test/rotate-session` (flag + uid5 only, T2 test) | `auth`,`verified` |
| Member area (auth) | profile (GET/PATCH/DELETE), complete-profile (GET/POST), referrals, wallet, `POST /wallet/withdraw`, orders (index/show), guide | `auth` |
| Breeze auth | login, register, password reset, email verify, logout | via `auth.php` |
| **Extension API** | `GET /api/extension/jobs`, `POST /api/extension/results` | **token-in-query** (`AFFILIATE_EXTENSION_TOKEN`), CSRF-exempt (`api/*`) |
| Link API | `GET /api/link-request/{id}` | `auth` |
| Price comparison API | `/api/price-comparison/{/,coop,bhx,kingfoodmart,winmart,affiliate-search-links}` | **prod:** `auth` + `role:Admin\|Operator`; **else open (!!!)** |
| Admin | withdraw-requests (+bulk-complete/complete/reject), affiliate-short-link, tiktok-order-sync, affiliate-config, finance, referrals/statistics, users (+wallet-adjust), promotion-news CRUD | `role`/`permission` (spatie) |

**Extension job flow (the heart):** `GET /api/extension/jobs?token=` selects up to **5** `pending` `LinkRequest` rows → atomically flips them to `processing` (no timeout — a crashed poll leaves them **stuck in `processing`**; `check_jobs.php` at root diagnoses this). `POST /api/extension/results?token=` with `{ "results": [ {id, affiliate_url, status} ] }` updates each row, refreshes `affiliate_cache.affiliate_url`, and runs ShopeeFood preview enrichment.

---

## 6. Core flows (traced)

### 6a. Shopee link, DIRECT strategy (default) — `DashboardCreateDirectLinkController@store`
1. `POST /link-requests` (auth+verified+forensic) → validate URL.
2. `LinkRequest::create(platform, status=processing)`.
3. Resolve short link → must be a Shopee landing, else `failed` + 422.
4. `AffiliateCacheService::get(item_id)` → **HIT**: copy cached fields onto row, build affiliate URL, `status=completed`.
5. **MISS**: log miss, build affiliate URL, then `dispatch(closure)->afterResponse()` runs after the HTTP response: `ProductDataService::getByUrl()` → commission/price → `CashbackCalculator` → update row + `AffiliateCacheService::put()`. (Closure body duplicated verbatim in the direct and extension controllers.)
6. Affiliate URL shape: `https://s.shopee.vn/an_redir?origin_link={cleanUrl}&affiliate_id={setting affiliate.direct.shopee_affiliate_id}&sub_id={username}`.

### 6b. Shopee link, EXTENSION strategy — `DashboardCreateExtensionLinkController@store`
Steps 1–5 identical, but after the response the row stays **`processing`** and the stride is:
- MV3 extension polls `GET /api/extension/jobs` every few sec → claims ≤5 rows (`pending → processing`).
- `background.js` (in the logged-in `affiliate.shopee.vn/offer/custom_link` tab) drives the page's React inputs via `setReactValue` (native value setter for React 17/18, no synthetic events), detects CAPTCHA, returns the generated `s.shopee.vn` / `s.shopee.co.id` link.
- `content.js` throttles/batches (batch size 5) and posts `POST /api/extension/results` → row `completed` + `affiliate_cache` refresh + ShopeeFood enrichment.
- Timeline: submit → T+3–10s completed (per `worker-extension.md`).

### 6c. ShopeeFood deep link — `DashboardController@store` fast-path
`isShopeeFoodUrl()` (hosts ending `shopeefood.vn`, `spf.shopee.vn`, `vnnow-food`) → `storeViaShopeeFoodDeepLink`:
1. Create row (`platform=ShopeeFood`, `status=processing`).
2. `ShopeeFoodAffiliateLinkService::resolvePipeline()`: normalize → resolve short URLs (`/u/{code}`, `spf.shopee.vn`) → extract numeric `restaurant_id` (3–12 digits, never guessed) → result is cached under `shopeefood:spf:{hash}`.
3. `ShopeeFoodPreviewService::preview()` fills card data.
4. `buildAffiliateUrl()` → `https://shopeefood.shopee.vn/now-food/shop/{restaurant_id}?shareChannel=copy_link&utm_source=an_{affId}&utm_medium=affiliate_food&utm_campaign=-&utm_content={sub_id}`.
5. `completed`/`failed` + JSON/redirect response. (Root cause of the 2026-09-25 commit `d35b984 "fix ShopeeFood SPF shortlink restaurant ID"`.)

### 6d. TikTok link — `DashboardCreateDirectLinkController@store`
`str_contains(url,'tiktok')` → `TikTokLinkEstimateService::create()` → **RioHub** API (`rio_ai/v1/…`). Error text is rewritten into friendly Vietnamese via `friendlyTikTokError()` (422 = not promotable, 401/403 = connector down, 502 fallback).

### 6e. Lazada link — same controller
`str_contains(url,'lazada')` → `LazadaLinkEstimateService::create()` → `LazadaApiClient` (HMAC-signed REST, `LAZADA_APP_KEY/SECRET/USER_TOKEN`). Same friendly-error pattern.

### 6f. Admin affiliate short-link — `Admin/AffiliateShortLinkController`
Admin/Operator enters any URL → strategy (default `extension`) → `AffiliateLinkService::handle($lr,'admin')` → row to `pending` → extension picks it up.

### 6g. Order & cashback sync (the money path)
- `AffiliateSyncAll` = orchestrate; `AffiliateTikTokSync --sync` = RioHub orders → `TikTokOrderNormalizer` → `affiliate_order_items` (+status) → finalize → **wallet credit when order becomes eligible**. Scheduled every **3 h via Windows Task Scheduler** (`docs/tiktok-autosync-windows-task.md`; running the command manually = real production side effects, incl. credit).
- `AffiliateLazadaFinalize` = Lazada orders (Shopee/other providers cross-coded).
- `AffiliateImportShopee` = **Shopee order CSV import**.
- `ShopeeFoodOrderSyncService` = partner API (cookie auth) → paid orders → wallet credit.
- Wallet ledger: **append-only**, amount always positive, `direction=credit|debit`, `users.wallet_balance` is only a cache; `withdraw_requests` create a debit **only on `status=paid`** (`WR+YYYYMMDD+4digits`). See `docs/database/*.md`.

### 6h. Referrals & registration
`CaptureReferral` middleware stamps `ref` cookie → `referred_by` on signup; `referral_code` unique on `users`. Admin `/admin/referrals/statistics`.

---

## 7. Database (42 migrations)

Production MySQL `hoantienaff` (dev/test = SQLite memory). Schema inventory (from migrations — contains both live and ~7 legacy stale tables; FK/engine note: `AffiliateCache` PK mismatch is a repo finding, see §12):
- `users` (username/email/google_id/referral_code all unique, `referral_code` unique NOT NULL per seeder, status enum active/suspended, wallet_balance/total_earned/total_withdrawn DECIMAL(15,2), password, google auth, timestamps) + `password_reset_tokens`, `sessions`, `jobs`/`failed_jobs`/`cache`/`cache_locks`.
- `link_requests`: user_id, original_url, affiliate_url, platform, **status as 5 bare strings** (`pending|processing|completed|failed` + helpers; no enum), item_id, shop_id, cashback + rates, product preview fields, seller/shopee commission, is_pinned + pinned_at, notes, timestamps.
- `affiliate_cache`: `item_id` (+migration `2026_06_30_150000` adds `cache_date` — composite PK *(item_id, cache_date)* — while the model still declares singlePK `item_id`).
- `affiliate_order_items`: 47+ Shopee source fields (`docs/database/affiliate-orders.md`), finalize/reverse lifecycle.
- `wallet_transactions` (ledger), `withdraw_requests` (running_no), `referrals`, `campaigns`, `categories`, `merchants`, `clicks`, `purchases`, `transactions`, `settings` (key/value; stores `affiliate.dashboard.strategy`, `affiliate.direct.shopee_affiliate_id`, `affiliate.direct.resolve_shortlink`, `affiliate.admin.strategy`), spatie `roles`/`permissions`/pivots, `orders`.
- Seeders (2): roles/permissions/perms + **default admin `admin@hoantien.local` / `password`** — change before going live. Factories: 6.

---

## 8. Laravel wiring (`bootstrap/app.php`)

- `Env::disablePutenv()` — prevents stale process `APP_KEY` from shadowing `.env` (deliberate; it fixed a 2026-09-03 “missing app key” incident — `_rollback/missing-appkey-20260903-224254/`).
- `withRouting(web: routes/web.php, commands: routes/console.php, health: '/up')` — **no API routes file**.
- Middleware: `trustProxies(at: '*')` (ALL); web appends `CaptureReferral`; aliases `role`, `permission`, `role_or_permission` (spatie), `forensic`.
- **CSRF exempted for `api/*`** (matches `/api/extension/*`, `/api/link-request/{id}`, `/api/price-comparison/*`).
- AppServiceProvider boot: forces HTTPS unless local; registers 4 container **tags**: `affiliate-providers` (8), `catalog-providers` (4), `affiliate-search-link-providers` (3), `promotion-news-providers` (4); config `services.affiliate_extension.token` = `AFFILIATE_EXTENSION_TOKEN`, `services.affiliate_worker.url` = worker health base.
- Exceptions renderers: (1) POST logout 419 → session invalidate + `/login`; (2) **`link-requests.store` 419 → `CsrfForensic::event('csrf_failure', …)`** (reads `X-Forensic-Page-Id` / `X-Forensic-T2-Flow-Id` headers, status only — behavior unchanged); (3) `MissingAppKeyException` → `AppKeyFlightRecorder::capture()` (diagnostic).

---

## 9. Console commands (9, none scheduled in Laravel)

`AffiliateSyncAll`, `AffiliateTikTokSync`, `AffiliateLazadaFinalize`, `AffiliateImportShopee`, `TikTokTestApi`, `BenchmarkAffiliate`, `WalletBootstrapLedger`, `UsersGenerateUsername`, `MigratePendingWithdrawTransactions`.

---

## 10. Security findings (reporting only — nothing changed)

1. **`APP_DEBUG=true` + `APP_ENV=local` + `LOG_LEVEL=debug` while `APP_URL=https://hoantien.xyz`** — the single most consequential config risk. (This machine may be treated as “local tested from home”, but it is the production host.)
2. **13 public debug routes** (`/debug/*`) with no auth, inc. `GET /debug/set-cookie` and full Shopee login/worker controls.
3. `/api/price-comparison/*` open in any non-production env.
4. `trustProxies(at: '*')` — trusts every proxy.
5. **Extension token** `AFFILIATE_EXTENSION_TOKEN` is sent **in the query string on every poll** (lands in access/reverse-proxy logs) and is **hard-coded as default** in `background.js` (`hoantien-affiliate-extension-2026`) and documented publicly in `deployment.md`/`api-endpoints.md`; also stored unencrypted in `chrome.storage.sync`.
6. `AffiliateJobController` token check is strict equality (good), but 401 failures were a constant log noise source; there is **no rate limiting** — it is a public guessing surface.
7. `.env` holds **live** secrets: ShopeeFood session cookie, Brevo SMTP password, Google OAuth client secret, RioHub API key, Lazada app secret + user token. `.env` is correctly gitignored (as are `.env.production`/`.env.backup`).
8. **Live Shopee CSRF/session artifacts committed**: `affiliate-worker/storage/` (incl. `chrome-profile/`, `shopee-state.json`, `debug/*/graphql-request.json` containing live CSRF tokens) **and `affiliate-worker/node_modules/` (1,401 files)**. Root cause: `.gitignore` has only `/node_modules` (root-anchored). `vendor.zip` (27.5 MB) and a second `composer.lock` in `_rollback/` are also committed.
9. Deprecated `server.js` (Node, port 3001) is unauth'd, wide-CORS, exposes `POST /shopee/export-cookies` — ignorable but a liability if ever started with a public port.
10. Seeders create `admin@hoantien.local` / `password`.

## 11. Repo hygiene (root clutter — all committed to `main`)

Junk at root: `# Wrote docsWORKER.md.txt` (65 KB redirect-corruption capture), `au khi bo PWA…` (a `git log` pasted into a filename), `can('withdrawals.manage')` / `can('withdrawals.view')` / `git` (0-byte strays), `audit_step1.php` (hardcoded user dump), `check_jobs.php`, `check_route.php`, `docs.zip`, `vendor.zip` (27.5 MB), `public/build.zip`, `product-data-api.md` (real, belongs in `docs/`), `BaoCao_Implement_WinMartProvider.md`, `_rollback/` (3 files), `docs - Copy/` (14 stale files), `.bat` launchers (CDP era). Not gitignored. Cleaning these is safe but **was not requested** (read-only audit).

## 12. Docs ↔ code drift & known issues

- `docs/` is accurate; `docs - Copy/` (root) is stale.
- Docs say "no queue worker needed" (`afterResponse()` on sync) but `.env` sets `QUEUE_CONNECTION=database`. Verify runtime behavior (the two closures run after response; with `database` they serialize as jobs — no worker was running at audit time; no `Jobs` classes exist to be picked up).
- `LinkRequest` status: bare strings, no enum; `AffiliateCache` PK vs migration composite-key mismatch.
- MV3 `host_permissions` `http://localhost/*` does **not** match `http://localhost:8000` (the active `php artisan serve`) — use LAN IP / port match for local dev.
- README/PROJECT_CONTEXT reference `/api/affiliate/jobs|result`; actual = `/api/extension/*`.
- Extension docs disagree on `chrome.alarms` vs `setTimeout`, and reference a non-existent "Poll ngay" button.
- `PWA_ENABLED` unset → PWA disabled, but ~7.3 MB PWA icon PNGs remain committed and `app.js` actively unregisters SWs.
- Only 14 user-facing errors (12×419 + 2×422) in the audited windows; 419s fully resolved by T2 V2 self-recovery (delivered in the completed forensic audit).

## 13. Testing & local ops

- `php artisan test` (sqlite memory). Coverage: T2 session-rotation feature test, T2 V2 CSRF self-recovery test, direct/extension link flows, ShopeeFood parser tests, etc.
- `php artisan benchmark:affiliate`; set `AFFILIATE_TIMING=true` for `[CACHE]`/`[Resolver]`/`[CACHE-Timing]` logs.
- Live box also runs `php artisan serve --host 127.0.0.1 --port 8000` (observed at audit time) — likely a dev instance alongside Apache.
- `storage/logs/csrf-forensic.log` (daily, 7-day retention) is the T2 forensic channel (marker `CSRF_FORENSIC`, string const in `app/Support/CsrfForensic.php`).

---

*End of map. Re-audit only if HEAD moves to a commit that changes `bootstrap/app.php`, `routes/*`, provider strategies, or the extension contract; otherwise trust this document + `docs/*.md`.*