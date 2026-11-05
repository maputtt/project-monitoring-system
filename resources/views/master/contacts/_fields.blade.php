{{-- Isian formulir PIC Bouwheer; dipakai halaman formulir penuh ($modal null) dan popup di daftar. --}}
@php($o = fn ($key, $default = null) => ($modal ?? null) === null || old('_modal') === $modal ? old($key, $default) : $default)
<div class="grid gap-4 sm:grid-cols-2">
    <label class="lbl">Nama PIC
        <input name="name" value="{{ $o('name', $contact->name) }}" required maxlength="150" class="inp">
    </label>
    <label class="lbl">Perusahaan
        <input name="company_name" value="{{ $o('company_name', $contact->company_name) }}" required maxlength="200" class="inp">
    </label>
    <label class="lbl">Jabatan
        <input name="position" value="{{ $o('position', $contact->position) }}" maxlength="100" class="inp">
    </label>
    <div class="hidden sm:block"></div>
    <label class="lbl">Nomor telepon
        <input name="phone_number" value="{{ $o('phone_number', $contact->phone_number) }}" maxlength="30" class="inp">
    </label>
    <label class="lbl">Email
        <input type="email" name="email" value="{{ $o('email', $contact->email) }}" maxlength="150" class="inp">
    </label>
    <p class="hint sm:col-span-2">Sebaiknya isi minimal salah satu: nomor telepon atau email.</p>
    <label class="flex items-center gap-2 text-sm font-medium text-slate-700 sm:col-span-2">
        <input type="checkbox" name="is_active" value="1" @checked($o('is_active', $contact->is_active)) class="h-4 w-4 rounded border-slate-300 accent-brand-blue">
        Aktif, bisa dipilih untuk project baru
    </label>
</div>
