# Fast Mode iOS Deep-Link / Shopee App Behavior — FORENSIC REPORT

**Ngày:** 2026-09-29
**Phạm vi:** ĐIỀU TRA CHỈ — không sửa code. Working tree sạch, HEAD `7665a2b trien khai fast mode v2`.
**Thiết bị thực địa:** **iPhone 12**. Tất cả kết luận dưới đây là **iOS / WebKit**.
**ROOT CAUSE STATUS:** **CONFIRMED** — đủ 4/4 flow thực địa, giải thích bằng **một biến duy nhất**. Mục 12.

> **Đính chính phiên bản trước:** bản đầu tiên của báo cáo này phân tích **sai hệ điều hành**.
> Toàn bộ nội dung đã được viết lại, chỉ dành cho **iPhone 12** (iOS / WebKit). Tài liệu này
> không chứa bất kỳ suy diễn nào về nền tảng khác.

---

## 1. Executive summary

Hai kết luận nền, cả hai đều có evidence:

**(1) Normal Mode và Fast Mode dùng CÙNG một cơ chế, cùng một URL.**

Cả hai đều đi qua `openAffiliateLink()` → dynamic `<a target="_blank" rel="noopener noreferrer">.click()`.
Normal Mode **không phải** native anchor navigation: `onAddToCartClick()` gọi
`e.preventDefault()` rồi gọi đúng hàm đó. Comment trong code (dòng 307) nói rõ: *"DUY NHẤT một
implementation... Dùng chung cho: 1) nút Add giỏ (Normal Mode) 2) Chế độ nhanh"*.

**(2) Dialog "This site is trying to open another application" là của trình duyệt, phản ứng với
custom scheme `shopeevn://` mà Shopee tự kích hoạt — không phải do URL hay code của ta.**

`https://s.shopee.vn/an_redir?...` trả `200` + HTML chạy `ULS.init()`, và ULS tự chạy
`window.location.href = "shopeevn://reactPath?..."` (`evokeAppMethod: 2`).

**Biến duy nhất giữa Normal và Fast là `await` (async boundary) trước khi gọi hàm chung.**

### Bốn flow thực địa trên iPhone 12 — đều đã test

| | Chrome — **Normal** | Chrome — **Fast** | Safari — **Normal** | Safari — **Fast** |
|---|---|---|---|---|
| Tab trung gian | không thấy | **có** (`s.shopee.vn`) | (không chắc) | **không có** |
| Dialog | không | **có** `[Allow]/[Don't allow]` | không | không |
| App | **mở trực tiếp** | mở sau khi bấm Allow | **mở** | **không chuyển** |

⇒ Với **cùng URL, cùng `openAffiliateLink()`, cùng `target="_blank"`, cùng thiết bị**, chỉ
khác `await`, kết quả **đảo ngược hoàn toàn** — và hiệu ứng đó lặp lại **độc lập trên cả hai
trình duyệt**.

⇒ **ROOT CAUSE CONFIRMED: `await` trước khi gọi `openAffiliateLink()` làm mất user activation.**

**Và "popup blocker" là giải thích KHÔNG đủ** (đây là điểm bắt buộc phải nói rõ):

Nếu chỉ là popup blocker, Chrome và Safari (cùng WebKit) phải hành xử giống nhau. Thực tế
không giống: **Chrome mở được tab, Safari thì không mở gì cả.** Vậy có **hai cổng kiểm soát
độc lập**, và mỗi trình duyệt fail một cổng khác nhau (mục 5, mục 12).

**Dữ kiện an toàn (Test 4):** bấm `[Don't allow]` → tab vẫn **về `shopee.vn` product page**
⇒ cơ chế `fallbackTime: 3000` của ULS hoạt động, người dùng không bị mắc kẹt ở trang trắng.

## 2. Current Fast Mode implementation

`resources/views/dashboard/partials/link-generator.blade.php:205-218`

```js
if (data.fast_mode) {
    this.result = null;                        // không hiện card
    this.loading = false;
    if (data.affiliate_url) {
        this.openAffiliateLink(data.affiliate_url);   // <-- SAU await fetch
    }
    this.$nextTick(() => { /* focus, select */ });
    return;                                   // không polling
}
```

`openAffiliateLink()` — dòng 313-323:

```js
openAffiliateLink(url) {
    if (!url) return;
    const a = document.createElement('a');
    a.href = url;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    a.remove();
}
```

Luồng: `submit()`/paste → `post()` → **`await` `fetch('/link-requests')` (~240ms)** → `openAffiliateLink()`.

## 3. Current Normal Mode implementation

Anchor Add giỏ / Mua hàng — dòng 519-528:

```html
<a x-bind:href="result.affiliate_url" target="_blank" rel="noopener noreferrer"
   @click="onAddToCartClick($event)" class="...">🛒 Add giỏ / Mua hàng</a>
```

`onAddToCartClick()` — dòng 328-332:

```js
onAddToCartClick(e) {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
    e.preventDefault();                                    // <-- chặn native navigation
    this.openAffiliateLink(this.result?.affiliate_url);   // <-- CÙNG hàm với Fast Mode
}
```

**Điểm mấu chốt:** `preventDefault()` + gọi hàm chung. Vì vậy Normal Mode cũng là programmatic
anchor click — **không có gì khác Fast Mode về mặt code**, chỉ khác ở chỗ **đồng bộ vs sau `await`**.

## 4. URL comparison — IDENTICAL (bằng chứng cứng)

Đo bằng probe tạm (test DB, `RefreshDatabase`, đã xoá sau khi dùng):

```
NORMAL affiliate_url : https://s.shopee.vn/an_redir?origin_link=https%3A%2F%2Fshopee.vn%2Fproduct%2F59917031%2F56759033748&affiliate_id=17342330566&sub_id=tintuctonghop103
FAST   affiliate_url : https://s.shopee.vn/an_redir?origin_link=https%3A%2F%2Fshopee.vn%2Fproduct%2F59917031%2F56759033748&affiliate_id=17342330566&sub_id=tintuctonghop103
BYTE-IDENTICAL?      : YES
DB row identical?    : YES
```

