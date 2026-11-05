<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// QA independen Paket 2: celah di luar PaketDuaServerTest/PaketDuaViewTest (input hostile, data lama tanpa due_date, XSS, banner aktivasi, ruang lingkup tautan dashboard).
class PaketDuaQaTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-20 03:00:00'); // 10:00 WIB
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
    }

    private function invoice(Project $project, int $seq, string $status = 'PLANNED', ?string $planned = null, array $extra = []): int
    {
        $billed = $status === 'PLANNED' ? [] : [
            'invoice_number' => "INV/{$project->id}/$seq", 'billing_date' => '2026-09-01', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500',
            'dpp_amount' => '100.00', 'ppn_amount' => '11.00', 'subtotal_amount' => '111.00', 'pph_amount' => '3.00', 'net_received_amount' => '108.00',
            'submitted_by' => $this->staff->id, 'submitted_at' => now(),
        ];

        return DB::table('invoices')->insertGetId([
            'project_id' => $project->id, 'department' => $project->department->value, 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'billing_percentage' => '10.0000',
            'planned_amount' => '100.00', 'workflow_status' => $status, 'planned_billing_date' => $planned, 'created_by' => $this->staff->id,
        ] + $billed + $extra);
    }

    public function test_existing_billed_invoice_without_due_date_still_renders_and_is_never_flagged(): void
    {
        $project = Project::factory()->active()->assignedTo($this->staff)->create();
        $waiting = $this->invoice($project, 1, 'WAITING_FOR_PAYMENT'); // data demo lama: due_date dan planned_billing_date NULL
        $this->invoice($project, 2, 'INVOICE_SUBMITTED');

        $page = $this->actingAs($this->staff)->get("/projects/{$project->id}")->assertOk();
        $page->assertDontSee('Terlambat ditagih')->assertSee('Unggah bukti bayar', false);
        foreach (['/invoices', '/invoices?overdue=1', '/invoices?late_to_bill=1', '/dashboard'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->assertSame(0, $this->get('/invoices?overdue=1')->viewData('invoices')->total());
        $this->assertSame(0, $this->get('/dashboard')->viewData('late_to_bill'));
        $this->assertNull(DB::table('invoices')->find($waiting)->due_date);
    }

    public function test_hostile_array_inputs_on_new_fields_do_not_crash_the_rerendered_page(): void
    {
        $project = Project::factory()->active()->assignedTo($this->staff)->create();
        $planned = $this->invoice($project, 1, 'PLANNED', '2026-10-01');
        $before = DB::table('invoices')->find($planned);

        // Pengajuan invoice (due_date dan invoice_number berupa array) dan ubah tanggal (tanggal dan alasan berupa array): validasi menolak, halaman dirender ulang dengan old() tanpa 500.
        $this->actingAs($this->staff)->from("/projects/{$project->id}")->post("/invoices/$planned/submit", [
            'version' => 1, 'invoice_number' => ['X-1'], 'billing_date' => '2026-10-10', 'due_date' => ['2026-11-01'], 'pph_qualification' => 'A',
        ])->assertSessionHasErrorsIn("invoice-$planned", ['invoice_number', 'due_date']);
        $this->get("/projects/{$project->id}")->assertOk();
        $this->from("/projects/{$project->id}")->post("/invoices/$planned/planned-billing-date", ['version' => 1, 'planned_billing_date' => ['2026-11-01'], 'reason' => ['x']])
            ->assertSessionHasErrorsIn("planned-date-$planned", ['planned_billing_date', 'reason']);
        $this->get("/projects/{$project->id}")->assertOk();

        $this->assertEquals($before, DB::table('invoices')->find($planned));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_reason_is_escaped_when_the_form_is_redisplayed_and_in_the_audit_row(): void
    {
        $project = Project::factory()->active()->assignedTo($this->staff)->create();
        $id = $this->invoice($project, 1, 'PLANNED', '2026-10-01');
        $xss = '<script>alert(1)</script>';

        // Gagal (tanggal salah) setelah alasan diisi: alasan tampil kembali di textarea, harus ter-escape.
        $this->actingAs($this->staff)->followingRedirects()->from("/projects/{$project->id}")->post("/invoices/$id/planned-billing-date", ['version' => 1, 'planned_billing_date' => '2026-02-31', 'reason' => $xss])
            ->assertOk()->assertDontSee($xss, false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertSee('Tanggal rencana penagihan tidak valid.');

        // Berhasil: tersimpan apa adanya di audit, dan halaman audit meng-escape.
        $this->post("/invoices/$id/planned-billing-date", ['version' => 1, 'planned_billing_date' => '2026-11-01', 'reason' => $xss])->assertSessionHasNoErrors();
        $this->assertSame($xss, DB::table('audit_logs')->where('entity_type', 'INVOICE')->sole()->reason);
        $this->actingAs($this->manager)->get('/audit-logs')->assertOk()->assertDontSee($xss, false);
    }

    public function test_planned_date_change_leaves_project_money_and_status_untouched_and_blocks_after_submit(): void
    {
        $project = Project::factory()->active()->assignedTo($this->staff)->create();
        $id = $this->invoice($project, 1, 'PLANNED', '2026-12-01');
        $projectBefore = DB::table('projects')->find($project->id);

        $this->actingAs($this->staff)->post("/invoices/$id/planned-billing-date", ['version' => 1, 'planned_billing_date' => '2027-01-15'])->assertSessionHasNoErrors();
        $this->assertEquals($projectBefore, DB::table('projects')->find($project->id));
        $this->assertSame('100.00', DB::table('invoices')->find($id)->planned_amount);

        // Setelah invoice diajukan (bukan PLANNED lagi), tanggal rencana beku: 409 dan tidak berubah.
        DB::table('invoices')->where('id', $id)->update(['workflow_status' => 'INVOICE_SUBMITTED', 'invoice_number' => 'INV/1', 'billing_date' => '2026-10-10', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500', 'dpp_amount' => '100.00', 'ppn_amount' => '11.00', 'subtotal_amount' => '111.00', 'pph_amount' => '3.00', 'net_received_amount' => '108.00', 'submitted_by' => $this->staff->id, 'submitted_at' => now()]);
        $this->post("/invoices/$id/planned-billing-date", ['version' => 2, 'planned_billing_date' => '2027-02-01'])->assertStatus(409);
        $this->assertSame('2027-01-15', DB::table('invoices')->find($id)->planned_billing_date);
    }

    public function test_activation_rejection_message_reaches_the_user_and_nothing_is_half_applied(): void
    {
        $project = Project::factory()->state(['status' => 'SUBMITTED', 'effective_rab' => '500.00'])->assignedTo($this->staff)->create();
        $op = DB::table('bq_items')->insertGetId([
            'project_id' => $project->id, 'item_name' => 'Operasional', 'item_type' => 'OPERATIONAL', 'status' => 'DRAFT', 'created_by' => $this->staff->id,
            'baseline_budget' => '500.01', 'effective_budget' => '500.01', 'negotiated_value' => '0.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000',
        ]);

        $this->actingAs($this->manager)->from("/projects/{$project->id}")->followingRedirects()->post("/projects/{$project->id}/activate")
            ->assertOk()->assertSee('Sisa RAB tidak cukup: total Nilai RAB semua item BQ');
        $this->assertSame('SUBMITTED', DB::table('projects')->find($project->id)->status);
        $this->assertSame('DRAFT', DB::table('bq_items')->find($op)->status);
        $this->assertSame(0, DB::table('audit_logs')->where('entity_type', 'PROJECT')->where('action', 'ACTIVATE')->count());
    }

    public function test_draft_update_ignores_surplus_dates_and_keeps_dates_aligned_when_a_middle_termin_is_removed(): void
    {
        $project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'DRAFT']);
        // Tiga termin 30/30/40 dengan tanggal berbeda; peta isian sama seperti form (sejajar per indeks).
        foreach ([['30.0000', '2026-11-01'], ['30.0000', '2026-11-02'], ['40.0000', '2026-11-03']] as $i => [$pct, $date]) {
            $this->invoice($project, $i + 1, 'PLANNED', $date, ['billing_percentage' => $pct]);
        }
        $form = $this->actingAs($this->staff)->get("/projects/{$project->id}/edit")->assertOk()->getContent();
        foreach (['2026-11-01', '2026-11-02', '2026-11-03'] as $date) {
            $this->assertStringContainsString($date, $form);
        }

        $p = DB::table('projects')->find($project->id);
        DB::table('pks')->where('id', $p->pks_id)->update(['status' => 'ACTIVE']);
        $payload = [
            'version' => $p->version, 'pks_id' => $p->pks_id, 'bouwheer_contact_id' => $p->bouwheer_contact_id, 'project_code' => $p->project_code, 'project_name' => $p->project_name,
            'location' => $p->location, 'spk_number' => $p->spk_number, 'spk_date' => substr($p->spk_date, 0, 10), 'contract_value' => $p->contract_value, 'contract_tax_mode' => $p->contract_tax_mode,
            'margin_mode' => 'PCT', 'margin_value' => '10', 'payment_type' => 'TERMIN', 'start_date' => substr($p->start_date, 0, 10), 'end_date' => substr($p->end_date, 0, 10),
            'billing_percentages' => ['30', '70'], 'planned_billing_dates' => ['2026-11-01', '2026-11-03', '2030-01-01', '2030-01-02'], // klien menghapus baris tengah; sisa tanggal berlebih
        ];
        $this->put("/projects/{$project->id}", $payload)->assertSessionHasNoErrors();
        $rows = DB::table('invoices')->where('project_id', $project->id)->orderBy('sequence_number')->get(['sequence_number', 'billing_percentage', 'planned_billing_date']);
        $this->assertSame([[1, '30.0000', '2026-11-01'], [2, '70.0000', '2026-11-03']], $rows->map(fn ($r) => [(int) $r->sequence_number, $r->billing_percentage, $r->planned_billing_date])->all());
    }

    /** D-P2-1 (Low): kartu dashboard Direksi dengan ?department= menghitung satu divisi, tetapi tautannya membuka daftar semua divisi. */
    public function test_dashboard_late_card_link_keeps_the_department_filter(): void
    {
        $direksi = User::factory()->role('DIREKSI', null)->create();
        foreach (['BM', 'CP', 'CP'] as $i => $dept) {
            $project = Project::factory()->active()->create(['department' => $dept]);
            $this->invoice($project, 1, 'PLANNED', '2026-10-01');
        }
        $this->actingAs($direksi);

        $dashboard = $this->get('/dashboard?department=CP');
        $this->assertSame(2, $dashboard->viewData('late_to_bill'));
        $href = html_entity_decode($dashboard->assertSee('late_to_bill=1&amp;department=CP', false)->getContent());
        $this->assertStringContainsString('/invoices?late_to_bill=1&department=CP', $href);
        $this->assertSame(2, $this->get('/invoices?late_to_bill=1&department=CP')->viewData('invoices')->total());
        $this->assertSame(3, $this->get('/invoices?late_to_bill=1')->viewData('invoices')->total());
        $this->get('/invoices?department=XX')->assertSessionHasErrors('department');

        // Peran terkunci divisi tidak bisa melebarkan lingkup lewat ?department=.
        $project = Project::factory()->active()->assignedTo($this->staff)->create();
        $this->invoice($project, 1, 'PLANNED', '2026-10-01');
        $this->actingAs($this->staff);
        $this->assertSame(1, $this->get('/invoices?late_to_bill=1&department=CP')->viewData('invoices')->total());
        $this->get('/invoices')->assertDontSee('Semua divisi');
    }
}
