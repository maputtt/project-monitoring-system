// Perilaku popup formulir (components/form-modal.blade.php): fokus masuk/kembali, jebakan Tab, kunci scroll.
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export default (startOpen = false) => ({
    open: startOpen,
    dirty: startOpen, // dibuka ulang setelah galat server: isian pengguna masih ada, jangan ditutup tak sengaja
    opener: null,
    submitting: false,

    init() {
        this.$watch('open', (on) => this.lock(on));
        if (startOpen) {
            this.lock(true);
            this.$nextTick(() => setTimeout(() => this.focusFirst(), 60));
        }
    },

    show(e) {
        this.opener = e.target.closest('a, button') ?? document.activeElement;
        this.dirty = false;
        this.open = true;
        this.$nextTick(() => setTimeout(() => this.focusFirst(), 60));
    },

    close() {
        this.open = false;
        // Tanpa pemicu (dibuka ulang oleh server): kembali ke pemicu pertama di komponen ini.
        (this.opener ?? this.$root.querySelector('a, button'))?.focus();
    },

    // Klik latar hanya menutup bila belum ada isian yang diubah; Escape, tombol X, dan Batal selalu menutup.
    backdrop() {
        if (!this.dirty) this.close();
        else this.$refs.panel.focus(); // klik latar memindahkan fokus ke body; kembalikan agar Escape dan jebakan Tab tetap bekerja
    },

    focusFirst() {
        const panel = this.$refs.panel;
        const target = panel.querySelector('[data-fm-errors]')
            ?? (matchMedia('(min-width: 640px)').matches ? panel.querySelector('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea') : null)
            ?? panel; // ponsel: jangan langsung memunculkan keyboard
        target.focus();
    },

    trap(e) {
        const items = [...this.$refs.panel.querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null);
        if (!items.length) return;
        const first = items[0];
        const last = items[items.length - 1];
        if (e.shiftKey && (document.activeElement === first || document.activeElement === this.$refs.panel)) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    },

    lock(on) {
        const html = document.documentElement;
        html.style.paddingRight = on ? `${innerWidth - html.clientWidth}px` : '';
        html.style.overflow = on ? 'hidden' : '';
    },
});
