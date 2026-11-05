{{-- Kartu KPI dashboard: chip ikon berwarna, angka besar, satu baris konteks. Warna hanya penghias; label dan angka selalu tertulis. Dengan href, seluruh kartu menjadi satu tautan (drill-down); aria-label bila teks kartu perlu dirangkum. --}}
@props(['label', 'value', 'money' => true, 'context' => null, 'tone' => 'blue', 'icon' => 'wallet', 'href' => null, 'aria' => null, 'tip' => null])
@php
    $chip = [
        'blue' => 'bg-brand-blue-soft text-brand-blue ring-brand-blue/15',
        'orange' => 'bg-orange-50 text-orange-700 ring-orange-600/15',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/15',
        'purple' => 'bg-purple-50 text-purple-700 ring-purple-600/15',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-600/15',
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-600/15',
        'slate' => 'bg-slate-100 text-slate-600 ring-slate-500/15',
    ][$tone] ?? '';
@endphp
{{-- Tombol info berada di luar tautan (tombol di dalam <a> tidak valid dan akan ikut menavigasi). --}}
<div class="relative min-w-0">
@if ($tip)<x-info-tip :label="$label" class="absolute top-2 right-2 z-10">{{ $tip }}</x-info-tip>@endif
@if ($href)<a href="{{ $href }}" @if ($aria) aria-label="{{ $aria }}" @endif class="block h-full min-w-0 rounded-2xl focus-visible:ring-2 focus-visible:ring-brand-blue focus-visible:ring-offset-2 focus-visible:outline-none">@endif
<div {{ $attributes->class(['card min-w-0 p-4 transition duration-200 hover:-translate-y-0.5 hover:shadow-pop sm:p-5', 'h-full']) }}>
    <div class="flex items-center gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset {{ $chip }}"><x-icon :name="$icon" /></span>
        <p @class(['min-w-0 text-sm font-medium text-slate-600', 'pr-7' => $tip])>{{ $label }}</p>
    </div>
    <p class="mt-3 text-[1.4rem] leading-tight font-semibold tracking-tight break-words text-slate-900 tabular-nums sm:text-2xl">{{ $money ? \App\Support\Money::format($value) : $value }}</p>
    @if ($context)
        <p class="mt-1.5 text-xs text-slate-600">{{ $context }}</p>
    @endif
</div>
@if ($href)</a>@endif
</div>