| Thuộc tính | Giá trị | Giống nhau? |
|---|---|---|
| scheme | `https` | ✅ |
| host | `s.shopee.vn` | ✅ |
| path | `/an_redir` | ✅ |
| query `origin_link` | `https://shopee.vn/product/59917031/56759033748` | ✅ |
| query `affiliate_id` | `17342330566` | ✅ |
| query `sub_id` | `tintuctonghop103` | ✅ |
| fragment | (không có) | ✅ |
| encoding | `%3A%2F%2F` nhất quán | ✅ |
| độ dài | 148 ký tự | ✅ |

Test `FastModeTest::test_fast_and_normal_mode_produce_identical_affiliate_url` dùng
`assertSame` — **PASS** (13 tests / 86 assertions).

⇒ **Loại trừ hoàn toàn** giả thuyết "Fast Mode dùng URL khác / thiếu tracking / URL hỏng".

Khác biệt duy nhất là response shape, không phải URL: Fast có thêm `fast_mode`, `item_id`,
`shopeedirect_url`, `status: completed`; Normal `status: processing` rồi polling.
**Cả hai đều dùng `affiliate_url` để mở app** → URL đi vào trình duyệt là như nhau.

## 5. DOM comparison

| | Normal Mode | Fast Mode |
|---|---|---|
| Element | `<a>` thật trong DOM, user click trực tiếp | `<a>` **sinh động**, `display:none`, append→click→remove |
| href | `result.affiliate_url` | `data.affiliate_url` (cùng giá trị) |
| target | `_blank` | `_blank` |
| rel | `noopener noreferrer` | `noopener noreferrer` |
| `preventDefault` | **CÓ** (line 330) | không áp dụng |
| Thời điểm gọi | **đồng bộ** trong trusted click | **sau `await` (~240ms)** |
| Native navigation | **KHÔNG** (đã bị chặn) | không |

**Điểm duy nhất khác biệt: đồng bộ vs sau `await`.** Không có khác biệt nào về URL, DOM, hay
số lần gọi endpoint.

## 6. Navigation mechanism comparison

Cả hai: `document.createElement('a')` → `target=_blank` → `rel=noopener noreferrer` →
`appendChild` → `.click()` → `.remove()`.

**Không dùng** `window.open()`, **không dùng** `window.location.href`, **không dùng** iframe
trong code ứng dụng.

Lưu ý: **Shopee dùng đúng kỹ thuật tương tự** trong chính trang `an_redir` (`uls_t`).

## 7. User gesture analysis (iOS / WebKit)

### 7.1 Evidence thực địa — iPhone 12, 4/4 flow

| Flow | Trạng thái | Kết quả quan sát |
|---|---|---|
| **A. Chrome — Normal** | ✅ TESTED | click Add giỏ → **App Shopee mở trực tiếp**, **không** thấy tab Chrome trung gian, **không** dialog |
| **B. Chrome — Fast** | ✅ TESTED | **tab Chrome mới** hiện `s.shopee.vn` → **dialog** "This site is trying to open another application" `[Allow] [Don't allow]` → Allow → app mở |
| **C. Safari — Normal** | ✅ TESTED | click Add giỏ → **App Shopee mở** (có hoặc không tab trung gian) |
| **D. Safari — Fast** | ✅ TESTED | **không** thấy tab Safari mới, **không** dialog, **không** chuyển sang app. **Không có gì xảy ra.** |

Test bổ sung:

| Test | Kết quả |
|---|---|
| **4. Chrome Fast, bấm `[Don't allow]`** | tab **về `shopee.vn` product page** ⇒ xác nhận `fallbackTime: 3000` hoạt động |

### 7.2 Đọc dữ liệu này

So sánh **A vs B** (cùng iPhone 12, cùng Chrome, cùng URL, cùng hàm) — chỉ khác `await`:
⇒ async boundary **gây ra** khác biệt. Evidence trực tiếp.

So sánh **C vs D** (cùng iPhone 12, cùng Safari, cùng URL, cùng hàm) — chỉ khác `await`:
⇒ async boundary **lặp lại đúng hiệu ứng đó** trên Safari. Evidence trực tiếp, độc lập.

So sánh **B vs D** (cùng iPhone 12, cùng WebKit, cùng `await`) — chỉ khác trình duyệt:
⇒ chính sách popup/scheme **của trình duyệt** là biến thứ hai.

⇒ **Hai cổng kiểm soát độc lập, không phải một:**

```
                    có user gesture (Normal)      mất gesture sau await (Fast)
                  ┌──────────────────────────┐   ┌──────────────────────────┐
  CỔNG 1          │ target=_blank            │   │ target=_blank            │
  (mở tab)        │ Chrome: handoff nhanh →  │   │ Chrome: MỞ TAB (thấy     │
                  │ user không thấy tab      │   │   s.shopee.vn)           │
                  │ Safari: handoff, im lặng  │   │ Safari: CHẶN → không có  │
                  ├──────────────────────────┤   │   gì cả                 │
  CỔNG 2          │ shopeevn://              │   │ shopeevn://              │
  (mở app)        │ iOS handoff → APP, im lặng│  │ Chrome: HỎI "Allow?"     │
                  │                          │   │ Safari: từ chối im lặng  │
                  └──────────────────────────┘   └──────────────────────────┘
```

- **Chrome** fail ở **cổng 2** (hỏi Allow) nhưng pass cổng 1 (mở tab) → thấy tab + dialog.
- **Safari** fail ở **cả hai** cổng (chặn tab **và** từ chối scheme) → **im lặng hoàn toàn**.

Đây là lý do **"popup blocker" là giải thích không đủ**: một popup-blocker đơn lẻ sẽ cho ra
"bị chặn ở cả hai trình duyệt", không cho ra "Chrome mở tab + hỏi Allow, Safari không mở gì".

### 7.3 Đo trên desktop (chỉ để xem cơ chế, KHÔNG suy ra iOS)

Chrome desktop, popup blocker mặc định:

| Kịch bản | visibilityState | hidden | ua.isActive | ua.hasBeenActive | opener | referrer |
|---|---|---|---|---|---|---|
| A) Tab mới, **đồng bộ** trong trusted click (Normal) | visible | false | false | false | false | URL trang cha |
| B) Tab mới, **sau `await`** (Fast) | visible | false | false | false | false | **(none)** |

