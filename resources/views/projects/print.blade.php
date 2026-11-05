{{-- Versi cetak project (Export PDF lewat dialog cetak browser). Tata letak mandiri tanpa shell aplikasi; read-only. Angka dan label sama dengan halaman project. --}}
@php
    use App\Enums\ApprovalStatus;
    use App\Enums\BqStatus;
    use App\Enums\InvoiceStatus;
    use App\Http\Controllers\InvoiceController;
    use App\Support\LocalTime;
    use App\Support\Money;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\HtmlString;

    $pct = fn (string $value) => Money::pct($value);
    $signed = fn (string $value) => (str_starts_with($value, '-') || $value === '0.00' ? '' : '+').Money::format($value);
    $date = fn (?string $value) => $value ? Carbon::parse($value)->format('d/m/Y') : '—';
    $neg = fn (?string $value) => $value !== null && str_starts_with($value, '-');
    // Status = teks + warna (nada sama dengan komponen status-badge).
    $badge = function (\BackedEnum $status) {
        $v = $status->value;
        $tone = match (true) {
            str_contains($v, 'REVISION') => 'warn',
            str_contains($v, 'SUBMITTED') => 'info',
            str_contains($v, 'WAITING') => 'purple',
            str_contains($v, 'CLOSED') => 'dark',
            in_array($v, ['PAID', 'APPROVED', 'VERIFIED', 'INVOICE_VERIFIED', 'ACTIVE', 'ACHIEVED', 'COMPLETED'], true) => 'ok',
            in_array($v, ['DELAYED', 'REPLACED', 'CANCELLED', 'REVERSED'], true) => 'bad',
            default => '',
        };

        return new HtmlString('<span class="badge '.$tone.'">'.e($status->label()).'</span>');
    };

    $locked = $project->status->isBaselineLocked();
    $closed = $summary['actual_profit'] !== null;
    $exclusive = $project->contract_tax_mode === 'EXCLUSIVE_PPN';
    $corrected = $project->contract_value !== $project->initial_contract_value;
    // [label, nilai, keterangan]; sama dengan kartu ringkasan di halaman project.
    $cards = array_values(array_filter([
        ['Nilai Project', $project->initial_contract_value, $corrected ? 'nilai tertagih '.Money::format($project->contract_value) : 'Nilai SPK; tidak berubah'],
        $exclusive ? ['Nilai Project + PPN', $project->margin_base_amount, 'dasar perhitungan target margin'] : null,
        ['RAB Awal', $project->initial_rab, $locked ? 'Baseline terkunci' : 'dihitung sistem'],
        ['RAB Perubahan', $project->effective_rab, $project->effective_rab !== $project->initial_rab ? 'berbeda dari RAB Awal' : 'sama dengan RAB Awal'],
        ['Target Margin Awal ('.$pct($summary['initial_margin_pct']).'%)', $summary['initial_margin_amount'], 'saat aktivasi'],
        ['Target Margin Perubahan ('.$pct($project->target_margin_pct).'%)', $project->target_margin_amount, 'dihitung sistem'],
        $closed
            ? ['Sisa Akhir (Tutup Buku)', $summary['final_sisa'], 'final, terkunci']
            : ['Sisa RAB', $summary['unallocated'], 'RAB Perubahan dikurangi total Nilai RAB BQ'],
        ['Pending Biaya BQ', $summary['pending'], 'belum saldo resmi'],
        ['Total Nilai BQ', $summary['bq_effective'], 'Vendor/Subkon '.Money::format($summary['bq_vendor']).' · Realisasi '.Money::format($summary['bq_actual'])],
        $closed
            ? ['Actual Profit', $summary['actual_profit'], 'final, terkunci'.($neg($summary['actual_profit']) ? ' (rugi)' : '')]
            : ['Projected Profit', $summary['projected_profit'], 'dapat berubah'],
    ]));

    $identity = [
        ['Kode project', $project->project_code],
        ['Divisi', $project->department->label().' ('.$project->department->value.')'],
        ['PKS', ($pks?->pks_number ?? '—').($pks ? ' — '.$pks->name : '')],
        ['Bouwheer / Klien', $pks?->client_name ?? '—'],
        ['PIC Bouwheer', $contact ? $contact->name.' ('.$contact->company_name.')' : '—'],
        ['No. / tanggal SPK', $project->spk_number.' / '.$project->spk_date->format('d/m/Y')],
        ['Lokasi', $project->location],
        ['Periode', $project->start_date->format('d/m/Y').' s.d. '.$project->end_date->format('d/m/Y')],
        ['Mode pajak kontrak', ['INCLUSIVE_PPN' => 'Termasuk PPN', 'EXCLUSIVE_PPN' => 'Belum termasuk PPN', 'NON_PPN' => 'Tanpa PPN'][$project->contract_tax_mode].($project->margin_base_ppn_rate_snapshot !== null ? ' (PPN '.$pct($project->margin_base_ppn_rate_snapshot).'%)' : '')],
        ['Tipe pembayaran', ['TERMIN' => 'Termin', 'MONTHLY' => 'Bulanan'][$project->payment_type] ?? $project->payment_type],
        ['Tim / PIC internal', $team->isEmpty() ? 'Belum ada Staff/PIC yang ditugaskan' : $team->pluck('name')->join(', ')],
    ];

    $mutationTypes = ['FROM_UNALLOCATED_RAB' => 'Dari Sisa RAB', 'TRANSFER_BETWEEN_BQ' => 'Antar item BQ', 'RETURN_TO_UNALLOCATED_RAB' => 'Kembalikan ke Sisa RAB'];
    $sum = fn ($rows, string $field) => Money::add(...$rows->pluck($field)->filter(fn ($v) => $v !== null)->all());
    $printedAt = LocalTime::format(now());
    $footerText = $project->project_code.' · Dicetak '.$printedAt.' oleh '.$printedBy;
    // Teks footer margin-box ada di dalam string CSS: semua karakter selain alfanumerik diberi escape heksadesimal agar tidak bisa keluar dari string/style.
    $footerCss = '"'.preg_replace_callback('/[^A-Za-z0-9 \-\/:]/u', fn ($m) => '\\'.dechex(mb_ord($m[0])).' ', $footerText).'"';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $project->project_code }} — {{ $project->project_name }} — {{ config('app.name') }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    @fonts
    @verbatim
    <style>
        :root { --navy: #0f1a45; --blue: #223f92; --blue-soft: #eef2fb; --orange: #f8991c; --ink: #0f172a; --muted: #475569; --line: #d5dbe8; --zebra: #f6f8fc; }
        * { box-sizing: border-box; }
        html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { margin: 0; background: #e3e8f2; color: var(--ink); font: 9pt/1.45 'Instrument Sans', 'Segoe UI', Arial, sans-serif; }
        .tabular, td.num, th.num { font-variant-numeric: tabular-nums; }

        /* Bilah alat (layar saja) */
        .toolbar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 10px 16px; background: var(--navy); color: #fff; font-size: 13px; }
        .toolbar p { margin: 0 auto 0 0; }
        .toolbar button { cursor: pointer; border: 1px solid rgb(255 255 255 / .35); border-radius: 10px; background: transparent; color: #fff; padding: 8px 16px; font: inherit; font-weight: 600; }
        .toolbar button.primary { background: var(--orange); border-color: var(--orange); color: var(--navy); }
        .toolbar button:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }

        /* Lembar A4 */
        .sheet { width: 210mm; min-height: 297mm; margin: 18px auto; padding: 14mm; background: #fff; box-shadow: 0 8px 30px rgb(15 23 42 / .18); }

        .kop { display: flex; align-items: center; gap: 14px; }
        .kop img { height: 15mm; width: auto; }
        .kop .org { margin-left: auto; text-align: right; }
        .kop .org strong { display: block; color: var(--navy); font-size: 11pt; letter-spacing: .01em; }
        .kop .org span { color: var(--muted); font-size: 8pt; }
        .accent { height: 2.2mm; margin: 3.5mm 0 5mm; background: linear-gradient(90deg, var(--navy) 0, var(--blue) 82%, var(--orange) 82%); border-radius: 1mm; }

        .title { display: flex; align-items: flex-end; gap: 10px; break-inside: avoid; }
        .title .code { margin: 0; color: var(--blue); font-size: 8.5pt; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .title h1 { margin: 1mm 0 0; color: var(--navy); font-size: 17pt; line-height: 1.2; font-weight: 700; overflow-wrap: anywhere; }
        .title .badge { margin: 0 0 1.2mm auto; font-size: 9pt; }
        .doc-label { margin: 1.5mm 0 0; color: var(--muted); font-size: 8pt; }

        h2 { display: flex; align-items: center; gap: 2.5mm; margin: 6.5mm 0 2.5mm; padding-bottom: 1.6mm; border-bottom: 0.4mm solid var(--blue); color: var(--navy); font-size: 11pt; font-weight: 700; break-after: avoid; }
        h2 .no { display: inline-grid; place-items: center; width: 5.5mm; height: 5.5mm; border-radius: 1.2mm; background: var(--navy); color: #fff; font-size: 8pt; }
        h3 { margin: 4mm 0 1.5mm; color: var(--blue); font-size: 9pt; font-weight: 700; break-after: avoid; }
        section { break-inside: auto; }
        .keep { break-inside: avoid; }

        .badge { display: inline-block; border-radius: 99px; border: 0.25mm solid #94a3b8; background: #f1f5f9; color: #334155; padding: 0.4mm 2.4mm; font-size: 7.5pt; font-weight: 700; white-space: nowrap; }
        td .badge { padding: 0.4mm 1.7mm; font-size: 7pt; white-space: normal; overflow-wrap: normal; word-break: normal; border-radius: 2.5mm; text-align: center; line-height: 1.25; }
        .badge.ok { background: #ecfdf5; border-color: #34d399; color: #047857; }
        .badge.info { background: #eff6ff; border-color: #60a5fa; color: #1d4ed8; }
        .badge.warn { background: #fffbeb; border-color: #f59e0b; color: #92400e; }
        .badge.purple { background: #faf5ff; border-color: #a78bfa; color: #6d28d9; }
        .badge.bad { background: #fff1f2; border-color: #fb7185; color: #be123c; }
        .badge.dark { background: #1e293b; border-color: #1e293b; color: #fff; }

        /* Identitas */
        .ident { display: grid; grid-template-columns: 1fr 1fr; gap: 0 8mm; margin: 0; }
        .ident div { padding: 1.7mm 0; border-bottom: 0.2mm solid var(--line); break-inside: avoid; }
        .ident dt { color: var(--muted); font-size: 7pt; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; }
        .ident dd { margin: 0.4mm 0 0; font-weight: 600; overflow-wrap: anywhere; }
        .ident .wide { grid-column: 1 / -1; }

        /* Kartu ringkasan finansial */
        .cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 3mm; }
        .card { border: 0.25mm solid var(--line); border-left: 1.1mm solid var(--blue); border-radius: 1.5mm; background: var(--blue-soft); padding: 2.4mm 3mm; break-inside: avoid; }
        .card.key { background: #fff; border-left-color: var(--orange); }
        .card .l { color: var(--muted); font-size: 7.2pt; font-weight: 600; }
        .card .v { margin-top: 0.8mm; color: var(--navy); font-size: 10.5pt; font-weight: 700; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .card .v.neg { color: #be123c; }
        .card .h { margin-top: 0.6mm; color: var(--muted); font-size: 6.8pt; }
        .lockchip { margin-left: auto; font-size: 8pt; }

        /* Tabel */
        table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 8pt; }
        thead { display: table-header-group; }
        tfoot { display: table-row-group; }
        th { padding: 1.8mm 1.4mm; background: var(--navy); color: #fff; font-size: 6.8pt; font-weight: 700; letter-spacing: .04em; text-align: left; text-transform: uppercase; vertical-align: bottom; }
        td { padding: 1.7mm 1.4mm; border-bottom: 0.2mm solid var(--line); vertical-align: top; overflow-wrap: anywhere; }
        tr { break-inside: avoid; }
        tbody tr:nth-child(even) td { background: var(--zebra); }
        table.inv { font-size: 7.5pt; }
        th.num, td.num { text-align: right; }
                tfoot td { border-top: 0.4mm solid var(--navy); border-bottom: 0.4mm solid var(--navy); background: var(--blue-soft); color: var(--navy); font-weight: 700; }
        td.empty { padding: 4mm; color: var(--muted); font-style: italic; text-align: center; }
        .sub { display: block; color: var(--muted); font-size: 7pt; font-weight: 400; }
        .strong { font-weight: 700; }
        .over { color: #be123c; font-weight: 700; }
        .flag { display: inline-block; border: 0.25mm solid #f59e0b; border-radius: 99px; background: #fffbeb; color: #92400e; padding: 0 1.8mm; font-size: 7pt; font-weight: 700; }
        .muted { color: var(--muted); }
        tr.cancelled td { color: #64748b; }
        .note { margin: 2mm 0 0; color: var(--muted); font-size: 7.2pt; }

        .pagefoot { margin-top: 8mm; padding-top: 2mm; border-top: 0.2mm solid var(--line); color: var(--muted); font-size: 7.5pt; }

        @media print {
            @page { size: A4 portrait; margin: 14mm 14mm 17mm; @bottom-right { content: 'Halaman ' counter(page) ' dari ' counter(pages); color: #64748b; font: 7.5pt 'Instrument Sans', 'Segoe UI', Arial, sans-serif; } }
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
            .pagefoot { display: none; } /* footer cetak ada di margin-box @page (lihat style di bawah) */
        }
    </style>
    @endverbatim
    <style>
        @media print { @page { @bottom-left { content: {!! $footerCss !!}; color: #64748b; font: 7.5pt 'Instrument Sans', 'Segoe UI', Arial, sans-serif; } } }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <p>Pratinjau cetak. Pilih <strong>Simpan sebagai PDF</strong> pada dialog cetak, ukuran A4.</p>
        <button type="button" class="primary" onclick="window.print()">Cetak / Simpan PDF</button>
        <button type="button" onclick="window.close()">Tutup</button>
    </div>

    <main class="sheet">
        <header class="kop">
            <img src="{{ asset('images/logo.svg') }}" alt="Project Monitoring System" width="270" height="120">
            <div class="org">
                <strong>Project Monitoring System</strong>
                <span>Laporan project</span>
            </div>
        </header>
        <div class="accent" aria-hidden="true"></div>

        <div class="title">
            <div>
                <p class="code">{{ $project->project_code }}</p>
                <h1>{{ $project->project_name }}</h1>
            </div>
            {{ $badge($project->status) }}
        </div>
        <p class="doc-label">Laporan project · Divisi {{ $project->department->value }} · Status {{ $project->status->label() }}</p>

        <section aria-labelledby="p-identitas">
            <h2 id="p-identitas"><span class="no">1</span>Identitas project</h2>
            <dl class="ident keep">
                @foreach ($identity as [$label, $value])
                    <div @class(['wide' => $loop->last])><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
        </section>

        <section aria-labelledby="p-ringkasan">
            <h2 id="p-ringkasan"><span class="no">2</span>Ringkasan finansial <span class="badge lockchip {{ $locked ? 'dark' : '' }}">{{ $locked ? 'Baseline terkunci' : 'Baseline belum terkunci' }}</span></h2>
            <div class="cards">
                @foreach ($cards as [$label, $value, $hint])
                    @php $key = in_array($label, ['Sisa RAB', 'Sisa Akhir (Tutup Buku)', 'Projected Profit', 'Actual Profit'], true); @endphp
                    <div @class(['card', 'key' => $key])>
                        <div class="l">{{ $label }}</div>
                        <div @class(['v', 'neg' => $neg($value)])>{{ Money::format($value) }}</div>
                        <div class="h">{{ $hint }}</div>
                    </div>
                @endforeach
            </div>
            <p class="note">Sisa RAB = RAB Perubahan − Total Nilai RAB semua item BQ. Projected Profit = Target Margin + RAB Perubahan − biaya BQ yang diajukan atau disetujui (item Normal: Nilai Vendor/Subkon; item Operasional: Nilai RAB).</p>
        </section>

        <section aria-labelledby="p-bq">
            <h2 id="p-bq"><span class="no">3</span>Item BQ</h2>
            <table>
                <colgroup><col style="width: 24%"><col style="width: 19%"><col style="width: 19%"><col style="width: 19%"><col style="width: 19%"></colgroup>
                <thead><tr><th>Item</th><th class="num">Nilai RAB</th><th class="num">Nilai Vendor/Subkon</th><th class="num">Realisasi</th><th class="num">Sisa</th></tr></thead>
                <tbody>
                    @forelse ($bqItems as $item)
                        @php $operational = $item->item_type === 'OPERATIONAL'; @endphp
                        <tr>
                            <td>
                                @if ($item->item_code)<span class="sub strong">{{ $item->item_code }}</span>@endif
                                <span class="strong">{{ $item->item_name }}</span>
                                @if ($operational)<span class="sub">Cadangan operasional</span>@endif
                                <span style="display: block; margin-top: 1mm">{{ $badge(BqStatus::from($item->status)) }}</span>
                            </td>
                            <td class="num">{{ Money::format($item->effective_budget) }}@if ($item->baseline_budget !== $item->effective_budget)<span class="sub">awal {{ Money::format($item->baseline_budget) }}</span>@endif</td>
                            <td class="num">{{ $operational ? '—' : Money::format($item->negotiated_value) }}</td>
                            <td class="num">{{ Money::format($item->actual_cost) }}</td>
                            <td @class(['num', 'over' => $item->is_overbudget])>{{ Money::format($item->sisa) }}@if ($item->is_overbudget)<span class="sub over">Overbudget</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty">Belum ada item BQ.</td></tr>
                    @endforelse
                </tbody>
                @if ($bqItems->isNotEmpty())
                    <tfoot><tr>
                        <td>Total</td>
                        <td class="num">{{ Money::format($summary['bq_effective']) }}</td>
                        <td class="num">{{ Money::format($sum($bqItems->where('item_type', 'NORMAL'), 'negotiated_value')) }}</td>
                        <td class="num">{{ Money::format($sum($bqItems, 'actual_cost')) }}</td>
                        <td class="num">{{ Money::format(Money::add(...$bqItems->pluck('sisa')->all())) }}</td>
                    </tr></tfoot>
                @endif
            </table>
            <p class="note">Item BQ berstatus Diarsipkan tidak dihitung. Total Nilai RAB BQ {{ Money::format($summary['bq_effective']) }}; Sisa RAB {{ Money::format($summary['unallocated']) }}.</p>
        </section>

        <section aria-labelledby="p-jadwal">
            <h2 id="p-jadwal"><span class="no">4</span>Jadwal termin &amp; invoice ({{ ['TERMIN' => 'Termin', 'MONTHLY' => 'Bulanan'][$project->payment_type] ?? $project->payment_type }})</h2>
            <table class="inv">
                <colgroup><col style="width: 6%"><col style="width: 6%"><col style="width: 16.5%"><col style="width: 11%"><col style="width: 15%"><col style="width: 11%"><col style="width: 14.5%"><col style="width: 20%"></colgroup>
                <thead><tr><th class="num">Termin</th><th class="num">%</th><th class="num">Nilai rencana</th><th>Tgl rencana</th><th>Invoice</th><th>Jatuh tempo</th><th>Status</th><th>Progres terakhir</th></tr></thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        @php $latest = $invoice->progress->first(); @endphp
                        <tr @class(['cancelled' => $invoice->workflow_status === 'CANCELLED'])>
                            <td class="num strong">{{ $invoice->sequence_number }}</td>
                            <td class="num">{{ $pct($invoice->billing_percentage) }}%</td>
                            <td class="num">{{ Money::format($invoice->planned_amount) }}</td>
                            <td>{{ $date($invoice->planned_billing_date) }}</td>
                            <td>@if ($invoice->invoice_number !== null)<span class="strong">{{ $invoice->invoice_number }}</span><span class="sub">{{ $date($invoice->billing_date) }}</span>@else<span class="muted">—</span>@endif</td>
                            <td>{{ $date($invoice->due_date) }}</td>
                            <td>{{ $badge(InvoiceStatus::from($invoice->workflow_status)) }}</td>
                            <td>
                                @if ($latest)
                                    <span class="strong">{{ InvoiceController::PROGRESS_STAGES[$latest->stage] ?? $latest->stage }}</span>
                                    <span class="sub">{{ $date($latest->progress_date) }}</span>
                                    @if ($invoice->hasIssue)<span class="flag">Kendala</span>@endif
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="empty">Belum ada jadwal invoice.</td></tr>
                    @endforelse
                </tbody>
                @if ($invoices->isNotEmpty())
                    <tfoot><tr>
                        <td>Total</td>
                        <td class="num">{{ $pct(Money::add(...$invoices->where('workflow_status', '<>', 'CANCELLED')->pluck('billing_percentage')->all())) }}%</td>
                        <td class="num">{{ Money::format(Money::add(...$invoices->where('workflow_status', '<>', 'CANCELLED')->pluck('planned_amount')->all())) }}</td>
                        <td colspan="5"><span class="muted" style="font-weight: 400">Tidak termasuk termin yang dibatalkan.</span></td>
                    </tr></tfoot>
                @endif
            </table>
        </section>

        <section aria-labelledby="p-riwayat">
            <h2 id="p-riwayat"><span class="no">5</span>Riwayat penyesuaian &amp; relokasi budget</h2>

            <h3>Penyesuaian</h3>
            <table>
                <colgroup><col style="width: 10%"><col style="width: 30%"><col style="width: 15%"><col style="width: 13%"><col style="width: 14%"><col style="width: 18%"></colgroup>
                <thead><tr><th>Tanggal</th><th>Jenis &amp; perubahan</th><th class="num">Selisih</th><th>Status</th><th>Pengaju / Penyetuju</th><th>Alasan</th></tr></thead>
                <tbody>
                    @forelse ($adjustments as $a)
                        <tr>
                            <td>{{ LocalTime::format($a->submitted_at, false) }}</td>
                            <td>
                                <span class="strong">{{ $a->type_label }}</span>
                                @if ($a->is_billing_percentage)
                                    @foreach ($a->termins as $t)
                                        <span class="sub">Termin {{ $t['sequence_number'] }}: {{ $pct($t['old_percentage']) }}% → {{ $pct($t['new_percentage']) }}% ({{ Money::format($t['old_amount']) }} → {{ Money::format($t['new_amount']) }})</span>
                                    @endforeach
                                @else
                                    @if ($a->is_contract_value)<span class="sub">Nilai tertagih {{ Money::format($a->old['contract_value']) }} → {{ Money::format($a->new['contract_value']) }}</span>@endif
                                    <span class="sub">Target margin {{ $pct($a->old['target_margin_pct']) }}% ({{ Money::format($a->old['target_margin_amount']) }}) → {{ $pct($a->new['target_margin_pct']) }}% ({{ Money::format($a->new['target_margin_amount']) }})</span>
                                    <span class="sub">RAB Perubahan {{ Money::format($a->old['effective_rab']) }} → {{ Money::format($a->new['effective_rab']) }}</span>
                                @endif
                            </td>
                            <td class="num">@if ($a->is_billing_percentage)<span class="muted">—</span>@else{{ $signed($a->difference_amount) }}<span class="sub">{{ $a->difference_label }}</span>@endif</td>
                            <td>{{ $badge(ApprovalStatus::from($a->status)) }}@if ($a->status === 'REVISION_REQUIRED' && $a->revision_reason)<span class="sub">Revisi: {{ $a->revision_reason }}</span>@endif</td>
                            <td><span class="sub" style="margin: 0">Diajukan</span>{{ $a->submitter }}<span class="sub" style="margin-top: 0.8mm">Disetujui</span>{{ $a->approver ?? '—' }}</td>
                            <td>{{ $a->reason }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty">Belum ada penyesuaian.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <h3>Relokasi budget</h3>
            <table>
                <colgroup><col style="width: 10%"><col style="width: 30%"><col style="width: 15%"><col style="width: 13%"><col style="width: 14%"><col style="width: 18%"></colgroup>
                <thead><tr><th>Tanggal</th><th>Jenis &amp; rute</th><th class="num">Nilai</th><th>Status</th><th>Pengaju / Penyetuju</th><th>Alasan</th></tr></thead>
                <tbody>
                    @forelse ($mutations as $m)
                        <tr>
                            <td>{{ LocalTime::format($m->submitted_at, false) }}</td>
                            <td><span class="strong">{{ $mutationTypes[$m->mutation_type] ?? $m->mutation_type }}</span><span class="sub">{{ $m->source_name ?? 'Sisa RAB' }} → {{ $m->destination_name }}</span></td>
                            <td class="num">{{ Money::format($m->amount) }}</td>
                            <td>{{ $badge(ApprovalStatus::from($m->status)) }}@if ($m->status === 'REVISION_REQUIRED' && $m->revision_reason)<span class="sub">Revisi: {{ $m->revision_reason }}</span>@endif</td>
                            <td><span class="sub" style="margin: 0">Diajukan</span>{{ $m->submitter }}<span class="sub" style="margin-top: 0.8mm">Disetujui</span>{{ $m->approver ?? '—' }}</td>
                            <td>{{ $m->reason }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty">Belum ada relokasi budget.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <footer class="pagefoot">{{ $project->project_code }} · Dicetak {{ $printedAt }} oleh {{ $printedBy }}</footer>
    </main>

    <script>
        // Dialog cetak terbuka sendiri; bila diblokir, tombol "Cetak / Simpan PDF" di atas tetap bisa dipakai.
        window.addEventListener('load', () => setTimeout(() => window.print(), 400));
    </script>
</body>
</html>
