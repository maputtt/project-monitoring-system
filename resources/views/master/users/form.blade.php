@php($editing = $user->exists)
<x-layouts.app :title="$editing ? 'Ubah pengguna' : 'Tambah pengguna'">
    <x-master-tabs />
    <a href="{{ route('master.users.index') }}" class="btn mb-3">← Pengguna</a>
    <h1 class="mb-4">{{ $editing ? 'Ubah pengguna' : 'Tambah pengguna' }}</h1>

    {{-- Divisi wajib untuk Staff/PIC dan Manager/GM, dan tidak berlaku untuk Direksi. Server tetap memeriksa ulang. --}}
    <form method="POST" action="{{ $editing ? route('master.users.update', $user) : route('master.users.store') }}"
          class="card max-w-2xl space-y-4">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        @include('master.users._fields', ['user' => $user, 'editing' => $editing, 'modal' => null])

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">{{ $editing ? 'Simpan perubahan' : 'Tambah pengguna' }}</button>
            <a href="{{ route('master.users.index') }}" class="btn">Batal</a>
        </div>
    </form>

    {{-- System Admin dapat mencabut Face ID ponsel pengguna ini; ponsel itu langsung keluar dari akun. --}}
    @if ($editing && isset($passkeys))
        <section class="card mt-6 max-w-2xl" aria-labelledby="passkeys-title">
            <h2 id="passkeys-title" class="text-lg">Perangkat Face ID</h2>
            <p class="mt-1 text-sm text-slate-600">Ponsel yang bisa membuka PMS pengguna ini tanpa password. Menghapus perangkat langsung mengeluarkan ponsel itu.</p>
            <ul class="mt-4 divide-y divide-slate-100">
                @forelse ($passkeys as $passkey)
                    @php($expired = filled($passkey->device_expires_at) && \Illuminate\Support\Carbon::parse($passkey->device_expires_at)->isPast())
                    <li class="flex flex-wrap items-center gap-3 py-3 text-sm">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-slate-900">{{ $passkey->name }} @if ($expired)<x-status-badge status="CANCELLED" label="Kedaluwarsa" />@else<x-status-badge status="ACTIVE" label="Aktif" />@endif</p>
                            <p class="text-xs text-slate-600">Didaftarkan {{ \App\Support\LocalTime::format($passkey->created_at) }} · Terakhir dipakai {{ filled($passkey->last_used_at) ? \App\Support\LocalTime::format($passkey->last_used_at) : 'belum pernah' }}</p>
                        </div>
                        <x-confirm-modal :action="url('/master/users/'.$user->getKey().'/passkeys/'.$passkey->id)" method="DELETE" title="Hapus perangkat Face ID?" confirm-label="Hapus"
                                         note="Ponsel ini langsung keluar dari akun {{ $user->name }} dan harus masuk dengan password lagi.">
                            <x-slot:trigger><button type="button" class="btn btn-warn" aria-label="Hapus perangkat {{ $passkey->name }} milik {{ $user->name }}">Hapus</button></x-slot:trigger>
                            <div><dt class="inline text-slate-500">Perangkat: </dt><dd class="inline font-medium">{{ $passkey->name }}</dd></div>
                        </x-confirm-modal>
                    </li>
                @empty
                    <li class="py-3 text-sm text-slate-600">Belum ada perangkat Face ID.</li>
                @endforelse
            </ul>
        </section>
    @endif
</x-layouts.app>