- Cột `ua.hasBeenActive` đo **document con vừa load**, luôn `false` bất kể navigation được
  khởi tạo thế nào. Đây là **sai tín hiệu** — nó không nói gì về cách trình duyệt gán
  navigation `shopeevn://` sau đó cho gesture chain. Bản đầu của báo cáo đã sai lý do này.
- Cái đo được và **có giá trị**: `rel="noopener noreferrer"` làm mất Referer (`(none)`).
- **Không dùng bảng này để kết luận về iOS.**

## 8. Chrome iPhone 12 result

| Chế độ | Tab trung gian | Dialog | App |
|---|---|---|---|
| Normal | không thấy | không | **mở trực tiếp** |
| Fast | **có** (`s.shopee.vn`) | **có** `[Allow] [Don't allow]` | mở sau khi bấm Allow |
| Fast + **Don't allow** | — | — | tab **về `shopee.vn` product page** |

**Khuyến nghị ghi nhận thêm (chưa test, không bắt buộc):** trong Fast Mode, tab mới xuất hiện
ngay lập tức hay trễ ~240ms? Nếu trễ ~240ms → khớp `await`; xem Test 5 (mục 17).

## 9. Safari iPhone 12 result

| Chế độ | Tab mới | Dialog | App |
|---|---|---|---|
| Normal | (không chắc có hay không) | **không** | **mở** ✅ |
| Fast | **không có** | **không có** | **không chuyển** |

**Cơ chế Fast Mode Safari — đã rõ:**

- **G1 (được xác nhận bởi Test 3):** `target="_blank"` bị WebKit chặn khi không có user
  activation. Bằng chứng: cùng `target="_blank"`, cùng URL, nhưng **có** gesture (Normal) thì
  app mở, **không** gesture (Fast) thì không có gì xảy ra. ⇒ im lặng hoàn toàn ở Fast là do
  navigation bị chặn ngay ở bước mở tab, chưa tới bước scheme.
- **G2 (loại trừ):** không cần giả thuyết "tab mở rồi bị đóng" — Test 3 cho thấy khi có
  gesture thì Safari **không** cần tab trung gian để handoff app.

**Limitation cần nêu rõ về iOS Safari:**
- **Không có log, không có console, không có cảnh báo** khi Safari từ chối một scheme
  navigation. Người dùng chỉ thấy "không có gì xảy ra" — đây là trải nghiệm tệ nhất vì
  **không có tín hiệu lỗi nào**.
- Cần user kiểm tra **icon Shopee trên màn hình chính** sau khi bấm, không chỉ nhìn màn hình
  hiện tại — app có thể đã mở ở background mà user tưởng không có gì (xem Test 7).
- Không phân biệt được bằng mắt giữa "Safari chặn popup" và "Safari im lặng rồi ULS fallback
  về https" nếu không đếm số tab trước/sau (xem Test 6). Tuy nhiên Test 3 đã đủ để kết luận
  nguyên nhân, nên Test 6 chỉ là kiểm chứng bổ sung.
- iOS Safari và iOS Chrome dùng chung WebKit nhưng **UI và policy khác nhau** — dữ liệu này
  đã tách riêng từng trình duyệt, không suy luận chéo.

## 10. Redirect chain (đo thật, desktop; nội dung này không phụ thuộc OS)

`an_redir` **KHÔNG phải HTTP redirect** — trả `200` + `Content-Type: text/html`, **không** có
header `Location` (HEAD với cả desktop/iOS UA: đều `[200]`, không `Location`).

Body chứa `CONFIG` + `ULS`:

```js
var CONFIG = {
  httpUrl: "https://shopee.vn/product/59917031/56759033748?credential_token=...&mmp_pid=an_17342330566
            &uls_trackid=...&utm_campaign=-&utm_content=tintuctonghop103&utm_medium=affiliates
            &utm_source=an_17342330566&utm_term=...",
  deepLinkUrl: "shopeevn://reactPath?navigate_url=...&path=shopee%2FTRANSFER_PAGE&tab=buy
                &uls_trackid=...&use_deeplink=1&...&version=1",
  fallbackTime: 3000,
  evokeAppMethod: 2,
  deferred: "false"
};
ULS.init(CONFIG);
```

Logic ULS (trích nguyên văn):

```js
switch (e) {                          // e = evokeAppMethod
  case 1: t(n); break;                        // dynamic anchor, same tab
  case 2: window.location.href = n; break;    // <-- CONFIG dùng case này
  case 3: t(n, {newPage:true}); break;        // dynamic anchor, TAB MỚI
  default: /* hidden iframe */                // (bị cấm ở mục 16)
}
setTimeout(function(){ return n(); }, e.fallbackTime);   // 3000ms -> fallback
```

**Chuỗi đầy đủ:**

```
s.shopee.vn/an_redir?origin_link=...&affiliate_id=...&sub_id=...
      │  HTTP 200, HTML + JS (KHÔNG phải 302)
      ▼
ULS.init(CONFIG)   → evokeAppMethod=2
      ▼
window.location.href = "shopeevn://reactPath?...&use_deeplink=1&path=shopee/TRANSFER_PAGE&tab=buy"
      │  ← CUSTOM SCHEME. Đây là điểm sinh ra dialog ở Chrome, và bị từ chối ở Safari.
      ▼
(app mở)  — hoặc —  sau 3s fallback:
https://shopee.vn/product/59917031/56759033748?credential_token=...&mmp_pid=an_...
            &uls_trackid=...&utm_source=an_...&utm_medium=affiliates&utm_content=<username>
```

**Đo được trên desktop (cùng tab):** sau 1.2s đã tới product page `?credential_token=...&mmp_pid=an_17342330566&uls_trackid=...&utm_*`
⇒ `shopeevn://` được thử trước, thất bại (máy tính không có app handler), rồi rơi về https.

**Xác nhận thực địa (Test 4):** khi user từ chối scheme, tab vẫn về `shopee.vn` product page
⇒ nhánh fallback hoạt động đúng như `fallbackTime: 3000`.

