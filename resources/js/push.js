// Web push sisi klien. Hanya kunci publik VAPID yang dipakai; setiap perubahan status baru dianggap berhasil setelah server menjawab OK.
export const key64 = (s) => Uint8Array.from(atob((s + '='.repeat((4 - (s.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
export const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const supported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
const ios = () => /iPhone|iPad|iPod/.test(navigator.userAgent) || (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1);

export function registerServiceWorker() {
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});
}

// Mengirim (atau mengirim ulang) langganan perangkat ini ke server; login di perangkat yang sama memindahkan kepemilikannya.
export async function syncSubscription(vapidKey) {
    const reg = await navigator.serviceWorker.ready;
    const sub = (await reg.pushManager.getSubscription())
        ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key64(vapidKey) }));
    const res = await fetch('/push-subscriptions', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify(sub.toJSON()),
    });
    if (!res.ok) throw new Error('push-subscriptions ' + res.status);
}

// state: hidden | ios | idle | busy | active | denied | error
export default () => ({
    state: 'hidden',
    async init() {
        const key = this.$el.dataset.vapidKey;
        if (!key) return;
        if (!supported()) { this.state = ios() ? 'ios' : 'hidden'; return; }
        if (Notification.permission === 'denied') { this.state = 'denied'; return; }
        if (Notification.permission !== 'granted') { this.state = 'idle'; return; }
        this.state = 'busy';
        try { await syncSubscription(key); this.state = 'active'; } catch { this.state = 'error'; }
    },
    async enable() {
        this.state = 'busy';
        try {
            if (await Notification.requestPermission() !== 'granted') { this.state = Notification.permission === 'denied' ? 'denied' : 'idle'; return; }
            await syncSubscription(this.$el.dataset.vapidKey);
            this.state = 'active';
        } catch { this.state = 'error'; }
    },
});
