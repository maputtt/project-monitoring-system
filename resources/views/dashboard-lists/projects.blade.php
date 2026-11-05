@php use App\Support\Money; @endphp
<x-layouts.app :title="$title">
    <div class="page-head">
        <div class="min-w-0">
            <a href="{{ route('dashboard', $department ? ['department' => $department] : []) }}" class="btn mb-3"><x-icon name="arrow" size="h-4 w-4" class="rotate-180" />Dashboard</a>
            <h1 class="mt-1">{{ $title }}</h1>
            <p class="text-sm text-slate-600">{{ $hint }}{{ $department ? ' · Divisi '.$department : '' }}</p>
        </div>
        <p class="ml-auto self-end text-sm text-slate-600">{{ number_format($projects->total(), 0, ',', '.') }} project</p>
    </div>

    @if ($projects->isEmpty())
        <div class="card empty" role="status"><p>Tidak ada project di daftar ini.</p></div>
    @else
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
                                <div class="grid grid-cols-[1fr_auto] items-baseline gap-2 lg:block"><dt class="text-xs text-slate-600">RAB Perubahan</dt><dd class="font-semibold text-slate-900 tabular-nums">{{ Money::format($project->effective_rab) }}</dd></div>
                                <div class="grid grid-cols-[1fr_auto] items-baseline gap-2 lg:block"><dt class="text-xs text-slate-600">Target Margin</dt><dd class="font-semibold text-slate-900 tabular-nums">{{ Money::format($project->target_margin_amount) }}</dd></div>
                            </dl>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="mt-4">{{ $projects->links() }}</div>
</x-layouts.app>
