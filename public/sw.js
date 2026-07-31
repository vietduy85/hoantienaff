/**
 * Hoàn Tiền — Service Worker
 *
 * Chiến lược:
 * - Tài nguyên tĩnh (favicon, icon, css, js, font, logo, image): CACHE FIRST.
 * - Mọi request còn lại: NETWORK ONLY.
 *
 * TUYỆT ĐỐI KHÔNG cache: HTML, Dashboard, Wallet, Withdraw, Admin, API, Ajax,
 * POST, PUT, PATCH, DELETE. Không intercept navigation.
 */

const CACHE_VERSION = '1.0.0';
const CACHE_PREFIX = 'hoantien-cache-';
const LEGACY_CACHE_PREFIX = 'hoantien-static-';
const STATIC_CACHE = `${CACHE_PREFIX}static-${CACHE_VERSION}`;

// Tài nguyên tĩnh được phép cache
const STATIC_ASSET = /\.(css|js|png|jpg|jpeg|gif|svg|webp|avif|ico|woff|woff2|ttf|eot|otf)(\?.*)?$/i;

function isStaticAsset(url) {
    return STATIC_ASSET.test(new URL(url).pathname);
}

// Có thuộc về cache của project này không (chỉ xoá cache của mình)
function isOwnCache(key) {
    return key.startsWith(CACHE_PREFIX) || key.startsWith(LEGACY_CACHE_PREFIX);
}

self.addEventListener('install', (event) => {
    // Tạo cache rỗng; tài nguyên được thêm dần theo nhu cầu (Cache First)
    event.waitUntil(caches.open(STATIC_CACHE));
    // Kích hoạt service worker mới ngay lập tức
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    // Chỉ xoá cache cũ của project này, giữ nguyên cache của website khác
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => isOwnCache(key) && key !== STATIC_CACHE)
                    .map((key) => caches.delete(key))
            )
        )
    );
    // Service worker mới kiểm soát toàn bộ tab đang mở
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // POST/PUT/PATCH/DELETE... không xử lý, luôn đi thẳng tới server
    if (request.method !== 'GET') return;

    // Không intercept navigation — HTML luôn lấy từ server (không cache HTML)
    if (request.mode === 'navigate') return;

    // Các request còn lại (API, Ajax, HTML partial, JSON...): NETWORK ONLY
    if (!isStaticAsset(request.url)) return;

    // Tài nguyên tĩnh: CACHE FIRST
    event.respondWith(
        caches.match(request).then((cached) => {
            if (cached) return cached;
            return fetch(request).then((response) => {
                if (response && response.ok) {
                    const clone = response.clone();
                    caches.open(STATIC_CACHE).then((cache) => cache.put(request, clone));
                }
                return response;
            });
        })
    );
});
