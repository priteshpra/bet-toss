const CACHE = 'tg-bet-alert-v1';

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll([
    './',
    './index.html',
    './assets/app.css',
    './assets/app.js',
    './manifest.json',
    './icon-192.png',
  ])).catch(() => {}));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.pathname.includes('api.php') || url.pathname.includes('cron.php')) {
    return;
  }
  event.respondWith(fetch(event.request).then((res) => {
    const copy = res.clone();
    caches.open(CACHE).then((cache) => cache.put(event.request, copy)).catch(() => {});
    return res;
  }).catch(() => caches.match(event.request)));
});

self.addEventListener('push', (event) => {
  let data = {
    title: 'Naya bet',
    body: 'Watched user ne bet lagaya',
    tag: 'tg-bet',
    url: './',
  };
  try {
    data = Object.assign(data, event.data ? event.data.json() : {});
  } catch (e) {}
  event.waitUntil((async () => {
    await self.registration.showNotification(data.title, {
      body: data.body,
      tag: String(data.tag || 'tg-bet'),
      renotify: true,
      requireInteraction: true,
      vibrate: [200, 80, 200, 80, 420],
      icon: 'icon-192.png',
      badge: 'icon-192.png',
      data: { url: data.url || './' },
    });
    const open = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of open) {
      client.postMessage({ type: 'push', title: data.title, body: data.body });
    }
  })());
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const client of list) {
      if ('focus' in client) return client.focus();
    }
    return self.clients.openWindow('./');
  }));
});
