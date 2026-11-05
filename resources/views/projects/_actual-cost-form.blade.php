{{-- Popup realisasi biaya item BQ APPROVED: $item = baris dengan canActual (error bag bq-{id}). Trigger ikut di partial ini. Alasan wajib hanya bila realisasi sudah pernah diisi. --}}
@php
    $id = $item->id;
    $modal = "actual-$id";
    $bag = "bq-$id";
    $bagErrors = $errors->getBag($bag);
    $failed = $bagErrors->any(); // bag khusus per popup: terbuka ulang tanpa bergantung pada _modal
    $filled = $item->actual_cost !== null;
@endphp
<x-form-modal :name="$modal" :bag="$bag" :open="$failed" icon="wallet" :action="route('bq-items.actual-cost', $id)" method="PUT" :title="($filled ? 'Ubah' : 'Isi').' realisasi biaya'" :subtitle="$item->item_name" submit-label="Simpan realisasi">
    <x-slot:trigger>
        <button type="button" class="btn px-3 py-1.5 text-[13px]" aria-haspopup="dialog" aria-label="{{ $filled ? 'Ubah' : 'Isi' }} realisasi biaya {{ $item->item_name }}">{{ $filled ? 'Ubah realisasi biaya' : 'Isi realisasi biaya' }}</button>
    </x-slot:trigger>
    <input type="hidden" name="version" value="{{ $item->version }}">
    <div class="grid gap-4">
        <label class="lbl">Realisasi biaya (Rp)
            <input name="actual_cost" value="{{ $failed ? old('actual_cost') : $item->actual_cost }}" required inputmode="decimal" class="inp" @if ($failed && $bagErrors->has('actual_cost')) aria-invalid="true" aria-describedby="e-{{ $bag }}-actual_cost" @endif>
            <x-field-error name="actual_cost" :bag="$bag" />
        </label>
        @if ($filled || ($failed && $bagErrors->has('reason')))
            <label class="lbl">Alasan perubahan
                <input name="reason" value="{{ $failed ? old('reason') : '' }}" maxlength="1000" required class="inp" @if ($failed && $bagErrors->has('reason')) aria-invalid="true" aria-describedby="e-{{ $bag }}-reason" @endif>
                <x-field-error name="reason" :bag="$bag" />
            </label>
        @endif
    </div>
    <p class="hint">Biaya riil yang dibayar. {{ $filled ? 'Perubahan wajib beralasan dan tercatat di audit log dengan nilai lama dan baru.' : 'Isian pertama tanpa alasan.' }} Tidak mengubah budget atau margin; dipakai saat financial closing.</p>
</x-form-modal>
