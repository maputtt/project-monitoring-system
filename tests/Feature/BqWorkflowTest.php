<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// BQ-02 (PRD 5.3, 5.8, AC-05): submit, approve, dan minta revisi item BQ, termasuk penjaga over-alokasi RAB.
class BqWorkflowTest extends TestCase
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
        // effective_rab 570.478.866,80; cadangan operasional 70.478.866,80 sudah APPROVED (aktivasi), sisa alokasi tepat 500.000.000,00.
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
        $this->bq('OPERATIONAL', 'APPROVED', '70478866.80', '0.00');
    }

    private function bq(string $type = 'NORMAL', string $status = 'DRAFT', string $budget = '1000.00', string $nego = '800.00', ?int $by = null): int
    {
        return DB::table('bq_items')->insertGetId([
            'project_id' => $this->project->id, 'item_name' => "Item $type", 'item_type' => $type, 'status' => $status, 'created_by' => $by ?? $this->staff->id,
            'baseline_budget' => $budget, 'effective_budget' => $budget, 'negotiated_value' => $nego, 'margin_amount' => '0.00', 'margin_pct' => '0.0000',
        ]);
    }

    private function row(int $id): object
    {
        return DB::table('bq_items')->where('id', $id)->first();
    }

    private function audits(string $action): int
    {
        return DB::table('audit_logs')->where('entity_type', 'BQ_ITEM')->where('action', $action)->count();
    }

    private function setProject(string $status): void
    {
        DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
    }

    private function summary(User $user): array
    {
        return $this->actingAs($user)->get("/projects/{$this->project->id}")->assertOk()->viewData('summary');
    }

    public function test_submit_revise_edit_resubmit_approve_cycle_moves_status_version_and_audit(): void
    {
        $id = $this->bq();

        $this->actingAs($this->staff)->post("/bq-items/$id/submit")->assertRedirect("/projects/{$this->project->id}");
        $this->assertSame(['SUBMITTED', 2], [$this->row($id)->status, $this->row($id)->version]);

        $this->actingAs($this->manager)->post("/bq-items/$id/request-revision")->assertSessionHasErrors('reason');
        $this->assertSame('SUBMITTED', $this->row($id)->status);
        $this->actingAs($this->manager)->post("/bq-items/$id/request-revision", ['reason' => 'Harga <b>vendor</b> belum jelas'])->assertRedirect();
        $this->assertSame(['REVISION_REQUIRED', 3], [$this->row($id)->status, $this->row($id)->version]);
        $this->assertSame('Harga <b>vendor</b> belum jelas', DB::table('audit_logs')->where('action', 'REQUEST_REVISION')->value('reason'));
        $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertSee('Alasan: Harga &lt;b&gt;vendor&lt;/b&gt; belum jelas', false)->assertDontSee('<b>vendor</b>', false); // terlihat oleh Staff/PIC dan di-escape

        // Bisa diubah lewat PUT BQ-01 lalu diajukan ulang.
        $this->actingAs($this->staff)->put("/bq-items/$id", ['item_name' => 'Revisi', 'baseline_budget' => '900.00', 'negotiated_value' => '700.00', 'version' => 3])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post("/bq-items/$id/submit")->assertRedirect();

        $this->actingAs($this->manager)->post("/bq-items/$id/approve")->assertRedirect();
        $approved = $this->row($id);
        $this->assertSame(['APPROVED', $this->manager->id], [$approved->status, $approved->approved_by]);
        $this->assertNotNull($approved->approved_at);
        $this->assertSame([2, 1, 1], [$this->audits('SUBMIT'), $this->audits('APPROVE'), $this->audits('REQUEST_REVISION')]);
        $after = json_decode(DB::table('audit_logs')->where('action', 'APPROVE')->value('data_after'), true);
        $this->assertSame(['APPROVED', $this->manager->id], [$after['status'], $after['approved_by']]);
    }

    public function test_project_must_be_active_or_completed(): void
    {
        $id = $this->bq();
        $this->setProject('DRAFT');
        $this->actingAs($this->staff)->post("/bq-items/$id/submit")->assertForbidden();
        $this->assertSame(['DRAFT', 0], [$this->row($id)->status, $this->audits('SUBMIT')]);

        $this->setProject('COMPLETED');
        $this->actingAs($this->staff)->post("/bq-items/$id/submit")->assertRedirect();
        $this->setProject('DRAFT');
        $this->actingAs($this->manager)->post("/bq-items/$id/approve")->assertForbidden();
        $this->actingAs($this->manager)->post("/bq-items/$id/request-revision", ['reason' => 'x'])->assertForbidden();
        $this->setProject('ACTIVE');
        $this->actingAs($this->manager)->post("/bq-items/$id/approve")->assertRedirect();
        $this->assertSame('APPROVED', $this->row($id)->status);
    }

    public function test_creator_cannot_decide_own_item_even_as_manager(): void
    {
        $own = $this->bq('NORMAL', 'SUBMITTED', '1000.00', '800.00', $this->manager->id);
        $this->actingAs($this->manager)->post("/bq-items/$own/approve")->assertForbidden();
        $this->actingAs($this->manager)->post("/bq-items/$own/request-revision", ['reason' => 'x'])->assertForbidden();
        $this->assertSame(['SUBMITTED', null, 0], [$this->row($own)->status, $this->row($own)->approved_by, $this->audits('APPROVE')]);

        $other = $this->bq('NORMAL', 'SUBMITTED'); // dibuat staff: manager boleh
        $this->actingAs($this->manager)->post("/bq-items/$other/approve")->assertRedirect();
    }

    public function test_roles_scope_and_invalid_transitions(): void
    {
        $draft = $this->bq();
        $submitted = $this->bq('NORMAL', 'SUBMITTED');
        $this->actingAs($this->manager)->post("/bq-items/$draft/submit")->assertForbidden();
        $this->actingAs($this->staff)->post("/bq-items/$submitted/approve")->assertForbidden();
        $this->actingAs(User::factory()->role('DIREKSI', 'BM')->create())->post("/bq-items/$submitted/approve")->assertForbidden();
        $this->actingAs(User::factory()->role('MANAGER_GM', 'CP')->create())->post("/bq-items/$submitted/approve")->assertNotFound();
        $this->actingAs($this->manager)->post('/bq-items/999999/approve')->assertNotFound();

        $operational = DB::table('bq_items')->where('item_type', 'OPERATIONAL')->value('id');
        DB::table('bq_items')->where('id', $operational)->update(['status' => 'DRAFT']);
        $this->actingAs($this->staff)->post("/bq-items/$operational/submit")->assertStatus(409); // BQ Operasional hanya lewat aktivasi
        $this->actingAs($this->manager)->post("/bq-items/$draft/approve")->assertStatus(409); // DRAFT ke APPROVED tidak sah
        $this->actingAs($this->staff)->post("/bq-items/$submitted/submit")->assertStatus(409); // sudah SUBMITTED
        $this->actingAs($this->manager)->post("/bq-items/$submitted/approve")->assertRedirect();
        $this->actingAs($this->manager)->post("/bq-items/$submitted/approve")->assertStatus(409); // diulang: tidak ada efek ganda
        $this->assertSame([1, 0, 2], [$this->audits('APPROVE'), $this->audits('SUBMIT'), (int) $this->row($submitted)->version]);
    }

    public function test_rab_guard_allows_exact_equality_and_rejects_one_cent_over_without_side_effects(): void
    {
        $over = $this->bq('NORMAL', 'SUBMITTED', '500000000.01', '1.00');
        $before = DB::table('audit_logs')->count();

        $this->actingAs($this->manager)->post("/bq-items/$over/approve")->assertSessionHasErrors('bq')->assertRedirect();
        $row = $this->row($over);
        $this->assertSame(['SUBMITTED', null, null, 1], [$row->status, $row->approved_by, $row->approved_at, (int) $row->version]);
        $this->assertSame($before, DB::table('audit_logs')->count());

        // Satu sen di bawah batas lolos: 70.478.866,80 + 500.000.000,00 sama persis dengan effective_rab.
        DB::table('bq_items')->where('id', $over)->update(['baseline_budget' => '500000000.00', 'effective_budget' => '500000000.00']);
        $this->actingAs($this->manager)->post("/bq-items/$over/approve")->assertRedirect();
        $this->assertSame('APPROVED', $this->row($over)->status);

        // Sudah penuh: item berikutnya, berapa pun kecilnya (0,01), ditolak.
        $next = $this->bq('NORMAL', 'SUBMITTED', '0.01', '0.01');
        $this->actingAs($this->manager)->post("/bq-items/$next/approve")->assertSessionHasErrors('bq');
        $this->assertSame('SUBMITTED', $this->row($next)->status);
    }

    public function test_submit_makes_cost_pending_and_approve_makes_it_committed(): void
    {
        $id = $this->bq('NORMAL', 'DRAFT', '1000.00', '800.00');
        $start = $this->summary($this->manager); // 570.478.866,80 dikurangi cadangan operasional
        $this->assertSame(['500000000.00', '0.00'], [$start['official_sisa'], $start['pending']]);

        $this->actingAs($this->staff)->post("/bq-items/$id/submit");
        $submitted = $this->summary($this->manager);
        $this->assertSame([$start['official_sisa'], '800.00', '499999200.00'], [$submitted['official_sisa'], $submitted['pending'], $submitted['projected_sisa']]);
        $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertSee('Setujui')->assertSee('Minta revisi');
        $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertDontSee('Setujui');

        $this->actingAs($this->manager)->post("/bq-items/$id/approve");
        $approved = $this->summary($this->manager);
        $this->assertSame(['499999200.00', '0.00', '800.00'], [$approved['official_sisa'], $approved['pending'], $approved['committed']]);
    }

    public function test_rab_guard_basis_is_effective_budget_of_all_non_archived_items(): void
    {
        // Uji coba PM 2026-11-02: DRAFT/SUBMITTED/REVISION_REQUIRED ikut dihitung (sama dengan kartu Sisa RAB); ARCHIVED tidak; nilai nego bukan dasar guard.
        $this->bq('NORMAL', 'APPROVED', '300000000.00', '1.00');
        $over = $this->bq('NORMAL', 'SUBMITTED', '200000000.01', '1.00'); // 70.478.866,80 + 300.000.000,00 + 200.000.000,01 = effective_rab + 0,01
        $this->actingAs($this->manager)->post("/bq-items/$over/approve")->assertSessionHasErrors(['bq' => 'Sisa RAB tidak cukup: total Nilai RAB semua item BQ (termasuk yang masih diajukan/draf) Rp570.478.866,81 melebihi RAB Perubahan Rp570.478.866,80. Item yang belum disetujui: Item NORMAL (Diajukan, Rp200.000.000,01). Kecilkan atau arsipkan item yang belum disetujui (Manager dapat "Minta revisi" bila sudah diajukan), atau naikkan RAB Perubahan lewat penyesuaian target margin.']);
        $this->assertSame('SUBMITTED', $this->row($over)->status);

        DB::table('bq_items')->where('id', $over)->update(['effective_budget' => '200000000.00', 'negotiated_value' => '999999999999.00']);
        $draft = $this->bq('NORMAL', 'DRAFT', '0.01'); // item draf 0,01 membuat total melebihi RAB
        $this->actingAs($this->manager)->post("/bq-items/$over/approve")->assertSessionHasErrors('bq');
        $this->assertSame('SUBMITTED', $this->row($over)->status);

        DB::table('bq_items')->where('id', $draft)->update(['status' => 'ARCHIVED']);
        $this->actingAs($this->manager)->post("/bq-items/$over/approve")->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', $this->row($over)->status);
    }

    public function test_every_item_status_gets_409_not_500_on_invalid_actions(): void
    {
        foreach (['DRAFT', 'SUBMITTED', 'REVISION_REQUIRED', 'APPROVED', 'CLOSED', 'ARCHIVED'] as $status) {
            $id = $this->bq('NORMAL', $status);
            $legal = ['submit' => in_array($status, ['DRAFT', 'REVISION_REQUIRED']), 'approve' => $status === 'SUBMITTED', 'request-revision' => $status === 'SUBMITTED'];
            foreach ($legal as $action => $allowed) {
                if ($allowed) {
                    continue; // jalur sah dicek di test lain
                }
                $actor = $action === 'submit' ? $this->staff : $this->manager;
                $this->actingAs($actor)->post("/bq-items/$id/$action", ['reason' => 'x'])->assertStatus(409);
            }
            $this->assertSame([$status, 1], [$this->row($id)->status, (int) $this->row($id)->version], $status);
        }
        $this->assertSame(0, DB::table('audit_logs')->where('entity_type', 'BQ_ITEM')->count());
    }

    public function test_workflow_is_closed_for_every_project_status_except_active_and_completed(): void
    {
        foreach (['SUBMITTED', 'FINANCIALLY_CLOSED', 'ARCHIVED', 'CANCELLED'] as $status) {
            $this->setProject($status);
            $submitted = $this->bq('NORMAL', 'SUBMITTED');
            $draft = $this->bq();
            $this->actingAs($this->manager)->post("/bq-items/$submitted/approve")->assertForbidden();
            $this->actingAs($this->manager)->post("/bq-items/$submitted/request-revision", ['reason' => 'x'])->assertForbidden();
            $this->actingAs($this->staff)->post("/bq-items/$draft/submit")->assertForbidden();
            $this->assertSame(['SUBMITTED', 'DRAFT'], [$this->row($submitted)->status, $this->row($draft)->status], $status);
            $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk(); // halaman tetap tampil tanpa 500
        }
        $this->assertSame(0, DB::table('audit_logs')->where('entity_type', 'BQ_ITEM')->count());
    }

    public function test_role_precedence_unassigned_staff_admin_and_guest(): void
    {
        $submitted = $this->bq('NORMAL', 'SUBMITTED');
        $draft = $this->bq();
        $this->post("/bq-items/$submitted/approve")->assertRedirect('/login');
        $this->actingAs(User::factory()->role('STAFF_PIC', 'BM')->create())->post("/bq-items/$draft/submit")->assertNotFound(); // satu divisi, tidak ditugaskan
        $this->actingAs(User::factory()->role('SYSTEM_ADMIN', null)->create())->post("/bq-items/$submitted/approve")->assertForbidden();
        $this->actingAs(User::factory()->role('DIREKSI', null)->create())->post("/bq-items/$draft/submit")->assertForbidden();
        // 404/403 mendahului validasi alasan.
        $this->actingAs(User::factory()->role('MANAGER_GM', 'CP')->create())->post("/bq-items/$submitted/request-revision")->assertNotFound();
        $this->actingAs($this->staff)->post("/bq-items/$submitted/request-revision")->assertForbidden();
        $this->actingAs($this->manager)->get("/bq-items/$submitted/approve")->assertStatus(405);
        $this->assertSame(['SUBMITTED', 'DRAFT', 0], [$this->row($submitted)->status, $this->row($draft)->status, DB::table('audit_logs')->where('entity_type', 'BQ_ITEM')->count()]);
    }

    public function test_revision_reason_limits_and_newest_reason_wins(): void
    {
        $id = $this->bq('NORMAL', 'SUBMITTED');
        foreach (['   ', str_repeat('a', 1001), ['x']] as $bad) {
            $this->actingAs($this->manager)->post("/bq-items/$id/request-revision", ['reason' => $bad])->assertSessionHasErrors('reason');
        }
        $this->assertSame(['SUBMITTED', 0], [$this->row($id)->status, DB::table('audit_logs')->where('entity_type', 'BQ_ITEM')->count()]);

        $this->actingAs($this->manager)->post("/bq-items/$id/request-revision", ['reason' => 'ALASAN-LAMA'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post("/bq-items/$id/submit");
        $this->actingAs($this->manager)->post("/bq-items/$id/request-revision", ['reason' => str_repeat('é', 1000)])->assertSessionHasNoErrors(); // 1000 karakter multibyte lolos
        $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertSee(str_repeat('é', 1000))->assertDontSee('ALASAN-LAMA');
        $this->actingAs($this->staff)->post("/bq-items/$id/submit");
        $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertDontSee('Alasan:'); // alasan hanya tampil saat REVISION_REQUIRED
    }

    public function test_buttons_follow_role_and_status_and_creator_sees_hint_instead_of_approve(): void
    {
        $own = $this->bq('NORMAL', 'SUBMITTED', '10.00', '5.00', $this->manager->id);
        $draft = $this->bq();
        $submitted = $this->bq('NORMAL', 'SUBMITTED');
        $approved = $this->bq('NORMAL', 'APPROVED');
        $operational = DB::table('bq_items')->where('item_type', 'OPERATIONAL')->value('id');

        $manager = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Dibuat oleh Anda, jadi tidak bisa Anda setujui', $manager);
        $this->assertStringContainsString("bq-items/$submitted/approve", $manager);
        $this->assertStringContainsString("bq-items/$submitted/request-revision", $manager);
        foreach (["bq-items/$own/approve", "bq-items/$own/request-revision", "bq-items/$draft/", "bq-items/$approved/", "bq-items/$operational/"] as $absent) {
            $this->assertStringNotContainsString($absent, $manager, $absent);
        }

        $staff = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->getContent();
        $this->assertStringContainsString("bq-items/$draft/submit", $staff);
        foreach (["bq-items/$submitted/", "bq-items/$own/", "bq-items/$approved/submit", "bq-items/$operational/submit"] as $absent) {
            $this->assertStringNotContainsString($absent, $staff, $absent);
        }
        foreach (['DIREKSI', 'SYSTEM_ADMIN'] as $role) {
            $this->assertStringNotContainsString('/bq-items/', $this->actingAs(User::factory()->role($role, null)->create())->get("/projects/{$this->project->id}")->assertOk()->getContent(), $role);
        }
    }

    public function test_oversized_route_ids_return_404_not_500(): void
    {
        $this->actingAs($this->manager)->post('/bq-items/99999999999999999999/approve')->assertNotFound();
        $this->actingAs($this->manager)->post('/assignments/99999999999999999999/deactivate')->assertNotFound();
    }
}
