{{-- Popup item BQ: $item null = tambah (error bag default), selain itu ubah / isi budget operasional (error bag bq-{id}). Trigger ikut di partial ini. Server memvalidasi ulang semuanya. --}}
@php
    $id = $item?->id;
    $modal = $id ? "bq-$id" : 'bq-new';
    $bag = $id ? "bq-$id" : 'default';
    $bagErrors = $errors->getBag($bag);
    $failed = $bagErrors->any() && ($id || old('_modal') === $modal); // tambah memakai bag default yang dipakai form lain
    $operational = $id && $item->item_type === 'OPERATIONAL';
    $v = fn (string $field) => $failed ? old($field) : ($id ? $item->{$field} : '');
    $fieldRows = $operational ? [['baseline_budget', 'Budget operasional (Rp)', 'decimal']] : $normalFields;
    $verb = $operational ? 'Isi budget' : 'Ubah';
@endphp
<x-form-modal :name="$modal" :bag="$bag" :open="$failed" icon="wallet" :action="$id ? route('bq-items.update', $id) : route('projects.bq-items.store', $project)" :method="$id ? 'PUT' : 'POST'"
              :title="$id ? ($operational ? 'Isi budget operasional' : 'Ubah item BQ') : 'Tambah item BQ'" :subtitle="$id ? $item->item_name : 'Item baru masuk daftar BQ project ini.'" :submit-label="$id ? 'Simpan perubahan' : 'Tambah item'">
    <x-slot:trigger>
        @if ($id)
            <button type="button" class="btn px-3 py-1.5 text-[13px]" aria-haspopup="dialog" aria-label="{{ $verb }} {{ $item->item_name }}">{{ $verb }}</button>
        @else
            <button type="button" class="btn btn-primary" aria-haspopup="dialog">+ Tambah item</button>
        @endif
    </x-slot:trigger>
    @if ($id)
        <input type="hidden" name="version" value="{{ $item->version }}">
    @endif
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach ($fieldRows as [$field, $label, $mode])
            @php $eid = $id ? "e-$bag-$field" : "e-$field"; @endphp
            <label @class(['lbl', 'sm:col-span-2' => $field === 'item_name' || $operational])>{{ $label }}
                <input name="{{ $field }}" value="{{ $v($field) }}" @required(in_array($field, $requiredFields)) @if ($mode === 'decimal') inputmode="decimal" @else maxlength="{{ $field === 'item_name' ? 200 : 100 }}" @endif class="inp" @if ($failed && $bagErrors->has($field)) aria-invalid="true" aria-describedby="{{ $eid }}" @endif>
                <x-field-error :name="$field" :bag="$bag" />
            </label>
        @endforeach
    </div>
    @if ($operational)
        <p class="hint">Cadangan operasional hanya bisa diisi selama project berstatus Draf dan terkunci setelah aktivasi.</p>
    @elseif (! $id)
        <p class="hint">Sisa dan penanda overbudget dihitung dari Nilai RAB, Nilai Vendor/Subkon, dan Realisasi.</p>
    @endif
</x-form-modal>
