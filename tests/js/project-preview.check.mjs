// Pemeriksaan pratinjau form project: angka harus sama dengan yang dihitung server (App\Support\Money).
// Jalankan: node tests/js/project-preview.check.mjs
import assert from 'node:assert/strict';
import projectPreview, { adjustPreview, billingPreview, fmt } from '../../resources/js/project-preview.js';

let n = 0;
const same = (actual, expected, why) => {
    assert.deepEqual(actual, expected, why);
    n++;
};
const ppn = [{ rate: '11.00', effective_from: '2022-04-01', effective_until: null }];
const calc = (over) => projectPreview({ ppn, rows: ['100'], spk_date: '2026-10-14', contract_value: '', contract_tax_mode: 'NON_PPN', margin_mode: 'PCT', margin_value: '0', ...over }).c;
const amounts = (c) => c.lines.map((l) => l.amount);

// Contoh PRD: INCLUSIVE_PPN, margin 15%, termin 20/50/30.
let c = calc({ contract_value: '671151608.00', contract_tax_mode: 'INCLUSIVE_PPN', margin_value: '15', rows: ['20', '50', '30'] });
same([c.base, c.pct, c.margin, c.rab, c.ok, c.total], ['Rp671.151.608,00', '15%', 'Rp100.672.741,20', 'Rp570.478.866,80', true, '100%'], 'PRD');
same([amounts(c), c.totalAmount], [['Rp134.230.321,60', 'Rp335.575.804,00', 'Rp201.345.482,40'], 'Rp671.151.608,00'], 'PRD termin');

// EXCLUSIVE_PPN: tarif 11% berlaku pada tanggal SPK; tanpa tarif berlaku, pesan sama dengan server.
c = calc({ contract_value: '180000000.00', contract_tax_mode: 'EXCLUSIVE_PPN', margin_value: '12', rows: ['40', '60'] });
same([c.rate, c.base, c.margin, c.rab, amounts(c)], ['11%', 'Rp199.800.000,00', 'Rp23.976.000,00', 'Rp175.824.000,00', ['Rp79.920.000,00', 'Rp119.880.000,00']], 'EXCLUSIVE');
c = calc({ contract_value: '180000000.00', contract_tax_mode: 'EXCLUSIVE_PPN', spk_date: '2020-01-01' });
same([c.base, c.error], ['—', 'Tarif PPN yang berlaku pada tanggal SPK belum ada. Tambahkan lebih dulu di menu Tarif Pajak.'], 'tanpa tarif');

// Margin nominal: persen dibulatkan half-up empat desimal; nominal tidak ikut bergeser.
c = calc({ contract_value: '1000000000.00', margin_mode: 'AMOUNT', margin_value: '123456789.12' });
same([c.pct, c.margin, c.rab], ['12,3457%', 'Rp123.456.789,12', 'Rp876.543.210,88'], 'AMOUNT QA');
same(calc({ contract_value: '30.00', margin_mode: 'AMOUNT', margin_value: '20.00' }).pct, '66,6667%', 'AMOUNT 66.6667');
same(calc({ contract_value: '16000.00', margin_mode: 'AMOUNT', margin_value: '1' }).pct, '0,0063%', 'AMOUNT tie naik');
c = calc({ contract_value: '100.00', margin_mode: 'AMOUNT', margin_value: '100.01' });
same([c.error, c.margin], ['Target margin tidak boleh melebihi dasar target margin (Rp100,00).', '—'], 'AMOUNT melebihi base');
same(calc({ contract_value: '100.00', margin_value: '100.0001' }).error, 'Target margin persen tidak boleh lebih dari 100.', 'PCT lebih dari 100');

// Baris terakhir menyerap selisih pembulatan.
c = calc({ contract_value: '100.00', rows: ['33.3333', '33.3333', '33.3334'] });
same([amounts(c), c.totalAmount, c.ok], [['Rp33,33', 'Rp33,33', 'Rp33,34'], 'Rp100,00', true], '33.3333');
c = calc({ contract_value: '100000.01', contract_tax_mode: 'EXCLUSIVE_PPN', margin_mode: 'AMOUNT', margin_value: '33333.33', rows: ['33.3333', '33.3333', '33.3334'] });
same([c.base, c.pct, c.rab, amounts(c)], ['Rp111.000,01', '30,03%', 'Rp77.666,68', ['Rp36.999,97', 'Rp36.999,97', 'Rp37.000,07']], 'EXCLUSIVE + AMOUNT');
c = calc({ contract_value: '100.00', rows: ['50', '40'] });
same([c.ok, c.total, c.bad, amounts(c)], [false, '90%', false, ['Rp50,00', 'Rp40,00']], 'total bukan 100');
same([calc({ rows: ['50', ''] }).bad, calc({ rows: ['50', '0'] }).bad], [true, true], 'baris kosong atau nol');

