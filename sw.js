const CACHE = 'telefon-v1';

const PRECACHE = [
    '/dashboard.php',
    '/login.php',
    '/assets/css/style.css',
    '/assets/js/app.js',
    '/assets/img/logo.jpg',
    '/favicon.svg',
];

self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE)
            .then(c => c.addAll(PRECACHE).catch(() => {}))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys.filter(k => k !== CACHE).map(k => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', e => {
    if (e.request.method !== 'GET') return;

    const url = new URL(e.request.url);

    // API volání nekešujeme — data musí být vždy živá
    if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/admin/api/')) return;

    // Cross-origin (CDN) — necháme prohlížeč
    if (url.origin !== location.origin) return;

    // Network-first: zkus síť, při selhání vrať cache
    e.respondWith(
        fetch(e.request)
            .then(res => {
                if (res.ok) {
                    const clone = res.clone();
                    caches.open(CACHE).then(c => c.put(e.request, clone));
                }
                return res;
            })
            .catch(() => caches.match(e.request))
    );
});
