@php($admin = auth()->user()->role === \App\Enums\Role::SYSTEM_ADMIN)
@php($pageUrl = \App\Models\SupportTicket::safePageUrl($ticket->page_url))
<x-layouts.app :title="'Tiket #'.$ticket->id">
    <a href="{{ route('support-tickets.index') }}" class="btn mb-3">← {{ $admin ? 'Semua tiket' : 'Tiket saya' }}</a>
    <div class="page-head">
        <div class="min-w-0">
            <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase">Tiket #{{ $ticket->id }}</p>
            <h1 class="break-words">{{ $ticket->title }}</h1>
        </div>
        <x-status-badge :status="$ticket->status" class="ml-auto" />
    </div>

    <div class="card max-w-2xl space-y-5">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt class="text-xs font-semibold text-slate-500">Pelapor</dt><dd class="text-slate-800">{{ $ticket->reporter->name }} ({{ $ticket->reporter->role->label() }})</dd></div>
            <div><dt class="text-xs font-semibold text-slate-500">Dilaporkan</dt><dd class="text-slate-800">{{ \App\Support\LocalTime::format($ticket->created_at) }}</dd></div>
            @if ($ticket->resolved_at)
                <div><dt class="text-xs font-semibold text-slate-500">Selesai</dt><dd class="text-slate-800">{{ \App\Support\LocalTime::format($ticket->resolved_at) }}</dd></div>
            @endif
            @if ($pageUrl)
                {{-- Hanya ditampilkan sebagai tautan, tidak pernah dialihkan otomatis. --}}
                <div class="min-w-0"><dt class="text-xs font-semibold text-slate-500">Halaman asal</dt><dd><a href="{{ $pageUrl }}" rel="noopener noreferrer" class="break-all text-brand-blue hover:underline">{{ $pageUrl }}</a></dd></div>
            @endif
        </dl>

        <div class="border-t border-slate-100 pt-4">
            <p class="text-xs font-semibold text-slate-500">Uraian masalah</p>
            <p class="mt-1 break-words whitespace-pre-line text-slate-800">{{ $ticket->description }}</p>
        </div>

        @if ($ticket->screenshot_path)
            <div>
                <p class="text-xs font-semibold text-slate-500">Tangkapan layar</p>
                <a href="{{ route('support-tickets.screenshot', $ticket) }}" target="_blank" rel="noopener" class="mt-1 inline-block rounded-lg focus-visible:ring-2 focus-visible:ring-brand-blue focus-visible:ring-offset-2 focus-visible:outline-none" aria-label="Buka tangkapan layar ukuran penuh (tab baru)">
                    <img src="{{ route('support-tickets.screenshot', $ticket) }}" alt="Tangkapan layar tiket #{{ $ticket->id }}" class="max-h-48 max-w-full rounded-lg border border-slate-200">
                </a>
                <p class="hint">Klik gambar untuk membuka ukuran penuh.</p>
            </div>
        @endif

        @if (! $admin && $ticket->admin_note)
            <div class="rounded-xl bg-slate-50 px-4 py-3"><p class="text-xs font-semibold text-slate-500">Catatan admin</p><p class="mt-1 break-words whitespace-pre-line text-slate-800">{{ $ticket->admin_note }}</p></div>
        @endif
    </div>

    @if ($admin)
        <form method="POST" action="{{ route('support-tickets.update', $ticket) }}" class="card mt-4 max-w-2xl space-y-4">
            @csrf
            @method('PUT')
            <h2 class="text-base font-semibold">Tindak lanjut admin</h2>
            <div>
                <label class="lbl" for="status">Status</label>
                <select id="status" name="status" class="inp">
                    @foreach (\App\Enums\SupportTicketStatus::cases() as $s)
                        <option value="{{ $s->value }}" @selected(old('status', $ticket->status->value) === $s->value)>{{ $s->label() }}</option>
                    @endforeach
                </select>
                @error('status')<p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="lbl" for="admin_note">Catatan admin</label>
                <textarea id="admin_note" name="admin_note" rows="4" maxlength="2000" class="inp">{{ old('admin_note', $ticket->admin_note) }}</textarea>
                <span class="hint">Terlihat oleh pelapor. Maksimal 2000 karakter.</span>
                @error('admin_note')<p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror
            </div>
            <div class="form-actions"><button type="submit" class="btn btn-primary">Simpan</button></div>
        </form>
    @endif
</x-layouts.app>