// Kontrak 0: tanpa pembagian nol.
c = calc({ contract_value: '0', margin_mode: 'AMOUNT', margin_value: '0', rows: ['60', '40'] });
same([c.base, c.pct, c.margin, c.rab, amounts(c)], ['Rp0,00', '0%', 'Rp0,00', 'Rp0,00', ['Rp0,00', 'Rp0,00']], 'kontrak 0');

// Bentuk angka yang diterima server (aturan decimal Laravel): ".5", "100.", "+100".
same(['.5', '100.', '+100', '100'].map((v) => calc({ contract_value: v }).base), ['Rp0,50', 'Rp100,00', 'Rp100,00', 'Rp100,00'], 'bentuk angka');
same(['', '.', '1,5', '1.234', 'abc', '-5', '1e3'].map((v) => calc({ contract_value: v }).base), Array(7).fill('—'), 'angka ditolak');
c = calc({ contract_value: '100.00', rows: ['.5', '99.5'] });
same([c.ok, amounts(c)], [true, ['Rp0,50', 'Rp99,50']], 'persen .5');

// Format sama dengan Money::format, termasuk negatif.
same([fmt(0n), fmt(1n), fmt(-1n), fmt(-123456n), fmt(100000000000n)], ['Rp0,00', 'Rp0,01', '-Rp0,01', '-Rp1.234,56', 'Rp1.000.000.000,00'], 'format');

// Adjustment target margin (PRJ-04): margin base 199.800.000,00, Effective RAB saat ini 175.824.000,00 (12%).
const adj = (over) => adjustPreview({ base: '199800000.00', rab_now: '175824000.00', margin_mode: 'PCT', margin_value: '', ...over }).c;
same(adj({ margin_value: '10' }), { pct: '10%', margin: 'Rp19.980.000,00', rab: 'Rp179.820.000,00', delta: '+Rp3.996.000,00', bad: false, low: false }, 'adjustment PCT naik');
same(adj({ margin_mode: 'AMOUNT', margin_value: '27972000' }), { pct: '14%', margin: 'Rp27.972.000,00', rab: 'Rp171.828.000,00', delta: '-Rp3.996.000,00', bad: false, low: false }, 'adjustment AMOUNT turun');
same(adj({ margin_value: '12' }).delta, 'Rp0,00', 'adjustment tanpa selisih');
same([adj({ margin_value: '100.0001' }).bad, adj({ margin_mode: 'AMOUNT', margin_value: '199800000.01' }).bad, adj({ margin_value: 'abc' }).bad], [true, true, false], 'adjustment ditolak');

// Koreksi nilai kontrak (contoh 1 desain): base baru 666.000.000,00, margin 15% = 99.900.000,00, RAB baru 566.100.000,00; low hanya bila RAB baru < committed.
const fix = (committed) => adjustPreview({ base: '666000000.00', rab_now: '943500000.00', committed, margin_mode: 'PCT', margin_value: '15.0000' }).c;
same([fix('566100000.01').low, fix('566100000.00').low, fix('0.00').low], [true, false, false], 'koreksi kontrak: peringatan RAB di bawah committed');
same([fix('0.00').rab, fix('0.00').delta], ['Rp566.100.000,00', '-Rp377.400.000,00'], 'koreksi kontrak: RAB baru');

// Koreksi Persentase Termin: total harus tetap 70% dan minimal satu persen berubah.
const bill = (vals) => billingPreview({ total: '70.0000', orig: { 2: '30.0000', 3: '40.0000' }, vals }).s;
same([bill({ 2: '33.3333', 3: '36.6667' }).ok, bill({ 2: '33.3333', 3: '36.6667' }).sum], [true, '70%'], 'koreksi termin: total sama');
same([bill({ 2: '33.3333', 3: '36.6666' }).ok, bill({ 2: '33.3333', 3: '36.6666' }).msg], [false, 'Total harus tetap 70%.'], 'koreksi termin: total berbeda');
same(bill({ 2: '30', 3: '40' }).msg, 'Belum ada persentase yang berubah.', 'koreksi termin: tidak berubah');
same([bill({ 2: '0', 3: '70' }).ok, bill({ 2: '', 3: '70' }).ok, bill({ 2: '30.00001', 3: '39.99999' }).ok], [false, false, false], 'koreksi termin: isian ditolak');

console.log(`project-preview.check: ${n} pemeriksaan lolos`);
