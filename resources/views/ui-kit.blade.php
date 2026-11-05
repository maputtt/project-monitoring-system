{{-- Contoh layout dan komponen bersama (FND-01). Hanya tersedia di development, di /ui-kit. --}}
@php
    use App\Enums\{ApprovalStatus, BqStatus, InvoiceStatus, ProjectStatus, Role};
    use App\Support\Money;

    // Contoh PRD 6.3: SPK Rp671.151.608,00 termasuk PPN 11%, termin 20%, PPh 2,65%.
    $dpp = Money::percentOf(Money::removeTax('671151608.00', '11'), '20');
    $ppn = Money::percentOf($dpp, '11');
    $subtotal = Money::add($dpp, $ppn);
    $pph = Money::percentOf($dpp, '2.65');
@endphp
<x-layouts.app title="Kerangka aplikasi" :role="$role">
    <h1>Kerangka aplikasi</h1>
    <p class="mt-1 text-sm text-slate-600">Layout dan komponen yang dipakai semua halaman. Menu belum mengarah ke halaman nyata.</p>

    <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
        <span class="text-slate-600">Pratinjau menu sebagai:</span>
        @foreach (Role::cases() as $r)
            <a href="/ui-kit?role={{ $r->value }}" @class(['btn', 'btn-primary' => $r === $role])>{{ $r->label() }}</a>
        @endforeach
    </div>

    <h2 class="mt-6 mb-2 font-semibold">Kartu nilai</h2>
    <p class="mb-2 text-sm text-slate-600">Dihitung oleh kelas <code>Money</code> dari contoh PRD bagian 6.3.</p>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-stat-card label="DPP termin 20%" :value="$dpp" icon="/invoices" />
        <x-stat-card label="PPN 11%" :value="$ppn" icon="/tax-rates" tone="text-brand-orange" />
        <x-stat-card label="Subtotal tagihan" :value="$subtotal" tone="text-purple-700" icon="/projects" />
        <x-stat-card label="Uang Diterima (Nett)" :value="Money::sub($subtotal, $pph)" tone="text-emerald-700" icon="check" hint="Subtotal dikurangi PPh 2,65%" />
    </div>

    <h2 class="mt-6 mb-2 font-semibold">Badge status</h2>
    <div class="card space-y-3 text-sm">
        @foreach (['Project' => ProjectStatus::cases(), 'BQ' => BqStatus::cases(), 'Invoice' => InvoiceStatus::cases(), 'Penyesuaian dan Relokasi Budget' => ApprovalStatus::cases()] as $group => $cases)
            <div class="flex flex-wrap items-center gap-2">
                <span class="w-40 shrink-0 text-slate-500">{{ $group }}</span>
                @foreach ($cases as $status)
                    <x-status-badge :status="$status" />
                @endforeach
            </div>
        @endforeach
    </div>

    <h2 class="mt-6 mb-2 font-semibold">Tombol, form, dan modal konfirmasi</h2>
    <div class="card grid gap-4 sm:grid-cols-2">
        <div class="space-y-3">
            <label class="lbl">Contoh input
                <input class="inp" placeholder="Nomor invoice">
            </label>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn">Sekunder</button>
                <button type="button" class="btn btn-primary">Utama</button>
                <button type="button" class="btn btn-warn">Minta revisi</button>
                <button type="button" class="btn btn-primary" disabled>Nonaktif</button>
            </div>
        </div>
        <div class="space-y-3 text-sm">
            <p class="text-slate-600">Setiap aksi finansial lewat modal yang merangkum dampaknya. Minta revisi dan unlock mewajibkan alasan.</p>
            <div class="flex flex-wrap gap-2">
                <x-confirm-modal title="Setujui pembayaran" action="#" note="Invoice menjadi Lunas dan terkunci.">
                    <x-slot:trigger><button type="button" class="btn btn-primary">Contoh persetujuan</button></x-slot:trigger>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Uang Diterima (Nett)</dt><dd class="font-semibold">{{ Money::format(Money::sub($subtotal, $pph)) }}</dd></div>
                </x-confirm-modal>
                <x-confirm-modal title="Minta revisi" action="#" confirm-label="Kirim" :require-reason="true">
                    <x-slot:trigger><button type="button" class="btn btn-warn">Contoh minta revisi</button></x-slot:trigger>
                </x-confirm-modal>
            </div>
        </div>
    </div>
</x-layouts.app>
