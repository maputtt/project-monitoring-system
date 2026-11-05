<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// INV-05 (UX layar 12): daftar invoice semua project yang terlihat, read-only, lewat Project::visibleTo.
class InvoiceListTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-16');
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
    }

    private function invoice(Project $project, int $seq, string $number, string $status = 'INVOICE_SUBMITTED', ?string $due = '2026-11-01', string $net = '108.00'): void
    {
        DB::table('invoices')->insert([
            'project_id' => $project->id, 'department' => $project->department->value, 'sequence_number' => $seq, 'billing_type' => 'TERMIN',
            'planned_amount' => '111.00', 'workflow_status' => $status, 'created_by' => $this->staff->id, 'due_date' => $due,
            'invoice_number' => $number, 'billing_date' => '2026-09-01', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500',
            'subtotal_amount' => '111.00', 'net_received_amount' => $net,
            'locked_at' => $status === 'PAID' ? now() : null, 'locked_by' => $status === 'PAID' ? $this->manager->id : null,
        ]);
    }

    public function test_each_role_sees_only_invoices_of_visible_projects(): void
    {
        $mine = Project::factory()->assignedTo($this->staff)->create(['project_code' => 'MINE-1']);
        $unassigned = Project::factory()->create(['project_code' => 'BM-OTHER']);
        $cp = Project::factory()->create(['project_code' => 'CP-1', 'department' => 'CP']);
        $this->invoice($mine, 1, 'INV/MINE');
        $this->invoice($unassigned, 1, 'INV/UNASSIGNED');
        $this->invoice($cp, 1, 'INV/CPSECRET', net: '987654.32');

        $this->actingAs($this->staff)->get('/invoices')->assertOk()->assertSee('INV/MINE')->assertSee('MINE-1')
            ->assertDontSee('INV/UNASSIGNED')->assertDontSee('INV/CPSECRET')->assertDontSee('987.654,32');
        $this->actingAs($this->manager)->get('/invoices')->assertSee('INV/MINE')->assertSee('INV/UNASSIGNED')
            ->assertDontSee('INV/CPSECRET')->assertDontSee('987.654,32');
        $this->actingAs(User::factory()->role('DIREKSI', null)->create())->get('/invoices')->assertSee('INV/UNASSIGNED')->assertSee('INV/CPSECRET')->assertSee('Rp987.654,32');
    }

    public function test_closed_to_system_admin_and_guests(): void
    {
        $this->get('/invoices')->assertRedirect('/login');
        $this->actingAs(User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create())->get('/invoices')->assertForbidden();
    }

    public function test_status_and_due_date_filters(): void
    {
        $p = Project::factory()->assignedTo($this->staff)->create();
        $this->invoice($p, 1, 'INV/LATE', 'WAITING_FOR_PAYMENT', '2026-10-01');
        $this->invoice($p, 2, 'INV/PAIDLATE', 'PAID', '2026-10-01');
        $this->invoice($p, 3, 'INV/FUTURE', 'WAITING_FOR_PAYMENT', '2026-12-01');
        $this->invoice($p, 4, 'INV/NODUE', 'INVOICE_SUBMITTED', null);
        $this->actingAs($this->staff);

        $this->get('/invoices?status=PAID')->assertSee('INV/PAIDLATE')->assertDontSee('INV/LATE')->assertDontSee('INV/FUTURE');
        $this->get('/invoices?overdue=1')->assertSee('INV/LATE')->assertDontSee('INV/PAIDLATE')->assertDontSee('INV/FUTURE')->assertDontSee('INV/NODUE');
        $this->get('/invoices?due_from=2026-11-01&due_to=2026-12-31')->assertSee('INV/FUTURE')->assertDontSee('INV/LATE')->assertDontSee('INV/NODUE');
        $this->get('/invoices?status=PAID&overdue=1&due_to=2026-09-01')->assertSee('Tidak ada invoice yang cocok');
        $this->get('/invoices?status=BUKAN')->assertSessionHasErrors('status');
        $this->get('/invoices?due_from=2026-12-01&due_to=2026-11-01')->assertSessionHasErrors('due_to');
    }

    public function test_pagination_keeps_filters_and_empty_state(): void
    {
        $this->actingAs($this->staff)->get('/invoices')->assertSee('Belum ada invoice');
        $p = Project::factory()->assignedTo($this->staff)->create();
        foreach (range(1, 21) as $i) {
            $this->invoice($p, $i, sprintf('INV/%02d', $i), 'WAITING_FOR_PAYMENT');
        }
        $this->get('/invoices?status=WAITING_FOR_PAYMENT')->assertSee('INV/20')->assertDontSee('INV/21')->assertSee('status=WAITING_FOR_PAYMENT', false)
            ->assertSee('page=2', false);
        $this->get('/invoices?status=WAITING_FOR_PAYMENT&page=2')->assertSee('INV/21')->assertDontSee('INV/01');
    }
}
