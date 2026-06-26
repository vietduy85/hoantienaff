# Hoàn Tiền Affiliate

Website hoàn tiền khi mua sắm qua link affiliate.

## Kiến trúc

```
User → Laravel Website → REST API → Browser Extension → Shopee Affiliate (page JS)
```

- **Laravel 12** — Website chính + REST API queue
- **Browser Extension (MV3)** — Worker chạy trong Chrome thật, inject JS vào trang Custom Link
- **Content Script** — Chạy logic tạo short-link (dùng `setReactValue`, throttle, batch 5 URLs)
- **Không Playwright / CDP** — Shopee chặn DevTools Protocol, chỉ page JS thuần mới qua được

## Thành phần

### 1. Laravel (`/`)

- `POST /link-requests` — User gửi URL → tạo `LinkRequest` với status `pending`
- `GET /api/affiliate/jobs?token=...` — Extension poll các job Shopee đang chờ
- `POST /api/affiliate/result?token=...` — Extension gửi kết quả short-link về

### 2. Browser Extension (`affiliate-worker/browser-extension/`)

- `manifest.json` — Manifest V3, chỉ match `affiliate.shopee.vn/offer/custom_link`
- `background.js` — Poll API mỗi 1 phút, gửi URL đến content script, nhận kết quả
- `content.js` — Chạy trong trang Custom Link, thao tác form như người dùng
- `popup.*` — Cấu hình API URL + token, bật/tắt polling

## Cài đặt

### Laravel

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

### Browser Extension

1. Mở `chrome://extensions`
2. Bật **Developer mode**
3. **Load unpacked** → chọn `affiliate-worker/browser-extension/`
4. Cấu hình API URL và token trong popup extension

### Sử dụng

1. Mở `https://affiliate.shopee.vn` và đăng nhập tay
2. Vào menu **Hoa hồng** → **Custom Link**
3. Bật extension (popup) — nó sẽ tự động poll và xử lý
4. User gửi URL trên website → extension tự xử lý → kết quả về website
