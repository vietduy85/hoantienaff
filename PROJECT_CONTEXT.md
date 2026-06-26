# HOANTIENAFF PROJECT CONTEXT

## Mục tiêu dự án

Website hoàn tiền khi mua sắm. Domain: hoantien.xyz

Người dùng: Dán link → Tạo link affiliate → Mua hàng → Nhận hoàn tiền

## Kiến trúc (2026-06-26 — ĐÃ CHUYỂN)

```
Laravel 12 → REST API → Browser Extension (MV3) → Content Script → Page JS
```

### Đã bỏ

- ❌ Playwright / CDP — Shopee detect DevTools Protocol, force captcha
- ❌ ChromeManager, AffiliateNavigator, CustomLinkWorker, Queue, Logger, Benchmark
- ❌ Tất cả module trong `playwright/cdp/`

### Giữ lại

- ✅ Laravel + Provider Pattern (đa nền tảng: Shopee, Lazada, TikTok...)
- ✅ `AffiliateWorkerClient` — giao tiếp Laravel ↔ Worker (giờ là HTTP call đến API)

## Luồng hệ thống

```
Dashboard → ProviderFactory → ShopeeProvider → LinkRequest (pending)
    ↓
Extension poll GET /api/affiliate/jobs
    ↓
Content Script xử lý (page JS, không CDP)
    ↓
Extension POST /api/affiliate/result
    ↓
LinkRequest (completed)
```

## Các Provider hiện có

Shopee, Lazada, TikTok, Long Châu, Pharmacity, Traveloka, Agoda, Booking

## Trạng thái

- ProviderFactory: PASS
- Laravel ↔ Worker: PASS
- Shopee Affiliate (CDP): ❌ DEAD
- Shopee Affiliate (Extension): ✅ ĐANG XÂY

## Bước tiếp theo

1. Cài extension vào Chrome, test poll + process thật
2. Xử lý edge case: captcha, session expire, network error
3. Mở rộng cho các platform khác nếu cần
