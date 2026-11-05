{{-- Isian formulir pengguna; dipakai halaman formulir penuh ($modal null) dan popup di daftar. Divisi wajib untuk Staff/PIC dan Manager/GM, tidak berlaku untuk Direksi; server tetap memeriksa ulang. --}}
@php
    use App\Enums\{Department, Role};
@endphp
@php($o = fn ($key, $default = null) => ($modal ?? null) === null || old('_modal') === $modal ? old($key, $default) : $default)
<div class="grid gap-4 sm:grid-cols-2" x-data="{ role: @js($o('role', $user->role?->value ?? '')) }">
    <label class="lbl">Nama lengkap
        <input name="name" value="{{ $o('name', $user->name) }}" required maxlength="150" class="inp">
    </label>
    <label class="lbl">Email
        <input type="email" name="email" value="{{ $o('email', $user->email) }}" required maxlength="150" autocomplete="off" class="inp">
    </label>
    <label class="lbl">Role
        <select name="role" required class="inp" x-model="role">
            <option value="">— pilih —</option>
            @foreach (Role::cases() as $role)
                <option value="{{ $role->value }}" @selected($o('role', $user->role?->value) === $role->value)>{{ $role->label() }}</option>
            @endforeach
        </select>
    </label>
    <label class="lbl" x-show="role !== 'DIREKSI'">Divisi
        <select name="department" class="inp" :required="['STAFF_PIC', 'MANAGER_GM'].includes(role)" :disabled="role === 'DIREKSI'">
            <option value="">— pilih —</option>
            @foreach (Department::cases() as $department)
                <option value="{{ $department->value }}" @selected($o('department', $user->department?->value) === $department->value)>{{ $department->value }} — {{ $department->label() }}</option>
            @endforeach
        </select>
        <span class="hint" x-show="['STAFF_PIC', 'MANAGER_GM'].includes(role)">Menentukan project yang bisa diakses pengguna ini.</span>
    </label>
    <p class="self-end text-sm text-slate-600" x-show="role === 'DIREKSI'" x-cloak>Direksi melihat semua divisi, jadi tidak punya divisi sendiri.</p>
    <label class="lbl">Jabatan
        <input name="position" value="{{ $o('position', $user->position) }}" maxlength="100" class="inp">
    </label>
    <label class="lbl">Nomor telepon
        <input name="phone_number" value="{{ $o('phone_number', $user->phone_number) }}" maxlength="30" class="inp">
    </label>

    @if ($editing)
        <p class="text-sm text-slate-600 sm:col-span-2">Password tidak bisa diubah dari sini. Pengguna menggantinya sendiri lewat "Lupa password?" di halaman masuk.</p>
    @else
        <label class="lbl sm:col-span-2">Password awal
            <input type="password" name="password" required minlength="8" autocomplete="new-password" class="inp">
            <span class="hint">Minimal 8 karakter. Sampaikan ke pengguna lewat jalur yang aman, lalu minta ia menggantinya.</span>
        </label>
    @endif
</div>
