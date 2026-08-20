const CACHE = 'refdrive-v2';
const OFFLINE = ['/'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(OFFLINE)));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys =>
    Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
  ));
  self.clients.claim();
});

self.addEventListener('fetch', e => {
  // Pouze GET požadavky na stejnou origin necháváme procházet přes SW
  // (offline fallback). Cross-origin požadavky (externí obrázky, QR kódy,
  // API volání) NEZACHYTÁVAT — na některých Android prohlížečích fetch()
  // pro cross-origin požadavky uvnitř service workeru selhává/odmítá a
  // způsobuje, že se externí obrázky vůbec nezobrazí.
  if (e.request.method !== 'GET') return;
  if (new URL(e.request.url).origin !== self.location.origin) return;

  e.respondWith(
    fetch(e.request).catch(() => caches.match(e.request))
  );
});
