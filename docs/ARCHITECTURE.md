# System Architecture

## High-Level Flow

```
┌─────────────────────────────────────────────────────────────┐
│                        Laravel App                          │
│  ┌──────────────┐   ┌────────────┐   ┌───────────────────┐  │
│  │ Dashboard UI │──>│ Controller │──>│ afterResponse()   │  │
│  │ (Alpine.js)  │<──│ (store)    │   │ dispatch(fn)      │  │
│  └──────────────┘   └─────┬──────┘   └────────┬──────────┘  │
│                           │                    │             │
│                    ┌──────▼──────┐     ┌───────▼────────┐   │
│                    │ LinkRequest │     │ ProductData    │   │
│                    │ (pending)   │     │ Service        │   │
│                    └──────┬──────┘     │ (AddLiveTag)   │   │
│                           │            └───────┬────────┘   │
│                    ┌──────▼──────┐     ┌───────▼────────┐   │
│                    │ GET /api/   │     │ Cashback       │   │
│                    │ extension/  │     │ Calculator     │   │
│                    │ jobs        │     └───────┬────────┘   │
│                    └──────┬──────┘             │            │
│                           │                    │            │
│                    ┌──────▼──────┐     ┌───────▼────────┐   │
│                    │ POST /api/  │     │ AffiliateCache │   │
│                    │ extension/  │     │ Service        │   │
│                    │ results     │     └────────────────┘   │
│                    └──────┬──────┘                          │
└───────────────────────────┼──────────────────────────────────┘
                            │
                    ┌───────▼───────────────┐
                    │   Chrome Extension     │
                    │   (background.js)      │
                    │   - polls jobs         │
                    │   - forwards to tab    │
                    │   - posts results      │
                    └───────┬───────────────┘
                            │
                    ┌───────▼───────────────┐
                    │   Content Script      │
                    │   (content.js)        │
                    │   - fills textarea    │
                    │   - clicks button     │
                    │   - reads modal       │
                    │   - returns links     │
                    └───────┬───────────────┘
                            │
                    ┌───────▼───────────────┐
                    │   affiliate.shopee.vn │
                    │   Custom Link page    │
                    │   (React SPA)         │
                    └───────────────────────┘
```

## Component Relationships

### Laravel Backend
- **Controllers** receive HTTP requests, validate, dispatch work
- **Services** contain business logic (ProviderFactory, ProductData, Cashback, Cache)
- **Models** are Eloquent ORM (11 models)
- **Middleware** includes auth, verified, Spatie permissions

### Browser Extension
- **background.js** — Service worker, polls API every 1-5s
- **content.js** — Runs on `affiliate.shopee.vn`, interacts with React form
- **popup.js/html** — User settings UI (API URL, token)

### Node.js Worker (DEPRECATED)
- `server.js` — Express on port 3001, used only for diagnostics now
- All Playwright/CDP code is deprecated (Shopee blocks CDP)

## Data Flow for Link Creation

1. User submits URL → `DashboardController@store()`
2. Platform detected (Shopee/Lazada/etc.)
3. If Shopee:
   a. URL resolved (short link expansion)
   b. item_id extracted from URL
   c. AffiliateCache checked for today's data
   d. **Cache HIT:** fields populated immediately → status `completed` if affiliate_url exists
   e. **Cache MISS:** minimal row created, afterResponse() dispatches ProductDataService + CashbackCalculator
4. HTTP response sent immediately (~50ms)
5. After response: AddLiveTag API fetches product data, updates LinkRequest + AffiliateCache
6. Browser extension picks up `pending` job, processes in Shopee tab, posts result back
7. Dashboard polling detects `completed` status, displays affiliate link

## Evolution of Approaches

| Approach | Status | Reason |
|----------|--------|--------|
| Direct GraphQL API | FAILED | HTTP 200 but empty response body |
| Playwright/CDP | BLOCKED | Shopee detects DevTools Protocol, redirects to CAPTCHA |
| Browser Extension MV3 | ACTIVE | Runs as real extension, bypasses CDP detection |

## Race Condition Analysis

**Worker vs AddLiveTag (afterResponse):**
- Worker reads: `id`, `original_url`, `item_id` — no product fields
- AddLiveTag writes: product fields, cashback, cache — no overlap with Worker reads
- Worker writes: `affiliate_url`, `status` — AddLiveTag does not touch these
- **Zero field overlap** between the two concurrent paths
- InnoDB row-level locking handles concurrent writes safely
