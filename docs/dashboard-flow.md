# Dashboard & Adaptive Polling

## Dashboard Page

**File:** `resources/views/dashboard.blade.php`
**Contains partials:**
- `greeting-card.blade.php` — "Xin chào, {name}"
- `link-generator.blade.php` — URL input + polling logic (Alpine.js)
- `affiliate-result.blade.php` — Result card (currently unused; Alpine handles display)
- `pinned-links.blade.php` — Pinned links list (max 5)
- `recent-links.blade.php` — Recent links list

## Link Generator Component (`link-generator.blade.php`)

This is the main interactive component using Alpine.js (`x-data`).

### Submit Flow

1. User pastes URL and clicks "Tạo Link Ngay"
2. `POST /link-requests` (JSON via fetch)
3. Server validates URL, creates `LinkRequest`, responds with `{ success, request_id }`
4. Client starts polling `GET /api/link-request/{request_id}`
5. On `completed` status: display affiliate link with cashback amount
6. On `failed`/`rejected` status: show error message

### Adaptive Polling (`startPolling()`)

Uses `setTimeout` chaining instead of `setInterval`:

```javascript
startPolling() {
  this.stopPolling();
  this._pollStartTime = Date.now();
  const poll = () => {
    const elapsed = (Date.now() - this._pollStartTime) / 1000;
    let delay;
    if (elapsed < 3) delay = 300;
    else if (elapsed < 8) delay = 800;
    else delay = 2000;
    // ...fetch...
    .then(data => {
      this._errorCount = 0; // reset on success
      // ...check status...
      this.pollTimer = setTimeout(poll, delay);
    })
    .catch(() => {
      this._errorCount = (this._errorCount || 0) + 1;
      const backoff = Math.min(delay * Math.pow(2, this._errorCount), 5000);
      this.pollTimer = setTimeout(poll, backoff);
    });
  };
  poll();
}
```

#### Polling Phases

| Phase | Time Elapsed | Interval | Rationale |
|-------|-------------|----------|-----------|
| Fast | 0-3s | 300ms | Cache HIT case (instant response expected) |
| Medium | 3-8s | 800ms | Typical AddLiveTag API time |
| Slow | 8s+ | 2000ms | Worker processing (may take longer) |

#### Exponential Backoff

- `_errorCount` increments on each consecutive network error
- Delay = `baseDelay × 2^errorCount`, capped at 5000ms
- `_errorCount` resets to 0 on any successful response

#### Why setTimeout Instead of setInterval

- No risk of overlapping requests (next poll starts only after current completes)
- Can dynamically change the interval on each iteration
- Clean stop with `clearTimeout` via `stopPolling()`

### Result Display

When `completed` status is detected:
1. Display cashback amount (formatted with `toLocaleString('vi-VN')`)
2. Show affiliate link as clickable anchor
3. Copy-to-clipboard button with 2s feedback
4. "Mua ngay" button linking to affiliate URL
5. Auto-focus and select the URL input for next use

### Pinned Links

- Max 5 pinned links per user
- Pin/unpin via `POST /link-requests/{id}/toggle-pin`
- Displayed at top of dashboard with pinned_at ordering

## DashboardController

**File:** `app/Http/Controllers/DashboardController.php`

### `index()`
- Loads pinned (max 5) and recent (max 5) links for authenticated user
- Returns `dashboard` view

### `store(Request)`
- Validates URL (required, url, max 2048)
- Detects platform (Shopee/Lazada/TikTok/Tiki/Khác)
- Creates LinkRequest (status: `pending` for Shopee, `completed` for others)
- For Shopee:
  1. Resolves short URL
  2. Extracts item_id
  3. Checks AffiliateCache
  4. **Cache HIT:** fills all fields immediately
  5. **Cache MISS:** saves item_id, dispatches afterResponse() for AddLiveTag + CashbackCalculator
- Returns JSON for AJAX requests, redirect for form submissions

### `togglePin(LinkRequest)`
- Toggles pin status, enforces max 5 pins
- Sets `pinned_at` timestamp on pin

### `detectPlatform(string $url): string`
- Simple string matching: shopee → Shopee, lazada → Lazada, tiktok → TikTok Shop, tiki → Tiki
- Default: "Khác"
