const CACHE_NAME = 'program-ttd-shell-v1';
const PUBLIC_ASSET_PREFIXES = ['/build/', '/icons/'];
const PUBLIC_ASSETS = ['/manifest.webmanifest'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(PUBLIC_ASSETS)).then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) return;
    if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/sanctum/')) return;

    const isPublicAsset = PUBLIC_ASSETS.includes(url.pathname)
        || PUBLIC_ASSET_PREFIXES.some((prefix) => url.pathname.startsWith(prefix));

    if (isPublicAsset) {
        event.respondWith(
            caches.match(request).then((cached) => cached ?? fetch(request).then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
                }

                return response;
            })),
        );
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => new Response(
            '<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Luring</title><body><main><h1>Anda sedang luring</h1><p>Data aplikasi tidak disimpan untuk akses luring.</p></main></body></html>',
            { headers: { 'Content-Type': 'text/html; charset=utf-8' } },
        )));
    }
});