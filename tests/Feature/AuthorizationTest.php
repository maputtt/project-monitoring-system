<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// PRD AC-19 dan AC-23: akses mengikuti role, divisi, dan assignment.
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $otherStaff;

    private User $managerBm;

    private User $managerCp;

    private User $direksi;

    private User $admin;

    private Project $assigned;

    private Project $unassigned;

    private Project $cp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->otherStaff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->managerBm = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->managerCp = User::factory()->role('MANAGER_GM', 'CP')->create();
        $this->direksi = User::factory()->role('DIREKSI', null)->create();
        $this->admin = User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create();

        $this->assigned = Project::factory()->assignedTo($this->staff)->create();
        $this->unassigned = Project::factory()->create();
        $this->cp = Project::factory()->create(['department' => 'CP']);

        // Route khusus test: route project yang sebenarnya dibuat pada PRJ-03.
        Route::middleware(['web', 'auth', 'active'])->group(function () {
            Route::get('/_test/projects/{project}', fn (Project $project) => $project->project_code);
            Route::get('/_test/manager-only', fn () => 'ok')->middleware('role:MANAGER_GM');
            Route::get('/_test/staff-or-manager', fn () => 'ok')->middleware('role:STAFF_PIC,MANAGER_GM');
        });
    }

    private function visibleIds(User $user): array
    {
        return Project::query()->visibleTo($user)->orderBy('id')->pluck('id')->all();
    }

    public function test_visible_projects_per_role_division_and_assignment(): void
    {
        $this->assertSame([$this->assigned->id], $this->visibleIds($this->staff));
        $this->assertSame([], $this->visibleIds($this->otherStaff)); // divisi sama tetapi tidak ditugaskan
        $this->assertSame([$this->assigned->id, $this->unassigned->id], $this->visibleIds($this->managerBm));
        $this->assertSame([$this->cp->id], $this->visibleIds($this->managerCp));
        $this->assertSame([$this->assigned->id, $this->unassigned->id, $this->cp->id], $this->visibleIds($this->direksi));
        $this->assertSame([$this->assigned->id, $this->unassigned->id, $this->cp->id], $this->visibleIds($this->admin)); // read-only, seperti Direksi
    }

    public function test_inactive_assignment_and_assignment_in_another_division_give_no_access(): void
    {
        $inactive = Project::factory()->assignedTo($this->otherStaff, active: false)->create();
        $crossDivision = Project::factory()->assignedTo($this->otherStaff)->create(['department' => 'CP']);

        $this->assertSame([], $this->visibleIds($this->otherStaff));
        $this->assertFalse($inactive->isVisibleTo($this->otherStaff));
        $this->assertFalse($crossDivision->isVisibleTo($this->otherStaff));
    }

    public function test_project_outside_reach_answers_404_like_a_missing_project(): void
    {
        $this->actingAs($this->staff)->get("/_test/projects/{$this->assigned->id}")->assertOk()->assertSee($this->assigned->project_code);
        $this->actingAs($this->staff)->get("/_test/projects/{$this->unassigned->id}")->assertNotFound();
        $this->actingAs($this->managerBm)->get("/_test/projects/{$this->cp->id}")->assertNotFound();
        $this->actingAs($this->managerCp)->get("/_test/projects/{$this->cp->id}")->assertOk();
        $this->actingAs($this->direksi)->get("/_test/projects/{$this->cp->id}")->assertOk();
        $this->actingAs($this->admin)->get("/_test/projects/{$this->assigned->id}")->assertOk();
        $this->actingAs($this->direksi)->get('/_test/projects/999999')->assertNotFound();
    }

    public function test_guest_cannot_reach_a_project(): void
    {
        $this->get("/_test/projects/{$this->assigned->id}")->assertRedirect('/login');
    }

    public function test_role_middleware(): void
    {
        $this->actingAs($this->managerBm)->get('/_test/manager-only')->assertOk();
        $this->actingAs($this->staff)->get('/_test/manager-only')->assertForbidden();
        $this->actingAs($this->direksi)->get('/_test/manager-only')->assertForbidden();
        $this->actingAs($this->staff)->get('/_test/staff-or-manager')->assertOk();
        $this->actingAs($this->admin)->get('/_test/staff-or-manager')->assertForbidden();
    }

    public function test_project_policy(): void
    {
        // Membuat project: hanya Staff/PIC.
        $this->assertTrue($this->staff->can('create', Project::class));
        foreach ([$this->managerBm, $this->direksi, $this->admin] as $user) {
            $this->assertFalse($user->can('create', Project::class));
        }

        // Mengubah: Staff/PIC yang ditugaskan, selama DRAFT.
        $this->assertTrue($this->staff->can('update', $this->assigned));
        $this->assertFalse($this->otherStaff->can('update', $this->assigned));
        $this->assertFalse($this->managerBm->can('update', $this->assigned));
        $active = Project::factory()->assignedTo($this->staff)->create(['status' => 'ACTIVE']);
        $this->assertFalse($this->staff->can('update', $active)); // baseline terkunci
        $this->assertTrue($this->staff->can('contribute', $active)); // BQ, invoice, progress tetap boleh

        // Menyetujui: Manager/GM divisi project.
        $this->assertTrue($this->managerBm->can('approve', $this->assigned));
        $this->assertFalse($this->managerCp->can('approve', $this->assigned));
        $this->assertFalse($this->staff->can('approve', $this->assigned));

        // Direksi melihat semua tetapi tidak bisa mengubah apa pun.
        $this->assertTrue($this->direksi->can('view', $this->cp));
        foreach (['update', 'contribute', 'approve'] as $ability) {
            $this->assertFalse($this->direksi->can($ability, $this->cp), $ability);
        }

        // System Admin hanya boleh membuka project (read-only), tidak mengubah apa pun.
        $this->assertTrue($this->admin->can('view', $this->assigned));
        foreach (['update', 'contribute', 'approve'] as $ability) {
            $this->assertFalse($this->admin->can($ability, $this->assigned), $ability);
        }
    }

    public function test_project_attributes_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new Project(['status' => 'ACTIVE', 'effective_rab' => '1.00', 'department' => 'CP']);
    }
}
