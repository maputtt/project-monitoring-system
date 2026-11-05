// Pratinjau form project (PRJ-01): cermin App\Support\Money dalam sen (BigInt), tanpa float.
// Hanya perkiraan; server menghitung ulang saat simpan dan angkanya yang resmi.
// Bentuk yang lolos sama dengan aturan decimal Laravel: ".5", "100." dan "+100" lolos; "-0" lolos, negatif lain tidak (min:0).
const dec = (v, scale) => {
    const m = /^([+-]?)(\d*)(?:\.(\d*))?$/.exec(String(v ?? '').trim());
    if (!m || m[2] + (m[3] ?? '') === '' || (m[3] ?? '').length > scale) {
        return null;
    }
    const n = BigInt(m[2] || '0') * 10n ** BigInt(scale) + BigInt((m[3] ?? '').padEnd(scale, '0'));

    return m[1] === '-' && n !== 0n ? null : n;
};
const rdiv = (n, d) => (2n * n + d) / (2n * d); // half-up; nilai tidak pernah negatif
const percentOf = (sen, pct4) => rdiv(sen * pct4, 1000000n);
const ratioPct = (part, whole) => (whole === 0n ? 0n : rdiv(part * 1000000n, whole));
const addTax = (sen, rate2) => rdiv(sen * (10000n + rate2), 10000n);
// Sama dengan Money::format, termasuk tanda minus di depan "Rp".
export const fmt = (sen) => {
    const abs = sen < 0n ? -sen : sen;

    return `${sen < 0n ? '-' : ''}Rp${(abs / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${(abs % 100n).toString().padStart(2, '0')}`;
};
// Teks tampilan Indonesia: koma desimal, nol di belakang dipangkas ("15%", "12,3457%"); isian form tetap titik desimal.
const fmtPct = (p) => `${p / 10000n}${(p % 10000n).toString().padStart(4, '0').replace(/0+$/, '').replace(/^(?=.)/, ',')}%`;
const none = '—';

export default (init) => ({
    ...init,

    get c() {
        const out = { error: null, rate: null, base: none, pct: none, margin: none, rab: none, lines: [], total: none, totalAmount: none };
        let base = dec(this.contract_value, 2);

        if (base !== null && this.contract_tax_mode === 'EXCLUSIVE_PPN') {
            const d = this.spk_date;
            const ppn = d && this.ppn.find((t) => t.effective_from <= d && (!t.effective_until || t.effective_until >= d));
            if (!ppn) {
                out.error = d ? 'Tarif PPN yang berlaku pada tanggal SPK belum ada. Tambahkan lebih dulu di menu Tarif Pajak.' : 'Isi tanggal SPK untuk menentukan tarif PPN.';
                base = null;
            } else {
                const rate = dec(ppn.rate, 2);
                out.rate = fmtPct(rate * 100n);
                base = addTax(base, rate);
            }
        }

        let margin = null;
        if (base !== null) {
            out.base = fmt(base);
            const byPct = this.margin_mode === 'PCT';
            const v = dec(this.margin_value, byPct ? 4 : 2);
            if (v !== null && byPct && v > 1000000n) {
                out.error = 'Target margin persen tidak boleh lebih dari 100.';
            } else if (v !== null && !byPct && v > base) {
                out.error = `Target margin tidak boleh melebihi dasar target margin (${fmt(base)}).`;
            } else if (v !== null) {
                margin = byPct ? percentOf(base, v) : v;
                out.pct = fmtPct(byPct ? v : ratioPct(v, base));
                out.margin = fmt(margin);
                out.rab = fmt(base - margin);
            }
        }

        const pcts = this.rows.map((r) => dec(r, 4));
        const valid = (p) => p !== null && p > 0n;
        const sum = pcts.reduce((s, p) => s + (p ?? 0n), 0n);
        out.count = pcts.length;
        out.total = fmtPct(sum);
        out.ok = pcts.every(valid) && sum === 1000000n;
        out.bad = !pcts.every(valid);

        // Baris terakhir menyerap selisih pembulatan, tetapi hanya bila total tepat 100% (sama seperti Money::allocate).
        const amounts = pcts.map((p) => (base !== null && valid(p) ? percentOf(base, p) : null));
        if (base !== null && out.ok) {
            amounts[amounts.length - 1] = base - amounts.slice(0, -1).reduce((s, a) => s + a, 0n);
        }
        out.lines = pcts.map((p, i) => ({ n: i + 1, pct: valid(p) ? fmtPct(p) : none, amount: amounts[i] === null ? none : fmt(amounts[i]) }));
        if (amounts.every((a) => a !== null)) {
            out.totalAmount = fmt(amounts.reduce((s, a) => s + a, 0n));
        }

        return out;
    },

    // Tanggal rencana tagih sejajar dengan rows (satu per termin); fokus memakai isian persentase karena baris juga memuat isian tanggal.
    add() {
        this.rows.push('');
        this.dates.push('');
        this.rowErr = {};
        this.dateErr = {};
        this.$nextTick(() => this.$refs.list.querySelectorAll('input[name="billing_percentages[]"]')[this.rows.length - 1].focus());
    },

    remove(i) {
        this.rows.splice(i, 1);
        this.dates.splice(i, 1);
        this.rowErr = {};
        this.dateErr = {};
        this.$nextTick(() => this.$refs.list.querySelectorAll('input[name="billing_percentages[]"]')[Math.min(i, this.rows.length - 1)].focus());
    },
});

