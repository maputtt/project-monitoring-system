<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

// CLS-01 (PRD 5.8, AC-16, AC-17): tandai selesai dan financial closing. Syarat diperiksa ulang di dalam transaksi.
class FinancialClosingTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private Project $project;

    private int $normal;

    private int $operational;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        // Effective RAB 179.820.000,00; target margin 30.000.000,00. Σ realisasi 170.000.000,00 → Final Sisa RAB 9.820.000,00.
        $this->project = Project::factory()->assignedTo($this->staff)->create([
            'created_by' => $this->staff->id, 'status' => 'COMPLETED', 'effective_rab' => '179820000.00', 'target_margin_amount' => '30000000.00',
            'contract_value' => '335575804.00', 'initial_contract_value' => '671151608.00', // termin 2 (50%) batal dan sudah dikoreksi: nilai kontrak = termin 1
        ]);
        $this->normal = $this->bq('NORMAL', 'APPROVED', '100000000.55');
        $this->operational = $this->bq('OPERATIONAL', 'APPROVED', '69999999.45');
        $this->invoice(1, 'PAID');
        $this->invoice(2, 'CANCELLED');
    }

    private function bq(string $type, string $status, ?string $actual, string $name = 'Item'): int
    {
        return DB::table('bq_items')->insertGetId([
            'project_id' => $this->project->id, 'item_name' => "$name $type", 'item_type' => $type, 'status' => $status, 'created_by' => $this->staff->id,
            'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '800.00', 'margin_amount' => '200.00', 'margin_pct' => '20.0000', 'actual_cost' => $actual,
        ]);
    }

    private function invoice(int $seq, string $status): int
    {
        return DB::table('invoices')->insertGetId([
            'invoice_number' => "INV/$seq", 'billing_date' => '2026-08-20', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500',
            'dpp_amount' => '100.00', 'ppn_amount' => '11.00', 'subtotal_amount' => '111.00', 'pph_amount' => '3.00', 'net_received_amount' => '108.00',
            'submitted_by' => $this->staff->id, 'submitted_at' => now(), 'locked_at' => $status === 'PAID' ? now() : null, 'locked_by' => $status === 'PAID' ? $this->manager->id : null,
            'project_id' => $this->project->id, 'department' => 'BM', 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'billing_percentage' => '50.0000',
            'planned_amount' => '111.00', 'workflow_status' => $status, 'created_by' => $this->staff->id,
        ]);
    }

    private function adjustment(string $status): int
    {
        return DB::table('project_adjustments')->insertGetId(['project_id' => $this->project->id, 'adjustment_type' => 'TARGET_MARGIN', 'status' => $status, 'reason' => 'x', 'submitted_by' => $this->staff->id, 'old_data' => '{}', 'new_data' => '{}']);
    }

    private function mutation(string $status): int
    {
        return DB::table('bq_budget_mutations')->insertGetId(['project_id' => $this->project->id, 'destination_bq_id' => $this->normal, 'mutation_type' => 'FROM_UNALLOCATED_RAB', 'amount' => '1.00', 'reason' => 'x', 'status' => $status, 'submitted_by' => $this->staff->id]);
    }

    /** Project, semua BQ, dan jumlah baris audit: cukup untuk membuktikan "tidak ada yang berubah". */
    private function state(): array
    {
        return [
            (array) DB::table('projects')->where('id', $this->project->id)->first(),
            DB::table('bq_items')->where('project_id', $this->project->id)->orderBy('id')->get()->map(fn ($b) => (array) $b)->all(),
            DB::table('audit_logs')->count(),
        ];
    }

    private function close(bool $json = true, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->manager)->{$json ? 'postJson' : 'post'}("/projects/{$this->project->id}/close");
    }

    private function row(): object
    {
        return DB::table('projects')->where('id', $this->project->id)->first();
    }

    public function test_complete_moves_active_to_completed_with_audit_and_no_self_approval_bar(): void
    {
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'ACTIVE']);
        DB::table('audit_logs')->insert(['user_id' => $this->manager->id, 'action' => 'SUBMIT', 'entity_type' => 'PROJECT', 'entity_id' => $this->project->id]); // pengaju tetap boleh menandai selesai

        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/complete")->assertRedirect("/projects/{$this->project->id}");

        $this->assertSame(['COMPLETED', 2], [$this->row()->status, (int) $this->row()->version]);
        $audit = DB::table('audit_logs')->where('entity_id', $this->project->id)->where('action', 'UPDATE')->sole();
        $this->assertSame(['ACTIVE', 'COMPLETED'], [json_decode($audit->data_before)->status, json_decode($audit->data_after)->status]);
    }

    public function test_complete_from_any_other_status_is_409_and_changes_nothing(): void
    {
        foreach (['DRAFT', 'SUBMITTED', 'COMPLETED', 'FINANCIALLY_CLOSED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            $before = $this->state();
            $this->actingAs($this->manager)->postJson("/projects/{$this->project->id}/complete")->assertStatus(409);
            $this->assertSame($before, $this->state());
        }
    }

    public function test_complete_is_refused_while_an_adjustment_is_pending_and_succeeds_once_resolved(): void
    {
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'ACTIVE']);
        foreach (['SUBMITTED', 'REVISION_REQUIRED'] as $status) {
            $id = $this->adjustment($status);
            $before = $this->state();
            $response = $this->actingAs($this->manager)->postJson("/projects/{$this->project->id}/complete")->assertStatus(409);
            $this->assertStringContainsString('penyesuaian atau koreksi yang belum selesai', $response->json('message'));
            $this->assertSame($before, $this->state()); // tetap ACTIVE, tanpa audit
            $this->assertSame('ACTIVE', $this->row()->status);
            DB::table('project_adjustments')->where('id', $id)->update(['status' => 'APPROVED']); // diputuskan
        }

        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/complete")->assertRedirect("/projects/{$this->project->id}");
        $this->assertSame('COMPLETED', $this->row()->status);
    }

    public function test_close_computes_final_sisa_and_actual_profit_closes_bq_and_audits(): void
    {
        $archived = $this->bq('NORMAL', 'ARCHIVED', '999999.00', 'Lama'); // ARCHIVED tidak dihitung dan tetap ARCHIVED

        $this->close(false)->assertRedirect("/projects/{$this->project->id}")->assertSessionHasNoErrors();

        $p = $this->row();
        $this->assertSame(['FINANCIALLY_CLOSED', '39820000.00', $this->manager->id, 2], [$p->status, $p->actual_profit_amount, (int) $p->financially_closed_by, (int) $p->version]);
        $this->assertNotNull($p->financially_closed_at);
        $bq = fn (int $id) => DB::table('bq_items')->find($id);
        $this->assertSame(['CLOSED', 'CLOSED', 'ARCHIVED', 2], [$bq($this->normal)->status, $bq($this->operational)->status, $bq($archived)->status, (int) $bq($this->normal)->version]);

        $audit = DB::table('audit_logs')->where('entity_type', 'PROJECT')->where('action', 'LOCK')->sole();
        $after = json_decode($audit->data_after);
        $this->assertSame([$this->manager->id, 'COMPLETED', 'FINANCIALLY_CLOSED', '9820000.00', '39820000.00', 2], [(int) $audit->user_id, json_decode($audit->data_before)->status, $after->status, $after->final_sisa_rab, $after->actual_profit_amount, $after->closed_bq_count]);

        // Layar: Final Sisa RAB = Actual Profit − target margin = 9.820.000,00; Official Sisa RAB tidak berubah karena item CLOSED tetap dihitung.
        $summary = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->assertSee('final, terkunci')->assertSee('<dt class="text-slate-500">Realisasi</dt>', false)->viewData('summary'); // angka realisasi di kartu ponsel tetap ada untuk item CLOSED
        $this->assertSame(['9820000.00', '39820000.00', '800.00'], [$summary['final_sisa'], $summary['actual_profit'], $summary['committed']]);
    }

    public function test_close_with_a_loss_stores_a_negative_actual_profit(): void
    {
        // Σ realisasi 215.000.000,00 → Final Sisa RAB −35.180.000,00; Actual Profit 30.000.000,00 − 35.180.000,00 = −5.180.000,00.
        DB::table('bq_items')->where('id', $this->normal)->update(['actual_cost' => '145000000.00']);
        DB::table('bq_items')->where('id', $this->operational)->update(['actual_cost' => '70000000.00']);

        $this->close(false)->assertSessionHasNoErrors();

        $this->assertSame(['FINANCIALLY_CLOSED', '-5180000.00'], [$this->row()->status, $this->row()->actual_profit_amount]);
        $this->assertSame('-35180000.00', json_decode(DB::table('audit_logs')->where('action', 'LOCK')->sole()->data_after)->final_sisa_rab);
        $summary = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->viewData('summary');
        $this->assertSame('-35180000.00', $summary['final_sisa']); // turunan Actual Profit − target margin tetap tepat untuk nilai negatif
    }

    public function test_each_unmet_condition_blocks_with_422_names_the_item_and_changes_nothing(): void
    {
        $p = $this->project->id;
        $cases = [
            'invoice belum lunas' => [fn () => $this->invoice(3, 'WAITING_FOR_PAYMENT'), 'termin 3 berstatus Menunggu Pembayaran'],
            'termin batal belum dikoreksi' => [fn () => DB::table('projects')->where('id', $p)->update(['contract_value' => '671151608.00']), '1 termin batal belum dikoreksi; ajukan Koreksi Nilai Tertagih'],
            'invoice di-unlock' => [fn () => DB::table('invoices')->where('sequence_number', 1)->update(['workflow_status' => 'PAYMENT_REVISION_REQUIRED']), 'termin 1 berstatus Revisi Pembayaran'],
            'BQ tanpa realisasi' => [fn () => $this->bq('NORMAL', 'APPROVED', null, 'Pagar'), 'Pagar NORMAL belum punya realisasi biaya'],
            'BQ operasional tanpa realisasi' => [fn () => DB::table('bq_items')->where('id', $this->operational)->update(['actual_cost' => null]), 'Item OPERATIONAL belum punya realisasi biaya'],
            'BQ SUBMITTED' => [fn () => $this->bq('NORMAL', 'SUBMITTED', null, 'Menunggu'), 'Menunggu NORMAL berstatus Diajukan'],
            'BQ REVISION_REQUIRED' => [fn () => $this->bq('NORMAL', 'REVISION_REQUIRED', '5.00', 'Revisi'), 'Revisi NORMAL berstatus Perlu Revisi'],
            'BQ DRAFT' => [fn () => $this->bq('NORMAL', 'DRAFT', null, 'Konsep'), 'Konsep NORMAL berstatus Draf'],
            'adjustment SUBMITTED' => [fn () => $this->adjustment('SUBMITTED'), '1 penyesuaian atau koreksi belum selesai'],
            'adjustment REVISION_REQUIRED' => [fn () => $this->adjustment('REVISION_REQUIRED'), '1 penyesuaian atau koreksi belum selesai'],
            'mutasi SUBMITTED' => [fn () => $this->mutation('SUBMITTED'), '1 Relokasi Budget belum selesai'],
            'mutasi REVISION_REQUIRED' => [fn () => $this->mutation('REVISION_REQUIRED'), '1 Relokasi Budget belum selesai'],
            'project masih ACTIVE' => [fn () => DB::table('projects')->where('id', $p)->update(['status' => 'ACTIVE']), 'status saat ini Berjalan'],
            'project sudah ditutup (klik ganda)' => [fn () => DB::table('projects')->where('id', $p)->update(['status' => 'FINANCIALLY_CLOSED']), 'status saat ini Tutup Buku'],
        ];

        foreach ($cases as $name => [$break, $expected]) {
            DB::beginTransaction(); // savepoint: setiap kasus mulai dari data yang lolos
            $break();
            $before = $this->state();
            $response = $this->close()->assertStatus(422)->assertJsonValidationErrors('closing');
            $this->assertStringContainsString($expected, implode(' ', $response->json('errors.closing')), $name);
            $this->assertSame($before, $this->state(), $name);
            DB::rollBack();
        }
        $this->close(false)->assertSessionHasNoErrors(); // dan data awal memang lolos
    }

    public function test_after_closing_financial_actions_are_refused(): void
    {
        $this->close(false)->assertSessionHasNoErrors();
        $id = $this->project->id;
        $paid = DB::table('invoices')->where('sequence_number', 1)->value('id');
        $adjustment = $this->adjustment('SUBMITTED');
        $mutation = $this->mutation('SUBMITTED');
        $before = $this->state();

        $this->actingAs($this->staff)->putJson("/bq-items/{$this->normal}/actual-cost", ['actual_cost' => '1.00', 'reason' => 'x', 'version' => 2])->assertForbidden();
        $this->actingAs($this->staff)->postJson("/projects/$id/bq-items", ['item_name' => 'Baru', 'baseline_budget' => '10', 'negotiated_value' => '9'])->assertForbidden();
        $this->actingAs($this->manager)->postJson("/bq-items/{$this->normal}/approve")->assertForbidden();
        $this->actingAs($this->manager)->postJson("/invoices/$paid/unlock", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->manager)->postJson("/adjustments/$adjustment/approve")->assertForbidden();
        $this->actingAs($this->manager)->postJson("/budget-mutations/$mutation/approve")->assertForbidden();
        $this->actingAs($this->manager)->postJson("/projects/$id/complete")->assertStatus(409);
        $this->actingAs($this->manager)->postJson("/projects/$id/request-revision", ['reason' => 'x'])->assertStatus(409);
        $this->close()->assertStatus(422); // klik ganda: tidak menghitung ulang

        $this->assertSame($before, $this->state());
        $this->assertSame(['SUBMITTED', 'SUBMITTED', 'PAID'], [DB::table('project_adjustments')->find($adjustment)->status, DB::table('bq_budget_mutations')->find($mutation)->status, DB::table('invoices')->find($paid)->workflow_status]);
    }

    public function test_only_the_division_manager_can_complete_or_close(): void
    {
        foreach (['close', 'complete'] as $command) {
            $this->post("/projects/{$this->project->id}/$command")->assertRedirect('/login'); // tamu dulu: actingAs di bawah bertahan antar iterasi
        }
        foreach (['close', 'complete'] as $command) {
            $url = "/projects/{$this->project->id}/$command";
            $this->actingAs($this->staff)->postJson($url)->assertForbidden();
            $this->actingAs(User::factory()->role('DIREKSI', null)->create())->postJson($url)->assertForbidden();
            $this->actingAs(User::factory()->role('SYSTEM_ADMIN', null)->create())->postJson($url)->assertForbidden();
            $this->actingAs(User::factory()->role('MANAGER_GM', 'CP')->create())->postJson($url)->assertNotFound();
        }
        $this->assertSame('COMPLETED', $this->row()->status);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'LOCK')->count());
    }

    public function test_checklist_on_the_screen_follows_the_same_rules(): void
    {
        $page = fn (User $u) => $this->actingAs($u)->get("/projects/{$this->project->id}")->assertOk();

        $ready = $page($this->manager)->assertSee('Tutup buku project');
        $this->assertTrue($ready->viewData('closing')['ready']);
        $this->assertSame(['9820000.00', '39820000.00'], [$ready->viewData('closing')['final_sisa'], $ready->viewData('closing')['actual_profit']]);
        $this->assertNull($page($this->staff)->viewData('closing')); // checklist hanya untuk Manager/GM

        $this->invoice(3, 'PLANNED');
        $blocked = $page($this->manager)->assertSee('termin 3 berstatus Direncanakan');
        $this->assertFalse($blocked->viewData('closing')['ready']);
        $blocked->assertSee('Aktif setelah semua syarat terpenuhi.');
    }

    public function test_close_rolls_back_everything_when_the_audit_write_fails(): void
    {
        $before = $this->state();
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into `audit_logs`')) {
                throw new RuntimeException('audit gagal');
            }
        });

        try {
            $this->actingAs($this->manager)->withoutExceptionHandling()->post("/projects/{$this->project->id}/close");
            $this->fail('Closing seharusnya gagal.');
        } catch (RuntimeException) {
        }

        $this->assertSame($before, $this->state());
        $this->assertSame(['COMPLETED', null, null], [$this->row()->status, $this->row()->actual_profit_amount, $this->row()->financially_closed_at]);
    }
}
