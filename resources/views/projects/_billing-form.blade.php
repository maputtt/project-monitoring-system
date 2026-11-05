{{-- Popup Koreksi Persentase Termin (trigger ikut di partial ini): $adjustment null = usulan baru, selain itu perbaikan usulan REVISION_REQUIRED. Isian = persen termin PLANNED saat ini (kunci billing_percentages[invoice_id]); server memvalidasi dan menghitung ulang semuanya. Tanpa lampiran. --}}
@php
    $id = $adjustment?->id;
    $bag = $id ? "adjustment-$id" : 'adjustment-new';
    $modal = $id ? "adjust-$id" : 'adjust-billing';
    $bagErrors = $errors->getBag($bag);
    $failed = $bagErrors->any() && ($id || old('adjustment_type') === 'BILLING_PERCENTAGE');
    $proposed = $adjustment ? collect($adjustment->new['termins'])->pluck('billing_percentage', 'invoice_id') : collect();
    $total = $billingCorrection['total_percentage'];
    $vals = $orig = [];
    foreach ($billingCorrection['termins'] as $t) {
        $orig[$t['invoice_id']] = $t['billing_percentage'];
        $vals[$t['invoice_id']] = $failed ? (string) old('billing_percentages.'.$t['invoice_id']) : $pctIn($proposed[$t['invoice_id']] ?? $t['billing_percentage']);
    }
    $key = $id ?? 'baru';
    $xdata = 'billingPreview('.\Illuminate\Support\Js::from(['total' => $total, 'orig' => $orig, 'vals' => $vals]).')';
@endphp
<x-form-modal :name="$modal" :bag="$bag" :open="$failed" icon="/invoices" size="lg" :action="$id ? route('adjustments.resubmit', $id) : route('projects.adjustments.store', $project)" :method="$id ? 'PUT' : 'POST'"
              :title="$id ? 'Perbaiki dan ajukan ulang Koreksi Persentase Termin' : 'Ajukan Koreksi Persentase Termin'" subtitle="Usulan berstatus Pending sampai Manager/GM memutuskan." :submit-label="$id ? 'Ajukan ulang' : 'Ajukan'" :data="$xdata" disable-when="! s.ok">
    <x-slot:trigger>
        @if ($id)
            <button type="button" class="btn px-3 py-1.5 text-[13px]" aria-haspopup="dialog" aria-label="Perbaiki penyesuaian {{ $id }}">Perbaiki</button>
        @else
            <button type="button" class="btn" aria-haspopup="dialog">Koreksi Persentase Termin</button>
        @endif
    </x-slot:trigger>
    @unless ($id) <input type="hidden" name="adjustment_type" value="BILLING_PERCENTAGE"> @endunless
    <p class="hint !mt-0 mb-3">Hanya termin yang belum ditagih (Direncanakan) yang bisa dikoreksi. Termin terakhir harus belum ditagih. Total persentase termin di bawah ini harus tetap {{ $pct($total) }}%. Termin yang sudah ditagih tidak berubah.</p>
    <div class="mt-2 grid gap-4 sm:grid-cols-2">
        @foreach ($billingCorrection['termins'] as $t)
            @php $field = 'billing_percentages.'.$t['invoice_id']; @endphp
            <label class="lbl">Termin {{ $t['sequence_number'] }} (%)
                <input name="billing_percentages[{{ $t['invoice_id'] }}]" x-model="vals[{{ $t['invoice_id'] }}]" required inputmode="decimal" class="inp" @if ($failed && $bagErrors->has($field)) aria-invalid="true" aria-describedby="e-{{ $bag }}-{{ $field }}" @endif>
                <span class="hint">Saat ini {{ $pct($t['billing_percentage']) }}% · rencana tagihan {{ \App\Support\Money::format($t['planned_amount']) }}</span>
                <x-field-error :name="$field" :bag="$bag" />
            </label>
        @endforeach
        <label class="lbl sm:col-span-2">Alasan
            <textarea name="reason" rows="2" maxlength="1000" required class="inp" @if ($failed && $bagErrors->has('reason')) aria-invalid="true" aria-describedby="e-{{ $bag }}-reason" @endif>{{ $failed ? old('reason') : ($adjustment->reason ?? '') }}</textarea>
            <x-field-error name="reason" :bag="$bag" />
        </label>
    </div>
    <div class="mt-4 rounded-xl bg-linear-to-b from-brand-blue-soft to-white p-4 ring-1 ring-slate-200/70" role="group" aria-labelledby="hitung-{{ $key }}-persentase">
        <h4 id="hitung-{{ $key }}-persentase" class="text-sm font-semibold text-slate-900">Pemeriksaan total</h4>
        <dl class="divide-y divide-slate-200/70 text-sm" aria-live="polite">
            <div class="flex justify-between gap-3 py-2"><dt class="text-slate-700">Total persentase sekarang</dt><dd class="font-semibold tabular-nums" x-text="s.sum"></dd></div>
            <div class="flex justify-between gap-3 py-2"><dt class="text-slate-700">Total yang harus tetap</dt><dd class="font-semibold tabular-nums">{{ $pct($total) }}%</dd></div>
            <div class="flex items-start gap-1.5 py-2 font-semibold" :class="s.ok ? 'text-emerald-700' : 'text-rose-700'"><x-icon name="check" size="mt-0.5 h-4 w-4 shrink-0" x-show="s.ok" /><x-icon name="alert" size="mt-0.5 h-4 w-4 shrink-0" x-show="! s.ok" /><span x-text="s.msg"></span></div>
        </dl>
    </div>
    <p class="hint">Mengajukan tidak mengubah project (status Pending). Persentase dan rencana tagihan termin berubah setelah Manager/GM menyetujui; rencana tagihan dihitung ulang oleh server.</p>
</x-form-modal>
