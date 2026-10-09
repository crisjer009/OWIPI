/**
 * OWI Physical Inventory - Service Worker
 * Enables PWA installability and single-instance launch handler on mobile devices.
 */
self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function (event) {
    // Direct network pass-through to ensure inventory sync & database queries are always fresh
    event.respondWith(fetch(event.request));
});
