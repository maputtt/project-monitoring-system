@php
    use App\Support\Money;
    $dmy = fn ($date) => $date ? \Illuminate\Support\Carbon::parse($date)->format('d/m/Y') : '—'; // format yang sama dengan detail project
    $filtered = filled($filters['status'] ?? null) || filled($filters['due_from'] ?? null) || filled($filters['due_to'] ?? null) || filled($filters['overdue'] ?? null) || filled($filters['late_to_bill'] ?? null) || filled($filters['outstanding'] ?? null) || filled($filters['department'] ?? null);
@endphp
<x-layouts.app title="Finance & Invoicing">
    <div class="page-head">
        <h1>Finance &amp; Invoicing</h1>
    </div>

    <form method="GET" action="{{ route('invoices.index') }}" @class(['card mb-6 grid gap-3 p-4 sm:grid-cols-2 sm:p-5 xl:items-end', 'xl:grid-cols-[14rem_10rem_10rem_minmax(0,1fr)_auto]' => ! $departments, 'xl:grid-cols-[14rem_9rem_10rem_10rem_minmax(0,1fr)_auto]' => $departments])>
        <label class="lbl">Status
            <select name="status" class="inp">
                <option value="">Semua status</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? null) === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </label>
        @if ($departments)
            <label class="lbl">Divisi
                <select name="department" class="inp">
                    <option value="">Semua divisi</option>
                    @foreach ($departments as $d)
                        <option value="{{ $d->value }}" @selected(($filters['department'] ?? null) === $d->value)>{{ $d->value }}</option>
                    @endforeach
                </select>
                @error('department')<span class="text-xs text-rose-700">{{ $message }}</span>@enderror
            </label>
        @endif
        <label class="lbl">Jatuh tempo dari
            <input type="date" name="due_from" value="{{ $filters['due_from'] ?? '' }}" class="inp">
            @error('due_from')<span class="text-xs text-rose-700">{{ $message }}</span>@enderror
        </label>
        <label class="lbl">Jatuh tempo sampai
            <input type="date" name="due_to" value="{{ $filters['due_to'] ?? '' }}" class="inp">
            @error('due_to')<span class="text-xs text-rose-700">{{ $message }}</span>@enderror
        </label>
        <div class="flex flex-wrap gap-x-4 gap-y-2 sm:col-span-2 sm:pb-2 xl:col-span-1">
        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="overdue" value="1" @checked(! empty($filters['overdue']))> Lewat jatuh tempo
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="late_to_bill" value="1" @checked(! empty($filters['late_to_bill']))> Terlambat ditagih
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="outstanding" value="1" @checked(! empty($filters['outstanding']))> Tagihan Belum Lunas
        </label>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary flex-1 sm:flex-none">Terapkan</button>
            @if ($filtered)
                <a href="{{ route('invoices.index') }}" class="btn flex-1 sm:flex-none">Atur ulang</a>
            @endif
        </div>
    </form>

    @if ($invoices->isEmpty())
        <div class="card empty">
            <p>{{ $filtered ? 'Tidak ada invoice yang cocok dengan filter.' : 'Belum ada invoice di project yang dapat Anda lihat.' }}</p>
            @if ($filtered)
                <a href="{{ route('invoices.index') }}" class="btn mt-4">Atur ulang filter</a>
            @endif
        </div>
    @else
        {{-- Ponsel: kartu bertumpuk seperti daftar project; mulai md: tabel. --}}
        <div class="grid gap-3 md:hidden">
            @foreach ($invoices as $invoice)
                @php($late = $invoice->due_date && $invoice->due_date < $today && ! in_array($invoice->workflow_status, $notOverdue, true))
                <a href="{{ route('projects.show', $invoice->project_id) }}#jadwal" class="rounded-xl border border-slate-200/80 bg-white p-4 transition duration-150 hover:border-brand-blue/30 hover:shadow-pop focus-visible:ring-2 focus-visible:ring-brand-blue focus-visible:ring-offset-2 focus-visible:outline-none">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-xs text-slate-600">{{ $invoice->project_code }} · Divisi {{ $invoice->department }}</p>
                            <p class="font-semibold break-words text-slate-900">{{ $invoice->project_name }}</p>
                            <p class="mt-1 text-xs break-words text-slate-600">Termin #{{ $invoice->sequence_number }} · {{ $invoice->invoice_number ?? 'Belum bernomor' }}</p>
                        </div>
                        <x-status-badge :status="\App\Enums\InvoiceStatus::from($invoice->workflow_status)" class="shrink-0" />
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                        <div><dt class="text-xs text-slate-500">Tgl penagihan</dt><dd class="text-slate-900">{{ $dmy($invoice->billing_date ?? $invoice->planned_billing_date) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Jatuh tempo</dt><dd class="text-slate-900">{{ $dmy($invoice->due_date) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Subtotal</dt><dd class="font-semibold text-slate-900 tabular-nums">{{ Money::format($invoice->subtotal_amount) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Nett</dt><dd class="font-semibold text-slate-900 tabular-nums">{{ Money::format($invoice->net_received_amount) }}</dd></div>
                    </dl>
                    @if ($late)
                        <p class="mt-3 text-xs font-semibold text-rose-700">Lewat jatuh tempo</p>
                    @endif
                    @if ($invoice->late_to_bill)
                        <p class="mt-3 text-xs font-semibold text-amber-800">Terlambat ditagih</p>
                    @endif
                </a>
            @endforeach
        </div>

        <div class="table-card hidden md:block">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="th px-3">Project</th>
                        <th class="th px-3">Status</th>
                        <th class="th px-3">Tgl penagihan</th>
                        <th class="th px-3">Jatuh tempo</th>
                        <th class="th num px-3">Subtotal</th>
                        <th class="th num px-3">Nett</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoices as $invoice)
                        <tr>
                            <td class="td min-w-44 px-3">
                                <a href="{{ route('projects.show', $invoice->project_id) }}#jadwal" class="font-semibold text-brand-blue hover:underline">{{ $invoice->project_code }}</a>
                                <p class="text-xs text-slate-600">{{ $invoice->project_name }} · Divisi {{ $invoice->department }}</p>
                                <p class="text-xs text-slate-600">Termin #{{ $invoice->sequence_number }} · {{ $invoice->invoice_number ?? 'Belum bernomor' }}</p>
                            </td>
                            <td class="td px-3"><x-status-badge :status="\App\Enums\InvoiceStatus::from($invoice->workflow_status)" /></td>
                            <td class="td whitespace-nowrap px-3">
                                {{ $dmy($invoice->billing_date ?? $invoice->planned_billing_date) }}
                                @if ($invoice->late_to_bill)
                                    <p class="text-xs font-semibold text-amber-800">Terlambat ditagih</p>
                                @endif
                            </td>
                            <td class="td whitespace-nowrap px-3">
                                {{ $dmy($invoice->due_date) }}
                                @if ($invoice->due_date && $invoice->due_date < $today && ! in_array($invoice->workflow_status, $notOverdue, true))
                                    <p class="text-xs font-semibold text-rose-700">Lewat jatuh tempo</p>
                                @endif
                            </td>
                            <td class="td num whitespace-nowrap px-3">{{ Money::format($invoice->subtotal_amount) }}</td>
                            <td class="td num whitespace-nowrap px-3">{{ Money::format($invoice->net_received_amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="mt-4">{{ $invoices->links() }}</div>
</x-layouts.app>
