@php
    use App\Support\Money;
    $filtered = filled($filters['q'] ?? null) || filled($filters['status'] ?? null);
    $isStaff = auth()->user()->role === \App\Enums\Role::STAFF_PIC;
@endphp
<x-layouts.app title="PKS & Projects">
    <div class="page-head">
        <h1>PKS &amp; Projects</h1>
        @if ($isStaff)
            <a href="{{ route('projects.create') }}" class="btn btn-primary ml-auto">+ Project baru</a>
        @endif
    </div>

    <form method="GET" action="{{ route('projects.index') }}" class="card mb-6 grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_14rem_auto] sm:items-end sm:p-5">
        <label class="lbl">Cari
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Kode project, nama project, atau nomor PKS" class="inp">
        </label>
        <label class="lbl">Status
            <select name="status" class="inp">
                <option value="">Semua status</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? null) === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary flex-1 sm:flex-none">Terapkan</button>
            @if ($filtered)
                <a href="{{ route('projects.index') }}" class="btn flex-1 sm:flex-none">Atur ulang</a>
            @endif
        </div>
    </form>

    @forelse ($pks as $group)
        @php($rows = $projectsByPks[$group->id] ?? collect())
        <details class="card mb-4 p-4 sm:p-5" @if ($filtered || $loop->first) open @endif>
            <summary class="flex cursor-pointer list-none items-center gap-3 rounded-lg focus-visible:ring-2 focus-visible:ring-brand-blue focus-visible:ring-offset-2 focus-visible:outline-none [&::-webkit-details-marker]:hidden">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-blue-soft text-brand-blue"><x-icon name="/projects" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block font-semibold break-words text-slate-900">{{ $group->pks_number }} — {{ $group->name }}</span>
                    <span class="block text-sm text-slate-600">{{ $group->client_name }}</span>
                </span>
                <span class="badge shrink-0">{{ $rows->count() }} project</span>
                <x-icon name="chevron" size="h-4 w-4" class="text-slate-400 transition [details[open]_&]:rotate-180" />
            </summary>
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                @foreach ($rows as $project)
                    <a href="{{ route('projects.show', $project) }}" class="rounded-xl border border-slate-200/80 bg-white p-4 transition duration-150 hover:-translate-y-0.5 hover:border-brand-blue/30 hover:shadow-pop focus-visible:ring-2 focus-visible:ring-brand-blue focus-visible:ring-offset-2 focus-visible:outline-none">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-xs text-slate-600">{{ $project->project_code }} · Divisi {{ $project->department->value }}</p>
                                <p class="font-semibold break-words text-slate-900">{{ $project->project_name }}</p>
                            </div>
                            <x-status-badge :status="$project->status" class="shrink-0" />
                        </div>
                        <p class="mt-3 text-xs text-slate-500">Nilai Project</p>
                        <p class="text-sm font-semibold text-slate-900 tabular-nums">{{ Money::format($project->initial_contract_value) }}</p>
                    </a>
                @endforeach
            </div>
        </details>
    @empty
        <div class="card empty">
            <p>{{ $filtered ? 'Tidak ada project yang cocok dengan pencarian atau filter.' : 'Belum ada project di divisi Anda.' }}</p>
            @if ($filtered)
                <a href="{{ route('projects.index') }}" class="btn mt-4">Atur ulang pencarian</a>
            @elseif ($isStaff)
                <a href="{{ route('projects.create') }}" class="btn btn-primary mt-4">+ Project baru</a>
            @endif
        </div>
    @endforelse

    <div class="mt-4">{{ $pks->links() }}</div>
</x-layouts.app>
