<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// AUD-01 verifikasi independen: tidak ada kebocoran lintas divisi untuk MANAGER_GM, filter, read-only, ketahanan input.
class AuditLogQaTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $bmStaff;

    private User $cpStaff;

    private Project $bm;

    private Project $cp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create(['name' => 'Manajer Budi']);
        $this->bmStaff = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => 'Staf BM Rina']);
        $this->cpStaff = User::factory()->role('STAFF_PIC', 'CP')->create(['name' => 'Staf CP Rahasia']);
        $this->bm = Project::factory()->assignedTo($this->bmStaff)->create(['project_name' => 'Proyek BM Terbuka']);
        $this->cp = Project::factory()->assignedTo($this->cpStaff)->create(['department' => 'CP', 'project_name' => 'Proyek CP Rahasia']);
    }

    private function log(string $type, int $entityId, ?string $reason, string $action = 'UPDATE', ?int $userId = -1, string $at = '2026-10-10 09:00:00', array $extra = []): int
    {
        return DB::table('audit_logs')->insertGetId(['user_id' => $userId === -1 ? $this->bmStaff->id : $userId, 'action' => $action, 'entity_type' => $type, 'entity_id' => $entityId,
            'reason' => $reason, 'created_at' => $at] + $extra);
    }

    private function bq(Project $p): int
    {
        return DB::table('bq_items')->insertGetId(['project_id' => $p->id, 'item_name' => 'Item', 'created_by' => $this->bmStaff->id]);
    }

    /** Satu baris untuk setiap entity_type terikat project milik $p; $tag membedakan teks alasannya. */
    private function projectLogs(Project $p, string $tag): void
    {
        $uid = $this->bmStaff->id;
        $bq = $this->bq($p);
        $inv = DB::table('invoices')->insertGetId(['project_id' => $p->id, 'department' => $p->department->value, 'sequence_number' => 1, 'billing_type' => 'TERMIN',
            'planned_amount' => '111.00', 'workflow_status' => 'PLANNED', 'created_by' => $uid, 'invoice_number' => 'NOINV-'.$tag]);
        $adj = DB::table('project_adjustments')->insertGetId(['project_id' => $p->id, 'adjustment_type' => 'TARGET_MARGIN', 'old_data' => '{}', 'new_data' => '{}', 'reason' => 'x', 'submitted_by' => $uid]);
        $mut = DB::table('bq_budget_mutations')->insertGetId(['project_id' => $p->id, 'destination_bq_id' => $bq, 'mutation_type' => 'FROM_UNALLOCATED_RAB', 'amount' => '5.00', 'reason' => 'x', 'submitted_by' => $uid]);
        $prog = DB::table('project_progress')->insertGetId(['project_id' => $p->id, 'entry_type' => 'WEEKLY_UPDATE', 'progress_date' => '2026-10-01', 'week_key' => '2026-W40', 'description' => 'x', 'created_by' => $uid]);
        $doc = DB::table('documents')->insertGetId(['project_id' => $p->id, 'entity_type' => 'INVOICE', 'entity_id' => $inv, 'document_type' => 'OTHER', 'original_filename' => 'f.pdf',
            'storage_key' => 'k-'.$tag, 'mime_type' => 'application/pdf', 'file_size' => 10, 'uploaded_by' => $uid]);
        $asg = DB::table('project_user_assignments')->where('project_id', $p->id)->value('id');
        foreach (['PROJECT' => $p->id, 'PROJECT_ASSIGNMENT' => $asg, 'PROJECT_ADJUSTMENT' => $adj, 'BQ_ITEM' => $bq, 'BQ_MUTATION' => $mut, 'INVOICE' => $inv, 'DOCUMENT' => $doc, 'PROGRESS' => $prog] as $type => $id) {
            $this->log($type, $id, "{$tag}-{$type}");
        }
    }

    private const PROJECT_TYPES = ['PROJECT', 'PROJECT_ASSIGNMENT', 'PROJECT_ADJUSTMENT', 'BQ_ITEM', 'BQ_MUTATION', 'INVOICE', 'DOCUMENT', 'PROGRESS'];

    public function test_manager_sees_every_project_scoped_type_of_own_division_and_nothing_else(): void
    {
        $this->projectLogs($this->bm, 'BMROW');
        $this->projectLogs($this->cp, 'CPROW');
        // Baris non-project yang entity_id-nya kebetulan sama dengan id project BM / id anak BM: entity_type guard harus menahannya.
        foreach (['USER', 'PKS', 'BOUWHEER_CONTACT', 'TAX_RATE', 'WHATEVER'] as $t) {
            $this->log($t, $this->bm->id, "NONPROJ-{$t}");
        }
        $this->log('USER', $this->bm->id, null, 'LOGIN');
        // Tipe terikat-project dengan entity_id yang tidak ada di tabelnya (yatim) atau ada di tabel divisi lain.
        $this->log('INVOICE', 999999, 'ORPHAN-INVOICE');
        $this->log('PROJECT', $this->cp->id, 'CPPROJ-DIRECT', 'APPROVE', $this->cpStaff->id);

        $res = $this->actingAs($this->manager)->get('/audit-logs')->assertOk();
        foreach (self::PROJECT_TYPES as $t) {
            $res->assertSee("BMROW-{$t}");
            $res->assertDontSee("CPROW-{$t}");
        }
        $res->assertDontSee(['NONPROJ-', 'ORPHAN-INVOICE', 'CPPROJ-DIRECT', 'NOINV-CPROW', 'Proyek CP Rahasia', 'Staf CP Rahasia'])->assertDontSee(['USER #', 'PKS #', 'BOUWHEER_CONTACT #', 'TAX_RATE #', 'WHATEVER #']);
        // Pilihan filter user tidak membocorkan nama user divisi lain.
        $res->assertSee('Staf BM Rina')->assertDontSee('Staf CP Rahasia');
        $this->assertSame(8, preg_match_all('/BMROW-/', $res->getContent())); // satu daftar untuk semua lebar layar
    }

    public function test_direksi_and_admin_see_all_including_other_division_and_master_but_never_login_rows(): void
    {
        $this->projectLogs($this->cp, 'CPROW');
        $this->log('USER', 1, 'LOGIN-ROW', 'LOGIN');
        $this->log('PKS', 1, 'PKS-ROW');
        $this->log('TAX_RATE', 1, 'TAX-ROW');
        foreach ([['DIREKSI', null], ['SYSTEM_ADMIN', 'ITBS']] as [$role, $dept]) {
            $res = $this->actingAs(User::factory()->role($role, $dept)->create())->get('/audit-logs')->assertOk()->assertSee(['PKS-ROW', 'TAX-ROW'])->assertDontSee('LOGIN-ROW');
            foreach (self::PROJECT_TYPES as $t) {
                $res->assertSee("CPROW-{$t}");
            }
        }
    }

    public function test_manager_filters_never_widen_scope(): void
    {
        $direksi = User::factory()->role('DIREKSI', null)->create(['name' => 'Direksi Dua Sisi']);
        $this->log('PROJECT', $this->bm->id, 'DIR-ON-BM', 'APPROVE', $direksi->id);
        $this->log('PROJECT', $this->cp->id, 'DIR-ON-CP', 'APPROVE', $direksi->id);
        $this->log('USER', $this->bm->id, 'DIR-ON-USER', 'APPROVE', $direksi->id);
        $this->log('USER', $this->bm->id, null, 'LOGIN', $this->cpStaff->id);
        $this->actingAs($this->manager);

        // user_id divisi lain: lolos validasi (user ada) tapi tidak ada baris miliknya di divisi BM.
        $this->get('/audit-logs?user_id='.$this->cpStaff->id)->assertOk()->assertSee('Tidak ada catatan');
        // user lintas divisi: hanya baris di project BM.
        $this->get('/audit-logs?user_id='.$direksi->id)->assertSee('DIR-ON-BM')->assertDontSee(['DIR-ON-CP', 'DIR-ON-USER']);
        $this->get('/audit-logs?action=APPROVE')->assertSee('DIR-ON-BM')->assertDontSee(['DIR-ON-CP', 'DIR-ON-USER']);
        $this->get('/audit-logs?action=LOGIN')->assertSessionHasErrors('action'); // LOGIN tidak lagi menjadi pilihan filter
        foreach (['USER', 'PKS', 'BOUWHEER_CONTACT', 'TAX_RATE'] as $t) {
            $this->get("/audit-logs?entity_type={$t}")->assertOk()->assertSee('Tidak ada catatan');
        }
        $this->get('/audit-logs?entity_type=PROJECT&date_from=2026-01-01&date_to=2026-12-31&action=APPROVE&user_id='.$direksi->id)->assertSee('DIR-ON-BM')->assertDontSee('DIR-ON-CP');
    }

    public function test_bad_input_never_gives_500(): void
    {
        $this->log('PROJECT', $this->bm->id, 'ROW');
        $bad = ['user_id[]=1', 'entity_type[]=PROJECT', 'action[]=UPDATE', 'date_from[]=2026-01-01', 'date_to[]=2026-01-01', 'user_id=1.5', 'user_id=abc', 'user_id=99999999999999999999',
            'date_from=2026-02-31', 'date_from=0000-00-00', 'date_to=10000-01-01', 'date_from=2026-1-1', 'action=login', 'action=update', 'entity_type=project', "entity_type=PROJECT'--",
            'page=abc', 'page=-1', 'page=0', 'page=99999999999999999999', 'page[]=1', 'user_id=%00'];
        foreach ([$this->manager, User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create()] as $user) {
            foreach ($bad as $qs) {
                $status = $this->actingAs($user)->get('/audit-logs?'.$qs)->getStatusCode();
                $this->assertContains($status, [200, 302], "{$user->role->value} ?{$qs} => {$status}");
            }
        }
    }

    public function test_extreme_but_valid_dates_work(): void
    {
        $this->log('PROJECT', $this->bm->id, 'ROW-EDGE', 'UPDATE', -1, '2026-10-10 16:59:59'); // 23:59:59 WIB
        $this->actingAs($this->manager);
        $this->get('/audit-logs?date_to=9999-12-30')->assertOk()->assertSee('ROW-EDGE');
        $this->get('/audit-logs?date_from=0001-01-01&date_to=2026-10-10')->assertOk()->assertSee('ROW-EDGE');
        $this->get('/audit-logs?date_from=2026-10-11')->assertOk()->assertDontSee('ROW-EDGE');
        $this->get('/audit-logs?date_to=2026-10-09')->assertOk()->assertDontSee('ROW-EDGE');
    }

    public function test_read_only_and_raw_data_never_rendered(): void
    {
        $this->log('PROJECT', $this->bm->id, 'ROW', 'UPDATE', -1, '2026-10-10 09:00:00', ['data_before' => '{"secret":"RAWBEFORE"}', 'data_after' => '{"secret":"RAWAFTER"}',
            'ip_address' => '10.9.8.7', 'user_agent' => 'UA-RAHASIA', 'request_id' => 'REQ-RAHASIA']);
        $before = DB::table('audit_logs')->count();
        foreach ([$this->manager, User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create()] as $user) {
            $this->actingAs($user)->get('/audit-logs')->assertOk()->assertSee('ROW')->assertDontSee(['{&quot;secret', '{"secret', '10.9.8.7', 'UA-RAHASIA', 'REQ-RAHASIA'], false); // JSON mentah tidak tampil; hanya baris perubahan terformat
            foreach (['post', 'put', 'patch', 'delete'] as $verb) {
                $this->{$verb.'Json'}('/audit-logs')->assertStatus(405);
                $this->{$verb.'Json'}('/audit-logs/1')->assertStatus(404);
            }
        }
        $this->assertSame($before, DB::table('audit_logs')->count());
        $verbs = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'audit-logs'))->flatMap->methods()->unique()->values()->all();
        $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $verbs);
    }

    public function test_null_actor_is_system_ties_are_ordered_by_id_desc_and_project_code_links(): void
    {
        $bq = $this->bq($this->bm);
        $first = $this->log('BQ_ITEM', $bq, 'TIE-A', 'UPDATE', null);
        $second = $this->log('BQ_ITEM', $bq, 'TIE-B', 'UPDATE', null);
        $res = $this->actingAs($this->manager)->get('/audit-logs')->assertOk()->assertSee('Sistem');
        $html = $res->getContent();
        $this->assertLessThan(strpos($html, 'TIE-A'), strpos($html, 'TIE-B'), 'id lebih besar harus tampil lebih dulu pada created_at yang sama');
        $res->assertSee('href="'.url('/projects/'.$this->bm->id).'"', false); // kode project pada baris BQ_ITEM ditautkan

        $this->log('PROJECT', $this->bm->id, 'LINKED');
        $this->actingAs($this->manager)->get('/audit-logs')->assertSee('href="'.url('/projects/'.$this->bm->id).'"', false);
    }

    public function test_actor_name_is_escaped_and_bad_page_does_not_leak(): void
    {
        $evil = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => '<script>alert(1)</script>']);
        $this->log('PROJECT', $this->bm->id, '"><img src=x onerror=alert(2)>', 'UPDATE', $evil->id);
        $this->actingAs($this->manager)->get('/audit-logs')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        // Halaman jauh di luar rentang: kosong, bukan 500.
        $this->get('/audit-logs?page=500')->assertOk();
    }

    public function test_every_allowed_role_gets_page_for_filter_combinations(): void
    {
        $this->projectLogs($this->bm, 'BMROW');
        $this->log('USER', 1, null, 'LOGIN', null);
        $combos = ['', '?entity_type=INVOICE&action=UPDATE', '?date_from=2026-10-01&date_to=2026-10-31', '?user_id='.$this->bmStaff->id.'&entity_type=PROJECT&action=UPDATE&date_from=2026-10-10&date_to=2026-10-10'];
        foreach ([$this->manager, User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create()] as $user) {
            foreach ($combos as $qs) {
                $this->actingAs($user)->get('/audit-logs'.$qs)->assertOk();
            }
        }
        $this->actingAs($this->bmStaff)->get('/audit-logs')->assertForbidden();
        $this->actingAs($this->cpStaff)->get('/audit-logs?user_id=1')->assertForbidden();
    }

    public function test_repeating_a_bad_filter_url_does_not_redirect_to_itself(): void
    {
        $this->actingAs($this->manager);
        $this->get('/audit-logs?date_from=zzz');
        $this->get('/audit-logs?date_from=zzz')->assertRedirect(url('/audit-logs'))->assertSessionHasErrors('date_from');
    }
}
