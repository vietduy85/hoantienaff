# PROJECT KNOWLEDGE BASE – HoanTienAff (hoantien.xyz)

> Tổng hợp từ 84 file Markdown (không đọc lại nguyên văn). Cập nhật theo tài liệu đến 2026-10-14. Khi tài liệu mâu thuẫn: tin `docs/CODEMAP.md` (HEAD d5e6518, 2026-09-26) và các *Final Report* hơn là `docs - Copy/`, README/PROJECT_CONTEXT, hay Review đề xuất.

## 1. Tổng quan dự án
- Laravel 12 / PHP ^8.2 / MySQL (`hoantienaff`; test dùng SQLite `:memory:`). Locale `vi`, timezone `Asia/Ho_Chi_Minh`.
- Mục tiêu: user dán link sản phẩm → hệ thống tạo link affiliate + ước tính cashback → user mua → đơn được đồng bộ → cộng cashback vào ví → rút tiền.
- Module chính: Affiliate link (Shopee, ShopeeFood, TikTok, Lazada, …), Cashback/Wallet/Withdraw, Referral, So sánh giá (`/so-sanh-gia`), Tin khuyến mãi (`/tin-tuc-khuyen-mai`), **Thẻ tín dụng** (`/thetindung`, DB riêng), Admin (tài chính, rút tiền, sync TikTok, system policy thẻ).
- Production hiện tại: 1 PC Windows + XAMPP (Apache) là host DUY NHẤT; Cloudflare proxy + Tunnel (cloudflared), SSL Flexible. Có thêm host `aff.hoantien.xyz` (LiteSpeed, hosting) – schema DB ở đó có nguy cơ lệch.
- UI: mobile-first (iPhone 12 / 390px), Be Vietnam Pro, Tailwind; Emerald=primary/cashback, Amber=pending, Blue=info/paid, Red=lỗi; button `h-12 rounded-xl`, card `rounded-2xl`. Cấm `tracking-wide*`, `leading-snug/loose`, `text-[..]`. Thay đổi UI phải cập nhật `docs/ui/design-system.md` trước.

## 2. Kiến trúc hệ thống
- **Luồng Shopee (Extension)**: Dashboard (Alpine.js) → `POST /link-requests` → LinkRequest → Chrome Extension MV3 poll `GET /api/extension/jobs?token=` → content script thao tác `affiliate.shopee.vn/offer/custom_link` → `POST /api/extension/results?token=` → `completed`. (README/PROJECT_CONTEXT ghi `/api/affiliate/jobs|result` = SAI.)
- **Lịch sử quyết định**: GraphQL trực tiếp = FAILED (200 body rỗng); Playwright/CDP = BLOCKED (CAPTCHA, bỏ 2026-06-26); Extension MV3 = ACTIVE. `affiliate-worker/server.js` (Express :3001), `playwright/cdp/*`, `.bat` = deprecated.
- **Strategy tạo link Shopee**: `AffiliateLinkService` + `DirectLinkStrategy` / `ExtensionStrategy`, chọn qua `Setting` `affiliate.dashboard.strategy` (mặc định direct) / `affiliate.admin.strategy` (default extension). Direct dựng: `https://s.shopee.vn/an_redir?origin_link={url sạch bỏ query}&affiliate_id=17342330566&sub_id={username}`; sau resolver phải `isShopeeLanding()` (chống SSRF/open redirect).
- **Provider pattern**: `ProviderFactory`, `AffiliateProviderInterface`, enum `Platform` (SHOPEE LAZADA TIKTOK LONG_CHAU PHARMACITY TRAVELOKA AGODA BOOKING OTHER); tích hợp thật: TikTok (RioHub `riohub.vn/api/v1`), Lazada (API HMAC), ShopeeFood (partner API + cookie). DI tag: `affiliate-providers`(8), `catalog-providers`(4), `affiliate-search-link-providers`(3), `promotion-news-providers`(4). Detect platform bị trùng giữa DashboardController và `ProviderFactory::detectPlatform` (TODO refactor).
- **afterResponse**: `dispatch(closure)->afterResponse()` chạy ProductDataService + CashbackCalculator + update LinkRequest + cache sau flush (response ~50ms thay vì ~730ms). Chạy SYNC trong `Kernel::terminate` (không vào bảng `jobs`, không nhả worker – chỉ ẩn khỏi TTFB). Không có `routes/api.php`, Kernel, `app/Jobs`; `QUEUE_CONNECTION=database` trái với thực tế không có queue worker.
- **bootstrap/app.php**: `Env::disablePutenv()` (fix thiếu APP_KEY 2026-09-03), `trustProxies(at:'*')`, CSRF except `api/*`, middleware `CaptureReferral`, alias `role/permission/role_or_permission/forensic`, forceScheme https khi không local, renderer riêng cho 419 (CsrfForensic) và MissingAppKey.
- **Module Thẻ tín dụng**: DB riêng `hoantien_creditcard`, connection `creditcard` (fallback `DB_CREDITCARD_*`→`DB_*`); migration ở `database/migrations/creditcard/` chạy bằng `php artisan credit-card:migrate [--seed|--fresh|--pretend]` / `credit-card:migrate:status`. Model `App\Models\CreditCard\*` extends `CreditCardModel` (ghim connection). Namespace service `App\Services\CreditCard\*`; **không nhầm** `app/Services/CashbackCalculator.php` (affiliate) với `app/Services/CreditCard/CashbackCalculator.php`.
- Auth: Breeze + Google OAuth (Socialite); spatie roles Admin, Operator, Merchant, Affiliate, Member; permission `credit-cards.view/manage`, `users.*`, `withdrawals.*`, `settings.manage`…

