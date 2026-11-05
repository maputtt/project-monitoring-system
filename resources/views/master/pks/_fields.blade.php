{{-- Isian formulir PKS; dipakai halaman formulir penuh ($modal null) dan popup di daftar. --}}
@php
    use App\Enums\PksStatus;
@endphp
@php($o = fn ($key, $default = null) => ($modal ?? null) === null || old('_modal') === $modal ? old($key, $default) : $default)
<div class="grid gap-4 sm:grid-cols-2">
    <label class="lbl">Nomor PKS
        <input name="pks_number" value="{{ $o('pks_number', $pks->pks_number) }}" required maxlength="100" class="inp">
    </label>
    <label class="lbl">Status
        <select name="status" required class="inp">
            @foreach (PksStatus::cases() as $status)
                <option value="{{ $status->value }}" @selected($o('status', $pks->status?->value) === $status->value)>{{ $status->label() }}</option>
            @endforeach
        </select>
    </label>
    <label class="lbl sm:col-span-2">Nama PKS
        <input name="name" value="{{ $o('name', $pks->name) }}" required maxlength="200" class="inp">
    </label>
    <label class="lbl">Klien / Bouwheer
        <input name="client_name" value="{{ $o('client_name', $pks->client_name) }}" required maxlength="200" class="inp">
    </label>
    <label class="lbl">Mode / peran kerja sama
        <input name="role_mode" value="{{ $o('role_mode', $pks->role_mode) }}" maxlength="50" class="inp">
    </label>
    <label class="lbl">Tanggal terbit
        <input type="date" name="issued_date" value="{{ $o('issued_date', $pks->issued_date?->format('Y-m-d')) }}" required class="inp">
    </label>
    <div class="hidden sm:block"></div>
    <label class="lbl">Tanggal mulai
        <input type="date" name="start_date" value="{{ $o('start_date', $pks->start_date?->format('Y-m-d')) }}" class="inp">
    </label>
    <label class="lbl">Tanggal berakhir
        <input type="date" name="end_date" value="{{ $o('end_date', $pks->end_date?->format('Y-m-d')) }}" class="inp">
    </label>
    <label class="lbl sm:col-span-2">Keterangan
        <textarea name="description" rows="3" class="inp">{{ $o('description', $pks->description) }}</textarea>
    </label>
</div>
