<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Verifikasi independen progres dokumen penagihan: batas tanggal WIB, status project, kendala vs dashboard, N+1, penyalahgunaan input.
class BillingProgressQaTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => 'Rina']);
        $this->project = Project::factory()->active()->assignedTo($this->staff)->create(['created_by' => $this->staff->id]);
    }

    private function invoice(Project $project, int $seq = 1, string $status = 'PLANNED'): int
    {
        return DB::table('invoices')->insertGetId(['project_id' => $project->id, 'department' => $project->department->value, 'sequence_number' => $seq, 'billing_type' => 'TERMIN',
            'billing_percentage' => '10.0000', 'planned_amount' => '100.00', 'workflow_status' => $status, 'created_by' => $this->staff->id]);
    }

    private function note(int $invoice, Project $project, string $date, bool $issue, string $text = 'n'): void
    {
        DB::table('project_progress')->insert(['project_id' => $project->id, 'invoice_id' => $invoice, 'entry_type' => 'BILLING', 'stage' => 'BAPP', 'is_issue' => $issue,
            'progress_date' => $date, 'week_key' => '2026-W44', 'description' => $text, 'created_by' => $this->staff->id]);
    }

    private function record(int $invoice, array $extra = [])
    {
        return $this->actingAs($this->staff)->post("/invoices/$invoice/progress", $extra + ['stage' => 'BAST', 'progress_date' => '2026-10-28', 'description' => 'ok']);
    }

    public function test_future_date_boundary_follows_wib_not_utc(): void
    {
        $id = $this->invoice($this->project);

        $this->travelTo('2026-10-27 18:00:00'); // 01:00 WIB tanggal 28
        $this->record($id, ['progress_date' => '2026-10-28'])->assertSessionDoesntHaveErrors();
        $this->record($id, ['progress_date' => '2026-10-29'])->assertSessionHasErrors('progress_date', null, "progress-$id");

        $this->travelTo('2026-10-28 16:59:00'); // 23:59 WIB tanggal 28
        $this->record($id, ['progress_date' => '2026-10-29'])->assertSessionHasErrors('progress_date', null, "progress-$id");
        $this->travelTo('2026-10-28 17:00:00'); // 00:00 WIB tanggal 29
        $this->record($id, ['progress_date' => '2026-10-29'])->assertSessionDoesntHaveErrors();
        $this->assertSame(2, DB::table('project_progress')->count());
    }

    public function test_project_status_gate_and_immutable_history(): void
    {
        $this->travelTo('2026-10-28 03:00:00');
        $id = $this->invoice($this->project);

        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED']);
        $this->record($id)->assertSessionDoesntHaveErrors();
        $this->record($id)->assertSessionDoesntHaveErrors(); // pengiriman ulang = baris baru, bukan menimpa
        $this->assertSame(2, DB::table('project_progress')->count());

        foreach (['SUBMITTED', 'FINANCIALLY_CLOSED', 'ARCHIVED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            $this->record($id)->assertForbidden();
        }
        $this->assertSame(2, DB::table('project_progress')->count());
        $this->assertSame(2, DB::table('audit_logs')->where('entity_type', 'PROGRESS')->count());
        $this->actingAs($this->staff)->put("/invoices/$id/progress")->assertStatus(405);
        $this->delete("/invoices/$id/progress")->assertStatus(405);
    }

    public function test_odd_input_never_500s_or_writes(): void
    {
        $this->travelTo('2026-10-28 03:00:00');
        $id = $this->invoice($this->project);
        foreach ([['stage' => ['BAST']], ['description' => ['x']], ['progress_date' => ['2026-10-27']], ['is_issue' => 'maybe'], ['is_issue' => ['1']], ['progress_date' => '2026-02-30'], ['description' => str_repeat("\u{1F600}", 1001)]] as $bad) {
            $this->record($id, $bad)->assertStatus(302)->assertSessionHasErrors(null, null, "progress-$id");
        }
        $this->assertSame(0, DB::table('project_progress')->count());
        $this->record($id, ['description' => str_repeat("\u{1F600}", 1000), 'is_issue' => '1'])->assertSessionDoesntHaveErrors(); // 1000 karakter multibyte persis
        $this->assertSame(1, (int) DB::table('project_progress')->value('is_issue'));
        $this->post('/invoices/99999999999999999999/progress', [])->assertNotFound();
    }

    public function test_issue_clearing_edge_cases_and_dashboard_list_parity(): void
    {
        $this->travelTo('2026-10-28 03:00:00');
        $manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $a = $this->invoice($this->project, 1);
        $b = $this->invoice($this->project, 2, 'CANCELLED');
        $c = $this->invoice($this->project, 3);
        $this->note($a, $this->project, '2026-10-27', true);
        $this->note($a, $this->project, '2026-10-26', false); // dicatat belakangan tetapi bertanggal lebih lama: tidak menghapus kendala
        $this->note($b, $this->project, '2026-10-27', true);  // CANCELLED tidak dihitung
        $this->note($c, $this->project, '2026-10-27', true);
        $this->note($c, $this->project, '2026-10-27', false); // tanggal sama, id lebih besar menang: kendala hilang

        $this->actingAs($manager);
        $this->assertSame(1, $this->get('/dashboard')->viewData('issues'));
        $this->assertSame(1, $this->get('/dashboard/kendala')->viewData('items')->total());
        // peran terkunci divisi mengabaikan ?department
        $this->assertSame(1, $this->get('/dashboard/kendala?department=CP')->viewData('items')->total());
        $this->get('/dashboard/kendala?department=XX')->assertOk(); // peran terkunci: nilai diabaikan
        $this->actingAs(User::factory()->role('DIREKSI', null)->create())->get('/dashboard/kendala?department=XX')->assertRedirect();
        $this->actingAs($manager);
        $this->get('/dashboard/kendala?page=2')->assertOk()->assertSee('Tidak ada kendala penagihan yang aktif');
    }

    public function test_list_paginates_and_matches_card_over_twenty_rows(): void
    {
        $this->travelTo('2026-10-28 03:00:00');
        $inv = $this->invoice($this->project, 1);
        $ids = [$inv];
        for ($i = 2; $i <= 25; $i++) {
            $ids[] = $this->invoice($this->project, $i);
        }
        foreach ($ids as $id) {
            $this->note($id, $this->project, '2026-10-27', true);
        }
        $this->actingAs($this->staff);
        $this->assertSame(25, $this->get('/dashboard')->viewData('issues'));
        $page = $this->get('/dashboard/kendala')->viewData('items');
        $this->assertSame([25, 20], [$page->total(), $page->count()]);
        $this->assertSame(5, $this->get('/dashboard/kendala?page=2')->viewData('items')->count());
    }

    public function test_project_page_query_count_does_not_grow_with_progress_rows(): void
    {
        $this->travelTo('2026-10-28 03:00:00');
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk();

            return count(DB::getQueryLog());
        };
        $first = $this->invoice($this->project, 1);
        $this->note($first, $this->project, '2026-10-27', true);
        $small = $queries();
        for ($i = 2; $i <= 6; $i++) {
            $id = $this->invoice($this->project, $i);
            $this->note($id, $this->project, '2026-10-27', true);
            $this->note($id, $this->project, '2026-10-28', false);
        }
        $this->assertSame($small, $queries());
    }
}
