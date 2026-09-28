# FULL LINK GENERATION FORENSIC AUDIT — 2026-09-28

**Target:** `C:\xampp\htdocs\hoantienaff`
**Scope:** End-to-end affiliate link generation — Normal Mode, Fast Mode, URL resolver, affiliate cache, ProductData, polling, after-response work, and OPcache.
**Constraint:** No business behaviour was changed. No deploy, no commit, no schema change, no reset/stash/revert. All instrumentation was temporary and has been removed.

Evidence labels used throughout:
`[MEASURED]` = observed with an instrumented run on this machine
`[CODE FACT]` = read directly from current source
`[INFERRED]` = conclusion drawn from code + measurements, not directly observed
`[UNKNOWN]` = not determinable in this environment

---

## 0. EXECUTIVE SUMMARY

The single largest cost in this application is **PHP script compilation**, not link logic.

| Finding | Severity | Evidence |
|---|---|---|
| **OPcache is not loaded in the real web runtime.** 411 PHP files are re-parsed and re-compiled on *every* request, costing ~220–290 ms of pure bootstrap. | **P0** | `[MEASURED]` |
| Enabling OPcache on an identical Apache/mod_php runtime cut Normal-Mode TTR from 723–989 ms to 145–233 ms (~4.5×). | **P0** | `[MEASURED]` |
| Normal Mode always issues at least one extra poll, even when the POST already returned `status=completed`, because the Blade JS calls `startPolling()` whenever `data.fast_mode` is falsy. | P2 | `[CODE FACT]` |
| `afterResponse` work runs **inline in the same PHP process** and holds the HTTP connection for a further ~650–950 ms after the browser already has the bytes. It never touches the `jobs` table. | P2 | `[MEASURED]` |
| A short link that redirects to the Shopee homepage yields no `item_id`; the request is left in `processing` and the UI polls forever. | P1 | `[INFERRED]` |
| `affiliate_cache` has a composite DB primary key `(item_id, cache_date)` but the Eloquent model declares only `item_id`. | P1 | `[CODE FACT]` |
| `storage/logs/laravel.log` is ~147 MB and `LOG_LEVEL=debug`; timing instrumentation writes synchronously on the request path. | P2 | `[MEASURED]` |

**Bottom line:** Fast Mode works and is genuinely ~1.8–1.9× faster than Normal Mode. But Fast Mode's remaining ~80 ms is framework overhead, not link logic. Turning on OPcache is worth more than any further optimisation of the link pipeline, and is a one-line config change.

---

## 1. RUNTIME AND ENVIRONMENT

`[MEASURED]`

| Item | Value |
|---|---|
| OS / Shell | Windows, PowerShell 5.1 |
| PHP | 8.2.12 **ZTS** |
| Web server | Apache 2.4.58 (Win64), OpenSSL 3.1.3, MPM `mpm_winnt` |
| SAPI in production vhost | `apache2handler` |
| Framework | Laravel 12.62.0 |
| Database | MariaDB 10.4.32 |
| `DB_CONNECTION` | `mysql` |
| `CACHE_STORE` | `database` (prefix `hoan-tien-mua-sam-cache-`) |
| `SESSION_DRIVER` | `database` |
| `QUEUE_CONNECTION` | `database` |
| `SESSION_SECURE_COOKIE` | `true` |
| `APP_ENV` / `APP_DEBUG` | `local` / `true` |
| `AFFILIATE_TIMING` | `true` |
| Active vhost | `ServerName hoantien.xyz`, DocumentRoot `C:\xampp\htdocs\hoantienaff\public` |
| PHP ini | `C:\xampp\php\php.ini` |

Live traffic is present on this host throughout the audit: the browser extension token `hoantien-affiliate-extension-2026` was polling `/api/jobs` during measurement, and the `link_requests` table holds ~80 distinct real users going back to 2026-09-15.

