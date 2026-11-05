<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// DSH-02 (UX layar 11): inbox gabungan item SUBMITTED di divisi Manager/GM. Read-only; keputusan tetap di detail project.
class ApprovalInboxTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private Project $bm;

    private Project $cp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => 'Siti Staff']);
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->bm = Project::factory()->create(['project_code' => 'BM-ACT', 'status' => 'ACTIVE', 'created_by' => $this->staff->id]);
        $this->cp = Project::factory()->create(['project_code' => 'CP-SECRET', 'department' => 'CP', 'status' => 'ACTIVE', 'created_by' => $this->staff->id]);
    }

    private function seedPending(Project $project, string $amountTag): void
    {
        $bq = DB::table('bq_items')->insertGetId(['project_id' => $project->id, 'item_name' => "BQ $amountTag", 'item_type' => 'NORMAL', 'status' => 'SUBMITTED', 'effective_budget' => "1$amountTag.10", 'created_by' => $this->staff->id]);
        $this->audit('BQ_ITEM', $bq);
        foreach ([[1, 'INVOICE_SUBMITTED', "2$amountTag.20"], [2, 'PAYMENT_SUBMITTED', "3$amountTag.30"], [3, 'PAID', "9$amountTag.99"]] as [$seq, $status, $subtotal]) {
            $id = DB::table('invoices')->insertGetId([
                'project_id' => $project->id, 'department' => $project->department->value, 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'planned_amount' => '100.00',
                'workflow_status' => $status, 'created_by' => $this->staff->id, 'subtotal_amount' => $subtotal, 'invoice_number' => "INV/{$project->project_code}/$seq", 'billing_date' => '2026-09-01',
                'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500', 'submitted_by' => $this->staff->id, 'submitted_at' => '2026-10-01 08:00:00',
                'locked_at' => $status === 'PAID' ? now() : null, 'locked_by' => $status === 'PAID' ? $this->manager->id : null,
            ]);
            $status === 'PAYMENT_SUBMITTED' && $this->audit('INVOICE', $id);
        }
        DB::table('project_adjustments')->insert(['project_id' => $project->id, 'adjustment_type' => 'TARGET_MARGIN', 'status' => 'SUBMITTED', 'reason' => 'x', 'difference_amount' => "4$amountTag.40", 'submitted_by' => $this->staff->id, 'old_data' => '{}', 'new_data' => '{}']);
        DB::table('project_adjustments')->insert(['project_id' => $project->id, 'adjustment_type' => 'TARGET_MARGIN', 'status' => 'APPROVED', 'reason' => 'x', 'difference_amount' => "8$amountTag.88", 'submitted_by' => $this->staff->id, 'old_data' => '{}', 'new_data' => '{}']);
        foreach (['SUBMITTED' => "5$amountTag.50", 'DRAFT' => "7$amountTag.77"] as $status => $amount) {
            DB::table('bq_budget_mutations')->insert(['project_id' => $project->id, 'destination_bq_id' => $bq, 'mutation_type' => 'FROM_UNALLOCATED_RAB', 'amount' => $amount, 'reason' => 'x', 'status' => $status, 'submitted_by' => $this->staff->id]);
        }
        DB::table('bq_items')->insert(['project_id' => $project->id, 'item_name' => "BQ approved $amountTag", 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'created_by' => $this->staff->id]);
    }

    private function audit(string $type, int $id, ?User $by = null): void
    {
        DB::table('audit_logs')->insert(['user_id' => ($by ?? $this->staff)->id, 'action' => 'SUBMIT', 'entity_type' => $type, 'entity_id' => $id]);
    }

    private function project(string $code, string $status = 'SUBMITTED'): Project
    {
        $p = Project::factory()->create(['project_code' => $code, 'status' => $status, 'created_by' => $this->staff->id]);
        $status === 'SUBMITTED' && $this->audit('PROJECT', $p->id);

        return $p;
    }

    public function test_manager_sees_all_six_pending_types_of_own_division_only(): void
    {
        $submitted = $this->project('BM-SUB');
        $this->project('BM-DRAFT', 'DRAFT');
        $this->seedPending($this->bm, '1');
        $this->seedPending($this->cp, '6'); // divisi lain: tidak boleh bocor

        $response = $this->actingAs($this->manager)->get('/approvals')->assertOk();
        $response->assertSeeInOrder(['Aktivasi project', 'BQ', 'Invoice', 'Bukti bayar', 'Penyesuaian', 'Relokasi Budget'])
            ->assertSee('BM-SUB')->assertSee('Siti Staff')
            ->assertSee('BQ 1')->assertSee('Rp11,10')->assertSee('Rp21,20')->assertSee('Rp31,30')->assertSee('Rp41,40')->assertSee('Rp51,50')
            ->assertDontSee('BM-DRAFT')->assertDontSee('BQ approved')->assertDontSee('Rp91,99')->assertDontSee('Rp81,88')->assertDontSee('Rp71,77')
            ->assertDontSee('CP-SECRET')->assertDontSee('BQ 6')->assertDontSee('Rp16,10')->assertDontSee('Rp26,20')->assertDontSee('Rp56,50');
        $this->assertEquals(['project' => 1, 'bq' => 1, 'invoice' => 1, 'payment' => 1, 'adjustment' => 1, 'mutation' => 1], $response->viewData('counts')->all());
        $response->assertSee('href="'.route('projects.show', $submitted->id).'"', false)
            ->assertSee(route('projects.show', $this->bm->id).'#item-bq', false)->assertSee(route('projects.show', $this->bm->id).'#jadwal', false)
            ->assertSee(route('projects.show', $this->bm->id).'#penyesuaian', false)->assertSee(route('projects.show', $this->bm->id).'#relokasi-budget', false)
            ->assertDontSee('/approve')->assertDontSee('name="_method"', false);
        $this->post('/approvals')->assertStatus(405);
    }

    public function test_items_submitted_by_the_manager_are_listed_and_marked(): void
    {
        $own = $this->project('BM-OWN');
        $this->audit('PROJECT', $own->id, $this->manager);
        $this->audit('PROJECT', $this->project('BM-OTHER')->id);
        $this->seedPending($this->bm, '1');
        DB::table('project_adjustments')->where('status', 'SUBMITTED')->update(['submitted_by' => $this->manager->id]);

        $response = $this->actingAs($this->manager)->get('/approvals')->assertOk();
        $rows = collect($response->viewData('items')->items());
        $this->assertTrue($rows->firstWhere('project_code', 'BM-OWN')['own']);
        $this->assertFalse($rows->firstWhere('project_code', 'BM-OTHER')['own']);
        $this->assertSame(['adjustment', 'project'], $rows->where('own', true)->pluck('type')->sort()->values()->all());
        $response->assertSee('Diajukan oleh Anda');
    }

    public function test_only_manager_gm_may_open_the_inbox(): void
    {
        $this->get('/approvals')->assertRedirect('/login');
        foreach ([$this->staff, User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', null)->create()] as $user) {
            $this->actingAs($user)->get('/approvals')->assertForbidden();
        }
    }

    public function test_empty_state_and_menu_entry(): void
    {
        $this->actingAs($this->manager)->get('/approvals')->assertOk()->assertSee('Tidak ada item yang menunggu keputusan Anda')->assertSee('href="/approvals"', false);
        $this->seedPending($this->cp, '6');
        $this->actingAs($this->manager)->get('/approvals')->assertSee('Tidak ada item yang menunggu keputusan Anda');
    }
}
