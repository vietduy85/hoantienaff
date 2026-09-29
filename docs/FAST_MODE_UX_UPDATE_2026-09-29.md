# Fast Mode UX Update — 2026-09-29

**Phạm vi:** đổi wording + đi thẳng vào action "🛒 Add giỏ / Mua ngay" khi Fast Mode tạo link thành công.
**Kết luận:** **PASS** — thay đổi frontend-only, 2 file, không đụng backend/database/OPcache.

---

## 1. Working tree BEFORE

```
Branch : main
HEAD   : 84c2c2f  fast mode v1   (đồng bộ origin/main)
Staged : (rỗng)
Untracked:
  ?? docs/OPCACHE_DEPLOYMENT_2026-09-29.md
  ?? docs/OPCACHE_DEPLOYMENT_AND_PERFORMANCE_2026-09-28.md
```

Hai file untracked trên là tài liệu OPcache có sẵn từ task trước — **không sửa, không xóa**.

Hash chuẩn (baseline) trước khi sửa:

| File | SHA-256 |
|---|---|
| `app/Http/Controllers/DashboardCreateDirectLinkController.php` | `DB3D031887201D5F056565BAD42465541E635EAFDE9A307126C909A159349047` |
| `resources/views/dashboard/partials/link-generator.blade.php` | `072C1E2034C92845BC03AB4AC03A8934749429974E54CE399C0BF3B9B9BDB261` |
| `tests/Feature/FastModeTest.php` | `8BB7A2E68F878C2D4792E3DDB60B6608822A70BFEE2990A0A449B4C25183DC4B` |
| `routes/web.php` | `DDC2911ADF9F1EF9E8DD995B2C5D470798C69883328F16A86D31C6A97D22C93A` |
| `config/app.php` | `1C285473A50E86E25B8DA1B0163A72AC0762C7B0CCB1F6DA3531BAFC6EEDEFB3` |
| `C:\xampp\php\php.ini` | `8FF4511CBABBEB1C8C5B2AE5D1AD0B9AAEB610BDD058BD74AFF67A0D825A4B54` |

## 2. Files đã inspect

- `resources/views/dashboard/partials/link-generator.blade.php` — toàn bộ Alpine component
- `app/Http/Controllers/DashboardCreateDirectLinkController.php` — nhánh Fast Mode
- `tests/Feature/FastModeTest.php`, `tests/Feature/DashboardLinkGeneratorViewTest.php`
- `tests/Feature/CsrfRecoveryHarnessRunTest.php` + `tests/harness/csrf-recovery.run.mjs`
- `routes/web.php`, `config/app.php`

## 3. Action thật của nút "🛒 Add giỏ / Mua ngay"

**Quan trọng:** nút này **không có function riêng**. Nó là anchor declarative thuần HTML
(`link-generator.blade.php:520-531`):

```html
<a x-bind:href="result.affiliate_url" target="_blank" rel="noopener noreferrer" class="...">
```

- Không có `@click`, không `window.open`, không `window.location`, không tracking/sub_id JS.
- Không URL transformation, không deep-link, không mobile logic, không clipboard.
- Vì vậy **không có logic nào để tái sử dụng trực tiếp**; để Fast Mode và nút dùng *chung một
  implementation* (đúng tinh thần yêu cầu "gọi CHÍNH action", không duplicate), đã tách
  `openAffiliateLink()` làm implementation duy nhất và cho nút gọi vào đó.

Phân biệt 3 action (tránh nhầm):
- **Add giỏ / Mua ngay** → `result.affiliate_url`, `target="_blank"` ← action được dùng lại
- `Mở link hoàn tiền` (dòng 464) → `result.affiliate_url`, **không** `target="_blank"`
- `Mở trang sản phẩm Shopee` (dòng 497) → `result.shopeedirect_url` (URL khác)

## 4. Before / After

### 4.1 Wording
- FROM: `Tạo link nhanh, không tải thông tin sản phẩm`
- TO: `Tạo link nhanh, không hiển thị thông tin sản phẩm` (dòng 407)

