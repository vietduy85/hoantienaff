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

const CACHE_VERSION = '1.0.4';
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

// Thông báo đẩy (admin: yêu cầu rút tiền mới). Payload JSON: { title, body, url }.
self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data && event.data.text() }; }

    event.waitUntil(
        self.registration.showNotification(data.title || 'Hoàn Tiền', {
            body: data.body || '',
            icon: '/icon-192.png',
            data: { url: data.url || '/' },
        })
            // Báo về máy chủ là máy đã nhận được push (để dò lỗi), không chặn hiển thị.
            .then(() => fetch('/admin/push/received?v=' + CACHE_VERSION, { credentials: 'same-origin' }).catch(() => {}))
    );
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'version' && event.ports[0]) {
        event.ports[0].postMessage(CACHE_VERSION);
    }
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            for (const client of windows) {
                if ('focus' in client) {
                    client.navigate(url).catch(() => {});
                    return client.focus();
                }
            }
            return self.clients.openWindow(url);
        })
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // POST/PUT/PATCH/DELETE... không xử lý, luôn đi thẳng tới server
    if (request.method !== 'GET') return;

    // /csrf-token: TUYỆT ĐỐI network-only, không bao giờ cache (token thay đổi theo session).
    if (new URL(request.url).pathname === '/csrf-token') return;

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
