@php($today = now(config('app.local_timezone'))->toDateString())
<x-layouts.app title="Tarif Pajak">
    <div class="page-head">
        <h1>Tarif Pajak</h1>
        @if (auth()->user()->role === \App\Enums\Role::STAFF_PIC)
            <x-form-modal name="tax-rate-new" title="Tambah versi tarif" subtitle="Tarif lama tidak diubah; versi baru berlaku mulai tanggal yang dipilih." icon="/tax-rates"
                          :action="route('tax-rates.store')" submit-label="Tambah versi tarif">
                <x-slot:trigger><a href="{{ route('tax-rates.create') }}" class="btn btn-primary ml-auto" aria-haspopup="dialog">+ Tambah versi tarif</a></x-slot:trigger>
                @include('tax-rates._fields', ['modal' => 'tax-rate-new'])
            </x-form-modal>
        @endif
    </div>

    <div class="table-card">
        <table class="w-full min-w-[760px]">
            <thead>
                <tr><th class="th">Jenis</th><th class="th">Nama</th><th class="th">Kualifikasi</th><th class="th num">Tarif (%)</th><th class="th">Berlaku dari</th><th class="th">Berlaku sampai</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($rates as $rate)
                    <tr>
                        <td class="td font-semibold text-slate-900">{{ $rate->tax_type }}</td>
                        <td class="td">{{ $rate->name }}</td>
                        <td class="td">{{ $rate->vendor_qualification ?? '—' }}</td>
                        <td class="td num">{{ \App\Support\Money::pct($rate->rate) }}</td>
                        <td class="td whitespace-nowrap">{{ $rate->effective_from->format('d/m/Y') }}</td>
                        <td class="td whitespace-nowrap">@if ($rate->effective_until){{ $rate->effective_until->format('d/m/Y') }}@elseif ($rate->effective_from->toDateString() > $today)<x-status-badge status="PLANNED" label="Belum berlaku" />@else<x-status-badge status="ACTIVE" label="Masih berlaku" />@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty"><div class="empty-msg">Belum ada tarif pajak.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="hint mt-4">Tarif tidak bisa diubah atau dihapus. Koreksi dilakukan dengan menambah versi baru.</p>
</x-layouts.app>