### 4.2 Fast Mode — trước
```
POST /link-requests (fast_mode=1) -> 200
  this.result = { ...data }        <- gán result
  return                            <- card HIỆN, user phải tự bấm "Add giỏ / Mua ngay"
```

### 4.3 Fast Mode — sau
```
POST /link-requests (fast_mode=1) -> 200
  this.result = null                <- KHÔNG card
  if (data.affiliate_url) openAffiliateLink(data.affiliate_url)   <- MỞ TAB NGAY
  return                            <- KHÔNG polling
```

## 5. Những gì KHÔNG đổi

| Hạng mục | Trạng thái |
|---|---|
| Paste auto-create + debounce 300ms | **không đổi** (không nằm trong diff) |
| Nút `🚀 Tạo Link Ngay` / Enter / submit | **không đổi** |
| Validation, URL normalization, platform detection | **không đổi** |
| Anti-double-submit (`if (this.loading) return`) | **không đổi** |
| Loading / error handling / CSRF 419 recovery | **không đổi** |
| `Mở trang sản phẩm Shopee` | **không đổi** |
| Normal Mode: ProductData, cashback, polling, result card, copy/open, Add giỏ | **không đổi** |
| Backend controller / routes / config | **không đổi** (hash giống baseline) |
| Database | **không đổi** |
| OPcache | **không đổi** |

Fast Mode vẫn **không** gọi ProductData, **không** tính cashback, **không** polling,
**không** ghi `affiliate_cache` — xác nhận bằng test backend `FastModeTest` (pass).

> Markup của card fast (`⚡ Link đã sẵn sàng`, mô tả "không tính hoàn tiền",
> `Mở trang sản phẩm Shopee`) **được giữ nguyên trong file** nhưng đã **không còn render**
> khi Fast Mode, vì `result = null` làm `<template x-if="result">` không được instantiate.
> Giữ markup giúp diff nhỏ và không đụng Normal Mode. Đã kiểm chứng bằng browser (mục 8).

## 6. Diff

```
 resources/views/dashboard/partials/link-generator.blade.php | 49 +++++++++++++---
 tests/Feature/FastModeTest.php                               | 65 +++++++++++++++++++++-
 2 files changed, 106 insertions(+), 8 deletions(-)
```

Các dòng chính trong `link-generator.blade.php`:
- `205-214` — nhánh Fast Mode: clear result → mở tab → return
- `313-321` — `openAffiliateLink(url)` (implementation duy nhất)
- `328-332` — `onAddToCartClick(e)` (nút gọi vào implementation chung)
- `407` — wording mới
- `523` — `@click="onAddToCartClick($event)"` trên anchor Add giỏ

### 6.1 Một lỗi thật đã phát hiện và sửa trong lúc làm

Bản đầu tiên đặt dấu `"` trong comment tiếng Việt **bên trong** thuộc tính `x-data="{ ... }"`.
Dấu `"` kết thúc sớm giá trị attribute → x-data bị cắt cụt → làm hỏng **2 test**
(`DashboardLinkGeneratorViewTest`, `CsrfRecoveryHarnessRunTest` với
`SyntaxError: Missing catch or finally after try`). Đã sửa bằng cách bỏ dấu `"` khỏi
comment. Đã verify: vùng `x-data` chỉ còn đúng 2 dấu `"` mở/đóng.

## 7. Test

```
php artisan test --filter=FastModeTest
  -> Tests: 13 passed (86 assertions)     [trước: 12 passed / 70 assertions]

php artisan test
  -> Tests: 3 failed, 1133 passed (4037->4044 assertions)   [baseline: 3 failed, 1133 passed]
```

3 test fail là **pre-existing, không liên quan**, đã fail từ baseline:
- `Tests\Feature\Auth\RegistrationTest > new users can register`
- `Tests\Feature\Services\ShopeeFood\ShopeeFoodOrderSyncServiceTest > cookie guard fails fast without http`
- `Tests\Feature\TikTokSyncPhase3Test > preexisting credited order is not credited again by admin`

