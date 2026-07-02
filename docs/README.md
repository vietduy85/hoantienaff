# HoanTienAff — Cashback Affiliate Link Generator

**Domain:** hoantien.xyz  
**Tech:** Laravel 12 + PHP ^8.2 + MySQL + Chrome Extension MV3  
**Locale:** Vietnamese (vi), Timezone: Asia/Ho_Chi_Minh  

## What It Does

Users paste a product URL (Shopee, Lazada, TikTok Shop, Tiki), the system generates an affiliate link, and the user gets cashback on purchases.

## Architecture Overview

```
User -> Laravel Website -> REST API -> Chrome Extension -> Shopee Affiliate (page JS)
                                        |
                                   Content Script
```

- **Laravel 12** — Main website + REST API queue
- **Chrome Extension (MV3)** — Background service worker polls API, sends jobs to content script
- **Content Script** — Runs inside `affiliate.shopee.vn`, interacts with React form to create short links
- **No Playwright/CDP** — Shopee blocks DevTools Protocol; only real browser extension works

## Key Directories

| Directory | Purpose |
|-----------|---------|
| `app/Http/Controllers/` | 20 controllers (Auth, API, Debug, Dashboard) |
| `app/Models/` | 11 Eloquent models |
| `app/Services/` | 15 services (providers, cashback, cache, worker client) |
| `affiliate-worker/browser-extension/` | MV3 extension (background.js, content.js) |
| `affiliate-worker/playwright/` | **DEPRECATED** — all CDP approaches blocked |
| `resources/views/` | 38 Blade view files |
| `database/migrations/` | 22 migration files |
| `routes/` | web.php, auth.php, console.php |

## Extension Flow

1. User creates `LinkRequest` via dashboard (status: `pending`)
2. Extension background.js polls `GET /api/extension/jobs?token=...`
3. Background forwards job to content.js via `chrome.tabs.sendMessage`
4. Content.js fills textarea, clicks "Lấy link", reads modal, returns results
5. Extension posts results to `POST /api/extension/results?token=...`
6. Status updated to `completed` with `affiliate_url`

## Key Design Decisions

- **afterResponse()** — AddLiveTag API call + CashbackCalculator run after HTTP response is sent (~50ms response vs ~730ms before)
- **Adaptive dashboard polling** — 3-phase setTimeout chaining (300ms / 800ms / 2000ms), exponential backoff on error
- **Tab caching** — Only `cachedTabId` in RAM, invalidated on tabs.get failure or URL mismatch
- **DOM-based waits** — `waitForMainTextarea`, `waitForButtonReady`, `waitForResult`, `waitForModalGone` replace fixed sleeps

## Known Issues

- "Đang xử lý" stuck status: fixed by wrapping `res.json()` in try-catch (background.js line 87-94)
- All Playwright/CDP approaches blocked by Shopee CAPTCHA detection
- Content script not yet tested end-to-end with current Shopee page layout

## Quick Start

See `docs/development-setup.md` for local setup instructions.
