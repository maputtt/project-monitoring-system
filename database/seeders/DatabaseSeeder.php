<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// Data awal untuk development saja: satu akun per role (password: "password") dan tarif pajak dasar.
// Jangan dijalankan di production; akun dan tarif production diisi lewat aplikasi.
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $admin = User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create(['name' => 'Admin Sistem', 'email' => 'admin@example.com']);
        $staff = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => 'Rina', 'email' => 'staff.bm@example.com']);
        User::factory()->role('MANAGER_GM', 'BM')->create(['name' => 'Budi', 'email' => 'manager.bm@example.com']);
        User::factory()->role('DIREKSI', null)->create(['name' => 'Ibu Sari', 'email' => 'direksi@example.com']);

        DB::table('tax_rates')->insert([
            ['tax_type' => 'PPN', 'name' => 'PPN 11%', 'vendor_qualification' => null, 'rate' => '11.00', 'effective_from' => '2022-04-01', 'created_by' => $staff->id],
            ['tax_type' => 'PPH', 'name' => 'PPh Kualifikasi A — perusahaan kecil', 'vendor_qualification' => 'A', 'rate' => '1.75', 'effective_from' => '2022-01-01', 'created_by' => $staff->id],
            ['tax_type' => 'PPH', 'name' => 'PPh Kualifikasi B — menengah ke atas', 'vendor_qualification' => 'B', 'rate' => '2.65', 'effective_from' => '2022-01-01', 'created_by' => $staff->id],
        ]);
    }
}
