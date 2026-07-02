# Content Script (`content.js`)

Runs inside `affiliate.shopee.vn` to automate Custom Link creation.

## Entry Point

```javascript
chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  if (msg.action !== 'process') return;
  // ... process URLs
  return true; // keeps sendResponse channel open for async
});
```

The listener returns `true` to indicate async response (required for MV3 service worker).

## Processing Flow

```
receiveMessage({ action: "process", urls: [jobs...] })
  │
  ├─► processAll(urls, onProgress)
  │      │
  │      ├─► chunk(urls, 5) — split into batches of 5
  │      │
  │      └─► for each batch:
  │             ├─► processBatch(batchUrls)
  │             │      ├─► closeModals() — close any open modals
  │             │      ├─► waitForMainTextarea() — find React textarea
  │             │      ├─► setReactValue(ta, urls.join('\n')) — fill form
  │             │      ├─► waitForButtonReady() — wait for "Lấy link" button
  │             │      ├─► btn.click() — submit
  │             │      ├─► waitForResult() — wait for modal with results
  │             │      ├─► parse links from modal textarea
  │             │      ├─► closeModals()
  │             │      └─► waitForModalGone() — ensure modal closed
  │             │
  │             └─► sleep(random 1500-3200ms) — anti-bot delay
  │
  └─► sendResponse({ ok: true, results: [...] })
       or
       sendResponse({ ok: false, error: "..." })
```

## Key Functions

### `setReactValue(el, value)`

Shopee's Custom Link page uses React with `antd`. Standard `el.value = x` won't notify React's synthetic event system. This function:
1. Gets the native `value` property setter from `HTMLTextAreaElement.prototype`
2. Calls it directly on the element (bypassing React's wrapper)
3. Dispatches an `input` event with `bubbles: true` for React to detect

```javascript
const setReactValue = (el, value) => {
  const setter = Object.getOwnPropertyDescriptor(
    window.HTMLTextAreaElement.prototype, 'value'
  ).set;
  setter.call(el, value);
  el.dispatchEvent(new Event('input', { bubbles: true }));
};
```

### `waitForMainTextarea(timeout = 5000)`

Finds the first `<textarea class="ant-input">` that is NOT inside a modal (to distinguish the main form from the result modal). Polls every 50ms.

### `waitForButtonReady(timeout = 3000)`

Finds button with innerText containing "Lấy link". Checks both:
- `btn.disabled` (native HTML property)
- `ant-btn-disabled` CSS class (Ant Design convention)

### `waitForResult(timeout = 18000)`

Waits for modal textarea to have a non-empty `.value`. The Shopee API typically responds in 3-8s.

### `waitForModalGone(timeout = 3000)`

After closing modals, waits until no `.ant-modal` exists in DOM.

### `closeModals()`

Clicks all `.ant-modal-close` buttons found in DOM.

## Error Handling

| Error | Cause | Effect |
|-------|-------|--------|
| `CAPTCHA` | Page redirected to `shopee.vn/verify/captcha` | Current batch fails, all URLs marked failed |
| `NO_FORM` | Main textarea not found within 5s | Batch fails |
| `NO_BUTTON` | "Lấy link" button not found within 3s | Batch fails |
| `TIMEOUT` | Modal result not generated within 18s | Batch fails |
| sendMessage error | Tab not found / content script not loaded | Background marks all jobs failed, invalidates tab cache |

## Configuration Constants

| Constant | Value | Purpose |
|----------|-------|---------|
| `BATCH_SIZE` | 5 | URLs per batch |
| `MIN_DELAY` | 1500ms | Min anti-bot delay between batches |
| `MAX_DELAY` | 3200ms | Max anti-bot delay between batches |
| `RESULT_TIMEOUT` | 18000ms | Max wait for Shopee to generate links |
| Wait poll interval | 50ms | For all wait functions (was 400ms) |

## Important Notes

- The content script re-enters the `__shopeeBulkLinkLoaded` guard on each injection (but is only injected once per page load by manifest)
- `msg.urls` is an array of job objects: `[{ id, original_url, item_id }, ...]`
- The `original_url` field is extracted via: `(j) => j.original_url ?? j.url ?? j`
- Results map back to jobs by array index (not by id)
- The result textarea contains one short link per line, parallel to input URLs
