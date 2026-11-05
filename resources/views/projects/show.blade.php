@php
    use App\Support\Money;
    $pctIn = fn (string $value) => str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value; // nilai isian form: titik desimal, format yang divalidasi server
    $pct = fn (string $value) => Money::pct($value); // teks tampilan: koma desimal (Indonesia)
    $signed = fn (string $value) => (str_starts_with($value, '-') || $value === '0.00' ? '' : '+').Money::format($value);
    $role = auth()->user()->role;
    $readOnly = in_array($role, [\App\Enums\Role::DIREKSI, \App\Enums\Role::SYSTEM_ADMIN], true);
    $locked = $project->status->isBaselineLocked();
    $closed = $summary['actual_profit'] !== null;
    $exclusive = $project->contract_tax_mode === 'EXCLUSIVE_PPN';
    $corrected = $project->contract_value !== $project->initial_contract_value;
    // [label, nilai, nada, ikon, keterangan]. Setelah closing, Sisa RAB menjadi Sisa Akhir (Tutup Buku) dan Projected Profit menjadi Actual Profit (UX layar 5).
    $cards = array_values(array_filter([
        ['Nilai Project', $project->initial_contract_value, 'text-brand-blue', 'wallet', $corrected ? 'nilai tertagih '.Money::format($project->contract_value).' (termin dibatalkan)' : 'Nilai SPK; tidak berubah', 'Nilai SPK awal project. Tidak berubah walau ada koreksi nilai tertagih.'],
        $exclusive ? ['Nilai Project + PPN', $project->margin_base_amount, 'text-brand-blue', 'wallet', 'dasar perhitungan target margin', 'Rumus: Nilai Project + PPN. Dipakai sebagai Dasar Margin.'] : null,
        ['RAB Awal', $project->initial_rab, 'text-slate-500', 'wallet', $locked ? 'Baseline terkunci' : 'dihitung sistem', 'Batas biaya saat aktivasi. Rumus: Dasar Margin − Target Margin Awal.'],
        ['RAB Perubahan', $project->effective_rab, 'text-brand-blue', 'wallet', $project->effective_rab !== $project->initial_rab ? 'Berbeda dari RAB Awal: penyesuaian target margin sudah disetujui' : 'Berubah hanya jika penyesuaian target margin disetujui', 'Batas biaya terkini. Rumus: Dasar Margin − Target Margin Perubahan.'],
        ['Target Margin Awal ('.$pct($summary['initial_margin_pct']).'%)', $summary['initial_margin_amount'], 'text-slate-500', 'wallet', 'saat aktivasi; dihitung sistem', 'Target margin saat aktivasi. Persen = Target Margin ÷ Dasar Margin × 100%.'],
        ['Target Margin Perubahan ('.$pct($project->target_margin_pct).'%)', $project->target_margin_amount, 'text-purple-700', 'wallet', 'dihitung sistem', 'Target margin terkini setelah penyesuaian disetujui. Persen = Target Margin ÷ Dasar Margin × 100%.'],
        $closed
            ? ['Sisa Akhir (Tutup Buku)', $summary['final_sisa'], 'text-slate-700', 'lock', 'final, terkunci', 'Sisa RAB saat tutup buku, sudah terkunci.']
            : ['Sisa RAB', $summary['unallocated'], 'text-emerald-600', 'wallet', 'RAB Perubahan dikurangi total Nilai RAB BQ', 'Rumus: RAB Perubahan − Total Nilai RAB semua item BQ. Berkurang saat item BQ ditambah atau relokasi ke item; bertambah saat relokasi dikembalikan atau item diarsipkan.'],
        ['Pending Biaya BQ', $summary['pending'], 'text-purple-700', '/approvals', 'pending, belum saldo resmi', 'Biaya item BQ yang sudah diajukan tapi belum disetujui.'],
        ['Total Nilai BQ', $summary['bq_effective'], 'text-brand-blue', 'wallet', 'Vendor/Subkon '.Money::format($summary['bq_vendor']).' · Realisasi '.Money::format($summary['bq_actual']), 'Total Nilai RAB seluruh item BQ yang belum diarsipkan.'],
        $closed
            ? ['Actual Profit', $summary['actual_profit'], str_starts_with($summary['actual_profit'], '-') ? 'text-rose-600' : 'text-slate-700', 'lock', 'final, terkunci'.(str_starts_with($summary['actual_profit'], '-') ? ' (rugi)' : ''), 'Laba final saat tutup buku. Rumus: Target Margin + Sisa Akhir (Tutup Buku).']
            : ['Projected Profit', $summary['projected_profit'], 'text-emerald-600', 'check', 'dapat berubah', 'Perkiraan laba. Rumus: Target Margin + RAB Perubahan − biaya BQ yang diajukan atau disetujui (item Normal: Nilai Vendor/Subkon; item Operasional: Nilai RAB).'],
    ]));
    $bq = [
        ['Total RAB awal BQ', $summary['bq_baseline']],
        ['Total Nilai RAB BQ', $summary['bq_effective']],
        ['Committed cost (BQ approved)', $summary['committed']],
        ['Cadangan operasional (approved)', $summary['operational_reserve']],
    ];
    $info = [
        ['PKS', ($pks?->pks_number ?? '—').($pks ? ' — '.$pks->name : '')],
        ['Klien', $pks?->client_name ?? '—'],
        ['PIC Bouwheer', $contact ? $contact->name.' ('.$contact->company_name.')' : '—'],
        ['SPK', $project->spk_number.' ('.$project->spk_date->format('d/m/Y').')'],
        ['Lokasi', $project->location],
        ['Periode', $project->start_date->format('d/m/Y').' s.d. '.$project->end_date->format('d/m/Y')],
        ['Nilai Project', Money::format($project->initial_contract_value)],
        ['Mode pajak kontrak', ['INCLUSIVE_PPN' => 'Termasuk PPN', 'EXCLUSIVE_PPN' => 'Belum termasuk PPN', 'NON_PPN' => 'Tanpa PPN'][$project->contract_tax_mode].($project->margin_base_ppn_rate_snapshot !== null ? ' (PPN '.$pct($project->margin_base_ppn_rate_snapshot).'%)' : '')],
        ['Tipe pembayaran', ['TERMIN' => 'Termin', 'MONTHLY' => 'Bulanan'][$project->payment_type] ?? $project->payment_type],
    ];
    $committedBudget = $contractCorrection['committed_budget'] ?? $project->committedBudget(); // peringatan RAB di bawah total BQ yang disetujui
    $line = 'flex justify-between gap-3';
    $todayWib = now(config('app.local_timezone'))->toDateString();