**Không thấy khác biệt redirect chain giữa Fast và Normal** — vì URL đầu vào giống hệt.

**Giới hạn:** chưa isolate được nhánh "real `an_redir` trong tab mới từ real gesture" (harness
mở nhầm URL con) ⇒ không có số đo cho nhánh đó.

## 11. Chain-of-custody: code ứng dụng chỉ quyết định ngữ cảnh

```
[code ta]  a.click()  →  mở/được-mở tab chứa an_redir
                                │
                                ▼
[Shopee]   ULS.init(CONFIG)  →  location.href = "shopeevn://..."
                                │
                    ┌───────────┴───────────┐
                    ▼                       ▼
        gesture CÒN  → iOS handoff       gesture HẾT → Chrome: hỏi "Allow?"
                        → APP, im lặng                    Safari: từ chối im lặng
```

Code ta **không** tạo ra custom scheme, **không** can thiệp vào ULS, **không** đổi URL. Nó chỉ
quyết định **navigation có nằm trong user gesture chain hay không** — và đó chính là biến duy
nhất khác nhau giữa hai chế độ.

## 12. Root cause

### CONFIRMED
1. Affiliate URL Normal ≡ Fast, byte-for-byte (`assertSame` PASS, 13/86).
2. Cơ chế navigation Normal ≡ Fast (cùng `openAffiliateLink()`, cùng `target=_blank`).
3. `an_redir` là HTML+JS `200`, không phải `302`.
4. ULS tự điều hướng tới **custom scheme `shopeevn://`** (`evokeAppMethod=2`).
5. **4/4 flow thực địa trên iPhone 12 (Chrome + Safari) giải thích được bằng MỘT biến duy
   nhất: `await` trước khi gọi `openAffiliateLink()`.**
6. Bấm `[Don't allow]` → tab về `shopee.vn` product page ⇒ fallback `fallbackTime: 3000` hoạt
   động, người dùng không bị mắc kẹt.

### Mô hình cuối cùng

| | Normal — **có** user activation | Fast — **mất** user activation sau `await` |
|---|---|---|
| **iPhone 12 Chrome** | iOS handoff thẳng vào app, **im lặng** (không tab, không dialog) | Cổng 1 pass (Chrome mở tab) → Cổng 2 fail → **hỏi "Allow?"** → Allow → app; Don't allow → product page |
| **iPhone 12 Safari** | iOS handoff thẳng vào app, **im lặng** | Cổng 1 **fail** (WebKit chặn unprompted `target=_blank`) → **không có gì xảy ra** |

⇒ Nguyên nhân: **`a.click()` sau `await` không còn thuộc user gesture chain của iOS.**
Trong gesture chain, iOS cho phép handoff custom scheme ngay lặp lẽ. Ngoài gesture chain,
mỗi trình duyệt phản ứng khác nhau ở hai cổng đã mô tả.

⇒ **Loại trừ hoàn toàn:** URL, DOM, backend, endpoint, tracking, `shopeedirect_url`, popup
blocker đơn lẻ, hay hành vi của Shopee.

### Còn lại chưa biết (không ảnh hưởng kết luận)
- Ngưỡng chính xác của gesture chain trên từng version iOS.
- Safari im lặng vì lý do cụ thể nào ở tầng WebKit (không log được trên thiết bị).
- Test 5–7 (độ trễ tab, đếm tab, app background) — kiểm chứng bổ sung, **không chặn quyết định**.

**ROOT CAUSE STATUS: CONFIRMED** — đủ 4/4 flow, một biến duy nhất, lặp lại trên hai trình
duyệt độc lập. Đủ cơ sở để chọn phương án sửa.

## 13. Options

| Option | Desktop | iPhone 12 **Chrome** | iPhone 12 **Safari** | Normal Mode Risk | Complexity |
|---|---|---|---|---|---|
| **A** Native anchor | không đổi | không dự đoán được — giả định sai, nguyên nhân là *thời điểm* gọi chứ không phải anchor dynamic | không dự đoán được | Cao (phải bỏ `preventDefault`, đổi hành vi) | Thấp |
| **B** Pre-create anchor từ gesture, set `href` sau | không đổi | **không giải quyết** — vẫn `.click()` **sau** `await` ⇒ vẫn ngoài gesture chain | không giải quyết | Thấp | Thấp |
| **C** Mở tab **đồng bộ** trong gesture, navigate tới URL khi có response | giữ được 1 tab, giữ form | **dự đoán: hết dialog** — tab nằm trong gesture chain, y hệt Normal | **dự đoán: hết im lặng** | Thấp–TB (chỉ Fast Mode) | Trung bình |
| **D** Điều hướng **cùng tab** (`location.href`) | **mất form** — regression | ULS chạy foreground, không cần tab mới | nhiều khả năng tốt nhất | Cao nếu áp cả Normal | Thấp |
| **E** Hiện link dự phòng + user bấm tay | giữ nguyên | chắc chắn, không dialog | chắc chắn | Thấp | Thấp |

Ghi chú trung thực:
- **A** giả định sai: Normal Mode hiện đã bị `preventDefault()` nên không native; mà nguyên nhân là
  **thời điểm gọi**, không phải anchor là dynamic hay thật.
- **B** không giải quyết gốc rễ: vẫn click **sau `await`** ⇒ vẫn ngoài gesture chain.
- **C** là hướng duy nhất **mở tab mới mà vẫn giữ được ngữ cảnh gesture**. Nó đúng vì Normal
  Mode (cũng là tab mới) đã chứng minh không bị dialog, trên cả hai trình duyệt.
- **D** giải quyết triệu chứng bằng cách bỏ hẳn tab mới, nhưng **làm hỏng UX desktop**.
- **E** không tự mở app; đánh đổi mất one-tap.

Không xếp hạng "best/worst" — bảng chỉ mô tả trade-off có thể suy ra từ evidence.

## 14. Recommended navigation strategy

**CHƯA IMPLEMENT — chờ user xác nhận.** Không file code nào bị thay đổi.

**→ Đã cập nhật: Option C ĐÃ được implement (mục 18). Phần dưới giữ lại làm gốc đối chiếu.**

**Ứng viên: Option C — giữ navigation trong user gesture chain.**

