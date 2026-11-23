// Kunci aplikasi dengan Face ID / sidik jari (passkey) khusus ponsel. Server yang memutuskan sesi sah; di sini hanya UI dan upacara WebAuthn.
// Tidak ada keberhasilan yang ditampilkan sebelum server menjawab OK. Semua nilai biner dikirim sebagai base64url.
import { key64, csrf } from './push';

const b64 = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
// Akses storage bisa melempar (mode privat, diblokir), jadi selalu dibungkus.
const area = (name) => ({
    get: (k) => { try { return window[name].getItem(k); } catch { return null; } },
    set: (k, v) => { try { window[name].setItem(k, v); } catch { /* abaikan */ } },
    del: (k) => { try { window[name].removeItem(k); } catch { /* abaikan */ } },
});
const local = area('localStorage');
const tab = area('sessionStorage');
const LAST = 'pms_last_path';
const PW = 'pms_use_password';

// Hanya ponsel (layar sentuh); tablet dan desktop tetap memakai password biasa.
export const isPhone = () => 'PublicKeyCredential' in window && /iPhone|iPod|Android.+Mobile/i.test(navigator.userAgent) && matchMedia('(pointer: coarse)').matches;
window.pmsPhone = isPhone();

const post = (url, body, token = csrf()) => fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
    body: body ? JSON.stringify(body) : undefined,
});
const fail = (status) => Object.assign(new Error('passkey ' + status), { status });

