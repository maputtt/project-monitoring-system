// Pratinjau pajak popup submit invoice (projects/_invoice-form.blade.php): angka dihitung server lewat invoices.tax-preview; server menghitung ulang saat submit.
export default (url, date = '', q = '') => ({
    date, q, p: null, err: null, busy: false, n: 0, t: null,
    init() { this.load(); },
    load() { clearTimeout(this.t); this.n++; this.busy = true; // kunci submit sampai pratinjau selesai: request pratinjau yang masih jalan bisa menimpa flash error submit
        this.p = null; this.err = null; this.t = setTimeout(() => this.fetchPreview(), 300); },
    async fetchPreview() {
        if (! this.date || ! this.q) { this.busy = false; return; }
        const n = ++this.n; this.busy = true;
        try {
            const r = await fetch(url + '?billing_date=' + encodeURIComponent(this.date) + '&pph_qualification=' + encodeURIComponent(this.q), { headers: { Accept: 'application/json' } });
            const j = await r.json();
            if (n !== this.n) return;
            if (r.ok) this.p = j; else this.err = Object.values(j.errors ?? {}).flat()[0] ?? 'Tarif pajak untuk tanggal ini belum ada.';
        } catch (e) { if (n === this.n) this.err = 'Pratinjau pajak gagal dimuat. Periksa koneksi lalu ubah tanggal untuk mencoba lagi.'; }
        if (n === this.n) this.busy = false;
    },
    rp(s) { const [w, c] = s.split('.'); return 'Rp' + w.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + c; },
    pct(s) { s = String(s); return (s.includes('.') ? s.replace(/0+$/, '').replace(/\.$/, '') : s).replace('.', ','); },
});