Đã thêm 1 test mới `test_fast_mode_opens_add_to_cart_action_without_result_card` khoá lại:
đúng 1 implementation, 2 call site, Fast Mode clear result **trước** khi mở tab, chỉ mở khi
có `affiliate_url`, và `return` **trước** `startPolling()`.

## 8. Kiểm chứng thật trên trình duyệt

Harness: Chrome `154.0.8037.58`, **popup blocker mặc định** (không dùng
`--disable-popup-blocking`), chạy **partial Blade thật** + **Alpine bundle thật**
(`public/build/assets/app-YANisdxd.js`). Chỉ chặn network bằng stub — **không ghi DB thật**;
stub chặn luôn mọi request về `hoantien.xyz` để không chạm production.

```
S1 Fast Mode + nút Tạo Link Ngay
   PASS đúng 1 POST /link-requests      (got 1)
   PASS KHÔNG polling                   (poll=0)
   PASS mở ĐÚNG 1 tab affiliate
   PASS tab mang đúng affiliate_url     (af=STUB)
   PASS KHÔNG hiện result card
   PASS KHÔNG "Link đã sẵn sàng" / "Mở link hoàn tiền" / "Mở trang sản phẩm Shopee" / mô tả fast

S2 Fast Mode + paste Ctrl+V thật (clipboard thật, input event thật)
   PASS giá trị đã dán   "https://shopee.vn/product/9/8"
   PASS đúng 1 POST      (got 1)
   PASS mở ĐÚNG 1 tab affiliate
   PASS KHÔNG hiện card

S3 Normal Mode
   PASS KHÔNG tự mở tab
   PASS CÓ polling        (GET /api/link-request/5150)
   PASS result card HIỆN + nút Add giỏ / Mua ngay HIỆN
   PASS KHÔNG "Link đã sẵn sàng"

S4 Normal Mode - bấm nút Add giỏ / Mua ngay
   PASS anchor giữ href=affiliate_url, target=_blank, rel="noopener noreferrer"
   PASS mở ĐÚNG 1 tab

S5 Fast Mode trả về nhưng KHÔNG có affiliate_url
   PASS KHÔNG mở tab nào
   PASS KHÔNG treo loading   (loading=false, result=null, error='')
   PASS KHÔNG hiện card

S6 Mobile 390x844 (mobile:true, DPR 3)
   PASS mở ĐÚNG 1 tab affiliate
   PASS KHÔNG hiện card
   PASS không tràn ngang     (scrollWidth=390)

S7 Bảo toàn hành vi native của anchor
   PASS Ctrl+click: mở tab, KHÔNG bị preventDefault (số lần preventDefault = 0)
   PASS Middle-click: KHÔNG bị preventDefault (= 0)

S8 Lặp lại hành động
   PASS Lần 1 đúng 1 tab; Lần 2 đúng 1 tab, đúng 1 POST, KHÔNG card (không nhân bản)

================  35 passed, 0 failed  ================
```

### 8.1 Rủi ro popup blocker — đã đo trước khi code

Trước khi sửa gì, đã kiểm chứng giả thuyết rủi ro lớn nhất: `await fetch()` rồi mới mở
`target="_blank"` có bị popup blocker chặn không.

| Kịch bản | Kết quả |
|---|---|
| Không có user gesture (control) | **0 tab — BỊ CHẶN** |
| Bấm nút thật → 300ms → fetch → mở tab | **1 tab — OK** |
| Paste thật (Ctrl+V) → 300ms → fetch → mở tab | **1 tab — OK** |

Control bị chặn chứng minh harness **thực sự nhạy** với popup blocker, nên 2 kết quả "OK" là
có ý nghĩa chứ không phải may mắn.

Đo ngưỡng transient activation của Chrome:

| Delay sau gesture | Popup |
|---|---|
| 0 / 500 / 1000 / 2000 / 3000 / 4000 ms | **mở** |
| 5000 / 6000 / 8000 ms | **bị chặn** |

Fast Mode trả về sau ~240 ms (nhanh vì cố tình bỏ ProductData), tức **biên an toàn ~20×**.
Không thêm fallback/fancy logic nào (cũng đúng yêu cầu "không tự thêm workaround").

