<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// QA independen arsip BQ dan pembatalan termin: celah yang tidak dicakup BqArchiveTest dan InvoiceCancelTest.
// Fokus: APPROVED tidak punya jalan ke ARCHIVED, efek uang lewat alur nyata (arsip dulu, lalu guard mutasi/adjustment/kartu RAB), item ARCHIVED/CANCELLED
// tidak bisa dipakai lagi, dan layar untuk semua peran dan status.
class ArchiveCancelQaTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        // Kontrak 199.800.000,00; 12% => margin 23.976.000,00; RAB 175.824.000,00.
        $this->project = Project::factory()->assignedTo($this->staff)->create([
            'created_by' => $this->staff->id, 'status' => 'ACTIVE', 'contract_value' => '199800000.00', 'margin_base_amount' => '199800000.00',
            'target_margin_pct' => '12.0000', 'target_margin_amount' => '23976000.00', 'initial_rab' => '175824000.00', 'effective_rab' => '175824000.00',
        ]);
    }

    private function bq(string $status, string $budget = '1000.00', string $name = 'Item', string $type = 'NORMAL', ?string $actual = null): int
    {
        return DB::table('bq_items')->insertGetId([
            'project_id' => $this->project->id, 'item_name' => $name, 'item_type' => $type, 'status' => $status, 'created_by' => $this->staff->id, 'actual_cost' => $actual,
            'baseline_budget' => $budget, 'effective_budget' => $budget, 'negotiated_value' => '800.00', 'margin_amount' => '200.00', 'margin_pct' => '20.0000',
        ]);
    }

    private function archive(int $id, mixed $reason = 'Batal'): TestResponse
    {
        return $this->actingAs($this->staff)->postJson("/bq-items/$id/archive", ['reason' => $reason]);
    }

    private function invoice(string $status, int $seq = 1): int
    {
        $planned = in_array($status, ['PLANNED', 'CANCELLED'], true);

        return DB::table('invoices')->insertGetId([
            'project_id' => $this->project->id, 'department' => 'BM', 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'billing_percentage' => '50.0000',
            'planned_amount' => '111.00', 'workflow_status' => $status, 'created_by' => $this->staff->id,
            'submitted_by' => $planned ? null : $this->staff->id, 'submitted_at' => $planned ? null : now(),
            'invoice_number' => $planned ? null : "INV/$seq", 'billing_date' => $planned ? null : '2026-08-20',
            'ppn_rate_snapshot' => $planned ? null : '11.0000', 'pph_rate_snapshot' => $planned ? null : '2.6500',
            'dpp_amount' => $planned ? null : '100.00', 'ppn_amount' => $planned ? null : '11.00', 'subtotal_amount' => $planned ? null : '111.00',
            'pph_amount' => $planned ? null : '3.00', 'net_received_amount' => $planned ? null : '108.00',
        ]);
    }

    private function bqStatus(int $id): string
    {
        return DB::table('bq_items')->where('id', $id)->value('status');
    }

    public function test_an_approved_bq_cannot_be_archived_in_any_project_status_and_no_other_route_archives_it(): void
    {
        $approved = $this->bq('APPROVED');
        foreach (['DRAFT', 'ACTIVE', 'COMPLETED'] as $projectStatus) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $projectStatus]);
            $this->archive($approved)->assertStatus(409);
        }
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'ACTIVE']);

        // Rute BQ lain (staff dan manager) tidak boleh mengubah APPROVED menjadi ARCHIVED, apa pun isinya.
        foreach (['submit', 'approve', 'request-revision'] as $route) {
            $this->actingAs($this->manager)->postJson("/bq-items/$approved/$route", ['reason' => 'x'])->assertStatus(in_array($route, ['submit'], true) ? 403 : 409);
        }
        $this->actingAs($this->staff)->putJson("/bq-items/$approved", ['item_name' => 'X', 'baseline_budget' => '1.00', 'negotiated_value' => '1.00', 'version' => 1, 'status' => 'ARCHIVED'])->assertStatus(409);
        $this->actingAs($this->staff)->putJson("/bq-items/$approved/actual-cost", ['actual_cost' => '0', 'version' => 1, 'status' => 'ARCHIVED'])->assertRedirect();

        $this->assertSame('APPROVED', $this->bqStatus($approved)); // Rp0 realisasi adalah jalan resmi, statusnya tetap APPROVED
        $this->assertSame('0.00', DB::table('bq_items')->where('id', $approved)->value('actual_cost'));
    }

    public function test_an_archived_bq_is_dead_for_every_other_action(): void
    {
        $id = $this->bq('DRAFT');
        $this->archive($id)->assertRedirect();
        $version = (int) DB::table('bq_items')->where('id', $id)->value('version');

        $this->actingAs($this->staff)->postJson("/bq-items/$id/submit")->assertStatus(409);
        $this->actingAs($this->manager)->postJson("/bq-items/$id/approve")->assertStatus(409);
        $this->actingAs($this->manager)->postJson("/bq-items/$id/request-revision", ['reason' => 'x'])->assertStatus(409);
        $this->actingAs($this->staff)->putJson("/bq-items/$id", ['item_name' => 'X', 'baseline_budget' => '1.00', 'negotiated_value' => '1.00', 'version' => $version])->assertStatus(409);
        $this->actingAs($this->staff)->putJson("/bq-items/$id/actual-cost", ['actual_cost' => '5', 'version' => $version])->assertStatus(409);

        $this->assertSame(['ARCHIVED', $version], [$this->bqStatus($id), (int) DB::table('bq_items')->where('id', $id)->value('version')]);
        $this->assertSame(1, DB::table('audit_logs')->where('entity_type', 'BQ_ITEM')->where('entity_id', $id)->where('action', 'UPDATE')->count());
    }

    public function test_archiving_a_draft_frees_unallocated_rab_for_the_card_mutation_and_adjustment_guards(): void
    {
        // Alokasi: APPROVED 100.000.000 + DRAFT 71.828.000 = 171.828.000; belum dialokasikan 175.824.000 - 171.828.000 = 3.996.000.
        $this->bq('APPROVED', '100000000.00', 'Besar');
        $draft = $this->bq('DRAFT', '71828000.00', 'Ditinggal');
        $summary = fn () => $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->viewData('summary');
        $this->assertSame('3996000.00', $summary()['unallocated']);

        // Peringatan lunak di ringkasan menghitung DRAFT, tetapi guard keras hanya APPROVED/CLOSED: mutasi 4.000.000 dan adjustment 12% -> 16% (RAB 167.832.000) tetap lolos.
        $dest = DB::table('bq_items')->where('item_name', 'Besar')->value('id');
        $mutation = DB::table('bq_budget_mutations')->insertGetId([
            'project_id' => $this->project->id, 'source_bq_id' => null, 'destination_bq_id' => $dest, 'amount' => '4000000.00', 'reason' => 'Tambah',
            'mutation_type' => 'FROM_UNALLOCATED_RAB', 'status' => 'SUBMITTED', 'submitted_by' => $this->staff->id, 'submitted_at' => now(),
        ]);
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/adjustments", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '16', 'reason' => 'Nego'])->assertSessionHasNoErrors();
        $adjustment = (int) DB::table('project_adjustments')->max('id');
        $this->archive($draft)->assertRedirect();

        $this->assertSame('75824000.00', $summary()['unallocated']); // 175.824.000 - 100.000.000
        $this->actingAs($this->manager)->post("/budget-mutations/$mutation/approve")->assertSessionHasNoErrors();
        $this->assertSame('104000000.00', DB::table('bq_items')->where('id', $dest)->value('effective_budget'));
        // Setelah mutasi alokasi 104.000.000; RAB baru 167.832.000 cukup.
        $this->actingAs($this->manager)->post("/adjustments/$adjustment/approve")->assertSessionHasNoErrors();
        $this->assertSame('167832000.00', DB::table('projects')->where('id', $this->project->id)->value('effective_rab'));
    }

    public function test_an_archived_bq_cannot_be_a_mutation_source_or_destination(): void
    {
        $live = $this->bq('APPROVED', '1000.00', 'Hidup');
        $dead = $this->bq('DRAFT', '1000.00', 'Mati');
        $this->archive($dead)->assertRedirect();
        $pdf = fn () => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf');

        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/budget-mutations", ['mutation_type' => 'FROM_UNALLOCATED_RAB', 'destination_bq_id' => $dead, 'amount' => '10.00', 'reason' => 'x', 'file' => $pdf()])
            ->assertSessionHasErrorsIn('mutation-new', 'destination_bq_id');
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/budget-mutations", ['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $dead, 'destination_bq_id' => $live, 'amount' => '10.00', 'reason' => 'x', 'file' => $pdf()])
            ->assertSessionHasErrorsIn('mutation-new', 'source_bq_id');
        $this->assertSame(0, DB::table('bq_budget_mutations')->count());
    }

    public function test_cancelled_invoice_cannot_be_submitted_or_paid(): void
    {
        $id = $this->invoice('PLANNED');
        $this->actingAs($this->manager)->postJson("/invoices/$id/cancel", ['reason' => 'Batal'])->assertRedirect();
        $pdf = fn () => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf');

        $this->actingAs($this->staff)->post("/invoices/$id/submit", ['version' => 2, 'invoice_number' => 'INV/9', 'billing_date' => '2026-08-20', 'due_date' => '2026-09-30', 'pph_qualification' => 'A', 'file' => $pdf()])->assertStatus(409);
        $this->actingAs($this->staff)->post("/invoices/$id/submit-payment", ['file' => $pdf()])->assertStatus(409);

        $row = DB::table('invoices')->find($id);
        $this->assertSame(['CANCELLED', 2, null, null], [$row->workflow_status, (int) $row->version, $row->invoice_number, $row->subtotal_amount]);
        $this->assertSame(0, DB::table('documents')->where('entity_type', 'INVOICE')->where('entity_id', $id)->count());
    }

    public function test_cancelling_a_termin_does_not_reallocate_dpp_to_the_remaining_termins(): void
    {
        // Kontrak 100.000,01 EXCLUSIVE, 33,3333/33,3333/33,3334: DPP 33.333,30 / 33.333,30 / 33.333,41 (sama dengan InvoiceSubmitQaTest). Termin 2 dibatalkan; 1 dan 3 tetap.
        DB::table('projects')->where('id', $this->project->id)->update(['contract_tax_mode' => 'EXCLUSIVE_PPN', 'contract_value' => '100000.01', 'initial_contract_value' => '100000.01', 'margin_base_ppn_rate_snapshot' => '11.0000']);
        foreach ([['PPN', null, '11.00'], ['PPH', 'B', '2.65']] as [$type, $q, $rate]) {
            DB::table('tax_rates')->insert(['tax_type' => $type, 'name' => "$type $rate", 'vendor_qualification' => $q, 'rate' => $rate, 'effective_from' => '2025-01-01', 'created_by' => $this->staff->id]);
        }
        $ids = [];
        foreach (['33.3333', '33.3333', '33.3334'] as $i => $pct) {
            $ids[] = DB::table('invoices')->insertGetId(['project_id' => $this->project->id, 'department' => 'BM', 'sequence_number' => $i + 1, 'billing_type' => 'TERMIN',
                'billing_percentage' => $pct, 'planned_amount' => '1.00', 'workflow_status' => 'PLANNED', 'created_by' => $this->staff->id]);
        }
        $this->actingAs($this->manager)->postJson("/invoices/{$ids[1]}/cancel", ['reason' => 'Tidak jadi'])->assertRedirect();

        foreach ([0, 2] as $i) {
            $this->actingAs($this->staff)->post("/invoices/{$ids[$i]}/submit", ['version' => 1, 'invoice_number' => "INV-$i", 'billing_date' => '2026-08-20', 'due_date' => '2026-09-30', 'pph_qualification' => 'B',
                'file' => UploadedFile::fake()->create('i.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
        }
        $this->assertSame(['33333.30', null, '33333.41'], array_map(fn ($id) => DB::table('invoices')->find($id)->dpp_amount, $ids));
    }

    public function test_both_routes_are_post_only(): void
    {
        $bq = $this->bq('DRAFT');
        $inv = $this->invoice('PLANNED');
        $this->actingAs($this->staff)->get("/bq-items/$bq/archive")->assertStatus(405);
        $this->actingAs($this->manager)->get("/invoices/$inv/cancel")->assertStatus(405);
        $this->actingAs($this->staff)->putJson("/bq-items/$bq/archive")->assertStatus(405);
        $this->actingAs($this->manager)->deleteJson("/invoices/$inv/cancel")->assertStatus(405);
        $this->assertSame('DRAFT', $this->bqStatus($bq));
    }

    public function test_reasons_are_escaped_in_the_audit_page_and_item_names_in_the_archived_list(): void
    {
        $xss = '<script>alert(1)</script>';
        $bq = $this->bq('DRAFT', '1000.00', "Nama $xss");
        $inv = $this->invoice('PLANNED');
        $this->archive($bq, "Alasan $xss")->assertRedirect();
        $this->actingAs($this->manager)->postJson("/invoices/$inv/cancel", ['reason' => "Batal $xss"])->assertRedirect();

        foreach ([$this->staff, $this->manager] as $user) {
            $this->actingAs($user)->get("/projects/{$this->project->id}")->assertOk()->assertDontSee($xss, false)->assertSee('Nama &lt;script&gt;alert(1)&lt;/script&gt;', false);
        }
        $this->actingAs($this->manager)->get('/audit-logs')->assertOk()->assertDontSee($xss, false)->assertSee('Alasan &lt;script&gt;', false)->assertSee('Batal &lt;script&gt;', false);
    }

    public function test_screen_buttons_follow_role_and_status_and_history_is_visible_to_every_role(): void
    {
        $this->bq('DRAFT', '1000.00', 'Draft Hidup');
        $this->bq('REVISION_REQUIRED', '1000.00', 'Revisi Hidup');
        $this->bq('APPROVED', '1000.00', 'Approved Hidup');
        $this->bq('SUBMITTED', '1000.00', 'Submitted Hidup');
        $gone = $this->bq('DRAFT', '1000.00', 'Mati Arsip');
        $this->archive($gone)->assertRedirect();
        $this->invoice('PLANNED', 1);
        $this->invoice('CANCELLED', 2);
        $roles = [$this->staff, $this->manager, User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', null)->create()];

        foreach (['DRAFT', 'SUBMITTED', 'ACTIVE', 'COMPLETED', 'FINANCIALLY_CLOSED', 'ARCHIVED', 'CANCELLED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            foreach ($roles as $user) {
                $page = $this->actingAs($user)->get("/projects/{$this->project->id}");
                $this->assertLessThan(500, $page->status(), "$status {$user->role->value}");
                if ($page->status() !== 200) {
                    continue;
                }
                $staffOpen = $user->id === $this->staff->id && in_array($status, ['DRAFT', 'ACTIVE', 'COMPLETED'], true);
                $managerOpen = $user->id === $this->manager->id && in_array($status, ['ACTIVE', 'COMPLETED'], true);
                $page->assertSee('Mati Arsip');
                $page->assertSee('Item BQ diarsipkan (1)', false);
                // Hanya DRAFT dan REVISION_REQUIRED yang bisa diarsipkan (tombol pemicu punya aria-label tanpa tanda tanya; judul modal berakhir "?").
                $html = $page->getContent();
                foreach (['Draft Hidup' => true, 'Revisi Hidup' => true, 'Approved Hidup' => false, 'Submitted Hidup' => false] as $name => $archivable) {
                    $this->assertSame($staffOpen && $archivable ? 1 : 0, substr_count($html, "aria-label=\"Arsipkan $name\""), "arsip $name $status {$user->role->value}");
                }
                $this->assertSame($managerOpen ? 1 : 0, substr_count($html, 'aria-label="Batalkan termin 1"'), "batal $status {$user->role->value}");
                $this->assertSame(0, substr_count($html, 'aria-label="Batalkan termin 2"')); // CANCELLED tidak bisa dibatalkan lagi
                $page->assertSee('Dibatalkan, tidak akan ditagih');
            }
        }
    }
}
