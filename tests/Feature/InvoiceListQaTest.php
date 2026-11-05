<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// INV-05 QA independen: kebocoran lintas-divisi/assignment, batas overdue, validasi filter, urutan, XSS, read-only.
class InvoiceListQaTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-16 23:30:00');
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
    }

    private function invoice(Project $project, int $seq, string $number, string $status = 'INVOICE_SUBMITTED', ?string $due = '2026-11-01', ?string $subtotal = '111.00', ?string $net = '108.00'): int
    {
        return DB::table('invoices')->insertGetId([
            'project_id' => $project->id, 'department' => $project->department->value, 'sequence_number' => $seq, 'billing_type' => 'TERMIN',
            'planned_amount' => '111.00', 'billing_percentage' => '100.0000', 'workflow_status' => $status, 'created_by' => $this->staff->id, 'due_date' => $due,
            'invoice_number' => $number, 'billing_date' => '2026-09-01', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500', 'subtotal_amount' => $subtotal, 'net_received_amount' => $net,
            'locked_at' => $status === 'PAID' ? now() : null, 'locked_by' => $status === 'PAID' ? $this->manager->id : null,
        ]);
    }

    public function test_no_leak_of_other_division_unassigned_or_deactivated_projects(): void
    {
        $mine = Project::factory()->assignedTo($this->staff)->create(['project_name' => 'ZZ-MINE-NAME']);
        $deactivated = Project::factory()->assignedTo($this->staff, false)->create(['project_name' => 'ZZ-DEACT-NAME']);
        $unassigned = Project::factory()->create(['project_name' => 'ZZ-UNASSIGNED-NAME']);
        $cp = Project::factory()->create(['department' => 'CP', 'project_name' => 'ZZ-CP-NAME']);
        $cpAssignedToBmStaff = Project::factory()->assignedTo($this->staff)->create(['department' => 'CP', 'project_name' => 'ZZ-CPASSIGNED-NAME']);
        $this->invoice($mine, 1, 'INV/MINE', net: '1111.11');
        $this->invoice($deactivated, 1, 'INV/DEACT', net: '2222.22');
        $this->invoice($unassigned, 1, 'INV/UNASS', net: '3333.33');
        $this->invoice($cp, 1, 'INV/CPONLY', net: '4444.44');
        $this->invoice($cpAssignedToBmStaff, 1, 'INV/CPASSIGNED', net: '5555.55');

        // Query string tidak boleh membuka apa pun: filter hanya mempersempit.
        foreach (['/invoices', '/invoices?overdue=0', '/invoices?status=INVOICE_SUBMITTED', '/invoices?due_from=2000-01-01&due_to=2999-12-31', '/invoices?project_id='.$cp->id, '/invoices?department=CP'] as $url) {
            $this->actingAs($this->staff)->get($url)->assertOk()->assertSee('INV/MINE')->assertSee('ZZ-MINE-NAME')
                ->assertDontSee('INV/DEACT')->assertDontSee('ZZ-DEACT-NAME')->assertDontSee('2.222,22')
                ->assertDontSee('INV/UNASS')->assertDontSee('ZZ-UNASSIGNED-NAME')->assertDontSee('3.333,33')
                ->assertDontSee('INV/CPONLY')->assertDontSee('ZZ-CP-NAME')->assertDontSee('4.444,44')
                ->assertDontSee('INV/CPASSIGNED')->assertDontSee('ZZ-CPASSIGNED-NAME')->assertDontSee('5.555,55');
        }
        foreach (['/invoices', '/invoices?department=CP'] as $url) {
            $this->actingAs($this->manager)->get($url)->assertOk()->assertSee('INV/MINE')->assertSee('INV/DEACT')->assertSee('INV/UNASS')
                ->assertDontSee('INV/CPONLY')->assertDontSee('ZZ-CP-NAME')->assertDontSee('4.444,44')
                ->assertDontSee('INV/CPASSIGNED')->assertDontSee('ZZ-CPASSIGNED-NAME')->assertDontSee('5.555,55');
        }
        // Manager divisi CP melihat CP saja.
        $this->actingAs(User::factory()->role('MANAGER_GM', 'CP')->create())->get('/invoices')
            ->assertSee('INV/CPONLY')->assertSee('INV/CPASSIGNED')->assertDontSee('INV/MINE')->assertDontSee('ZZ-MINE-NAME')->assertDontSee('1.111,11');
    }

    public function test_assignment_deactivated_after_the_fact_removes_the_invoices(): void
    {
        $p = Project::factory()->assignedTo($this->staff)->create();
        $this->invoice($p, 1, 'INV/WAS-MINE');
        $this->actingAs($this->staff)->get('/invoices')->assertSee('INV/WAS-MINE');
        DB::table('project_user_assignments')->where('project_id', $p->id)->update(['is_active' => false]);
        $this->get('/invoices')->assertOk()->assertDontSee('INV/WAS-MINE')->assertSee('Belum ada invoice');
    }

    public function test_overdue_boundary_is_exact(): void
    {
        // setUp: 2026-10-16 23:30 UTC = 17 Okt 06:30 WIB, jadi "hari ini" bisnis adalah 17 Okt.
        $p = Project::factory()->assignedTo($this->staff)->create();
        $this->invoice($p, 1, 'INV/YESTERDAY', 'WAITING_FOR_PAYMENT', '2026-10-16');
        $this->invoice($p, 2, 'INV/TODAY', 'WAITING_FOR_PAYMENT', '2026-10-17');
        $this->invoice($p, 3, 'INV/TOMORROW', 'WAITING_FOR_PAYMENT', '2026-10-18');
        $this->invoice($p, 4, 'INV/PAIDPAST', 'PAID', '2026-01-01');
        $this->invoice($p, 5, 'INV/CANCELPAST', 'CANCELLED', '2026-01-01');
        $this->invoice($p, 6, 'INV/PLANNEDPAST', 'PLANNED', '2026-01-01');
        $this->invoice($p, 7, 'INV/NODUE', 'INVOICE_SUBMITTED', null);
        $this->invoice($p, 8, 'INV/PAYREVPAST', 'PAYMENT_REVISION_REQUIRED', '2026-10-01');

        $res = $this->actingAs($this->staff)->get('/invoices?overdue=1')->assertOk();
        $res->assertSee('INV/YESTERDAY')->assertSee('INV/PAYREVPAST')->assertDontSee('INV/PLANNEDPAST') // 1A: PLANNED belum ditagih, tidak pernah lewat jatuh tempo

            ->assertDontSee('INV/TODAY')->assertDontSee('INV/TOMORROW')->assertDontSee('INV/PAIDPAST')->assertDontSee('INV/CANCELPAST')->assertDontSee('INV/NODUE');

        // Penanda di baris tanpa filter harus cocok dengan himpunan filter: 2 baris x (kartu + tabel) + 1 label filter.
        $html = $this->get('/invoices')->getContent();
        $this->assertSame(1 + 2 * 2, substr_count($html, 'Lewat jatuh tempo'));
    }

    public function test_date_range_is_inclusive_and_single_ended(): void
    {
        $p = Project::factory()->assignedTo($this->staff)->create();
        foreach (['2026-10-09' => 'A', '2026-10-10' => 'B', '2026-10-20' => 'C', '2026-10-21' => 'D'] as $due => $tag) {
            $this->invoice($p, (int) substr($due, 8), "INV/R-$tag", 'WAITING_FOR_PAYMENT', $due);
        }
        $this->actingAs($this->staff);
        $this->get('/invoices?due_from=2026-10-10&due_to=2026-10-20')->assertDontSee('INV/R-A')->assertSee('INV/R-B')->assertSee('INV/R-C')->assertDontSee('INV/R-D');
        $this->get('/invoices?due_from=2026-10-20&due_to=2026-10-20')->assertDontSee('INV/R-B')->assertSee('INV/R-C')->assertDontSee('INV/R-D');
        $this->get('/invoices?due_from=2026-10-20')->assertDontSee('INV/R-B')->assertSee('INV/R-C')->assertSee('INV/R-D');
        $this->get('/invoices?due_to=2026-10-10')->assertSee('INV/R-A')->assertSee('INV/R-B')->assertDontSee('INV/R-C');
    }

    public function test_invalid_filters_give_validation_errors_not_500(): void
    {
        $this->actingAs($this->staff);
        $this->get('/invoices?status[]=PAID')->assertSessionHasErrors('status');
        $this->get('/invoices?status=paid')->assertSessionHasErrors('status');
        $this->get('/invoices?due_from=2026-02-30')->assertSessionHasErrors('due_from');
        $this->get('/invoices?due_from=16/10/2026')->assertSessionHasErrors('due_from');
        $this->get('/invoices?due_to=abc')->assertSessionHasErrors('due_to');
        $this->get('/invoices?due_from[]=2026-10-10')->assertSessionHasErrors('due_from');
        $this->get('/invoices?overdue=banana')->assertSessionHasErrors('overdue');
        $this->get('/invoices?overdue[]=1')->assertSessionHasErrors('overdue');
        $this->get('/invoices?page=abc')->assertOk();
        $this->get('/invoices?page=-3')->assertOk();
        $this->get('/invoices?page=99999999999999999999')->assertOk();
        // SYSTEM_ADMIN: 403 sebelum validasi apa pun.
        $this->actingAs(User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create())->get('/invoices?status=BUKAN')->assertForbidden();
    }

    public function test_repeating_a_bad_filter_url_does_not_redirect_to_itself(): void
    {
        $this->actingAs($this->staff);
        $this->get('/invoices?status=ZZZ');
        $this->get('/invoices?status=ZZZ')->assertRedirect(url('/invoices'))->assertSessionHasErrors('status');
    }

    public function test_sort_order_nulls_last_then_id_across_projects(): void
    {
        $a = Project::factory()->assignedTo($this->staff)->create();
        $b = Project::factory()->assignedTo($this->staff)->create();
        $this->invoice($a, 1, 'INV/S-NULL1', 'PLANNED', null);
        $this->invoice($b, 1, 'INV/S-LATE', 'PLANNED', '2026-12-01');
        $this->invoice($a, 2, 'INV/S-TIE1', 'PLANNED', '2026-11-01');
        $this->invoice($b, 2, 'INV/S-TIE2', 'PLANNED', '2026-11-01');
        $this->invoice($b, 3, 'INV/S-EARLY', 'PLANNED', '2026-01-01');
        $this->invoice($b, 4, 'INV/S-NULL2', 'PLANNED', null);

        $html = $this->actingAs($this->staff)->get('/invoices')->getContent();
        $order = ['INV/S-EARLY', 'INV/S-TIE1', 'INV/S-TIE2', 'INV/S-LATE', 'INV/S-NULL1', 'INV/S-NULL2'];
        $pos = array_map(fn ($n) => strpos($html, $n), $order);
        $sorted = $pos;
        sort($sorted);
        $this->assertSame($sorted, $pos);
        $this->assertNotContains(false, $pos);
    }

    public function test_pagination_total_and_second_page_keep_filters_without_duplicates(): void
    {
        $p = Project::factory()->assignedTo($this->staff)->create();
        foreach (range(1, 45) as $i) {
            $this->invoice($p, $i, sprintf('INV/P%02d-X', $i), 'WAITING_FOR_PAYMENT', '2026-11-01');
        }
        $this->invoice($p, 46, 'INV/P46-PAID', 'PAID', '2026-11-01');
        $this->actingAs($this->staff);
        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $html = $this->get("/invoices?status=WAITING_FOR_PAYMENT&page=$page")->assertOk()->getContent();
            preg_match_all('/INV\/P\d\d-X/', $html, $m);
            $this->assertCount($page < 3 ? 20 : 5, array_unique($m[0]));
            $seen = array_merge($seen, array_unique($m[0]));
            $this->assertStringNotContainsString('INV/P46-PAID', $html);
        }
        $this->assertCount(45, array_unique($seen));
        $this->get('/invoices?status=WAITING_FOR_PAYMENT&page=9')->assertOk();
    }

    public function test_html_is_escaped_and_nullable_values_render(): void
    {
        $p = Project::factory()->assignedTo($this->staff)->create(['project_name' => '<script>alert("pn")</script>']);
        $this->invoice($p, 1, '"><img src=x onerror=alert(1)>', 'PLANNED', null, null, null);

        $res = $this->actingAs($this->staff)->get('/invoices')->assertOk();
        $res->assertDontSee('<script>alert("pn")</script>', false)->assertDontSee('<img src=x', false)
            ->assertSee('&lt;script&gt;alert(&quot;pn&quot;)&lt;/script&gt;', false);
    }

    public function test_page_is_read_only_and_rows_link_to_project_schedule(): void
    {
        $p = Project::factory()->assignedTo($this->staff)->create();
        $id = $this->invoice($p, 1, 'INV/RO');
        $res = $this->actingAs($this->staff)->get('/invoices')->assertOk()->assertSee('/projects/'.$p->id.'#jadwal', false);
        $this->assertStringNotContainsString('name="_method"', $res->getContent());
        $this->assertStringNotContainsString('/invoices/'.$id.'/', $res->getContent());
        $this->post('/invoices')->assertStatus(405);
        $this->put('/invoices')->assertStatus(405);
        $this->delete('/invoices')->assertStatus(405);
        $this->get('/projects/'.$p->id)->assertOk();
    }

    public function test_money_and_dates_are_formatted(): void
    {
        $p = Project::factory()->assignedTo($this->staff)->create();
        $this->invoice($p, 1, 'INV/FMT', 'WAITING_FOR_PAYMENT', '2026-03-05', '1234567.895', '999.995');
        $this->actingAs($this->staff)->get('/invoices')->assertSee('05/03/2026')->assertDontSee('2026-03-05')->assertSee('Rp1.234.567,90')->assertSee('Rp1.000,00');
    }
}
