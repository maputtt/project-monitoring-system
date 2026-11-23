<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

// Verifikasi independen Story A (PM 2026-11-16): SYSTEM_ADMIN hanya dashboard admin dan Pengguna. Menyapu SEMUA route terdaftar.
class AdminScopeQaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->admin = User::factory()->role('SYSTEM_ADMIN', null)->create();
        $this->project = Project::factory()->assignedTo($staff)->create(['created_by' => $staff->id, 'status' => 'ACTIVE', 'project_code' => 'BM-2026-777']);
        $p = $this->project->id;
        $bq = DB::table('bq_items')->insertGetId(['project_id' => $p, 'item_name' => 'Item', 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'created_by' => $staff->id,
            'baseline_budget' => '777000.00', 'effective_budget' => '777000.00', 'negotiated_value' => '777000.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000']);
        $invoice = DB::table('invoices')->insertGetId(['invoice_number' => 'INV/1', 'billing_date' => '2026-08-20', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500',
            'dpp_amount' => '100.01', 'ppn_amount' => '11.00', 'subtotal_amount' => '111.01', 'pph_amount' => '2.65', 'net_received_amount' => '108.36', 'submitted_by' => $staff->id,
            'submitted_at' => now(), 'project_id' => $p, 'department' => 'BM', 'sequence_number' => 1, 'billing_type' => 'TERMIN', 'billing_percentage' => '50.0000',
            'planned_amount' => '111.01', 'workflow_status' => 'INVOICE_SUBMITTED', 'created_by' => $staff->id]);
        $this->ids = [
            'project' => $p, 'bq' => $bq, 'invoice' => $invoice, 'group' => 'active', 'user' => $staff->id, 'token' => 'abc',
            'document' => DB::table('documents')->insertGetId(['project_id' => $p, 'entity_type' => 'PROJECT', 'entity_id' => $p, 'document_type' => 'BAPP', 'original_filename' => 'a.pdf',
                'storage_key' => 'k/a', 'mime_type' => 'application/pdf', 'file_size' => 10, 'status' => 'UPLOADED', 'uploaded_by' => $staff->id]),
            'adjustment' => DB::table('project_adjustments')->insertGetId(['project_id' => $p, 'adjustment_type' => 'TARGET_MARGIN', 'status' => 'SUBMITTED', 'reason' => 'x', 'old_data' => '{}', 'submitted_by' => $staff->id,
                'submitted_at' => now(), 'new_data' => json_encode(['margin_mode' => 'AMOUNT', 'margin_value' => '1.00'])]),
            'mutation' => DB::table('bq_budget_mutations')->insertGetId(['project_id' => $p, 'source_bq_id' => null, 'destination_bq_id' => $bq, 'amount' => '100.00', 'reason' => 'x',
                'mutation_type' => 'FROM_UNALLOCATED_RAB', 'status' => 'SUBMITTED', 'submitted_by' => $staff->id, 'submitted_at' => now()]),
            'assignment' => (int) DB::table('project_user_assignments')->value('id'),
            'pks' => 1, 'contact' => 1,
        ];
        Storage::disk('local')->put('support-tickets/x.png', 'png');
        $this->ids['ticket'] = DB::table('support_tickets')->insertGetId(['reporter_id' => $this->admin->id, 'title' => 'Tiket admin', 'description' => 'x', 'screenshot_path' => 'support-tickets/x.png', 'screenshot_mime' => 'image/png', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function uri(string $uri): string
    {
        return preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $this->ids[$m[1]] ?? 1, $uri);
    }

    private function snapshot(): array
    {
        return collect(DB::select('SHOW TABLES'))->map(fn ($t) => array_values((array) $t)[0])->reject(fn ($t) => in_array($t, ['sessions', 'migrations', 'request_metrics'], true)) // request_metrics: efek samping middleware metrik
            ->mapWithKeys(fn ($t) => [$t => md5(json_encode(DB::table($t)->orderBy(DB::raw('1'))->get()->map(fn ($r) => collect($r)->except($t === 'users' ? ['last_seen_at'] : [])->all())))])->all();
    }

    public function test_every_get_route_is_allowed_admin_page_or_denied_without_leaking_data(): void
    {
        $allowed = ['/', 'dashboard', 'master/users', 'master/users/create', 'master/users/{user}/edit', 'support-tickets', 'support-tickets/create', 'support-tickets/{ticket}', 'support-tickets/{ticket}/screenshot', 'login', 'forgot-password', 'reset-password/{token}', 'ui-kit', 'up', 'passkeys', 'passkey/unlock-options']; // passkeys: semua peran, milik sendiri; unlock-options: 204 bila sudah login
        $this->ids['user'] = $this->admin->id;
        $seen = 0;
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = $route->uri();
            $url = $uri === 'documents/{document}/download'
                ? URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $this->ids['document']])
                : '/'.ltrim($this->uri($uri), '/');
            $res = $this->actingAs($this->admin)->get($url);
            $seen++;
            if (in_array($uri, $allowed, true)) {
                $this->assertContains($res->getStatusCode(), [200, 204, 302], "$url diizinkan, tapi {$res->getStatusCode()}");
            } else {
                $this->assertContains($res->getStatusCode(), [403, 404, 302], "$url harus ditolak, tapi {$res->getStatusCode()}");
                $this->assertNotSame(200, $res->getStatusCode(), $url);
            }
            $body = (string) $res->getContent();
            $this->assertStringNotContainsString('BM-2026-777', $body, $url);
            if ($uri !== 'ui-kit') { // ui-kit: halaman dev (tidak ada di production) berisi contoh PRD 6.3 statis, bukan data project
                $this->assertDoesNotMatchRegularExpression('/Rp\s?\d|777\.000|777,000/', $body, $url);
            }
            if ($res->getStatusCode() === 302 && ! in_array($uri, ['login', 'forgot-password', 'reset-password/{token}'], true)) {
                $this->assertContains(parse_url($res->headers->get('Location'), PHP_URL_PATH), ['/dashboard', null, '/'], "$url mengalihkan ke ".$res->headers->get('Location'));
            }
        }
        $this->assertGreaterThan(25, $seen);
    }

    public function test_every_write_route_is_denied_for_admin_and_changes_nothing(): void
    {
        $before = $this->snapshot();
        $payload = ['reason' => 'x', 'version' => 1, 'amount' => '1.00', 'user_id' => $this->admin->id, 'assignment_role' => 'PIC', 'document_type' => 'BAPP', 'item_name' => 'x', 'item_type' => 'NORMAL'];
        $skip = ['login', 'logout', 'forgot-password', 'reset-password', 'master/users', 'master/users/{user}/activate', 'master/users/{user}/deactivate', 'master/users/{user}', 'support-tickets', 'support-tickets/{ticket}', 'notifications/read-all', 'push-subscriptions', 'passkeys/options', 'passkeys', 'passkey/unlock']; // passkey*: semua peran, hanya perangkat sendiri / belum login; read-all dan push-subscriptions: semua peran, hanya menyentuh milik sendiri (Story E, D)
        $count = 0;
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            foreach (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS', 'QUERY', 'PATCH']) as $method) {
                if ($uri === '/' || in_array($uri, $skip, true)) {
                    continue;
                }
                $res = $this->actingAs($this->admin)->call($method, '/'.ltrim($this->uri($uri), '/'), $payload);
                $this->assertContains($res->getStatusCode(), [403, 404], "$method $uri -> {$res->getStatusCode()}");
                $count++;
            }
        }
        $this->assertGreaterThan(30, $count);
        $this->assertSame($before, $this->snapshot());
    }

    // D-A-1 (keputusan PM 2026-11-19): pesan guard "satu-satunya Staff/PIC" hanya memuat jumlah dan divisi, tanpa kode project.
    public function test_admin_user_guard_message_does_not_reveal_project_codes(): void
    {
        $res = $this->actingAs($this->admin)->post('/master/users/'.$this->ids['user'].'/deactivate');
        $this->assertStringNotContainsString('BM-2026-777', json_encode(app('session.store')->get('errors')));
        $res->assertSessionHasErrors('user');
    }
}