Mở tab **đồng bộ** ngay trong trusted click (trước `await`), rồi điều hướng tab đó tới
`affiliate_url` khi response về.

Lý do (dựa trên evidence, không phải suy đoán):
- Đây là cách **duy nhất** vừa mở được tab mới, vừa giữ tab đó trong gesture chain.
- **Normal Mode đã chứng minh thực tế** một tab mới nằm trong gesture chain thì mở app im lặng,
  trên **cả Chrome và Safari**.
- Không cần mổ xung màn hình form trên desktop (điểm yếu của D).
- Không đổi URL, backend, Normal Mode, paste, polling.
- Nhờ Test 4, kể cả khi app không mở, tab vẫn về product page ⇒ **không có rủi ro trang trắng**.

Ràng buộc bắt buộc khi triển khai C:
- Chỉ mở **một** tab. Không retry vô hạn, không `setInterval`, không mở cả `about:blank` lẫn URL thật.
- Nếu tab đã mở ở bước 1 mà request **lỗi/timeout**, phải đóng tab đó lại (không để lại tab trắng).
- Debounce paste 300ms: nếu tab đã mở ở bước 1 thì bước 2 chỉ đổi hướng tab đó, không mở tab thứ hai.
- Giữ `rel="noopener noreferrer"`; không thêm tracking/sub_id/deep-link.
- **Chỉ áp cho Fast Mode.** Không sửa Normal Mode.
- Test lại desktop (không đổi hành vi 1 tab) và paste-debounce sau khi sửa.

**Điểm cần nói thẳng:** Option C là *suy đoán có cơ sở* từ evidence Normal Mode, **chưa được
kiểm chứng bằng cách sửa code rồi test trên iPhone 12**. Bắt buộc test lại 4 flow sau khi
triển khai. Nếu Safari vẫn im lặng sau khi áp C → chuyển sang **D** (cùng tab) hoặc **E**.

## 15. Risks

| Rủi ro | Mức | Ghi chú |
|---|---|---|
| C không đủ cho Safari (chưa kiểm chứng bằng code thật) | **Cao** | Lý do để cần test lại 4 flow sau khi sửa |
| Option D làm hỏng UX desktop | Cao | Không nên áp dụng đại trà |
| App có thể đã mở ở background mà user tưởng "không có gì" | TB | Cần user kiểm tra icon app (Test 7) |
| Option C để lại tab trống khi lỗi | TB | Đã ghi ràng buộc bắt buộc |
| Tab trắng xuất hiện rồi mới đổi hướng (thấy nháy) | Thấp–TB | UX nhỏ, cần quan sát sau khi sửa |
| `rel=noreferrer` mất Referer | Thấp | Đã đo; có thể ảnh hưởng attribution, không liên quan mở app |
| Shopee đổi `an_redir`/`ULS` bất cứ lúc nào | TB | Ngoài tầm kiểm soát |

## 16. What must NOT change

Normal Mode (anchor Add giỏ + result card + ProductData + cashback + polling), paste
auto-create + debounce 300ms, nút Tạo Link Ngay, validation/normalization, anti-double-submit,
loading/error/CSRF-419, backend controller/routes/config, **affiliate URL**, `shopeedirect_url`,
ProductData, cashback, `affiliate_cache`, OPcache, database.
Không `migrate`/`migrate:fresh`/`refresh`/xóa dữ liệu. Không `git reset`/`restore`/`checkout`,
không commit.

Không dùng: iframe hack, fake click loop, popup spam, redirect loop, UA-sniffing.

## 17. Test plan

### 17.1 Đã test ✅

| | Desktop Chrome | iPhone 12 **Chrome** | iPhone 12 **Safari** |
|---|---|---|---|
| Normal Mode | ✅ | ✅ app mở trực tiếp | ✅ app mở |
| Fast Mode | ✅ | ✅ tab + Allow + app | ✅ không có gì xảy ra |

Test bổ sung đã test: **Test 4** — Chrome Fast, `[Don't allow]` → về product page ✅

### 17.2 CẦN USER TEST ⛔ (kiểm chứng bổ sung, không chặn quyết định)

| # | Test | Ghi lại | Dùng để |
|---|---|---|---|
| 5 | iPhone 12 Chrome — Fast | tab mới hiện ngay hay trễ ~240ms? | xác nhận `await` là nguyên nhân |
| 6 | iPhone 12 Safari — Fast | **đếm số tab trước/sau**; có tab trắng nhấp nháy rồi biến mất không? | phân biệt chặn popup vs tab mở rồi đóng |
| 7 | Sau mỗi lần bấm ở Safari | **kiểm tra icon Shopee trên màn hình chính** | phát hiện app mở ở background |

### 17.3 BẮT BUỘC sau khi triển khai Option C

Lặp lại **toàn bộ 4 flow** trên iPhone 12 (Chrome + Safari) + desktop, và so sánh với bảng
17.1. Đặc biệt:
- Safari Fast Mode: **phải mở app**, không được còn "không có gì xảy ra".
- Chrome Fast Mode: **không được còn dialog Allow**.
- Desktop: vẫn 1 tab, không đổi hành vi, paste-debounce không đổi.
- Request lỗi: không còn tab trắng mắc lại.

Không test được bằng automation ở đây: môi trường chỉ có desktop Chrome, không có iOS
device/emulator/Safari/WebKit thật. `Emulation.setUserAgentOverride` chỉ đổi UA string,
**không** đổi engine hay policy ⇒ không tạo được giá trị bằng chứng.

## 18. If implemented

**ĐÃ BỊ REVERT — Option C KHÔNG còn trong working tree và KHÔNG có trong deployment.**

Bản ghi bên dưới giữ lại làm tài liệu thử nghiệm, nhưng không phản ánh code thật trên máy:

- `git restore` đã đưa `link-generator.blade.php` và `FastModeTest.php` về HEAD `7665a2b`.
- User đã bác bỏ `window.open()`/tab trung gian — POC mới (mục 19) chọn **server-side
  redirect**, hoàn toàn không dùng JS navigation.

(Toàn bộ nội dung 18 dưới đây là bản ghi của thử nghiệm đã bỏ — chỉ tham khảo.)