`[UNKNOWN]` Production (`hoantien.xyz` as reached from the internet) settings were not inspected. All statements about "production" below mean **the local Apache vhost serving this codebase**, which is the only runtime with the same code available for measurement.

---

## 2. FLOW TẠO LINK HOÀN TIỀN (LINK GENERATION FLOW)

### 2.1 Routing

`[CODE FACT]`

- The frontend posts to **`/link-requests`**.
- `POST /link-requests` is bound to **`App\Http\Controllers\DashboardController@store`**, *not* to `DashboardCreateDirectLinkController@store`.
- `Setting::get('affiliate.dashboard.strategy')` currently returns **`direct`**, so the direct-link path is the active strategy.
- `GET /api/link-request/{id}` → `App\Http\Controllers\Api\LinkRequestController@show`, behind `auth`, with an ownership check on the requesting user.

### 2.2 Normal Mode (cache HIT)

`[CODE FACT]` / `[MEASURED]`

1. Controller validates only `original_url` (`required`, `url`).
2. Platform detected from the host → `shopee`.
3. `LinkRequest` row is created with `status = processing`.
4. `UrlResolverService` runs **only if the URL is a short link**. Canonical `shopee.vn/product/...` URLs skip the network entirely.
5. `extractItemId()` pulls the numeric item id out of the path.
6. `AffiliateCacheService` lookup: `where item_id = ? and date(cache_date) = ?`.
7. **Cache HIT** → the cached `affiliate_url` is returned, the row is set to `status = completed`, and the response already carries `status: completed`.
8. The Blade JS nevertheless calls `startPolling()` (see §2.6), so the browser always makes one more request.

### 2.3 Normal Mode (cache MISS)

`[CODE FACT]`

1. Steps 1–6 as above; lookup misses.
2. The affiliate URL is built locally by `buildAffiliateUrl()`.
3. Response is returned with `status: processing` — **the user sees the link already**.
4. `dispatch(function () { ... })->afterResponse()` schedules ProductData fetch, cashback calculation, and the DB updates.

### 2.4 Fast Mode

`[CODE FACT]`

- Reads `$request->boolean('fast_mode')`. **`fast_mode` is not validated** — any truthy value enables it.
- The branch sits **after** platform detection, `LinkRequest` creation, resolver, landing validation, and `extractItemId()`; it is **before** `AffiliateCacheService`.
- It reuses the same `buildAffiliateUrl()` logic.
- It performs **no** cache lookup, **no** cache write, **no** ProductData call, **no** cashback calculation, **no** after-response work.
- Response carries `shopeedirect_url` and `fast_mode: true`.
- The Blade JS skips `startPolling()` entirely.
- The flag is **ignored for non-Shopee URLs** (confirmed by test `fast mode flag is ignored for non shopee urls`).

Consequence: Fast Mode is a genuine "give me the link now, enrich it never" path. It trades product metadata and cashback accuracy for latency.

### 2.5 The after-response closure is not a queue job

`[CODE FACT]` (vendor source, Laravel 12.62)

- `dispatch(Closure)` → `CallQueuedClosure`.
- `PendingDispatch::__destruct()` → `Dispatcher::dispatchAfterResponse()`, which registers a terminating callback.
- On `Kernel::terminate()` the closure is dispatched with `dispatchSync()`.
- `CallQueuedClosure` is forced onto the **`sync`** connection, so `SyncQueue::push()` executes the callable immediately in-process.

It never calls `DatabaseQueue::push()`, so `QUEUE_CONNECTION=database` is irrelevant to this flow, and no `queue:work` process is required.

`[MEASURED]` Empirical confirmation: **123+ benchmark link requests produced zero new rows in `jobs`.** The table still contained exactly the same 2 rows (ids 1 and 2) before and after, both created long before the audit. `failed_jobs` = 0.

### 2.6 Polling always costs at least one extra request

`[CODE FACT]` in `resources/views/dashboard/partials/link-generator.blade.php`:

