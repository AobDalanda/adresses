const CACHE_NAME = 'aldahim-bo-v10';
const APP_SHELL = [
  '/?pwa=aldahim-bo&v=10',
  '/manifest.webmanifest?v=10',
  '/bo/app.js?v=10',
  '/bo/styles.css?v=10',
  '/bo/icon.svg?v=10'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
    )).then(() => self.clients.matchAll({ type: 'window' })).then((clients) => {
      clients.forEach((client) => client.postMessage({ type: 'PWA_VERSION_READY', version: CACHE_NAME }));
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  if (request.method !== 'GET' || url.pathname.startsWith('/api/')) {
    return;
  }

  event.respondWith(
    fetch(request)
      .then((response) => {
        const copy = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
        return response;
      })
      .catch(() => caches.match(request).then((cached) => cached || caches.match('/?pwa=aldahim-bo&v=10')))
  );
});
