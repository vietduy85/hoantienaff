# Shopee Affiliate Worker — CDP Setup

## Cấu trúc module mới

```
playwright/cdp/
├── ChromeManager.js      — CDP connectOverCDP, getContext, getPage
├── AffiliateNavigator.js — SPA navigation (không goto)
├── CustomLinkForm.js     — textarea input + submit
├── ModalParser.js        — parse modal kết quả
├── AffiliateWorker.js    — orchestrator
└── Queue.js              — FIFO queue (mỗi lần 1 request)
```

## Yêu cầu

- Node.js 18+
- Playwright  (`npm install playwright`)
- Chrome bản thường (đã cài sẵn)

## BƯỚC 1 — Mở Chrome với Remote Debugging

```bat
"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe" ^
  --remote-debugging-port=9222 ^
  --user-data-dir=C:\Users\Administrator\shopee-chrome-profile
```

Giải thích:
- `--remote-debugging-port=9222` — cho phép CDP kết nối
- `--user-data-dir=...` — profile riêng, không dùng User Data mặc định

## BƯỚC 2 — Đăng nhập

Sau khi Chrome mở:

1. Vào `https://affiliate.shopee.vn`
2. Đăng nhập tài khoản Shopee Affiliate
3. Vào trang **Custom Link** (`offer/custom_link`) và để đó

## BƯỚC 3 — Giữ nguyên Chrome

- **Không đóng Chrome**
- **Không tắt remote debugging**
- Worker sẽ attach vào Chrome đang chạy qua CDP

## BƯỚC 4 — Khởi động worker

```bash
cd affiliate-worker
node server.js
```

## BƯỚC 5 — Kiểm tra CDP

```bash
curl http://127.0.0.1:3001/diagnostic/cdp
```

Kết quả mong đợi:

```json
{
  "connected": true,
  "browserVersion": "...",
  "contexts": 1,
  "pages": [
    { "url": "https://affiliate.shopee.vn/offer/custom_link", "title": "..." }
  ]
}
```

## BƯỚC 6 — Kiểm tra SPA Navigation

```bash
curl http://127.0.0.1:3001/diagnostic/custom-link
```

Kết quả mong đợi:

```json
{
  "status": "ALREADY_ON_CUSTOM_LINK",
  "url": "https://affiliate.shopee.vn/offer/custom_link",
  "title": "...",
  "screenshot": "storage/diagnostic-custom-link.png"
}
```

## BƯỚC 7 — Tạo link

```bash
curl -X POST http://127.0.0.1:3001/shopee/create-link \
  -H "Content-Type: application/json" \
  -d '{"url":"https://shopee.vn/some-product"}'
```

Kết quả mong đợi:

```json
{
  "success": true,
  "shortLink": "https://short.link/...",
  "results": [
    { "original": "https://shopee.vn/some-product", "short": "https://short.link/..." }
  ]
}
```

## Debug

- Tất cả log đều có prefix: `[CDP]`, `[Navigator]`, `[Form]`, `[Parser]`, `[Worker]`
- Nếu timeout → xem log biết Worker đang ở bước nào
- Nếu `connected: false` → Chrome chưa mở remote debugging

## Rollback

Module cũ vẫn giữ nguyên ở `playwright/` (không xoá).
Module CDP mới nằm trong `playwright/cdp/` riêng biệt.
Để rollback: xoá routes CDP trong `server.js`, bỏ qua `playwright/cdp/`.