@endphp
<x-layouts.app :title="$project->project_name">
    @if ($role !== \App\Enums\Role::SYSTEM_ADMIN)
        <a href="{{ route('projects.index') }}" class="btn mb-4"><x-icon name="arrow" size="h-4 w-4" class="rotate-180" />PKS &amp; Projects</a>
    @endif

    <div class="page-head items-end">
        <div class="min-w-0">
            <p class="text-sm text-slate-600">{{ $project->project_code }} · Divisi {{ $project->department->value }} · {{ $project->location }}</p>
            <h1 class="mt-0.5 break-words">{{ $project->project_name }}</h1>
        </div>
        <x-status-badge :status="$project->status" class="mb-1.5" />
        @if ($readOnly)
            <span class="badge mb-1.5">Hanya baca</span>
        @endif

        <div class="ml-auto flex flex-wrap gap-2">
            <a href="{{ route('projects.print', $project) }}" target="_blank" rel="noopener" class="btn"><x-icon name="print" size="h-4 w-4" />Export PDF<span class="sr-only"> (terbuka di tab baru)</span></a>
            @if ($can['edit'])
                <a href="{{ route('projects.edit', $project) }}" class="btn">Ubah draft</a>
            @endif
            @if ($can['submit'])
                <x-confirm-modal title="Ajukan project untuk aktivasi?" :action="route('projects.submit', $project)" confirm-label="Ajukan"
                    note="Data project tidak bisa diubah selama menunggu keputusan Manager/GM.">
                    <x-slot:trigger><button type="button" class="btn btn-primary">Submit untuk aktivasi</button></x-slot:trigger>
                    <div class="{{ $line }}"><dt>Status</dt><dd class="font-semibold">{{ \App\Enums\ProjectStatus::DRAFT->label() }} → {{ \App\Enums\ProjectStatus::SUBMITTED->label() }}</dd></div>
                    <div class="{{ $line }}"><dt>Nilai Project</dt><dd class="font-semibold">{{ Money::format($project->initial_contract_value) }}</dd></div>
                    <div class="{{ $line }}"><dt>RAB Perubahan</dt><dd class="font-semibold">{{ Money::format($project->effective_rab) }}</dd></div>
                </x-confirm-modal>
            @endif
            @if ($can['activate'])
                <x-confirm-modal title="Aktifkan project?" :action="route('projects.activate', $project)" confirm-label="Aktifkan"
                    note="RAB Awal, dasar target margin, dan target margin terkunci sebagai baseline. BQ Operasional ikut disetujui.">
                    <x-slot:trigger><button type="button" class="btn btn-primary">Aktivasi</button></x-slot:trigger>
                    <div class="{{ $line }}"><dt>Status</dt><dd class="font-semibold">{{ \App\Enums\ProjectStatus::SUBMITTED->label() }} → {{ \App\Enums\ProjectStatus::ACTIVE->label() }}</dd></div>
                    <div class="{{ $line }}"><dt>RAB Awal</dt><dd class="font-semibold">{{ Money::format($project->initial_rab) }}</dd></div>
                    <div class="{{ $line }}"><dt>Dasar target margin</dt><dd class="font-semibold">{{ Money::format($project->margin_base_amount) }}</dd></div>
                    <div class="{{ $line }}"><dt>Target margin</dt><dd class="font-semibold">{{ Money::format($project->target_margin_amount) }}</dd></div>
                </x-confirm-modal>
            @endif
            @if ($can['complete'])
                <x-confirm-modal title="Tandai project selesai?" :action="route('projects.complete', $project)" confirm-label="Tandai selesai"
                    note="Project menjadi Selesai. BQ, invoice, dan realisasi biaya masih bisa diselesaikan; langkah berikutnya financial closing, yang mengunci seluruh nilai finansial.">
                    <x-slot:trigger><button type="button" class="btn btn-primary">Tandai project selesai</button></x-slot:trigger>
                    <div class="{{ $line }}"><dt>Status</dt><dd class="font-semibold">{{ \App\Enums\ProjectStatus::ACTIVE->label() }} → {{ \App\Enums\ProjectStatus::COMPLETED->label() }}</dd></div>
                </x-confirm-modal>
            @endif
            @if ($can['revise'])
                <x-confirm-modal title="Minta revisi project?" :action="route('projects.request-revision', $project)" confirm-label="Minta revisi" :require-reason="true"
                    note="Project kembali ke status Draf agar Staff/PIC dapat memperbaikinya. Alasan tercatat di audit log.">
                    <x-slot:trigger><button type="button" class="btn btn-warn">Minta revisi</button></x-slot:trigger>
                    <div class="{{ $line }}"><dt>Status</dt><dd class="font-semibold">{{ \App\Enums\ProjectStatus::SUBMITTED->label() }} → {{ \App\Enums\ProjectStatus::DRAFT->label() }}</dd></div>
                    <div class="{{ $line }}"><dt>Nilai Project</dt><dd class="font-semibold">{{ Money::format($project->initial_contract_value) }}</dd></div>
                </x-confirm-modal>
            @endif
        </div>
    </div>

    @if ($project->status === \App\Enums\ProjectStatus::SUBMITTED && ! $can['activate'] && ! $can['revise'])
        <p class="mb-6 flex items-start gap-3 rounded-2xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800" role="status">
            <x-icon name="alert" size="mt-0.5 h-5 w-5" class="text-blue-600" />Menunggu keputusan Manager/GM. Data project tidak bisa diubah selama menunggu.
        </p>
    @endif

    <section class="mb-6" aria-labelledby="ringkasan">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <h2 id="ringkasan">Ringkasan finansial</h2>
            <span @class(['badge', 'bg-slate-800 text-white ring-slate-900/20' => $locked])>{{ $locked ? 'Baseline terkunci' : 'Baseline belum terkunci' }}</span>
        </div>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:[&_p.tabular-nums]:text-lg">
            @foreach ($cards as [$label, $value, $tone, $icon, $hint, $tip])
                <x-stat-card :label="$label" :value="$value" :tone="$tone" :icon="$icon" :hint="$hint" :tip="$tip" />
            @endforeach
        </div>
    </section>

    @if ($closing)
        @php
            $met = collect($closing['checks'])->filter(fn ($c) => $c['blockers'] === [])->count();
            $loss = str_starts_with($closing['actual_profit'], '-');
        @endphp
        <section class="card mb-6" aria-labelledby="closing">
            <div class="mb-1 flex flex-wrap items-center gap-2">
                <h2 id="closing">Financial closing</h2>
                <span @class(['badge', 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $closing['ready'], 'bg-rose-50 text-rose-700 ring-rose-600/20' => ! $closing['ready']])>{{ $closing['ready'] ? 'Siap ditutup' : 'Belum siap ditutup' }} · {{ $met }}/{{ count($closing['checks']) }} syarat</span>
            </div>
            <p class="mb-4 text-sm text-slate-600">Penutupan buku mengunci seluruh nilai finansial project dan tidak bisa dibatalkan.</p>
            @foreach ($errors->get('closing') as $message)
                <p class="mb-2 flex items-start gap-1.5 text-sm font-medium text-rose-700" role="alert"><x-icon name="alert" size="mt-0.5 h-4 w-4 shrink-0" />{{ $message }}</p>
            @endforeach
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($closing['checks'] as $check)
                    @php $ok = $check['blockers'] === []; @endphp
                    <li class="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                        <span @class(['mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full', 'bg-emerald-50 text-emerald-600' => $ok, 'bg-rose-50 text-rose-600' => ! $ok])><x-icon :name="$ok ? 'check' : 'alert'" size="h-4 w-4" /></span>
                        <div class="min-w-0">
                            <p class="font-semibold text-slate-900">{{ $check['label'] }}</p>
                            <p @class(['text-xs font-medium', 'text-emerald-700' => $ok, 'text-rose-700' => ! $ok])>{{ $ok ? 'Terpenuhi' : 'Belum terpenuhi' }}</p>
                            @if (! $ok)
                                <ul class="mt-1 list-disc space-y-0.5 pl-4 text-xs text-slate-700">
                                    @foreach ($check['blockers'] as $blocker)<li class="break-words">{{ $blocker }}</li>@endforeach
                                </ul>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            <dl class="mt-4 space-y-1.5 rounded-xl bg-slate-50 p-4 text-sm ring-1 ring-slate-100">
                <div class="{{ $line }}"><dt>Target margin</dt><dd class="font-semibold tabular-nums">{{ Money::format($project->target_margin_amount) }}</dd></div>
                <div class="{{ $line }}"><dt>Sisa Akhir (Tutup Buku) (pratinjau)</dt><dd class="font-semibold tabular-nums">{{ Money::format($closing['final_sisa']) }}</dd></div>
                <div class="{{ $line }}"><dt>Actual Profit (pratinjau)</dt><dd @class(['font-semibold tabular-nums', 'text-rose-700' => $loss])>{{ Money::format($closing['actual_profit']) }}@if ($loss) <span class="text-xs">(rugi)</span>@endif</dd></div>
            </dl>
            <div class="mt-4">
                @if ($closing['ready'])
                    <x-confirm-modal title="Tutup buku project?" :action="route('projects.close', $project)" confirm-label="Tutup buku"
                        note="Tidak bisa dibatalkan. Seluruh BQ menjadi Ditutup dan semua nilai finansial project terkunci permanen. Syarat diperiksa ulang oleh server.">
                        <x-slot:trigger><button type="button" class="btn btn-primary"><x-icon name="lock" size="h-4 w-4" />Tutup buku project</button></x-slot:trigger>
                        <div class="{{ $line }}"><dt>Status</dt><dd class="font-semibold">{{ \App\Enums\ProjectStatus::COMPLETED->label() }} → {{ \App\Enums\ProjectStatus::FINANCIALLY_CLOSED->label() }}</dd></div>
                        <div class="{{ $line }}"><dt>RAB Perubahan</dt><dd class="font-semibold">{{ Money::format($project->effective_rab) }}</dd></div>
                        <div class="{{ $line }}"><dt>Sisa Akhir (Tutup Buku)</dt><dd class="font-semibold">{{ Money::format($closing['final_sisa']) }}</dd></div>
                        <div class="{{ $line }}"><dt>Target margin</dt><dd class="font-semibold">{{ Money::format($project->target_margin_amount) }}</dd></div>
                        <div class="{{ $line }}"><dt>Actual Profit</dt><dd @class(['font-semibold', 'text-rose-700' => $loss])>{{ Money::format($closing['actual_profit']) }}@if ($loss) (rugi)@endif</dd></div>
                    </x-confirm-modal>
                @else
                    <button type="button" class="btn btn-primary" disabled aria-disabled="true" aria-describedby="closing-hint"><x-icon name="lock" size="h-4 w-4" />Tutup buku project</button>
                    <p id="closing-hint" class="hint">Aktif setelah semua syarat terpenuhi. Angka pratinjau belum final selama ada syarat yang belum terpenuhi.</p>
                @endif
            </div>
        </section>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card" aria-labelledby="kontrak">
            <h2 id="kontrak" class="mb-3">Kontrak</h2>
            <dl class="divide-y divide-slate-100 text-sm">
                @foreach ($info as [$label, $value])
                    <div class="{{ $line }} py-2.5 first:pt-0 last:pb-0"><dt class="text-slate-500">{{ $label }}</dt><dd class="text-right font-semibold break-words">{{ $value }}</dd></div>
                @endforeach
            </dl>
        </section>

        <section class="card" aria-labelledby="rincian-bq">
            <h2 id="rincian-bq" class="mb-3">Rincian BQ</h2>
            <dl class="divide-y divide-slate-100 text-sm">
                @foreach ($bq as [$label, $value])
                    <div class="{{ $line }} py-2.5 first:pt-0 last:pb-0"><dt class="text-slate-500">{{ $label }}</dt><dd class="text-right font-semibold tabular-nums">{{ Money::format($value) }}</dd></div>
                @endforeach
            </dl>
            <p class="hint mt-3">BQ berstatus Diarsipkan tidak dihitung. Biaya dari BQ yang belum dibuat bernilai Rp0,00.</p>
        </section>
    </div>

    @php
        $projectDraft = $project->status === \App\Enums\ProjectStatus::DRAFT;
        $anyEditable = $bqItems->contains('editable', true);
        $normalFields = [['item_name', 'Nama pekerjaan', 'text'], ['item_code', 'Kode item (opsional)', 'text'], ['vendor_quote_amount', 'Harga vendor (Rp, opsional)', 'decimal'], ['baseline_budget', 'Nilai RAB (Rp)', 'decimal'], ['negotiated_value', 'Nilai Vendor/Subkon (Rp)', 'decimal']];
        $requiredFields = ['item_name', 'baseline_budget', 'negotiated_value'];
    @endphp
    <section class="mt-6" aria-labelledby="item-bq">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h2 id="item-bq">Item BQ</h2>
            @if ($can['addBq'])
                @include('projects._bq-form', ['item' => null])
            @endif
        </div>
        @if (Money::compare($summary['unallocated'], '0') < 0)
            <p class="mb-3 flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="status">
                <x-icon name="alert" size="mt-0.5 h-5 w-5 shrink-0" class="text-rose-600" />
                <span><strong>Over-alokasi {{ Money::format(Money::sub('0', $summary['unallocated'])) }}.</strong> Total Nilai RAB BQ sudah melebihi RAB Perubahan. Selama over-alokasi, BQ tidak dapat disetujui dan project tidak dapat diaktivasi. Kecilkan atau arsipkan item yang belum disetujui (Manager dapat "Minta revisi" bila sudah diajukan), atau naikkan RAB Perubahan lewat penyesuaian target margin.</span>
            </p>
        @endif
        <div class="table-card">
            <table class="w-full md:min-w-[720px] [&_.td]:px-3 [&_.th]:px-3">
                <thead><tr><th class="th">Item</th><th class="th num hidden md:table-cell">Nilai RAB</th><th class="th num hidden md:table-cell">Nilai Vendor/Subkon</th><th class="th num hidden md:table-cell">Realisasi</th><th class="th num hidden md:table-cell">Sisa</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($bqItems as $item)
                        @php $operational = $item->item_type === 'OPERATIONAL'; $overbudget = $item->is_overbudget; @endphp
                        <tr @class(['bg-brand-blue-soft/40' => $operational])>
                            <td @class(['td min-w-60 break-words', 'border-l-4 border-brand-blue' => $operational])>
                                @if ($item->item_code)<span class="block text-xs font-semibold text-slate-500">{{ $item->item_code }}</span>@endif
                                <span class="block font-medium text-slate-900">{{ $item->item_name }}</span>@if ($operational)<span class="badge mt-1 bg-brand-blue-soft text-brand-blue ring-brand-blue/20">Cadangan operasional</span>@endif
                                @if ($operational)
                                    <p class="mt-0.5 text-xs text-slate-600">{{ $projectDraft ? 'Isi selama project berstatus Draf. Terkunci setelah aktivasi; perubahan berikutnya lewat Relokasi Budget.' : 'Terkunci sejak aktivasi. Perubahan lewat Relokasi Budget.' }}</p>
                                @endif
                                {{-- Di ponsel kolom angka disembunyikan dan angkanya ikut di kolom Item (grid 2x2) supaya Sisa tidak tergeser keluar layar. --}}
                                <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs md:hidden">
                                    <div><dt class="text-slate-500">Nilai RAB</dt><dd class="font-semibold tabular-nums text-slate-900">{{ Money::format($item->effective_budget) }}@if ($item->baseline_budget !== $item->effective_budget)<span class="block font-normal text-slate-500">awal {{ Money::format($item->baseline_budget) }}</span>@endif</dd></div>
                                    <div><dt class="text-slate-500">Nilai Vendor/Subkon</dt><dd class="font-semibold tabular-nums text-slate-900">{{ $operational ? '—' : Money::format($item->negotiated_value) }}</dd></div>
                                    <div><dt class="text-slate-500">Realisasi</dt><dd class="font-semibold tabular-nums text-slate-900">{{ Money::format($item->actual_cost) }}</dd></div>
                                    <div><dt class="text-slate-500">Sisa</dt><dd @class(['font-semibold tabular-nums', 'text-slate-900' => ! $overbudget, 'text-rose-700' => $overbudget])>{{ Money::format($item->sisa) }}</dd></div>
                                </dl>
                                <span class="mt-1.5 flex flex-wrap gap-1.5">
                                    <x-status-badge :status="\App\Enums\BqStatus::from($item->status)" />
                                    @if ($overbudget)<span class="badge bg-rose-50 text-rose-700 ring-rose-600/20">Overbudget</span>@endif
                                </span>
                                @if ($item->status === 'SUBMITTED')
                                    <p class="mt-0.5 text-xs font-medium text-blue-700">Pending, belum saldo resmi</p>
                                @elseif ($item->status === 'REVISION_REQUIRED')
                                    <p class="mt-0.5 text-xs font-medium text-amber-800">Dikembalikan untuk revisi{{ $item->canSubmit ? '. Perbaiki lalu Submit ulang.' : '.' }}</p>
                                    @if ($item->revisionReason)
                                        <p class="mt-0.5 break-words text-xs text-amber-800">Alasan: {{ $item->revisionReason }}</p>
                                    @endif
                                @endif
                                @if (($item->canSubmit ?? false) || ($item->canDecide ?? false) || ($item->editable ?? false) || ($item->canActual ?? false) || $item->canArchive)
                                    <span class="mt-2 flex flex-wrap items-center gap-2">
                                        @if ($item->canSubmit ?? false)
                                            <x-confirm-modal :title="'Submit '.$item->item_name.'?'" :action="route('bq-items.submit', $item->id)" confirm-label="Submit"
                                                note="Nilai Vendor/Subkon item ini menjadi Pending Biaya BQ, tetapi belum mengubah saldo resmi sampai Manager/GM menyetujui.">
                                                <x-slot:trigger><button type="button" class="btn btn-primary px-3 py-1.5 text-[13px]" aria-label="Submit {{ $item->item_name }}">Submit</button></x-slot:trigger>
                                                <div class="{{ $line }}"><dt>Status</dt><dd class="font-semibold">{{ \App\Enums\BqStatus::from($item->status)->label() }} → {{ \App\Enums\BqStatus::SUBMITTED->label() }}</dd></div>
                                                <div class="{{ $line }}"><dt>Nilai Vendor/Subkon</dt><dd class="font-semibold">{{ Money::format($item->negotiated_value) }}</dd></div>
                                            </x-confirm-modal>
                                        @endif
                                        @if ($item->canDecide ?? false)
                                            <x-confirm-modal :title="'Setujui '.$item->item_name.'?'" :action="route('bq-items.approve', $item->id)" confirm-label="Setujui"
                                                note="Nilai Vendor/Subkon menjadi biaya komitmen dan mengurangi saldo resmi. Ditolak bila total Nilai RAB semua item BQ (termasuk yang masih diajukan/draf) melebihi RAB Perubahan.">
                                                <x-slot:trigger><button type="button" class="btn btn-primary px-3 py-1.5 text-[13px]" aria-label="Setujui {{ $item->item_name }}">Setujui</button></x-slot:trigger>
                                                <div class="{{ $line }}"><dt>Status</dt><dd class="font-semibold">{{ \App\Enums\BqStatus::SUBMITTED->label() }} → {{ \App\Enums\BqStatus::APPROVED->label() }}</dd></div>
                                                <div class="{{ $line }}"><dt>Nilai Vendor/Subkon</dt><dd class="font-semibold">{{ Money::format($item->negotiated_value) }}</dd></div>
                                                <div class="{{ $line }}"><dt>Nilai RAB</dt><dd class="font-semibold">{{ Money::format($item->effective_budget) }}</dd></div>
                                            </x-confirm-modal>
                                            <x-confirm-modal :title="'Minta revisi '.$item->item_name.'?'" :action="route('bq-items.request-revision', $item->id)" confirm-label="Minta revisi" :require-reason="true"
                                                note="Item kembali ke Staff/PIC untuk diperbaiki. Alasan tercatat di audit log.">
                                                <x-slot:trigger><button type="button" class="btn btn-warn px-3 py-1.5 text-[13px]" aria-label="Minta revisi {{ $item->item_name }}">Minta revisi</button></x-slot:trigger>
                                                <div class="{{ $line }}"><dt>Status</dt><dd class="font-semibold">{{ \App\Enums\BqStatus::SUBMITTED->label() }} → {{ \App\Enums\BqStatus::REVISION_REQUIRED->label() }}</dd></div>
                                            </x-confirm-modal>
                                        @endif
                                        @if ($item->editable ?? false)
                                            @include('projects._bq-form')
                                        @endif
                                        @if ($item->canArchive)
                                            <x-confirm-modal :title="'Arsipkan '.$item->item_name.'?'" :action="route('bq-items.archive', $item->id)" confirm-label="Arsipkan" :require-reason="true"
                                                note="Item tidak lagi dihitung sebagai alokasi budget dan tidak menghalangi financial closing. Tidak bisa dikembalikan; riwayat dan alasan tetap tersimpan di audit log.">
                                                <x-slot:trigger><button type="button" class="btn px-3 py-1.5 text-[13px]" aria-label="Arsipkan {{ $item->item_name }}">Arsipkan</button></x-slot:trigger>
                                                <div class="{{ $line }}"><dt>Status</dt><dd class="font-semibold">{{ \App\Enums\BqStatus::from($item->status)->label() }} → {{ \App\Enums\BqStatus::ARCHIVED->label() }}</dd></div>
                                                <div class="{{ $line }}"><dt>Nilai RAB</dt><dd class="font-semibold">{{ Money::format($item->effective_budget) }}</dd></div>
                                            </x-confirm-modal>
                                        @endif
                                        @if ($item->canActual ?? false)
                                            @include('projects._actual-cost-form')
                                        @endif
                                    </span>
                                @endif
                                @if ($item->status === 'SUBMITTED' && $item->ownItem && $can['approveBq'])
                                    <p class="mt-1.5 text-xs text-slate-600">Dibuat oleh Anda, jadi tidak bisa Anda setujui.</p>
                                @elseif (! ($item->editable ?? false) && $anyEditable && ! $operational)
                                    <p class="mt-0.5 text-xs text-slate-600">Terkunci, tidak bisa diubah</p>
                                @endif
                            </td>
                            <td class="td num hidden whitespace-nowrap md:table-cell">{{ Money::format($item->effective_budget) }}@if ($item->baseline_budget !== $item->effective_budget)<span class="block text-xs font-normal text-slate-500">awal {{ Money::format($item->baseline_budget) }}</span>@endif</td>
                            <td class="td num hidden whitespace-nowrap md:table-cell">{{ $operational ? '—' : Money::format($item->negotiated_value) }}</td>
                            <td class="td num hidden whitespace-nowrap md:table-cell">{{ Money::format($item->actual_cost) }}</td>
                            <td @class(['td num hidden whitespace-nowrap md:table-cell', 'font-semibold text-rose-700' => $overbudget])>{{ Money::format($item->sisa) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty"><div class="empty-msg">Belum ada item BQ.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($archivedBq->isNotEmpty())
            <details class="mt-3 text-sm text-slate-600">
                <summary class="cursor-pointer font-semibold">Item BQ diarsipkan ({{ $archivedBq->count() }}), tidak dihitung</summary>
                <ul class="mt-2 space-y-1">
                    @foreach ($archivedBq as $a)
                        <li class="flex flex-wrap items-center gap-2"><x-status-badge :status="\App\Enums\BqStatus::ARCHIVED" /><span class="break-words">{{ $a->item_code ? $a->item_code.' ' : '' }}{{ $a->item_name }}</span><span class="whitespace-nowrap">Nilai RAB {{ Money::format($a->baseline_budget) }}</span></li>
                    @endforeach
                </ul>
            </details>
        @endif
    </section>

    @php
        $approvedBq = $bqItems->where('status', 'APPROVED');
        $mutationTypes = ['FROM_UNALLOCATED_RAB' => 'Dari Sisa RAB', 'TRANSFER_BETWEEN_BQ' => 'Antar item BQ', 'RETURN_TO_UNALLOCATED_RAB' => 'Kembalikan ke Sisa RAB'];
    @endphp
    <section class="mt-6" aria-labelledby="relokasi-budget">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h2 id="relokasi-budget">Relokasi Budget</h2>
            @if ($can['mutate'])
                @include('projects._mutation-form', ['mutation' => null])
            @endif
        </div>
        <div class="table-card">
            <table class="w-full [&_.td]:px-3 [&_.th]:px-3">
                <thead><tr><th class="th">Usulan</th><th class="th num hidden md:table-cell">Nominal</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($mutations as $m)
                        <tr>
                            <td class="td min-w-60 break-words">
                                <span class="block text-xs font-semibold text-slate-500">{{ $mutationTypes[$m->mutation_type] ?? $m->mutation_type }}</span>
                                <span class="block font-medium text-slate-900">{{ $m->source_name ?? 'Sisa RAB' }} → {{ $m->destination_name }}</span>
                                <p class="mt-0.5 text-sm font-semibold tabular-nums text-slate-800 md:hidden">{{ Money::format($m->amount) }}</p>
                                {{-- Status ikut di kolom Usulan (seperti tabel BQ) supaya tetap terbaca di ponsel. --}}
                                <span class="mt-1.5 block"><x-status-badge :status="\App\Enums\ApprovalStatus::from($m->status)" /></span>
                                @if ($m->status === 'SUBMITTED')
                                    <p class="mt-0.5 text-xs font-medium text-blue-700">Pending, saldo belum berubah</p>
                                @elseif ($m->status === 'REVISION_REQUIRED' && $m->revision_reason)
                                    <p class="mt-0.5 break-words text-xs text-amber-800">Alasan: {{ $m->revision_reason }}</p>
                                @endif
                                <span class="mt-1.5 block text-xs text-slate-600">Keperluan: {{ $m->reason }}</span>
                                <span class="block text-xs text-slate-500">Diajukan {{ $m->submitter }}, {{ \App\Support\LocalTime::format($m->submitted_at) }}</span>
                                @if ($m->notaUrl)<a href="{{ $m->notaUrl }}" class="btn mt-2 px-3 py-1.5 text-[13px]" aria-label="Unduh Nota Disposisi relokasi {{ $m->id }}">Nota Disposisi</a>@endif
                                @if ($m->canDecide || $m->canResubmit || $m->canWithdraw)
                                    <span class="mt-2 flex flex-wrap items-center gap-2">
                                        @if ($m->canDecide)
                                            <x-confirm-modal title="Setujui Relokasi Budget?" :action="route('budget-mutations.approve', $m->id)" confirm-label="Setujui"
                                                :note="$m->mutation_type === 'RETURN_TO_UNALLOCATED_RAB' ? 'Sisa item sumber dikembalikan ke Sisa RAB. Ditolak bila sisa item sumber tidak cukup saat ini.' : 'Saldo sumber berkurang dan saldo tujuan bertambah sekaligus. Ditolak bila saldo sumber atau Sisa RAB tidak cukup saat ini.'">
                                                <x-slot:trigger><button type="button" class="btn btn-primary px-3 py-1.5 text-[13px]" aria-label="Setujui relokasi {{ $m->id }}">Setujui</button></x-slot:trigger>
                                                <div class="{{ $line }}"><dt>Dari</dt><dd class="font-semibold">{{ $m->source_name ?? 'Sisa RAB' }}</dd></div>
                                                <div class="{{ $line }}"><dt>Ke</dt><dd class="font-semibold">{{ $m->destination_name }}</dd></div>
                                                <div class="{{ $line }}"><dt>Nominal</dt><dd class="font-semibold">{{ Money::format($m->amount) }}</dd></div>
                                            </x-confirm-modal>
                                            <x-confirm-modal title="Minta revisi Relokasi Budget?" :action="route('budget-mutations.request-revision', $m->id)" confirm-label="Minta revisi" :require-reason="true"
                                                note="Usulan kembali ke pengaju untuk diperbaiki. Alasan tercatat di audit log.">
                                                <x-slot:trigger><button type="button" class="btn btn-warn px-3 py-1.5 text-[13px]" aria-label="Minta revisi relokasi {{ $m->id }}">Minta revisi</button></x-slot:trigger>
                                                <div class="{{ $line }}"><dt>Status</dt><dd class="font-semibold">{{ \App\Enums\ApprovalStatus::SUBMITTED->label() }} → {{ \App\Enums\ApprovalStatus::REVISION_REQUIRED->label() }}</dd></div>
                                            </x-confirm-modal>
                                        @endif
                                        @if ($m->canResubmit)
                                            @include('projects._mutation-form', ['mutation' => $m])
                                        @endif
                                        @if ($m->canWithdraw)
                                            <x-confirm-modal title="Tarik Relokasi Budget?" :action="route('budget-mutations.withdraw', $m->id)" confirm-label="Tarik pengajuan" :require-reason="true"
                                                note="Usulan berhenti permanen dan tidak bisa diajukan ulang. Riwayat tetap tersimpan.">
                                                <x-slot:trigger><button type="button" class="btn px-3 py-1.5 text-[13px]" aria-label="Tarik pengajuan relokasi {{ $m->id }}">Tarik pengajuan</button></x-slot:trigger>
                                            </x-confirm-modal>
                                        @endif
                                    </span>
                                @endif
                                @if ($m->status === 'SUBMITTED' && $m->own && $can['approveBq'] && ! $m->canDecide)
                                    <p class="mt-1.5 text-xs text-slate-600">Diajukan oleh Anda, jadi tidak bisa Anda setujui.</p>
                                @endif
                            </td>
                            <td class="td num hidden whitespace-nowrap md:table-cell">{{ Money::format($m->amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="empty"><div class="empty-msg">Belum ada Relokasi Budget.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @php
    @endphp
    <section class="mt-6" aria-labelledby="penyesuaian">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h2 id="penyesuaian">Penyesuaian</h2>
            <div class="flex flex-wrap gap-2">
                @if ($can['correctContract'])
                    @include('projects._adjustment-form', ['adjustment' => null, 'contract' => true])
                @endif
                @if ($can['correctBilling'])
                    @include('projects._billing-form', ['adjustment' => null])
                @endif
                @if ($can['adjust'])
                    @include('projects._adjustment-form', ['adjustment' => null])
                @endif
            </div>
        </div>
        @if ($contractCorrection['possible'] ?? false)
            <p class="mb-3 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
                <x-icon name="alert" size="mt-0.5 h-5 w-5 shrink-0" class="text-amber-600" />
                <span><strong>{{ count($contractCorrection['cancelled_sequences']) }} termin dibatalkan belum dikoreksi ke nilai tertagih</strong> (termin {{ collect($contractCorrection['cancelled_sequences'])->map(fn ($n) => '#'.$n)->join(', ') }}). Nilai tertagih {{ Money::format($contractCorrection['contract_value']) }} masih termasuk termin batal; seharusnya {{ Money::format($contractCorrection['new_contract_value']) }}. Financial closing menunggu koreksi ini disetujui.@unless ($can['correctContract']) Koreksi diajukan oleh Staff/PIC project.@endunless</span>
            </p>
        @endif
        @if ($billingCorrection && $billingCorrection['termins'] && ! $billingCorrection['possible'] && $role === \App\Enums\Role::STAFF_PIC)
            <p class="mb-3 text-sm text-slate-600" role="status">Koreksi Persentase Termin belum bisa diajukan: {{ $billingCorrection['has_open'] ? 'masih ada koreksi persentase termin yang menunggu keputusan atau perbaikan.' : (count($billingCorrection['termins']) < 2 ? 'perlu minimal dua termin yang belum ditagih.' : 'termin terakhir sudah ditagih atau dibatalkan.') }}</p>
        @endif
        <div class="table-card">
            <table class="w-full [&_.td]:px-3 [&_.th]:px-3">
                <thead><tr><th class="th">Usulan</th><th class="th num hidden md:table-cell">RAB Perubahan</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($adjustments as $a)
                        <tr>
                            <td class="td min-w-60 break-words">
                                <span class="block text-xs font-semibold text-slate-600">{{ $a->type_label }}</span>
                                @if ($a->is_billing_percentage)
                                    {{-- Koreksi Persentase Termin: tidak ada angka RAB/margin; daftar termin lama -> baru. --}}
                                    <ul class="space-y-0.5 font-medium text-slate-900">
                                        @foreach ($a->termins as $t)
                                            <li>Termin {{ $t['sequence_number'] }}: {{ $pct($t['old_percentage']) }}% → {{ $pct($t['new_percentage']) }}%<span class="block text-xs font-normal tabular-nums text-slate-500">Rencana tagihan {{ Money::format($t['old_amount']) }} → {{ Money::format($t['new_amount']) }}</span></li>
                                        @endforeach
                                    </ul>
                                @else
                                @if ($a->is_contract_value)
                                    <span class="block font-medium text-slate-900">Nilai tertagih {{ Money::format($a->old['contract_value']) }} → {{ Money::format($a->new['contract_value']) }}@if ($a->new['cancelled_sequences'] ?? []) <span class="text-xs font-normal text-slate-500">(termin batal {{ collect($a->new['cancelled_sequences'])->map(fn ($n) => '#'.$n)->join(', ') }})</span>@endif</span>
                                @endif
                                <span class="block font-medium text-slate-900">Target margin {{ $pct($a->old['target_margin_pct']) }}% ({{ Money::format($a->old['target_margin_amount']) }}) → {{ $pct($a->new['target_margin_pct']) }}% ({{ Money::format($a->new['target_margin_amount']) }})</span>
                                <p class="mt-0.5 text-sm font-semibold tabular-nums text-slate-800 md:hidden">RAB Perubahan {{ Money::format($a->old['effective_rab']) }} → {{ Money::format($a->new['effective_rab']) }}<span class="block text-xs font-normal text-slate-500">{{ $a->difference_label }} {{ $signed($a->difference_amount) }}</span></p>
                                @if ($a->rab_below_committed)
                                    <p class="mt-1 flex items-start gap-1.5 text-xs font-medium text-amber-800"><x-icon name="alert" size="mt-px h-3.5 w-3.5 shrink-0" />RAB baru di bawah total BQ yang sudah disetujui ({{ Money::format($committedBudget) }}) — tetap boleh, karena biaya sudah terjadi.</p>
                                @endif
                                @endif
                                <span class="mt-1.5 block"><x-status-badge :status="\App\Enums\ApprovalStatus::from($a->status)" /></span>
                                @if ($a->status === 'SUBMITTED')
                                    <p class="mt-0.5 text-xs font-medium text-blue-700">{{ $a->is_billing_percentage ? 'Pending, persentase termin belum berubah' : 'Pending, project belum berubah' }}</p>
                                @elseif ($a->status === 'REVISION_REQUIRED' && $a->revision_reason)
                                    <p class="mt-0.5 break-words text-xs text-amber-800">Alasan revisi: {{ $a->revision_reason }}</p>
                                @endif
                                <span class="mt-1.5 block text-xs text-slate-600">Alasan pengajuan: {{ $a->reason }}</span>
                                <span class="block text-xs text-slate-500">Diajukan {{ $a->submitter }}, {{ \App\Support\LocalTime::format($a->submitted_at) }}</span>
                                @if ($a->notaUrl)<a href="{{ $a->notaUrl }}" class="btn mt-2 px-3 py-1.5 text-[13px]" aria-label="Unduh Nota Disposisi Direksi penyesuaian {{ $a->id }}">Nota Disposisi Direksi</a>@endif
                                @if ($a->canDecide || $a->canResubmit || $a->canWithdraw)
                                    <span class="mt-2 flex flex-wrap items-center gap-2">
                                        @if ($a->canDecide && $a->stale)
                                            <p class="basis-full text-xs font-medium text-amber-800">{{ $a->is_billing_percentage ? 'Daftar termin yang belum ditagih sudah berubah sejak usulan diajukan' : 'Angka project sudah berubah sejak usulan diajukan' }} — minta revisi agar pengaju memperbarui.</p>
                                        @endif
                                        @if ($a->canDecide)
                                            @unless ($a->stale)
                                            <x-confirm-modal :title="$a->is_billing_percentage ? 'Setujui Koreksi Persentase Termin?' : ($a->is_contract_value ? 'Setujui Koreksi Nilai Tertagih?' : 'Setujui penyesuaian target margin?')" :action="route('adjustments.approve', $a->id)" confirm-label="Setujui"
                                                :note="$a->is_billing_percentage ? 'Persentase dan rencana tagihan termin yang belum ditagih berubah sekaligus; termin yang sudah ditagih, Nilai Project, dan RAB tidak berubah. Ditolak bila daftar termin berubah. Tidak dapat dibatalkan.' : ($a->is_contract_value ? 'Nilai tertagih, target margin, dan RAB Perubahan berubah sekaligus; Nilai Project dan RAB Awal (baseline) tidak berubah. Tidak dapat dibatalkan.' : 'Target margin dan RAB Perubahan berubah sekaligus; RAB Awal (baseline) tidak berubah. Ditolak bila RAB Perubahan baru lebih kecil dari total budget BQ.')">
                                                <x-slot:trigger><button type="button" class="btn btn-primary px-3 py-1.5 text-[13px]" aria-label="Setujui penyesuaian {{ $a->id }}">Setujui</button></x-slot:trigger>
                                                @if ($a->is_billing_percentage)
                                                    @foreach ($a->termins as $t)
                                                        <div class="{{ $line }}"><dt>Termin {{ $t['sequence_number'] }}</dt><dd class="text-right font-semibold">{{ $pct($t['old_percentage']) }}% → {{ $pct($t['new_percentage']) }}%<span class="block text-xs font-normal tabular-nums text-slate-600">{{ Money::format($t['old_amount']) }} → {{ Money::format($t['new_amount']) }}</span></dd></div>
                                                    @endforeach
                                                    <div class="{{ $line }}"><dt>Nilai Project</dt><dd class="font-semibold">{{ Money::format($project->initial_contract_value) }} (tetap)</dd></div>
                                                @else
                                                @if ($a->is_contract_value)
                                                    <div class="{{ $line }}"><dt>Nilai tertagih</dt><dd class="font-semibold">{{ Money::format($a->old['contract_value']) }} → {{ Money::format($a->new['contract_value']) }}</dd></div>
                                                @endif
                                                <div class="{{ $line }}"><dt>Target margin</dt><dd class="font-semibold">{{ $pct($a->old['target_margin_pct']) }}% → {{ $pct($a->new['target_margin_pct']) }}%</dd></div>
                                                <div class="{{ $line }}"><dt>RAB Perubahan</dt><dd class="font-semibold">{{ Money::format($a->old['effective_rab']) }} → {{ Money::format($a->new['effective_rab']) }}</dd></div>
                                                <div class="{{ $line }}"><dt>{{ $a->difference_label }}</dt><dd class="font-semibold">{{ $signed($a->difference_amount) }}</dd></div>
                                                @if ($a->is_contract_value)
                                                    <div class="{{ $line }}"><dt>Nilai Project</dt><dd class="font-semibold">{{ Money::format($project->initial_contract_value) }} (tetap)</dd></div>
                                                @endif
                                                <div class="{{ $line }}"><dt>RAB Awal</dt><dd class="font-semibold">{{ Money::format($project->initial_rab) }} (tetap)</dd></div>
                                                @if ($a->rab_below_committed)
                                                    <div class="{{ $line }} font-semibold text-amber-800"><dt>Peringatan</dt><dd class="text-right">RAB baru di bawah total BQ yang sudah disetujui ({{ Money::format($committedBudget) }}) — tetap boleh, karena biaya sudah terjadi.</dd></div>
                                                @endif
                                                @endif
                                            </x-confirm-modal>
                                            @endunless
                                            <x-confirm-modal title="Minta revisi penyesuaian?" :action="route('adjustments.request-revision', $a->id)" confirm-label="Minta revisi" :require-reason="true"
                                                note="Usulan kembali ke pengaju untuk diperbaiki. Alasan tercatat di audit log.">
                                                <x-slot:trigger><button type="button" class="btn btn-warn px-3 py-1.5 text-[13px]" aria-label="Minta revisi penyesuaian {{ $a->id }}">Minta revisi</button></x-slot:trigger>
                                                <div class="{{ $line }}"><dt>Status</dt><dd class="font-semibold">{{ \App\Enums\ApprovalStatus::SUBMITTED->label() }} → {{ \App\Enums\ApprovalStatus::REVISION_REQUIRED->label() }}</dd></div>
                                            </x-confirm-modal>
                                        @endif
                                        @if ($a->canResubmit)
                                            @include($a->is_billing_percentage ? 'projects._billing-form' : 'projects._adjustment-form', ['adjustment' => $a])
                                        @endif
                                        @if ($a->canWithdraw)
                                            <x-confirm-modal :title="'Tarik '.$a->type_label.'?'" :action="route('adjustments.withdraw', $a->id)" confirm-label="Tarik pengajuan" :require-reason="true"
                                                note="Usulan berhenti permanen dan tidak bisa diajukan ulang. Riwayat tetap tersimpan.">
                                                <x-slot:trigger><button type="button" class="btn px-3 py-1.5 text-[13px]" aria-label="Tarik pengajuan penyesuaian {{ $a->id }}">Tarik pengajuan</button></x-slot:trigger>
                                            </x-confirm-modal>
                                        @endif
                                    </span>
                                @endif
                                @if ($a->status === 'SUBMITTED' && $a->own && $can['approveBq'] && ! $a->canDecide)
                                    <p class="mt-1.5 text-xs text-slate-600">Diajukan oleh Anda, jadi tidak bisa Anda setujui.</p>
                                @endif
                            </td>
                            <td class="td num hidden whitespace-nowrap md:table-cell">@if ($a->is_billing_percentage)Tidak berubah<span class="block text-xs font-normal text-slate-500">Total persentase tetap</span>@else{{ Money::format($a->old['effective_rab']) }} → {{ Money::format($a->new['effective_rab']) }}<span class="block text-xs font-normal text-slate-500">{{ $a->difference_label }} {{ $signed($a->difference_amount) }}</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="empty"><div class="empty-msg">Belum ada penyesuaian.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-6" aria-labelledby="jadwal" x-data="{ hist: null }">
        <h2 id="jadwal" class="mb-3">Jadwal invoice ({{ $project->payment_type }})</h2>
        @if ($can['correctBilling'])
            <p class="hint !mt-0 mb-3">Persentase termin yang belum ditagih salah? <a href="#penyesuaian" class="font-semibold text-brand-blue underline">Ajukan Koreksi Persentase Termin</a> di bagian Penyesuaian.</p>
        @endif
        <div class="table-card">
            <table class="w-full">
                <thead><tr><th class="th num">Termin</th><th class="th num hidden sm:table-cell">Persentase</th><th class="th num hidden sm:table-cell">Rencana tagihan</th><th class="th">Status</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($invoices as $invoice)
                        <tr @class(['bg-emerald-50/40' => $invoice->workflow_status === 'PAID', 'opacity-60' => $invoice->workflow_status === 'CANCELLED'])>
                            <td class="td num">{{ $invoice->sequence_number }}<span class="block text-xs font-normal text-slate-500 sm:hidden">{{ $pct($invoice->billing_percentage) }}%</span></td>
                            <td class="td num hidden sm:table-cell">{{ $pct($invoice->billing_percentage) }}%</td>
                            <td class="td num hidden whitespace-nowrap sm:table-cell">{{ Money::format($invoice->planned_amount) }}</td>
                            <td class="td">
                                <x-status-badge :status="\App\Enums\InvoiceStatus::from($invoice->workflow_status)" />
                                <p class="mt-1 text-xs text-slate-600 sm:hidden">Rencana tagihan <span class="whitespace-nowrap font-semibold">{{ Money::format($invoice->planned_amount) }}</span></p>
                                @if ($invoice->workflow_status === 'PLANNED')
                                    @php
                                        $lateToBill = $invoice->planned_billing_date !== null && $invoice->planned_billing_date < $todayWib && in_array($project->status, \App\Http\Controllers\BqWorkflowController::WORKFLOW_PROJECT_STATUSES, true)
                                    @endphp
                                    <p class="mt-1 text-xs text-slate-600">Rencana tgl tagih: <span class="font-semibold text-slate-900">{{ $invoice->planned_billing_date ? \Illuminate\Support\Carbon::parse($invoice->planned_billing_date)->format('d/m/Y') : 'Belum diatur' }}</span>
                                        @if ($lateToBill)<span class="badge ml-1 bg-amber-50 text-amber-800 ring-amber-600/20">Terlambat ditagih</span>@endif</p>
                                @endif
                                @if ($invoice->invoice_number !== null)
                                    {{-- Angka tersimpan (snapshot) dari submit; terkunci sampai revisi. --}}
                                    <dl class="mt-2 grid max-w-xs gap-0.5 text-xs text-slate-700">
                                        <div class="{{ $line }}"><dt>No. invoice</dt><dd class="break-all font-semibold">{{ $invoice->invoice_number }}</dd></div>
                                        <div class="{{ $line }}"><dt>Tgl. penagihan</dt><dd class="font-semibold">{{ \Illuminate\Support\Carbon::parse($invoice->billing_date)->format('d/m/Y') }}</dd></div>
                                        <div class="{{ $line }}"><dt>DPP</dt><dd class="font-semibold tabular-nums">{{ Money::format($invoice->dpp_amount) }}</dd></div>
                                        <div class="{{ $line }}"><dt>PPN ({{ $pct($invoice->ppn_rate_snapshot) ?: '0' }}%)</dt><dd class="font-semibold tabular-nums">{{ Money::format($invoice->ppn_amount) }}</dd></div>
                                        <div class="{{ $line }}"><dt>Subtotal</dt><dd class="font-semibold tabular-nums">{{ Money::format($invoice->subtotal_amount) }}</dd></div>
                                        <div class="{{ $line }}"><dt>PPh ({{ $pct($invoice->pph_rate_snapshot) }}%)</dt><dd class="font-semibold tabular-nums">{{ Money::format($invoice->pph_amount) }}</dd></div>
                                        <div class="{{ $line }}"><dt>Diterima bersih</dt><dd class="font-semibold tabular-nums">{{ Money::format($invoice->net_received_amount) }}</dd></div>
                                    </dl>
                                @endif
                                @if ($invoice->workflow_status === 'INVOICE_SUBMITTED')
                                    <p class="mt-1 text-xs font-medium text-blue-700">Menunggu verifikasi Manager/GM</p>
                                @elseif ($invoice->workflow_status === 'INVOICE_REVISION_REQUIRED')
                                    <p class="mt-1 text-xs font-medium text-amber-800">Dikembalikan untuk revisi{{ $invoice->canSubmit ? '. Perbaiki lalu ajukan ulang.' : '.' }}</p>
                                    @if ($invoice->revision_reason)
                                        <p class="mt-0.5 break-words text-xs text-amber-800">Alasan revisi: {{ $invoice->revision_reason }}</p>
                                    @endif
                                @elseif ($invoice->workflow_status === 'PAYMENT_SUBMITTED')
                                    <p class="mt-1 text-xs font-medium text-blue-700">Bukti bayar menunggu persetujuan Manager/GM</p>
                                @elseif ($invoice->workflow_status === 'PAYMENT_REVISION_REQUIRED')
                                    @if ($invoice->showUnlockNote)
                                        {{-- Berbeda dari revisi biasa: kotak beraksen amber dengan ikon gembok terbuka, karena catatan keuangan PAID sempat dibuka. --}}
                                        <div class="mt-1.5 max-w-xs rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                            <p class="flex items-start gap-1.5 font-semibold"><x-icon name="unlock" size="mt-px h-3.5 w-3.5" /><span>Kunci dibuka{{ $invoice->canSubmitPayment ? '. Unggah bukti bayar yang benar.' : '. Menunggu bukti bayar yang benar.' }}</span></p>
                                            <p class="mt-1 break-words">Alasan unlock: {{ $invoice->unlock_reason }}</p>
                                            <p class="mt-0.5 break-words text-amber-800">Dibuka oleh {{ $invoice->unlockedBy ?? 'Pengguna' }}, {{ \App\Support\LocalTime::format($invoice->unlocked_at) }}</p>
                                        </div>
                                    @else
                                        <p class="mt-1 text-xs font-medium text-amber-800">Bukti bayar dikembalikan untuk revisi{{ $invoice->canSubmitPayment ? '. Unggah bukti bayar yang benar.' : '.' }}</p>
                                        @if ($invoice->revision_reason)
                                            <p class="mt-0.5 break-words text-xs text-amber-800">Alasan revisi: {{ $invoice->revision_reason }}</p>
                                        @endif
                                    @endif
                                @elseif ($invoice->workflow_status === 'CANCELLED')
                                    <p class="mt-1 text-xs font-medium text-slate-600">Dibatalkan, tidak akan ditagih. Alasan ada di audit log.</p>
                                @elseif ($invoice->workflow_status === 'PAID')
                                    <p class="mt-1.5 flex items-start gap-1.5 text-xs font-semibold text-emerald-800"><x-icon name="lock" size="mt-px h-3.5 w-3.5" /><span>Lunas, terkunci{{ $invoice->locked_at ? ' sejak '.\App\Support\LocalTime::format($invoice->locked_at) : '' }}. Tidak bisa diubah.</span></p>
                                @endif
                                @if ($invoice->proofUrl)
                                    <a href="{{ $invoice->proofUrl }}" class="mt-1.5 flex w-fit items-center gap-1 text-xs font-semibold text-brand-blue underline" aria-label="Unduh bukti bayar termin {{ $invoice->sequence_number }}"><x-icon name="download" size="h-3.5 w-3.5" />Bukti bayar</a>
                                @endif
                                @if ($invoice->canCancel)
                                    <span class="mt-2 flex flex-wrap items-center gap-2">
                                        <x-confirm-modal :title="'Batalkan termin '.$invoice->sequence_number.'?'" :action="route('invoices.cancel', $invoice->id)" confirm-label="Batalkan termin" :require-reason="true"
                                            note="Hanya untuk termin yang tidak jadi ditagih. Salah input persentase/tanggal? Gunakan Ubah tgl rencana atau Koreksi Persentase Termin. Termin ini tidak akan ditagih dan tidak bisa dikembalikan. Untuk mengoreksi invoice yang sudah diajukan, gunakan permintaan revisi. Alasan tercatat di audit log.">
                                            <x-slot:trigger><button type="button" class="btn btn-warn px-3 py-1.5 text-[13px]" aria-label="Batalkan termin {{ $invoice->sequence_number }}">Batalkan termin</button></x-slot:trigger>
                                            <div class="{{ $line }}"><dt>Termin</dt><dd class="font-semibold">{{ $invoice->sequence_number }}</dd></div>
                                            <div class="{{ $line }}"><dt>Rencana tagihan</dt><dd class="font-semibold">{{ Money::format($invoice->planned_amount) }}</dd></div>
                                            <div class="{{ $line }}"><dt>Status</dt><dd class="font-semibold">{{ \App\Enums\InvoiceStatus::PLANNED->label() }} → {{ \App\Enums\InvoiceStatus::CANCELLED->label() }}</dd></div>
                                        </x-confirm-modal>
                                    </span>
                                @endif
                                @if ($invoice->canUnlock)
                                    <span class="mt-2 flex flex-wrap items-center gap-2">
                                        <x-confirm-modal :title="'Buka kunci invoice termin '.$invoice->sequence_number.'?'" :action="route('invoices.unlock', $invoice->id)" confirm-label="Buka kunci" :require-reason="true"
                                            note="Invoice berstatus Lunas adalah catatan keuangan yang terkunci. Membuka kunci mengembalikannya ke tahap revisi pembayaran: Staff/PIC mengunggah bukti bayar yang benar, lalu Manager/GM harus menyetujuinya lagi agar invoice kembali Lunas. Bukti bayar lama tetap tersimpan. Isi alasan yang jelas, karena tindakan ini tercatat di audit log atas nama Anda.">
                                            <x-slot:trigger><button type="button" class="btn btn-warn px-3 py-1.5 text-[13px]" aria-label="Buka kunci invoice termin {{ $invoice->sequence_number }}"><x-icon name="unlock" size="h-3.5 w-3.5" />Buka kunci</button></x-slot:trigger>
                                            <div class="{{ $line }}"><dt>Termin</dt><dd class="font-semibold">{{ $invoice->sequence_number }}</dd></div>
                                            <div class="{{ $line }}"><dt>No. invoice</dt><dd class="break-all font-semibold">{{ $invoice->invoice_number }}</dd></div>
                                            <div class="{{ $line }}"><dt>Diterima bersih</dt><dd class="font-semibold">{{ Money::format($invoice->net_received_amount) }}</dd></div>
                                            <div class="{{ $line }}"><dt>Status</dt><dd class="font-semibold">{{ \App\Enums\InvoiceStatus::PAID->label() }} → {{ \App\Enums\InvoiceStatus::PAYMENT_REVISION_REQUIRED->label() }}</dd></div>
                                        </x-confirm-modal>
                                    </span>
                                @endif
                                @if ($invoice->canApprovePayment)
                                    <span class="mt-2 flex flex-wrap items-center gap-2">
                                        <x-confirm-modal :title="'Setujui pembayaran termin '.$invoice->sequence_number.'?'" :action="route('invoices.approve-payment', $invoice->id)" confirm-label="Setujui pembayaran"
                                            note="Invoice menjadi Lunas dan terkunci; perubahan berikutnya hanya lewat unlock terkontrol oleh Manager/GM.">
                                            <x-slot:trigger><button type="button" class="btn btn-primary px-3 py-1.5 text-[13px]" aria-label="Setujui pembayaran termin {{ $invoice->sequence_number }}">Setujui pembayaran</button></x-slot:trigger>
                                            <div class="{{ $line }}"><dt>No. invoice</dt><dd class="break-all font-semibold">{{ $invoice->invoice_number }}</dd></div>
                                            <div class="{{ $line }}"><dt>Status</dt><dd class="font-semibold">{{ \App\Enums\InvoiceStatus::PAYMENT_SUBMITTED->label() }} → {{ \App\Enums\InvoiceStatus::PAID->label() }}</dd></div>
                                            <div class="{{ $line }}"><dt>Diterima bersih</dt><dd class="font-semibold">{{ Money::format($invoice->net_received_amount) }}</dd></div>
                                        </x-confirm-modal>
                                        <x-confirm-modal :title="'Minta revisi bukti bayar termin '.$invoice->sequence_number.'?'" :action="route('invoices.request-revision', $invoice->id)" confirm-label="Minta revisi" :require-reason="true"
                                            note="Bukti bayar kembali ke Staff/PIC untuk diperbaiki. Alasan tercatat di audit log.">
                                            <x-slot:trigger><button type="button" class="btn btn-warn px-3 py-1.5 text-[13px]" aria-label="Minta revisi bukti bayar termin {{ $invoice->sequence_number }}">Minta revisi</button></x-slot:trigger>
                                            <div class="{{ $line }}"><dt>Status</dt><dd class="font-semibold">{{ \App\Enums\InvoiceStatus::PAYMENT_SUBMITTED->label() }} → {{ \App\Enums\InvoiceStatus::PAYMENT_REVISION_REQUIRED->label() }}</dd></div>
                                        </x-confirm-modal>
                                    </span>
                                @elseif ($invoice->ownPayment && $invoice->workflow_status === 'PAYMENT_SUBMITTED' && $can['approveBq'])
                                    <p class="mt-1.5 text-xs text-slate-600">Pembayaran diajukan oleh Anda, jadi tidak bisa Anda setujui.</p>
                                @endif
                                @if ($invoice->canSubmitPayment)
                                    @include('projects._payment-form')
                                @endif
                                @if ($invoice->canVerify)
                                    <span class="mt-2 flex flex-wrap items-center gap-2">
                                        <x-confirm-modal :title="'Verifikasi invoice termin '.$invoice->sequence_number.'?'" :action="route('invoices.verify', $invoice->id)" confirm-label="Verifikasi"
                                            note="Invoice dan file-nya dinyatakan benar. Status berpindah ke Menunggu Pembayaran, menunggu bukti pembayaran dari Staff/PIC.">
                                            <x-slot:trigger><button type="button" class="btn btn-primary px-3 py-1.5 text-[13px]" aria-label="Verifikasi invoice termin {{ $invoice->sequence_number }}">Verifikasi</button></x-slot:trigger>
                                            <div class="{{ $line }}"><dt>No. invoice</dt><dd class="break-all font-semibold">{{ $invoice->invoice_number }}</dd></div>
                                            <div class="{{ $line }}"><dt>Status</dt><dd class="font-semibold">{{ \App\Enums\InvoiceStatus::INVOICE_SUBMITTED->label() }} → {{ \App\Enums\InvoiceStatus::WAITING_FOR_PAYMENT->label() }}</dd></div>
                                            <div class="{{ $line }}"><dt>Diterima bersih</dt><dd class="font-semibold">{{ Money::format($invoice->net_received_amount) }}</dd></div>
                                        </x-confirm-modal>
                                        <x-confirm-modal :title="'Minta revisi invoice termin '.$invoice->sequence_number.'?'" :action="route('invoices.request-revision', $invoice->id)" confirm-label="Minta revisi" :require-reason="true"
                                            note="Invoice kembali ke Staff/PIC untuk diperbaiki dan diajukan ulang. Alasan tercatat di audit log.">
                                            <x-slot:trigger><button type="button" class="btn btn-warn px-3 py-1.5 text-[13px]" aria-label="Minta revisi invoice termin {{ $invoice->sequence_number }}">Minta revisi</button></x-slot:trigger>
                                            <div class="{{ $line }}"><dt>Status</dt><dd class="font-semibold">{{ \App\Enums\InvoiceStatus::INVOICE_SUBMITTED->label() }} → {{ \App\Enums\InvoiceStatus::INVOICE_REVISION_REQUIRED->label() }}</dd></div>
                                        </x-confirm-modal>
                                    </span>
                                @elseif ($invoice->ownInvoice && $invoice->workflow_status === 'INVOICE_SUBMITTED' && $can['approveBq'])
                                    <p class="mt-1.5 text-xs text-slate-600">Diajukan oleh Anda, jadi tidak bisa Anda verifikasi.</p>
                                @endif
                                @php $latest = $invoice->progress->first(); @endphp
                                @if ($latest)
                                    <p class="mt-2 text-xs text-slate-600">Tahap dokumen: <span class="badge bg-slate-50 text-slate-800 ring-slate-600/20">{{ \App\Http\Controllers\InvoiceController::PROGRESS_STAGES[$latest->stage] ?? $latest->stage }}</span>
                                        @if ($invoice->hasIssue)<span class="badge ml-1 bg-amber-50 text-amber-800 ring-amber-600/20">Kendala</span>@endif
                                        <span class="whitespace-nowrap">· {{ \Illuminate\Support\Carbon::parse($latest->progress_date)->format('d/m/Y') }}</span></p>
                                    <button type="button" class="btn mt-2 ml-0 px-3 py-1.5 text-[13px]" aria-controls="riwayat-progres-{{ $invoice->id }}" :aria-expanded="hist === {{ $invoice->id }}" @click="hist = hist === {{ $invoice->id }} ? null : {{ $invoice->id }}">Riwayat progres ({{ $invoice->progress->count() }})</button>
                                @endif
                                @if ($invoice->canRecordProgress)
                                    @include('projects._progress-form')
                                @endif
                                @if ($latest)
                                    <ol id="riwayat-progres-{{ $invoice->id }}" x-show="hist === {{ $invoice->id }}" x-cloak class="mt-2 max-w-md space-y-2 border-l-2 border-slate-200 pl-3 text-xs text-slate-700" aria-label="Riwayat progres termin {{ $invoice->sequence_number }}">
                                        @foreach ($invoice->progress as $p)
                                            <li class="min-w-0">
                                                <p><span class="font-semibold text-slate-900">{{ \Illuminate\Support\Carbon::parse($p->progress_date)->format('d/m/Y') }}</span> · {{ \App\Http\Controllers\InvoiceController::PROGRESS_STAGES[$p->stage] ?? $p->stage }}
                                                    @if ($p->is_issue)<span class="badge ml-1 bg-amber-50 text-amber-800 ring-amber-600/20">Kendala</span>@endif</p>
                                                <p class="[overflow-wrap:anywhere] whitespace-pre-line">{{ $p->description }}</p>
                                                <p class="text-slate-500">oleh {{ $p->author }}</p>
                                            </li>
                                        @endforeach
                                    </ol>
                                @endif
                                @if ($invoice->canChangePlannedDate)
                                    @include('projects._planned-date-form')
                                @endif
                                @if ($invoice->canSubmit)
                                    @include('projects._invoice-form')
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty"><div class="empty-msg">Belum ada jadwal invoice.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @php
        $docLabels = ['NOTA_DISPOSISI' => 'Nota Disposisi', 'BAPP' => 'BAPP', 'BAST' => 'BAST', 'BUKTI_BAYAR' => 'Bukti bayar', 'OTHER' => 'Lainnya'];
    @endphp
    <section class="mt-6" aria-labelledby="dokumen">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h2 id="dokumen">Dokumen</h2>
            @if ($can['upload'])
                <x-form-modal name="upload-doc" icon="download" :files="true" :action="route('projects.documents.store', $project)" title="Unggah dokumen" subtitle="Dokumen pendukung project; tercatat dengan pengunggah dan waktu." submit-label="Unggah">
                    <x-slot:trigger><button type="button" class="btn btn-primary" aria-haspopup="dialog">+ Unggah dokumen</button></x-slot:trigger>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="lbl">File
                            <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="inp" aria-describedby="h-file @error('file') e-file @enderror" @error('file') aria-invalid="true" @enderror>
                            <span id="h-file" class="hint">PDF, JPG, atau PNG, maksimal 50 MB</span>
                            <x-field-error name="file" />
                        </label>
                        <label class="lbl">Jenis dokumen
                            <select name="document_type" required class="inp" @error('document_type') aria-invalid="true" aria-describedby="e-document_type" @enderror>
                                @foreach ($documentTypes as $type)
                                    <option value="{{ $type }}" @selected((old('_modal') === 'upload-doc' ? old('document_type', 'OTHER') : 'OTHER') === $type)>{{ $docLabels[$type] ?? $type }}</option>
                                @endforeach
                            </select>
                            <x-field-error name="document_type" />
                        </label>
                    </div>
                </x-form-modal>
            @endif
        </div>
        <div class="table-card">
            <table class="w-full min-w-[640px]">
                <thead><tr><th class="th">Dokumen</th><th class="th">Sumber</th><th class="th">Status</th><th class="th">Pengunggah</th><th class="th">Tanggal</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($documents as $doc)
                        <tr>
                            <td class="td">
                                <span class="block text-xs font-semibold text-slate-500">{{ $docLabels[$doc->document_type] ?? $doc->document_type }}</span>
                                <span class="block break-all font-medium text-slate-900">{{ $doc->original_filename }}</span>
                                <a href="{{ $doc->url }}" class="btn mt-2 px-3 py-1.5 text-[13px]" aria-label="Unduh {{ $doc->original_filename }}">Unduh</a>
                            </td>
                            <td class="td min-w-32">{{ $doc->source }}</td>
                            <td class="td"><x-status-badge :status="$doc->status" /></td>
                            <td class="td">{{ $doc->uploader }}</td>
                            <td class="td whitespace-nowrap">{{ \App\Support\LocalTime::format($doc->uploaded_at) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty"><div class="empty-msg">Belum ada dokumen.{{ $can['upload'] ? ' Unggah dokumen pertama lewat tombol di atas.' : '' }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card mt-6" aria-labelledby="tim">
        <div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1">
            <h2 id="tim">Tim project</h2>
            <span class="text-xs text-slate-600">Staff/PIC aktif yang ditugaskan.</span>
        </div>
        <x-field-error name="assignment" />
        <ul class="divide-y divide-slate-100 text-sm">
            @forelse ($team as $member)
                <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-3 first:pt-0 last:pb-0">
                    <x-avatar :name="$member->name" />
                    <span class="min-w-0 font-medium break-words text-slate-900">{{ $member->name }}</span>
                    <span class="badge">PIC</span>
                    <span class="text-xs text-slate-600 sm:ml-auto">Ditugaskan {{ \App\Support\LocalTime::format($member->assigned_at, false) }}</span>
                    @if ($can['manageTeam'] && $usableIds->reject(fn ($id) => $id == $member->user_id)->isNotEmpty())
                        <x-confirm-modal :title="'Nonaktifkan '.$member->name.'?'" :action="route('assignments.deactivate', $member->id)" confirm-label="Nonaktifkan"
                            note="Staff/PIC tidak lagi melihat project ini. Riwayat assignment tetap tersimpan.">
                            <x-slot:trigger><button type="button" class="btn btn-warn" aria-label="Nonaktifkan {{ $member->name }}">Nonaktifkan</button></x-slot:trigger>
                        </x-confirm-modal>
                    @elseif ($can['manageTeam'])
                        <span class="w-full text-xs text-slate-600 sm:w-auto sm:max-w-xs sm:text-right">Staff/PIC aktif terakhir. Tugaskan pengganti dulu sebelum menonaktifkan.</span>
                    @endif
                </li>
            @empty
                <li class="text-slate-600">Belum ada Staff/PIC yang ditugaskan.</li>
            @endforelse
        </ul>
        @if ($can['manageTeam'])
            <div class="mt-4 border-t border-slate-100 pt-4">
                @if ($eligible->isEmpty())
                    <p class="hint !mt-0">Tidak ada Staff/PIC aktif lain di divisi {{ $project->department->value }} yang bisa ditugaskan.</p>
                @else
                    <form method="POST" action="{{ route('projects.assignments.store', $project) }}" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
                        @csrf
                        <label class="lbl">Staff/PIC
                            <select name="user_id" required class="inp" @error('user_id') aria-invalid="true" aria-describedby="e-user_id" @enderror>
                                <option value="">Pilih Staff/PIC</option>
                                @foreach ($eligible as $candidate)
                                    <option value="{{ $candidate->id }}" @selected((string) old('user_id') === (string) $candidate->id)>{{ $candidate->name }} ({{ $candidate->email }})</option>
                                @endforeach
                            </select>
                            <x-field-error name="user_id" />
                        </label>
                        <button type="submit" class="btn btn-primary sm:mt-[1.625rem]">Tugaskan</button>
                    </form>
                @endif
            </div>
        @endif
    </section>
</x-layouts.app>