## 3. Danh sách module / thành phần
| Module | Thành phần chính |
|---|---|
| Link affiliate | DashboardController, DashboardCreateDirectLinkController, DashboardCreateExtensionLinkController, UrlResolverService, AffiliateCacheService, ProductDataService, ShopeeAffiliateUrlService, FastRedirectController |
| Extension | `affiliate-worker/browser-extension/` (manifest, background.js, content.js, popup) |
| Nền tảng khác | TikTok/RioHub, Lazada, ShopeeFood, AffiliateSearchLinks, PromotionNews |
| Giá | PriceComparison (coop_online, bach_hoa_xanh, kingfoodmart, winmart) |
| Đơn & ví | ShopeeCsvParser, TikTokOrderSyncService, `affiliate_order_items`, WalletService, FinanceService, BankExportService, ReferralService |
| Thẻ tín dụng | CashbackCalculator, TierResolverService, PolicyService, PolicyCloneService, CardPolicyLookupService, CategoryRuleService, CategoryComboService, SystemCategoryService, CardRecommendationService, CashbackQuotaService, SpendQualificationService, StatementPeriodService, Import Excel, MCC (PolicyMccProfileService, importers) |
- Artisan: `affiliate:sync-all`, `affiliate:tiktok-sync [--sync]`, `affiliate:lazada-finalize`, `affiliate:import-shopee` (CSV `AffiliateCommissionReport_*.csv`), `benchmark:affiliate`, `wallet:bootstrap-ledger`, `users:generate-username`, `credit-card:backup`, `credit-card:import-mcc`, `credit-card:import-mcc-row-benefits`, `credit-card:normalise-rule-order [--all]`, `credit-card:prune-orphans`(đề xuất).
- **Portfolio Optimizer**: KHÔNG có tài liệu `.md` riêng. Chỉ thấy `CardRecommendationService` được liệt kê và nhắc "không động workstream MCC/Optimizer/Backup-OrderSync" (xem §13).

## 4. Quy tắc nghiệp vụ quan trọng
**Người dùng (guides)**: cashback thông thường 1–30%; hoàn tiền 1–30 ngày; rút tối thiểu **50.000đ**, ngân hàng 1–3 ngày làm việc, Momo 24h, miễn phí. KHÔNG hoàn: đơn hủy/trả, mua không qua link, lạm dụng nhiều tài khoản, đơn không hợp lệ, vi phạm điều khoản. Hỗ trợ support@hoantien.xyz.

**Link/cache**
- `affiliate_cache` PK ghép `(item_id, cache_date)`; `cache_date` = ngày VN → hết ngày là MISS (MISS 1 lần/item/ngày). `extractItemId`: `item_id`, `itemId`, `/product/{shop}/{item}`, `/opaanlp/{shop}/{item}`, `-i.{shop}.{item}`. HIT có affiliate_url → `completed` ngay; không có → `pending` cho extension.
- Short domain Shopee: `s.shopee.vn`, `vn.shp.ee`, `s.shp.ee`, `shope.ee`. Resolver: curl timeout 2s/connect 1s (code cũ ghi 6s/4s ở audit), max redirect 10, retry ≤3 (0/300/500ms) cho lỗi mạng, không retry 400/401/403/404/405/410/414/451; 500/502/503/504 retry 1 lần; fail → dùng URL gốc. Cache resolver key `shopee_resolve:md5(full-url)` TTL 86400 (query khác = MISS).
- ShopeeFood: host `shopeefood.vn`, `spf.shopee.vn`, `vnnow-food`; `restaurant_id` số 3–12 chữ số (không đoán); link `shopeefood.shopee.vn/now-food/shop/{id}?…utm_source=an_{affId}&utm_medium=affiliate_food&utm_content={sub_id}`.
- Pin tối đa 5 link/user. Polling dashboard: 300ms (0–3s) → 800ms (3–8s) → 2000ms; backoff mũ tối đa 5000ms.
- **Fast Mode (hiện hành, 2026-09-29)**: chỉ Shopee; `GET /fast/redirect?url=` (guest, không CSRF) → resolver + `isShopeeLanding()` → `ShopeeAffiliateUrlService::build()` → 302 sang `an_redir`; frontend dùng `window.location.href` (không `a.click()` sau `await` vì mất user activation trên iOS). Link Normal ≡ Fast từng byte. URL ≤2048; sai/không phải Shopee → redirect `/dashboard` + flash. Không cache/ProductData/cashback.

