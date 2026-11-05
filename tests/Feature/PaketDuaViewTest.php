<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Paket 2, sisi tampilan: isian tanggal di form, filter/penanda terlambat ditagih, kartu dashboard, form ubah tanggal rencana.
class PaketDuaViewTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-20 03:00:00'); // 10:00 WIB
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
    }

    private function invoice(Project $project, int $seq, string $status = 'PLANNED', ?string $planned = null): int
    {
        return DB::table('invoices')->insertGetId([
            'project_id' => $project->id, 'department' => $project->department->value, 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'billing_percentage' => '100.0000',
            'planned_amount' => '100.00', 'workflow_status' => $status, 'planned_billing_date' => $planned, 'created_by' => $this->staff->id,
        ]);
    }

    public function test_project_form_has_a_planned_billing_date_input_per_termin_and_repopulates_it(): void
    {
        $this->actingAs($this->staff)->get('/projects/create')->assertOk()->assertSee('name="planned_billing_dates[]"', false);

        $project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id]);
        $this->invoice($project, 1, 'PLANNED', '2026-12-05');
        $this->get("/projects/{$project->id}/edit")->assertOk()->assertSee('2026-12-05')->assertSee('05/12/2026');

        // Setelah validasi gagal, isian dipulihkan dari old() dan pesan tampil per baris.
        $this->withSession(['_old_input' => ['billing_percentages' => ['50', '50'], 'planned_billing_dates' => ['2026-11-01', '2026-12-01']]])
            ->get("/projects/{$project->id}/edit")->assertSee('2026-11-01')->assertSee('2026-12-01');
    }

    public function test_invoice_submit_form_has_a_required_due_date_input(): void
    {
        $project = Project::factory()->active()->assignedTo($this->staff)->create();
        $this->invoice($project, 1);

        $html = $this->actingAs($this->staff)->get("/projects/{$project->id}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*type="date"[^>]*name="due_date"[^>]*required/', $html);
        $this->assertStringContainsString('Jatuh tempo', $html);
    }

    public function test_invoice_list_shows_late_to_bill_checkbox_and_amber_marker_beside_overdue(): void
    {
        $project = Project::factory()->active()->assignedTo($this->staff)->create();
        $this->invoice($project, 1, 'PLANNED', '2026-10-19'); // lewat
        $this->invoice($project, 2, 'PLANNED', '2026-10-20'); // hari ini, belum terlambat

        $this->actingAs($this->staff)->get('/invoices')->assertOk()->assertSee('name="late_to_bill"', false)->assertSee('name="overdue"', false)->assertSee('Terlambat ditagih');
        $page = $this->get('/invoices?late_to_bill=1')->assertOk();
        $page->assertSee('name="late_to_bill" value="1" checked', false)->assertSee('Atur ulang');
        // Satu termin lewat = satu penanda di kartu ponsel + satu di tabel; termin bertanggal hari ini tidak ditandai.
        $this->assertSame(2, substr_count($page->getContent(), 'text-amber-800">Terlambat ditagih'));
    }

    public function test_dashboard_shows_prospects_card_and_late_to_bill_link(): void
    {
        Project::factory()->state(['status' => 'DRAFT', 'contract_value' => '1000000.00'])->assignedTo($this->staff)->create();
        $active = Project::factory()->active()->assignedTo($this->staff)->create();

        $this->actingAs($this->staff)->get('/dashboard')->assertOk()
            ->assertSee('Calon Kontrak')->assertSee('Rp1.000.000,00 · draf dan menunggu aktivasi')->assertSee('href="'.route('dashboard.projects', 'prospects').'"', false)
            ->assertSee('Tidak ada termin yang lewat tanggal rencana tagih')->assertSee('0 termin terlambat ditagih'); // kartu selalu menaut ke daftar, juga saat 0

        $this->invoice($active, 1, 'PLANNED', '2026-10-01');
        $this->get('/dashboard')->assertOk()->assertSee('1 termin terlambat ditagih')->assertSee('/invoices?late_to_bill=1', false);
    }

    public function test_change_planned_date_form_renders_only_for_allowed_users_and_states(): void
    {
        $active = Project::factory()->active()->assignedTo($this->staff)->create();
        $planned = $this->invoice($active, 1, 'PLANNED', '2026-10-01');
        $cancelled = $this->invoice($active, 2, 'CANCELLED');
        $draft = Project::factory()->assignedTo($this->staff)->create();
        $this->invoice($draft, 1, 'PLANNED', '2026-10-01');

        $this->actingAs($this->staff)->get("/projects/{$active->id}")->assertOk()
            ->assertSee('name="_modal" value="planned-'.$planned.'"', false)->assertSee('name="planned_billing_date"', false)->assertSee('>Ubah tgl rencana</button>', false)
            ->assertSee('Alasan perubahan (wajib)')->assertSee('Terlambat ditagih')
            ->assertDontSee('value="planned-'.$cancelled.'"', false);
        $this->get("/projects/{$draft->id}")->assertOk()->assertDontSee('>Ubah tgl rencana</button>', false);

        // Manager/GM dan Direksi tidak punya hak contribute: tidak ada aksi.
        $manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->actingAs($manager)->get("/projects/{$active->id}")->assertOk()->assertDontSee('>Ubah tgl rencana</button>', false)->assertDontSee('value="planned-'.$planned.'"', false);
        $direksi = User::factory()->role('DIREKSI', null)->create();
        $this->actingAs($direksi)->get("/projects/{$active->id}")->assertOk()->assertDontSee('>Ubah tgl rencana</button>', false);
    }
}
