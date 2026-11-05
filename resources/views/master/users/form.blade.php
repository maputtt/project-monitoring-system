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
</x-layouts.app>
