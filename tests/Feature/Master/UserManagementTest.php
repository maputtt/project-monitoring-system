<?php

namespace Tests\Feature\Master;

use App\Enums\Department;
use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// PRD FD-17, AC-20, AC-28.
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create(['name' => 'Admin Sistem']);
    }

    private function form(array $overrides = []): array
    {
        return $overrides + ['name' => 'Rina Wijaya', 'email' => 'rina@example.com', 'role' => 'STAFF_PIC', 'department' => 'BM', 'position' => 'PIC Project', 'phone_number' => '0812', 'password' => 'rahasia-awal'];
    }

    public function test_only_system_admin_can_open_user_management(): void
    {
        $this->get('/master/users')->assertRedirect('/login');

        foreach ([['STAFF_PIC', 'BM'], ['MANAGER_GM', 'BM'], ['DIREKSI', null]] as [$role, $department]) {
            $user = User::factory()->role($role, $department)->create();
            $this->actingAs($user)->get('/master/users')->assertForbidden();
            $this->actingAs($user)->post('/master/users', $this->form())->assertForbidden();
            $this->actingAs($user)->post("/master/users/{$this->admin->id}/deactivate")->assertForbidden();
        }

        $this->assertSame(0, User::where('email', 'rina@example.com')->count());
    }

    public function test_wrong_roles_cannot_touch_an_existing_user(): void
    {
        $target = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => 'Rina', 'email' => 'rina@example.com']);
        $before = $target->fresh()->toArray();
        $update = ['name' => 'Diubah', 'email' => 'rina@example.com', 'role' => 'MANAGER_GM', 'department' => 'CP'];

        foreach ([['STAFF_PIC', 'BM'], ['MANAGER_GM', 'BM'], ['DIREKSI', null]] as [$role, $department]) {
            $this->actingAs(User::factory()->role($role, $department)->create());
            $this->get('/master/users/create')->assertForbidden();
            $this->get("/master/users/{$target->id}/edit")->assertForbidden();
            $this->put("/master/users/{$target->id}", $update)->assertForbidden();
            $this->post("/master/users/{$target->id}/deactivate")->assertForbidden();
            $this->post("/master/users/{$target->id}/activate")->assertForbidden();
        }

        $this->assertSame($before, $target->fresh()->toArray());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_list_shows_users_and_search_filters_by_name_or_email(): void
    {
        User::factory()->role('STAFF_PIC', 'CP')->create(['name' => 'Budi Santoso', 'email' => 'budi@example.com']);
        User::factory()->role('DIREKSI', null)->create(['name' => 'Ibu Sari', 'email' => 'direksi@example.com', 'is_active' => false]);

        $this->actingAs($this->admin)->get('/master/users')->assertOk()
            ->assertSee('Budi Santoso')->assertSee('Ibu Sari')->assertSee('Nonaktif')->assertSee('Akun Anda')->assertSee('Belum pernah');
        $this->get('/master/users?q=budi')->assertSee('Budi Santoso')->assertDontSee('Ibu Sari');
        $this->get('/master/users?q=direksi@')->assertSee('Ibu Sari')->assertDontSee('Budi Santoso');
        $this->get('/master/users?q=tidak-ada')->assertSee('Tidak ada pengguna yang cocok');
    }

    public function test_confirm_modal_escapes_the_name_exactly_once(): void
    {
        User::factory()->role('STAFF_PIC', 'CP')->create(['name' => 'A&B <script>x</script>']);

        $html = $this->actingAs($this->admin)->get('/master/users')->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Nonaktifkan A&amp;B &lt;script&gt;x&lt;/script&gt;"', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
    }

    public function test_admin_creates_a_user_with_hashed_password_and_audit_log(): void
    {
        $this->actingAs($this->admin)->get('/master/users/create')->assertOk()->assertSee('Password awal');

        $this->post('/master/users', $this->form())->assertRedirect('/master/users')->assertSessionHas('status');

        $user = User::where('email', 'rina@example.com')->sole();
        $this->assertSame([Role::STAFF_PIC, Department::BM, true], [$user->role, $user->department, $user->is_active]);
        $this->assertTrue(Hash::check('rahasia-awal', $user->password_hash));

        $log = DB::table('audit_logs')->sole();
        $this->assertSame(['CREATE', 'USER', $user->id, $this->admin->id], [$log->action, $log->entity_type, $log->entity_id, $log->user_id]);
        $this->assertSame('STAFF_PIC', json_decode($log->data_after)->role);
        $this->assertStringNotContainsString('password', $log->data_after); // password dan hash tidak masuk audit

        // Akun baru langsung bisa dipakai masuk.
        $this->post('/logout');
        $this->post('/login', ['email' => 'rina@example.com', 'password' => 'rahasia-awal'])->assertRedirect('/dashboard');
    }

    public function test_validation_rules(): void
    {
        $this->actingAs($this->admin);
        User::factory()->create(['email' => 'sudah.ada@example.com']);

        $this->post('/master/users', $this->form(['email' => 'sudah.ada@example.com']))->assertInvalid(['email' => 'sudah dipakai']);
        $this->post('/master/users', $this->form(['department' => '']))->assertInvalid(['department' => 'Divisi wajib diisi']);
        $this->post('/master/users', $this->form(['role' => 'MANAGER_GM', 'department' => null]))->assertInvalid('department');
        $this->post('/master/users', $this->form(['department' => 'HR']))->assertInvalid(['department' => 'BM, CP, atau ITBS']);
        $this->post('/master/users', $this->form(['role' => 'FINANCE']))->assertInvalid('role'); // Finance/FAC bukan role aplikasi
        $this->post('/master/users', $this->form(['password' => 'pendek']))->assertInvalid(['password' => 'minimal 8']);
        $this->post('/master/users', $this->form(['name' => '']))->assertInvalid('name');

        $this->assertSame(0, User::where('email', 'rina@example.com')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_direksi_never_has_a_division_and_admin_division_is_optional(): void
    {
        $this->actingAs($this->admin);

        $this->post('/master/users', $this->form(['email' => 'direksi@example.com', 'role' => 'DIREKSI', 'department' => 'BM']))->assertValid();
        $this->post('/master/users', $this->form(['email' => 'admin2@example.com', 'role' => 'SYSTEM_ADMIN', 'department' => null]))->assertValid();

        $this->assertNull(User::where('email', 'direksi@example.com')->sole()->department);
        $this->assertNull(User::where('email', 'admin2@example.com')->sole()->department);
    }

    public function test_admin_updates_a_user_and_audit_keeps_old_and_new_values(): void
    {
        $user = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => 'Rina', 'email' => 'rina@example.com']);
        $oldHash = $user->password_hash;
        $this->actingAs($this->admin)->get("/master/users/{$user->id}/edit")->assertOk()->assertSee('rina@example.com')->assertDontSee('Password awal');

        $update = ['name' => 'Rina Wijaya', 'email' => 'rina@example.com', 'role' => 'MANAGER_GM', 'department' => 'CP'];
        $this->put("/master/users/{$user->id}", $update)->assertRedirect('/master/users');

        $user->refresh();
        $this->assertSame(['Rina Wijaya', Role::MANAGER_GM, Department::CP], [$user->name, $user->role, $user->department]);
        $log = DB::table('audit_logs')->sole();
        $this->assertSame(['UPDATE', 'STAFF_PIC', 'MANAGER_GM', 'BM', 'CP'], [$log->action, json_decode($log->data_before)->role, json_decode($log->data_after)->role, json_decode($log->data_before)->department, json_decode($log->data_after)->department]);

        // Password tidak bisa diubah dari halaman ini.
        $this->put("/master/users/{$user->id}", $update + ['password' => 'ganti-paksa-1'])->assertInvalid('password');
        $this->assertSame($oldHash, $user->fresh()->password_hash);

        // Email milik pengguna lain ditolak; email sendiri boleh tetap.
        $this->put("/master/users/{$user->id}", ['email' => $this->admin->email] + $update)->assertInvalid('email');
    }

    public function test_deactivate_and_activate_are_audited_and_block_login(): void
    {
        $user = User::factory()->role('STAFF_PIC', 'BM')->create(['email' => 'rina@example.com']);
        $this->actingAs($this->admin);

        $this->from('/master/users')->post("/master/users/{$user->id}/deactivate")->assertRedirect('/master/users');
        $this->assertFalse($user->fresh()->is_active);

        $this->post('/logout');
        $this->post('/login', ['email' => 'rina@example.com', 'password' => 'password'])->assertInvalid('email');

        $this->actingAs($this->admin)->post("/master/users/{$user->id}/activate");
        $this->assertTrue($user->fresh()->is_active);

        $actions = DB::table('audit_logs')->where('entity_id', $user->id)->orderBy('id')->get()->map(fn ($l) => [json_decode($l->data_before)->is_active, json_decode($l->data_after)->is_active])->all();
        $this->assertSame([[true, false], [false, true]], $actions);
    }

    public function test_admin_cannot_lock_themselves_out_and_users_are_never_deleted(): void
    {
        $this->actingAs($this->admin);

        $this->post("/master/users/{$this->admin->id}/deactivate")->assertInvalid(['user' => 'akun sendiri']);
        $this->put("/master/users/{$this->admin->id}", ['name' => 'Admin', 'email' => $this->admin->email, 'role' => 'STAFF_PIC', 'department' => 'BM'])->assertInvalid(['role' => 'akun sendiri']);
        $this->put("/master/users/{$this->admin->id}", ['name' => 'Admin Baru', 'email' => $this->admin->email, 'role' => 'SYSTEM_ADMIN', 'department' => 'ITBS'])->assertValid();

        $this->assertSame([true, Role::SYSTEM_ADMIN, 'Admin Baru'], [$this->admin->fresh()->is_active, $this->admin->fresh()->role, $this->admin->fresh()->name]);
        $this->delete("/master/users/{$this->admin->id}")->assertStatus(405); // tidak ada route hapus
    }

    private function pic(string $department = 'BM'): User
    {
        return User::factory()->role('STAFF_PIC', $department)->create();
    }

    private function sameForm(User $user, array $override = []): array
    {
        return $override + ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value, 'department' => $user->department?->value];
    }

    /** Tiga cara membuat PIC tidak berguna lagi: nonaktif, role berubah, divisi berubah. */
    private function leave(User $user, string $how)
    {
        return match ($how) {
            'deactivate' => $this->from('/master/users')->post("/master/users/{$user->id}/deactivate"),
            'role' => $this->put("/master/users/{$user->id}", $this->sameForm($user, ['role' => 'MANAGER_GM'])),
            'department' => $this->put("/master/users/{$user->id}", $this->sameForm($user, ['department' => 'CP'])),
        };
    }

    public static function leaveWays(): array
    {
        return ['deactivate' => ['deactivate', 'user'], 'role change' => ['role', 'role'], 'division change' => ['department', 'department']];
    }

    #[DataProvider('leaveWays')]
    public function test_sole_usable_pic_of_an_open_project_cannot_leave(string $how, string $field): void
    {
        $sole = $this->pic();
        $a = Project::factory()->assignedTo($sole)->create(['created_by' => $sole->id]);
        $b = Project::factory()->assignedTo($sole)->create(['created_by' => $sole->id]);
        $this->actingAs($this->admin);

        $this->leave($sole, $how)->assertInvalid([$field => "satu-satunya Staff/PIC aktif di project {$a->project_code}, {$b->project_code}. Minta Manager/GM menugaskan pengganti lebih dulu."]);
        $sole->refresh();
        $this->assertSame([true, Role::STAFF_PIC, Department::BM], [$sole->is_active, $sole->role, $sole->department]);
        $this->assertSame(0, DB::table('audit_logs')->where('entity_id', $sole->id)->count());
    }

    #[DataProvider('leaveWays')]
    public function test_pic_may_leave_when_another_usable_pic_exists_or_the_project_is_not_open(string $how, string $field): void
    {
        $sole = $this->pic();
        $shared = Project::factory()->assignedTo($sole)->create(['created_by' => $sole->id]);
        DB::table('project_user_assignments')->insert(['project_id' => $shared->id, 'user_id' => $this->pic()->id, 'assignment_role' => 'PIC', 'assigned_by' => $sole->id, 'is_active' => true]);
        foreach (['FINANCIALLY_CLOSED', 'ARCHIVED', 'CANCELLED'] as $status) {
            $p = Project::factory()->assignedTo($sole)->create(['created_by' => $sole->id]);
            DB::table('projects')->where('id', $p->id)->update(['status' => $status]);
        }
        $this->actingAs($this->admin);

        $this->leave($sole, $how)->assertValid();
        $this->assertSame(1, DB::table('audit_logs')->where('entity_id', $sole->id)->count());
    }

    public function test_the_other_pic_must_itself_be_usable_and_inactive_assignments_do_not_count(): void
    {
        $sole = $this->pic();
        $project = Project::factory()->assignedTo($sole)->create(['created_by' => $sole->id]);
        $this->actingAs($this->admin);
        $add = fn (User $u, bool $active = true) => DB::table('project_user_assignments')->insert(['project_id' => $project->id, 'user_id' => $u->id, 'assignment_role' => 'PIC', 'assigned_by' => $sole->id, 'is_active' => $active]);

        $add(User::factory()->role('STAFF_PIC', 'BM')->create(['is_active' => false])); // akun nonaktif
        $add($this->pic('CP')); // pindah divisi
        $add(User::factory()->role('MANAGER_GM', 'BM')->create()); // bukan Staff/PIC
        $add($this->pic(), false); // assignment nonaktif
        $this->leave($sole, 'deactivate')->assertInvalid('user');

        $add($this->pic()); // PIC lain yang berguna
        $this->leave($sole, 'deactivate')->assertValid();
        $this->assertFalse($sole->fresh()->is_active);
    }

    public function test_users_who_are_not_usable_pics_are_never_blocked(): void
    {
        $manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $inactive = User::factory()->role('STAFF_PIC', 'BM')->create(['is_active' => false]);
        $project = Project::factory()->assignedTo($inactive)->create(['created_by' => $inactive->id]);
        DB::table('project_user_assignments')->insert(['project_id' => $project->id, 'user_id' => $manager->id, 'assignment_role' => 'PIC', 'assigned_by' => $manager->id, 'is_active' => true]);
        $this->actingAs($this->admin);

        $this->leave($manager, 'department')->assertValid();
        $this->put("/master/users/{$inactive->id}", $this->sameForm($inactive, ['role' => 'MANAGER_GM']))->assertValid();
        $this->post("/master/users/{$inactive->id}/activate")->assertSessionHasNoErrors(); // mengaktifkan tidak pernah dicek
    }

    public function test_changes_that_keep_the_user_usable_are_allowed_for_a_sole_pic(): void
    {
        $sole = $this->pic();
        Project::factory()->assignedTo($sole)->create(['created_by' => $sole->id]);
        $this->actingAs($this->admin);

        $this->put("/master/users/{$sole->id}", $this->sameForm($sole, ['name' => 'Nama Baru', 'position' => 'Senior']))->assertValid();
        $this->assertSame('Nama Baru', $sole->fresh()->name);
    }
}