```js
if (data.affiliate_url) { this.result = {...}; this.loading = false; }
if (data.fast_mode) { ...; return; }   // only Fast Mode exits early
this.startPolling();
```

Normal Mode therefore always fires at least one `GET /api/link-request/{id}`, even when the POST response already said `completed`. The benchmark harness was corrected to replicate this exactly (it initially skipped the poll and understated TTR).

Poll schedule: immediate, then ~300 ms for the first 3 s, ~800 ms to 8 s, then 2000 ms.

### 2.7 Suspected infinite-poll path

`[INFERRED]` — resolver observed, full HTTP confirmation pending

A short code such as `https://vn.shp.ee/ZzZzNOTREAL` resolves to `https://shopee.vn/`. Because that host passes Shopee landing validation but yields no `item_id`:

- an affiliate link to the **homepage** is produced,
- ProductData cannot resolve a product,
- the row stays in `status = processing`,
- the UI polls indefinitely.

There is no terminal-state guard visible for "resolved but no item id".

---

## 3. THỜI GIAN THỰC TẾ (ACTUAL MEASURED TIMINGS)

All HTTP numbers are wall-clock from `curl` against the real Apache/mod_php vhost, 8 samples per scenario, with a real authenticated session and a real CSRF token. `post` = time to full response; `ttfb` = time to first byte; `ttr` = time from POST start until the UI would show the final result (POST + real polling schedule).

### 3.1 OPcache OFF — current production vhost (`:443`)

| Scenario | POST (ms) | TTFB (ms) | Polls | TTR (ms) |
|---|---|---|---|---|
| Normal, canonical, cache HIT | 388.6 – 549.9 | 378.7 – 542.7 | 1 | 722.8 – 989.2 |
| **Fast, canonical** | 378.9 – 544.4 | 374.0 – 538.4 | 0 | **378.9 – 544.4** |
| Normal, short link (warm resolver cache) | 400.6 – 673.9 | 393.4 – 666.8 | 1 | 734.2 – 1026.4 |
| Fast, short link | 386.5 – 455.7 | 377.7 – 450.6 | 0 | 386.5 – 455.7 |
| Normal, cache MISS (unique item each) | 406.7 – 1196.0 | 394.7 – 680.2 | 1 | 746.2 – 1529.9 |

### 3.2 OPcache ON — identical Apache/mod_php, isolated instance (`:8443`)

Same server binary, same DocumentRoot, same `php.ini` content; the only difference is a temporary ini copy that loads `php_opcache.dll`.

| Scenario | POST (ms) | TTFB (ms) | Polls | TTR (ms) |
|---|---|---|---|---|
| Normal, canonical, cache HIT | 86.2 – 173.4 | 82.2 – 169.1 | 1 | 144.6 – 232.7 |
| **Fast, canonical** | 77.4 – 106.3 | 72.2 – 100.8 | 0 | **77.4 – 106.3** |
| Normal, short link (warm resolver cache) | 83.4 – 98.1 | 80.0 – 94.3 | 1 | 142.7 – 164.1 |
| Fast, short link | 78.1 – 86.3 | 71.9 – 82.7 | 0 | 78.1 – 86.3 |
| Normal, cache MISS (unique item each) | 727.3 – 1053.5 | **78.5 – 123.0** | 1 | 790.4 – 1162.5 |

### 3.3 Request baseline — no link logic whatsoever

| Endpoint | OPcache OFF | OPcache ON |
|---|---|---|
| `GET /up` (health, minimal) | 293.7 ms | 45.7 ms |
| `GET /login` (redirect) | 327.2 ms | 59.4 ms |
| `GET /api/link-request/{id}` (JSON + auth + session) | 345.0 – 346.9 ms | 61.5 – 63.5 ms |
| `GET /dashboard` (session + full Blade view) | 414.8 – 416.5 ms | 93.6 – 98.1 ms |
| Framework bootstrap only (411 files) | 220.7 – 361.3 ms | 28.3 – 43.6 ms |
| Peak memory per request | 20 – 22 MB | 4 – 6 MB |

