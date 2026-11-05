<?php

namespace App\Http\Requests\Master;

use App\Enums\Department;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Dipakai untuk tambah dan ubah pengguna. Hak aksesnya dijaga middleware role:SYSTEM_ADMIN di route. */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Direksi melihat semua divisi, jadi tidak punya divisi sendiri.
        if ($this->input('role') === Role::DIREKSI->value) {
            $this->merge(['department' => null]);
        }
    }

    public function rules(): array
    {
        $user = $this->route('user');
        $needsDepartment = in_array($this->input('role'), [Role::STAFF_PIC->value, Role::MANAGER_GM->value], true);

        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['required', Rule::enum(Role::class)],
            'department' => [Rule::requiredIf($needsDepartment), 'nullable', Rule::enum(Department::class)],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'position' => ['nullable', 'string', 'max:100'],
            // Password hanya ditetapkan saat akun dibuat. Setelah itu hanya pemilik akun yang menggantinya lewat reset.
            'password' => $user ? ['prohibited'] : ['required', Password::min(8)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama wajib diisi.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah dipakai pengguna lain.',
            'role.required' => 'Role wajib dipilih.',
            'role.enum' => 'Role tidak dikenal.',
            'department.required' => 'Divisi wajib diisi untuk Staff/PIC dan Manager/GM.',
            'department.enum' => 'Divisi harus BM, CP, atau ITBS.',
            'password.required' => 'Password awal wajib diisi.',
            'password.min' => 'Password minimal 8 karakter.',
            'password.prohibited' => 'Password tidak bisa diubah dari halaman ini.',
        ];
    }
}