**Product Data API (AddLiveTag)**: `https://data.addlivetag.com/product-data/product-data.php`, tham số `item_id` hoặc `url`; cache 24h; dataSource `api|db|fallback`; rate limit 300 req/phút (lấy Shopee API), 2000 req/phút (DB). Commission: seller không cap; Shopee cap **50.000đ** và tối đa **4,5% giá**; `commission = sellerComFinal + shopeeComFinal`. ProductDataService nội bộ: timeout 10s, retry 2×500ms (đề xuất hạ 4s/1 retry).

**Provider giá**: WinMart (`api-crownx.winmart.vn`, store 1998/1535, search TTL 300s, product TTL 600s, sellable = publish && stock>0). Lỗi provider được cô lập (log + trả rỗng).

## 5. Quy trình đồng bộ Affiliate
- Điểm vào DUY NHẤT: Windows Task Scheduler `WindowScheduleSync` → `C:\xampp\php\php.exe artisan affiliate:sync-all` **mỗi 3 giờ** (00/03/…/21h). Thứ tự: [1] TikTok (`creditWallet:true`) → [2] Lazada Sync → [3] Lazada Finalize (chỉ khi [2] OK) → [4] ShopeeFood → [5] Shopee/AddLiveTag API. Task `HoanTien-TikTok-Sync` DISABLED từ 2026-10-14 (giữ để rollback).
- Laravel scheduler KHÔNG dùng (`schedule:list` rỗng là bình thường). Exit 0 = OK; ≠0 khi lỗi API/auth/config hoặc lock bị giữ. Admin/Operator có menu "Đồng bộ đơn hàng TikTok" dùng cùng service + cùng lock; `sync_type`: `scheduled_windows|manual_admin|manual_operator`. Chạy tay `--sync` = side effect thật (RioHub, DB, ví) → cần xác nhận chủ dự án. Không log key/token.
- `affiliate_order_items` (59 cột: 47 Shopee + 12 hệ thống): UNIQUE `uk_order_item(order_id,item_id)`; 8 index. Map user: `sub_id1` = `users.username`; không khớp → `user_id/username` NULL nhưng VẪN import, giữ sub_id1, KHÔNG xoá. Vòng đời: lần 1 set `first_imported_at`; mỗi import cập nhật `last_*_sync_at`; hoàn thành → `locked_at=now`; importer bỏ qua record locked. `commission_type`: `Shopee Comm|XTRA Comm`. `content_id` là RioHub content_id (không phải checkout id).
- Cookie `SHOPEEFOOD_COOKIE` có thể hết hạn âm thầm.

## 6. Quy trình tính hoa hồng và Cashback (affiliate)
- **Ước tính tại link** (`CashbackCalculator`): `commission_rate = commission/price`; `>=0.52` → 70%; `>=0.12` → 60%; `<0.12` → 50%; `net = floor(commission×0.90)` (trừ 10% thuế); `user = floor(net×rate)`. price≤0 hoặc commission≤0 → rate 0.50, cashback 0. Lưu `cashback_rate` dạng 0.50/0.60/0.70. VD: 200k/comm 30k → 16.200đ; 500k/300k → 189.000đ; 100k/5k → 2.250đ.
- **Đơn thực** (`docs/database/affiliate-orders.md`): `commission_rate = (net_commission×0.9)/order_amount`; `<0.12`→50; `0.12–0.52`→60; `>0.52`→70 (lưu 50/60/70); `cashback_amount = net_commission×cashback_rate/100`. ⚠ Khác biên (`>=0.52` vs `>0.52`) và đơn vị (0.5 vs 50) giữa hai mô tả – cần đối chiếu code (xem §10).
- Cộng cashback khi `affiliate_status` "Đang chờ xử lý" → "Hoàn thành"; chống trùng bằng `(reference_type='affiliate_order_item', reference_id, type='cashback', status='completed')`. Đơn hủy sau khi đã cộng → debit `refund`.

