# Glossary

| Term | Definition |
|------|------------|
| **AddLiveTag** | External API (`data.addlivetag.com/product-data/product-data.php`) that provides product data (price, commission, rating) for Shopee URLs |
| **Affiliate Cache** | Daily cache table (`affiliate_cache`) keyed by `(item_id, cache_date)` to avoid redundant AddLiveTag API calls |
| **Affiliate Link** | A special URL that tracks referrals. When a user clicks and purchases, the affiliate earns commission |
| **afterResponse()** | Laravel's deferred execution — runs a callback after the HTTP response has been sent to the client. Used to run AddLiveTag + CashbackCalculator without blocking the response |
| **CAPTCHA** | Shopee's bot detection. When triggered, the page redirects to `shopee.vn/verify/captcha?scene=crawler_item` |
| **Cashback** | A portion of the affiliate commission returned to the user who made the purchase |
| **CDP** | Chrome DevTools Protocol — used by Playwright to control Chrome programmatically. Blocked by Shopee |
| **Content Script** | JavaScript file injected into a web page by a browser extension. In this project, runs on `affiliate.shopee.vn` |
| **Custom Link** | Shopee's term for a short affiliate link created via `affiliate.shopee.vn/offer/custom_link` |
| **Đang xử lý** | Vietnamese for "Processing" — the dashboard status shown while the extension is working on a link |
| **Exponential Backoff** | Retry strategy where delay doubles after each consecutive failure, capped at a maximum |
| **GraphQL** | Shopee's API protocol at `affiliate.shopee.vn/api/v3/gql?q=batchCustomLink` — returns empty responses to automated requests |
| **Item ID** | Shopee's unique numeric product identifier (e.g., `123456789`) |
| **MV3** | Manifest V3 — the latest Chrome extension manifest format (uses service workers instead of background pages) |
| **Platform** | The e-commerce site (Shopee, Lazada, TikTok Shop, Tiki, etc.) |
| **Playwright** | Browser automation library. Used in the (deprecated) CDP approach |
| **Provider** | A class implementing `AffiliateProviderInterface` that handles affiliate link generation for a specific platform |
| **Service Worker** | Background script for MV3 extensions — runs in the background, handles polling and messaging |
| **setReactValue()** | Custom function that bypasses React's synthetic event system to set textarea values in Shopee's React form |
| **Shop ID** | Shopee's unique numeric shop identifier |
| **Stealth Plugin** | `puppeteer-extra-plugin-stealth` — attempts to hide automation from bot detection. Used in deprecated Playwright approach |
| **Token** | Shared secret between Laravel and the extension for API authentication (query parameter `?token=...`) |

## Vietnamese Terms Used in UI

| Term | English | Context |
|------|---------|---------|
| Đang xử lý | Processing | Status shown while link is being generated |
| Hoàn tiền | Cashback | The cashback amount user receives |
| Lấy link | Get link | Button on Shopee's Custom Link page |
| Link hoàn tiền | Cashback link | The generated affiliate link |
| Sao chép | Copy | Copy-to-clipboard button |
| Mua ngay | Buy now | Direct purchase button |
| Tạo Link Ngay | Create link now | Submit button on dashboard |
| Ghim | Pin | Pin/unpin a link |
| Lô | Batch | Batch of URLs being processed |
