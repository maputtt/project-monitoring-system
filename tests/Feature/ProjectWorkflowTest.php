<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

// PRJ-02 (PRD 5.1, AC-01, AC-10): submit, aktivasi, dan minta revisi project.
class ProjectWorkflowTest extends TestCase
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
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id]);
        DB::table('bq_items')->insert(['project_id' => $this->project->id, 'item_name' => 'Biaya Lain-lain / Operasional', 'item_type' => 'OPERATIONAL', 'status' => 'DRAFT', 'created_by' => $this->staff->id]);
    }

    private function projectStatus(?Project $project = null): string
    {
        return DB::table('projects')->where('id', ($project ?? $this->project)->id)->value('status');
    }

    private function audit(string $action)
    {
        return DB::table('audit_logs')->where('entity_type', 'PROJECT')->where('entity_id', $this->project->id)->where('action', $action)->orderBy('id')->get();
    }

    private function submitted(): void
    {
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/submit")->assertRedirect("/projects/{$this->project->id}");
    }

    public function test_submit_activate_locks_the_baseline_and_approves_operational_bq(): void
    {
        $before = DB::table('projects')->where('id', $this->project->id)->first();

        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/submit")
            ->assertRedirect("/projects/{$this->project->id}")->assertSessionHas('status', 'Project diajukan untuk aktivasi.');
        $this->assertSame('SUBMITTED', $this->projectStatus());
        $this->assertSame($this->staff->id, $this->audit('SUBMIT')->sole()->user_id);
        $this->assertSame('DRAFT', DB::table('bq_items')->where('project_id', $this->project->id)->value('status'));

        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/activate")->assertRedirect("/projects/{$this->project->id}");
        $after = DB::table('projects')->where('id', $this->project->id)->first();
        $this->assertSame('ACTIVE', $after->status);
        $this->assertSame($this->manager->id, (int) $after->activated_by);
        $this->assertNotNull($after->activated_at);
        $this->assertSame(3, (int) $after->version); // setiap transisi menaikkan version
        foreach (['initial_rab', 'effective_rab', 'margin_base_amount', 'target_margin_amount', 'target_margin_pct', 'contract_value'] as $field) {
            $this->assertSame($before->$field, $after->$field, $field);
        }

        $bq = DB::table('bq_items')->where('project_id', $this->project->id)->first();
        $this->assertSame('APPROVED', $bq->status);
        $this->assertSame($this->manager->id, (int) $bq->approved_by);
        $approve = $this->audit('APPROVE')->sole();
        $this->assertSame($this->manager->id, $approve->user_id);
        $this->assertSame('ACTIVE', json_decode($approve->data_after)->status);

        // Setelah ACTIVE, ubah draft ditolak dan tidak ada yang berubah.
        $this->actingAs($this->staff)->get("/projects/{$this->project->id}/edit")->assertForbidden();
        $this->put("/projects/{$this->project->id}", ['project_name' => 'Diubah', 'version' => 3])->assertForbidden();
        $this->assertSame($before->project_name, DB::table('projects')->where('id', $this->project->id)->value('project_name'));
    }

    public function test_request_revision_needs_a_reason_and_returns_the_project_to_draft(): void
    {
        $this->submitted();

        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/request-revision")->assertSessionHasErrors('reason');
        $this->post("/projects/{$this->project->id}/request-revision", ['reason' => '  '])->assertSessionHasErrors('reason');
        $this->assertSame('SUBMITTED', $this->projectStatus());
        $this->assertCount(0, $this->audit('REQUEST_REVISION'));

        $this->post("/projects/{$this->project->id}/request-revision", ['reason' => 'Termin salah'])->assertRedirect("/projects/{$this->project->id}");
        $this->assertSame('DRAFT', $this->projectStatus());
        $log = $this->audit('REQUEST_REVISION')->sole();
        $this->assertSame('Termin salah', $log->reason);
        $this->assertSame($this->manager->id, $log->user_id);
        $this->assertNull(DB::table('projects')->where('id', $this->project->id)->value('activated_at'));

        // Staff dapat mengedit lagi dan mengajukan ulang; pengaju terakhir yang berlaku.
        $this->actingAs($this->staff)->get("/projects/{$this->project->id}/edit")->assertOk();
        $this->submitted();
        $this->assertCount(2, $this->audit('SUBMIT'));
    }

    public function test_wrong_role_is_forbidden_and_out_of_reach_project_is_not_found(): void
    {
        $roles = fn (array $users, string $path, int $code) => collect($users)->each(function (User $user) use ($path, $code) {
            $this->actingAs($user)->post("/projects/{$this->project->id}/$path", ['reason' => 'x'])->assertStatus($code);
        });
        $direksi = User::factory()->role('DIREKSI', null)->create();
        $admin = User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create();

        $roles([$this->manager, $direksi, $admin], 'submit', 403);
        $roles([$this->staff, $direksi, $admin], 'activate', 403);
        $roles([$this->staff, $direksi, $admin], 'request-revision', 403);
        $this->assertSame('DRAFT', $this->projectStatus());

        // Staff divisi sama tanpa assignment, manajer divisi lain: tidak terlihat.
        $roles([User::factory()->role('STAFF_PIC', 'BM')->create()], 'submit', 404);
        $roles([User::factory()->role('MANAGER_GM', 'CP')->create()], 'activate', 404);
        $this->assertCount(0, DB::table('audit_logs')->where('entity_type', 'PROJECT')->where('action', '<>', 'CREATE')->get());
    }

    public function test_submitter_cannot_activate_even_with_the_manager_role(): void
    {
        $this->submitted();
        DB::table('users')->where('id', $this->staff->id)->update(['role' => 'MANAGER_GM']);

        $this->actingAs($this->staff->fresh())->post("/projects/{$this->project->id}/activate")->assertForbidden();
        $this->assertSame('SUBMITTED', $this->projectStatus());
        $this->assertSame('DRAFT', DB::table('bq_items')->where('project_id', $this->project->id)->value('status'));
        $this->assertCount(0, $this->audit('APPROVE'));
    }

    public function test_invalid_transitions_are_refused_and_repeats_are_idempotent(): void
    {
        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/activate")->assertStatus(409); // DRAFT tidak bisa diaktifkan
        $this->post("/projects/{$this->project->id}/request-revision", ['reason' => 'x'])->assertStatus(409);

        $this->submitted();
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/submit")->assertStatus(409); // kirim ulang tidak menggandakan audit
        $this->assertCount(1, $this->audit('SUBMIT'));

        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/activate")->assertRedirect();
        $this->post("/projects/{$this->project->id}/activate")->assertStatus(409);
        $this->post("/projects/{$this->project->id}/request-revision", ['reason' => 'x'])->assertStatus(409);
        $this->assertCount(1, $this->audit('APPROVE'));
        $this->assertSame('ACTIVE', $this->projectStatus());
    }

    public function test_repeated_activation_renders_the_friendly_409_page_but_json_stays_json(): void
    {
        $this->submitted();
        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/activate")->assertRedirect();

        $this->post("/projects/{$this->project->id}/activate")
            ->assertStatus(409)->assertSee('Kode 409')->assertSee('sudah diproses sebelumnya')->assertSee('Ke beranda');
        $this->postJson("/projects/{$this->project->id}/activate")->assertStatus(409)->assertJsonStructure(['message']);
    }

    // --- QA independen PRJ-02 ---

    public function test_inactive_user_and_inactive_assignment_cannot_run_lifecycle_commands(): void
    {
        $this->submitted();
        DB::table('users')->where('id', $this->manager->id)->update(['is_active' => false]);
        $this->actingAs($this->manager->fresh())->post("/projects/{$this->project->id}/activate")->assertRedirect('/login');
        $this->assertSame('SUBMITTED', $this->projectStatus());
        $this->assertCount(0, $this->audit('APPROVE'));

        DB::table('users')->where('id', $this->manager->id)->update(['is_active' => true]);
        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/request-revision", ['reason' => 'x'])->assertRedirect();
        DB::table('project_user_assignments')->where('project_id', $this->project->id)->update(['is_active' => false]);
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/submit")->assertNotFound();
        $this->assertSame('DRAFT', $this->projectStatus());
        $this->assertCount(1, $this->audit('SUBMIT'));
    }

    public function test_every_past_submitter_is_blocked_from_activating_even_after_resubmission_by_another(): void
    {
        $second = User::factory()->role('STAFF_PIC', 'BM')->create();
        DB::table('project_user_assignments')->insert(['project_id' => $this->project->id, 'user_id' => $second->id, 'assignment_role' => 'PIC', 'assigned_by' => $this->staff->id]);
        $this->submitted();
        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/request-revision", ['reason' => 'x']);
        $this->actingAs($second)->post("/projects/{$this->project->id}/submit")->assertRedirect();
        $this->assertTrue($this->project->hasBeenSubmittedBy($this->staff));
        $this->assertTrue($this->project->hasBeenSubmittedBy($second));
        $this->assertFalse($this->project->hasBeenSubmittedBy($this->manager));

        // Pengaju awal (A) dan pengaju terbaru (B) sama-sama ditolak setelah dipromosikan; manajer yang tidak pernah submit boleh.
        DB::table('users')->whereIn('id', [$this->staff->id, $second->id])->update(['role' => 'MANAGER_GM']);
        foreach ([$this->staff, $second] as $submitter) {
            $this->actingAs($submitter->fresh())->post("/projects/{$this->project->id}/activate")->assertForbidden();
        }
        $this->assertSame('SUBMITTED', $this->projectStatus());
        $this->assertCount(0, $this->audit('APPROVE'));

        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/activate")->assertRedirect();
        $this->assertSame('ACTIVE', $this->projectStatus());
    }

    public function test_activation_does_not_approve_normal_bq_and_keeps_the_audit_trail_complete(): void
    {
        $normal = DB::table('bq_items')->insertGetId(['project_id' => $this->project->id, 'item_name' => 'Besi', 'item_type' => 'NORMAL', 'status' => 'DRAFT', 'created_by' => $this->staff->id]);
        $this->submitted();
        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/activate")->assertRedirect();

        $this->assertSame('DRAFT', DB::table('bq_items')->where('id', $normal)->value('status'));
        $this->assertNull(DB::table('bq_items')->where('id', $normal)->value('approved_by'));
        $submit = $this->audit('SUBMIT')->sole();
        $this->assertSame('DRAFT', json_decode($submit->data_before)->status);
        $this->assertSame('SUBMITTED', json_decode($submit->data_after)->status);
        $approve = $this->audit('APPROVE')->sole();
        $this->assertSame('SUBMITTED', json_decode($approve->data_before)->status);
        $this->assertNull(json_decode($approve->data_before)->activated_by);
        $this->assertSame($this->manager->id, json_decode($approve->data_after)->activated_by);
    }

    public function test_revision_reason_limits_and_shapes(): void
    {
        $this->submitted();
        $this->actingAs($this->manager);
        $url = "/projects/{$this->project->id}/request-revision";

        $this->post($url, ['reason' => str_repeat('a', 1001)])->assertSessionHasErrors('reason');
        $this->post($url, ['reason' => ['x']])->assertSessionHasErrors('reason');
        $this->assertSame('SUBMITTED', $this->projectStatus());

        $this->post($url, ['reason' => str_repeat('a', 1000)])->assertRedirect();
        $this->assertSame(1000, strlen($this->audit('REQUEST_REVISION')->sole()->reason));
    }

    public function test_activation_rolls_back_when_the_audit_write_fails(): void
    {
        $this->submitted();
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into `audit_logs`')) {
                throw new RuntimeException('audit gagal');
            }
        });

        try {
            $this->actingAs($this->manager)->withoutExceptionHandling()->post("/projects/{$this->project->id}/activate");
            $this->fail('Aktivasi seharusnya gagal.');
        } catch (RuntimeException) {
        }

        $this->assertSame('SUBMITTED', $this->projectStatus());
        $this->assertNull(DB::table('projects')->where('id', $this->project->id)->value('activated_at'));
        $this->assertSame('DRAFT', DB::table('bq_items')->where('project_id', $this->project->id)->value('status'));
    }
}