**Rủi ro còn lại (đã biết, chấp nhận):** nếu backend Fast Mode treo > ~5 giây, popup sẽ bị
chặn và user không thấy gì. Không phát hiện được trạng thái này bằng JS, nên không thể xử lý
mà không tạo fallback — mà fallback bị cấm. Ghi nhận tại đây thay vì che giấu.

## 9. Mobile

Đã kiểm ở viewport `390x844, mobile:true, DPR 3` (mô phỏng). Chưa kiểm được trên
**thiết bị thật** (Android Chrome / iOS Safari) vì môi trường không có thiết bị —
phần này **không được coi là đã xác minh đầy đủ**. Vì Fast Mode chỉ dùng
`a.target="_blank"` (đúng semantics sẵn có của nút Add giỏ) nên không có logic mobile mới
cần kiểm, nhưng popup behavior trên mobile browser thực vẫn nên xác nhận tay khi deploy.

## 10. Database

**Không có thay đổi nào.** Harness chạy ngoài DB với network stub; test dùng
`RefreshDatabase`/fake của Laravel. Không `migrate`, không `migrate:fresh`, không xóa
users / link_requests / affiliate_cache, không tạo benchmark fixture.

## 11. OPcache

**OPcache impact: No change.**

- `C:\xampp\php\php.ini` SHA-256 sau task: `8FF4511CBABBEB1C8C5B2AE5D1AD0B9AAEB610BDD058BD74AFF67A0D825A4B54` — **giống baseline**.
- Không bật/tắt, không restart Apache, không sửa directive nào.
- Không cần restart: file đã đổi là `.blade.php` và `opcache.validate_timestamps=1` nên
  được tự reload.

## 12. Trạng thái cuối

```
 M resources/views/dashboard/partials/link-generator.blade.php
 M tests/Feature/FastModeTest.php
?? docs/OPCACHE_DEPLOYMENT_2026-09-29.md            (có sẵn, không sửa)
?? docs/OPCACHE_DEPLOYMENT_AND_PERFORMANCE_2026-09-28.md  (có sẵn, không sửa)
```

Hash sau thay đổi:

| File | SHA-256 | So với baseline |
|---|---|---|
| `resources/views/dashboard/partials/link-generator.blade.php` | `EF70A8C6B95388FC38E91B0CF58A9E70BD6E98159F0C943864DAC7C9F78C8D80` | **đã sửa (có chủ đích)** |
| `tests/Feature/FastModeTest.php` | `866DBAA69C8914769646BFE88F246D3FFD31BC030D4583DC6F48D43CA274091A` | **đã sửa (có chủ đích)** |
| `app/Http/Controllers/DashboardCreateDirectLinkController.php` | `DB3D031887201D5F056565BAD42465541E635EAFDE9A307126C909A159349047` | **không đổi** |
| `routes/web.php` | `DDC2911ADF9F1EF9E8DD995B2C5D470798C69883328F16A86D31C6A97D22C93A` | **không đổi** |
| `config/app.php` | `1C285473A50E86E25B8DA1B0163A72AC0762C7B0CCB1F6DA3531BAFC6EEDEFB3` | **không đổi** |

**Chưa commit** (HEAD vẫn là `84c2c2f`).

## 13. Kết luận

**PASS.**

- Wording đúng chính xác yêu cầu.
- Fast Mode thành công → mở **đúng 1 tab** bằng **chính action** của nút Add giỏ / Mua ngay
  (dùng chung một implementation `openAffiliateLink`), **không** hiện card trung gian,
  **không** polling, **không** bắt user bấm "Mở link hoàn tiền" thêm lần nữa.
- Paste và nút bấm đều hoạt động; Normal Mode và mọi thứ ngoài phạm vi giữ nguyên 100%.
- Không regression: full suite về đúng baseline (3 fail pre-existing).
- Đã đo trước rủi ro popup và xác nhận ngưỡng an toàn.
- Backend / database / OPcache: không đụng.

**Còn lại cần làm tay:** xác nhận popup behavior trên mobile browser thật khi deploy.
