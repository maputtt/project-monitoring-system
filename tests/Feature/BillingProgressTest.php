<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Support\AuditSentence;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Progres dokumen penagihan per termin (project_progress BILLING): tulis, kendala aktif, dashboard, halaman project, audit.
class BillingProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-28 03:00:00'); // 10:00 WIB
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => 'Rina']);
        $this->project = Project::factory()->active()->assignedTo($this->staff)->create(['project_code' => 'BM-2026-102', 'created_by' => $this->staff->id]);
    }

    private function invoice(?Project $project = null, int $seq = 1, string $status = 'PLANNED'): int
    {
        $project ??= $this->project;

        return DB::table('invoices')->insertGetId([
            'project_id' => $project->id, 'department' => $project->department->value, 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'billing_percentage' => '10.0000',
            'planned_amount' => '100.00', 'workflow_status' => $status, 'created_by' => $this->staff->id,
        ] + ($status === 'PAID' ? ['invoice_number' => "INV/$seq", 'billing_date' => '2026-09-01', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500', 'subtotal_amount' => '111.00', 'net_received_amount' => '108.00', 'locked_at' => now(), 'locked_by' => $this->staff->id] : []));
    }

    private function progressRow(int $invoice, string $date, bool $issue, string $stage = 'BAPP', ?Project $project = null): int
    {
        return DB::table('project_progress')->insertGetId([
            'project_id' => ($project ?? $this->project)->id, 'invoice_id' => $invoice, 'entry_type' => 'BILLING', 'stage' => $stage, 'is_issue' => $issue,
            'progress_date' => $date, 'week_key' => '2026-W44', 'description' => "Catatan $date", 'created_by' => $this->staff->id,
        ]);
    }

    private function record(int $invoice, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->staff)->post("/invoices/$invoice/progress", $extra + ['stage' => 'BAST', 'progress_date' => '2026-10-27', 'description' => 'BAST sudah ditandatangani', 'is_issue' => '1']);
    }

    public function test_check_constraints_reject_incomplete_billing_rows(): void
    {
        $base = ['project_id' => $this->project->id, 'entry_type' => 'BILLING', 'progress_date' => '2026-10-27', 'week_key' => '2026-W44', 'description' => 'x', 'created_by' => $this->staff->id];
        foreach ([['stage' => 'BAST'], ['invoice_id' => $this->invoice(), 'stage' => 'SALAH'], ['invoice_id' => $this->invoice(null, 2)]] as $extra) {
            try {
                DB::table('project_progress')->insert($base + $extra);
                $this->fail('CHECK chk_progress_billing seharusnya menolak baris ini.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('chk_progress_billing', $e->getMessage());
            }
        }
    }

    public function test_staff_records_progress_with_week_key_and_readable_audit(): void
    {
        $this->invoice();
        $id = $this->invoice(null, 2);

        $this->record($id)->assertRedirect(route('projects.show', $this->project).'#jadwal')->assertSessionHas('status', 'Progres termin 2 dicatat.');

        $row = DB::table('project_progress')->first();
        $this->assertSame(['BILLING', 'BAST', 1, '2026-W44', $id, $this->staff->id], [$row->entry_type, $row->stage, (int) $row->is_issue, $row->week_key, $row->invoice_id, $row->created_by]);
        $logs = DB::table('audit_logs')->where('entity_type', 'PROGRESS')->get();
        $this->assertCount(1, $logs);
        $this->assertSame('CREATE', $logs[0]->action);

        $logs[0]->user_name = 'Rina';
        AuditSentence::decorate($logs);
        $this->assertSame(['mencatat', 'progres termin 2 project ', 'BM-2026-102'], [$logs[0]->verb, $logs[0]->object['pre'], $logs[0]->object['code']]);
        $this->assertEqualsCanonicalizing(['Tahap: BAST', 'Tanggal: 27/10/2026', 'Kendala: Ya', 'Catatan: BAST sudah ditandatangani'], $logs[0]->details);

        $this->travelTo('2027-01-01 03:00:00'); // ISO week 53 tahun 2026 jatuh ke 2027-01-01 (Jumat)
        $this->record($id, ['progress_date' => '2027-01-01', 'is_issue' => null])->assertSessionDoesntHaveErrors();
        $last = DB::table('project_progress')->orderByDesc('id')->first();
        $this->assertSame(['2026-W53', 0], [$last->week_key, (int) $last->is_issue]);
    }

    public function test_validation_rejects_bad_input_without_writing(): void
    {
        $id = $this->invoice();
        $bag = "progress-$id";

        $this->record($id, ['progress_date' => '2026-10-29'])->assertSessionHasErrors('progress_date', null, $bag); // besok (WIB)
        $this->record($id, ['progress_date' => '1999-12-31'])->assertSessionHasErrors('progress_date', null, $bag);
        $this->record($id, ['stage' => 'SALAH'])->assertSessionHasErrors('stage', null, $bag);
        $this->record($id, ['description' => '  '])->assertSessionHasErrors('description', null, $bag);
        $this->record($id, ['description' => str_repeat('a', 1001)])->assertSessionHasErrors('description', null, $bag);
        $this->assertSame([0, 0], [DB::table('project_progress')->count(), DB::table('audit_logs')->where('entity_type', 'PROGRESS')->count()]);
        $this->record($id, ['progress_date' => '2026-10-28'])->assertSessionDoesntHaveErrors(); // hari ini (WIB) boleh
    }

    public function test_authorization_and_state_guards(): void
    {
        $id = $this->invoice();
        $this->record($id, [], User::factory()->role('MANAGER_GM', 'BM')->create())->assertForbidden();
        $this->record($id, [], User::factory()->role('DIREKSI', null)->create())->assertForbidden();
        $this->record($id, [], User::factory()->role('STAFF_PIC', 'CP')->create())->assertNotFound();
        $this->record($id, [], User::factory()->role('STAFF_PIC', 'BM')->create())->assertNotFound(); // BM tetapi tidak ditugaskan

        foreach (['PAID', 'CANCELLED'] as $i => $status) {
            $this->record($this->invoice(null, 2 + $i, $status))->assertStatus(409);
        }
        $draft = Project::factory()->assignedTo($this->staff)->create(); // DRAFT
        $this->record($this->invoice($draft))->assertForbidden();
        $this->assertSame(0, DB::table('project_progress')->count());
    }

    public function test_active_issue_follows_latest_row_and_dashboard_count_equals_list(): void
    {
        $this->travelTo('2026-10-28 03:00:00');
        $manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $direksi = User::factory()->role('DIREKSI', null)->create();

        $open = $this->invoice(null, 1);            // kendala, tidak ada catatan lebih baru: aktif
        $cleared = $this->invoice(null, 2);         // kendala lalu catatan non-kendala lebih baru: tidak aktif
        $sameDay = $this->invoice(null, 3);         // tanggal sama, id lebih besar menang
        $paid = $this->invoice(null, 4, 'PAID');    // kendala tetapi lunas: tidak dihitung
        $this->progressRow($open, '2026-10-20', true);
        $this->progressRow($cleared, '2026-10-20', true);
        $this->progressRow($cleared, '2026-10-22', false, 'BAST');
        $this->progressRow($sameDay, '2026-10-21', false);
        $this->progressRow($sameDay, '2026-10-21', true, 'MEMO');
        $this->progressRow($paid, '2026-10-20', true);
        $cp = Project::factory()->active()->state(['department' => 'CP'])->create();
        $this->progressRow($this->invoice($cp), '2026-10-20', true, 'BAPP', $cp);
        $hidden = Project::factory()->active()->create(); // BM, staf tidak ditugaskan
        $this->progressRow($this->invoice($hidden), '2026-10-20', true, 'BAPP', $hidden);

        $check = function (User $user, int $expected, string $query = '') {
            $count = $this->actingAs($user)->get("/dashboard$query")->assertOk()->viewData('issues');
            $list = $this->get("/dashboard/kendala$query")->assertOk()->viewData('items')->total();
            $this->assertSame([$expected, $expected], [$count, $list]);
        };
        $check($this->staff, 2);
        $check($manager, 3);
        $check($direksi, 4);
        $check($direksi, 3, '?department=BM');
        $check($direksi, 1, '?department=CP');
        $this->get('/dashboard/kendala')->assertSee('BM-2026-102')->assertSee('Termin 1')->assertSee('Catatan 2026-10-20');
    }

    public function test_project_page_shows_stage_issue_history_and_escapes_notes(): void
    {
        $id = $this->invoice();
        $this->record($id, ['description' => '<script>alert(1)</script>', 'stage' => 'MEMO'])->assertSessionDoesntHaveErrors();
        $this->record($id, ['progress_date' => '2026-10-28', 'description' => 'Sudah lengkap', 'stage' => 'INVOICE', 'is_issue' => null]);

        $page = $this->get("/projects/{$this->project->id}")->assertOk();
        $page->assertSee('Tahap dokumen')->assertSee('Invoice dikirim')->assertSee('Riwayat progres (2)')->assertSee('Memo ke Finance/FAC')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertSee('Catat progres');
        $this->assertFalse($page->viewData('invoices')->first()->hasIssue); // kendala dibersihkan catatan terbaru

        $manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->actingAs($manager)->get("/projects/{$this->project->id}")->assertOk()->assertSee('Riwayat progres (2)')->assertDontSee('Catat progres');
    }
}