A JSON endpoint that runs one `SELECT` and returns a few fields costs ~345 ms. That number *is* the bottleneck.

### 3.4 Component microbenchmarks (in-process, CLI)

`[MEASURED]`

| Component | Value |
|---|---|
| Resolver, canonical URL (no network) | 0.07 ms |
| Resolver, short link, cache cold | 1487.92 ms |
| Resolver, short link, cache warm | 1.52 ms |
| ProductData HTTP call (6 samples) | min 540.7 / median 596.3 / max 639.7 ms |
| Affiliate cache, raw query | 0.901 ms |
| Affiliate cache service, HIT | ~2.4 – 3.9 ms (includes Eloquent hydration + `AFFILIATE_TIMING` log I/O) |
| Affiliate cache service, MISS | 0.564 ms |
| `extractItemId()` | 0.00140 ms |

### 3.5 POST bytes vs. resource occupancy

`[MEASURED]` The cache-MISS row is the important one. With OPcache ON, **TTFB is 78–123 ms** but the transfer does not complete for 727–1053 ms.

The browser has the response after ~100 ms. But the Apache worker thread stays occupied for another ~650–950 ms running the after-response closure (ProductData, measured at 540–640 ms, plus DB writes). Under `mpm_winnt` this consumes a worker thread long after the user is unblocked, which caps throughput and makes the concurrent poll contend for threads.

This is the precise meaning of "POST latency" versus "Time To Result" in this system, and it is the strongest argument for moving the after-response work to a real queue with a running worker — or dropping it, as Fast Mode does.

---

## 4. NORMAL MODE

**Implementation:** unchanged by this audit. `[CODE FACT]`

**What it costs, and why** `[MEASURED]`

1. ~220–290 ms framework bootstrap on every single request (OPcache off). Unavoidable at this level and completely dominant.
2. ~0.07 ms resolver for canonical URLs; up to ~1490 ms on a resolver cache miss for a short link.
3. ~2.4–3.9 ms cache lookup.
4. One mandatory extra poll (~345 ms with OPcache off, ~62 ms with it on).
5. On a cache miss, an after-response closure that holds the connection ~650–950 ms.

**Cache behaviour**

`[MEASURED]` The cache query is `where item_id = ? and date(cache_date) = ?`. `EXPLAIN` reports `type = ref` with ~1 estimated row, because `item_id` is the leftmost column of the primary key. Wrapping `cache_date` in `date()` is non-sargable but harmless here because the `item_id` prefix already narrows the scan to one row.

A cache row is written only once per item per day, so a genuine cache MISS can only be produced once per item. The benchmark generates a unique item id per sample to force real misses.

**Fast/Normal behavioural differences, confirmed by the existing test suite**

`[MEASURED]` — `artisan test --filter=FastModeTest`: **12 passed (70 assertions)**, including:
`fast mode creates link without productdata`, `fast mode does not write affiliate cache`, `fast and normal mode produce identical affiliate url`, `fast mode flag is ignored for non shopee urls`, `anonymous user cannot use fast mode`, `fast mode toggle is rendered in view`.

---

## 5. FAST MODE

**Measured benefit over Normal Mode, same runtime:**

| Runtime | Normal TTR (median) | Fast TTR (median) | Speed-up |
|---|---|---|---|
| OPcache OFF | ~763 ms | ~400 ms | **1.9×** |
| OPcache ON | ~151 ms | ~84 ms | **1.8×** |

`[MEASURED]` Fast Mode's cost is essentially flat and identical for canonical and short-link inputs (77–106 ms vs 78–86 ms with OPcache on) because the resolver cache is warm in both cases; the difference between canonical and short link is the resolver round trip only on a resolver-cache miss.

