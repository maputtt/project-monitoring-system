<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// MST-03 (AC-28): MANAGER_GM menugaskan dan menonaktifkan Staff/PIC pada project divisinya.
class ProjectAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const LAST = 'Project harus memiliki minimal satu Staff/PIC aktif. Tugaskan penggantinya lebih dulu.';

    private User $manager;

    private User $staff;

    private User $other;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->other = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id]);
    }

    private function assign(?User $actor, User $user, string $role = 'PIC', ?Project $project = null)
    {
        return $this->actingAs($actor ?? $this->manager)->postJson('/projects/'.($project ?? $this->project)->id.'/assignments', ['user_id' => $user->id, 'assignment_role' => $role]);
    }

    private function row(User $user, string $role = 'PIC', ?Project $project = null): ?object
    {
        return DB::table('project_user_assignments')->where('project_id', ($project ?? $this->project)->id)->where('user_id', $user->id)->where('assignment_role', $role)->first();
    }

    private function audits()
    {
        return DB::table('audit_logs')->where('entity_type', 'PROJECT_ASSIGNMENT')->orderBy('id')->get();
    }

    public function test_manager_assigns_staff_and_the_staff_can_then_see_the_project(): void
    {
        $this->actingAs($this->other)->get("/projects/{$this->project->id}")->assertNotFound();

        $this->assign(null, $this->other)->assertRedirect();
        $row = $this->row($this->other);
        $this->assertTrue((bool) $row->is_active);
        $this->assertSame($this->manager->id, (int) $row->assigned_by);
        $audit = $this->audits()->sole();
        $this->assertSame('CREATE', $audit->action);
        $this->assertSame($this->manager->id, $audit->user_id);
        $this->assertSame((int) $row->id, (int) $audit->entity_id);
        $this->assertNull($audit->data_before);
        $this->assertSame(1, json_decode($audit->data_after)->is_active);

        $this->actingAs($this->other)->get("/projects/{$this->project->id}")->assertOk();
    }

    public function test_only_manager_of_the_division_can_assign(): void
    {
        $this->assign(User::factory()->role('DIREKSI', null)->create(), $this->other)->assertForbidden();
        $this->assign(User::factory()->role('SYSTEM_ADMIN', null)->create(), $this->other)->assertForbidden();
        // Staff yang ditugaskan di project itu melihatnya tetapi tetap tidak boleh mengelola tim.
        $this->assign($this->staff, $this->other)->assertForbidden();
        $this->assign($this->other, $this->other)->assertNotFound(); // staff lain tidak melihat project
        $this->assign(User::factory()->role('MANAGER_GM', 'CP')->create(), $this->other)->assertNotFound();
        $this->assertNull($this->row($this->other));
        $this->assertCount(0, $this->audits());
    }

    public function test_invalid_users_and_roles_are_rejected(): void
    {
        $this->assign(null, User::factory()->role('STAFF_PIC', 'CP')->create())->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assign(null, User::factory()->role('STAFF_PIC', 'BM')->create(['is_active' => false]))->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assign(null, User::factory()->role('MANAGER_GM', 'BM')->create())->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertCount(0, $this->audits());
    }

    public function test_client_role_input_is_ignored_and_the_row_is_always_pic(): void
    {
        foreach (['MANAGER', 'VIEWER', 'OWNER', ''] as $role) {
            $user = User::factory()->role('STAFF_PIC', 'BM')->create();
            $this->assign(null, $user, $role)->assertRedirect();
            $this->assertSame('PIC', DB::table('project_user_assignments')->where('user_id', $user->id)->value('assignment_role'));
        }
    }

    public function test_deactivated_or_moved_staff_does_not_count_as_the_remaining_pic(): void
    {
        $this->assign(null, $this->other)->assertRedirect();
        $staffId = $this->row($this->staff)->id;

        // other masih aktif tetapi tidak berguna (nonaktif, pindah divisi, atau bukan lagi Staff/PIC): staff tetap yang terakhir.
        foreach ([['is_active' => false], ['department' => 'CP'], ['role' => 'MANAGER_GM']] as $change) {
            DB::table('users')->where('id', $this->other->id)->update($change);
            $this->actingAs($this->manager)->postJson("/assignments/$staffId/deactivate")->assertUnprocessable()->assertJsonValidationErrors(['assignment' => self::LAST]);
            $this->assertTrue((bool) $this->row($this->staff)->is_active);
            $html = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->getContent();
            $this->assertStringContainsString('Staff/PIC aktif terakhir', $html);
            DB::table('users')->where('id', $this->other->id)->update(['is_active' => true, 'department' => 'BM', 'role' => 'STAFF_PIC']);
        }

        $this->actingAs($this->manager)->post("/assignments/$staffId/deactivate")->assertRedirect();
    }

    public function test_assigning_someone_already_active_is_rejected_with_a_message(): void
    {
        $this->assign(null, $this->staff)->assertUnprocessable()->assertJsonValidationErrors(['user_id' => 'Staff/PIC ini sudah ditugaskan aktif pada project ini.']);
        $this->assign(null, $this->staff, 'VIEWER')->assertUnprocessable();
        $this->assertSame(1, DB::table('project_user_assignments')->where('project_id', $this->project->id)->count());
        $this->assertCount(0, $this->audits());

        // Bentuk form: redirect dengan pesan error per field.
        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/assignments", ['user_id' => $this->staff->id, 'assignment_role' => 'PIC'])->assertSessionHasErrors('user_id');
    }

    public function test_deactivate_then_assign_again_reactivates_the_same_row_with_update_audit(): void
    {
        $this->assign(null, $this->other)->assertRedirect();
        $id = $this->row($this->other)->id;

        $this->actingAs($this->manager)->post("/assignments/$id/deactivate")->assertRedirect("/projects/{$this->project->id}");
        $this->assertFalse((bool) $this->row($this->other)->is_active); // tidak dihapus
        $this->actingAs($this->other)->get("/projects/{$this->project->id}")->assertNotFound();

        $this->assign(null, $this->other)->assertRedirect();
        $this->assertSame(1, DB::table('project_user_assignments')->where('user_id', $this->other->id)->count());
        $this->assertTrue((bool) $this->row($this->other)->is_active);
        $this->assertSame(['CREATE', 'UPDATE', 'UPDATE'], $this->audits()->pluck('action')->all());
        $last = $this->audits()->last();
        $this->assertSame(0, json_decode($last->data_before)->is_active);
        $this->assertSame(1, json_decode($last->data_after)->is_active);
        $this->actingAs($this->other)->get("/projects/{$this->project->id}")->assertOk();
    }

    public function test_last_active_assignment_cannot_be_deactivated_but_can_after_a_replacement(): void
    {
        $staffId = $this->row($this->staff)->id;

        $this->actingAs($this->manager)->postJson("/assignments/$staffId/deactivate")
            ->assertUnprocessable()->assertJsonValidationErrors(['assignment' => self::LAST]);
        $this->assertTrue((bool) $this->row($this->staff)->is_active);
        $this->assertCount(0, $this->audits());

        $this->assign(null, $this->other)->assertRedirect();
        $this->actingAs($this->manager)->post("/assignments/$staffId/deactivate")->assertRedirect();
        $this->assertFalse((bool) $this->row($this->staff)->is_active);
        $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertNotFound();

        // Pengganti kini yang terakhir.
        $this->actingAs($this->manager)->postJson('/assignments/'.$this->row($this->other)->id.'/deactivate')->assertUnprocessable();
        // Menonaktifkan yang sudah nonaktif ditolak, bukan menulis audit ganda.
        $count = $this->audits()->count();
        $this->actingAs($this->manager)->postJson("/assignments/$staffId/deactivate")->assertUnprocessable();
        $this->assertCount($count, $this->audits());
    }

    public function test_deactivate_authorization_and_reach(): void
    {
        $this->assign(null, $this->other)->assertRedirect();
        $id = $this->row($this->staff)->id;

        $this->actingAs($this->staff)->post("/assignments/$id/deactivate")->assertForbidden();
        $this->actingAs(User::factory()->role('DIREKSI', null)->create())->post("/assignments/$id/deactivate")->assertForbidden();
        $this->actingAs(User::factory()->role('SYSTEM_ADMIN', null)->create())->post("/assignments/$id/deactivate")->assertForbidden();
        $this->actingAs(User::factory()->role('MANAGER_GM', 'CP')->create())->post("/assignments/$id/deactivate")->assertNotFound();
        $this->actingAs($this->manager)->post('/assignments/999999/deactivate')->assertNotFound();
        $this->assertTrue((bool) $this->row($this->staff)->is_active);
    }

    public function test_staff_may_be_assigned_to_several_projects(): void
    {
        $second = Project::factory()->assignedTo($this->other)->create(['created_by' => $this->other->id]);
        $this->assign(null, $this->staff, 'PIC', $second)->assertRedirect();
        $this->assertTrue((bool) $this->row($this->staff, 'PIC', $second)->is_active);
        $this->assertTrue((bool) $this->row($this->staff)->is_active);
    }

    public function test_closed_project_team_is_frozen(): void
    {
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'FINANCIALLY_CLOSED']);
        $this->assign(null, $this->other)->assertForbidden();
        $this->actingAs($this->manager)->post('/assignments/'.$this->row($this->staff)->id.'/deactivate')->assertForbidden();
        $this->assertNull($this->row($this->other));
    }

    public function test_show_page_offers_team_controls_only_to_the_manager(): void
    {
        $inactive = User::factory()->role('STAFF_PIC', 'BM')->create(['is_active' => false]);
        $cp = User::factory()->role('STAFF_PIC', 'CP')->create();

        $page = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->assertSee('Tugaskan')->assertSee($this->other->name.' ('.$this->other->email.')');
        $eligible = $page->viewData('eligible')->pluck('id')->all();
        $this->assertSame([$this->other->id], $eligible); // bukan yang sudah aktif, nonaktif, atau divisi lain
        $this->assertTrue($page->viewData('can')['manageTeam']);

        $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->assertDontSee('Tugaskan')->assertDontSee('Nonaktifkan');
        $this->actingAs(User::factory()->role('SYSTEM_ADMIN', null)->create())->get("/projects/{$this->project->id}")->assertOk()->assertDontSee('Tugaskan');
    }
}
