# AffiliateCache System

## Purpose

Avoid redundant AddLiveTag API calls for the same product within the same day. The cache is keyed by `(item_id, cache_date)` — each product gets one cache entry per day.

## Model: `AffiliateCache`

**File:** `app/Models/AffiliateCache.php`
**Table:** `affiliate_cache`

**Composite Primary Key:** `(item_id, cache_date)`
- Changed from single `item_id` PK via migration `2026_06_30_150000`
- Enables daily rotation: yesterday's data is automatically ignored

**Fillable fields (17):**
`item_id`, `cache_date`, `shop_id`, `product_name`, `product_price`, `seller_commission`, `shopee_commission`, `estimated_cashback`, `user_estimated_cashback`, `cashback_rate`, `affiliate_url`, `rating`, `sales`, `product_image`, `product_link`, `shop_name`, `is_xtra`, `data_source`, `last_affiliate_created_at`

## Service: `AffiliateCacheService`

**File:** `app/Services/AffiliateCacheService.php`

### Key Methods

#### `get(int $itemId): ?AffiliateCache`

Looks up cache by `item_id` + today's date. Returns model or null.

#### `logMiss(int $itemId): void`

Logs cache MISS (only when `AFFILIATE_TIMING=true` in .env).

#### `put(int $itemId, array $data): AffiliateCache`

Uses `updateOrCreate` with composite key `(item_id, cache_date)`:

```php
AffiliateCache::updateOrCreate(
    ['item_id' => $itemId, 'cache_date' => $this->cacheDate],
    $data
);
```

**Important:** `updateOrCreate` with `fill()` only updates dirty (supplied) columns. Fields not in the `$data` array are NOT overwritten. This means an empty `put($itemId, [])` creates a minimal row without overwriting any existing product data.

#### `updateAffiliateUrl(int $itemId, string $affiliateUrl): void`

Called by `AffiliateJobController@result` when the extension posts back. Updates only `affiliate_url` and `last_affiliate_created_at`.

#### `extractItemId(string $url): ?int`

Extracts item_id from Shopee URLs. Tries in order:

1. `item_id` query parameter
2. `itemId` query parameter
3. `/product/{shop_id}/{item_id}` path pattern
4. `/opaanlp/{shop_id}/{item_id}` path pattern
5. `-i.{shop_id}.{item_id}` path pattern

## Flow Example

### First request for a product (Cache MISS)

1. User submits URL → `DashboardController@store()`
2. `item_id` extracted, cache lookup → MISS
3. `cacheService.logMiss(itemId)`
4. `cacheService.put(itemId, [])` — creates empty row with just `item_id` + `cache_date`
5. HTTP response sent immediately
6. `afterResponse()` runs:
   - `ProductDataService::getByUrl()` → AddLiveTag API (~730ms)
   - `CashbackCalculator::calculate()` → user cashback
   - `LinkRequest::update()` → product fields
   - `cacheService.put(itemId, $fullData)` → fills cache row
7. Extension picks up pending job, processes in Shopee
8. Extension posts result → `cacheService.updateAffiliateUrl(itemId, url)` → fills affiliate_url

### Second request for same product (Cache HIT)

1. User submits URL → `DashboardController@store()`
2. Cache lookup → HIT
3. All fields populated from cache immediately (including cashback, affiliate_url if available)
4. No AddLiveTag API call needed
5. If `affiliate_url` exists → status `completed` immediately
6. If `affiliate_url` is null → status `pending`, extension will process

## Race Condition Safety

- **Worker reads:** `id`, `original_url`, `item_id` — never reads cache fields
- **AddLiveTag writes:** product fields + cashback + cache — never writes `affiliate_url`
- **Worker writes:** `affiliate_url`, `status` — never writes product/cashback fields
- **Zero field overlap** between the two concurrent code paths
- `updateOrCreate` with `fill()` only overwrites dirty columns
