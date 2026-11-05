<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Verifikasi independen CLS-01 (QA): gerbang gabungan, uang ke sen, angka Overview setelah close, dan buku yang terkunci permanen.
class FinancialClosingQaTest extends TestCase
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
        Storage::fake('local');
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create([
            'created_by' => $this->staff->id, 'status' => 'COMPLETED', 'effective_rab' => '179820000.00', 'target_margin_amount' => '30000000.00',
            'contract_value' => '335575804.00', 'initial_contract_value' => '671151608.00', // termin 2 (50%) batal dan sudah dikoreksi: nilai kontrak = termin 1
        ]);
        $this->normal = $this->bq('NORMAL', 'APPROVED', '100000000.55');
        $this->operational = $this->bq('OPERATIONAL', 'APPROVED', '69999999.45');
        $this->invoice('PAID');
        $this->invoice('CANCELLED');
    }

    private function bq(string $type, string $status, ?string $actual, string $name = 'Item'): int
    {
        return DB::table('bq_items')->insertGetId([
            'project_id' => $this->project->id, 'item_name' => "$name $type", 'item_type' => $type, 'status' => $status, 'created_by' => $this->staff->id,
            'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '800.00', 'margin_amount' => '200.00', 'margin_pct' => '20.0000', 'actual_cost' => $actual,
        ]);
    }

    private function invoice(string $status): int
    {
        $seq = DB::table('invoices')->count() + 1;

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

    private function proof(int $invoice): void
    {
        DB::table('documents')->insert([
            'project_id' => $this->project->id, 'entity_type' => 'INVOICE', 'entity_id' => $invoice, 'document_type' => 'BUKTI_BAYAR', 'original_filename' => 'b.pdf',
            'storage_key' => "k/$invoice/".uniqid(), 'mime_type' => 'application/pdf', 'file_size' => 10, 'status' => 'UPLOADED', 'uploaded_by' => $this->staff->id,
        ]);
    }

    private function state(): array
    {
        $all = fn (string $t) => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return array_map($all, ['projects', 'bq_items', 'invoices', 'bq_budget_mutations', 'project_adjustments', 'documents', 'project_user_assignments', 'audit_logs']);
    }

    private function close(?User $user = null)
    {
        return $this->actingAs($user ?? $this->manager)->postJson("/projects/{$this->project->id}/close");
    }

    private function row(): object
    {
        return DB::table('projects')->where('id', $this->project->id)->first();
    }

    public function test_combined_blockers_are_all_named_in_one_refusal_and_the_browser_flow_redirects_back(): void
    {
        $this->invoice('PAYMENT_REVISION_REQUIRED'); // unlock yang belum dibayar ulang
        $this->bq('NORMAL', 'APPROVED', null, 'Tanpa biaya');
        $this->bq('NORMAL', 'SUBMITTED', null, 'Menunggu');
        $this->adjustment('REVISION_REQUIRED');
        $this->mutation('SUBMITTED');
        $before = $this->state();

        $messages = implode(' | ', $this->close()->assertStatus(422)->json('errors.closing'));
        foreach (['termin 3 berstatus Revisi Pembayaran', 'Tanpa biaya NORMAL belum punya realisasi biaya', 'Menunggu NORMAL berstatus Diajukan', '1 penyesuaian atau koreksi belum selesai', '1 Relokasi Budget belum selesai'] as $needle) {
            $this->assertStringContainsString($needle, $messages);
        }
        $this->assertCount(3, $this->close()->json('errors.closing')); // satu pesan per syarat gagal (invoice, BQ, pending); syarat COMPLETED lolos

        $this->actingAs($this->manager)->from("/projects/{$this->project->id}")->post("/projects/{$this->project->id}/close")
            ->assertRedirect("/projects/{$this->project->id}")->assertSessionHasErrors('closing');
        $this->assertSame($before, $this->state());
        $this->assertNull($this->row()->actual_profit_amount);
    }

    public function test_zero_actual_cost_counts_as_filled_and_closes(): void
    {
        DB::table('bq_items')->where('id', $this->operational)->update(['actual_cost' => '0.00']); // Rp0 sah, bukan kosong
        $this->close()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('FINANCIALLY_CLOSED', $this->row()->status);
        // Final Sisa RAB 179.820.000,00 − 100.000.000,55 = 79.819.999,45; Actual Profit 30.000.000,00 + 79.819.999,45 = 109.819.999,45.
        $this->assertSame('109819999.45', $this->row()->actual_profit_amount);
        $this->assertSame('79819999.45', json_decode(DB::table('audit_logs')->where('action', 'LOCK')->sole()->data_after)->final_sisa_rab);
    }

    public function test_money_is_exact_at_the_column_limit_and_a_zero_profit_is_not_confused_with_not_closed(): void
    {
        // Kolom DECIMAL(18,2): 999999999999999,99 − (500000000000000,01 + 499999999999999,99) = −0,01; Actual Profit = 0,01 − 0,01 = 0,00.
        DB::table('projects')->where('id', $this->project->id)->update(['effective_rab' => '999999999999999.99', 'target_margin_amount' => '0.01']);
        DB::table('bq_items')->where('id', $this->normal)->update(['actual_cost' => '500000000000000.01']);
        DB::table('bq_items')->where('id', $this->operational)->update(['actual_cost' => '499999999999999.99']);
        $this->bq('NORMAL', 'ARCHIVED', '123456789.12', 'Dibuang'); // ARCHIVED tidak ikut

        $this->close()->assertRedirect();

        $this->assertSame('0.00', $this->row()->actual_profit_amount);
        $this->assertSame('-0.01', json_decode(DB::table('audit_logs')->where('action', 'LOCK')->sole()->data_after)->final_sisa_rab);
        $page = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk();
        $this->assertSame(['-0.01', '0.00'], [$page->viewData('summary')['final_sisa'], $page->viewData('summary')['actual_profit']]); // "0.00" bukan null: tetap tampil final
        $page->assertSee('final, terkunci');
    }

    public function test_overview_figures_do_not_move_when_the_books_close(): void
    {
        $keys = ['bq_baseline', 'bq_effective', 'unallocated', 'committed', 'operational_reserve', 'pending', 'official_sisa', 'projected_sisa', 'projected_profit'];
        $before = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->viewData('summary');
        $this->assertSame('800.00', $before['committed']); // fixture: nilai nego 800, operasional memakai effective_budget 1000
        $this->assertSame('1000.00', $before['operational_reserve']);

        $this->close()->assertRedirect();
        $after = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->viewData('summary');

        $this->assertSame(array_intersect_key($before, array_flip($keys)), array_intersect_key($after, array_flip($keys)));
        // Final Sisa RAB (pembukuan realisasi) tidak sama dengan Official Sisa RAB (komitmen): dua angka berbeda, keduanya tetap benar.
        $this->assertSame('9820000.00', $after['final_sisa']);
    }

    public function test_the_closed_books_are_immutable_through_every_route_even_with_actionable_leftovers(): void
    {
        $this->close()->assertRedirect();
        $p = $this->project->id;
        $u = $this->staff;
        $m = $this->manager;

        // Sisa data "di luar kebiasaan" yang di project terbuka akan bisa diproses: hanya gerbang status project yang menahannya.
        $draft = $this->bq('NORMAL', 'DRAFT', null, 'Konsep');
        $sub = $this->bq('NORMAL', 'SUBMITTED', null, 'Diajukan');
        $rev = $this->bq('NORMAL', 'REVISION_REQUIRED', null, 'Revisi');
        $appr = $this->bq('NORMAL', 'APPROVED', null, 'Setujui');
        $planned = $this->invoice('PLANNED');
        $invSub = $this->invoice('INVOICE_SUBMITTED');
        $invRev = $this->invoice('INVOICE_REVISION_REQUIRED');
        $wfp = $this->invoice('WAITING_FOR_PAYMENT');
        $paySub = $this->invoice('PAYMENT_SUBMITTED');
        $this->proof($paySub);
        $payRev = $this->invoice('PAYMENT_REVISION_REQUIRED');
        $paid = DB::table('invoices')->where('workflow_status', 'PAID')->value('id');
        $adjSub = $this->adjustment('SUBMITTED');
        $adjRev = $this->adjustment('REVISION_REQUIRED');
        $mutSub = $this->mutation('SUBMITTED');
        $mutRev = $this->mutation('REVISION_REQUIRED');
        $other = User::factory()->role('STAFF_PIC', 'BM')->create();
        $assignment = DB::table('project_user_assignments')->where('project_id', $p)->value('id');
        $pdf = fn () => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf');
        $bqBody = ['item_name' => 'Baru', 'baseline_budget' => '10.00', 'negotiated_value' => '9.00', 'version' => 1];
        $invBody = fn () => ['version' => 1, 'invoice_number' => 'Z-'.uniqid(), 'billing_date' => '2026-08-20', 'due_date' => '2026-09-30', 'pph_qualification' => 'B', 'file' => $pdf()];
        $mutBody = fn () => ['mutation_type' => 'FROM_UNALLOCATED_RAB', 'destination_bq_id' => $appr, 'amount' => '1.00', 'reason' => 'x', 'file' => $pdf()];

        $before = $this->state();
        $cases = [
            // [pengguna, method, uri, body, status yang diharapkan]
            [$u, 'post', "/projects/$p/bq-items", $bqBody, 403],
            [$u, 'put', "/bq-items/$draft", $bqBody, 403],
            [$u, 'put', "/bq-items/$rev", $bqBody, 403],
            [$u, 'put', "/bq-items/{$this->operational}", $bqBody, 403],
            [$u, 'put', "/bq-items/{$this->normal}", $bqBody, 403],
            [$u, 'post', "/bq-items/$draft/submit", [], 403],
            [$u, 'post', "/bq-items/$rev/submit", [], 403],
            [$u, 'post', "/bq-items/{$this->normal}/submit", [], 403],
            [$m, 'post', "/bq-items/$sub/approve", [], 403],
            [$m, 'post', "/bq-items/$sub/request-revision", ['reason' => 'x'], 403],
            [$m, 'post', "/bq-items/{$this->normal}/approve", [], 403],
            [$u, 'put', "/bq-items/$appr/actual-cost", ['actual_cost' => '5.00', 'version' => 1], 403],
            [$u, 'put', "/bq-items/{$this->normal}/actual-cost", ['actual_cost' => '1.00', 'reason' => 'x', 'version' => 2], 403],
            [$u, 'put', "/bq-items/{$this->operational}/actual-cost", ['actual_cost' => '1.00', 'reason' => 'x', 'version' => 2], 403],
            [$u, 'post', "/invoices/$planned/submit", $invBody(), 403],
            [$u, 'post', "/invoices/$invRev/submit", $invBody(), 403],
            [$u, 'post', "/invoices/$payRev/submit-payment", ['file' => $pdf()], 403],
            [$u, 'post', "/invoices/$wfp/submit-payment", ['file' => $pdf()], 403],
            [$m, 'post', "/invoices/$invSub/verify", [], 403],
            [$m, 'post', "/invoices/$invSub/request-revision", ['reason' => 'x'], 403],
            [$m, 'post', "/invoices/$paySub/approve-payment", [], 403],
            [$m, 'post', "/invoices/$paySub/request-revision", ['reason' => 'x'], 403],
            [$m, 'post', "/invoices/$paid/unlock", ['reason' => 'x'], 403],
            [$u, 'post', "/projects/$p/adjustments", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'AMOUNT', 'margin_value' => '1000.00', 'reason' => 'x'], 403],
            [$u, 'put', "/adjustments/$adjRev", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'AMOUNT', 'margin_value' => '1000.00', 'reason' => 'x'], 403],
            [$m, 'post', "/adjustments/$adjSub/approve", [], 403],
            [$m, 'post', "/adjustments/$adjSub/request-revision", ['reason' => 'x'], 403],
            [$u, 'post', "/projects/$p/budget-mutations", $mutBody(), 403],
            [$u, 'put', "/budget-mutations/$mutRev", $mutBody(), 403],
            [$m, 'post', "/budget-mutations/$mutSub/approve", [], 403],
            [$m, 'post', "/budget-mutations/$mutSub/request-revision", ['reason' => 'x'], 403],
            [$u, 'post', "/projects/$p/documents", ['document_type' => 'BAPP', 'file' => $pdf()], 403],
            [$u, 'get', "/projects/$p/edit", [], 403],
            [$u, 'put', "/projects/$p", ['version' => 2], 403],
            [$u, 'post', "/projects/$p/submit", [], 409],
            [$m, 'post', "/projects/$p/activate", [], 409],
            [$m, 'post', "/projects/$p/request-revision", ['reason' => 'x'], 409],
            [$m, 'post', "/projects/$p/complete", [], 409],
            [$m, 'post', "/projects/$p/close", [], 302], // non-JSON: redirect dengan error sesi
            [$m, 'post', "/projects/$p/assignments", ['user_id' => $other->id], 403],
            [$m, 'post', "/assignments/$assignment/deactivate", [], 403], // policy manageTeam
        ];

        foreach ($cases as $i => [$user, $method, $uri, $body, $expected]) {
            $response = $this->actingAs($user)->{$method}($uri, $body);
            $this->assertSame($expected, $response->getStatusCode(), "#$i $method $uri");
            $this->assertSame($before, $this->state(), "#$i $method $uri mengubah data");
        }
        $this->assertSame('FINANCIALLY_CLOSED', $this->row()->status);
    }

    public function test_control_the_same_payloads_do_work_while_the_project_is_still_completed(): void
    {
        // Tanpa ini, 403 di atas bisa saja berasal dari payload yang salah, bukan dari kunci closing.
        $p = $this->project->id;
        $draft = $this->bq('NORMAL', 'DRAFT', null, 'Konsep');
        $planned = DB::table('invoices')->insertGetId(['project_id' => $p, 'department' => 'BM', 'sequence_number' => 9, 'billing_type' => 'TERMIN', 'billing_percentage' => '50.0000', 'planned_amount' => '1.00', 'workflow_status' => 'PLANNED', 'created_by' => $this->staff->id]);
        DB::table('invoices')->where('id', '<>', $planned)->delete(); // satu-satunya termin: porsi akhir = seluruh kontrak, bukan sisa negatif
        $pdf = fn () => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf');
        foreach ([['PPN', null, '11.00'], ['PPH', 'B', '2.65']] as [$type, $q, $rate]) {
            DB::table('tax_rates')->insert(['tax_type' => $type, 'name' => "$type $rate", 'vendor_qualification' => $q, 'rate' => $rate, 'effective_from' => '2025-01-01', 'created_by' => $this->staff->id]);
        }

        $this->actingAs($this->staff)->post("/projects/$p/bq-items", ['item_name' => 'Baru', 'baseline_budget' => '10.00', 'negotiated_value' => '9.00'])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->staff)->put("/bq-items/$draft", ['item_name' => 'Baru', 'baseline_budget' => '10.00', 'negotiated_value' => '9.00', 'version' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->staff)->post("/bq-items/$draft/submit")->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->manager)->post("/bq-items/$draft/approve")->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->staff)->put("/bq-items/$draft/actual-cost", ['actual_cost' => '5.00', 'version' => 4])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->staff)->post("/invoices/$planned/submit", ['version' => 1, 'invoice_number' => 'Z-1', 'billing_date' => '2026-08-20', 'due_date' => '2026-09-30', 'pph_qualification' => 'B', 'file' => $pdf()])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->staff)->post("/projects/$p/budget-mutations", ['mutation_type' => 'FROM_UNALLOCATED_RAB', 'destination_bq_id' => $draft, 'amount' => '1.00', 'reason' => 'x', 'file' => $pdf()])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->staff)->post("/projects/$p/documents", ['document_type' => 'BAPP', 'file' => $pdf()])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_a_pending_adjustment_blocks_completion_so_it_can_never_strand_the_project(): void
    {
        // D1 diperbaiki: adjustment hanya bisa diputuskan saat ACTIVE, jadi complete() ditolak (409) selama masih ada yang pending.
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'ACTIVE']);
        $adj = $this->adjustment('SUBMITTED');

        $this->actingAs($this->manager)->postJson("/projects/{$this->project->id}/complete")->assertStatus(409);
        $this->assertSame('ACTIVE', $this->row()->status);
        $this->assertSame('SUBMITTED', DB::table('project_adjustments')->find($adj)->status);
    }

    public function test_planned_invoices_block_closing_until_the_manager_cancels_them(): void
    {
        // Cacat lama (tidak ada jalan membatalkan termin PLANNED) ditutup oleh invoices.cancel; lihat InvoiceCancelTest untuk skenario lengkap.
        $planned = $this->invoice('PLANNED');
        $this->close()->assertStatus(422);

        $this->actingAs($this->manager)->post("/invoices/$planned/cancel", ['reason' => 'Tidak ditagih'])->assertRedirect();
        $this->assertSame('CANCELLED', DB::table('invoices')->find($planned)->workflow_status);
        $this->close()->assertRedirect();
    }

    public function test_screen_escapes_blocker_names_and_renders_for_every_role_and_status_without_error(): void
    {
        $this->bq('NORMAL', 'APPROVED', null, '<script>alert(1)</script>');
        $page = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk();
        $page->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; NORMAL belum punya realisasi biaya', false);

        $roles = [$this->staff, $this->manager, User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', null)->create()];
        foreach (['DRAFT', 'SUBMITTED', 'ACTIVE', 'COMPLETED', 'FINANCIALLY_CLOSED', 'ARCHIVED', 'CANCELLED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status, 'actual_profit_amount' => $status === 'FINANCIALLY_CLOSED' ? '-1.00' : null]);
            foreach ($roles as $user) {
                $this->actingAs($user)->get("/projects/{$this->project->id}")->assertOk();
            }
        }
    }

    public function test_only_manager_sees_the_checklist_and_the_close_form_exists_only_when_ready(): void
    {
        $url = "/projects/{$this->project->id}";
        $this->actingAs($this->manager)->get($url)->assertSee(route('projects.close', $this->project), false);
        $this->actingAs($this->staff)->get($url)->assertDontSee(route('projects.close', $this->project), false)->assertDontSee('Tutup buku project');

        $this->bq('NORMAL', 'SUBMITTED', null, 'Tunda');
        $this->actingAs($this->manager)->get($url)->assertDontSee(route('projects.close', $this->project), false)->assertSee('Belum siap ditutup');

        $this->close()->assertStatus(422);
        DB::table('bq_items')->where('item_name', 'Tunda NORMAL')->delete();
        $this->close()->assertRedirect();
        $this->actingAs($this->manager)->get($url)->assertDontSee('Tutup buku project')->assertDontSee(route('projects.close', $this->project), false);
    }
}
