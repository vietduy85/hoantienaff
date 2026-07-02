# Browser Extension (MV3) Architecture

## Overview

The Chrome extension (`affiliate-worker/browser-extension/`) is the active solution for creating Shopee affiliate short links. It runs as a real browser extension, bypassing Shopee's CAPTCHA detection that blocks CDP/Playwright approaches.

## Files

| File | Role |
|------|------|
| `manifest.json` | MV3 manifest: permissions, host_permissions, background service worker, content scripts |
| `background.js` | Service worker: polls API, manages tab cache, forwards jobs to content script |
| `content.js` | Content script: injected into `affiliate.shopee.vn`, interacts with React form |
| `popup.html` | Popup UI: API URL, token, status display |
| `popup.js` | Popup logic: save settings, check tab status |

## Background Service Worker (`background.js`)

### Lifecycle

- **On install:** Set default `apiUrl` (`http://hoantien.xyz`) and `token` if not exist; start polling
- **On startup:** Start polling
- **On message:** Respond to `getStatus` (used by popup.js)

### Polling Logic (`poll()`)

1. Read `apiUrl`, `token`, `enabled` from `chrome.storage.sync`
2. If disabled or no apiUrl → sleep 3s and retry
3. `fetch(GET /api/extension/jobs?token=...)`
4. Try-catch around `res.json()` — invalid JSON logs error and retries with SLEEP_ERROR (5s)
5. If no jobs → sleep 3s and retry
6. Find Shopee tab via `getAffiliateTab()` (uses cached tabId or queries)
7. `chrome.tabs.sendMessage(target.id, { action: "process", urls: jobs })`
8. If sendMessage fails → invalidate tab cache, continue (all jobs marked failed)
9. Collect results; if sendMessage returns `{ ok: true, results: [...] }`, use those; else mark all failed
10. `fetch(POST /api/extension/results?token=...)` with results
11. Top-level try-catch catches any unexpected error, logs it, and continues polling

### Tab Caching (`getAffiliateTab()`)

- **cachedTabId** stored in RAM (lost on service worker restart)
- **First call:** `chrome.tabs.query({ url: 'https://affiliate.shopee.vn/*' })` → cache result
- **Subsequent calls:** `chrome.tabs.get(cachedTabId)` → validate URL matches
- **Invalidation triggers:** tabs.get error, URL mismatch, sendMessage failure
- **No event listeners** — cache validated lazily on each poll

### Poll Timing

| Constant | Value | When Used |
|----------|-------|-----------|
| SLEEP_EMPTY | 3000ms | No jobs, disabled, or tab not found |
| SLEEP_ERROR | 5000ms | Network error or invalid JSON |
| SLEEP_DONE | 1000ms | After successfully posting results |

## Content Script (`content.js`)

### Injection

- **Match pattern:** `*://affiliate.shopee.vn/*`
- **Run at:** document_idle (after page load)
- **Self-guard:** `window.__shopeeBulkLinkLoaded` prevents double injection

### URL Processing

- **Batch size:** 5 URLs per batch
- **Anti-bot delay:** 1500-3200ms random between batches
- **Result timeout:** 18s per batch

### DOM Interaction (React Form)

Shopee's Custom Link page uses React with `antd` components. Direct `element.value = ...` won't work because React doesn't detect native property changes. Solution:

```javascript
const setReactValue = (el, value) => {
  const setter = Object.getOwnPropertyDescriptor(
    window.HTMLTextAreaElement.prototype,
    'value'
  ).set;
  setter.call(el, value);
  el.dispatchEvent(new Event('input', { bubbles: true }));
};
```

### Wait Functions (DOM-based, no fixed sleeps)

| Function | Condition | Timeout | Poll Interval |
|----------|-----------|---------|---------------|
| `waitForMainTextarea` | First textarea not in modal | 5s | 50ms |
| `waitForButtonReady` | Button with "Lấy link" text, not disabled | 3s | 50ms |
| `waitForResult` | Modal textarea with non-empty value | 18s | 50ms |
| `waitForModalGone` | No `.ant-modal` in DOM | 3s | 50ms |

All throw on CAPTCHA detection (`location.href.includes('verify/captcha')`).

### CAPTCHA Detection

Checked during every wait loop:
```javascript
if (isCaptcha()) throw new Error('CAPTCHA');
```

When CAPTCHA is detected, the batch fails and all URLs in that batch are reported as failed. The extension continues with the next poll cycle.

## Popup (`popup.html` + `popup.js`)

- Shows connection status to API
- Displays whether Custom Link tab is open
- Configurable API URL and token
- Save configuration to `chrome.storage.sync`

## Manifest Permissions

```json
{
  "manifest_version": 3,
  "permissions": ["storage"],
  "host_permissions": [
    "https://affiliate.shopee.vn/*",
    "https://hoantien.xyz/*",
    "http://localhost/*"
  ]
}
```

- **No `tabs` permission** needed — `chrome.tabs.query` and `chrome.tabs.get` work due to host_permissions matching
- **No `scripting` permission** — content script declared in manifest
- **No `alarms` permission** — uses `setTimeout` chaining instead

## Debugging

- Background worker logs prefix: `[BG]`, `[Worker]`
- Content script logs prefix: none (console.log directly)
- Open `chrome://extensions/` → service worker → inspect to see background logs
- Open DevTools on `affiliate.shopee.vn` to see content script logs
