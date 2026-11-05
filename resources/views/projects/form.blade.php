@php
    use App\Support\Money;
    use Illuminate\Support\Str;
    $editing = $project->exists;
    $title = $editing ? 'Ubah project draft' : 'Project baru';
    // Request buatan sendiri bisa mengirim array di tempat skalar (atau sebaliknya); old() dibaca sebagai string agar halaman tidak error.
    $val = fn (string $key, mixed $default = null): string => is_scalar($v = old($key, $default)) ? (string) $v : '';
    $date = fn (string $field) => $val($field, $project->{$field}?->format('Y-m-d'));
    $old = old('billing_percentages', $editing ? $invoices->pluck('billing_percentage')->all() : ['100']);
    $rows = collect(is_array($old) ? $old : [$old])->map(fn ($r) => is_scalar($r) ? (string) $r : '')->values()->all() ?: [''];
    // Tanggal rencana tagih sejajar dengan baris persentase; dipotong atau diisi kosong agar panjangnya sama.
    $oldDates = old('planned_billing_dates', $editing ? $invoices->map(fn ($i) => $i->planned_billing_date ? Str::substr($i->planned_billing_date, 0, 10) : '')->all() : []);
    $dates = collect($rows)->keys()->map(fn ($i) => is_array($oldDates) && is_scalar($oldDates[$i] ?? null) ? (string) $oldDates[$i] : '')->all();
    // Isian yang salah di server: ditandai aria-invalid dan dihubungkan ke pesannya (id e-{name} dari x-field-error).
    $bad = fn (string $name) => $errors->has($name) ? 'aria-invalid="true" aria-describedby="e-'.$name.'"' : '';
    // Nilai awal pratinjau = nilai yang tampil di isian (old() lebih dulu); kuncinya sama dengan name isian.
    // Saat mengubah, target margin diisi dari nominal tersimpan: persen hasil pembulatan tidak boleh menggeser nominal.
    $preview = [
        'ppn' => $ppnRates,
        'rows' => $rows,
        'dates' => $dates,
        'dateErr' => collect($errors->get('planned_billing_dates.*'))->mapWithKeys(fn ($m, $k) => [(int) Str::after($k, '.') => $m[0]]),
        'rowErr' => collect($errors->get('billing_percentages.*'))->mapWithKeys(fn ($m, $k) => [(int) Str::after($k, '.') => $m[0]]),
        'spk_date' => $date('spk_date'),
        'contract_value' => $val('contract_value', $project->contract_value),
        'contract_tax_mode' => $val('contract_tax_mode', $project->contract_tax_mode ?? 'INCLUSIVE_PPN'),
        'margin_mode' => $val('margin_mode', $editing ? 'AMOUNT' : 'PCT'),
        'margin_value' => $val('margin_value', $editing ? $project->target_margin_amount : null),
    ];
