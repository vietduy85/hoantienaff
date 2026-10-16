<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'affiliate_worker' => [
        'url' => env('AFFILIATE_WORKER_URL', 'http://127.0.0.1:3001'),
    ],

    'affiliate_extension' => [
        'token' => env('AFFILIATE_EXTENSION_TOKEN', ''),
    ],

    'riohub' => [
        'base_url' => env('RIOHUB_BASE_URL'),
        'api_key' => env('RIOHUB_API_KEY'),
        'creator_username' => env('RIOHUB_CREATOR_USERNAME'),
    ],

    'shopeefood' => [
        'base_url' => env('SHOPEEFOOD_API_URL', 'https://data.addlivetag.com/shopeefood'),
        'cookie' => env('SHOPEEFOOD_COOKIE'),
    ],

    /*
     * AddLiveTag — Sàn Cam Product Data API.
     *
     * Từ 2026-10-01 mọi request bắt buộc kèm API key qua header `X-API-Key`
     * (hoặc `&key=` trong URL). Key lấy ở addlivetag.com → API Key → Tạo Key.
     *
     * `base_url` không kèm path — endpoint cụ thể do service quyết định
     * (vd: /product-data/product-data.php). Không lưu key trong repo.
     */
    'addlivetag' => [
        'base_url' => env('ADDLIVETAG_BASE_URL', 'https://data.addlivetag.com'),
        'conversions_base_url' => env('ADDLIVETAG_CONVERSIONS_URL', 'https://addlivetag.com/api/v1/conversions.php'),
        'api_key' => env('ADDLIVETAG_API_KEY'),
        'max_sync_pages' => (int) env('ADDLIVETAG_MAX_SYNC_PAGES', 100),
    ],

    /*
     * AccessTrade affiliate network.
     *
     * The token is normally configured through the admin UI and stored
     * encrypted in the `settings` table. These env values are only a fallback
     * for headless deployments; never commit a real token.
     */
    'accesstrade' => [
        'base_url' => env('ACCESSTRADE_BASE_URL', 'https://api.accesstrade.vn'),
        'token' => env('ACCESSTRADE_TOKEN'),
        'timeout' => (int) env('ACCESSTRADE_TIMEOUT', 15),
        'connect_timeout' => (int) env('ACCESSTRADE_CONNECT_TIMEOUT', 8),
        'max_retries' => (int) env('ACCESSTRADE_MAX_RETRIES', 2),
    ],

    'lazada' => [
        'base_url' => env('LAZADA_BASE_URL', 'https://api.lazada.vn/rest'),
        'app_key' => env('LAZADA_APP_KEY'),
        'app_secret' => env('LAZADA_APP_SECRET'),
        'user_token' => env('LAZADA_USER_TOKEN'),
    ],

    /*
     * Bách Hóa Xanh product search.
     *
     * INTERNAL / UNDOCUMENTED API (reverse-engineered from the website's
     * Next.js bundles). It is not a public/partner API and may change
     * without notice. The API is location dependent: store_id drives
     * price, stock and availability, so it must be configured explicitly
     * per deployment (never guessed from the visitor).
     */
    'bachhoaxanh' => [
        'base_url' => env('BHX_API_BASE_URL', 'https://api.bachhoaxanh.com/gw'),
        'store_id' => env('BHX_STORE_ID'),
        'province_id' => env('BHX_PROVINCE_ID'),
        'ward_id' => env('BHX_WARD_ID'),
        'user_agent' => env(
            'BHX_USER_AGENT',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        ),
    ],

    /*
     * Kingfoodmart product search.
     *
     * INTERNAL / UNDOCUMENTED API (reverse-engineered from the website's
     * Next.js bundles). It is not a public/partner API and may change
     * without notice. Search does NOT require authentication, cookies or
     * an API key, so no credentials are stored here.
     *
     * The API is location dependent: a store-codes header scopes price and
     * stock. That header is intentionally NOT sent in this phase because the
     * UI has no store selector yet (no store code is guessed or hard-coded).
     */
    'kingfoodmart' => [
        'base_url' => env(
            'KINGFOODMART_API_BASE_URL',
            'https://onelife-api.kingfoodmart.com/v1'
        ),
        'tenant' => env('KINGFOODMART_TENANT', 'kingfood'),
    ],

    /*
     * WinMart product search.
     *
     * PUBLIC catalog API (no authentication / API key required):
     *   POST https://api-crownx.winmart.vn/ss/api/v2/public/winmart/item-search
     *
     * The API is store scoped: store_group_code + store_no drive price, stock
     * and availability, so they must be configured per deployment. Defaults
     * below are the values verified against the live API (never guessed from
     * the visitor). The product page is public too:
     *   https://www.winmart.vn/products/{seoName}
     */
    'winmart' => [
        'base_url' => env(
            'WINMART_API_BASE_URL',
            'https://api-crownx.winmart.vn'
        ),
        'store_group_code' => env('WINMART_STORE_GROUP_CODE', '1998'),
        'store_no' => env('WINMART_STORE_NO', '1535'),
    ],

    /*
     * Product metadata (name/image/price) fetched from a merchant product page
     * via Open Graph / meta tags. Used to enrich AccessTrade links (e.g.
     * CellphoneS) where the network API does not return product information.
     *
     * This is a best-effort, read-only fetch with SSRF guards. It must NEVER
     * break link creation — a failure simply leaves the metadata fields empty.
     */
    'product_metadata' => [
        'enabled' => (bool) env('PRODUCT_METADATA_ENABLED', true),
        'timeout' => (int) env('PRODUCT_METADATA_TIMEOUT', 8),
        'connect_timeout' => (int) env('PRODUCT_METADATA_CONNECT_TIMEOUT', 4),
        'max_bytes' => (int) env('PRODUCT_METADATA_MAX_BYTES', 524288),
        'max_redirects' => (int) env('PRODUCT_METADATA_MAX_REDIRECTS', 3),
        'verify_dns' => (bool) env('PRODUCT_METADATA_VERIFY_DNS', true),
    ],

];
