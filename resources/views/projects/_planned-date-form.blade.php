{{-- Ubah tanggal rencana tagih termin PLANNED: $invoice = baris PLANNED, $todayWib = hari ini (WIB, Y-m-d). Popup dengan trigger di partial ini. Alasan wajib bila tanggal rencana lama sudah lewat. --}}
@php
    $id = $invoice->id;
    $bag = "planned-date-$id";
    $modal = "planned-$id";
    $bagErrors = $errors->getBag($bag);
    $failed = $bagErrors->any(); // bag khusus per popup: terbuka ulang tanpa bergantung pada _modal
    $past = $invoice->planned_billing_date !== null && $invoice->planned_billing_date < $todayWib;
    // old() dari request buatan sendiri bisa berupa array; hanya string yang dipakai ulang.
    $oldString = fn (string $field) => is_string($o = old($field)) ? $o : '';
    $value = $failed ? $oldString('planned_billing_date') : ($invoice->planned_billing_date ? \Illuminate\Support\Str::substr($invoice->planned_billing_date, 0, 10) : '');
@endphp
<x-form-modal :name="$modal" :bag="$bag" :open="$failed" icon="/invoices" :action="route('invoices.planned-billing-date', $id)" :title="'Ubah tanggal rencana tagih termin '.$invoice->sequence_number" :subtitle="'Rencana tagihan '.\App\Support\Money::format($invoice->planned_amount)" submit-label="Simpan tanggal rencana">
    <x-slot:trigger>
        <button type="button" class="btn mt-2 ml-0 px-3 py-1.5 text-[13px]" aria-haspopup="dialog" aria-label="Ubah tanggal rencana tagih termin {{ $invoice->sequence_number }}">Ubah tgl rencana</button>
    </x-slot:trigger>
    <input type="hidden" name="version" value="{{ $invoice->version }}">
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="lbl">Tanggal rencana tagih
            <input type="date" name="planned_billing_date" value="{{ $value }}" required class="inp" @if ($failed && $bagErrors->has('planned_billing_date')) aria-invalid="true" aria-describedby="e-{{ $bag }}-planned_billing_date" @endif>
            <x-field-error name="planned_billing_date" :bag="$bag" />
        </label>
        <label class="lbl">Alasan perubahan{{ $past ? ' (wajib)' : ' (opsional)' }}
            <textarea name="reason" rows="2" maxlength="1000" @required($past) class="inp" @if ($failed && $bagErrors->has('reason')) aria-invalid="true" aria-describedby="e-{{ $bag }}-reason" @endif>{{ $failed ? $oldString('reason') : '' }}</textarea>
            <span class="hint">Wajib bila tanggal rencana sudah lewat. Tercatat di audit log.</span>
            <x-field-error name="reason" :bag="$bag" />
        </label>
    </div>
</x-form-modal>