## 7. Quy trình Wallet
- `wallet_transactions` = sổ cái bất biến, **source of truth**; `users.wallet_balance` = cache runtime. `amount` luôn dương, `direction` credit|debit, `type`: cashback, withdraw, promotion, bonus, referral, adjustment, refund; chỉ `completed` tham gia đối soát. `running_no` = `WT`+YYYYMMDD+4 số. Không UPDATE amount/direction/type/running_no/balance_*; sai → tạo giao dịch mới.
- `WalletService` là nơi DUY NHẤT ghi ledger và `wallet_balance` (`creditCashback`, `debitWithdraw`, `adjust`, `availableBalance = balance − pending withdraw`, `syncBalance`, `isCashbackCredited`). Controller/Command/Job/Import không được `WalletTransaction::create()`.
- `withdraw_requests`: `running_no` `WR`+YYYYMMDD+4 số; `pending → approved → paid | rejected` (cấm pending→paid trực tiếp, paid 1 lần, không sửa amount). KHÔNG trừ tiền khi gửi yêu cầu; chỉ khi `paid` mới tạo `wallet_transactions` (withdraw, debit, completed, `processed_by`=admin). Bank lấy từ `users.bank_*`, thiếu → chặn; lưu snapshot. Admin: bulk-complete/complete/reject + export xlsx.
- Referral: cookie `ref` → `users.referred_by`; `referral_code` 8 ký tự upper unique. Các bảng campaigns/merchants/clicks/purchases/transactions/withdrawals(cũ) là legacy chưa dùng.

## 8. Quy tắc Credit Card và MCC
**Mô hình**: Thẻ user = ngân hàng (`bank_id`) + tên tự đặt (không lưu PAN/CVV/expiry, chỉ last4). 43 bank canonical, 19 category hệ thống. Template/Policy: `PolicyTemplate` (scope system|user) → `Policy` (blueprint `user_card_id IS NULL`) + `PolicyVersion` (cùng bảng `credit_card_policies`, `root_policy_id`, `version_no`) → `PolicyTier` → `PolicyTierCategory` (+ `transaction_caps`). Clone = deep copy cô lập; version append-only; `default_version_id` dùng khi clone (`defaultBlueprint()`), version mới KHÔNG tự đổi default. Kỳ finalized & policy superseded bất biến.

**Engine tính cashback (chốt)**:
1. Cashback **RETROACTIVE**: bậc chọn theo tổng eligible spend cả kỳ, áp cho mọi giao dịch; khoảng bậc nửa mở `[min,max)`; ngưỡng chi tiêu chỉ ở BẬC (category rule không có ngưỡng).
2. Thứ tự resolve rule: category cụ thể → combo → fallback `other` (mỗi bậc ĐÚNG 1 fallback, `category_id=NULL`, không xoá được). Thứ tự hiển thị: cụ thể → combo → fallback cuối.
3. Cap: (1) per-transaction (cap động `transaction_caps` khoảng đóng, không chồng lấn, THAY cap cố định) → (2) per-category/kỳ → (3) **trần bậc `max_cashback_per_period`** (từ §21; cột policy legacy chỉ để đọc, engine KHÔNG đọc). Chỉ rule `counts_toward_tier_cap=true` ăn trần bậc (fallback thường false).
4. Làm tròn: luôn FLOOR xuống đồng (`floor(round($v,6))`); `rounding_mode` bị bỏ qua. Thứ tự tiêu quota `ORDER BY transaction_date ASC, id ASC`.
5. Kỳ sao kê suy ra từ `statement_day` (VD 15 → 16/08–15/09; ngày 29/30/31 clamp cuối tháng). `spending_deadline_day` chỉ để nhắc. Cashback KHÔNG nhập tay (Excel có header cashback/hoan tien/tien hoan → từ chối file). Import chỉ `.xlsx`, idempotent `source_reference="<file>#<dòng>"`, số VN `1.000.000` & EN `1,000,000.50`.
6. `category_id` RESTRICT: đã dùng thì `is_active=false`. Giao dịch soft delete. Không gọi API ngân hàng.
- Applicability template: `applicable_bank_id` + `applicable_product_match` (substring không phân biệt hoa/thường), guard ở `PolicyService::cloneTemplate` (→422).
- Bảo mật: `Rule::exists(Model::class)` (không dùng tên bảng thô vì sai connection); thẻ của người khác trả cùng thông báo; Policy 403 trước, service kiểm lại; throttle `credit-card-api` 60/phút, admin `credit-card-admin-api` 120/phút; route admin `/admin/credit-card/...` cần `credit-cards.manage` + `isAdmin()` + `assertSystemOnlyCategories`.
- Combo: không combo chỉ có 1 danh mục (5 combo đã xoá, còn 7: `san-thuong-mai-dien-tu`, `cham-soc-sac-dep-y-te-thoi-trang`, `vib-shopping`, `vib-travel`, `vib-online`, `vib-one-card-mua-sam`, `vib-one-card-du-lich`; sau đó +`vpb-s-rewards-uu-dai`, `tpb-evo-online`).

