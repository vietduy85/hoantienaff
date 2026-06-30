# Configuration

## .env

### Application

| Variable | Current Value | Mô tả |
|----------|---------------|-------|
| APP_NAME | Hoàn Tiền Mua Sắm | Tên ứng dụng dùng trong session cookie name |
| APP_ENV | production | Môi trường |
| APP_DEBUG | false | Debug mode |
| APP_URL | https://hoantien.xyz | Base URL |
| APP_LOCALE | vi | Locale mặc định |
| APP_KEY | (set) | Encryption key (32 chars AES-256-CBC) |

### Database

| Variable | Current Value | Mô tả |
|----------|---------------|-------|
| DB_CONNECTION | mysql | Database driver |
| DB_HOST | 127.0.0.1 | Host |
| DB_PORT | 3306 | Port |
| DB_DATABASE | hoantien | Database name |
| DB_USERNAME | (set) | Username |
| DB_PASSWORD | (set) | Password |

### Session

| Variable | Current Value | Mô tả |
|----------|---------------|-------|
| SESSION_DRIVER | database | Session storage driver |
| SESSION_COOKIE | hoan-tien-mua-sam-session | Session cookie name |
| SESSION_PATH | / | Cookie path |
| SESSION_SECURE_COOKIE | true | Secure flag (HTTPS only) |
| SESSION_DOMAIN | null | Cookie domain (null → exact hostname) |
| SESSION_SAME_SITE | lax | Same-Site policy |
| SESSION_LIFETIME | 10080 | Session lifetime (minutes = 7 days) |
| SESSION_ENCRYPT | false | Encrypt session data in storage |
| SESSION_HTTP_ONLY | true | HttpOnly flag |

**Lưu ý**: `SESSION_SECURE_COOKIE=true` + `SESSION_DOMAIN=null` + `SESSION_SAME_SITE=lax` là bộ cấu hình có thể gây vấn đề trên iOS Safari (cookie không lưu được).

### Database Session Table

| Variable | Current Value |
|----------|---------------|
| SESSION_TABLE | sessions |
| SESSION_CONNECTION | (not set — uses default) |

### Cache

| Variable | Current Value | Mô tả |
|----------|---------------|-------|
| CACHE_STORE | database | Cache driver |
| DB_CACHE_TABLE | cache | Cache table name |

### Queue

| Variable | Current Value | Mô tả |
|----------|---------------|-------|
| QUEUE_CONNECTION | database | Queue driver |
| DB_QUEUE_TABLE | jobs | Queue table |

### Logging

| Variable | Current Value | Mô tả |
|----------|---------------|-------|
| LOG_CHANNEL | stack | Log channel |
| LOG_LEVEL | debug | Log level |
| LOG_STACK | single | Stack channels |

### Mail

| Variable | Current Value | Mô tả |
|----------|---------------|-------|
| MAIL_MAILER | log | Mail driver (log only) |
| MAIL_HOST | 127.0.0.1 | |
| MAIL_PORT | 2525 | |
| MAIL_USERNAME | null | |
| MAIL_PASSWORD | null | |
| MAIL_FROM_ADDRESS | hello@hoantien.xyz | From address |
| MAIL_FROM_NAME | Hoàn Tiền Mua Sắm | From name |

### Google OAuth

| Variable | Value |
|----------|-------|
| GOOGLE_CLIENT_ID | (set) |
| GOOGLE_CLIENT_SECRET | (set) |
| GOOGLE_REDIRECT_URI | https://hoantien.xyz/auth/google/callback |

### Affiliate Worker

| Variable | Current Value | Mô tả |
|----------|---------------|-------|
| AFFILIATE_WORKER_URL | http://127.0.0.1:3001 | URL worker Express |
| AFFILIATE_EXTENSION_TOKEN | (set) | Token xác thực extension |

### Debug

| Variable | Current Value | Mô tả |
|----------|---------------|-------|
| AFFILIATE_TIMING | false | Bật/tắt timing log |

### Previous Keys

| Variable | Value |
|----------|-------|
| APP_PREVIOUS_KEYS | (empty) |

## Cloudflare

### Proxy / Tunnel
- **SSL**: Flexible (Cloudflare - Origin: HTTP)
- **Tunnel**: cloudflared tunnel kết nối origin server đến Cloudflare
- **Cache**: DYNAMIC (không cache PHP response)
- **HTTP/2**: Enabled
- **Min TLS**: 1.2

### Page Rules
TODO / Unknown — Không có thông tin về Page Rules hiện tại.

### Workers
TODO / Unknown — Không có thông tin về Workers.

### Transform Rules
TODO / Unknown — Không có thông tin về Transform Rules.

## Chrome / Playwright (Deprecated)

| Config | Value | Mô tả |
|--------|-------|-------|
| Chrome path | C:\Program Files\Google\Chrome\Application\chrome.exe | Chrome executable |
| CDP port | 9222 | Remote debugging port |
| User data dir | affiliate-worker/storage/chrome-profile | Profile directory |

Config này chỉ dùng cho CDP (đã deprecated). Hiện tại worker dùng Browser Extension.

## Browser Extension

Extension config được lưu trong `chrome.storage.local`:

| Key | Mô tả |
|-----|-------|
| apiUrl | HTTPS URL của Laravel (https://hoantien.xyz) |
| token | AFFILIATE_EXTENSION_TOKEN |
| isRunning | Boolean — polling on/off |

## AppServiceProvider

```php
// app/Providers/AppServiceProvider.php
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tag all affiliate providers
        $this->app->tag([...], 'affiliate-providers');
        
        // Inject tagged providers into ProviderFactory
        $this->app->when(ProviderFactory::class)
            ->needs('$providers')
            ->giveTagged('affiliate-providers');
    }

    public function boot(): void
    {
        // Force HTTPS in non-local environment
        if (! $this->app->isLocal()) {
            URL::forceScheme('https');
        }
    }
}
```

**'forceScheme('https')**: Quan trọng — đảm bảo tất cả URL generated bởi Laravel đều dùng HTTPS (cần cho Cloudflare).

## Middleware Config (bootstrap/app.php)

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: '*');
    $middleware->validateCsrfTokens(except: ['api/*']);
})
```

- `trustProxies(at: '*')`: Trust tất cả proxy (Cloudflare). Cần để `request()->secure()` trả về đúng khi đứng sau Cloudflare.
- `validateCsrfTokens(except: ['api/*'])`: Bỏ CSRF cho API routes (extension endpoints).