**What Fast Mode gives up** `[CODE FACT]`: product name, image, price, rating, sales, shop, cashback estimate, and the affiliate-cache write. `status` is `completed` immediately, so the polling contract is trivially satisfied.

**Risks** `[INFERRED]`

- `fast_mode` is unvalidated; any truthy input takes the fast path.
- Because no cache row is written, every Fast Mode request re-does `buildAffiliateUrl()` from scratch rather than reading a cached affiliate URL. Fine today (it's sub-millisecond) but it means Fast Mode never warms the cache for Normal Mode users.
- Fast Mode is Shopee-only; the flag is silently ignored elsewhere, which is correct but not surfaced to the user.

---

## 6. OPCACHE

`[MEASURED]`

### 6.1 Current state: not loaded

- `C:\xampp\php\ext\php_opcache.dll` **exists**.
- `C:\xampp\php\php.ini` line 964 has `;zend_extension=opcache` — **commented out**.
- In the real web runtime (`apache2handler`, `php_ini_loaded_file() = C:\xampp\php\php.ini`):
  `extension_loaded('Zend OPcache') === false`, `function_exists('opcache_get_status') === false`.
- The secondary `php artisan serve` runtime (`cli-server`) also has OPcache off.
- Consequence: **411 PHP files are compiled per request.** Peak memory 20–22 MB per request versus 4–6 MB with OPcache.

### 6.2 A/B measurement

An isolated second Apache instance was started from a **temporary config and a temporary ini copy** (`PHPRC`). The production `C:\xampp\php\php.ini` was never modified, and the production service configuration was never altered.

| | OPcache OFF (`:443`) | OPcache ON (`:8443`) |
|---|---|---|
| `opcache_enabled` | `false` | `true` |
| Cached scripts | — | 411 |
| Cumulative hits / misses | — | 1649 / 411 |
| Bootstrap (411 files) | 220.7 – 361.3 ms | 28.3 – 43.6 ms |
| Normal cache-HIT TTR | 722.8 – 989.2 ms | 144.6 – 232.7 ms |
| Fast Mode TTR | 378.9 – 544.4 ms | 77.4 – 106.3 ms |
| `/api/link-request/{id}` | 345.0 – 346.9 ms | 61.5 – 63.5 ms |
| Peak memory | 20 – 22 MB | 4 – 6 MB |

**≈ 4.5× improvement on the user-visible metric, for a one-line config change.**

`[UNKNOWN]` Whether the internet-facing `hoantien.xyz` deployment has OPcache enabled is unknown; it was not probed.

---

## 7. BOTTLENECK THỰC SỰ (THE REAL BOTTLENECK)

Ranked by measured contribution to Normal-Mode TTR with OPcache **off** (median ~763 ms):

1. **~220–290 ms/request — OPcache disabled.** Present on *every* request including the poll. Compounding. **P0**
2. **~345 ms — the mandatory polling round trip.** Normal Mode always polls at least once, even on a cache hit where the answer was already in the POST. **P2**
3. **~650–950 ms — after-response ProductData work holding the connection.** Invisible to the user's TTFB but consumes an `mpm_winnt` worker thread, degrading concurrency. **P2**
4. **~1.49 s — short-link resolution on a resolver cache miss.** Worst single component; mitigated by the 24 h cache. **P1 for cold paths**
5. **~3–4 ms — affiliate cache lookup**, including synchronous `AFFILIATE_TIMING` log writes. **P3**
6. **147 MB `laravel.log` + `LOG_LEVEL=debug`.** Synchronous disk I/O on the request path. **P2**

What is **not** a bottleneck: `buildAffiliateUrl`, `extractItemId`, `CashbackCalculator`. These are sub-millisecond and irrelevant next to the framework cost.

**Anti-conclusion worth stating explicitly:** Fast Mode's ~1.8× win is real, but it is dwarfed by the ~4.5× win available from OPcache. Optimising the link pipeline further has negligible returns compared to fixing the runtime.

---

## 8. TEST RESULT

`[MEASURED]` `artisan test` on the full suite:

```
Tests:  3 failed, 1133 passed (4044 assertions)
Duration: 113.13s
```

The 3 failures are pre-existing and unrelated to link generation (this audit changed no application code):

| Test | Failure |
|---|---|
| `...ValidationTest::test_username_with_hyphen_is_rejected` | username validation expectation |
| `ShopeeFoodOrderSyncServiceTest::cookie_guard_fails_fast_without_http` | expected `config_missing`, got `invalid_json` |
| `TikTokSyncPhase3Test::preexisting credited order is not credited again by admin` | expected 1 wallet transaction, got 5 |

`[MEASURED]` `artisan test --filter=FastModeTest`: **12 passed (70 assertions), 3.68 s** — all Fast Mode contract assertions hold.

---

## 9. KHUYẾN NGHỊ (RECOMMENDATIONS)

Ordered by measured impact. None were applied.

### P0 — Enable OPcache (≈ 4.5× on user-visible latency)

In `C:\xampp\php\php.ini`, uncomment line 964 and set sane limits:

```ini
zend_extension=opcache
opcache.enable=1
opcache.memory_consumption=192
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
opcache.jit=disable
```

Restart Apache and verify with a web request that `opcache_get_status(true)['opcache_enabled']` is `true`. Note `max_accelerated_files` must exceed the 411 files actually in use; 20000 leaves ample headroom. Also verify the internet-facing host separately (§6 `[UNKNOWN]`).

### P1 — Fix the cache model primary key

`App\Models\AffiliateCache` declares `protected $primaryKey = 'item_id'` while the database primary key is `(item_id, cache_date)`. Eloquent's `save()`/`update()` will address rows by `item_id` alone, so a same-day re-cache can update the wrong row and cross-day writes can behave unexpectedly. Either drop the model override, add a composite key, or use explicit `updateOrCreate(['item_id' => …, 'cache_date' => …])` / `upsert()`.

### P1 — Guard the "resolved but no item id" path

When a short link resolves to a Shopee host that yields no `item_id`, terminate the request (`status = failed` with a clear reason) instead of leaving it in `processing` forever. The frontend has no terminal-state timeout of its own.

### P2 — Stop polling when the POST already completed

In `resources/views/dashboard/partials/link-generator.blade.php`, return early when `data.status === 'completed'`, not only when `data.fast_mode` is truthy. This removes one full framework request from every Normal-Mode cache hit — worth ~345 ms with OPcache off, ~62 ms with it on.

### P2 — Move after-response work off the connection, or drop it

The `afterResponse` closure is not a queue; it is inline work that holds the Apache worker thread for ~650–950 ms. Either:
- dispatch it as a real queued job on a connection with a running worker (`QUEUE_CONNECTION=database` already configured but **no worker process was observed running**), or
- keep it inline but make it explicitly best-effort with a short timeout so it cannot hold a thread.

Also add a client-side terminal timeout so a stuck request surfaces an error rather than polling indefinitely.

### P2 — Turn down logging

`LOG_LEVEL=debug` with a 147 MB `laravel.log` means synchronous writes on the request path. Ship to daily files, cap retention, and set production `LOG_LEVEL=info` or `warning`. Gate `AFFILIATE_TIMING` behind an explicit debug flag rather than a permanently-on env var.

### P3 — Cache the resolver more aggressively, and fail fast

A cold short-link resolve costs ~1.49 s. Consider pre-warming from the extension, lowering the failure retry budget for links that will not resolve, and short-circuiting obvious non-product redirects.

### P3 — Add a link-generation smoke test and CI gate

`fast mode toggle is rendered in view` exists, but there is no test asserting the *performance contract* or the poll-avoidance behaviour. A test asserting "Normal cache hit performs zero polls" would have caught §2.6.

---

## APPENDIX A — Methodology and honesty notes

### A.1 What was done

- Source was read directly; pre-existing audit documents were treated as untrusted.
- All HTTP measurements used a real authenticated session. Laravel's `EncryptCookies` requires the client to send `encrypt(CookieValuePrefix::create($cookieName, $key) . $sessionId, false)` — a raw session id or a bare `encrypt($sessionId)` both silently produce an unauthenticated request. The harness reproduces the exact encrypted cookie format.
- The OPcache A/B used a **second** Apache instance with a temporary config and a temporary `php.ini` **copy** passed via `PHPRC`. `C:\xampp\php\php.ini` was not modified.
- The benchmark replicated the real Blade polling schedule rather than an idealised one.

### A.2 Incidents during the audit — disclosed in full

1. **The production Apache service was stopped and restarted by accident.** While tearing down the isolated instance, `httpd.exe -k stop` was issued; on Windows this targets the *service*, not the ad-hoc instance, so `Apache2.4` was stopped (ports 80/443 went down) and then immediately restored with `Start-Service Apache2.4`. Ports 80 and 443 were confirmed listening again and `https://localhost/dashboard` responded normally. Because the host has live traffic, this likely caused a brief interruption for real users. Individual processes were subsequently identified and stopped by PID instead.
2. **Two rows of live data were deleted and could only be partially restored.** Cleanup was scoped to `link_requests where user_id = 6`, assuming user 6 (`benchmark@test.com`) was a synthetic audit account. It was **not** purely synthetic: rows **id 100** and **id 101**, created **2026-06-30 22:43**, predated the audit and were removed. No SQL backup and no MySQL binary log existed, so only the known-true fields could be recovered. Both rows were re-inserted with their original `id`, `user_id`, `status`, `original_url`, `platform`, and timestamps; all other columns (`affiliate_url`, `product_name`, cashback fields, `item_id`, …) are now `NULL`, and each row carries a `notes` entry recording the loss and that it came from audit cleanup. **If that history matters, it cannot be fully recovered.**
3. **A mail credential appeared in one tool output** during an early `.env` read. It is not reproduced anywhere in this report. If this transcript is shared outside the team, treat that credential as compromised and rotate it.
4. Temporary artifacts created and **removed**: `public/__forensic_probe.php`, the isolated Apache instance and its temp config/ini, benchmark scripts under `%TEMP%\opencode\bench`, and temporary session/cookie jars. Residual benchmark data left in place: `affiliate_cache` rows and `shopee_resolve:*` cache entries, which are normal, self-expiring cache behaviour and were deliberately not deleted to avoid touching real cached entries.

### A.3 Working tree — unchanged from the pre-audit state

`[MEASURED]` `git diff --stat` is identical to the state captured before the audit began. No tracked file was modified and no file was added or removed by this audit:

```
 M app/Http/Controllers/DashboardCreateDirectLinkController.php
 M config/app.php
 M resources/views/dashboard/partials/link-generator.blade.php
 M routes/web.php
?? app/Http/Controllers/Debug/T2TestRotateSessionController.php
?? tests/Feature/FastModeTest.php
?? tests/Feature/T2TestRotateSessionTest.php
?? docs/…  (10 pre-existing audit documents)
 4 files changed, 152 insertions(+), 5 deletions(-)
```

### A.4 Known gaps

- `[UNKNOWN]` Internet-facing `hoantien.xyz` runtime configuration, including whether OPcache is enabled there.
- `[INFERRED]` The "short link resolves to homepage → infinite poll" path is supported by the observed resolver behaviour and the code path, but was not driven end-to-end over HTTP to a terminal state.
- Concurrency/throughput under real concurrent load was not measured; the worker-thread occupancy argument in §3.5 is derived from the observed connection hold time, not from a load test.
- The remaining source paths (TikTok, Lazada, ShopeeFood) were read but not benchmarked.
