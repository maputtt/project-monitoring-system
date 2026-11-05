@php($editing = $pks->exists)
<x-layouts.app :title="$editing ? 'Ubah PKS' : 'Tambah PKS'">
    <x-master-tabs />
    <a href="{{ route('master.pks.index') }}" class="btn mb-3">← PKS</a>
    <h1 class="mb-4">{{ $editing ? 'Ubah PKS' : 'Tambah PKS' }}</h1>

    <form method="POST" action="{{ $editing ? route('master.pks.update', $pks) : route('master.pks.store') }}" class="card max-w-2xl space-y-4">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        @include('master.pks._fields', ['pks' => $pks, 'modal' => null])

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">{{ $editing ? 'Simpan perubahan' : 'Tambah PKS' }}</button>
            <a href="{{ route('master.pks.index') }}" class="btn">Batal</a>
        </div>
    </form>
</x-layouts.app>
