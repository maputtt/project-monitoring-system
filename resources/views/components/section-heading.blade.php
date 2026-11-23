{{-- Judul seksi dashboard: h2 asli dalam pil dengan ikon di lingkaran berwarna merek. Ikon dekoratif (aria-hidden); teks tetap terbaca pembaca layar. Slot boleh memuat <x-info-tip>. --}}
@props(['id', 'icon' => '/dashboard'])
<h2 id="{{ $id }}" {{ $attributes->class(['section-heading']) }}>
    <span class="section-heading-icon" aria-hidden="true"><x-icon :name="$icon" size="h-4 w-4" /></span>
    <span>{{ $slot }}</span>
</h2>
