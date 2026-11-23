<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Story C: tiket laporan masalah. Semua peran melapor, hanya pelapor dan System Admin yang melihat, screenshot privat.
class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['app.url' => 'http://pms.test']);
    }

    private function user(string $role, ?string $dept = 'BM'): User
    {
        return User::factory()->role($role, $dept)->create();
    }

    private function report(User $user, array $extra = [])
    {
        return $this->actingAs($user)->post('/support-tickets', $extra + ['title' => 'Tombol error', 'description' => 'Tidak bisa simpan']);
    }

    private function ticket(User $user, array $extra = []): object
    {
        $this->report($user, $extra)->assertSessionHasNoErrors();

        return DB::table('support_tickets')->orderByDesc('id')->first();
    }

    public function test_every_role_can_report_with_audit_row(): void
    {
        foreach ([['STAFF_PIC', 'BM'], ['MANAGER_GM', 'BM'], ['DIREKSI', null], ['SYSTEM_ADMIN', null]] as [$role, $dept]) {
            $user = $this->user($role, $dept);
            $t = $this->ticket($user);
            $this->assertSame([$user->id, 'NEW', null], [$t->reporter_id, $t->status, $t->resolved_at]);
            $log = DB::table('audit_logs')->where(['entity_type' => 'SUPPORT_TICKET', 'entity_id' => $t->id, 'action' => 'CREATE', 'user_id' => $user->id])->get();
            $this->assertCount(1, $log);
            $after = json_decode($log[0]->data_after, true);
            $this->assertSame(['title', 'status', 'page_url'], array_keys($after));
            $this->assertStringNotContainsString('Tidak bisa simpan', $log[0]->data_after);
        }
        $this->assertSame(4, DB::table('support_tickets')->count());
    }

    public function test_validation_and_guest(): void
    {
        $this->post('/support-tickets', [])->assertRedirect('/login');
        $this->report($this->user('STAFF_PIC'), ['title' => '', 'description' => ''])->assertSessionHasErrors(['title', 'description']);
        $this->assertSame(0, DB::table('support_tickets')->count());
    }

    public function test_page_url_kept_only_for_own_host(): void
    {
        $user = $this->user('STAFF_PIC');
        $this->assertSame('http://pms.test/projects/5?tab=bq', $this->ticket($user, ['page_url' => 'http://pms.test/projects/5?tab=bq'])->page_url);
        foreach (['http://evil.test/projects/5', 'http://pms.test@evil.test/x', 'javascript://pms.test/%0Aalert(1)', '/projects/5', 'not a url'] as $bad) {
            $this->assertNull($this->ticket($user, ['page_url' => $bad])->page_url, $bad);
        }
        $this->assertNull($this->ticket($user)->page_url);
    }

    public function test_create_page_prefills_only_safe_referring_page(): void
    {
        $user = $this->user('STAFF_PIC');
        $this->actingAs($user)->get('/support-tickets/create?page='.urlencode('http://pms.test/invoices'))
            ->assertOk()->assertSee('value="http://pms.test/invoices"', false)->assertSee('data finansial yang tidak perlu');
        $this->actingAs($user)->get('/support-tickets/create?page='.urlencode('http://evil.test/'))->assertOk()->assertSee('name="page_url" value=""', false);
        $this->get('/dashboard')->assertSee('Laporkan Masalah')->assertSee('Tiket saya');
    }

    public function test_screenshot_is_stored_privately_with_random_name_and_detected_extension(): void
    {
        $user = $this->user('STAFF_PIC');
        $t = $this->ticket($user, ['screenshot' => UploadedFile::fake()->create('Layar Saya.png', 100, 'image/png')]);
        $this->assertMatchesRegularExpression('#^support-tickets/[0-9a-f-]{36}\.png$#', $t->screenshot_path);
        $this->assertSame('image/png', $t->screenshot_mime);
        Storage::disk('local')->assertExists($t->screenshot_path);

        $t2 = $this->ticket($user, ['screenshot' => UploadedFile::fake()->create('foto.jpeg', 5120, 'image/jpeg')]); // tepat 5 MB
        $this->assertMatchesRegularExpression('#\.jpg$#', $t2->screenshot_path);
        $this->assertNotSame($t->screenshot_path, $t2->screenshot_path);
    }

    public function test_bad_screenshots_are_rejected_and_nothing_is_stored(): void
    {
        $user = $this->user('STAFF_PIC');
        $bad = [
            'pdf' => UploadedFile::fake()->create('laporan.pdf', 10, 'application/pdf'),
            'pdf renamed png' => UploadedFile::fake()->create('palsu.png', 10, 'application/pdf'),
            'too large' => UploadedFile::fake()->create('besar.png', 5121, 'image/png'),
            'png renamed jpg' => UploadedFile::fake()->create('palsu.jpg', 10, 'image/png'),
        ];
        foreach ($bad as $file) {
            $this->report($user, ['screenshot' => $file])->assertSessionHasErrors('screenshot');
        }
        $this->assertSame(0, DB::table('support_tickets')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('entity_type', 'SUPPORT_TICKET')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_show_and_screenshot_visible_to_reporter_and_admin_only(): void
    {
        $owner = $this->user('STAFF_PIC');
        $t = $this->ticket($owner, ['screenshot' => UploadedFile::fake()->create('a.png', 10, 'image/png')]);

        foreach ([$owner, $this->user('SYSTEM_ADMIN', null)] as $viewer) {
            $this->actingAs($viewer)->get("/support-tickets/{$t->id}")->assertOk()->assertSee('Tombol error');
            $this->actingAs($viewer)->get("/support-tickets/{$t->id}/screenshot")->assertOk()
                ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        }
        foreach ([$this->user('STAFF_PIC'), $this->user('MANAGER_GM'), $this->user('DIREKSI', null)] as $other) {
            $this->actingAs($other)->get("/support-tickets/{$t->id}")->assertNotFound();
            $this->actingAs($other)->get("/support-tickets/{$t->id}/screenshot")->assertNotFound();
        }
        $this->actingAs($owner)->get('/support-tickets/999999')->assertNotFound();
        $this->actingAs($owner)->get('/support-tickets/1abc')->assertNotFound();
    }

    public function test_page_url_in_show_is_never_a_foreign_link(): void
    {
        $owner = $this->user('STAFF_PIC');
        $t = $this->ticket($owner);
        DB::table('support_tickets')->where('id', $t->id)->update(['page_url' => 'http://evil.test/x']); // data diubah langsung di database
        $this->actingAs($owner)->get("/support-tickets/{$t->id}")->assertOk()->assertDontSee('evil.test');
    }

    public function test_only_admin_can_update_status(): void
    {
        $owner = $this->user('STAFF_PIC');
        $t = $this->ticket($owner);
        foreach ([$owner, $this->user('MANAGER_GM'), $this->user('DIREKSI', null)] as $nope) {
            $this->actingAs($nope)->put("/support-tickets/{$t->id}", ['status' => 'RESOLVED'])->assertNotFound();
        }
        $this->assertSame('NEW', DB::table('support_tickets')->where('id', $t->id)->value('status'));
        $this->assertSame(0, DB::table('audit_logs')->where(['entity_type' => 'SUPPORT_TICKET', 'action' => 'UPDATE'])->count());
    }

    public function test_admin_update_audits_before_after_and_sets_then_clears_resolved_at(): void
    {
        $admin = $this->user('SYSTEM_ADMIN', null);
        $t = $this->ticket($this->user('STAFF_PIC'));
        $audit = fn () => DB::table('audit_logs')->where(['entity_type' => 'SUPPORT_TICKET', 'entity_id' => $t->id, 'action' => 'UPDATE'])->orderBy('id')->get();
        $row = fn () => DB::table('support_tickets')->where('id', $t->id)->first();

        $this->actingAs($admin)->put("/support-tickets/{$t->id}", ['status' => 'IN_PROGRESS', 'admin_note' => 'Sedang dicek'])->assertRedirect("/support-tickets/{$t->id}");
        $this->assertSame(['IN_PROGRESS', 'Sedang dicek', $admin->id, null], [$row()->status, $row()->admin_note, $row()->handled_by, $row()->resolved_at]);

        $this->actingAs($admin)->put("/support-tickets/{$t->id}", ['status' => 'RESOLVED', 'admin_note' => 'Sudah diperbaiki']);
        $this->assertNotNull($row()->resolved_at);
        $first = $row()->resolved_at;

        $this->actingAs($admin)->put("/support-tickets/{$t->id}", ['status' => 'RESOLVED', 'admin_note' => 'Sudah diperbaiki, tes ulang']);
        $this->assertSame($first, $row()->resolved_at); // tetap Selesai: waktu selesai tidak bergeser

        $this->actingAs($admin)->put("/support-tickets/{$t->id}", ['status' => 'NEW', 'admin_note' => '']); // buka lagi: sembarang transisi
        $this->assertSame(['NEW', null, null], [$row()->status, $row()->admin_note, $row()->resolved_at]);

        $logs = $audit();
        $this->assertCount(4, $logs);
        $before = json_decode($logs[0]->data_before, true);
        $after = json_decode($logs[0]->data_after, true);
        $this->assertSame(['NEW', null, null], [$before['status'], $before['admin_note'], $before['resolved_at']]);
        $this->assertSame(['IN_PROGRESS', 'Sedang dicek'], [$after['status'], $after['admin_note']]);
        $this->assertNotNull(json_decode($logs[1]->data_after, true)['resolved_at']);
        $this->assertNull(json_decode($logs[3]->data_after, true)['resolved_at']);

        $this->actingAs($admin)->put("/support-tickets/{$t->id}", ['status' => 'NEW'])->assertSessionHasNoErrors(); // tanpa perubahan: tanpa audit
        $this->assertCount(4, $audit());

        $this->actingAs($admin)->put("/support-tickets/{$t->id}", ['status' => 'DONE'])->assertSessionHasErrors('status');
    }

    public function test_index_scoping_own_vs_all(): void
    {
        $a = $this->user('STAFF_PIC');
        $b = $this->user('MANAGER_GM');
        $this->ticket($a, ['title' => 'Milik A']);
        $this->ticket($b, ['title' => 'Milik B']);

        $this->actingAs($a)->get('/support-tickets')->assertOk()->assertSee('Milik A')->assertDontSee('Milik B');
        $this->actingAs($this->user('DIREKSI', null))->get('/support-tickets')->assertOk()->assertDontSee('Milik A')->assertDontSee('Milik B');
        $this->actingAs($this->user('SYSTEM_ADMIN', null))->get('/support-tickets')->assertOk()->assertSee('Milik A')->assertSee('Milik B');
    }

    public function test_admin_dashboard_ticket_counts_and_status_filter(): void
    {
        $a = $this->user('STAFF_PIC');
        $b = $this->user('MANAGER_GM');
        $admin = $this->user('SYSTEM_ADMIN', null);
        $this->ticket($a, ['title' => 'Baru A1']);
        $this->ticket($a, ['title' => 'Baru A2']);
        $this->ticket($b, ['title' => 'Baru B1']);
        $proses = $this->ticket($a, ['title' => 'Proses A']);
        $selesai = $this->ticket($b, ['title' => 'Selesai B']);
        $this->actingAs($admin)->put("/support-tickets/{$proses->id}", ['status' => 'IN_PROGRESS']);
        $this->actingAs($admin)->put("/support-tickets/{$selesai->id}", ['status' => 'RESOLVED']);

        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertViewHas('ticketCounts', ['NEW' => 3, 'IN_PROGRESS' => 1]);

        $this->actingAs($admin)->get('/support-tickets?status=NEW')->assertOk()
            ->assertSee('Baru A1')->assertSee('Baru A2')->assertSee('Baru B1')->assertDontSee('Proses A')->assertDontSee('Selesai B');
        $this->actingAs($admin)->get('/support-tickets?status=RESOLVED')->assertOk()->assertSee('Selesai B')->assertDontSee('Baru A1');
        $this->actingAs($admin)->get('/support-tickets?status=')->assertOk()->assertSee('Baru A1')->assertSee('Selesai B'); // kosong = semua

        $this->actingAs($a)->get('/support-tickets?status=IN_PROGRESS')->assertOk()->assertSee('Proses A')->assertDontSee('Baru A1')->assertDontSee('Selesai B');
        $this->actingAs($a)->get('/support-tickets?status=NEW')->assertOk()->assertSee('Baru A1')->assertDontSee('Baru B1');

        $this->actingAs($admin)->get('/support-tickets?status=DONE')->assertSessionHasErrors('status');
        $this->actingAs($a)->get('/support-tickets?status=new')->assertSessionHasErrors('status');
    }

    public function test_status_filter_survives_pagination(): void
    {
        $admin = $this->user('SYSTEM_ADMIN', null);
        $user = $this->user('STAFF_PIC');
        for ($i = 0; $i < 21; $i++) {
            DB::table('support_tickets')->insert(['reporter_id' => $user->id, 'title' => "T$i", 'description' => 'x', 'status' => 'NEW', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($admin)->get('/support-tickets?status=NEW')->assertOk()->assertSee('status=NEW&amp;page=2', false);
    }

    public function test_admin_form_is_for_admin_only(): void
    {
        $owner = $this->user('STAFF_PIC');
        $t = $this->ticket($owner);
        $this->actingAs($this->user('SYSTEM_ADMIN', null))->get("/support-tickets/{$t->id}")->assertSee('name="admin_note"', false);
        $this->actingAs($owner)->get("/support-tickets/{$t->id}")->assertDontSee('name="admin_note"', false);
    }

    public function test_no_delete_route_and_post_is_throttled(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'support-tickets')) {
                $this->assertNotContains('DELETE', $route->methods());
            }
        }
        $user = $this->user('STAFF_PIC');
        for ($i = 0; $i < 10; $i++) {
            $this->report($user)->assertSessionHasNoErrors();
        }
        $this->report($user)->assertStatus(429);
    }

    public function test_ticket_audit_rows_render_for_direksi(): void
    {
        $this->ticket($this->user('STAFF_PIC'), ['title' => 'Judul Unik']);
        $this->actingAs($this->user('DIREKSI', null))->get('/audit-logs')->assertOk()->assertSee('Judul Unik')->assertSee('tiket');
    }
}
