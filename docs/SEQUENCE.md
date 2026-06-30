# Sequence Diagrams

## 1. Luồng tạo Affiliate Link (Cache MISS)

```mermaid
sequenceDiagram
    actor User
    participant D as Dashboard (Blade)
    participant DC as DashboardController
    participant URL as UrlResolverService
    participant ACS as AffiliateCacheService
    participant PDS as ProductDataService
    participant CC as CashbackCalculator
    participant DB as Database
    participant AL as AddLiveTag API

    User->>D: Nhập URL sản phẩm Shopee
    D->>DC: POST /link-requests (original_url)
    
    DC->>DC: detectPlatform(url) → "Shopee"
    DC->>DB: INSERT link_requests (status=pending)
    
    DC->>URL: resolve(original_url)
    alt Short link
        URL->>URL: Follow redirect (max 10 hops)
        URL-->>DC: Full URL
    else Normal link
        URL-->>DC: Original URL
    end
    
    DC->>ACS: extractItemId(resolvedUrl)
    ACS-->>DC: item_id
    
    DC->>ACS: get(item_id)
    ACS->>DB: SELECT WHERE item_id=? AND cache_date=today
    DB-->>ACS: null (MISS)
    ACS-->>DC: null
    DC->>ACS: logMiss(item_id)
    
    DC->>PDS: getByUrl(resolvedUrl)
    PDS->>PDS: extractProductIds(url) → item_id
    PDS->>AL: GET product-data.php?item_id=...
    AL-->>PDS: product info (JSON)
    PDS-->>DC: Mapped data
    
    DC->>CC: calculate(commission, price)
    CC->>CC: commission_rate → rate → user_cashback
    CC-->>DC: {cashback_rate, user_estimated_cashback}
    
    DC->>DB: UPDATE link_request (product data + cashback)
    DC->>ACS: put(item_id, data)
    ACS->>DB: INSERT/UPDATE affiliate_cache
    DB-->>DC: Done
    
    DC-->>D: Response (success)
    D-->>User: Hiển thị kết quả (product + cashback)
```

## 2. Luồng Worker (Browser Extension) — Tạo Affiliate Link

```mermaid
sequenceDiagram
    participant L as Laravel API
    participant BE as Background.js
    participant CS as Content.js
    participant S as affiliate.shopee.vn

    Note over L: User đã submit URL, status=pending
    
    loop Poll every 3s
        BE->>L: GET /api/extension/jobs?token=...
        L->>L: SELECT link_requests WHERE status=pending LIMIT 5
        L->>L: UPDATE status=processing
        L-->>BE: [{id, original_url}, ...]
        
        alt Có jobs
            BE->>CS: chrome.tabs.sendMessage({processJobs, jobs})
            
            CS->>CS: batch = jobs.slice(0, 5)
            
            loop Mỗi URL trong batch
                CS->>S: Tìm textarea input
                CS->>CS: setReactValue(textarea, url)
                CS->>CS: Click "Lấy link"
                Note over CS: Chờ 2s
                CS->>S: Đọc kết quả
                S-->>CS: affiliate URL
                CS->>BE: {type: 'jobResult', results}
            end
            
            BE->>L: POST /api/extension/results?token=...
            L->>L: UPDATE link_requests SET status=completed
            L->>L: UPDATE affiliate_cache SET affiliate_url
            
        else Không có jobs
            Note over BE: Đợi 3s, poll lại
        end
    end
```

## 3. Luồng Cache HIT

```mermaid
sequenceDiagram
    actor User
    participant DC as DashboardController
    participant ACS as AffiliateCacheService
    participant DB as Database
    
    User->>DC: Submit URL (sản phẩm đã cache hôm nay)
    DC->>ACS: extractItemId(url) → 123
    DC->>ACS: get(123)
    ACS->>DB: SELECT WHERE item_id=123 AND cache_date=today
    DB-->>ACS: AffiliateCache (HIT)
    ACS-->>DC: Cached data
    
    alt Có affiliate_url
        DC->>DB: UPDATE link_request SET status=completed, ... (từ cache)
    else Chưa có affiliate_url
        DC->>DB: UPDATE link_request SET status=pending, ... (từ cache)
        Note over DC: Worker sẽ xử lý sau
    end
    
    DC-->>User: Response với cached data
```

## 4. Luồng Google Login

