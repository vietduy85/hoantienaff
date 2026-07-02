# Troubleshooting Guide

## "Đang xử lý" Stuck Forever

**Symptom:** Dashboard shows "Đang xử lý..." and never changes to completed/failed.

**Root cause:** `background.js` line 86 (`res.json()`) was not wrapped in try-catch. If the API returns non-JSON (e.g., 500 HTML error page), the promise rejection terminates the `setTimeout` chain permanently.

**Fix (applied):** 
- `res.json()` now wrapped in its own try-catch (logs error, schedules retry)
- Top-level try-catch around entire `poll()` as safety net
- See `background.js` lines 87-94 and 145-148

## Extension Not Polling

**Symptom:** No `[Worker]` logs in service worker console.

**Checklist:**
1. Extension loaded in `chrome://extensions/`?
2. "Developer mode" enabled?
3. Popup configured with correct API URL and token?
4. `chrome.storage.sync` has `apiUrl` and `token` set? (Open popup → Save)
5. Any errors in service worker console?

## No Shopee Tab Found

**Symptom:** `[BG] Rediscover affiliate tab` repeated, or `[Worker] Không tìm thấy tab Shopee`.

**Checklist:**
1. Is `affiliate.shopee.vn` open in any tab?
2. Is the tab URL `https://affiliate.shopee.vn/*` (not http)?
3. Is the user logged in on affiliate.shopee.vn?
4. Host permissions in `manifest.json` include `https://affiliate.shopee.vn/*`?

## Content Script Not Responding

**Symptom:** `sendMessage error` in background logs.

**Checklist:**
1. Is `affiliate.shopee.vn` loaded and not showing CAPTCHA?
2. Content script injected? (Check DevTools → Sources → Content scripts)
3. `window.__shopeeBulkLinkLoaded` already set? (Refresh the tab)
4. Any console errors on the Shopee page?

## CAPTCHA Detected

**Symptom:** `Error: CAPTCHA` in content script logs.

**Cause:** Shopee detects automation patterns.

**Mitigations:**
- The extension runs as a real browser extension (not CDP), which is less detectable
- Anti-bot delays (1500-3200ms) between batches
- If CAPTCHA appears, the batch fails and retries on next poll cycle
- Manual login to resolve CAPTCHA, then retry

## AddLiveTag API Failures

**Symptom:** Product data not populated (null fields in LinkRequest).

**Checklist:**
1. Check `storage/logs/laravel.log` for AddLiveTag errors
2. Is `data.addlivetag.com` accessible from the server?
3. Did `afterResponse()` execute? (Check for [CACHE-Timing] log with `AFFILIATE_TIMING=true`)
4. The dispatch in `DashboardController@store` uses `->afterResponse()` — does the response send successfully?

## Database Errors

**Symptom:** 500 errors or migration failures.

**Checklist:**
1. Run `php artisan migrate:status` to check migration state
2. Check MySQL credentials in `.env`
3. `affiliate_cache` uses composite PK — ensure migration `2026_06_30_150000` ran

## Session/Auth Issues

**Symptom:** Redirected to login despite being authenticated.

**Checklist:**
1. `SESSION_DRIVER` in `.env` (should be `file` or `database`)
2. Session directory writable: `storage/framework/sessions/`
3. APP_URL matches the actual URL used in browser

## Worker API Returns Empty Jobs

**Symptom:** Extension receives `{ jobs: [] }` repeatedly.

**Checklist:**
1. Are there LinkRequests with `status = 'pending'`?
2. Does the token in the extension match `AFFILIATE_EXTENSION_TOKEN`?
3. Check `GET /api/extension/jobs?token=...` directly in browser
4. CSRF exempt configured for `api/*`? (Check `bootstrap/app.php`)

## After Response Not Executing

**Symptom:** Cache MISS → product data never populated.

**Checklist:**
1. PHP version ≥ 8.2? (Laravel 12 requirement)
2. Queue driver configured? (Should work without queue for `afterResponse()`)
3. Check for fatal errors in Laravel log
4. The dispatch uses `function () use (...) { ... }` — verify no undefined variables

## CDP/Playwright (Deprecated) Issues

These approaches are fully blocked and should NOT be used:
- Direct GraphQL: HTTP 200 empty response
- Playwright/CDP: CAPTCHA redirect
- The browser extension is the only working approach

If encountering `ECONNREFUSED` on port 9222, ensure Chrome is started with `--remote-debugging-port=9222`. However, this approach is deprecated.
