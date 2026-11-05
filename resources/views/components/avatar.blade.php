{{-- Lingkaran inisial (maksimal dua huruf) untuk orang. Dekoratif: nama selalu tertulis di dekatnya. --}}
@props(['name', 'size' => 'h-9 w-9 text-xs'])
<span {{ $attributes->class([$size, 'inline-flex shrink-0 items-center justify-center rounded-full bg-linear-to-br from-brand-blue-soft to-white font-semibold text-brand-blue ring-1 ring-brand-blue/15']) }} aria-hidden="true">{{ collect(preg_split('/\s+/', trim($name)))->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->join('') }}</span>