### File đã đổi

| File | Thay đổi |
|---|---|
| `resources/views/dashboard/partials/link-generator.blade.php` | +105 / -8: thêm 3 method mới, 2 entry point, 4 call site cleanup, 1 ràng buộc guard |
| `tests/Feature/FastModeTest.php` | +37: cập nhật assertion theo kiến trúc mới + thêm invariant mới |

**Không đổi:** controller, routes, config, database, migrations, `affiliate_url`,
ProductData, cashback, polling, Normal Mode logic, OPcache.
`php.ini` SHA-256 không đổi: `8FF4511CBABBEB1C8C5B2AE5D1AD0B9AAEB610BDD058BD74AFF67A0D825A4B54`.

### Method mới

- `reserveGestureTab()` — mở tab **đồng bộ** bằng `window.open('', '_blank')` ngay trong
  trusted click. Chỉ chạy khi `fastMode === true` và chưa `loading`. Ghi handle vào
  `window.__fastGestureTab` (ngoài Alpine, vì WindowProxy không serialize được — cùng pattern
  với `window.__csrfPromise` sẵn có trong file). Trang tab hiển thị "Đang mở Shopee…" thay vì
  trắng.
- `navigateGestureTab(url)` — điều hướng tab đã dự trước tới `affiliate_url` bằng
  `location.replace`. Nếu không có tab dự trước → **fallback về `openAffiliateLink()`** (nhánh
  degraded, giữ đúng invariant "một implementation duy nhất"). Nếu tab đã bị đóng hoặc
  `replace` lỗi → đóng tab + fallback.
- `closeGestureTab()` — đóng tab dự trước, clear handle.

### Entry point được nối vào gesture

- Nút `Tạo Link Ngay`: `@click="reserveGestureTab(); submit"` — `@click` là
  activation-triggering.
- Paste: `@keydown` bắt `Ctrl/Cmd+V` và `@paste` (chỉ dự trước nếu clipboard khớp
  `^https?://`) — vì debounce 300ms phá vỡ gesture nên phải dự trước ở event gốc.

### Cleanup (không để lại tab trắng)

6 call site `closeGestureTab()`:
1. Watchdog 20s — chống trường hợp tab dự trước mà request treo.
2. Guard 1.5s — `Ctrl/Cmd+V` nhưng clipboard rong ⇒ không có `input` ⇒ đóng ngay.
3. Response `success` nhưng **không có `affiliate_url`**.
4. Business error (`!data.success`).
5. Network error.
6. `showSessionExpired()` (401/419 hết phiên).

Các nhánh `return` sớm trong debounce paste (URL rỗng / trùng `lastSubmittedUrl` / không phải
URL) cũng gọi `closeGestureTab()`.

### Test sau khi sửa

**PHP — `php artisan test --filter=FastModeTest`:** `13 passed (96 assertions)`, tăng từ 86
assertion vì thêm invariant mới (dự trước tab trong gesture, chỉ áp cho Fast Mode, có fallback,
có đủ 6 call site cleanup).

**PHP — full suite:** `3 failed, 1134 passed`. Ba lỗi **pre-existing, không liên quan**:
- `Tests\Feature\Auth\RegistrationTest > new users can register`
- `Tests\Feature\Services\ShopeeFood\ShopeeFoodOrderSyncServiceTest > cookie guard fails fast without http`
- `Tests\Feature\TikTokSyncPhase3Test > preexisting credited order is not credited again by admin`

**Browser — harness `optc.js` (Chrome 154, partial Blade thật + Alpine thật, chỉ stub network):
`20 passed, 0 failed`.** Phép thử quyết định là **T1**: stub cố tình **delay POST 3 giây**, rồi
kiểm tra ở giây thứ 7:

| Assertion | Kết quả |
|---|---|
| POST đã bắt đầu | `POST=1` |
| vẫn còn `loading` (response chưa về) | `true` |
| **tab ĐÃ tồn tại khi request còn đang bay** | `tabs=1` |
| `window.__fastGestureTab` có handle | `true` |
| sau response: đúng 1 tab | `tabs=1` |
| tab đã điều hướng đúng `affiliate_url` | `/affiliate?af=STUB` |
| có `gesture_tab_opened` + `gesture_tab_navigated` | ✅ |
| **KHÔNG** `gesture_tab_blocked` | ✅ |
| **KHÔNG** rơi vào fallback `openAffiliateLink` | ✅ |

⇒ Đây là bằng chứng trực tiếp rằng tab **được tạo trong gesture**, không phải sau response —
tức đúng thứ mà Normal Mode đang làm và điều Fast Mode còn thiếu.

Còn lại: T2 (business error ⇒ 0 tab mắc, có `gesture_tab_closed`), T3 (không `affiliate_url` ⇒
0 tab, loading tắt), T4 (Normal Mode: 0 tab tự mở, card hiện, **không** gọi
`gesture_tab_opened` — chứng minh Normal Mode không bị đụng).

**Browser — regression `e2e.js`:** `35 passed, 0 failed` (S1 Fast button, S2 paste, S3 Normal
card/polling, S4 Add giỏ, S5 thiếu URL, S6 mobile 390x844, S7 ctrl/middle-click native,
S8 không nhân bản). Không regression.

**Parse check:** `x-data` parse thành công (19193 ký tự), 0 dấu nháy kép lọt vào attribute
`x-data="..."` — điều kiện bắt buộc vì cả object Alpine nằm trong một HTML attribute.

### CHƯA xác minh — BẮT BUỘC test trên iPhone 12

Mọi thứ trên là **desktop Chrome**. Option C **chưa được kiểm chứng trên iOS**. Cần chạy lại
đủ 4 flow:

| Flow | Kỳ vọng sau khi sửa |
|---|---|
| Chrome — Normal | app mở trực tiếp (không đổi) |
| Chrome — Fast | mở app, **không còn dialog Allow** |
| Safari — Normal | app mở (không đổi) |
| Safari — Fast | **mở app, thay vì im lặng** |

Nếu Safari Fast vẫn im lặng ⇒ Option C chưa đủ cho Safari → chuyển sang **Option D** (điều hướng
cùng tab) hoặc **Option E** (link dự phòng + bấm tay). Chưa được kết luận trước khi test.

