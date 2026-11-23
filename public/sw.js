// Hanya push + klik notifikasi. Sengaja tanpa handler fetch dan tanpa cache: data finansial tidak boleh tersimpan offline.
// Versi baru langsung aktif tanpa menunggu semua tab ditutup (aman: tidak ada cache/fetch).
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch { /* payload bukan JSON */ }
    const url = typeof data.url === 'string' && /^\/(?!\/)/.test(data.url) ? data.url : '/dashboard';
    event.waitUntil(Promise.all([
        self.registration.showNotification(data.title || 'PMS', {
            body: data.body,
            icon: '/images/logo-mark.svg',
            badge: '/images/logo-mark.svg',
            data: { url },
        }),
        // Tab yang sedang terbuka menampilkan banner "Muat ulang"; tidak ada muat ulang otomatis.
        self.clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((list) => list.forEach((c) => c.postMessage({ type: 'pms-notification' }))),
    ]));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/dashboard', self.location.origin);
    if (url.origin !== self.location.origin) return;
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
        const open = list.find((c) => new URL(c.url).origin === self.location.origin);
        return open ? open.focus().then((c) => c.navigate(url.href)) : self.clients.openWindow(url.href);
    }));
});
