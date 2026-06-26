# Shopee Affiliate Worker — Browser Extension

> **2026-06-26: Playwright/CDP đã chết.** Shopee detect DevTools Protocol và force captcha.
> Giải pháp: Browser Extension (MV3) chạy JS thuần trong page — không CDP, không Playwright.

## Kiến trúc mới

```
Laravel API ──poll──▶ Browser Extension ──msg──▶ Content Script ──inject──▶ Page JS
     ▲                                                                         │
     └────────────────────── POST kết quả ─────────────────────────────────────┘
```

## Yêu cầu

- Chrome bản thường (bất kỳ)
- Đã đăng nhập `affiliate.shopee.vn` (làm tay)

## Cài đặt Extension

1. Mở `chrome://extensions`
2. Bật **Developer mode** (góc phải)
3. **Load unpacked** → chọn thư mục `browser-extension/`
4. Extension hiện ra với icon màu đỏ ở thanh toolbar

## Cấu hình

Nhấn icon extension → điền:

- **API URL**: `https://hoantien.xyz` (hoặc `http://localhost` nếu chạy local)
- **Token**: xem trong `.env` ở `AFFILIATE_EXTENSION_TOKEN`

Bấm **Lưu**, sau đó bấm **Poll ngay** để test kết nối.

## Luồng hoạt động

| Bước | Mô tả |
|------|-------|
| 1 | User dán URL trên website → `LinkRequest` tạo với status `pending` |
| 2 | Extension poll `GET /api/affiliate/jobs` mỗi 1 phút |
| 3 | Extension thấy job → gửi message đến Content Script |
| 4 | Content Script dán URL vào textarea, bấm "Lấy link", chờ modal |
| 5 | Xong → gửi kết quả về background → POST lên Laravel |
| 6 | Laravel update `LinkRequest` thành `completed` |

## Chi tiết kỹ thuật

### content.js

- Dùng đúng logic từ `shopee-bulk-link.user.js` (đã verify hoạt động)
- `setReactValue()` — set value đúng cách cho React-controlled input
- Batch 5 URLs mỗi lần, throttle 1.5-3.2s giữa các lô
- Map kết quả theo index (không dùng URL matching)
- Phát hiện captcha → báo lỗi, không submit tiếp

### background.js

- Service worker, dùng `chrome.alarms` để poll (1 phút)
- Lưu API URL + token trong `chrome.storage.sync`
- Nhận kết quả từ content script → POST lên Laravel

## Debug

- Mở `chrome://extensions` → extension → **Service Worker** → console
- Popup extension hiển thị log chi tiết
- Poll manual bằng nút "Poll ngay" trong popup

## File cấu trúc

```
browser-extension/
├── manifest.json      # MV3 manifest
├── background.js      # Service worker (poll API)
├── content.js         # Content script (thao tác form)
└── popup.html/js      # Popup cấu hình
```