**MCC (tra cứu, KHÔNG ảnh hưởng engine/ví)** – 3 lớp: (a) hồ sơ MCC admin nhập (`credit_card_policy_mcc_profiles/_rows/_row_codes/_documents`, bảng liên kết `..._profile_template_links` cho VIB nhiều hạng); (b) `credit_card_policy_mcc_row_benefits` (bậc/tỷ lệ/trần để tra cứu); (c) blueprint engine.
- Master `mcc` char(4) UNIQUE (293 mã); không tự tạo mã; mã thiếu master KHÔNG đưa vào. Row `included|excluded`; mã không được vừa in vừa ex; loại trừ THẮNG khi tra cứu.
- Tra cứu `lookupMcc()` / `lookupCategory()` (`/thetindung/tra-cuu`, `/tra-cuu/danh-muc`). Sắp xếp: rank 0 = có % số, 1 = thiếu %/unverified, 2 = excluded; % giảm dần, hoà → cap lớn hơn (NULL xếp sau). **Bonus** (`source_note='bonus'`, VD Sacombank UniQ 50% giao dịch đầu 30 ngày) không tham gia xếp hạng; chỉ có nhãn ("tới 20%") → `cashback_percent=null`, không bịa số. VIB gộp theo `product_name` (`VIB Max Card Visa`, `VIB One Card Visa`).
- Phí thường niên: chỉ ghi khi có nguồn chính thức đúng SP+hạng; NULL nếu không có (KHÔNG điền 0 kể cả "Miễn phí"); tách thẻ chính/phụ; `shinhanfinance.com.vn` ≠ Shinhan Bank. 20/27 hồ sơ có phí (VD LPBank 1.499.000; Sacombank Uniq 599.000; UOB One 1.200.000; BIDV 1.000.000/600.000; VPBank Z Card 399.000 miễn khi chi tiêu 80tr).
- Số liệu tham chiếu (có thể đổi): VIB Max: Starter 0,5% / Plus 5% / Pro 8% / Elite 10%, trần 500k/500k/1M/1,5M, ngoài nhóm 0,1%; VIB One: Gold 3% / Platinum 6% / Signature 9%, trần điểm 500k/1M/1,5M. VPBank Z Card JCB (thể lệ 27/08/2026–31/12/2027): 15%, 500.000/nhóm/kỳ ×3 nhóm, trần tổng 1.000.000/kỳ (chưa có cột riêng). MSB Online Visa: 10%, min chi 3tr, trần 300.000/kỳ. HSBC Cash Back: siêu thị/taxi 6% (8% nếu lương ≥10tr) trần 200.000/tháng. 8 template nghiên cứu 12/10 (Sacombank Platinum Cashback/Amex, MB JCB Platinum, UOB One, VPBank Flex, Shinhan 365/MyLife, Cake 2in1) + 6 đợt bổ sung (VPBank S-Rewards, HSBC, BIDV 360, TPB EVO, VIB Online Plus 2in1, OCB JCB Platinum). KHÔNG seed: VPBank Reward Visa, Shinhan Cashback Visa (thiếu/xung đột dữ liệu).
- Quy trình thêm chính sách thẻ: nguồn chính thức → xác minh tên/mạng lưới → phân loại HOÀN THÀNH / CHƯA ĐỦ DỮ LIỆU / CHƯA HỖ TRỢ / CẦN XÁC NHẬN → seed idempotent (`updateOrCreate (scope,owner,slug)`, chỉ trỏ 19 danh mục gốc) → test → admin.

