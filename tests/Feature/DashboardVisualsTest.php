<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Ringkasan visual dashboard: alokasi RAB, alur tagihan, batang per project, callout approvals. Fixture deterministik.
class DashboardVisualsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();

        // RAB 1000.00. Committed = 300.10 (NORMAL APPROVED) + 50.00 (NORMAL CLOSED) + 99.90 (OPERATIONAL APPROVED, pakai effective_budget) = 450.00. Pending = 200.00.
        $p = Project::factory()->active()->create(['project_code' => 'BM-V', 'status' => 'ACTIVE', 'contract_value' => '2000.00', 'effective_rab' => '1000.00']);
        $this->bq($p, 'APPROVED', 'NORMAL', '300.10', '999.00');
        $this->bq($p, 'CLOSED', 'NORMAL', '50.00', '999.00');
        $this->bq($p, 'APPROVED', 'OPERATIONAL', '0.00', '99.90');
        $this->bq($p, 'SUBMITTED', 'NORMAL', '200.00', '999.00');
        $this->bq($p, 'DRAFT', 'NORMAL', '777.00', '777.00');
        $this->bq($p, 'ARCHIVED', 'NORMAL', '888.00', '888.00');
        // Project kedua melebihi RAB; project CP tidak boleh ikut untuk manager BM.
        $over = Project::factory()->active()->create(['project_code' => 'BM-O', 'effective_rab' => '100.00']);
        $this->bq($over, 'APPROVED', 'NORMAL', '120.00', '0');
        $cp = Project::factory()->active()->create(['project_code' => 'CP-V', 'department' => 'CP', 'effective_rab' => '5000.00']);
        $this->bq($cp, 'APPROVED', 'NORMAL', '4000.00', '0');

        // Tagihan: PAID subtotal 111.00; outstanding 222.22; PLANNED memakai planned_amount 66.78; CANCELLED tidak dihitung. Total 400.00.
        $this->invoice($p, 1, 'PAID', '111.00', '100.00');
        $this->invoice($p, 2, 'INVOICE_SUBMITTED', '222.22', '100.00');
        $this->invoice($p, 3, 'PLANNED', null, '66.78');
        $this->invoice($p, 4, 'CANCELLED', '999.99', '100.00');
        $this->invoice($cp, 1, 'PAID', '9999.00', '100.00');
    }

    private function bq(Project $project, string $status, string $type, string $negotiated, string $effective): void
    {
        DB::table('bq_items')->insert(['project_id' => $project->id, 'item_name' => 'Item', 'item_type' => $type, 'status' => $status, 'negotiated_value' => $negotiated, 'effective_budget' => $effective, 'created_by' => $this->manager->id]);
    }

    private function invoice(Project $project, int $seq, string $status, ?string $subtotal, string $planned): void
    {
        $billed = ! in_array($status, ['PLANNED', 'CANCELLED'], true);
        DB::table('invoices')->insert([
            'project_id' => $project->id, 'department' => $project->department->value, 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'planned_amount' => $planned,
            'workflow_status' => $status, 'created_by' => $this->manager->id, 'subtotal_amount' => $subtotal, 'net_received_amount' => null,
            'invoice_number' => $billed ? "V/{$project->project_code}/$seq" : null, 'billing_date' => $billed ? '2026-09-01' : null,
            'ppn_rate_snapshot' => $billed ? '11.0000' : null, 'pph_rate_snapshot' => $billed ? '2.6500' : null,
            'locked_at' => $status === 'PAID' ? now() : null, 'locked_by' => $status === 'PAID' ? $this->manager->id : null,
        ]);
    }

    public function test_allocation_and_pipeline_numbers_are_exact_and_scoped(): void
    {
        $response = $this->actingAs($this->manager)->get('/dashboard')->assertOk();
        // Divisi BM: RAB 1100.00, committed 450.00 + 120.00 = 570.00, pending 200.00, sisa 330.00; 570/1100 = 51.82%, 200/1100 = 18.18%.
        $this->assertSame(['rab' => '1100.00', 'committed' => '570.00', 'pending' => '200.00', 'available' => '330.00', 'committed_pct' => '51.82', 'pending_pct' => '18.18', 'over' => false], $response->viewData('allocation'));
        // Total 111.00 + 222.22 + 66.78 = 400.00; persen empat desimal half-up.
        $this->assertSame(['paid' => '111.00', 'outstanding' => '222.22', 'planned' => '66.78', 'paid_pct' => '27.7500', 'outstanding_pct' => '55.5550', 'planned_pct' => '16.6950'], $response->viewData('pipeline'));
        $response->assertSee('Rp330,00')->assertDontSee('Rp4.000,00')->assertDontSee('Rp9.999,00')->assertSee('width: 51.82%', false);
    }

    public function test_approvals_callout_is_for_manager_only(): void
    {
        $link = 'href="'.route('approvals.index').'"';
        $this->actingAs($this->manager)->get('/dashboard')->assertSee('Menunggu persetujuan Anda')->assertSee($link, false);
        foreach (['STAFF_PIC', 'DIREKSI', 'SYSTEM_ADMIN'] as $role) {
            $this->actingAs(User::factory()->role($role, $role === 'STAFF_PIC' ? 'BM' : null)->create())->get('/dashboard')->assertDontSee('Menunggu persetujuan Anda')->assertDontSee($link, false);
        }
    }
}
