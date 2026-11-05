<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Contoh PRD 6.3: kontrak Rp671.151.608,00 termasuk PPN dengan target margin 15%.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by' => User::factory(), // harus paling atas: pks_id di bawah memakai id ini
            // PKS dan PIC Bouwheer belum punya model (MST-02), jadi dibuat langsung di tabelnya.
            'pks_id' => fn (array $a) => DB::table('pks')->insertGetId(['pks_number' => 'PKS/'.fake()->unique()->numerify('#####'), 'name' => 'PKS Contoh', 'client_name' => 'PT Klien', 'issued_date' => '2026-01-01', 'created_by' => $a['created_by']]),
            'bouwheer_contact_id' => fn () => DB::table('bouwheer_contacts')->insertGetId(['name' => fake()->name(), 'company_name' => 'PT Klien']),
            'department' => 'BM',
            'project_code' => fake()->unique()->bothify('PRJ-####'),
            'project_name' => 'Project '.fake()->city(),
            'location' => fake()->city(),
            'spk_number' => fake()->unique()->bothify('SPK/###/2026'),
            'spk_date' => '2026-02-10',
            'contract_value' => '671151608.00',
            'initial_contract_value' => fn (array $a) => $a['contract_value'], // ikut override contract_value
            'contract_tax_mode' => 'INCLUSIVE_PPN',
            'margin_base_amount' => '671151608.00',
            'target_margin_pct' => '15.0000',
            'target_margin_amount' => '100672741.20',
            'initial_rab' => '570478866.80',
            'effective_rab' => '570478866.80',
            'payment_type' => 'TERMIN',
            'payment_count' => 3,
            'start_date' => '2026-03-01',
            'end_date' => '2026-11-30',
            'status' => 'DRAFT',
        ];
    }

    /** Project yang sudah diaktifkan (masuk angka dashboard). */
    public function active(): static
    {
        return $this->state(['status' => 'ACTIVE']);
    }

    /** Menugaskan user ke project sebagai PIC. */
    public function assignedTo(User $user, bool $active = true): static
    {
        return $this->afterCreating(fn (Project $project) => DB::table('project_user_assignments')->insert([
            'project_id' => $project->id, 'user_id' => $user->id, 'assignment_role' => 'PIC', 'assigned_by' => $user->id, 'is_active' => $active,
        ]));
    }
}
