<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Paket 2, sisi server: tanggal rencana penagihan, terlambat ditagih, calon kontrak (dashboard), guard satu RAB.
class PaketDuaServerTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
    }

    private function invoice(Project $project, int $seq, string $status = 'PLANNED', ?string $planned = null): int
    {
        $billed = $status === 'PLANNED' ? [] : [
            'invoice_number' => "INV/{$project->id}/$seq", 'billing_date' => '2026-09-01', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500', 'subtotal_amount' => '111.00', 'net_received_amount' => '108.00',
            'locked_at' => $status === 'PAID' ? now() : null, 'locked_by' => $status === 'PAID' ? $this->manager->id : null,
        ];

        return DB::table('invoices')->insertGetId([
            'project_id' => $project->id, 'department' => $project->department->value, 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'billing_percentage' => '10.0000',
            'planned_amount' => '100.00', 'workflow_status' => $status, 'planned_billing_date' => $planned, 'created_by' => $this->staff->id,
        ] + $billed);
    }

    private function bq(Project $project, string $budget, string $status = 'APPROVED', string $type = 'NORMAL'): int
    {
        return DB::table('bq_items')->insertGetId([
            'project_id' => $project->id, 'item_name' => "Item $status $budget", 'item_type' => $type, 'status' => $status, 'created_by' => $this->staff->id,
            'baseline_budget' => $budget, 'effective_budget' => $budget, 'negotiated_value' => '0.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000',
        ]);
    }

    // ---- P2-A0: ubah tanggal rencana penagihan ----

    private function plannedProject(): array
    {
        $project = Project::factory()->active()->assignedTo($this->staff)->create(['created_by' => $this->staff->id]);

        return [$project, $this->invoice($project, 1, 'PLANNED', '2026-10-10')];
    }

    private function changeDate(int $id, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->staff)->post("/invoices/$id/planned-billing-date", $extra + ['planned_billing_date' => '2026-11-15', 'version' => 1]);
    }

    public function test_past_planned_date_needs_a_reason_and_the_change_is_audited(): void
    {
        $this->travelTo('2026-10-20 03:00:00'); // 10:00 WIB
        [$project, $id] = $this->plannedProject();

        // Tanggal lama 10-10 sudah lewat: tanpa alasan ditolak, tidak ada jejak.
        $this->changeDate($id)->assertSessionHasErrors('reason', null, "planned-date-$id");
        $this->changeDate($id, ['reason' => '   '])->assertSessionHasErrors('reason', null, "planned-date-$id");
        $this->assertSame(['2026-10-10', 1, 0], [DB::table('invoices')->find($id)->planned_billing_date, (int) DB::table('invoices')->find($id)->version, DB::table('audit_logs')->count()]);

        $this->changeDate($id, ['reason' => 'Menunggu BAST dari bouwheer'])->assertRedirect("/projects/{$project->id}")->assertSessionHasNoErrors();
        $row = DB::table('invoices')->find($id);
        $this->assertSame(['2026-11-15', 2, 'PLANNED'], [$row->planned_billing_date, (int) $row->version, $row->workflow_status]);
        $audit = DB::table('audit_logs')->where('entity_type', 'INVOICE')->sole();
        $this->assertSame(['UPDATE', $id, $this->staff->id, 'Menunggu BAST dari bouwheer'], [$audit->action, (int) $audit->entity_id, (int) $audit->user_id, $audit->reason]);
        $this->assertSame(['2026-10-10', '2026-11-15'], [json_decode($audit->data_before)->planned_billing_date, json_decode($audit->data_after)->planned_billing_date]);
    }

    public function test_reason_not_needed_for_null_or_future_current_date_and_boundary_is_wib_today(): void
    {
        $this->travelTo('2026-10-20 18:00:00'); // 01:00 WIB tanggal 21
        [$project] = $this->plannedProject();
        $null = $this->invoice($project, 2);
        $today = $this->invoice($project, 3, 'PLANNED', '2026-10-21'); // hari ini (WIB) belum lewat
        $yesterday = $this->invoice($project, 4, 'PLANNED', '2026-10-20'); // kemarin menurut WIB: lewat

        $this->changeDate($null)->assertSessionHasNoErrors();
        $this->changeDate($today)->assertSessionHasNoErrors();
        $this->changeDate($yesterday)->assertSessionHasErrors('reason', null, "planned-date-$yesterday");
        $this->assertSame('2026-11-15', DB::table('invoices')->find($null)->planned_billing_date);
        $this->assertSame('2026-10-20', DB::table('invoices')->find($yesterday)->planned_billing_date);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'UPDATE')->count());
    }

    public function test_planned_date_change_rejects_stale_version_wrong_status_project_status_roles_scope_and_bad_dates(): void
    {
        $this->travelTo('2026-10-20 03:00:00');
        [$project, $id] = $this->plannedProject();

        $this->actingAs($this->staff)->postJson("/invoices/$id/planned-billing-date", ['planned_billing_date' => '2026-11-15', 'version' => 5, 'reason' => 'x'])->assertStatus(409);
        foreach (['2026-02-31', '15/11/2026', '1999-12-31', '2100-01-01'] as $bad) {
            $this->changeDate($id, ['planned_billing_date' => $bad, 'reason' => 'x'])->assertSessionHasErrors('planned_billing_date', null, "planned-date-$id");
        }
        $this->changeDate($id, ['planned_billing_date' => null, 'reason' => 'x'])->assertSessionHasErrors('planned_billing_date', null, "planned-date-$id");

        $this->changeDate($id, ['reason' => 'x'], $this->manager)->assertForbidden();
        $this->changeDate($id, ['reason' => 'x'], User::factory()->role('DIREKSI', null)->create())->assertForbidden();
        $this->changeDate($id, ['reason' => 'x'], User::factory()->role('STAFF_PIC', 'CP')->create())->assertNotFound();
        $this->changeDate($id, ['reason' => 'x'], User::factory()->role('STAFF_PIC', 'BM')->create())->assertNotFound(); // tidak ditugaskan

        $submitted = $this->invoice($project, 2, 'INVOICE_SUBMITTED', '2026-10-01');
        $this->changeDate($submitted, ['reason' => 'x'])->assertStatus(409);
        DB::table('projects')->where('id', $project->id)->update(['status' => 'FINANCIALLY_CLOSED']);
        $this->changeDate($id, ['reason' => 'x'])->assertForbidden();
        DB::table('projects')->where('id', $project->id)->update(['status' => 'COMPLETED']);
        $this->changeDate($id, ['reason' => 'x'])->assertSessionHasNoErrors(); // COMPLETED boleh

        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    // ---- P2-A: terlambat ditagih ----

    public function test_late_to_bill_matrix_at_the_wib_midnight_boundary(): void
    {
        $this->travelTo('2026-10-20 18:00:00'); // 2026-10-21 01:00 WIB
        $active = Project::factory()->active()->assignedTo($this->staff)->create();
        $late = $this->invoice($active, 1, 'PLANNED', '2026-10-20');
        $this->invoice($active, 2, 'PLANNED', '2026-10-21'); // hari ini (WIB): belum terlambat
        $this->invoice($active, 3, 'PLANNED', '2026-10-22');
        $this->invoice($active, 4); // tanpa tanggal rencana
        foreach (['INVOICE_SUBMITTED', 'INVOICE_REVISION_REQUIRED', 'INVOICE_VERIFIED', 'WAITING_FOR_PAYMENT', 'PAYMENT_SUBMITTED', 'PAYMENT_REVISION_REQUIRED', 'PAID', 'CANCELLED'] as $i => $status) {
            $this->invoice($active, 10 + $i, $status, '2026-10-01');
        }
        $completed = Project::factory()->state(['status' => 'COMPLETED'])->assignedTo($this->staff)->create();
        $lateCompleted = $this->invoice($completed, 1, 'PLANNED', '2026-10-01');
        foreach (['DRAFT', 'SUBMITTED', 'CANCELLED', 'FINANCIALLY_CLOSED', 'ARCHIVED'] as $status) {
            $this->invoice(Project::factory()->state(['status' => $status])->assignedTo($this->staff)->create(), 1, 'PLANNED', '2026-10-01');
        }

        $listed = $this->actingAs($this->staff)->get('/invoices?late_to_bill=1')->assertOk()->viewData('invoices');
        $this->assertEqualsCanonicalizing([$late, $lateCompleted], $listed->pluck('id')->all());
        $this->assertSame([true, true], $listed->pluck('late_to_bill')->all());

        // Tanpa filter: penanda per baris memakai definisi yang sama, dan jumlah dashboard = jumlah daftar.
        $all = $this->get('/invoices')->assertOk()->viewData('invoices');
        $flagged = $all->filter(fn ($r) => $r->late_to_bill)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$late, $lateCompleted], $flagged);
        $this->assertSame(2, $this->get('/dashboard')->assertOk()->viewData('late_to_bill'));

        // 16:59 UTC sehari sebelumnya = 23:59 WIB tanggal 20: 10-20 belum terlambat; tepat 17:00 UTC (00:00 WIB tanggal 21) terlambat.
        $this->travelTo('2026-10-20 16:59:59');
        $this->assertSame([$lateCompleted], $this->get('/invoices?late_to_bill=1')->viewData('invoices')->pluck('id')->all());
        $this->assertSame(1, $this->get('/dashboard')->viewData('late_to_bill'));
        $this->travelTo('2026-10-20 17:00:00');
        $this->assertSame(2, $this->get('/dashboard')->viewData('late_to_bill'));
    }

    public function test_late_to_bill_filter_is_validated_and_scoped_to_visible_projects_and_division(): void
    {
        $this->travelTo('2026-10-20 03:00:00');
        $mine = Project::factory()->active()->assignedTo($this->staff)->create();
        $unassigned = Project::factory()->active()->create(); // BM, staf ini tidak ditugaskan
        $cp = Project::factory()->active()->state(['department' => 'CP'])->create();
        $this->invoice($mine, 1, 'PLANNED', '2026-10-01');
        $this->invoice($unassigned, 1, 'PLANNED', '2026-10-01');
        $this->invoice($unassigned, 2, 'PLANNED', '2026-10-02');
        $this->invoice($cp, 1, 'PLANNED', '2026-10-01');

        $this->actingAs($this->staff)->get('/invoices?late_to_bill=abc')->assertSessionHasErrors('late_to_bill');
        $this->assertSame([1, 1], [$this->get('/invoices?late_to_bill=1')->viewData('invoices')->total(), $this->get('/dashboard')->viewData('late_to_bill')]);
        $this->actingAs($this->manager); // seluruh divisi BM, bukan CP
        $this->assertSame([3, 3], [$this->get('/invoices?late_to_bill=1')->viewData('invoices')->total(), $this->get('/dashboard')->viewData('late_to_bill')]);

        $direksi = User::factory()->role('DIREKSI', null)->create();
        $this->assertSame(4, $this->actingAs($direksi)->get('/invoices?late_to_bill=1')->viewData('invoices')->total());
        $this->assertSame(4, $this->get('/dashboard')->viewData('late_to_bill'));
        $this->assertSame(1, $this->get('/dashboard?department=CP')->viewData('late_to_bill'));
    }

    // ---- P2-B: calon kontrak ----

    public function test_prospects_count_draft_and_submitted_only_within_scope(): void
    {
        Project::factory()->state(['status' => 'DRAFT', 'contract_value' => '1000.10'])->assignedTo($this->staff)->create();
        Project::factory()->state(['status' => 'SUBMITTED', 'contract_value' => '2000.20'])->assignedTo($this->staff)->create();
        Project::factory()->state(['status' => 'DRAFT', 'contract_value' => '4000.40'])->create(); // divisi sama, staf tidak ditugaskan
        Project::factory()->state(['status' => 'CANCELLED', 'contract_value' => '9999.00'])->assignedTo($this->staff)->create();
        Project::factory()->active()->state(['contract_value' => '8888.00'])->assignedTo($this->staff)->create();
        Project::factory()->state(['status' => 'SUBMITTED', 'department' => 'CP', 'contract_value' => '16000.80'])->create();

        $prospects = fn (User $user, string $url = '/dashboard') => $this->actingAs($user)->get($url)->assertOk()->viewData('prospects');
        $this->assertSame(['count' => 2, 'total' => '3000.30'], $prospects($this->staff));
        $this->assertSame(['count' => 3, 'total' => '7000.70'], $prospects($this->manager)); // seluruh divisi BM
        $direksi = User::factory()->role('DIREKSI', null)->create();
        $this->assertSame(['count' => 4, 'total' => '23001.50'], $prospects($direksi));
        $this->assertSame(['count' => 1, 'total' => '16000.80'], $prospects($direksi, '/dashboard?department=CP'));
        $this->assertSame(['count' => 3, 'total' => '7000.70'], $prospects($direksi, '/dashboard?department=BM'));

        // Tidak masuk KPI project teraktivasi.
        $this->assertSame(1, $this->actingAs($this->manager)->get('/dashboard')->viewData('kpi')['projects']);
        $empty = User::factory()->role('MANAGER_GM', 'ITBS')->create();
        $this->assertSame(['count' => 0, 'total' => '0.00'], $prospects($empty));
    }

    // ---- P2-C: satu RAB ----

    public function test_committed_budget_sums_approved_and_closed_only(): void
    {
        $project = Project::factory()->active()->create();
        $this->assertSame('0.00', $project->committedBudget());
        $this->bq($project, '100.10');
        $this->bq($project, '200.20', 'CLOSED');
        foreach (['DRAFT', 'SUBMITTED', 'REVISION_REQUIRED', 'ARCHIVED'] as $status) {
            $this->bq($project, '1000.00', $status);
        }
        $this->assertSame('300.30', $project->committedBudget());
    }

    public function test_bq_approve_counts_closed_items_and_allows_exactly_the_remaining_rab(): void
    {
        $project = Project::factory()->active()->assignedTo($this->staff)->create(['effective_rab' => '1000.00']);
        $this->bq($project, '600.00', 'CLOSED');
        $this->bq($project, '900.00', 'ARCHIVED'); // tidak dihitung
        $tooBig = $this->bq($project, '400.01', 'SUBMITTED');

        $this->actingAs($this->manager)->post("/bq-items/$tooBig/approve")->assertSessionHasErrors('bq');
        $this->assertSame('SUBMITTED', DB::table('bq_items')->find($tooBig)->status);
        DB::table('bq_items')->where('id', $tooBig)->update(['effective_budget' => '400.00']);
        $this->actingAs($this->manager)->post("/bq-items/$tooBig/approve")->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', DB::table('bq_items')->find($tooBig)->status);
    }

    public function test_activation_rejects_operational_budget_above_rab_and_rolls_back_everything(): void
    {
        $project = Project::factory()->state(['status' => 'SUBMITTED', 'effective_rab' => '1000.00'])->assignedTo($this->staff)->create();
        $operational = $this->bq($project, '1000.01', 'DRAFT', 'OPERATIONAL');
        $version = (int) DB::table('projects')->find($project->id)->version;

        $this->actingAs($this->manager)->post("/projects/{$project->id}/activate")->assertSessionHasErrors('project');

        $this->assertSame(['SUBMITTED', $version, null], [DB::table('projects')->find($project->id)->status, (int) DB::table('projects')->find($project->id)->version, DB::table('projects')->find($project->id)->activated_by]);
        $this->assertSame(['DRAFT', null], [DB::table('bq_items')->find($operational)->status, DB::table('bq_items')->find($operational)->approved_by]);
        $this->assertSame(0, DB::table('audit_logs')->count());

        // Sama persis dengan RAB lolos.
        DB::table('bq_items')->where('id', $operational)->update(['effective_budget' => '1000.00']);
        $this->actingAs($this->manager)->post("/projects/{$project->id}/activate")->assertSessionHasNoErrors();
        $this->assertSame(['ACTIVE', 'APPROVED'], [DB::table('projects')->find($project->id)->status, DB::table('bq_items')->find($operational)->status]);
    }

    public function test_from_unallocated_rab_mutation_ignores_archived_items(): void
    {
        $project = Project::factory()->active()->assignedTo($this->staff)->create(['effective_rab' => '1000.00']);
        $dest = $this->bq($project, '700.00');
        $this->bq($project, '5000.00', 'ARCHIVED'); // tidak dihitung
        $mutation = fn (string $amount) => DB::table('bq_budget_mutations')->insertGetId([
            'project_id' => $project->id, 'source_bq_id' => null, 'destination_bq_id' => $dest, 'amount' => $amount, 'reason' => 'Tambah',
            'mutation_type' => 'FROM_UNALLOCATED_RAB', 'status' => 'SUBMITTED', 'submitted_by' => $this->staff->id, 'submitted_at' => now(),
        ]);

        $over = $mutation('300.01'); // sisa 300.00
        $this->actingAs($this->manager)->post("/budget-mutations/$over/approve")->assertSessionHasErrors('mutation');
        $this->assertSame('700.00', DB::table('bq_items')->find($dest)->effective_budget);
        $exact = $mutation('300.00');
        $this->actingAs($this->manager)->post("/budget-mutations/$exact/approve")->assertSessionHasNoErrors();
        $this->assertSame('1000.00', DB::table('bq_items')->find($dest)->effective_budget);
    }

    public function test_adjustment_below_allocated_is_rejected_with_the_new_message_but_archived_items_do_not_count(): void
    {
        $project = Project::factory()->active()->assignedTo($this->staff)->create([
            'contract_value' => '1000.00', 'margin_base_amount' => '1000.00', 'target_margin_pct' => '10.0000', 'target_margin_amount' => '100.00', 'initial_rab' => '900.00', 'effective_rab' => '900.00',
        ]);
        $this->bq($project, '800.00', 'SUBMITTED');
        $this->bq($project, '900.00', 'ARCHIVED'); // tidak menghalangi
        $submit = function (string $pct) use ($project) {
            $this->actingAs($this->staff)->post("/projects/{$project->id}/adjustments", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => $pct, 'reason' => 'Nego'])->assertSessionHasNoErrors();

            return (int) DB::table('project_adjustments')->max('id');
        };

        $low = $submit('21'); // RAB 790.00 < 800.00
        $this->actingAs($this->manager)->post("/adjustments/$low/approve")->assertSessionHasErrors('adjustment');
        $this->assertStringContainsString('Sisa RAB tidak cukup: RAB Perubahan baru Rp790,00 lebih kecil dari total Nilai RAB semua item BQ', session('errors')->first('adjustment'));
        $this->assertStringContainsString('Item yang belum disetujui: Item SUBMITTED 800.00 (Diajukan, Rp800,00). Kecilkan atau arsipkan item yang belum disetujui lebih dulu.', session('errors')->first('adjustment'));
        $this->assertSame(['900.00', 'SUBMITTED'], [DB::table('projects')->find($project->id)->effective_rab, DB::table('project_adjustments')->find($low)->status]);

        $ok = $submit('20'); // RAB 800.00 = Σ Nilai RAB BQ
        $this->actingAs($this->manager)->post("/adjustments/$ok/approve")->assertSessionHasNoErrors();
        $this->assertSame('800.00', DB::table('projects')->find($project->id)->effective_rab);
    }
}
