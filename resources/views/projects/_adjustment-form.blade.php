{{-- Popup adjustment (PRJ-04), trigger ikut di partial ini: $adjustment null = usulan baru, selain itu perbaikan usulan REVISION_REQUIRED. $contract = koreksi nilai kontrak (jenis CONTRACT_VALUE; nilai kontrak baru dihitung server, tidak diketik). Server memvalidasi dan menghitung ulang semuanya. --}}
@php
    $id = $adjustment?->id;
    $contract = $id ? $adjustment->is_contract_value : ($contract ?? false);
    $key = $id ?? ($contract ? 'koreksi' : 'baru'); // id elemen; error bag usulan baru dipakai bersama kedua jenis, dibedakan lewat adjustment_type yang dikirim
    $bag = $id ? "adjustment-$id" : 'adjustment-new';
    $modal = $id ? "adjust-$id" : ($contract ? 'adjust-contract' : 'adjust-new');
    $bagErrors = $errors->getBag($bag);
    $failed = $bagErrors->any() && ($id || (old('adjustment_type') !== 'BILLING_PERCENTAGE' && (old('adjustment_type') === 'CONTRACT_VALUE') === $contract)); // bag usulan baru dipakai bersama tiga popup
    $v = fn (string $field, $default = null) => $failed ? old($field) : ($adjustment->new[$field] ?? $default);
    $value = $v('margin_value');
    $mode = in_array($v('margin_mode'), ['PCT', 'AMOUNT'], true) ? $v('margin_mode') : 'PCT';
    // Koreksi kontrak: margin terisi persen sekarang; margin base baru dan committed dari server.
    $cc = $contract ? $contractCorrection : null;
    $value = $value ?? ($contract ? $pctIn($cc['current_margin_pct']) : null);
    $base = $contract ? $cc['new_margin_base_amount'] : $project->margin_base_amount;
    $xdata = 'adjustPreview('.\Illuminate\Support\Js::from(['base' => $base, 'rab_now' => $project->effective_rab, 'committed' => $cc['committed_budget'] ?? null, 'margin_mode' => $mode, 'margin_value' => is_scalar($value) ? (string) $value : '']).')';
    $title = $id ? 'Perbaiki dan ajukan ulang '.($contract ? 'Koreksi Nilai Tertagih' : 'penyesuaian') : ($contract ? 'Ajukan Koreksi Nilai Tertagih' : 'Ajukan penyesuaian target margin');
