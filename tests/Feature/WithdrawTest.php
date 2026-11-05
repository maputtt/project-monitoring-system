<?php

namespace Tests\Feature;

use App\Http\Controllers\ProjectClosingController;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// D-C-1: "Tarik pengajuan" (WITHDRAWN) untuk project_adjustments dan bq_budget_mutations. Pengaju atau Manager/GM divisi; hanya SUBMITTED/REVISION_REQUIRED; terminal; tanpa efek ke angka.
class WithdrawTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create([
            'created_by' => $this->staff->id, 'status' => 'ACTIVE', 'contract_value' => '199800000.00', 'margin_base_amount' => '199800000.00',
            'target_margin_pct' => '12.0000', 'target_margin_amount' => '23976000.00', 'initial_rab' => '175824000.00', 'effective_rab' => '175824000.00',
        ]);
    }

    private function adjustment(string $status = 'SUBMITTED', ?int $by = null): int
    {
        return DB::table('project_adjustments')->insertGetId([
            'project_id' => $this->project->id, 'adjustment_type' => 'TARGET_MARGIN', 'status' => $status, 'reason' => 'Nego ulang', 'submitted_by' => $by ?? $this->staff->id,
            'old_data' => json_encode(['target_margin_pct' => '12.0000', 'target_margin_amount' => '23976000.00', 'effective_rab' => '175824000.00']),
            'new_data' => json_encode(['target_margin_pct' => '10.0000', 'target_margin_amount' => '19980000.00', 'effective_rab' => '179820000.00', 'margin_mode' => 'PCT', 'margin_value' => '10.0000']),
            'difference_amount' => '3996000.00', 'revision_reason' => $status === 'REVISION_REQUIRED' ? 'Perbaiki' : null,
        ]);
    }

    private function bq(): int
    {
        return DB::table('bq_items')->insertGetId(['project_id' => $this->project->id, 'item_name' => 'Item', 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'created_by' => $this->staff->id,
            'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '800.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000']);
    }

    private function mutation(string $status = 'SUBMITTED', ?int $by = null): int
    {
        return DB::table('bq_budget_mutations')->insertGetId([
            'project_id' => $this->project->id, 'source_bq_id' => null, 'destination_bq_id' => $this->bq(), 'amount' => '100.00', 'reason' => 'Geser budget',
            'mutation_type' => 'FROM_UNALLOCATED_RAB', 'status' => $status, 'submitted_by' => $by ?? $this->staff->id, 'submitted_at' => now(),
        ]);
    }

    private function colleague(): User
    {
        $colleague = User::factory()->role('STAFF_PIC', 'BM')->create();
        DB::table('project_user_assignments')->insert(['project_id' => $this->project->id, 'user_id' => $colleague->id, 'assignment_role' => 'PIC', 'assigned_by' => $this->staff->id]);

        return $colleague;
    }

    private function snapshot(): array
    {
        return [DB::table('projects')->get()->all(), DB::table('invoices')->get()->all(), DB::table('bq_items')->get()->all()];
    }

    private function updates(string $entity, int $id)
    {
        return DB::table('audit_logs')->where('entity_type', $entity)->where('entity_id', $id)->where('action', 'UPDATE');
    }

    public function test_adjustment_submitter_and_manager_can_withdraw_with_one_audit_and_no_side_effects(): void
    {
        $a = $this->adjustment();
        $b = $this->adjustment('REVISION_REQUIRED');
        $before = $this->snapshot();

        $this->actingAs($this->staff)->post("/adjustments/$a/withdraw", ['reason' => 'Salah ajukan'])->assertSessionHasNoErrors()->assertRedirect("/projects/{$this->project->id}")
            ->assertSessionHas('status', 'Penyesuaian target margin ditarik. Riwayatnya tetap tersimpan; ajukan usulan baru bila masih diperlukan.');
        $this->actingAs($this->manager)->post("/adjustments/$b/withdraw", ['reason' => 'Tidak jadi'])->assertSessionHasNoErrors();

        $this->assertSame(['WITHDRAWN', 'WITHDRAWN'], [DB::table('project_adjustments')->find($a)->status, DB::table('project_adjustments')->find($b)->status]);
        $this->assertSame('Perbaiki', DB::table('project_adjustments')->find($b)->revision_reason); // alasan revisi tidak ditimpa
        $this->assertEquals($before, $this->snapshot());
        $log = $this->updates('PROJECT_ADJUSTMENT', $a)->get();
        $this->assertCount(1, $log);
        $this->assertSame(['SUBMITTED', 'WITHDRAWN', 'Salah ajukan'], [json_decode($log[0]->data_before, true)['status'], json_decode($log[0]->data_after, true)['status'], $log[0]->reason]);
    }

    public function test_adjustment_withdraw_authorization_and_reason(): void
    {
        $id = $this->adjustment();
        $colleague = $this->colleague();
        $outsider = User::factory()->role('STAFF_PIC', 'BM')->create();
        $otherManager = User::factory()->role('MANAGER_GM', 'CP')->create();
        $direksi = User::factory()->role('DIREKSI', null)->create();

        $this->actingAs($colleague)->post("/adjustments/$id/withdraw", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($direksi)->post("/adjustments/$id/withdraw", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($outsider)->post("/adjustments/$id/withdraw", ['reason' => 'x'])->assertNotFound();
        $this->actingAs($otherManager)->post("/adjustments/$id/withdraw", ['reason' => 'x'])->assertNotFound();
        $this->actingAs($this->manager)->post('/adjustments/999999/withdraw', ['reason' => 'x'])->assertNotFound();
        $this->actingAs($this->staff)->post("/adjustments/$id/withdraw")->assertSessionHasErrors(['reason' => 'Alasan penarikan wajib diisi.']);
        $this->actingAs($this->staff)->post("/adjustments/$id/withdraw", ['reason' => str_repeat('a', 1001)])->assertSessionHasErrors(['reason' => 'Alasan penarikan maksimal 1000 karakter.']);

        $this->assertSame([0, 'SUBMITTED'], [$this->updates('PROJECT_ADJUSTMENT', $id)->count(), DB::table('project_adjustments')->find($id)->status]);
    }

    public function test_adjustment_invalid_status_and_project_status(): void
    {
        $approved = $this->adjustment('APPROVED');
        $withdrawn = $this->adjustment('WITHDRAWN');
        $this->actingAs($this->staff)->post("/adjustments/$approved/withdraw", ['reason' => 'x'])->assertStatus(409);
        $this->actingAs($this->manager)->post("/adjustments/$withdrawn/withdraw", ['reason' => 'x'])->assertStatus(409);

        $open = $this->adjustment();
        foreach (['DRAFT', 'FINANCIALLY_CLOSED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            $this->actingAs($this->staff)->post("/adjustments/$open/withdraw", ['reason' => 'x'])->assertForbidden();
        }
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED']);
        $this->actingAs($this->staff)->post("/adjustments/$open/withdraw", ['reason' => 'x'])->assertSessionHasNoErrors(); // COMPLETED sah untuk semua jenis
        $this->assertSame('WITHDRAWN', DB::table('project_adjustments')->find($open)->status);
    }

    public function test_withdrawn_adjustment_is_terminal_and_leaves_every_pending_list(): void
    {
        $id = $this->adjustment();
        $kept = $this->adjustment();
        $this->actingAs($this->staff)->post("/adjustments/$id/withdraw", ['reason' => 'x'])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertStatus(409);
        $this->actingAs($this->manager)->post("/adjustments/$id/request-revision", ['reason' => 'x'])->assertStatus(409);
        $this->actingAs($this->staff)->put("/adjustments/$id", ['margin_mode' => 'PCT', 'margin_value' => '9', 'reason' => 'ulang', 'file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf')])->assertStatus(409);
        $this->assertSame('WITHDRAWN', DB::table('project_adjustments')->find($id)->status);

        $this->assertSame(1, $this->actingAs($this->manager)->get('/approvals')->viewData('counts')['adjustment']);
        $this->assertSame(1, $this->actingAs($this->manager)->get('/dashboard')->viewData('kpi')['pending']);

        DB::table('project_adjustments')->where('id', $kept)->update(['status' => 'WITHDRAWN']);
        $this->assertSame(0, $this->actingAs($this->manager)->get('/dashboard')->viewData('kpi')['pending']);
        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/complete")->assertSessionHasNoErrors();
        $this->assertSame('COMPLETED', DB::table('projects')->find($this->project->id)->status);
        $this->assertSame([], ProjectClosingController::readiness($this->project->fresh())['checks'][4]['blockers']);
    }

    public function test_view_flag_can_withdraw_follows_owner_approver_and_status(): void
    {
        $own = $this->adjustment();
        $done = $this->adjustment('APPROVED');
        $m = $this->mutation();
        $colleague = $this->colleague();
        $flags = fn (User $u) => [
            $this->actingAs($u)->get("/projects/{$this->project->id}")->viewData('adjustments')->pluck('canWithdraw', 'id')->all(),
            $this->actingAs($u)->get("/projects/{$this->project->id}")->viewData('mutations')->pluck('canWithdraw', 'id')->all(),
        ];

        $this->assertSame([[$done => false, $own => true], [$m => true]], $flags($this->staff));
        $this->assertSame([[$done => false, $own => true], [$m => true]], $flags($this->manager));
        $this->assertSame([[$done => false, $own => false], [$m => false]], $flags($colleague));
    }

    public function test_mutation_withdraw_flow_audit_and_no_balance_change(): void
    {
        $a = $this->mutation();
        $b = $this->mutation('REVISION_REQUIRED');
        $before = $this->snapshot();

        $this->actingAs($this->staff)->post("/budget-mutations/$a/withdraw", ['reason' => 'Salah ajukan'])->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Relokasi Budget ditarik. Riwayatnya tetap tersimpan; ajukan usulan baru bila masih diperlukan.');
        $this->actingAs($this->manager)->post("/budget-mutations/$b/withdraw", ['reason' => 'Tidak jadi'])->assertSessionHasNoErrors();

        $this->assertEquals($before, $this->snapshot());
        $this->assertSame(['WITHDRAWN', 'WITHDRAWN'], [DB::table('bq_budget_mutations')->find($a)->status, DB::table('bq_budget_mutations')->find($b)->status]);
        $log = $this->updates('BQ_MUTATION', $a)->get();
        $this->assertCount(1, $log);
        $this->assertSame(['SUBMITTED', 'WITHDRAWN', 'Salah ajukan'], [json_decode($log[0]->data_before, true)['status'], json_decode($log[0]->data_after, true)['status'], $log[0]->reason]);

        $this->actingAs($this->manager)->post("/budget-mutations/$a/approve")->assertStatus(409);
        $this->actingAs($this->manager)->post("/budget-mutations/$a/request-revision", ['reason' => 'x'])->assertStatus(409);
        $this->actingAs($this->staff)->put("/budget-mutations/$a", ['mutation_type' => 'FROM_UNALLOCATED_RAB', 'destination_bq_id' => 1, 'amount' => '1.00', 'reason' => 'x', 'file' => UploadedFile::fake()->create('n.pdf', 10, 'application/pdf')])->assertStatus(409);
        $this->actingAs($this->staff)->post("/budget-mutations/$a/withdraw", ['reason' => 'lagi'])->assertStatus(409);
        $this->assertSame(0, $this->actingAs($this->manager)->get('/approvals')->viewData('counts')->get('mutation', 0));
    }

    public function test_mutation_withdraw_authorization_status_and_closing_readiness(): void
    {
        $id = $this->mutation();
        $approved = $this->mutation('APPROVED');
        $direksi = User::factory()->role('DIREKSI', null)->create();

        $this->actingAs($this->colleague())->post("/budget-mutations/$id/withdraw", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($direksi)->post("/budget-mutations/$id/withdraw", ['reason' => 'x'])->assertForbidden();
        $this->actingAs(User::factory()->role('STAFF_PIC', 'BM')->create())->post("/budget-mutations/$id/withdraw", ['reason' => 'x'])->assertNotFound();
        $this->actingAs(User::factory()->role('MANAGER_GM', 'CP')->create())->post("/budget-mutations/$id/withdraw", ['reason' => 'x'])->assertNotFound();
        $this->actingAs($this->staff)->post("/budget-mutations/$id/withdraw")->assertSessionHasErrors(['reason' => 'Alasan penarikan wajib diisi.']);
        $this->actingAs($this->staff)->post("/budget-mutations/$approved/withdraw", ['reason' => 'x'])->assertStatus(409);

        $this->assertNotSame([], ProjectClosingController::readiness($this->project->fresh())['checks'][4]['blockers']);
        $this->actingAs($this->staff)->post("/budget-mutations/$id/withdraw", ['reason' => 'x'])->assertSessionHasNoErrors();
        $this->assertSame([], ProjectClosingController::readiness($this->project->fresh())['checks'][4]['blockers']);

        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'DRAFT']);
        $again = $this->mutation();
        $this->actingAs($this->staff)->post("/budget-mutations/$again/withdraw", ['reason' => 'x'])->assertForbidden();
    }
}