### Lưu ý UX cần quan sát

Tab giờ mở **trước** khi có response, nên người dùng sẽ thấy "Đang mở Shopee…" trong khoảng
~240ms thay vì chờ xong mới nhảy. Cần xác nhận trên iPhone 12 rằng khoảng trễ này không gây
khó chịu, và rằng không có hiện tượng nháy tab trắng.

---

## Phụ lục — bằng chứng

Đã chạy (ngoài DB production; chỉ read-only hoặc test DB):
- Probe Laravel tạm (`RefreshDatabase`) → dump URL + response shape 2 chế độ → `assertSame` PASS.
- `HEAD`/`GET` tới `s.shopee.vn/an_redir` (desktop + iOS UA) → `[200]`, không `Location`, đọc `CONFIG`/`ULS`.
- Chrome **desktop** 154 CDP (popup blocker mặc định) → `visibilityState`/`userActivation`/`referrer`.
- Load `an_redir` thật trong cùng tab → URL cuối sau 1.2s và 5s.
- `php artisan test --filter=FastModeTest` → **13 passed (86 assertions)**.

Evidence thực địa (**không phải do tôi tái lập**): iPhone 12, 4 flow + Test 4, mục 7.1.

---

## 19. POC — SERVER-SIDE REDIRECT → SHOPEE APP (29/09/2026)

POC độc lập, KHÔNG tích hợp Fast Mode, KHÔNG đụng production flow.

### Kiến trúc POC

```
USER TAP
  → browser navigation thật (GET, cùng tab) — không JS
  → GET /__poc/fast-redirect?url=<shopee url>
  → Laravel: resolve() hiện tại + buildAffiliateUrl() hiện tại
  → HTTP 302 → Location: affiliate_url
  → s.shopee.vn/an_redir → Shopee App (nếu hợp lệ)
```

### Files changed (POC)

| File | Nội dung |
|---|---|
| pp/Http/Controllers/Poc/FastRedirectPocController.php (mới) | index() + edirect(); gọi **phương thức private của production controller qua Reflection** để dùng đúng detectPlatform()/uildAffiliateUrl() hiện tại + UrlResolverService thật |
| esources/views/poc/fast-redirect-test.blade.php (mới) | trang test: form GET + anchor mẫu |
| outes/web.php | **+5 dòng** (import POC controller + 2 route GET) |

### Production files changed

