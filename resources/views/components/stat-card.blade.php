{{-- Kartu satu angka. Nilai uang diformat Rupiah di sini; nilai mentahnya tetap string desimal. Nada (tone) mewarnai chip ikon dan garis atas, bukan angkanya. --}}
@props(['label', 'value', 'money' => true, 'hint' => null, 'tone' => 'text-brand-blue', 'icon' => 'wallet', 'tip' => null])
<div {{ $attributes->class(['card relative overflow-hidden p-4 sm:p-5', $tone]) }}>
    <span class="absolute inset-x-0 top-0 h-1 bg-current"></span>
    <div class="flex items-start justify-between gap-2">
        <p class="flex items-center gap-1 text-xs font-medium text-slate-500 sm:text-sm">{{ $label }}@if ($tip)<x-info-tip :label="$label">{{ $tip }}</x-info-tip>@endif</p>
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-current/10"><x-icon :name="$icon" /></span>
    </div>
    <p class="mt-2 text-[15px] font-semibold tracking-tight break-words text-slate-900 tabular-nums sm:text-xl lg:text-2xl">{{ $money ? \App\Support\Money::format($value) : $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
</div>
