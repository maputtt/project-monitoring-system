{{-- Form unggah bukti bayar (INV-03): $invoice = baris jadwal WAITING_FOR_PAYMENT atau PAYMENT_REVISION_REQUIRED. Popup dengan trigger di partial ini. --}}
@php
    $id = $invoice->id;
    $bag = "payment-$id";
    $modal = "payment-$id";
    $bagErrors = $errors->getBag($bag);
    $failed = $bagErrors->any(); // bag khusus per popup: terbuka ulang tanpa bergantung pada _modal
@endphp
<x-form-modal :name="$modal" :bag="$bag" :open="$failed" icon="download" :files="true" :action="route('invoices.submit-payment', $id)" :title="($invoice->workflow_status === 'WAITING_FOR_PAYMENT' ? 'Unggah bukti bayar termin ' : 'Unggah ulang bukti bayar termin ').$invoice->sequence_number" :subtitle="$invoice->invoice_number ? 'No. invoice '.$invoice->invoice_number : null" submit-label="Kirim bukti bayar">
    <x-slot:trigger>
        <button type="button" class="btn btn-primary mt-2 px-3 py-1.5 text-[13px]" aria-haspopup="dialog" aria-label="{{ $invoice->workflow_status === 'WAITING_FOR_PAYMENT' ? 'Unggah' : 'Unggah ulang' }} bukti bayar termin {{ $invoice->sequence_number }}">{{ $invoice->workflow_status === 'WAITING_FOR_PAYMENT' ? 'Unggah bukti bayar' : 'Unggah ulang bukti bayar' }}</button>
    </x-slot:trigger>
    @if ($invoice->workflow_status === 'PAYMENT_REVISION_REQUIRED' && $invoice->revision_reason)
        <p class="mb-3 break-words rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Alasan revisi: {{ $invoice->revision_reason }}</p>
    @endif
    <div>
        <label class="lbl">File bukti bayar
            <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="inp" @if ($failed && $bagErrors->has('file')) aria-invalid="true" aria-describedby="e-{{ $bag }}-file" @endif>
            <span class="hint">PDF/JPG/PNG maks 50 MB{{ $invoice->proofUrl ? '; menggantikan bukti lama (bukti lama tetap tersimpan)' : '' }}</span>
            <x-field-error name="file" :bag="$bag" />
        </label>
    </div>
    <p class="hint mt-4">Setelah dikirim, status menjadi Pembayaran Diajukan dan menunggu persetujuan Manager/GM. Anda tidak bisa mengubahnya sampai diputuskan.</p>
</x-form-modal>
