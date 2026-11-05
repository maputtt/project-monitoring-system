@php use App\Support\Money; @endphp
<x-layouts.app title="BQ overbudget">
    <div class="page-head">
        <div class="min-w-0">
            <a href="{{ route('dashboard', $department ? ['department' => $department] : []) }}" class="btn mb-3"><x-icon name="arrow" size="h-4 w-4" class="rotate-180" />Dashboard</a>
            <h1 class="mt-1">BQ overbudget</h1>
            <p class="text-sm text-slate-600">Item BQ yang melewati budget{{ $department ? ' · Divisi '.$department : '' }}. Pilih item untuk membuka project.</p>
        </div>
        <p class="ml-auto self-end text-sm text-slate-600">{{ number_format($items->total(), 0, ',', '.') }} item</p>
    </div>

    @if ($items->isEmpty())
        <div class="card empty" role="status"><p>Tidak ada item BQ yang melewati budget.</p></div>
    @else
        <ul class="grid gap-3">
            @foreach ($items as $item)
                <li>
                    <a href="{{ route('projects.show', $item->project_id) }}#item-bq" class="group block rounded-2xl border border-slate-200/70 bg-white p-4 shadow-card transition duration-200 hover:-translate-y-0.5 hover:border-brand-blue/30 hover:shadow-pop focus-visible:ring-2 focus-visible:ring-brand-blue focus-visible:ring-offset-2 focus-visible:outline-none sm:p-5">
                        <div class="grid gap-3 lg:grid-cols-[minmax(0,1.6fr)_repeat(4,minmax(0,1fr))] lg:items-center lg:gap-6">
                            <div class="min-w-0">
                                <p class="text-xs font-medium text-slate-600">{{ $item->project_code }} · {{ $item->project_name }}</p>
                                <p class="mt-0.5 font-semibold break-words text-slate-900 group-hover:text-brand-blue">{{ $item->item_code ? $item->item_code.' ' : '' }}{{ $item->item_name }}</p>
                            </div>
                            <dl class="contents text-sm">
                                <div class="grid grid-cols-[1fr_auto] items-baseline gap-2 lg:block"><dt class="text-xs text-slate-600">Nilai RAB</dt><dd class="font-semibold text-slate-900 tabular-nums">{{ Money::format($item->effective_budget) }}</dd></div>
                                <div class="grid grid-cols-[1fr_auto] items-baseline gap-2 lg:block"><dt class="text-xs text-slate-600">Nilai Vendor/Subkon</dt><dd class="font-semibold text-slate-900 tabular-nums">{{ Money::format($item->negotiated_value) }}</dd></div>
                                <div class="grid grid-cols-[1fr_auto] items-baseline gap-2 lg:block"><dt class="text-xs text-slate-600">Realisasi</dt><dd class="font-semibold text-slate-900 tabular-nums">{{ Money::format($item->actual_cost) }}</dd></div>
                                <div class="grid grid-cols-[1fr_auto] items-baseline gap-2 lg:block"><dt class="text-xs text-slate-600">Sisa</dt><dd @class(['font-semibold tabular-nums', 'text-rose-700' => Money::compare($item->sisa, 0) < 0, 'text-slate-900' => Money::compare($item->sisa, 0) >= 0])>{{ Money::format($item->sisa) }}</dd></div>
                            </dl>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="mt-4">{{ $items->links() }}</div>
</x-layouts.app>