## 9. Các quyết định kỹ thuật
- Extension thay Playwright/CDP; DB-driven cache (không Redis); Windows Task Scheduler thay Laravel scheduler; `afterResponse` thay queue thật; Fast Mode = server-side 302; OPcache bật.
- **OPcache** (php.ini `C:\xampp\php\php.ini`, SHA `8FF4511C…A4B54`): enable=1, enable_cli=0, memory 128, interned_strings 16, max_accelerated_files 10000, validate_timestamps=1, revalidate_freq=2, save_comments=1, `jit=off` (`jit_buffer_size=0`; KHÔNG dùng `jit=0`). Hiệu quả: /login 380→49ms, /dashboard 458→114ms, TTR HIT ~4,9×. Restart bằng `Restart-Service -Name "Apache2.4" -Force` (KHÔNG `httpd.exe -k stop`). Backup: `php.ini.backup-opcache-20260928-230423` (OPcache tắt), `…20260929-081452` (192/20000).
- Credit card: connection riêng, thư mục migration con, FK xuyên DB không dùng (không JOIN xuyên DB); SQLite vs MySQL: DATE trả `"YYYY-MM-DD 00:00:00"` → luôn `whereDate()`; `authorize('create', [Model::class, $parent])` (tham số thứ 3 bị bỏ); Carbon strict → dùng `hasFormat`; Laravel `sortBy` nhiều closure sai → dùng 1 closure trả mảng khoá.
- Session: cookie `hoan-tien-mua-sam-session`/`hoantien_session_v2`, SECURE, SameSite lax, lifetime 10080 phút; T2 V2 = tự phục hồi CSRF (`GET /csrf-token` rồi retry 1 lần).

## 10. Lỗi và rủi ro đã biết
**Bảo mật (CODEMAP §10)**: (1) `APP_DEBUG=true`/`APP_ENV=local`/`LOG_LEVEL=debug` trên domain public (laravel.log ~147–154MB); (2) 13 route `/debug/*` public; (3) price-comparison API mở ngoài prod; (4) `trustProxies('*')`; (5) token extension qua query string, default hard-code, lưu `chrome.storage.sync` không mã hoá, lộ Apache log; (6) `.env` chứa secret thật, DB root mật khẩu rỗng, credential mail từng lộ trong output; (7) commit nhầm `affiliate-worker/storage/` (chrome-profile, shopee-state.json) + `node_modules` + `vendor.zip` 27.5MB; (8) `server.js` không auth, CORS rộng; (9) seed admin mặc định `admin@hoantien.local`/`password`; (10) `T2_TEST_ENABLED=true` bật `/__t2-test/rotate-session`.

