{{-- Isian formulir versi tarif; dipakai halaman formulir penuh ($modal null) dan popup di daftar. Kualifikasi hanya untuk PPh; server tetap memeriksa ulang. --}}
@php($o = fn ($key, $default = null) => ($modal ?? null) === null || old('_modal') === $modal ? old($key, $default) : $default)
<div class="grid gap-4 sm:grid-cols-2" x-data="{ type: @js($o('tax_type', '')) }">
    <label class="lbl">Jenis pajak
        <select name="tax_type" required class="inp" x-model="type">
            <option value="">— pilih —</option>
            <option value="PPN">PPN</option>
            <option value="PPH">PPh</option>
        </select>
    </label>
    <label class="lbl" x-show="type === 'PPH'">Kualifikasi vendor
        <select name="vendor_qualification" class="inp" :required="type === 'PPH'" :disabled="type !== 'PPH'">
            <option value="">— pilih —</option>
            <option value="A" @selected($o('vendor_qualification') === 'A')>A — perusahaan kecil</option>
            <option value="B" @selected($o('vendor_qualification') === 'B')>B — menengah ke atas</option>
        </select>
    </label>
    <label class="lbl sm:col-span-2">Nama tarif
        <input name="name" value="{{ $o('name') }}" required maxlength="150" class="inp">
    </label>
    <label class="lbl">Tarif (%)
        <input name="rate" value="{{ $o('rate') }}" required inputmode="decimal" placeholder="11.00" class="inp">
    </label>
    <label class="lbl">Berlaku mulai
        <input type="date" name="effective_from" value="{{ $o('effective_from') }}" required class="inp">
    </label>

    <p class="hint sm:col-span-2">Versi terbuka sebelumnya dengan jenis dan kualifikasi yang sama otomatis berakhir sehari sebelum tanggal ini.</p>
</div>