// Opsi buka kunci: objek opsi (terkunci), 'active' (sesi masih sah), atau null (perangkat tidak dikenal / gagal).
async function unlockOptions() {
    const res = await fetch('/passkey/unlock-options', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
    return res.status === 204 ? 'active' : res.status === 200 ? res.json() : null;
}

async function assert(o) {
    const cred = await navigator.credentials.get({ publicKey: {
        challenge: key64(o.challenge),
        rpId: o.rpId,
        allowCredentials: o.allowCredentials.map((c) => ({ type: c.type, id: key64(c.id) })),
        userVerification: o.userVerification,
        timeout: o.timeout,
    } });
    const r = cred.response;
    const res = await post('/passkey/unlock', {
        id: cred.id, rawId: b64(cred.rawId), clientDataJSON: b64(r.clientDataJSON), authenticatorData: b64(r.authenticatorData),
        signature: b64(r.signature), userHandle: r.userHandle ? b64(r.userHandle) : null,
    }, o.csrf ?? csrf()); // token sesi tamu baru dari unlock-options; sesi lama sudah habis
    if (!res.ok) throw fail(res.status);
    return res.json();
}

const safePath = (p) => typeof p === 'string' && p.startsWith('/') && !p.startsWith('//') && !p.startsWith('/\\');
// Mulai dingin di iOS selalu membuka /dashboard; jika begitu, kembalikan ke halaman terakhir yang tersimpan.
function target(redirect) {
    let u;
    try { u = new URL(redirect ?? '/dashboard', location.origin); } catch { u = null; }
    if (!u || u.origin !== location.origin) return '/dashboard';
    const path = u.pathname.replace(/^\/+/, '/'); // "//host" tidak boleh menjadi URL protocol-relative
    const last = local.get(LAST);
    if (path === '/dashboard' && !u.search && safePath(last) && !last.startsWith('/login')) return last;
    return path + u.search;
}

const message = (e, auto) => {
    if (e?.name === 'NotAllowedError') return auto ? 'Ketuk tombol untuk membuka dengan Face ID.' : 'Verifikasi dibatalkan atau waktu habis. Coba lagi.';
    if (e?.status === 401 || e?.status === 403 || e?.status === 422) return 'Perangkat ini tidak dapat dibuka dengan Face ID. Gunakan password.';
    return 'Belum berhasil membuka. Periksa koneksi lalu coba lagi, atau gunakan password.';
};

// Logika bersama overlay dan halaman login; masing-masing mengisi done(res).
const lockCore = () => ({
    busy: false,
    leaving: false,
    msg: '',
    async unlock(auto = false, pre = null) {
        if (this.busy) return;
        this.busy = true;
        this.msg = '';
        try {
            const o = pre ?? await unlockOptions();
            if (o === 'active') { this.done(null); return; }
            if (!o) throw fail(401);
            this.done(await assert(o));
        } catch (e) {
            this.msg = message(e, auto);
        } finally {
            this.busy = false;
        }
    },
    // Muat ulang halaman ini lewat alur login biasa tanpa mode kunci, agar halaman tujuan tetap tersimpan.
    usePassword() {
        tab.set(PW, '1');
        location.reload();
    },
});

// Overlay kunci di halaman yang sudah terbuka: formulir yang belum disimpan tetap ada di memori; hanya token CSRF yang diganti.
export function passkeyLock() {
    return {
        ...lockCore(),
        open: false,
        opener: null,
        checking: false,
        init() {
            if (!isPhone()) return;
            if (location.pathname !== '/login') local.set(LAST, location.pathname + location.search);
            const check = () => { if (document.visibilityState === 'visible') this.check(); };
            document.addEventListener('visibilitychange', check);
            window.addEventListener('pageshow', (e) => { if (e.persisted) check(); });
        },
        async check() {
            if (this.open || this.checking) return;
            this.checking = true;
            try {
                const o = await unlockOptions().catch(() => null);
                if (!o || o === 'active') return;
                this.show();
                await this.unlock(true, o);
            } finally {
                this.checking = false;
            }
        },
        show() {
            this.opener = document.activeElement;
            this.open = true;
            this.msg = '';
            // Konten di belakang overlay tidak boleh bisa difokus keyboard / pembaca layar.
            [...document.body.children].forEach((el) => { if (el !== this.$el) el.inert = true; });
            this.$nextTick(() => this.$refs.unlockbtn?.focus());
        },
        done(res) {
            if (res?.csrf) {
                document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', res.csrf);
                document.querySelectorAll('input[name="_token"]').forEach((i) => { i.value = res.csrf; });
            }
            [...document.body.children].forEach((el) => { el.inert = false; });
            this.open = false;
            if (this.opener?.isConnected) this.opener.focus();
        },
    };
}

// Halaman /login pada muat penuh: jika perangkat dikenal, tampilkan mode kunci; selain itu form password biasa.
export function passkeyLogin() {
    return {
        ...lockCore(),
        mode: isPhone() ? 'checking' : 'password',
        async init() {
            if (this.mode !== 'checking') return;
            // Galat login / status dari server (mis. password salah, reset berhasil) harus terlihat di form password.
            if (tab.get(PW) || this.$el.dataset.passwordOnly !== undefined) { tab.del(PW); this.mode = 'password'; return; }
            const o = await unlockOptions().catch(() => null);
            if (o === 'active') { location.assign('/dashboard'); return; }
            if (!o) { this.mode = 'password'; return; }
            this.mode = 'lock';
            this.$nextTick(() => this.unlock(true, o));
        },
        done(res) {
            this.leaving = true;
            location.assign(target(res?.redirect));
        },
    };
}

// Penawaran / pendaftaran passkey di ponsel ini. state: hidden | offer | busy | done
export function passkeyOffer() {
    return {
        state: 'hidden',
        msg: '',
        key: '',
        inline: false,
        async init() {
            this.inline = this.$el.dataset.inline !== undefined;
            this.key = 'pms_passkey_' + (this.$el.dataset.userId ?? '');
            if (!isPhone() || (!this.inline && local.get(this.key))) return;
            try {
                if (!(await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable())) return;
            } catch { return; }
            this.state = 'offer';
        },
        skip() {
            local.set(this.key, 'skip');
            this.state = 'hidden';
        },
        async register() {
            this.state = 'busy';
            this.msg = '';
            try {
                const opt = await post('/passkeys/options');
                if (opt.status === 403) { // sesi hasil buka kunci: tidak boleh mendaftar, harus masuk dengan password
                    this.msg = (await opt.json().catch(() => ({}))).message ?? 'Masuk ulang dengan password untuk mendaftarkan perangkat.';
                    this.state = this.inline ? 'offer' : 'hidden';
                    return;
                }
                if (!opt.ok) throw fail(opt.status);
                const o = await opt.json();
                const cred = await navigator.credentials.create({ publicKey: {
                    challenge: key64(o.challenge),
                    rp: o.rp,
                    user: { id: key64(o.user.id), name: o.user.name, displayName: o.user.displayName },
                    pubKeyCredParams: o.pubKeyCredParams,
                    authenticatorSelection: o.authenticatorSelection,
                    attestation: o.attestation,
                    timeout: o.timeout,
                } });
                const r = cred.response;
                const publicKey = r.getPublicKey?.();
                if (!publicKey || !r.getAuthenticatorData) throw fail(0);
                const res = await post('/passkeys', {
                    id: cred.id, rawId: b64(cred.rawId), clientDataJSON: b64(r.clientDataJSON), authenticatorData: b64(r.getAuthenticatorData()),
                    publicKey: b64(publicKey), publicKeyAlgorithm: r.getPublicKeyAlgorithm(), name: /iPhone|iPod/.test(navigator.userAgent) ? 'iPhone' : 'Android',
                });
                if (!res.ok) throw fail(res.status);
                local.set(this.key, 'done');
                this.state = 'done';
                if (this.inline) setTimeout(() => location.reload(), 1200);
            } catch (e) {
                this.state = 'offer';
                this.msg = e?.name === 'NotAllowedError' ? 'Pengaktifan dibatalkan. Anda bisa mencoba lagi.' : 'Face ID belum aktif di perangkat ini. Coba lagi.';
            }
        },
    };
}
