{{-- Pesan server di bawah isian yang salah; di dalam label sehingga ikut terbaca bersama nama isian. id e-{name} dipakai aria-describedby isian. Error bag bernama (mis. bq-12) membuat id e-{bag}-{name}. --}}
@props(['name', 'bag' => 'default'])
@php($eid = 'e-'.($bag === 'default' ? '' : $bag.'-').$name)
@error($name, $bag)
    <span id="{{ $eid }}" class="mt-1.5 flex items-start gap-1.5 text-xs font-medium text-rose-700"><x-icon name="alert" size="mt-px h-3.5 w-3.5" />{{ $message }}</span>
@enderror
