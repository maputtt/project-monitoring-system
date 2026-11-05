{{-- Catat progres dokumen penagihan termin: $invoice = baris jadwal yang belum Lunas/Dibatalkan, $todayWib = hari ini (WIB, Y-m-d). Popup dengan trigger di partial ini. --}}
@php
    $id = $invoice->id;
    $bag = "progress-$id";
    $modal = "progress-$id";
    $bagErrors = $errors->getBag($bag);
    $failed = $bagErrors->any(); // bag khusus per popup: terbuka ulang tanpa bergantung pada _modal
    // old() dari request buatan sendiri bisa berupa array; hanya string yang dipakai ulang.
    $oldString = fn (string $field, string $default = '') => $failed ? (is_string($o = old($field)) ? $o : $default) : $default;
@endphp
<x-form-modal :name="$modal" :bag="$bag" :open="$failed" icon="check" :action="route('invoices.progress', $id)" :title="'Catat progres dokumen termin '.$invoice->sequence_number" subtitle="Tahap dokumen penagihan; tercatat di audit log." submit-label="Simpan progres">
    <x-slot:trigger>
        <button type="button" class="btn mt-2 ml-0 px-3 py-1.5 text-[13px]" aria-haspopup="dialog" aria-label="Catat progres termin {{ $invoice->sequence_number }}">Catat progres</button>
    </x-slot:trigger>
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="lbl">Tahap dokumen
            <select name="stage" required class="inp" @if ($failed && $bagErrors->has('stage')) aria-invalid="true" aria-describedby="e-{{ $bag }}-stage" @endif>
                <option value="">Pilih tahap</option>
                @foreach (\App\Http\Controllers\InvoiceController::PROGRESS_STAGES as $code => $label)
                    <option value="{{ $code }}" @selected($oldString('stage') === $code)>{{ $label }}</option>
                @endforeach
            </select>
            <x-field-error name="stage" :bag="$bag" />
        </label>
        <label class="lbl">Tanggal
            <input type="date" name="progress_date" value="{{ $oldString('progress_date', $todayWib) }}" max="{{ $todayWib }}" required class="inp" @if ($failed && $bagErrors->has('progress_date')) aria-invalid="true" aria-describedby="e-{{ $bag }}-progress_date" @endif>
            <x-field-error name="progress_date" :bag="$bag" />
        </label>
        <label class="lbl sm:col-span-2">Keterangan
            <textarea name="description" rows="2" maxlength="1000" required class="inp" @if ($failed && $bagErrors->has('description')) aria-invalid="true" aria-describedby="e-{{ $bag }}-description" @endif>{{ $oldString('description') }}</textarea>
            <x-field-error name="description" :bag="$bag" />
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
            <input type="checkbox" name="is_issue" value="1" @checked($oldString('is_issue') === '1')> Ada kendala
        </label>
    </div>
    <p class="hint mt-4">Catatan tidak bisa diubah atau dihapus; bila keliru, catat progres baru. Tercatat di audit log.</p>
</x-form-modal>
