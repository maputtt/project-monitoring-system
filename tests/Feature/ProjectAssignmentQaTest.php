<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Verifikasi independen MST-03: celah yang belum dicakup ProjectAssignmentTest.
class ProjectAssignmentQaTest extends TestCase
{
    use RefreshDatabase;

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

    private function act(string $uri, array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->manager)->postJson($uri, $data);
    }

    private function assignUri(?Project $p = null): string
    {
        return '/projects/'.($p ?? $this->project)->id.'/assignments';
    }

    private function rows(?Project $p = null)
    {
        return DB::table('project_user_assignments')->where('project_id', ($p ?? $this->project)->id)->get();
    }

    private function auditCount(): int
    {
        return DB::table('audit_logs')->where('entity_type', 'PROJECT_ASSIGNMENT')->count();
    }

    public function test_inactive_manager_is_logged_out_and_changes_nothing(): void
    {
        $inactive = User::factory()->role('MANAGER_GM', 'BM')->create(['is_active' => false]);
        $id = $this->rows()->first()->id;

        $this->act($this->assignUri(), ['user_id' => $this->other->id, 'assignment_role' => 'PIC'], $inactive)->assertRedirect('/login');
        $this->act("/assignments/$id/deactivate", [], $inactive)->assertRedirect('/login');
        $this->assertCount(1, $this->rows());
        $this->assertSame(0, $this->auditCount());
    }

    public function test_guest_cannot_use_either_route(): void
    {
        $this->postJson($this->assignUri(), [])->assertUnauthorized();
        $this->postJson('/assignments/1/deactivate')->assertUnauthorized();
    }

    public function test_malformed_input_is_rejected_without_server_error(): void
    {
        $other = $this->other->id;
        foreach ([
            [['assignment_role' => 'PIC'], 'user_id'],
            [['user_id' => 'abc', 'assignment_role' => 'PIC'], 'user_id'],
            [['user_id' => [$other], 'assignment_role' => 'PIC'], 'user_id'],
            [['user_id' => 999999, 'assignment_role' => 'PIC'], 'user_id'],
        ] as [$payload, $field]) {
            $this->act($this->assignUri(), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertCount(1, $this->rows());
        $this->assertSame(0, $this->auditCount());
    }

    public function test_array_role_input_is_ignored_like_any_other_role_value(): void
    {
        $this->act($this->assignUri(), ['user_id' => $this->other->id, 'assignment_role' => ['VIEWER']])->assertRedirect();
        $this->assertSame('PIC', $this->rows()->firstWhere('user_id', $this->other->id)->assignment_role);
    }

    public function test_legacy_non_pic_row_stays_and_a_new_pic_row_is_created_after_it_is_deactivated(): void
    {
        $legacy = DB::table('project_user_assignments')->insertGetId(['project_id' => $this->project->id, 'user_id' => $this->other->id, 'assignment_role' => 'VIEWER', 'assigned_by' => $this->manager->id, 'is_active' => true]);
        $this->act($this->assignUri(), ['user_id' => $this->other->id])->assertUnprocessable(); // sudah aktif (apa pun perannya)
        $this->act("/assignments/$legacy/deactivate")->assertRedirect();

        $this->act($this->assignUri(), ['user_id' => $this->other->id])->assertRedirect();
        $mine = $this->rows()->where('user_id', $this->other->id);
        $this->assertSame([['VIEWER', 0], ['PIC', 1]], $mine->sortBy('id')->map(fn ($r) => [$r->assignment_role, (int) $r->is_active])->values()->all());
    }

    public function test_reactivation_records_the_acting_manager_and_a_fresh_assigned_at(): void
    {
        $second = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->act($this->assignUri(), ['user_id' => $this->other->id, 'assignment_role' => 'PIC'])->assertRedirect();
        $id = $this->rows()->firstWhere('user_id', $this->other->id)->id;
        $this->act("/assignments/$id/deactivate")->assertRedirect();

        $this->act($this->assignUri(), ['user_id' => $this->other->id, 'assignment_role' => 'PIC'], $second)->assertRedirect();
        $this->assertSame($second->id, (int) DB::table('project_user_assignments')->where('id', $id)->value('assigned_by'));
        $this->assertSame($second->id, (int) DB::table('audit_logs')->where('entity_type', 'PROJECT_ASSIGNMENT')->orderByDesc('id')->value('user_id'));
    }

    public function test_every_status_except_closed_allows_team_changes(): void
    {
        foreach (ProjectStatus::cases() as $status) {
            $p = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id]);
            DB::table('projects')->where('id', $p->id)->update(['status' => $status->value]);
            $res = $this->act($this->assignUri($p), ['user_id' => $this->other->id, 'assignment_role' => 'PIC']);
            $status === ProjectStatus::FINANCIALLY_CLOSED ? $res->assertForbidden() : $res->assertRedirect();
        }
    }

    public function test_deactivate_edge_routes(): void
    {
        $id = $this->rows()->first()->id;
        $this->actingAs($this->manager)->get("/assignments/$id/deactivate")->assertStatus(405);
        $this->act('/assignments/abc/deactivate')->assertNotFound();
        $this->act('/assignments/0/deactivate')->assertNotFound();
    }

    public function test_assignment_of_another_project_in_the_same_division_is_manageable_but_not_across_divisions(): void
    {
        $cpStaff = User::factory()->role('STAFF_PIC', 'CP')->create();
        $cpProject = Project::factory()->assignedTo($cpStaff)->create(['created_by' => $cpStaff->id, 'department' => 'CP']);
        $cpId = $this->rows($cpProject)->first()->id;

        $this->act("/assignments/$cpId/deactivate")->assertNotFound();
        $this->act($this->assignUri($cpProject), ['user_id' => $cpStaff->id, 'assignment_role' => 'PIC'])->assertNotFound();
        $this->assertTrue((bool) $this->rows($cpProject)->first()->is_active);

        // Staff divisi lain tidak bisa ditugaskan ke project BM, dan sebaliknya.
        $this->act($this->assignUri(), ['user_id' => $cpStaff->id, 'assignment_role' => 'PIC'])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_assignment_change_is_atomic_when_audit_write_fails(): void
    {
        $this->act($this->assignUri(), ['user_id' => $this->other->id, 'assignment_role' => 'PIC'])->assertRedirect();
        $otherId = $this->rows()->firstWhere('user_id', $this->other->id)->id;
        $before = $this->auditCount();

        DB::listen(function ($query) {
            if (str_contains($query->sql, 'into `audit_logs`')) {
                throw new \RuntimeException('audit down');
            }
        });
        $third = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->withoutExceptionHandling();

        foreach ([
            fn () => $this->act($this->assignUri(), ['user_id' => $third->id, 'assignment_role' => 'PIC']),
            fn () => $this->act("/assignments/$otherId/deactivate"),
        ] as $call) {
            try {
                $call();
                $this->fail('audit failure must surface');
            } catch (\RuntimeException $e) {
                $this->assertSame('audit down', $e->getMessage());
            }
        }
        $this->assertCount(2, $this->rows()); // tidak ada baris baru untuk $third
        $this->assertTrue((bool) DB::table('project_user_assignments')->where('id', $otherId)->value('is_active')); // penonaktifan dibatalkan
        $this->assertSame($before, $this->auditCount());
    }

    public function test_deactivated_staff_loses_list_and_detail_access_in_the_same_session(): void
    {
        $this->act($this->assignUri(), ['user_id' => $this->other->id, 'assignment_role' => 'PIC'])->assertRedirect();
        $mine = $this->rows()->firstWhere('user_id', $this->other->id)->id;
        $token = $this->project->project_code;

        $this->actingAs($this->other);
        $this->get('/projects')->assertOk()->assertSee($token);
        $this->get("/projects/{$this->project->id}")->assertOk();

        $this->act("/assignments/$mine/deactivate")->assertRedirect(); // oleh manajer, sesi staff tetap terbuka
        $this->actingAs($this->other);
        $this->get('/projects')->assertOk()->assertDontSee($token);
        $this->get("/projects/{$this->project->id}")->assertNotFound();
        $this->get("/projects/{$this->project->id}/edit")->assertNotFound();
    }

    public function test_names_and_emails_are_escaped_on_the_team_card_and_form(): void
    {
        $evil = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => 'Zed <script>alert(1)</script> O\'Neil & Co', 'email' => 'zed"><b>@example.com']);
        $this->act($this->assignUri(), ['user_id' => User::factory()->role('STAFF_PIC', 'BM')->create()->id, 'assignment_role' => 'PIC']);

        $html = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>@example.com', $html);

        $this->act($this->assignUri(), ['user_id' => $evil->id, 'assignment_role' => 'PIC']);
        $html = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_last_active_row_shows_a_hint_and_no_button_while_others_get_buttons(): void
    {
        $html = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('Nonaktifkan', $html);
        $this->assertStringContainsString('Staff/PIC aktif terakhir', $html);

        $this->act($this->assignUri(), ['user_id' => $this->other->id, 'assignment_role' => 'PIC']);
        $html = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->getContent();
        $this->assertSame(2, substr_count($html, 'btn btn-warn'));
        $this->assertStringNotContainsString('Staff/PIC aktif terakhir', $html);
    }

    public function test_field_errors_render_after_a_form_post(): void
    {
        $this->actingAs($this->manager)->from("/projects/{$this->project->id}")
            ->post($this->assignUri(), ['user_id' => $this->staff->id, 'assignment_role' => 'PIC'])
            ->assertRedirect("/projects/{$this->project->id}");
        $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertSee('sudah ditugaskan aktif', false);

        $id = $this->rows()->first()->id;
        $this->actingAs($this->manager)->from("/projects/{$this->project->id}")->post("/assignments/$id/deactivate")->assertSessionHasErrors('assignment');
        $this->followingRedirects()->actingAs($this->manager)->from("/projects/{$this->project->id}")->post("/assignments/$id/deactivate")
            ->assertSee('minimal satu Staff/PIC aktif', false);
    }

    public function test_closed_project_page_hides_every_team_control_from_the_manager(): void
    {
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'FINANCIALLY_CLOSED']);
        $page = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()
            ->assertDontSee('Tugaskan', false)->assertDontSee('aria-label="Nonaktifkan', false);
        $this->assertFalse($page->viewData('can')['manageTeam']);
        $this->assertCount(0, $page->viewData('eligible'));
    }

    public function test_ui_hint_and_buttons_follow_the_usable_pic_rule_and_there_is_no_role_select(): void
    {
        $ghost = User::factory()->role('STAFF_PIC', 'BM')->create(['is_active' => false, 'name' => 'Ghost Akun']);
        DB::table('project_user_assignments')->insert(['project_id' => $this->project->id, 'user_id' => $ghost->id, 'assignment_role' => 'PIC', 'assigned_by' => $this->manager->id, 'is_active' => true]);

        $page = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk();
        $html = $page->getContent();
        $this->assertStringNotContainsString('name="assignment_role"', $html);
        $this->assertSame(1, substr_count($html, 'btn btn-warn')); // hanya baris akun nonaktif yang boleh dibersihkan
        $this->assertSame(1, substr_count($html, 'Staff/PIC aktif terakhir')); // satu-satunya PIC berguna: petunjuk, bukan tombol
        $this->assertStringContainsString('aria-label="Nonaktifkan Ghost Akun"', $html);

        // Pembersihan baris hantu boleh; PIC berguna terakhir tetap tidak boleh.
        $this->act('/assignments/'.$this->rows()->firstWhere('user_id', $ghost->id)->id.'/deactivate')->assertRedirect();
        $this->act('/assignments/'.$this->rows()->firstWhere('user_id', $this->staff->id)->id.'/deactivate')->assertUnprocessable();
    }

    public function test_assignment_whose_only_other_row_is_an_unusable_user_is_refused(): void
    {
        $ghost = User::factory()->role('STAFF_PIC', 'BM')->create(['is_active' => false]);
        DB::table('project_user_assignments')->insert(['project_id' => $this->project->id, 'user_id' => $ghost->id, 'assignment_role' => 'PIC', 'assigned_by' => $this->manager->id, 'is_active' => true]);
        $this->act('/assignments/'.$this->rows()->firstWhere('user_id', $this->staff->id)->id.'/deactivate')->assertUnprocessable();
        $this->assertTrue((bool) $this->rows()->firstWhere('user_id', $this->staff->id)->is_active);
    }

    public function test_names_with_special_characters_are_escaped_once_in_modal_titles(): void
    {
        $this->staff->update(['name' => 'A&B <i>']);
        $this->act($this->assignUri(), ['user_id' => $this->other->id])->assertRedirect();
        $html = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->getContent();
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringContainsString('<h2 class="text-lg">Nonaktifkan A&amp;B &lt;i&gt;?</h2>', $html);

        $admin = User::factory()->role('SYSTEM_ADMIN', null)->create();
        $html = $this->actingAs($admin)->get('/master/users')->getContent();
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringContainsString('Nonaktifkan A&amp;B &lt;i&gt;</h2>', $html);
    }
}