**Lỗi/hành vi**
- Job kẹt `processing` (extension poll crash, không timeout/retry; ProductData `success=false`, short link về trang chủ Shopee không có item_id → UI poll vô hạn, không MAX_POLL); race claim job extension (SELECT→UPDATE không điều kiện); `result()` N+1.
- `AffiliateCache` khai `$primaryKey='item_id'` trong khi DB PK ghép; `whereDate(cache_date)` có thể mất index; thiếu index cache_date.
- `aff.hoantien.xyz` không hiện ProductData: nghi schema DB lệch (thiếu cột `product_image, shop_name, sales, …` hoặc `item_id` INT) – cần `php artisan migrate` (chưa xác nhận).
- 419 CSRF mãn tính trên Chrome iOS do document legacy (JS pre-T2, token cũ) – 24/09–26/09; fallback JS cũ hiển thị "Lỗi không xác định". Đề xuất: 419 JSON chuẩn, probe CSRF mọi page, alert csrf_failure lặp.
- Fast Mode cũ (`await fetch` rồi `a.click()`) mất user activation trên iOS → đã thay bằng 302 server-side (cần field-test lại trên iPhone 12).
- Hiệu năng: external HTTP đồng bộ (resolver lạnh ~0,9–1s, ShopeeFood `/u/{code}` ~1,05–1,1s, store API 0,58–0,72s gọi 2 lần); không negative cache; ProductData worst-case ~25,5s; DB không phải bottleneck.
- Sự cố đã xảy ra: xoá nhầm `link_requests` id 100, 101 (chỉ khôi phục một phần); tắt/bật Apache bằng `httpd -k stop`.
- Docs: README/PROJECT_CONTEXT sai endpoint; `docs - Copy/` stale; `queue-system.md`, `known-issues.md` cũ (ghi 10 vấn đề, thực 5); có `background - Copy.js`/`content - Copy.js` dễ nhầm; `cam-nang-san-sale original.md` là bản cũ.
- **Credit card**: bảng `credit_card_banks`/`credit_card_categories` trùng tên ở CẢ HAI DB → model quên ghim connection đọc bảng rỗng; 4 bảng legacy giữ nguyên không dùng; Review 1A lệch triển khai thực (tin Final Report); bug MB Ultimate JCB (editor đọc `tier.categories` thay vì `rules` → lưu version xoá sạch rule; DB chưa hồi, cần admin lưu v3); clone chép nguyên `note` và `max_cashback_total_per_period` legacy (đã sửa bằng `cloneBlueprintNote()` + migration `2026_10_14_000010/000011`); blueprint `superseded` do clone giữ status (#3,#6,#7,#9,#10,#11,#13, `effective_from > effective_to`; `currentBlueprint()` null nhưng `defaultBlueprint()` đúng); cột `max_total=800000` legacy vẫn còn trên #3,#6,#7,#9,#10,#11,#13 (chưa xác nhận sai); master thiếu MCC (VIB `5301,5298,5296,0046` + dải hàng không; Sacombank Amex dải 3xxx; Shinhan `6381,6399`; MB `8044,8082,7295`; Sacombank Uniq `9751`; BIDV `6050,6532,6533,6538`; LPBank …) ⇒ tra MCC không trả Amex; lệch nhãn 5734/5735.
- **Hạn chế engine đã chốt bằng test**: trần chung combo bị áp theo từng danh mục; không có cờ kênh POS/online; không hỗ trợ "≥7 giao dịch" (OCB), thưởng lương HSBC 8%, trần theo tháng dương lịch, trần "giao dịch đặc biệt" 10.000 điểm/kỳ, trần tổng nhóm Z Card (chỉ ghi note); chưa tự xác định hạng VIB ưu tiên; điểm quy đổi 1:1→% (UOB cần xác nhận).
- Test baseline: full suite luôn có 3 fail có sẵn (`Auth\RegistrationTest`, `ShopeeFoodOrderSyncServiceTest`, `TikTokSyncPhase3Test`); ngoài ra thấy `CreditCardModuleTest::…override_existing_routes` (route `credit-cards.api.policies.history`), `PromotionNewsHomepageTest`, `MoneyInputThousandRegressionTest`, `ShinhanSupremePolicyTemplateTest` (phụ thuộc thứ tự). Số test đã ghi: ~1573 pass (CC ~1611).

## 11. Những điểm cần lưu ý khi phát triển
- Mọi ghi ledger/số dư đi qua `WalletService`; không `WalletTransaction::create()` ngoài đó.
- Không sửa `app/Services/CashbackCalculator.php` khi làm thẻ tín dụng; thêm mã CC dùng `App\Services\CreditCard\*`.
- Model/validation CC: luôn ghim connection `creditcard`; `Rule::exists(Model::class)`; migration CC đặt trong `database/migrations/creditcard/` và chạy bằng `credit-card:migrate`.
- Thêm seeder KHÔNG tạo dữ liệu live (test dùng `:memory:`). Seed live an toàn: script reflection gọi `seedResearchedTemplates($banks,$categories)` trong `DB::connection('creditcard')->transaction`; KHÔNG chạy `db:seed --class=CreditCardSeeder` (có `deactivateNonCanonicalBanks()`). Payload canonical `tiers[].rules` (cấm alias `categories`).
- Không tự đặt thông số chính sách thẻ; không điền phí = 0; không bịa % khi chỉ có nhãn.
- Không sync tay (`affiliate:tiktok-sync --sync`) hay `credit-card:migrate --fresh` (xoá dữ liệu user) khi chưa được chủ dự án xác nhận.
- Đổi `php.ini`/OPcache phải backup + `Restart-Service Apache2.4`; không bật JIT, không `validate_timestamps=0`.
- Đo hiệu năng qua HTTPS (SESSION_SECURE_COOKIE=true, HTTP gây 419; XSRF cookie URL-encode cần decode).
- `affiliate-worker/storage/chrome-profile` tuyệt đối không commit/deploy; extension + tab `affiliate.shopee.vn/offer/custom_link` đăng nhập tay vẫn phải chạy trên máy cá nhân.
- Chuyển hosting (cPanel khả thi): xoá/gate debug route, `.env` production + `APP_DEBUG=false`, siết trustProxies, mở outbound (riohub.vn, api.lazada.vn, api.bachhoaxanh.com, data.addlivetag.com, shopeefood, Shopee, Google), thay Task Scheduler bằng cron `affiliate:sync-all` mỗi 3h, `QUEUE_CONNECTION=sync`, `storage/` ghi được, cập nhật token trong popup extension.
- Workstream dở dang (không commit lẫn): MCC (`PolicyMccProfile`, `CardPolicyLookupService`, `MccRowBenefitImporter`…), Optimizer, Backup/OrderSync.
- Vận hành: giám sát `failed_jobs`, `storage/logs/laravel.log`, `csrf-forensic.log` (retention 7 ngày), `AFFILIATE_TIMING=true` cho log `[CACHE-Timing]`; `.env` quan trọng: APP_URL, AFFILIATE_EXTENSION_TOKEN, AFFILIATE_WORKER_URL, GOOGLE_*, SHOPEEFOOD_COOKIE, LAZADA_*, `DB_CREDITCARD_*`, `CREDIT_CARD_API_RATE_LIMIT`.

## 12. Danh sách tài liệu nguồn đã đọc (84/84)
**Gốc / vận hành**: README.md, PROJECT_CONTEXT.md, product-data-api.md, BaoCao_Implement_WinMartProvider.md, affiliate-worker/README-SETUP.md.
**docs/ (cốt lõi)**: README, CODEMAP, architecture, api-endpoints, affiliate-cache, content-script, worker-extension, queue-system, dashboard-flow, database-schema, deployment, development-setup, glossary, known-issues, troubleshooting, tiktok-autosync-windows-task; database/{affiliate-orders, wallet-transactions, withdraw-requests}; ui/design-system.
**docs/ (Credit Card, 22)**: PHASE_1A_ARCHITECTURE_REVIEW, PHASE_1A_IMPLEMENTATION, PHASE_1A_IMPLEMENTATION_REPORT, PHASE_1B_FINAL_REPORT, PHASE_1C_FINAL_REPORT, SYSTEM_POLICY_ADMIN_REPORT, SYSTEM_POLICY_CLONE_INDEPENDENCE_REPORT, POLICY_DEVELOPMENT_GUIDE, BLUEPRINT_NOTE_STATUS_AUDIT_REPORT, CATEGORY_COMBO_NORMALIZATION_PLAN, NEW_TEMPLATES_RESEARCH_REPORT, MCC_WORKFLOW, MCC_POLICY_COVERAGE_AUDIT_REPORT, MCC_PROFILES_REPORT, MCC_NEW_PROFILES_REPORT, MCC_ANNUAL_FEES_REPORT, MCC_CATEGORY_LOOKUP_REPORT, MCC_ROW_BENEFITS_REPORT, MCC_VIB_PRODUCT_GROUPING_REPORT, MCC_VPBANK_Z_CARD_JCB_UPDATE_REPORT, MSB_ONLINE_800K_CAP_INVESTIGATION, VIB_MAX_CARD_VISA_REPORT.
**docs/ (audit & hiệu năng, 14)**: BUG_AUDIT_2026-09-26_SHOPEE_UNKNOWN_ERROR(+_2), BUG_AUDIT_CONTROL_CASE_2026-09-26_2036, BUG_AUDIT_DOCUMENT_LINEAGE_2026-09-26, FAST_MODE_MOBILE_DEEPLINK_FORENSIC_2026-09-29, FAST_MODE_UX_UPDATE_2026-09-29, FULL_LINK_GENERATION_FORENSIC_AUDIT_2026-09-28, OPCACHE_DEPLOYMENT_2026-09-29, OPCACHE_DEPLOYMENT_AND_PERFORMANCE_2026-09-28, PERFORMANCE_AUDIT_2026-09-27(+_SHOPEE), PERFORMANCE_AUDIT_2026-09-28, PERFORMANCE_FAST_MODE_OPCACHE_AUDIT_2026-09-28, PRODUCTDATA_AUDIT_2026-09-28_AFF_vs_HOANTIEN.
**docs - Copy/ (stale, 14)**: API, ARCHITECTURE, CACHE, CASHBACK, CHANGELOG_AI, CONFIG, DATABASE, FILE_REFERENCE, FOLDER_STRUCTURE, LOGIN, SEQUENCE, SERVICES, TODO, WORKER.
**resources/content/guides/ (9)**: cach-tao-link-hoan-tien, cam-nang-san-sale, cam-nang-san-sale original, cau-hoi-thuong-gap, huong-dan-rut-tien, meo-san-flash-sale, thuat-ngu-affiliate, tra-cuu-don-hang, truong-hop-khong-hoan-tien.

## 13. Điểm chưa xác minh
- Công thức cashback: biên `>=0.52` vs `>0.52`, đơn vị 0.5 vs 50 – chưa đối chiếu với code.
- Không có tài liệu Portfolio Optimizer / CardRecommendationService; không có tài liệu API đối tác ngoài Product Data API & extension API.
- Schema DB trên `aff.hoantien.xyz`, OPcache/php.ini trên host đó; trạng thái `APP_DEBUG` hiện tại của production.
- Số migration (22 vs 42 vs 41 CC), `->limit(5)` ở `/api/extension/jobs` (docs mâu thuẫn); resolver timeout (2s vs 6s).
- Field-test Fast Mode 302 trên iPhone 12; cơ chế sinh document legacy trên Chrome iOS (bfcache vs duplicate tab).
- Trạng thái live DB thẻ (v3 MB Ultimate JCB đã lưu lại chưa; các blueprint superseded; `max_total=800000` legacy).
- Tài liệu có thể đã lỗi thời so với code sau 2026-10-14; không đối chiếu source code theo yêu cầu.
