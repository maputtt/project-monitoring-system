<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

// QA independen Story B/C: celah yang tidak dicakup SupportTicketTest/AdminSystemHealthTest (isi file nyata, XSS, kegagalan metrik, rollback migrasi).
class SupportTicketQaTest extends TestCase
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

    /** File nyata di disk (bukan UploadedFile::fake) agar deteksi MIME memakai isi file. */
    private function real(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qa');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_screenshot_type_is_decided_by_file_content_not_name(): void
    {
        $user = $this->user('STAFF_PIC');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $bad = ['html as png' => ['a.png', '<html><script>alert(1)</script></html>'], 'php as png' => ['a.png', '<?php system($_GET[0]);'],
            'svg as png' => ['a.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'], 'gif as png' => ['a.png', 'GIF89a'.str_repeat("\0", 20)],
            'double ext' => ['a.php.png', '<?php echo 1;'], 'png as php' => ['a.php', $png], 'png as jpg' => ['a.jpg', $png]];
        foreach ($bad as $label => [$name, $content]) {
            $this->actingAs($user)->post('/support-tickets', ['title' => 't', 'description' => 'd', 'screenshot' => $this->real($name, $content)])->assertSessionHasErrors('screenshot');
        }
        $this->assertSame(0, DB::table('support_tickets')->count(), 'ticket dibuat dari file palsu');
        $this->assertSame([], Storage::disk('local')->allFiles());

        // PNG asli yang diberi tambahan HTML di ekor tetap disajikan sebagai image/png + nosniff, tidak pernah sebagai HTML.
        $this->actingAs($user)->post('/support-tickets', ['title' => 't', 'description' => 'd', 'screenshot' => $this->real('Foto ../../x.png', $png.'<script>alert(1)</script>')])->assertSessionHasNoErrors();
        $t = DB::table('support_tickets')->first();
        $this->assertMatchesRegularExpression('#^support-tickets/[0-9a-f-]{36}\.png$#', $t->screenshot_path);
        $r = $this->get("/support-tickets/{$t->id}/screenshot")->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('tangkapan-layar-'.$t->id.'.png', $r->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('..', $r->headers->get('Content-Disposition'));
    }

    public function test_user_text_is_escaped_everywhere_it_is_rendered(): void
    {
        $x = '<script>alert(7)</script>';
        $owner = $this->user('STAFF_PIC');
        $admin = $this->user('SYSTEM_ADMIN', null);
        $this->actingAs($owner)->post('/support-tickets', ['title' => "T$x", 'description' => "D$x", 'page_url' => 'http://pms.test/x?a="><script>alert(7)</script>'])->assertSessionHasNoErrors();
        $t = DB::table('support_tickets')->first();
        $this->actingAs($admin)->put("/support-tickets/{$t->id}", ['status' => 'IN_PROGRESS', 'admin_note' => "</textarea>N$x"])->assertSessionHasNoErrors();

        $pages = [[$owner, "/support-tickets/{$t->id}"], [$admin, "/support-tickets/{$t->id}"], [$owner, '/support-tickets'], [$admin, '/support-tickets'], [$admin, '/dashboard'],
            [$this->user('DIREKSI', null), '/audit-logs'], [$owner, '/support-tickets/create?page='.urlencode('http://pms.test/x?a="><script>alert(7)</script>')]];
        foreach ($pages as [$u, $url]) {
            $html = $this->actingAs($u)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('alert(7)</script>', $html, $url);
            $this->assertStringNotContainsString('</textarea>N', $html, $url);
        }
    }

    public function test_malformed_page_param_on_create_does_not_500(): void
    {
        $this->actingAs($this->user('STAFF_PIC'))->get('/support-tickets/create?page[]=x')->assertOk();
        $this->actingAs($this->user('STAFF_PIC'))->post('/support-tickets', ['title' => 't', 'description' => 'd', 'page_url' => ['x']])->assertSessionHasErrors('page_url');
    }

    public function test_page_url_hostile_variants_are_dropped(): void
    {
        $user = $this->user('STAFF_PIC');
        $hostile = ['data:text/html,<script>alert(1)</script>', 'javascript:alert(1)', 'http://pms.test.evil.test/x', 'http://evil.test/?u=http://pms.test/', 'http://pms.test:80@evil.test/', 'http://pms.test\\@evil.test/',
            'http://pms.test/ onclick=alert(1)', "http://pms.test/x\r\nLocation: http://evil.test", 'ftp://pms.test/x', 'http://pms.test/'.str_repeat('a', 2100)];
        foreach ($hostile as $url) {
            $this->actingAs($user)->post('/support-tickets', ['title' => 't', 'description' => 'd', 'page_url' => $url]);
        }
        $this->assertSame(array_fill(0, count($hostile), null), DB::table('support_tickets')->orderBy('id')->pluck('page_url')->all());
    }

    public function test_non_admin_put_cannot_probe_ticket_existence(): void
    {
        $staff = $this->user('STAFF_PIC');
        $this->actingAs($staff)->post('/support-tickets', ['title' => 't', 'description' => 'd']);
        $id = DB::table('support_tickets')->value('id');
        $this->assertSame($this->actingAs($staff)->put("/support-tickets/{$id}", ['status' => 'RESOLVED'])->getStatusCode(),
            $this->actingAs($staff)->put('/support-tickets/999999', ['status' => 'RESOLVED'])->getStatusCode(), 'PUT membedakan id ada dan tidak ada');
    }

    public function test_metric_write_failure_never_breaks_the_request(): void
    {
        Exceptions::fake();
        $armed = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$armed) {
            if ($armed && str_contains($query, 'request_metrics')) {
                throw new \RuntimeException('metrics down');
            }
        });
        $this->actingAs($this->user('STAFF_PIC'))->get('/dashboard')->assertOk();
        Exceptions::assertReported(\RuntimeException::class);
        $armed = false;
        $this->assertSame(0, DB::table('request_metrics')->count());
    }

    public function test_guest_and_5xx_and_inactive_user_effects(): void
    {
        $this->get('/login')->assertOk(); // tamu tercatat sebagai request, tanpa pengguna
        $this->assertSame(1, (int) DB::table('request_metrics')->sum('request_count'));

        $inactive = $this->user('STAFF_PIC');
        $inactive->forceFill(['is_active' => false])->save();
        $this->actingAs($inactive)->get('/dashboard')->assertRedirect('/login');
        $this->assertNull(DB::table('users')->where('id', $inactive->id)->value('last_seen_at'), 'akun nonaktif tidak boleh ditandai online');
    }

    public function test_failed_ticket_insert_leaves_no_orphan_file(): void
    {
        $user = $this->user('STAFF_PIC');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        DB::connection()->beforeExecuting(function (string $query) {
            if (str_contains($query, 'insert into `support_tickets`')) {
                throw new \RuntimeException('db down');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->post('/support-tickets', ['title' => 't', 'description' => 'd', 'screenshot' => $this->real('a.png', $png)]);
            $this->fail('harus melempar');
        } catch (\RuntimeException) {
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, DB::table('audit_logs')->where('entity_type', 'SUPPORT_TICKET')->count());
    }

    public function test_admin_dashboard_exposes_no_project_data_and_sections_stay_admin_only(): void
    {
        $staff = $this->user('STAFF_PIC');
        $p = Project::factory()->assignedTo($staff)->create(['project_code' => 'BM-2026-QAX', 'project_name' => 'Proyek Rahasia QAX', 'status' => 'ACTIVE']);
        $html = $this->actingAs($this->user('SYSTEM_ADMIN', null))->get('/dashboard')->assertOk()->getContent();
        foreach (['BM-2026-QAX', 'Proyek Rahasia QAX', 'Rp', '/projects/'.$p->id] as $leak) {
            $this->assertStringNotContainsString($leak, $html);
        }
        foreach ([$staff, $this->user('MANAGER_GM'), $this->user('DIREKSI', null)] as $other) {
            $this->actingAs($other)->get('/dashboard')->assertOk()->assertDontSee('Pengguna online')->assertDontSee('Tiket baru')->assertDontSee('Kesehatan sistem');
        }
    }

    public function test_down_refuses_while_rows_exist(): void
    {
        $u = $this->user('STAFF_PIC');
        DB::table('support_tickets')->insert(['reporter_id' => $u->id, 'title' => 't', 'description' => 'd', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => 'App\Models\User', 'notifiable_id' => $u->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['2026_11_16_000004_create_support_tickets_table', '2026_11_16_000005_create_notifications_table'] as $file) {
            $migration = require database_path("migrations/$file.php");
            try {
                $migration->down();
                $this->fail("$file down() tidak menolak");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Rollback ditolak', $e->getMessage());
            }
        }
        $this->assertSame(1, DB::table('support_tickets')->count());
        $this->assertSame(1, DB::table('notifications')->count());
    }
}
