<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// PRJ-04 (PRD 5.2/5.8, AC-02): adjustment target margin. Submit tidak mengubah project; approve mengubah target_margin_* dan effective_rab
// atomik di bawah kunci, menghitung ulang dari margin base, menolak bila RAB baru < total budget BQ non-ARCHIVED, dan tidak pernah menyentuh initial_rab.
// CONCURRENCY: tes paralel sungguhan butuh dua proses (qa-engineer); urutan kunci project lalu adjustment sama dengan BQ-04.
class ProjectAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        // Margin base 199.800.000,00; target 12% = 23.976.000,00; initial = effective RAB = 175.824.000,00.
        $this->project = Project::factory()->assignedTo($this->staff)->create([
            'created_by' => $this->staff->id, 'status' => 'ACTIVE', 'contract_value' => '199800000.00', 'margin_base_amount' => '199800000.00',
            'target_margin_pct' => '12.0000', 'target_margin_amount' => '23976000.00', 'initial_rab' => '175824000.00', 'effective_rab' => '175824000.00',
        ]);
    }

    private function adjustment(string $status = 'SUBMITTED', string $mode = 'PCT', string $value = '10.0000', ?int $by = null, ?Project $project = null): int
    {
        $base = '199800000.00';
        $margin = $mode === 'PCT' ? bcmul(bcdiv($value, '100', 6), $base, 2) : $value;
        $pct = $mode === 'PCT' ? $value : bcmul(bcdiv($margin, $base, 6), '100', 4);

        return DB::table('project_adjustments')->insertGetId([
            'project_id' => ($project ?? $this->project)->id, 'adjustment_type' => 'TARGET_MARGIN', 'status' => $status, 'reason' => 'Nego ulang', 'submitted_by' => $by ?? $this->staff->id,
            'old_data' => json_encode(['target_margin_pct' => '12.0000', 'target_margin_amount' => '23976000.00', 'effective_rab' => '175824000.00']),
            'new_data' => json_encode(['target_margin_pct' => $pct, 'target_margin_amount' => $margin, 'effective_rab' => bcsub($base, $margin, 2), 'margin_mode' => $mode, 'margin_value' => $value]),
            'difference_amount' => '0.00',
        ]);
    }

    private function bq(string $budget, string $status = 'APPROVED'): void
    {
        DB::table('bq_items')->insert(['project_id' => $this->project->id, 'item_name' => 'Item', 'item_type' => 'NORMAL', 'status' => $status, 'created_by' => $this->staff->id,
            'baseline_budget' => $budget, 'effective_budget' => $budget, 'negotiated_value' => '0.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000']);
    }

    private function row(int $id): object
    {
        return DB::table('project_adjustments')->where('id', $id)->first();
    }

    private function state(): array
    {
        $p = DB::table('projects')->where('id', $this->project->id)->first();

        return [$p->target_margin_pct, $p->target_margin_amount, $p->effective_rab, $p->initial_rab, $p->version];
    }

    private function audits(string $action): int
    {
        return DB::table('audit_logs')->where('entity_type', 'PROJECT_ADJUSTMENT')->where('action', $action)->count();
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '10', 'reason' => 'Nego ulang dengan klien'];
    }

    private function submit(array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->staff)->post("/projects/{$this->project->id}/adjustments", $this->payload($extra));
    }

    public function test_submit_creates_submitted_adjustment_with_old_and_new_data_and_does_not_change_project(): void
    {
        $before = $this->state();

        $this->submit()->assertSessionHasNoErrors()->assertRedirect("/projects/{$this->project->id}");

        $a = DB::table('project_adjustments')->first();
        $this->assertSame(['TARGET_MARGIN', 'SUBMITTED', $this->staff->id, '3996000.00'], [$a->adjustment_type, $a->status, $a->submitted_by, $a->difference_amount]); // 179.820.000 - 175.824.000
        $this->assertEquals(['target_margin_pct' => '12.0000', 'target_margin_amount' => '23976000.00', 'effective_rab' => '175824000.00'], json_decode($a->old_data, true)); // JSON MySQL mengurutkan ulang kunci
        $this->assertEquals(['target_margin_pct' => '10.0000', 'target_margin_amount' => '19980000.00', 'effective_rab' => '179820000.00', 'margin_mode' => 'PCT', 'margin_value' => '10.0000'], json_decode($a->new_data, true));
        $this->assertSame($before, $this->state());
        $this->assertSame([1, 0], [$this->audits('SUBMIT'), $this->audits('APPROVE')]);
    }

    public function test_submit_by_amount_derives_pct_and_negative_difference_when_margin_grows(): void
    {
        $this->submit(['margin_mode' => 'AMOUNT', 'margin_value' => '27972000.00'])->assertSessionHasNoErrors();

        $a = DB::table('project_adjustments')->first();
        $this->assertSame('-3996000.00', $a->difference_amount);
        $this->assertEquals(['target_margin_pct' => '14.0000', 'target_margin_amount' => '27972000.00', 'effective_rab' => '171828000.00', 'margin_mode' => 'AMOUNT', 'margin_value' => '27972000.00'], json_decode($a->new_data, true));
    }

    public function test_money_rounds_half_up_and_pct_has_four_decimals(): void
    {
        // 0,05% dari 10,00 = 0,005 -> 0,01 (half-up), RAB 9,99.
        DB::table('projects')->where('id', $this->project->id)->update(['margin_base_amount' => '10.00']);
        $this->submit(['margin_value' => '0.05'])->assertSessionHasNoErrors();
        $new = json_decode(DB::table('project_adjustments')->first()->new_data, true);
        $this->assertSame(['0.0500', '0.01', '9.99'], [$new['target_margin_pct'], $new['target_margin_amount'], $new['effective_rab']]);

        // Nominal 1,00 dari 3,00 -> 33,3333% (empat desimal).
        DB::table('projects')->where('id', $this->project->id)->update(['margin_base_amount' => '3.00']);
        $this->submit(['margin_mode' => 'AMOUNT', 'margin_value' => '1.00'])->assertSessionHasNoErrors();
        $this->assertSame('33.3333', json_decode(DB::table('project_adjustments')->orderByDesc('id')->first()->new_data, true)['target_margin_pct']);
    }

    public function test_submit_validation_rejects_bad_input_without_row_or_audit(): void
    {
        $cases = [
            'mode kosong' => [['margin_mode' => ''], 'margin_mode'],
            'mode tidak dikenal' => [['margin_mode' => 'X'], 'margin_mode'],
            'nilai kosong' => [['margin_value' => ''], 'margin_value'],
            'nilai negatif' => [['margin_value' => '-1'], 'margin_value'],
            'persen di atas 100' => [['margin_value' => '100.0001'], 'margin_value'],
            'persen lima desimal' => [['margin_value' => '10.12345'], 'margin_value'],
            'nominal tiga desimal' => [['margin_mode' => 'AMOUNT', 'margin_value' => '1.234'], 'margin_value'],
            'nominal melebihi margin base' => [['margin_mode' => 'AMOUNT', 'margin_value' => '199800000.01'], 'margin_value'],
            'alasan kosong' => [['reason' => ''], 'reason'],
            'alasan terlalu panjang' => [['reason' => str_repeat('a', 1001)], 'reason'],
        ];

        foreach ($cases as [$extra, $field]) {
            $this->submit($extra)->assertSessionHasErrors($field, null, 'adjustment-new');
        }
        $this->assertSame([0, 0], [DB::table('project_adjustments')->count(), $this->audits('SUBMIT')]);

        $this->submit(['margin_mode' => 'AMOUNT', 'margin_value' => '199800000.00'])->assertSessionHasNoErrors(); // batas atas: margin sama dengan margin base, RAB 0,00
        $this->assertSame('0.00', json_decode(DB::table('project_adjustments')->first()->new_data, true)['effective_rab']);
    }

    public function test_approve_changes_margin_and_effective_rab_atomically_and_leaves_initial_rab(): void
    {
        $this->bq('100000000.00');
        $id = $this->adjustment();

        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertSessionHasNoErrors()->assertRedirect("/projects/{$this->project->id}");

        $this->assertSame(['10.0000', '19980000.00', '179820000.00', '175824000.00', 2], $this->state()); // initial_rab tetap; version naik
        $a = $this->row($id);
        $this->assertSame(['APPROVED', $this->manager->id, '3996000.00'], [$a->status, $a->approved_by, $a->difference_amount]);
        $this->assertEquals(['target_margin_pct' => '12.0000', 'target_margin_amount' => '23976000.00', 'effective_rab' => '175824000.00'], json_decode($a->old_data, true)); // JSON MySQL mengurutkan ulang kunci
        $log = DB::table('audit_logs')->where('action', 'APPROVE')->where('entity_type', 'PROJECT_ADJUSTMENT')->first();
        $this->assertSame([$id, '179820000.00', '175824000.00'], [$log->entity_id, json_decode($log->data_after, true)['effective_rab'], json_decode($log->data_before, true)['effective_rab']]);

        // Kartu Effective RAB sekarang berbeda dari Initial RAB.
        $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->assertSee('Rp179.820.000,00')->assertSee('Rp175.824.000,00');
    }

    public function test_approve_recomputes_from_margin_base_and_ignores_tampered_new_data(): void
    {
        $id = $this->adjustment();
        DB::table('project_adjustments')->where('id', $id)->update(['new_data' => json_encode(['target_margin_pct' => '1.0000', 'target_margin_amount' => '1.00', 'effective_rab' => '999999999.00', 'margin_mode' => 'PCT', 'margin_value' => '10.0000'])]);

        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertSessionHasNoErrors();

        $this->assertSame(['10.0000', '19980000.00', '179820000.00', '175824000.00', 2], $this->state());
        $this->assertSame('179820000.00', json_decode($this->row($id)->new_data, true)['effective_rab']);
    }

    public function test_guard_rejects_new_rab_below_total_bq_budget_at_the_cent_and_rolls_back(): void
    {
        $this->bq('171828000.00'); // 12% -> 14%: RAB baru 171.828.000,00, tepat sama dengan total BQ
        $this->bq('999999999.00', 'ARCHIVED'); // ARCHIVED tidak dihitung
        $id = $this->adjustment(value: '14.0000');
        $before = $this->state();

        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertSessionHasNoErrors();
        $this->assertSame(['14.0000', '27972000.00', '171828000.00', '175824000.00', 2], $this->state()); // sama persis diperbolehkan

        // Satu sen di bawah: tolak 422, tidak ada perubahan apa pun.
        DB::table('projects')->where('id', $this->project->id)->update(['target_margin_pct' => '12.0000', 'target_margin_amount' => '23976000.00', 'effective_rab' => '175824000.00', 'version' => 1]);
        DB::table('bq_items')->where('status', 'APPROVED')->update(['effective_budget' => '171828000.01']);
        $id2 = $this->adjustment(value: '14.0000');
        $approvals = $this->audits('APPROVE');

        $this->actingAs($this->manager)->post("/adjustments/$id2/approve")->assertSessionHasErrors('adjustment');

        $this->assertSame($before, $this->state());
        $this->assertSame(['SUBMITTED', null], [$this->row($id2)->status, $this->row($id2)->approved_by]);
        $this->assertSame($approvals, $this->audits('APPROVE'));
    }

    public function test_self_approval_and_self_revision_are_forbidden(): void
    {
        $id = $this->adjustment(by: $this->manager->id);
        $before = $this->state();

        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertForbidden();
        $this->actingAs($this->manager)->post("/adjustments/$id/request-revision", ['reason' => 'x'])->assertForbidden();

        $this->assertSame([$before, 'SUBMITTED', 0], [$this->state(), $this->row($id)->status, $this->audits('APPROVE')]);
    }

    public function test_authorization_scope_and_ids(): void
    {
        $id = $this->adjustment();
        $outsider = User::factory()->role('STAFF_PIC', 'BM')->create();
        $otherManager = User::factory()->role('MANAGER_GM', 'CP')->create();
        $direksi = User::factory()->role('DIREKSI', null)->create();

        $this->actingAs($this->staff)->post("/adjustments/$id/approve")->assertForbidden();
        $this->actingAs($this->staff)->post("/adjustments/$id/request-revision", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($direksi)->post("/adjustments/$id/approve")->assertForbidden();
        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/adjustments", $this->payload())->assertForbidden();
        $this->actingAs($direksi)->post("/projects/{$this->project->id}/adjustments", $this->payload())->assertForbidden();
        $this->actingAs($otherManager)->post("/adjustments/$id/approve")->assertNotFound();
        $this->actingAs($outsider)->post("/projects/{$this->project->id}/adjustments", $this->payload())->assertNotFound();
        $this->actingAs($this->manager)->post('/adjustments/999999/approve')->assertNotFound();
        $this->actingAs($this->manager)->post('/adjustments/1234567890123456789/approve')->assertNotFound(); // 19 digit
        $this->actingAs($this->manager)->post('/adjustments/abc/approve')->assertNotFound();

        $this->assertSame([1, 'SUBMITTED', 0], [DB::table('project_adjustments')->count(), $this->row($id)->status, $this->audits('APPROVE')]);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $id = $this->adjustment();

        $this->post("/adjustments/$id/approve")->assertRedirect('/login');
        $this->post("/adjustments/$id/request-revision")->assertRedirect('/login');
        $this->put("/adjustments/$id")->assertRedirect('/login');
        $this->post("/projects/{$this->project->id}/adjustments")->assertRedirect('/login');
    }

    public function test_only_active_projects_accept_adjustments(): void
    {
        foreach (['DRAFT', 'SUBMITTED', 'COMPLETED', 'FINANCIALLY_CLOSED', 'ARCHIVED', 'CANCELLED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            $id = $this->adjustment();
            $this->submit()->assertForbidden();
            $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertForbidden();
            $this->actingAs($this->manager)->post("/adjustments/$id/request-revision", ['reason' => 'x'])->assertForbidden();
        }
        $this->assertSame(6, DB::table('project_adjustments')->count());
        $this->assertSame(0, DB::table('project_adjustments')->where('status', '<>', 'SUBMITTED')->count());
    }

    public function test_invalid_transitions_return_409(): void
    {
        $other = User::factory()->role('MANAGER_GM', 'BM')->create();
        $approved = $this->adjustment('APPROVED');
        $revision = $this->adjustment('REVISION_REQUIRED');
        $before = $this->state();

        foreach ([$approved, $revision] as $id) {
            $this->actingAs($other)->post("/adjustments/$id/approve")->assertStatus(409);
            $this->actingAs($other)->post("/adjustments/$id/request-revision", ['reason' => 'x'])->assertStatus(409);
        }
        $this->assertSame([$before, 0], [$this->state(), $this->audits('APPROVE')]);
    }

    public function test_request_revision_needs_reason_then_submitter_can_resubmit(): void
    {
        $id = $this->adjustment();
        $before = $this->state();

        $this->actingAs($this->manager)->post("/adjustments/$id/request-revision", [])->assertSessionHasErrors('reason');
        $this->assertSame('SUBMITTED', $this->row($id)->status);

        $this->actingAs($this->manager)->post("/adjustments/$id/request-revision", ['reason' => 'Terlalu kecil'])->assertSessionHasNoErrors();
        $a = $this->row($id);
        $this->assertSame(['REVISION_REQUIRED', $this->manager->id, 'Terlalu kecil'], [$a->status, $a->revision_requested_by, $a->revision_reason]);
        $this->assertSame([$before, 1], [$this->state(), $this->audits('REQUEST_REVISION')]);

        // Bukan pengaju tidak boleh memperbaiki; pengaju boleh, dan angka dihitung ulang.
        $colleague = User::factory()->role('STAFF_PIC', 'BM')->create();
        DB::table('project_user_assignments')->insert(['project_id' => $this->project->id, 'user_id' => $colleague->id, 'assignment_role' => 'PIC', 'assigned_by' => $this->staff->id]);
        $this->actingAs($colleague)->put("/adjustments/$id", $this->payload())->assertForbidden();
        $this->actingAs($this->manager)->put("/adjustments/$id", $this->payload())->assertForbidden();
        $this->actingAs($this->staff)->put("/adjustments/$id", $this->payload(['reason' => '']))->assertSessionHasErrors('reason', null, "adjustment-$id");

        $this->actingAs($this->staff)->put("/adjustments/$id", $this->payload(['margin_value' => '9']))->assertSessionHasNoErrors();
        $a = $this->row($id);
        $this->assertSame('SUBMITTED', $a->status);
        $this->assertEquals(['target_margin_pct' => '9.0000', 'target_margin_amount' => '17982000.00', 'effective_rab' => '181818000.00', 'margin_mode' => 'PCT', 'margin_value' => '9.0000'], json_decode($a->new_data, true));
        $this->assertSame([$before, 1], [$this->state(), $this->audits('SUBMIT')]); // pengajuan awal dibuat langsung di DB, jadi hanya ajukan ulang yang tercatat

        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertSessionHasNoErrors();
        $this->assertSame('181818000.00', $this->state()[2]);
    }

    public function test_approved_or_submitted_adjustment_cannot_be_edited(): void
    {
        foreach (['APPROVED', 'SUBMITTED'] as $status) {
            $id = $this->adjustment($status);
            $this->actingAs($this->staff)->put("/adjustments/$id", $this->payload(['margin_value' => '5']))->assertStatus(409);
            $this->assertSame($status, $this->row($id)->status);
            $this->assertSame('10.0000', json_decode($this->row($id)->new_data, true)['target_margin_pct']);
        }
    }

    public function test_page_shows_adjustment_actions_by_role_and_ownership(): void
    {
        $id = $this->adjustment();
        $own = $this->adjustment(by: $this->manager->id);
        $url = "/projects/{$this->project->id}";

        $this->actingAs($this->staff)->get($url)->assertOk()->assertSee('Ajukan penyesuaian')->assertDontSee("adjustments/$id/approve", false);
        $this->actingAs($this->manager)->get($url)->assertOk()->assertSee("adjustments/$id/approve", false)->assertDontSee("adjustments/$own/approve", false)->assertSee('tidak bisa Anda setujui');
    }

    public function test_stale_submitted_adjustment_hides_approve_but_keeps_revision_and_409(): void
    {
        $id = $this->adjustment();
        DB::table('projects')->where('id', $this->project->id)->update(['target_margin_amount' => '20000000.00', 'effective_rab' => '179800000.00']); // angka bergeser sejak usulan
        $url = "/projects/{$this->project->id}";

        $this->actingAs($this->manager)->get($url)->assertOk()->assertDontSee("adjustments/$id/approve", false)->assertSee("adjustments/$id/request-revision", false)
            ->assertSee('Angka project sudah berubah sejak usulan diajukan');
        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertStatus(409);
    }
}
