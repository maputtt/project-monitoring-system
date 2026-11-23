<x-layouts.app title="Perangkat Face ID">
    <div class="page-head">
        <div class="min-w-0">
            <h1>Perangkat Face ID</h1>
            <p class="mt-1 text-sm text-slate-600">Ponsel yang bisa membuka PMS dengan Face ID atau sidik jari tanpa password selama 30 hari. Menghapus perangkat langsung mengeluarkan ponsel itu dari akun Anda.</p>
        </div>
    </div>

    {{-- Pendaftaran perangkat ini: hanya tampil di ponsel yang mendukung (komponen menyembunyikan dirinya sendiri). --}}
    <section x-data="passkeyOffer" data-inline data-user-id="{{ auth()->id() }}" x-show="state !== 'hidden'" x-cloak aria-live="polite" class="card mb-6 text-sm">
        <div x-show="state !== 'done'">
            <p class="font-semibold text-slate-900">Aktifkan Face ID di ponsel ini</p>
            @if (\App\Support\WebAuthn::canRegister(request()))
                <p class="mt-1 text-slate-600">Anda akan diminta memverifikasi dengan Face ID atau sidik jari.</p>
                <p x-show="msg" x-text="msg" class="mt-2 font-medium text-rose-700" role="alert"></p>
                <button type="button" @click="register()" :disabled="state === 'busy'" class="btn btn-primary mt-3 w-full sm:w-auto"><span x-text="state === 'busy' ? 'Menunggu verifikasi…' : (msg ? 'Coba lagi' : 'Aktifkan di ponsel ini')"></span></button>
            @else
                <p class="mt-1 text-slate-600">Masuk ulang dengan password untuk mendaftarkan perangkat.</p>
                <form method="POST" action="{{ route('logout') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="btn btn-primary w-full sm:w-auto">Keluar dan masuk dengan password</button>
                </form>
            @endif
        </div>
        <p x-show="state === 'done'" class="font-medium text-emerald-800" role="status">Face ID aktif di perangkat ini. Memuat ulang daftar…</p>
    </section>

    <div class="table-card">
        <table class="w-full min-w-[640px]">
            <thead>
                <tr><th class="th">Perangkat</th><th class="th">Status</th><th class="th">Didaftarkan</th><th class="th">Terakhir dipakai</th><th class="th">Berlaku sampai</th><th class="th"><span class="sr-only">Aksi</span></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($passkeys as $passkey)
                    @php($expired = filled($passkey->device_expires_at) && \Illuminate\Support\Carbon::parse($passkey->device_expires_at)->isPast())
                    <tr>
                        <td class="td font-semibold text-slate-900">{{ $passkey->name }}</td>
                        <td class="td">@if ($expired)<x-status-badge status="CANCELLED" label="Kedaluwarsa" />@else<x-status-badge status="ACTIVE" label="Aktif" />@endif</td>
                        <td class="td whitespace-nowrap">{{ \App\Support\LocalTime::format($passkey->created_at) }}</td>
                        <td class="td whitespace-nowrap">{{ filled($passkey->last_used_at) ? \App\Support\LocalTime::format($passkey->last_used_at) : 'Belum pernah' }}</td>
                        <td class="td whitespace-nowrap">{{ \App\Support\LocalTime::format($passkey->device_expires_at, false) }}</td>
                        <td class="td text-right">
                            <x-confirm-modal :action="url('/passkeys/'.$passkey->id)" method="DELETE" title="Hapus perangkat Face ID?" confirm-label="Hapus"
                                             note="Ponsel ini langsung keluar dari akun Anda dan harus masuk dengan password lagi.">
                                <x-slot:trigger><button type="button" class="btn btn-warn" aria-label="Hapus perangkat {{ $passkey->name }}">Hapus</button></x-slot:trigger>
                                <div><dt class="inline text-slate-500">Perangkat: </dt><dd class="inline font-medium">{{ $passkey->name }}</dd></div>
                            </x-confirm-modal>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">
                        <div class="empty-msg">
                            <p class="font-semibold text-slate-700">Belum ada perangkat Face ID.</p>
                            <p class="mt-1">Aktifkan dari ponsel Anda agar bisa membuka PMS tanpa mengetik password.</p>
                        </div>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.app>
