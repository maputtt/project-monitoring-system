<x-layouts.app title="Tambah versi tarif">
    <a href="{{ route('tax-rates.index') }}" class="btn mb-3">← Tarif Pajak</a>
    <h1 class="mb-4">Tambah versi tarif</h1>

    {{-- Kualifikasi hanya untuk PPh. Server tetap memeriksa ulang. --}}
    <form method="POST" action="{{ route('tax-rates.store') }}" class="card max-w-2xl space-y-4">
        @csrf

        @include('tax-rates._fields', ['modal' => null])

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Tambah versi tarif</button>
            <a href="{{ route('tax-rates.index') }}" class="btn">Batal</a>
        </div>
    </form>
</x-layouts.app>
