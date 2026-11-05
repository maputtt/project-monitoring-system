{{-- Form submit invoice (INV-01): $invoice = baris jadwal PLANNED atau INVOICE_REVISION_REQUIRED. Popup dengan trigger di partial ini. Pratinjau pajak memanggil invoices.tax-preview; server menghitung ulang saat submit. --}}
@php
    $id = $invoice->id;
    $bag = "invoice-$id";
    $modal = "invoice-$id";
    $bagErrors = $errors->getBag($bag);
    $failed = $bagErrors->any(); // bag khusus per popup: terbuka ulang tanpa bergantung pada _modal
    // Request buatan sendiri bisa mengirim array di tempat skalar; hanya old() bertipe string yang dipakai ulang.
    $v = fn (string $field) => $failed ? (is_string($o = old($field)) ? $o : null) : ($invoice->{$field} ?? null);
    $xdata = 'invoicePreview('.\Illuminate\Support\Js::from(route('invoices.tax-preview', $id)).', '.\Illuminate\Support\Js::from($v('billing_date') ?? '').', '.\Illuminate\Support\Js::from($v('pph_qualification') ?? '').')';
    $redo = $invoice->workflow_status !== 'PLANNED';
@endphp
<x-form-modal :name="$modal" :bag="$bag" :open="$failed" icon="/invoices" size="lg" :files="true" :action="route('invoices.submit', $id)" :data="$xdata" disable-when="busy"
              :title="($redo ? 'Perbaiki dan ajukan ulang invoice termin ' : 'Ajukan invoice termin ').$invoice->sequence_number" :subtitle="'Rencana tagihan '.\App\Support\Money::format($invoice->planned_amount).'; pajak dihitung server.'" :submit-label="$redo ? 'Ajukan ulang' : 'Ajukan invoice'">
    <x-slot:trigger>
        <button type="button" class="btn btn-primary mt-2 px-3 py-1.5 text-[13px]" aria-haspopup="dialog" aria-label="{{ $redo ? 'Perbaiki' : 'Ajukan' }} invoice termin {{ $invoice->sequence_number }}">{{ $redo ? 'Perbaiki & ajukan ulang' : 'Ajukan invoice' }}</button>
    </x-slot:trigger>
    <input type="hidden" name="version" value="{{ $invoice->version }}">
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
    <div class="grid content-start gap-4 sm:grid-cols-2">
        <label class="lbl">Nomor invoice
            <input name="invoice_number" value="{{ $v('invoice_number') }}" required maxlength="100" class="inp" @if ($failed && $bagErrors->has('invoice_number')) aria-invalid="true" aria-describedby="e-{{ $bag }}-invoice_number" @endif>
            <x-field-error name="invoice_number" :bag="$bag" />
        </label>
        <label class="lbl">Tanggal penagihan
            <input type="date" name="billing_date" x-model="date" @change="load()" required class="inp" @if ($failed && $bagErrors->has('billing_date')) aria-invalid="true" aria-describedby="e-{{ $bag }}-billing_date" @endif>
            <span class="hint">Menentukan tarif PPN yang berlaku</span>
            <x-field-error name="billing_date" :bag="$bag" />
        </label>
        <label class="lbl">Jatuh tempo
            <input type="date" name="due_date" value="{{ $v('due_date') ? \Illuminate\Support\Str::substr($v('due_date'), 0, 10) : '' }}" :min="date || null" required class="inp" @if ($failed && $bagErrors->has('due_date')) aria-invalid="true" aria-describedby="e-{{ $bag }}-due_date" @endif>
            <span class="hint">Tidak boleh lebih awal dari tanggal penagihan</span>
            <x-field-error name="due_date" :bag="$bag" />
        </label>
        <label class="lbl">Nomor BAPP (opsional)
            <input name="bapp_number" value="{{ $v('bapp_number') }}" maxlength="100" class="inp" @if ($failed && $bagErrors->has('bapp_number')) aria-invalid="true" aria-describedby="e-{{ $bag }}-bapp_number" @endif>
            <x-field-error name="bapp_number" :bag="$bag" />
        </label>
        <label class="lbl">Nomor BAST (opsional)
            <input name="bast_number" value="{{ $v('bast_number') }}" maxlength="100" class="inp" @if ($failed && $bagErrors->has('bast_number')) aria-invalid="true" aria-describedby="e-{{ $bag }}-bast_number" @endif>
            <x-field-error name="bast_number" :bag="$bag" />
        </label>
        <label class="lbl">Kualifikasi PPh
            <select name="pph_qualification" x-model="q" @change="load()" required class="inp" @if ($failed && $bagErrors->has('pph_qualification')) aria-invalid="true" aria-describedby="e-{{ $bag }}-pph_qualification" @endif>
                <option value="">Pilih kualifikasi</option>
                <option value="A">A — perusahaan kecil</option>
                <option value="B">B — menengah ke atas</option>
            </select>
            <x-field-error name="pph_qualification" :bag="$bag" />
            <x-field-error name="pph_tax_rate_id" :bag="$bag" />
        </label>
        <label class="lbl">File invoice
            <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="inp" @if ($failed && $bagErrors->has('file')) aria-invalid="true" aria-describedby="e-{{ $bag }}-file" @endif>
            <span class="hint">PDF/JPG/PNG maks 50 MB{{ $invoice->workflow_status === 'PLANNED' ? '' : '; menggantikan file lama (file lama tetap tersimpan)' }}</span>
            <x-field-error name="file" :bag="$bag" />
        </label>
    </div>
    <aside class="rounded-2xl border border-slate-200/70 bg-linear-to-b from-brand-blue-soft to-white p-4 lg:self-start" aria-labelledby="pajak-{{ $id }}">
        <h4 id="pajak-{{ $id }}" class="font-semibold text-slate-900">Pratinjau pajak</h4>
        <p class="hint mb-2">Perkiraan, dihitung server dari tarif pada tanggal penagihan.</p>
        <div class="text-sm" aria-live="polite">
            <template x-if="p">
                <dl class="divide-y divide-slate-200/70">
                    <div class="flex justify-between gap-3 py-2"><dt class="text-slate-700">DPP</dt><dd class="font-semibold tabular-nums" x-text="rp(p.dpp_amount)"></dd></div>
                    <div class="flex justify-between gap-3 py-2"><dt class="text-slate-700">PPN (<span x-text="pct(p.ppn_rate)"></span>%)</dt><dd class="font-semibold tabular-nums" x-text="rp(p.ppn_amount)"></dd></div>
                    <div class="flex justify-between gap-3 py-2"><dt class="font-semibold text-slate-900">Subtotal tagihan</dt><dd class="font-semibold tabular-nums text-slate-900" x-text="rp(p.subtotal_amount)"></dd></div>
                    <div class="flex justify-between gap-3 py-2"><dt class="text-slate-700">PPh (<span x-text="pct(p.pph_rate)"></span>%)</dt><dd class="font-semibold tabular-nums" x-text="rp(p.pph_amount)"></dd></div>
                    <div class="-mx-2 flex justify-between gap-3 rounded-xl bg-emerald-50 px-2 py-2.5 text-emerald-900"><dt class="font-semibold">Diterima bersih</dt><dd class="font-bold tabular-nums" x-text="rp(p.net_received_amount)"></dd></div>
                </dl>
            </template>
            <p x-show="busy" class="text-slate-600">Menghitung...</p>
            <p x-show="! p && ! err && ! busy" class="text-slate-600">Isi tanggal penagihan dan kualifikasi PPh untuk melihat pratinjau pajak.</p>
            <p x-show="err" x-text="err" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 font-medium text-rose-800" role="alert"></p>
        </div>
    </aside>
    </div>
    <p class="hint mt-3">Pratinjau hanya perkiraan; angka final dihitung server saat diajukan dan terkunci sebagai snapshot.</p>
</x-form-modal>