```mermaid
sequenceDiagram
    actor User
    participant G as Google OAuth
    participant GC as GoogleController
    participant DB as Database
    
    User->>GC: Click "Đăng nhập Google"
    GC->>G: Redirect đến Google
    G->>User: Chọn tài khoản
    User->>G: Xác nhận
    G->>GC: Callback (code)
    GC->>G: Exchange code → user info
    G-->>GC: {email, name, google_id, avatar}
    
    GC->>DB: SELECT users WHERE email=?
    
    alt User tồn tại
        GC->>DB: UPDATE google_id, avatar
    else User mới
        GC->>DB: INSERT user (name, email, google_id, avatar, password=random)
    end
    
    GC->>GC: Auth::login(user, remember=true)
    GC-->>User: Redirect dashboard
```

## 5. Luồng Cashback Calculation

```mermaid
sequenceDiagram
    participant DC as DashboardController
    participant PDS as ProductDataService
    participant CC as CashbackCalculator
    
    DC->>PDS: getByUrl(url)
    PDS-->>DC: {commission: 30000, product_price: 200000, ...}
    
    DC->>CC: calculate(30000, 200000)
    
    Note over CC: commission_rate = 30000/200000 = 0.15
    Note over CC: 0.15 >= 0.12 → rate = 60%
    Note over CC: net = floor(30000 * 0.90) = 27000
    Note over CC: user = floor(27000 * 0.60) = 16200
    
    CC-->>DC: {cashback_rate: 0.60, user_estimated_cashback: 16200}
```

## 6. Luồng URL Resolver

```mermaid
sequenceDiagram
    participant DC as DashboardController
    participant URL as UrlResolverService
    
    DC->>URL: resolve(shortUrl)
    Note over URL: s.shopee.vn/abc123
    
    URL->>URL: isShortLink(shortUrl)
    alt Là short link
        URL->>URL: expandShortUrl(shortUrl)
        
        loop Retry tối đa 3 lần
            URL->>External: GET shortUrl (follow redirect)
            alt Success (HTTP 2xx/3xx)
                External-->>URL: Full URL
                URL-->>DC: https://shopee.vn/product/...
            else Non-retryable error (4xx, ...)
                URL-->>DC: null (fallback)
            else Retryable error (timeout, 5xx)
                Note over URL: Retry với delay
            end
        end
        
    else Không phải short link
        URL-->>DC: Original URL
    end
```

## 7. Luồng Provider Detection

```mermaid
flowchart TD
    A[URL input] --> B{Chứa 'shopee'?}
    B -->|Yes| C[ShopeeProvider → Worker]
    B -->|No| D{Chứa 'lazada'?}
    D -->|Yes| E[LazadaProvider → Fake link]
    D -->|No| F{Chứa 'tiktok'?}
    F -->|Yes| G[TikTokProvider → Fake link]
    F -->|No| H{Chứa 'longchau'?}
    H -->|Yes| I[LongChauProvider → Fake link]
    H -->|No| J{Chứa 'pharmacity'?}
    J -->|Yes| K[PharmacityProvider → Fake link]
    J -->|No| L{Chứa 'traveloka'?}
    L -->|Yes| M[TravelokaProvider → Fake link]
    L -->|No| N{Chứa 'agoda'?}
    N -->|Yes| O[AgodaProvider → Fake link]
    N -->|No| P{Chứa 'booking'?}
    P -->|Yes| Q[BookingProvider → Fake link]
    P -->|No| R[Throw RuntimeException]
```

## 8. Tổng quan luồng dữ liệu

```mermaid
flowchart LR
    subgraph "User"
        U[User]
        B[Browser]
    end
    
    subgraph "Laravel"
        C[Controller]
        S[Service]
        M[Model]
        V[View]
    end
    
    subgraph "External"
        G[Google]
        A[AddLiveTag]
        CF[Cloudflare]
    end
    
    subgraph "Worker"
        W[Express Server]
        E[Extension]
    end
    
    B <--> CF
    CF <--> C
    C <--> S
    S <--> M
    C <--> V
    V <--> B
    C <--> G
    S <--> A
    W <--> E
    S <--> W
    
    classDef user fill:#61BD6D,color:#fff
    classDef laravel fill:#4F5B93,color:#fff
    classDef ext fill:#E44D26,color:#fff
    classDef worker fill:#83CD29,color:#000
    class U,B user
    class C,S,M,V laravel
    class G,A,CF ext
    class W,E worker
```
