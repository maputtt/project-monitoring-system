@php
    $dt = fn ($value) => \App\Support\LocalTime::format($value);
    $role = fn ($log) => $log->actor_name === null ? null : (\App\Enums\Role::tryFrom((string) $log->actor_role)?->label() ?? $log->actor_role);
    $filtered = collect($filters)->filter(fn ($v) => filled($v))->isNotEmpty();
@endphp
<x-layouts.app title="Audit Log">
    <div class="page-head">
        <h1>Audit Log</h1>
    </div>

    <form method="GET" action="{{ route('audit-logs.index') }}" class="card mb-6 grid grid-cols-2 gap-3 p-4 sm:p-5 lg:grid-cols-5 lg:items-end">
        <label class="lbl col-span-2 sm:col-span-1">User
            <select name="user_id" class="inp">
                <option value="">Semua user</option>
                @foreach ($users as $u)
                    <option value="{{ $u->id }}" @selected((string) ($filters['user_id'] ?? '') === (string) $u->id)>{{ $u->name }}</option>
                @endforeach
            </select>
            @error('user_id')<span class="text-xs text-rose-700">{{ $message }}</span>@enderror
        </label>
        <label class="lbl col-span-2 sm:col-span-1">Entitas
            <select name="entity_type" class="inp">
                <option value="">Semua entitas</option>
                @foreach ($entityTypes as $type)
                    <option value="{{ $type }}" @selected(($filters['entity_type'] ?? null) === $type)>{{ \App\Support\AuditSentence::entityLabel($type) }}</option>
                @endforeach
            </select>
            @error('entity_type')<span class="text-xs text-rose-700">{{ $message }}</span>@enderror
        </label>
        <label class="lbl col-span-2 sm:col-span-1">Aksi
            <select name="action" class="inp">
                <option value="">Semua aksi</option>
                @foreach ($actions as $action)
                    <option value="{{ $action->value }}" @selected(($filters['action'] ?? null) === $action->value)>{{ ucfirst($action->verb()) }}</option>
                @endforeach
            </select>
            @error('action')<span class="text-xs text-rose-700">{{ $message }}</span>@enderror
        </label>
        <label class="lbl">Tanggal dari
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="inp">
            @error('date_from')<span class="text-xs text-rose-700">{{ $message }}</span>@enderror
        </label>
        <label class="lbl">Tanggal sampai
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="inp">
            @error('date_to')<span class="text-xs text-rose-700">{{ $message }}</span>@enderror
        </label>
        <div class="col-span-2 flex gap-2 lg:col-span-5">
            <button type="submit" class="btn btn-primary flex-1 sm:flex-none">Terapkan</button>
            @if ($filtered)
                <a href="{{ route('audit-logs.index') }}" class="btn flex-1 sm:flex-none">Atur ulang</a>
            @endif
        </div>
    </form>

    @if ($logs->isEmpty())
        <div class="card empty">
            <p>{{ $filtered ? 'Tidak ada catatan yang cocok dengan filter.' : 'Belum ada catatan audit yang dapat Anda lihat.' }}</p>
            @if ($filtered)
                <a href="{{ route('audit-logs.index') }}" class="btn mt-4">Atur ulang filter</a>
            @endif
        </div>
    @else
        {{-- Satu daftar kalimat untuk ponsel dan desktop; teks panjang membungkus, tidak melebar. --}}
    <div class="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-card">
        @foreach ($logs as $log)
            <div class="min-w-0 px-4 py-3 sm:px-5">
                <p class="text-sm leading-6 text-slate-800 [overflow-wrap:anywhere]"><b class="font-semibold text-slate-900">{{ $log->actor_name ?? 'Sistem' }}</b> {{ $log->verb }} {{ $log->object['pre'] }}@if ($log->object['code'] !== null)@if ($log->href)<a href="{{ $log->href }}" class="font-semibold whitespace-nowrap text-brand-blue hover:underline">{{ $log->object['code'] }}</a>@else<span class="font-semibold whitespace-nowrap">{{ $log->object['code'] }}</span>@endif @endif{{ $log->object['post'] }}</p>
                <p class="mt-0.5 text-xs text-slate-600">{{ $dt($log->created_at) }}@if ($role($log)) · {{ $role($log) }}@endif</p>
                @if ($log->details !== [])
                    <ul class="mt-2 space-y-0.5 border-l-2 border-slate-200 pl-3 text-sm text-slate-600 [overflow-wrap:anywhere]">
                        @foreach ($log->details as $line)
                            <li>↳ {{ $line }}</li>
                        @endforeach
                    </ul>
                @endif
                @if (filled($log->reason))
                    <p class="mt-2 text-sm text-slate-700 [overflow-wrap:anywhere]">Alasan: {{ $log->reason }}</p>
                @endif
            </div>
        @endforeach
    </div>
    @endif

    <div class="mt-4">{{ $logs->links() }}</div>
</x-layouts.app>
