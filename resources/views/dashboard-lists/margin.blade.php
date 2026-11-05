@php
    use App\Support\Money;
    // Persen dua desimal half-up, tanpa nol di belakang: "40.00" menjadi "40%", "33.33" menjadi "33,33%".
    $ratio = fn ($amount, $base) => str_replace('.', ',', rtrim(rtrim(Money::ratioPct($amount, $base, 2), '0'), '.')).'%';
@endphp
<x-layouts.app title="Margin Realisasi">
    <div class="page-head">
        <div class="min-w-0">
            <a href="{{ route('dashboard', $department ? ['department' => $department] : []) }}" class="btn mb-3"><x-icon name="arrow" size="h-4 w-4" class="rotate-180" />Dashboard</a>
            <h1 class="mt-1">Margin Realisasi</h1>
            <p class="text-sm text-slate-600">Project tutup buku dengan laba aktual{{ $department ? ' · Divisi '.$department : '' }}. Persen = rupiah dibagi dasar margin; total memakai rata-rata tertimbang.</p>
        </div>
        <p class="ml-auto self-end text-sm text-slate-600">{{ number_format($totals->n, 0, ',', '.') }} project</p>
    </div>

    @if ($projects->isEmpty())
        <div class="card empty" role="status"><p>Belum ada project tutup buku.</p></div>
    @else
        <section class="card mb-4" aria-labelledby="total-margin">
            <h2 id="total-margin">Total</h2>
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-xs text-slate-600">Nilai Project</dt><dd class="font-semibold text-slate-900 tabular-nums">{{ Money::format($totals->contract) }}</dd></div>
                <div><dt class="text-xs text-slate-600">Target Margin</dt><dd class="font-semibold text-slate-900 tabular-nums">{{ Money::format($totals->target) }} · {{ $ratio($totals->target, $totals->base) }}</dd></div>
                <div><dt class="text-xs text-slate-600">Margin Realisasi</dt><dd class="font-semibold text-slate-900 tabular-nums">{{ Money::format($totals->actual) }} · {{ $ratio($totals->actual, $totals->base) }}</dd></div>
            </dl>
        </section>
        <ul class="grid gap-3">
            @foreach ($projects as $project)
                <li>
                    <a href="{{ route('projects.show', $project->id) }}" class="group block rounded-2xl border border-slate-200/70 bg-white p-4 shadow-card transition duration-200 hover:-translate-y-0.5 hover:border-brand-blue/30 hover:shadow-pop focus-visible:ring-2 focus-visible:ring-brand-blue focus-visible:ring-offset-2 focus-visible:outline-none sm:p-5">
                        <div class="grid gap-3 lg:grid-cols-[minmax(0,1.6fr)_repeat(3,minmax(0,1fr))] lg:items-center lg:gap-6">
                            <div class="flex min-w-0 items-start justify-between gap-3 lg:block">
                                <div class="min-w-0">
                                    <p class="text-xs font-medium text-slate-600">{{ $project->project_code }} · Divisi {{ $project->department->value }}</p>
                                    <p class="mt-0.5 font-semibold break-words text-slate-900 group-hover:text-brand-blue">{{ $project->project_name }}</p>
                                </div>
                                <x-status-badge :status="$project->status" class="shrink-0 lg:mt-2" />
                            </div>
                            <dl class="contents text-sm">
                                <div class="grid grid-cols-[1fr_auto] items-baseline gap-2 lg:block"><dt class="text-xs text-slate-600">Nilai Project</dt><dd class="font-semibold text-slate-900 tabular-nums">{{ Money::format($project->initial_contract_value) }}</dd></div>
                                <div class="grid grid-cols-[1fr_auto] items-baseline gap-2 lg:block"><dt class="text-xs text-slate-600">Target Margin</dt><dd class="font-semibold text-slate-900 tabular-nums">{{ Money::format($project->target_margin_amount) }} · {{ $ratio($project->target_margin_amount, $project->margin_base_amount) }}</dd></div>
                                <div class="grid grid-cols-[1fr_auto] items-baseline gap-2 lg:block"><dt class="text-xs text-slate-600">Margin Realisasi</dt><dd @class(['font-semibold tabular-nums', 'text-rose-700' => Money::compare($project->actual_profit_amount, 0) < 0, 'text-slate-900' => Money::compare($project->actual_profit_amount, 0) >= 0])>{{ Money::format($project->actual_profit_amount) }} · {{ $ratio($project->actual_profit_amount, $project->margin_base_amount) }}</dd></div>
                            </dl>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="mt-4">{{ $projects->links() }}</div>
</x-layouts.app>