// Pratinjau adjustment target margin (PRJ-04): margin base dan Effective RAB saat ini (rab_now) dari server, hasilnya perkiraan; server menghitung ulang saat simpan dan saat approve.
// Koreksi nilai kontrak memakai base = margin base baru dari server dan committed (total BQ disetujui): low = RAB baru di bawah committed (peringatan, bukan blokir).
export const adjustPreview = (init) => ({
    ...init,

    get c() {
        const base = dec(this.base, 2);
        const byPct = this.margin_mode === 'PCT';
        const v = dec(this.margin_value, byPct ? 4 : 2);
        if (v === null || (byPct ? v > 1000000n : v > base)) {
            return { pct: none, margin: none, rab: none, delta: none, bad: v !== null, low: false };
        }
        const margin = byPct ? percentOf(base, v) : v;
        const delta = base - margin - dec(this.rab_now, 2);

        return { pct: fmtPct(byPct ? v : ratioPct(v, base)), margin: fmt(margin), rab: fmt(base - margin), delta: (delta > 0n ? '+' : '') + fmt(delta), bad: false, low: this.committed != null && base - margin < dec(this.committed, 2) };
    },
});

// Koreksi Persentase Termin: jumlah persen (empat desimal, BigInt) harus sama dengan total semula dan minimal satu persen berubah.
// vals = isian per invoice_id, orig = persen tersimpan per invoice_id, total = jumlah persen semula. Hanya petunjuk; server memeriksa ulang.
export const billingPreview = (init) => ({
    ...init,

    get s() {
        const all = Object.entries(this.vals).map(([id, v]) => [dec(v, 4), dec(this.orig[id], 4)]);
        const required = dec(this.total, 4);
        if (all.some(([v]) => v === null || v <= 0n || v > 1000000n)) {
            return { sum: none, ok: false, msg: 'Isi semua persentase dengan angka lebih dari 0 dan paling banyak 100, maksimal empat desimal.' };
        }
        const sum = all.reduce((acc, [v]) => acc + v, 0n);
        if (sum !== required) {
            return { sum: fmtPct(sum), ok: false, msg: `Total harus tetap ${fmtPct(required)}.` };
        }
        if (all.every(([v, o]) => v === o)) {
            return { sum: fmtPct(sum), ok: false, msg: 'Belum ada persentase yang berubah.' };
        }

        return { sum: fmtPct(sum), ok: true, msg: 'Total sesuai.' };
    },
});