@endphp
<x-layouts.app :title="$title">
    <div class="page-head">
        <h1>{{ $editing ? $project->project_name : $title }}</h1>
        @if ($editing)
            <x-status-badge :status="$project->status" />
            <span class="text-sm text-slate-600">{{ $project->project_code }}</span>
            <a href="{{ route('projects.show', $project) }}" class="btn ml-auto">Lihat detail</a>
        @endif
    </div>

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]" x-data="projectPreview(@js($preview))">
        <div class="min-w-0 space-y-6">
            <form method="POST" action="{{ $editing ? route('projects.update', $project) : route('projects.store') }}" class="card flex flex-col gap-6">
                @csrf
                @if ($editing)
                    @method('PUT')
                    <input type="hidden" name="version" value="{{ $project->version }}">
                @endif

                <section class="grid gap-4 sm:grid-cols-2" aria-labelledby="s-kontrak">
                    <h2 id="s-kontrak" class="sm:col-span-2">Kontrak dan PKS</h2>
                    <label class="lbl">PKS
                        <select name="pks_id" required class="inp" {!! $bad('pks_id') !!}>
                            <option value="">— pilih —</option>
                            @foreach ($pksList as $pks)
                                <option value="{{ $pks->id }}" @selected($val('pks_id', $project->pks_id) === (string) $pks->id)>{{ $pks->pks_number }} — {{ $pks->name }}</option>
                            @endforeach
                        </select>
                        <x-field-error name="pks_id" />
                    </label>
                    <label class="lbl">PIC Bouwheer
                        <select name="bouwheer_contact_id" required class="inp" {!! $bad('bouwheer_contact_id') !!}>
                            <option value="">— pilih —</option>
                            @foreach ($contacts as $contact)
                                <option value="{{ $contact->id }}" @selected($val('bouwheer_contact_id', $project->bouwheer_contact_id) === (string) $contact->id)>{{ $contact->name }} — {{ $contact->company_name }}</option>
                            @endforeach
                        </select>
                        <x-field-error name="bouwheer_contact_id" />
                    </label>
                    <label class="lbl">Kode project
                        <input name="project_code" value="{{ $val('project_code', $project->project_code) }}" required maxlength="100" class="inp" {!! $bad('project_code') !!}>
                        <x-field-error name="project_code" />
                    </label>
                    <label class="lbl">Nama project
                        <input name="project_name" value="{{ $val('project_name', $project->project_name) }}" required maxlength="200" class="inp" {!! $bad('project_name') !!}>
                        <x-field-error name="project_name" />
                    </label>
                    <label class="lbl sm:col-span-2">Lokasi
                        <input name="location" value="{{ $val('location', $project->location) }}" required maxlength="255" class="inp" {!! $bad('location') !!}>
                        <x-field-error name="location" />
                    </label>
                    <label class="lbl">Nomor SPK
                        <input name="spk_number" value="{{ $val('spk_number', $project->spk_number) }}" required maxlength="100" class="inp" {!! $bad('spk_number') !!}>
                        <x-field-error name="spk_number" />
                    </label>
                    <label class="lbl">Tanggal SPK
                        <input type="date" name="spk_date" x-model="spk_date" required class="inp" {!! $bad('spk_date') !!}>
                        <x-field-error name="spk_date" />
                    </label>
                </section>

                <section class="grid gap-4 border-t border-slate-100 pt-6 sm:grid-cols-2" aria-labelledby="s-nilai">
                    <h2 id="s-nilai" class="sm:col-span-2">Nilai dan margin</h2>
                    <label class="lbl">Nilai Project (Rp)
                        <input name="contract_value" x-model="contract_value" required inputmode="decimal" placeholder="671151608.00" class="inp" {!! $bad('contract_value') !!}>
                        <x-field-error name="contract_value" />
                    </label>
                    <label class="lbl">Mode pajak kontrak
                        <select name="contract_tax_mode" x-model="contract_tax_mode" required class="inp" {!! $bad('contract_tax_mode') !!}>
                            @foreach (['INCLUSIVE_PPN' => 'Termasuk PPN', 'EXCLUSIVE_PPN' => 'Belum termasuk PPN', 'NON_PPN' => 'Tanpa PPN'] as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <x-field-error name="contract_tax_mode" />
                    </label>
                    <label class="lbl">Target margin diisi sebagai
                        <select name="margin_mode" x-model="margin_mode" required class="inp" {!! $bad('margin_mode') !!}>
                            <option value="PCT">Persen (%)</option>
                            <option value="AMOUNT">Nominal (Rp)</option>
                        </select>
                        <x-field-error name="margin_mode" />
                    </label>
                    <label class="lbl">Nilai target margin
                        <input name="margin_value" x-model="margin_value" required inputmode="decimal" class="inp" {!! $bad('margin_value') !!}>
                        <span class="hint">Persen: sampai empat desimal. Nominal: sampai dua desimal, tidak melebihi dasar target margin.</span>
                        <x-field-error name="margin_value" />
                    </label>
                </section>

                <section class="grid gap-4 border-t border-slate-100 pt-6 sm:grid-cols-2" aria-labelledby="s-tagihan">
                    <h2 id="s-tagihan" class="sm:col-span-2">Jadwal tagihan</h2>
                    <label class="lbl">Tipe pembayaran
                        <select name="payment_type" required class="inp" {!! $bad('payment_type') !!}>
                            <option value="TERMIN" @selected($val('payment_type', $project->payment_type ?? 'TERMIN') === 'TERMIN')>Termin</option>
                            <option value="MONTHLY" @selected($val('payment_type', $project->payment_type) === 'MONTHLY')>Bulanan</option>
                        </select>
                        <x-field-error name="payment_type" />
                    </label>

                    <div class="sm:col-span-2" role="group" aria-labelledby="l-persen" @error('billing_percentages') aria-describedby="e-billing_percentages" @enderror>
                        <p id="l-persen" class="lbl">Persentase penagihan (total harus tepat 100%) dan rencana tanggal tagih (opsional)</p>
                        <div x-ref="list">
                            <template x-for="(row, i) in rows" :key="i">
                                <div class="mt-1.5">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="w-6 text-sm text-slate-600" x-text="i + 1" aria-hidden="true"></span>
                                        <input name="billing_percentages[]" x-model="rows[i]" @input="delete rowErr[i]" required inputmode="decimal" placeholder="mis. 30" :aria-label="'Persentase penagihan ke-' + (i + 1) + ' (%)'" :aria-invalid="rowErr[i] ? 'true' : null" :aria-describedby="rowErr[i] ? 'e-row-' + i : null" class="inp mt-0 max-w-40">
                                        <span class="text-sm text-slate-600" aria-hidden="true">%</span>
                                        <label class="flex items-center gap-1.5 text-sm text-slate-600"><span>Rencana tgl tagih</span>
                                            <input type="date" name="planned_billing_dates[]" x-model="dates[i]" @input="delete dateErr[i]" :aria-label="'Rencana tanggal tagih termin ke-' + (i + 1)" :aria-invalid="dateErr[i] ? 'true' : null" :aria-describedby="dateErr[i] ? 'e-date-' + i : null" class="inp mt-0 w-auto">
                                        </label>
                                        <button type="button" class="btn" x-show="rows.length > 1" @click="remove(i)" :aria-label="'Hapus baris ' + (i + 1)">Hapus</button>
                                    </div>
                                    <p x-show="dateErr[i]" x-text="dateErr[i]" :id="'e-date-' + i" role="alert" class="mt-1 ml-8 text-xs font-medium text-rose-700"></p>
                                    <p x-show="rowErr[i]" x-text="rowErr[i]" :id="'e-row-' + i" role="alert" class="mt-1 ml-8 text-xs font-medium text-rose-700"></p>
                                </div>
                            </template>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2">
                            <button type="button" class="btn" x-show="rows.length < {{ $maxPayments }}" @click="add()">+ Tambah baris</button>
                            <p role="status" class="flex items-center gap-1.5 text-sm font-semibold" :class="c.ok ? 'text-emerald-800' : 'text-rose-700'">
                                <x-icon name="check" size="h-4 w-4" x-show="c.ok" />
                                <x-icon name="alert" size="h-4 w-4" x-show="! c.ok" />
                                <span x-text="c.count + ' baris, total ' + c.total + (c.ok ? ' — sesuai' : (c.bad ? ' — ada baris kosong atau nol, total harus tepat 100%' : ' — harus tepat 100%'))"></span>
                            </p>
                        </div>
                        <x-field-error name="billing_percentages" />
                    </div>
                </section>

                <section class="grid gap-4 border-t border-slate-100 pt-6 sm:grid-cols-2" aria-labelledby="s-periode">
                    <h2 id="s-periode" class="sm:col-span-2">Periode</h2>
                    <label class="lbl">Tanggal mulai
                        <input type="date" name="start_date" value="{{ $date('start_date') }}" required class="inp" {!! $bad('start_date') !!}>
                        <x-field-error name="start_date" />
                    </label>
                    <label class="lbl">Tanggal selesai
                        <input type="date" name="end_date" value="{{ $date('end_date') }}" required class="inp" {!! $bad('end_date') !!}>
                        <x-field-error name="end_date" />
                    </label>
                </section>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ $editing ? 'Simpan perubahan' : 'Simpan project draft' }}</button>
                    <a href="{{ route('home') }}" class="btn">Batal</a>
                </div>
            </form>

            @if ($editing)
                <section class="card" aria-labelledby="tersimpan">
                    <h2 id="tersimpan">Angka tersimpan di server</h2>
                    <p class="hint mb-3">Resmi. Pratinjau "Dihitung sistem" hanya perkiraan sebelum disimpan.</p>
                    <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        <div><dt class="hint">Divisi</dt><dd>{{ $project->department->value }}</dd></div>
                        <div><dt class="hint">Dasar target margin</dt><dd class="num">{{ Money::format($project->margin_base_amount) }}</dd></div>
                        @if ($project->margin_base_ppn_rate_snapshot !== null)
                            <div><dt class="hint">Tarif PPN (snapshot pada tanggal SPK)</dt><dd>{{ Money::pct($project->margin_base_ppn_rate_snapshot) }}%</dd></div>
                        @endif
                        <div><dt class="hint">Target margin</dt><dd class="num">{{ Money::pct($project->target_margin_pct) }}% = {{ Money::format($project->target_margin_amount) }}</dd></div>
                        <div><dt class="hint">RAB Awal</dt><dd class="num">{{ Money::format($project->initial_rab) }}</dd></div>
                        <div><dt class="hint">RAB Perubahan</dt><dd class="num">{{ Money::format($project->effective_rab) }}</dd></div>
                    </dl>

                    <h3 class="mt-5 mb-2 text-sm font-semibold text-slate-900">Jadwal penagihan (rencana)</h3>
                    <div class="table-card">
                        <table class="w-full">
                            <thead><tr><th class="th">Ke-</th><th class="th num">Persentase</th><th class="th num">Rencana tagihan</th><th class="th num">Rencana tgl tagih</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($invoices as $invoice)
                                    <tr><td class="td">{{ $invoice->sequence_number }}</td><td class="td num">{{ Money::pct($invoice->billing_percentage) }}%</td><td class="td num">{{ Money::format($invoice->planned_amount) }}</td><td class="td num">{{ $invoice->planned_billing_date ? \Illuminate\Support\Carbon::parse($invoice->planned_billing_date)->format('d/m/Y') : '—' }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>

        <aside class="card bg-linear-to-b from-brand-blue-soft to-white lg:sticky lg:top-24 lg:max-h-[calc(100vh-7rem)] lg:overflow-y-auto" aria-labelledby="dihitung">
            <h2 id="dihitung">Dihitung sistem</h2>
            <p class="hint mb-3">Perkiraan, dihitung ulang oleh server saat disimpan. Angka resmi tampil di halaman ubah setelah disimpan.</p>
            <p x-show="c.error" x-text="c.error" class="mb-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-800"></p>
            <dl class="divide-y divide-slate-200/70 text-sm" aria-live="polite">
                <div class="flex justify-between gap-3 py-2.5" x-show="contract_tax_mode === 'EXCLUSIVE_PPN'"><dt class="text-slate-700">Tarif PPN pada tanggal SPK</dt><dd class="text-right font-semibold" x-text="c.rate ?? '—'"></dd></div>
                <div class="flex justify-between gap-3 py-2.5"><dt class="text-slate-700">Dasar target margin</dt><dd class="font-semibold tabular-nums" x-text="c.base"></dd></div>
                <div class="flex justify-between gap-3 py-2.5"><dt class="text-slate-700">Target margin</dt><dd class="text-right font-semibold tabular-nums"><span x-text="c.pct"></span><br><span x-text="c.margin"></span></dd></div>
                <div class="flex justify-between gap-3 py-2.5"><dt class="font-semibold text-slate-900">RAB Awal</dt><dd class="font-semibold text-brand-blue tabular-nums" x-text="c.rab"></dd></div>
                <div class="flex justify-between gap-3 py-2.5"><dt class="text-slate-700">RAB Perubahan</dt><dd class="font-semibold tabular-nums" x-text="c.rab"></dd></div>
            </dl>

            <h3 class="mt-4 mb-1 text-sm font-semibold text-slate-900">Jadwal tagihan (rencana)</h3>
            <ul class="divide-y divide-slate-200/70 text-sm" aria-live="polite">
                <template x-for="l in c.lines" :key="l.n">
                    <li class="flex justify-between gap-3 py-2"><span class="text-slate-700" x-text="l.n + '. ' + l.pct"></span><span class="font-semibold tabular-nums" x-text="l.amount"></span></li>
                </template>
                <li class="flex justify-between gap-3 py-2 font-semibold text-slate-900"><span x-text="'Total ' + c.total"></span><span class="tabular-nums" x-text="c.totalAmount"></span></li>
            </ul>
            <p class="hint">Baris terakhir menyerap selisih pembulatan, sehingga total tagihan sama dengan dasar target margin.</p>
        </aside>
    </div>
</x-layouts.app>
