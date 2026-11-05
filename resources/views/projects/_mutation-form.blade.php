{{-- Popup Relokasi Budget (BQ-04), trigger ikut di partial ini: $mutation null = usulan baru, selain itu perbaikan usulan REVISION_REQUIRED. Server memvalidasi ulang semuanya. --}}
@php
    $id = $mutation?->id;
    $modal = $id ? "mutation-$id" : 'mutation-new';
    $bag = $id ? "mutation-$id" : 'mutation-new';
    $bagErrors = $errors->getBag($bag);
    $failed = $bagErrors->any(); // bag khusus per popup: terbuka ulang tanpa bergantung pada _modal
    $v = fn (string $field) => $failed ? old($field) : ($mutation->{$field} ?? null);
    $type = $v('mutation_type') ?: 'FROM_UNALLOCATED_RAB';
    $xdata = '{ type: '.\Illuminate\Support\Js::from(in_array($type, \App\Http\Controllers\BudgetMutationController::TYPES, true) ? $type : 'FROM_UNALLOCATED_RAB').' }';
@endphp
<x-form-modal :name="$modal" :bag="$bag" :open="$failed" icon="/approvals" :files="true" :action="$id ? route('budget-mutations.resubmit', $id) : route('projects.budget-mutations.store', $project)" :method="$id ? 'PUT' : 'POST'"
              :title="$id ? 'Perbaiki dan ajukan ulang relokasi' : 'Ajukan Relokasi Budget'" subtitle="Memindahkan budget antar item BQ atau dari/ke Sisa RAB." :submit-label="$id ? 'Ajukan ulang' : 'Ajukan'" :data="$xdata">
    <x-slot:trigger>
        @if ($id)
            <button type="button" class="btn px-3 py-1.5 text-[13px]" aria-haspopup="dialog" aria-label="Perbaiki relokasi {{ $id }}">Perbaiki</button>
        @else
            <button type="button" class="btn btn-primary" aria-haspopup="dialog">+ Ajukan Relokasi Budget</button>
        @endif
    </x-slot:trigger>
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="lbl">Jenis relokasi
            <select name="mutation_type" required class="inp" x-model="type" @if ($failed && $bagErrors->has('mutation_type')) aria-invalid="true" aria-describedby="e-{{ $bag }}-mutation_type" @endif>
                <option value="FROM_UNALLOCATED_RAB">Dari Sisa RAB</option>
                <option value="TRANSFER_BETWEEN_BQ">Antar item BQ</option>
                <option value="RETURN_TO_UNALLOCATED_RAB">Kembalikan ke Sisa RAB</option>
            </select>
            <x-field-error name="mutation_type" :bag="$bag" />
        </label>
        <label class="lbl" x-show="type !== 'FROM_UNALLOCATED_RAB'">Item BQ sumber
            <select name="source_bq_id" class="inp" :disabled="type === 'FROM_UNALLOCATED_RAB'" :required="type !== 'FROM_UNALLOCATED_RAB'" @if ($failed && $bagErrors->has('source_bq_id')) aria-invalid="true" aria-describedby="e-{{ $bag }}-source_bq_id" @endif>
                <option value="">Pilih item</option>
                @foreach ($approvedBq as $item)
                    <option value="{{ $item->id }}" @selected((string) $v('source_bq_id') === (string) $item->id)>{{ ($item->item_code ? $item->item_code.' - ' : '').$item->item_name }} (Nilai RAB {{ \App\Support\Money::format($item->effective_budget) }}, sisa {{ \App\Support\Money::format($item->sisa) }})</option>
                @endforeach
            </select>
            <x-field-error name="source_bq_id" :bag="$bag" />
        </label>
        <label class="lbl" x-show="type !== 'RETURN_TO_UNALLOCATED_RAB'">Item BQ tujuan
            <select name="destination_bq_id" :disabled="type === 'RETURN_TO_UNALLOCATED_RAB'" :required="type !== 'RETURN_TO_UNALLOCATED_RAB'" class="inp" @if ($failed && $bagErrors->has('destination_bq_id')) aria-invalid="true" aria-describedby="e-{{ $bag }}-destination_bq_id" @endif>
                <option value="">Pilih item</option>
                @foreach ($approvedBq as $item)
                    <option value="{{ $item->id }}" @selected((string) $v('destination_bq_id') === (string) $item->id)>{{ ($item->item_code ? $item->item_code.' - ' : '').$item->item_name }} (Nilai RAB {{ \App\Support\Money::format($item->effective_budget) }}, sisa {{ \App\Support\Money::format($item->sisa) }})</option>
                @endforeach
            </select>
            <x-field-error name="destination_bq_id" :bag="$bag" />
        </label>
        <label class="lbl">Nominal (Rp)
            <input name="amount" value="{{ $v('amount') }}" required inputmode="decimal" class="inp" @if ($failed && $bagErrors->has('amount')) aria-invalid="true" aria-describedby="e-{{ $bag }}-amount" @endif>
            <x-field-error name="amount" :bag="$bag" />
        </label>
        <label class="lbl sm:col-span-2">Alasan
            <textarea name="reason" rows="2" maxlength="1000" required class="inp" @if ($failed && $bagErrors->has('reason')) aria-invalid="true" aria-describedby="e-{{ $bag }}-reason" @endif>{{ $v('reason') }}</textarea>
            <x-field-error name="reason" :bag="$bag" />
        </label>
        <label class="lbl sm:col-span-2">Nota Disposisi{{ $id ? ' (opsional, mengganti yang lama)' : '' }}
            <input type="file" name="file" @required(! $id) accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="inp" @if ($failed && $bagErrors->has('file')) aria-invalid="true" aria-describedby="e-{{ $bag }}-file" @endif>
            <span class="hint">PDF/JPG/PNG maks 50 MB</span>
            <x-field-error name="file" :bag="$bag" />
        </label>
    </div>
    <p class="hint">Mengajukan tidak mengubah saldo. Saldo sumber dan tujuan berubah setelah Manager/GM menyetujui, dan dicek ulang saat itu.</p>
</x-form-modal>
