# Known Issues & Limitations

## Resolved Issues

### "Đang xử lý" Stuck Forever

- **Status:** Fixed (see `troubleshooting.md` for details)
- **Fix:** Wrapped `res.json()` in try-catch and added top-level safety net in `background.js`
- **Commit:** Latest edit to `background.js`

## Active Issues

### Shopee CAPTCHA Detection

- All automated approaches (GraphQL, CDP, Playwright) are blocked by Shopee's CAPTCHA
- Chrome extension is the only working approach because it runs as a real browser extension
- The extension may still trigger CAPTCHA if behavior patterns are detected
- **Mitigation:** Anti-bot delays (1500-3200ms random), real user interaction patterns

### Content Script Untested End-to-End

- The content.js script has been designed but NOT yet fully tested with the current Shopee Custom Link page layout
- The React form structure may have changed since the last login session (~2026-06-23)
- **Mitigation:** DOM-based wait functions with selectors that target generic patterns (not specific IDs)

### Stale Shopee Session

- The last known Shopee affiliate login was around 2026-06-23
- Session cookies in `storage/shopee-state.json` may be expired
- Affiliate account shows payment setup not completed
- **Mitigation:** User must log in manually to `affiliate.shopee.vn` with the extension loaded

### No Batch Size Limit Enforcement on API

- Extension API returns up to 5 jobs but the backend does not enforce a limit
- If `AffiliateJobController@jobs` returns more than 5, the extension only processes the first 5
- **Impact:** Low — the controller is designed to return only `pending` jobs
- **Fix:** Add `->limit(5)` to the query in `AffiliateJobController`

### Race Condition: afterResponse Extension Jobs

- When `afterResponse()` runs (AddLiveTag + CashbackCalculator), it updates `LinkRequest` product fields
- The extension may poll and pick up the job BEFORE `afterResponse()` completes
- The extension reads `original_url` and `item_id` — these are set synchronously before `afterResponse()`
- **Impact:** Low — no field overlap between Worker (reads: id, original_url, item_id) and afterResponse (writes: product fields, cashback)

## Unresolved Questions

### GraphQL Empty Response (Shopee Block)

- **Unknown:** Why does Shopee return HTTP 200 with empty body for GraphQL API calls?
- **Hypothesis:** Some form of rate limiting, bot detection, or request signature validation
- **Impact:** All non-extension approaches are impossible

### Extension Tab Discovery Reliability

- **Unknown:** Will `chrome.tabs.query({ url: 'https://affiliate.shopee.vn/*' })` reliably find the correct tab?
- **Risk:** Multiple tabs matching the pattern, or Tab URL not matching due to subdomain variations
- **Mitigation:** Only first match is used; cache invalidation on failure

### Shopee UI Stability

- **Unknown:** How stable is the Shopee Custom Link page DOM structure?
- **Risk:** React component reorganization could break selectors (`textarea.ant-input`, button with "Lấy link" text)
- **Mitigation:** Generic selectors and DOM-based wait functions with timeouts

## Deprecated Components

### Playwright/CDP (Full Directory)

All files under `affiliate-worker/playwright/` and `affiliate-worker/tests/` are deprecated:
- Direct GraphQL calls: empty response
- CDP-based automation: CAPTCHA redirect
- These files remain for reference only and should not be used

### Node.js Express Server

`affiliate-worker/server.js` is no longer needed for link creation but can run for diagnostics.

## Future Considerations

1. **Multiple Shopee tabs:** Current logic uses first match only
2. **Token rotation:** No mechanism to rotate the shared extension token
3. **Error reporting:** Extension errors are only visible in DevTools console
4. **Extension updates:** No auto-update mechanism for unpacked extensions
5. **Rate limiting:** No protection against rapid submissions from dashboard
