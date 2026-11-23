<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Data awal untuk development saja: satu akun per role (password: "password") dan tarif pajak dasar.
// Jangan dijalankan di production; akun dan tarif production diisi lewat aplikasi.
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Staging (publik): password demo wajib dari env SEED_PASSWORD (min. 12 karakter), bukan "password".
        $seedPassword = (string) env('SEED_PASSWORD');
        if (! app()->environment(['local', 'testing']) && strlen($seedPassword) < 12) {
            throw new \RuntimeException('Set SEED_PASSWORD (min. 12 karakter) sebelum seed di luar lingkungan local/testing.');
        }

        // Tanpa User::factory(): Faker adalah dependency dev dan tidak terpasang di server (composer --no-dev).
        $hash = Hash::make($seedPassword !== '' ? $seedPassword : 'password');
        $make = fn (string $role, ?string $department, string $name, string $email) => User::create([
            'role' => $role, 'department' => $department, 'name' => $name, 'email' => $email, 'password_hash' => $hash, 'is_active' => true,
        ]);
        $make('SYSTEM_ADMIN', 'ITBS', 'Admin Sistem', 'admin@example.com');
        $staff = $make('STAFF_PIC', 'BM', 'Rina', 'staff.bm@example.com');
        $make('MANAGER_GM', 'BM', 'Budi', 'manager.bm@example.com');
        $make('DIREKSI', null, 'Ibu Sari', 'direksi@example.com');

        DB::table('tax_rates')->insert([
            ['tax_type' => 'PPN', 'name' => 'PPN 11%', 'vendor_qualification' => null, 'rate' => '11.00', 'effective_from' => '2022-04-01', 'created_by' => $staff->id],
            ['tax_type' => 'PPH', 'name' => 'PPh Kualifikasi A — perusahaan kecil', 'vendor_qualification' => 'A', 'rate' => '1.75', 'effective_from' => '2022-01-01', 'created_by' => $staff->id],
            ['tax_type' => 'PPH', 'name' => 'PPh Kualifikasi B — menengah ke atas', 'vendor_qualification' => 'B', 'rate' => '2.65', 'effective_from' => '2022-01-01', 'created_by' => $staff->id],
        ]);
    }
}