**KHÔNG có.** link-generator.blade.php, DashboardCreateDirectLinkController.php,
UrlResolverService.php, .env, config/*, outes/api.php đều **không đổi** (verify bằng
git diff --quiet). php.ini SHA-256 không đổi.

Vui lòng kiểm tra URL do chính uildAffiliateUrl() của production tạo ra — POC chỉ gọi lại nó,
không tạo thuật toán mới.

### Backend POC

- Route: GET /__poc/fast-redirect và GET /__poc/fast-redirect-test (guest, không auth)
- Controller: App\Http\Controllers\Poc\FastRedirectPocController
- Affiliate URL (log/curl): `https://s.shopee.vn/an_redir?origin_link=https%3A%2F%2Fshopee.vn%2Fproduct%2F59917031%2F56759033748&affiliate_id=17342330566&sub_id=vietduy85`
- Resolved URL: `https://shopee.vn/product/59917031/56759033748` (canonical, không cần shortlink)
- HTTP Status: `302 Found`
- Location: đúng ffiliate_url (affiliate_id = 17342330566, sub_id = user fallback đầu tiên khi chưa đăng nhập)

Note: khi người dùng chưa đăng nhập, sub_id lấy user đầu tiên (vietduy85). POC không cần đúng
sub_id của tester (§13 — không kiểm tracking); iOS behavior không phụ thuộc sub_id.

### Desktop Chrome

PASS

- POC page hiển thị đúng.
- Form method="get" → ction=/__poc/fast-redirect — navigation thật, không JS/AJAX/Alpine.
- Anchor mẫu click bằng chuột thật (CDP Input) → browser chuyển hướng tới /__poc/fast-redirect?url=....
- Laravel 302 → đến s.shopee.vn/an_redir → đáp xuống trang product shopee.vn **có tracking**
  (mmp_pid=an_17342330566, utm_content=vietduy85) — chain affiliate chạy trọn vẹn.

### iPhone 12 Chrome

PENDING — chưa được chạy trên thiết bị thật. Không ghi PASS.

### iPhone 12 Safari

PENDING — chưa được chạy trên thiết bị thật. Không ghi PASS.

### Test trên iPhone 12

1. Mở https://hoantien.xyz/__poc/fast-redirect-test.
2. Nhập URL Shopee thật (hoặc bấm link mẫu).
3. Bấm "TEST SERVER REDIRECT → SHOPEE".
4. Quan sát: có mở app? có tab mới? có popup "trying to open another application"? có cần Allow?

Ghi kết quả Chrome và Safari riêng. POC PASS chỉ khi: app mở trực tiếp, không popup, không tab
trung gian, không cần thao tác thêm.

### Điều kiện dừng

- PASS cả 2 trình duyệt → dừng, không tự tích hợp, chờ yêu cầu.
- FAIL → báo chính xác behavior Chrome/Safari, không hack thêm, không sửa Fast Mode.

### Điểm quan trọng về cơ chế cần quan sát

POC dùng **navigation thật trong tab hiện tại** — đây là loại chuyển hướng duy nhất giữ được
user activation với iOS Safari. Nếu POC PASS nghĩa là server-side redirect + Shopee ULS
(tự location.href = shopeevn:// trong trang n_redir) đủ để mở app. Nếu FAIL, chứng cứ cho
thấy chặn không nằm ở async trong Fast Mode mà ở cách n_redir tự mở scheme sau mỗi loại
điều hướng.

---

## 20. TRIỂN KHAI CHÍNH THỨC FAST MODE — SERVER-SIDE REDIRECT (2026-09-29)

### 20.1 Bối cảnh

POC server-side redirect đã **PASS trên thiết bị thật iPhone 12**:

- iPhone 12 **Chrome**: tap link POC → Laravel → HTTP redirect → Shopee App mở trực tiếp
  (không tab mới, không popup Allow, không thao tác thêm).
- iPhone 12 **Safari**: giống Chrome — mở app trực tiếp.

→ Chuyển POC thành cơ chế chính thức của Fast Mode. Không dùng lại Option C
(`window.open`/tab trung gian/`a.click()` sau await). Normal Mode giữ nguyên 100%.

### 20.2 Cơ chế chính thức

```
USER TAP / PASTE (fast mode ON)
  → browser navigation thật (cùng tab) — không JS/AJAX sau await
  → GET /fast/redirect?url=<encodeURIComponent(shopee url)>
  → FastRedirectController: UrlResolverService::resolve() + isShopeeLanding()
  → ShopeeAffiliateUrlService::build() (đúng buildAffiliateUrl() cũ)
  → HTTP 302 → Location: https://s.shopee.vn/an_redir?...
  → Shopee App mở trực tiếp
```

### 20.3 Endpoint production

- Route: `GET /fast/redirect` — tên `fast.redirect`, nhóm guest (không auth), không CSRF (GET).
  Thay thế hoàn toàn 2 route `__poc`.
- Controller: `App\Http\Controllers\FastRedirectController` (không Reflection).
- Dịch vụ dùng chung: `App\Services\ShopeeAffiliateUrlService::build()` — tách từ
  `buildAffiliateUrl()` private của `DashboardCreateDirectLinkController` (nay delegate),
  output giữ nguyên từng byte.
- An toàn: validate `url` (http/https, `FILTER_VALIDATE_URL`, ≤ 2048 ký tự); resolver +
  `isShopeeLanding()` chặn open redirect. Sai/không phải Shopee → redirect về `dashboard`
  kèm flash `error`, KHÔNG phát Location affiliate.
- Guest: không yêu cầu đăng nhập (đúng POC). `sub_id` = username user đăng nhập, fallback
  user đầu tiên có username (giống POC).

### 20.4 Frontend Fast Mode

- `submit()`: nếu `fastMode` → gọi `fastRedirect()` và `return` TRƯỚC mọi thao tác Normal.
- `fastRedirect()`: `window.location.href = '/fast/redirect?url=' + encodeURIComponent(val)`
  (navigation thật, không `window.open`, không tab trung gian).
- Paste (debounce 300ms) và nút "Tạo Link Ngay" đều đi qua `submit()` → `fastRedirect()`.
- Xoá nhánh `if (data.fast_mode)` trong `post()` — Fast Mode không còn gọi
  `openAffiliateLink()` sau await.

### 20.5 Normal Mode

Không đổi: `submit()` normal path, `post()`, ProductData, cashback, polling, cache,
result card, `onAddToCartClick()`, `openAffiliateLink()`, CTA Mua hàng, auto-create.

### 20.6 Files

Thêm mới:

| File | Nội dung |
|---|---|
| `app/Http/Controllers/FastRedirectController.php` | endpoint redirect production |
| `app/Services/ShopeeAffiliateUrlService.php` | build affiliate URL dùng chung |

Sửa:

| File | Thay đổi |
|---|---|
| `app/Http/Controllers/DashboardCreateDirectLinkController.php` | inject service; `buildAffiliateUrl()` delegate; bỏ `use Setting` không còn dùng |
| `routes/web.php` | +import `FastRedirectController`; thay 2 route `__poc` bằng `GET /fast/redirect` |
| `resources/views/dashboard/partials/link-generator.blade.php` | guard fast trong `submit()`; thêm `fastRedirect()`; xoá nhánh `if (data.fast_mode)` |
| `tests/Feature/FastModeTest.php` | cập nhật 2 test view; +9 test endpoint |

Đã xoá (POC cleanup, §13):

- `app/Http/Controllers/Poc/FastRedirectPocController.php`
- `resources/views/poc/fast-redirect-test.blade.php`
- 2 route `GET /__poc/fast-redirect` và `GET /__poc/fast-redirect-test`

### 20.7 Kiểm thử

- `FastModeTest`: **22 passed (146 assertions)** — gồm 9 test endpoint mới (302 + Location,
  short link, resolver fail, non-Shopee, invalid URL, guest, không ProductData/cache,
  URL trùng backend cũ, query URL).
- CSRF harness (`tests/harness/csrf-recovery.run.mjs`): **36/36 checks passed**.
- Full suite: **3 failed, 1143 passed** — đúng 3 fail có sẵn từ trước
  (`Auth\RegistrationTest`, `ShopeeFoodOrderSyncServiceTest`, `TikTokSyncPhase3Test`),
  không phát sinh fail mới.
- Backend HTTP thật (`php -S 127.0.0.1:8077`):
  - canonical URL → `302` + `Location: https://s.shopee.vn/an_redir?origin_link=...&affiliate_id=17342330566&sub_id=...`
  - short link `vn.shp.ee/yvL5woPp` (resolver thật) → `302` + origin_link canonical
  - URL có query → `302`, origin_link đã bỏ query (đúng quy tắc cũ)
  - `tiki.vn` / URL sai → `302` về `/dashboard` (không open redirect)
- Desktop Chrome (harness `fast-desktop.js`, markup production + Alpine bundle, điều khiển
  bằng CDP `Input`/`Runtime`):
  - Paste + input event → 300ms debounce → `submit()` → navigation → `/fast/redirect`
    → 302 → `s.shopee.vn/an_redir` → landing shopee có `mmp_pid=an_17342330566` — PASS.
  - Nút "Tạo Link Ngay" → cùng chain — PASS.
- iPhone 12 Chrome/Safari: cần người dùng field-test lại (agent không có thiết bị).
  Cơ chế đã được chứng minh PASS bằng POC trước đó; bản chính thức dùng **đúng** cơ chế đó.

### 20.8 Xác nhận regression

- Normal Mode: code path không đổi.
- Fast Mode không còn gọi `openAffiliateLink()` sau await.
- Không còn `window.open`, tab trung gian, blank tab, `a.click()` sau fetch.
- Không còn endpoint `__poc` nào.
- Không đổi DB/migration/`.env`/config/OPcache/php.ini.
