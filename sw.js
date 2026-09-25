// sw.js -- EGradePro Student app (installable web app).
// Pages always come from the server (grades, classwork and messages must be
// current), so nothing personal is cached. Only the offline page and the app
// icons are kept, so the app can say "offline" instead of a browser error when
// the server (the school PC / tunnel) is not reachable.
const CACHE = 'egradepro-app-v1';
const OFFLINE_URL = 'offline.html';
const PRECACHE = [OFFLINE_URL, 'static/images/app/icon-192.png', 'static/images/app/icon-512.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  if (event.request.mode !== 'navigate') return; // files, images, forms: straight to the server
  event.respondWith(
    fetch(event.request).catch(() => caches.match(OFFLINE_URL))
  );
});
