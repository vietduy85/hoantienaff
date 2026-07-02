# Kiến trúc hệ thống

## Mục tiêu dự án

Website **hoantien.xyz** — Người dùng dán link sản phẩm Shopee → Hệ thống tạo affiliate link → Người dùng mua hàng qua link → Nhận hoàn tiền (cashback).

## Luồng nghiệp vụ

```
User
  │
  ▼ Nhập link sản phẩm (VD: https://shopee.vn/...)
  │
  ▼ URL Resolver — Rút gọn short link (s.shopee.vn → full URL)
  │
  ▼ Cache lookup — Kiểm tra affiliate_cache (theo item_id + ngày)
  │
  ▼ Product Data — Gọi API AddLiveTag lấy thông tin sản phẩm
  │
  ▼ Cashback Calculator — Tính hoa hồng dựa trên commission rate
  │
  ▼ Affiliate Link Generation — Tạo link affiliate qua Worker/Extension
  │
  ▼ Cache save — Lưu kết quả vào affiliate_cache
  │
  ▼ Dashboard — Hiển thị kết quả cho user (product info + cashback + link)
```

### Giải thích từng bước

1. **User nhập link**: User dán URL sản phẩm Shopee vào form trên Dashboard.
2. **URL Resolver**: Nếu URL là short link (s.shopee.vn, vn.shp.ee), hệ thống follow redirect để lấy URL đầy đủ. Có retry + timing log.
3. **Cache lookup**: Kiểm tra `affiliate_cache` theo `item_id` và `cache_date` (hôm nay). Nếu đã cache → dùng luôn, không cần gọi API hay Worker.
4. **Product Data**: Gọi API `data.addlivetag.com/product-data/product-data.php` để lấy thông tin sản phẩm (giá, commission, tên, hình ảnh, rating...).
5. **Cashback Calculator**: Tính `user_estimated_cashback` dựa trên commission thực tế × cashback_rate (50%/60%/70%) sau khi trừ 10% thuế.
6. **Affiliate Link Generation**: Với Shopee, link được tạo bởi Browser Extension (content script inject vào trang affiliate.shopee.vn). Các platform khác fake link.
7. **Cache save**: Lưu toàn bộ thông tin sản phẩm + cashback + affiliate_url vào `affiliate_cache`.
8. **Dashboard**: Hiển thị link affiliate, cashback ước tính, thông tin sản phẩm.

## Kiến trúc tổng thể

```mermaid
graph TD
    subgraph "Laravel Application"
        C[HTTP Controllers]
        S[Services]
        M[Models]
        V[Blade Views]
        R[Routes]
    end

    subgraph "Database"
        DB[(MySQL)]
    end

    subgraph "Affiliate Worker"
        BE[Browser Extension<br/>MV3]
        CS[Content Script<br/>affiliate.shopee.vn]
    end

    subgraph "External APIs"
        AL[AddLiveTag API<br/>data.addlivetag.com]
        SP[Shopee Affiliate<br/>affiliate.shopee.vn]
        GG[Google OAuth]
        CF[Cloudflare]
    end

    User --> CF
    CF --> C
    C --> S
    S --> M
    M --> DB
    S --> BE
    BE --> CS
    CS --> SP
    S --> AL
    C --> V
    User --> GG
    GG --> C

    classDef php fill:#4F5B93,color:#fff
    classDef node fill:#83CD29,color:#000
    classDef db fill:#F29111,color:#fff
    classDef ext fill:#E44D26,color:#fff
    class C,S,M,V,R php
    class BE,CS node
    class DB db
    class AL,SP,GG ext
```

## Luồng request chi tiết

```mermaid
sequenceDiagram
    actor User
    participant Laravel
    participant Cache as AffiliateCache
    participant API as AddLiveTag API
    participant Worker as Browser Extension
    participant Shopee as affiliate.shopee.vn

    User->>Laravel: POST /link-requests (URL sản phẩm)
    Laravel->>Laravel: URL Resolver (nếu short link)
    Laravel->>Laravel: Extract item_id từ URL
    Laravel->>Cache: get(item_id, cache_date)
    
    alt Cache HIT
        Cache-->>Laravel: cached data
        Laravel->>Laravel: Update link_request từ cache
    else Cache MISS
        Laravel->>API: GET product-data.php?item_id=...
        API-->>Laravel: product info (commission, price, ...)
        Laravel->>Laravel: CashbackCalculator
        Laravel->>Cache: put(item_id, data)
        Laravel->>DB: INSERT link_request (status=pending)
        Worker->>Laravel: Poll GET /api/extension/jobs
        Laravel-->>Worker: Job list (pending links)
        Worker->>Shopee: Content script tạo custom link
        Shopee-->>Worker: affiliate URL
        Worker->>Laravel: POST /api/extension/results
        Laravel->>Cache: updateAffiliateUrl(item_id, url)
        Laravel->>DB: UPDATE link_request (status=completed)
    end

    Laravel-->>User: Response (link_request with data)
```

## Các thành phần chính

### 1. Laravel (PHP 8.2 / Laravel 12)
- Web server: Apache (XAMPP)
- Session driver: Database
- Cache driver: Database
- Queue driver: Database (không dùng queue thực tế — polling qua Extension)

### 2. Affiliate Worker (Node.js/Express)
- Port: 3001
- Express HTTP API
- Browser Extension (MV3) poll Laravel API để lấy job
- Content Script thao tác trực tiếp trên trang affiliate.shopee.vn
- Playwright/CDP: **DEAD** — Shopee phát hiện DevTools Protocol

### 3. Database (MySQL)
- 15 tables: users, sessions, cache, jobs, link_requests, affiliate_cache, ...

### 4. External APIs
- **AddLiveTag API**: Lấy thông tin sản phẩm Shopee
- **Google OAuth**: Đăng nhập
- **Cloudflare**: CDN, SSL, Tunnel

### 5. Cloudflare
- Proxy/DDoS protection
- SSL termination (origin chỉ nhận HTTP từ Cloudflare)
- Tunnel (cloudflared) kết nối origin server đến Cloudflare
- Cache: DYNAMIC (không cache trang PHP)
