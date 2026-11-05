<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// D-C-1 (tampilan): tombol "Tarik pengajuan" hanya muncul bila canWithdraw, badge "Ditarik", dan galat alasan tampil di banner halaman.
class WithdrawUiTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE', 'contract_value' => '199800000.00', 'margin_base_amount' => '199800000.00',
            'target_margin_pct' => '12.0000', 'target_margin_amount' => '23976000.00', 'initial_rab' => '175824000.00', 'effective_rab' => '175824000.00']);
    }

    private function adjustment(string $status): int
    {
        return DB::table('project_adjustments')->insertGetId([
            'project_id' => $this->project->id, 'adjustment_type' => 'TARGET_MARGIN', 'status' => $status, 'reason' => 'Nego ulang', 'submitted_by' => $this->staff->id,
            'old_data' => json_encode(['target_margin_pct' => '12.0000', 'target_margin_amount' => '23976000.00', 'effective_rab' => '175824000.00']),
            'new_data' => json_encode(['target_margin_pct' => '10.0000', 'target_margin_amount' => '19980000.00', 'effective_rab' => '179820000.00', 'margin_mode' => 'PCT', 'margin_value' => '10.0000']),
            'difference_amount' => '3996000.00', 'revision_reason' => $status === 'REVISION_REQUIRED' ? 'Perbaiki' : null,
        ]);
    }

    private function mutation(string $status): int
    {
        $bq = DB::table('bq_items')->insertGetId(['project_id' => $this->project->id, 'item_name' => 'Item', 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'created_by' => $this->staff->id,
            'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '800.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000']);

        return DB::table('bq_budget_mutations')->insertGetId([
            'project_id' => $this->project->id, 'source_bq_id' => null, 'destination_bq_id' => $bq, 'amount' => '100.00', 'reason' => 'Geser budget',
            'mutation_type' => 'FROM_UNALLOCATED_RAB', 'status' => $status, 'submitted_by' => $this->staff->id, 'submitted_at' => now(),
        ]);
    }

    public function test_withdraw_button_only_for_submitter_and_manager_on_open_rows(): void
    {
        $url = "/projects/{$this->project->id}";
        $a = $this->adjustment('SUBMITTED');
        $m = $this->mutation('REVISION_REQUIRED');
        $colleague = User::factory()->role('STAFF_PIC', 'BM')->create();
        DB::table('project_user_assignments')->insert(['project_id' => $this->project->id, 'user_id' => $colleague->id, 'assignment_role' => 'PIC', 'assigned_by' => $this->staff->id]);
        $direksi = User::factory()->role('DIREKSI', null)->create();

        foreach ([$this->staff, $this->manager] as $user) {
            $this->actingAs($user)->get($url)->assertOk()->assertSee('Tarik pengajuan')->assertSee("adjustments/$a/withdraw", false)->assertSee("budget-mutations/$m/withdraw", false)
                ->assertSee('Tarik Penyesuaian target margin?')->assertSee('Tarik Relokasi Budget?')->assertSee('Usulan berhenti permanen dan tidak bisa diajukan ulang.');
        }
        foreach ([$colleague, $direksi] as $user) {
            $this->actingAs($user)->get($url)->assertOk()->assertDontSee('Tarik pengajuan');
        }

        // APPROVED dan WITHDRAWN tidak punya tombol; WITHDRAWN tampil sebagai "Ditarik".
        DB::table('project_adjustments')->update(['status' => 'APPROVED']);
        DB::table('bq_budget_mutations')->update(['status' => 'WITHDRAWN']);
        $this->actingAs($this->manager)->get($url)->assertOk()->assertDontSee('Tarik pengajuan')->assertSee('Ditarik');
        DB::table('project_adjustments')->update(['status' => 'WITHDRAWN']);
        $this->actingAs($this->staff)->get($url)->assertOk()->assertDontSee('Tarik pengajuan')->assertSee('Ditarik');
    }

    public function test_failed_reason_is_shown_in_the_page_error_banner(): void
    {
        $id = $this->adjustment('SUBMITTED');
        $this->actingAs($this->staff)->from("/projects/{$this->project->id}")->post("/adjustments/$id/withdraw", ['reason' => ''])->assertRedirect("/projects/{$this->project->id}");
        $this->actingAs($this->staff)->followingRedirects()->from("/projects/{$this->project->id}")->post("/adjustments/$id/withdraw", ['reason' => ''])->assertSee('Alasan penarikan wajib diisi.');
    }
}