@endphp
<x-form-modal :name="$modal" :bag="$bag" :open="$failed" icon="wallet" size="lg" :files="! $contract" :action="$id ? route('adjustments.resubmit', $id) : route('projects.adjustments.store', $project)" :method="$id ? 'PUT' : 'POST'"
              :title="$title" subtitle="Usulan berstatus Pending sampai Manager/GM memutuskan." :submit-label="$id ? 'Ajukan ulang' : 'Ajukan'" :data="$xdata">
    <x-slot:trigger>
        @if ($id)
            <button type="button" class="btn px-3 py-1.5 text-[13px]" aria-haspopup="dialog" aria-label="Perbaiki penyesuaian {{ $id }}">Perbaiki</button>
        @elseif ($contract)
            <button type="button" class="btn" aria-haspopup="dialog">Ajukan Koreksi Nilai Tertagih</button>
        @else
            <button type="button" class="btn btn-primary" aria-haspopup="dialog">+ Ajukan penyesuaian</button>
        @endif
    </x-slot:trigger>
    @if ($contract && ! $id) <input type="hidden" name="adjustment_type" value="CONTRACT_VALUE"> @endif
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="lbl">Isi target margin {{ $contract ? 'setelah koreksi' : 'baru' }} sebagai
            <select name="margin_mode" required class="inp" x-model="margin_mode" @if ($failed && $bagErrors->has('margin_mode')) aria-invalid="true" aria-describedby="e-{{ $bag }}-margin_mode" @endif>
                <option value="PCT">Persen (%)</option>
                <option value="AMOUNT">Nominal (Rp)</option>
            </select>
            <x-field-error name="margin_mode" :bag="$bag" />
        </label>
        <label class="lbl">Target margin baru <span x-text="margin_mode === 'PCT' ? '(%)' : '(Rp)'"></span>
            <input name="margin_value" x-model="margin_value" required inputmode="decimal" class="inp" @if ($failed && $bagErrors->has('margin_value')) aria-invalid="true" aria-describedby="e-{{ $bag }}-margin_value" @endif>
            <x-field-error name="margin_value" :bag="$bag" />
        </label>
        <label class="lbl sm:col-span-2">Alasan
            <textarea name="reason" rows="2" maxlength="1000" required class="inp" @if ($failed && $bagErrors->has('reason')) aria-invalid="true" aria-describedby="e-{{ $bag }}-reason" @endif>{{ $failed ? old('reason') : ($adjustment->reason ?? '') }}</textarea>
            <x-field-error name="reason" :bag="$bag" />
        </label>
        @unless ($contract)
            <label class="lbl sm:col-span-2">Nota Disposisi Direksi{{ $id && $adjustment->notaUrl ? ' (opsional, mengganti yang lama)' : '' }}
                <input type="file" name="file" @required(! $id || ! $adjustment->notaUrl) accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="inp" @if ($failed && $bagErrors->has('file')) aria-invalid="true" aria-describedby="e-{{ $bag }}-file" @endif>
                <span class="hint">PDF/JPG/PNG maks 50 MB</span>
                <x-field-error name="file" :bag="$bag" />
            </label>
        @endunless
    </div>
    <div class="mt-4 rounded-xl bg-linear-to-b from-brand-blue-soft to-white p-4 ring-1 ring-slate-200/70" role="group" aria-labelledby="hitung-{{ $key }}">
        <h4 id="hitung-{{ $key }}" class="text-sm font-semibold text-slate-900">Dihitung sistem</h4>
        <p class="hint mb-2">Perkiraan, dihitung ulang oleh server saat diajukan dan saat disetujui.</p>
        <p x-show="c.bad" x-cloak role="alert" class="mb-2 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-800">Target margin tidak boleh melebihi dasar target margin atau 100%.</p>
        @if ($contract)
            <p x-show="c.low" x-cloak role="status" class="mb-2 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900"><x-icon name="alert" size="mt-0.5 h-4 w-4 shrink-0" />RAB baru di bawah total BQ yang sudah disetujui ({{ \App\Support\Money::format($cc['committed_budget']) }}) — tetap boleh, karena biaya sudah terjadi.</p>
        @endif
        <dl class="divide-y divide-slate-200/70 text-sm" aria-live="polite">
            @if ($contract)
                <div class="flex justify-between gap-3 py-2"><dt class="text-slate-700">Nilai tertagih</dt><dd class="text-right font-semibold tabular-nums">{{ \App\Support\Money::format($cc['contract_value']) }} → {{ \App\Support\Money::format($cc['new_contract_value']) }}</dd></div>
                <div class="flex justify-between gap-3 py-2"><dt class="text-slate-700">Termin dibatalkan</dt><dd class="text-right font-semibold">{{ collect($cc['cancelled_sequences'])->map(fn ($n) => '#'.$n)->join(', ') ?: '—' }}</dd></div>
                <div class="flex justify-between gap-3 py-2"><dt class="text-slate-700">Dasar target margin</dt><dd class="text-right font-semibold tabular-nums">{{ \App\Support\Money::format($project->margin_base_amount) }} → {{ \App\Support\Money::format($cc['new_margin_base_amount']) }}</dd></div>
            @else
                <div class="flex justify-between gap-3 py-2"><dt class="text-slate-700">Dasar target margin</dt><dd class="font-semibold tabular-nums">{{ \App\Support\Money::format($project->margin_base_amount) }}</dd></div>
            @endif
            <div class="flex justify-between gap-3 py-2"><dt class="text-slate-700">Target margin saat ini</dt><dd class="text-right font-semibold tabular-nums">{{ $pct($project->target_margin_pct) }}% · {{ \App\Support\Money::format($project->target_margin_amount) }}</dd></div>
            <div class="flex justify-between gap-3 py-2"><dt class="text-slate-700">Target margin baru</dt><dd class="text-right font-semibold tabular-nums"><span x-text="c.pct"></span> · <span x-text="c.margin"></span></dd></div>
            <div class="flex justify-between gap-3 py-2"><dt class="text-slate-700">RAB Perubahan saat ini</dt><dd class="font-semibold tabular-nums">{{ \App\Support\Money::format($project->effective_rab) }}</dd></div>
            <div class="flex justify-between gap-3 py-2"><dt class="font-semibold text-slate-900">RAB Perubahan baru</dt><dd class="font-semibold text-brand-blue tabular-nums" x-text="c.rab"></dd></div>
            <div class="flex justify-between gap-3 py-2"><dt class="text-slate-700">Selisih RAB Perubahan</dt><dd class="font-semibold tabular-nums" x-text="c.delta"></dd></div>
            <div class="flex justify-between gap-3 py-2"><dt class="text-slate-700">RAB Awal (tidak berubah)</dt><dd class="font-semibold tabular-nums">{{ \App\Support\Money::format($project->initial_rab) }}</dd></div>
        </dl>
    </div>
    <p class="hint">Mengajukan tidak mengubah project (status Pending). {{ $contract ? 'Nilai tertagih, target margin, dan RAB Perubahan' : 'Target margin dan RAB Perubahan' }} berubah setelah Manager/GM menyetujui; {{ $contract ? 'Nilai Project dan ' : '' }}RAB Awal tidak berubah.</p>
</x-form-modal>
