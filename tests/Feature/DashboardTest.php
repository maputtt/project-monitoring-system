<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// DSH-01 (AC-23, UX layar 2): KPI per role dari Project::visibleTo, read-only, filter divisi hanya DIREKSI/SYSTEM_ADMIN.
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private Project $a;

    private Project $b;

    private Project $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();

        // A: BM, ditugaskan ke staff. B: BM, tidak ditugaskan. C: CP (rahasia bagi divisi BM).
        $this->a = $this->project(['project_code' => 'BM-A', 'status' => 'ACTIVE', 'contract_value' => '1000.10', 'effective_rab' => '700.05', 'target_margin_amount' => '150.15'], true);
        $this->b = $this->project(['project_code' => 'BM-B', 'status' => 'SUBMITTED', 'contract_value' => '2000.20', 'effective_rab' => '1400.10', 'target_margin_amount' => '300.30']);
        $this->c = $this->project(['project_code' => 'CP-C', 'department' => 'CP', 'status' => 'ACTIVE', 'contract_value' => '4000.40', 'effective_rab' => '2800.20', 'target_margin_amount' => '600.60']);

        // A: outstanding 222.22 (INVOICE_SUBMITTED; PLANNED/CANCELLED/PAID tidak dihitung), nett 90.55.
        $this->invoice($this->a, 1, 'PAID', '111.00', '90.55');
        $this->invoice($this->a, 2, 'INVOICE_SUBMITTED', '222.22');
        $this->invoice($this->a, 3, 'PLANNED', null);
        $this->invoice($this->a, 4, 'CANCELLED', '999.99');
        $this->invoice($this->b, 1, 'PAYMENT_SUBMITTED', '333.33', '300.01');
        $this->invoice($this->c, 1, 'PAID', '8000.00', '7777.77');
        $this->invoice($this->c, 2, 'WAITING_FOR_PAYMENT', '1234.56');

        $this->bq($this->a, 'SUBMITTED', true);
        $approved = $this->bq($this->a, 'APPROVED', true);
        $this->bq($this->a, 'ARCHIVED', true); // tidak dihitung
        $this->bq($this->b, 'SUBMITTED', false);
        $this->bq($this->c, 'SUBMITTED', true);
        DB::table('bq_budget_mutations')->insert(['project_id' => $this->a->id, 'destination_bq_id' => $approved, 'amount' => '1.00', 'reason' => 'x', 'mutation_type' => 'FROM_UNALLOCATED_RAB', 'status' => 'SUBMITTED', 'submitted_by' => $this->staff->id]);
        DB::table('project_adjustments')->insert(['project_id' => $this->a->id, 'adjustment_type' => 'TARGET_MARGIN', 'status' => 'SUBMITTED', 'reason' => 'x', 'submitted_by' => $this->staff->id, 'old_data' => '{}', 'new_data' => '{}']);
    }

    private function project(array $attributes, bool $assigned = false): Project
    {
        $factory = Project::factory()->active();

        return ($assigned ? $factory->assignedTo($this->staff) : $factory)->create($attributes + ['created_by' => $this->staff->id]);
    }

    private function invoice(Project $project, int $seq, string $status, ?string $subtotal, ?string $net = null): void
    {
        $billed = ! in_array($status, ['PLANNED', 'CANCELLED'], true);
        DB::table('invoices')->insert([
            'project_id' => $project->id, 'department' => $project->department->value, 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'planned_amount' => '100.00',
            'workflow_status' => $status, 'created_by' => $this->staff->id, 'subtotal_amount' => $subtotal, 'net_received_amount' => $net,
            'invoice_number' => $billed ? "INV/{$project->project_code}/$seq" : null, 'billing_date' => $billed ? '2026-09-01' : null,
            'ppn_rate_snapshot' => $billed ? '11.0000' : null, 'pph_rate_snapshot' => $billed ? '2.6500' : null,
            'locked_at' => $status === 'PAID' ? now() : null, 'locked_by' => $status === 'PAID' ? $this->manager->id : null,
        ]);
    }

    private function bq(Project $project, string $status, bool $overbudget): int
    {
        return DB::table('bq_items')->insertGetId(['project_id' => $project->id, 'item_name' => 'Item', 'item_type' => 'NORMAL', 'status' => $status, 'is_overbudget' => $overbudget, 'created_by' => $this->staff->id]);
    }

    /** Semua kunci KPI dalam urutan controller; tes hanya menyebut yang berbeda dari nol. */
    private function expected(array $override): array
    {
        return array_replace(['activated' => 0, 'projects' => 0, 'completed' => 0, 'contract' => '0.00', 'rab' => '0.00', 'margin' => '0.00', 'margin_pct' => '0.00', 'outstanding' => '0.00', 'nett' => '0.00', 'realized_count' => 0, 'realized' => '0.00', 'realized_pct' => '0.00', 'overbudget' => 0, 'pending' => 0], $override);
    }

    private function kpi(User $user, string $query = ''): array
    {
        return $this->actingAs($user)->get('/dashboard'.$query)->assertOk()->viewData('kpi');
    }

    public function test_staff_sees_kpis_of_assigned_projects_only(): void
    {
        $this->assertSame($this->expected(['activated' => 1, 'projects' => 1, 'contract' => '1000.10', 'rab' => '700.05', 'margin' => '150.15', 'outstanding' => '222.22', 'nett' => '90.55', 'overbudget' => 2, 'pending' => 4]), $this->kpi($this->staff));
    }

    public function test_manager_sees_the_whole_division(): void
    {
        // BM-B (SUBMITTED) tidak masuk angka uang (6A), tetapi tagihan PAYMENT_SUBMITTED-nya tetap dihitung di antrean persetujuan (pending 7).
        $this->assertSame($this->expected(['activated' => 1, 'projects' => 1, 'contract' => '1000.10', 'rab' => '700.05', 'margin' => '150.15', 'outstanding' => '222.22', 'nett' => '90.55', 'overbudget' => 2, 'pending' => 7]), $this->kpi($this->manager));
    }

    public function test_direksi_and_system_admin_see_all_divisions_and_can_filter(): void
    {
        $all = $this->expected(['activated' => 2, 'projects' => 2, 'contract' => '5000.50', 'rab' => '3500.25', 'margin' => '750.75', 'outstanding' => '1456.78', 'nett' => '7868.32', 'overbudget' => 3, 'pending' => 8]);
        $cp = $this->expected(['activated' => 1, 'projects' => 1, 'contract' => '4000.40', 'rab' => '2800.20', 'margin' => '600.60', 'outstanding' => '1234.56', 'nett' => '7777.77', 'overbudget' => 1, 'pending' => 1]);
        foreach ([User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', null)->create()] as $user) {
            $this->assertSame($all, $this->kpi($user));
            $this->assertSame($cp, $this->kpi($user, '?department=CP'));
            $this->assertSame(1, $this->kpi($user, '?department=BM')['projects']);
            $this->actingAs($user)->get('/dashboard?department=XX')->assertSessionHasErrors('department');
        }
    }

    public function test_division_roles_are_locked_to_their_division_and_department_param_is_ignored(): void
    {
        foreach ([$this->staff, $this->manager] as $user) {
            $this->assertSame($this->kpi($user), $this->kpi($user, '?department=CP'));
            $this->actingAs($user)->get('/dashboard?department=CP')->assertDontSee('CP-C')->assertDontSee('Rp4.000,40')->assertDontSee('Rp7.777,77')
                ->assertDontSee('aria-label="Filter divisi"', false);
        }
    }

    public function test_direksi_sees_the_division_filter(): void
    {
        $this->actingAs(User::factory()->role('DIREKSI', null)->create())->get('/dashboard')->assertSee('aria-label="Filter divisi"', false)->assertSee('?department=ITBS', false);
    }

    public function test_dashboard_has_no_project_list_and_page_is_read_only(): void
    {
        $admin = User::factory()->role('SYSTEM_ADMIN', null)->create();
        $response = $this->actingAs($admin)->get('/dashboard')->assertOk();
        foreach ([$this->a, $this->b, $this->c] as $project) {
            $response->assertDontSee($project->project_code)->assertDontSee('href="'.route('projects.show', $project->id).'"', false);
        }
        $response->assertSee('Rp5.000,50')->assertSee('Rp1.456,78')
            ->assertDontSee('/edit')->assertDontSee('/approve')->assertDontSee('/submit')->assertDontSee('Project baru')->assertDontSee('name="_method"', false);
        $this->post('/dashboard')->assertStatus(405);
    }

    public function test_empty_state_and_guest(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/')->assertRedirect('/login');
        $this->assertSame($this->expected([]),
            $this->kpi(User::factory()->role('MANAGER_GM', 'ITBS')->create()));
        $this->get('/dashboard')->assertSee('Belum ada project di jangkauan Anda');
        $this->get('/')->assertRedirect('/dashboard');
    }
}
