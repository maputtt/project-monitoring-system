@php($editing = $contact->exists)
<x-layouts.app :title="$editing ? 'Ubah PIC Bouwheer' : 'Tambah PIC Bouwheer'">
    <x-master-tabs />
    <a href="{{ route('master.bouwheer-contacts.index') }}" class="btn mb-3">← PIC Bouwheer</a>
    <h1 class="mb-4">{{ $editing ? 'Ubah PIC Bouwheer' : 'Tambah PIC Bouwheer' }}</h1>

    <form method="POST" action="{{ $editing ? route('master.bouwheer-contacts.update', $contact) : route('master.bouwheer-contacts.store') }}" class="card max-w-2xl space-y-4">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        @include('master.contacts._fields', ['contact' => $contact, 'modal' => null])

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">{{ $editing ? 'Simpan perubahan' : 'Tambah PIC' }}</button>
            <a href="{{ route('master.bouwheer-contacts.index') }}" class="btn">Batal</a>
        </div>
    </form>
</x-layouts.app>
