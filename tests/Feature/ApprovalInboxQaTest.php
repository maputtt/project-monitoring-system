<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// QA independen DSH-02: no-leak lintas divisi, kelengkapan/akurasi, paritas "own" dengan guard detail, read-only, escaping, paginasi.
class ApprovalInboxQaTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => 'Siti Staff']);
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
    }

    private function project(string $dept, string $code, string $status = 'ACTIVE', array $extra = []): Project
    {
        return Project::factory()->create(array_replace(['department' => $dept, 'project_code' => $code, 'project_name' => "Nama $code", 'status' => $status, 'created_by' => $this->staff->id], $extra));
    }

    private function bq(Project $p, string $status, string $budget = '100.00', ?int $by = null, string $name = 'BQ x'): int
    {
        return DB::table('bq_items')->insertGetId(['project_id' => $p->id, 'item_name' => $name, 'item_type' => 'NORMAL', 'status' => $status, 'effective_budget' => $budget, 'created_by' => $by ?? $this->staff->id]);
    }

    private function invoice(Project $p, string $status, string $subtotal, int $seq = 1, ?int $by = null, ?string $number = null): int
    {
        return DB::table('invoices')->insertGetId([
            'project_id' => $p->id, 'department' => $p->department->value, 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'billing_percentage' => '33.3333', 'planned_amount' => '100.00',
            'workflow_status' => $status, 'created_by' => $this->staff->id, 'subtotal_amount' => $subtotal, 'invoice_number' => $number ?? 'INV-'.uniqid(), 'billing_date' => '2026-09-01',
            'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500', 'submitted_by' => $by ?? $this->staff->id, 'submitted_at' => '2026-10-01 08:00:00',
            'locked_at' => $status === 'PAID' ? now() : null, 'locked_by' => $status === 'PAID' ? $this->manager->id : null,
        ]);
    }

    private function adjustment(Project $p, string $status, string $diff, ?int $by = null): int
    {
        return DB::table('project_adjustments')->insertGetId(['project_id' => $p->id, 'adjustment_type' => 'TARGET_MARGIN', 'status' => $status, 'reason' => 'alasan', 'difference_amount' => $diff, 'submitted_by' => $by ?? $this->staff->id, 'submitted_at' => '2026-10-02 09:00:00', 'old_data' => '{}', 'new_data' => '{}']);
    }

    private function mutation(Project $p, int $dest, string $status, string $amount, ?int $by = null): int
    {
        return DB::table('bq_budget_mutations')->insertGetId(['project_id' => $p->id, 'destination_bq_id' => $dest, 'mutation_type' => 'FROM_UNALLOCATED_RAB', 'amount' => $amount, 'reason' => 'alasan', 'status' => $status, 'submitted_by' => $by ?? $this->staff->id, 'submitted_at' => '2026-10-03 10:00:00']);
    }

    private function audit(string $type, int $id, User $by, string $at = '2026-10-04 11:00:00'): void
    {
        DB::table('audit_logs')->insert(['user_id' => $by->id, 'action' => 'SUBMIT', 'entity_type' => $type, 'entity_id' => $id, 'created_at' => $at]);
    }

    /** Satu set enam jenis pending + semua status non-pending, dengan token unik per divisi. */
    private function fullSet(Project $p, string $n): void
    {
        $this->audit('PROJECT', $p->id, $this->staff);
        $bq = $this->bq($p, 'SUBMITTED', "1$n.11", null, "BQNAME$n");
        $this->audit('BQ_ITEM', $bq, $this->staff);
        foreach (['DRAFT', 'APPROVED', 'REVISION_REQUIRED', 'ARCHIVED'] as $i => $s) {
            $this->bq($p, $s, "9$n.9$i", null, "BQHIDE$n$s");
        }
        $this->invoice($p, 'INVOICE_SUBMITTED', "2$n.22", 1, null, "INV-A$n");
        $pay = $this->invoice($p, 'PAYMENT_SUBMITTED', "3$n.33", 2, null, "INV-B$n");
        $this->audit('INVOICE', $pay, $this->staff);
        foreach (['PLANNED', 'INVOICE_REVISION_REQUIRED', 'WAITING_FOR_PAYMENT', 'PAYMENT_REVISION_REQUIRED', 'PAID', 'CANCELLED'] as $i => $s) {
            $planned = in_array($s, ['PLANNED', 'CANCELLED'], true);
            $planned ? DB::table('invoices')->insert(['project_id' => $p->id, 'department' => $p->department->value, 'sequence_number' => 10 + $i, 'billing_type' => 'TERMIN', 'planned_amount' => '1.00', 'workflow_status' => $s, 'created_by' => $this->staff->id, 'subtotal_amount' => "8$n.8$i"])
                : $this->invoice($p, $s, "8$n.8$i", 10 + $i, null, "INV-H$n$i");
        }
        $this->adjustment($p, 'SUBMITTED', "4$n.44");
        foreach (['DRAFT', 'APPROVED', 'REVISION_REQUIRED'] as $i => $s) {
            $this->adjustment($p, $s, "7$n.7$i");
        }
        $this->mutation($p, $bq, 'SUBMITTED', "5$n.55");
        foreach (['DRAFT', 'APPROVED', 'REVISION_REQUIRED', 'REVERSED'] as $i => $s) {
            $this->mutation($p, $bq, $s, "6$n.6$i");
        }
    }

    public function test_no_cross_division_leak_and_counts_exclude_other_divisions(): void
    {
        $cpStaff = User::factory()->role('STAFF_PIC', 'CP')->create(['name' => 'Cipto Rahasia']);
        $itbsStaff = User::factory()->role('STAFF_PIC', 'ITBS')->create(['name' => 'Ismail Itbs']);
        $bm = $this->project('BM', 'BM-OK');
        $this->fullSet($bm, '1');
        foreach ([['CP', $cpStaff, '6'], ['ITBS', $itbsStaff, '7']] as [$dept, $user, $n]) {
            $p = $this->project($dept, "$dept-SECRET", 'ACTIVE', ['created_by' => $user->id]);
            $this->fullSet($p, $n);
            $this->project($dept, "$dept-SUBMIT", 'SUBMITTED', ['created_by' => $user->id]);
            DB::table('project_adjustments')->where('project_id', $p->id)->update(['submitted_by' => $user->id]);
        }
        $subP = $this->project('CP', 'CP-SUBMITTED', 'SUBMITTED', ['contract_value' => '123456789.00']);
        $this->audit('PROJECT', $subP->id, $cpStaff);

        $res = $this->actingAs($this->manager)->get('/approvals')->assertOk();
        $this->assertEquals(['project' => 0, 'bq' => 1, 'invoice' => 1, 'payment' => 1, 'adjustment' => 1, 'mutation' => 1], array_merge(['project' => 0], $res->viewData('counts')->all()));
        foreach (['CP-SECRET', 'ITBS-SECRET', 'CP-SUBMIT', 'ITBS-SUBMIT', 'CP-SUBMITTED', 'Nama CP', 'Nama ITBS', 'BQNAME6', 'BQNAME7', 'INV-A6', 'INV-B6', 'INV-A7', 'INV-B7', 'Cipto Rahasia', 'Ismail Itbs',
            'Rp16,11', 'Rp26,22', 'Rp36,33', 'Rp46,44', 'Rp56,55', 'Rp17,11', 'Rp27,22', 'Rp37,33', 'Rp47,44', 'Rp57,55', 'Rp123.456.789,00'] as $needle) {
            $res->assertDontSee($needle);
        }
        // sanity: data BM sendiri tampil dan item non-pending BM tidak
        $res->assertSee('BM-OK')->assertSee('BQNAME1')->assertSee('Rp11,11')->assertSee('Rp21,22')->assertSee('Rp31,33')->assertSee('Rp41,44')->assertSee('Rp51,55');
        foreach (['BQHIDE', 'INV-H1', 'Rp91,', 'Rp81,', 'Rp71,', 'Rp61,'] as $needle) {
            $res->assertDontSee($needle);
        }
        $this->assertSame(5, $res->viewData('items')->total());

        // cermin: Manager CP hanya melihat CP
        $cpManager = User::factory()->role('MANAGER_GM', 'CP')->create();
        $cp = $this->actingAs($cpManager)->get('/approvals')->assertOk();
        $cp->assertSee('CP-SECRET')->assertSee('CP-SUBMITTED')->assertDontSee('BM-OK')->assertDontSee('ITBS-SECRET')->assertDontSee('BQNAME1');
        $this->assertSame(7, $cp->viewData('items')->total()); // 5 jenis pending + 2 project SUBMITTED
    }

    public function test_each_pending_item_appears_exactly_once_with_correct_submitter_and_amount(): void
    {
        $p = $this->project('BM', 'BM-ONE', 'SUBMITTED', ['contract_value' => '1234567.895']);
        $other = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => 'Budi Kedua']);
        $this->audit('PROJECT', $p->id, $this->staff, '2026-10-01 08:00:00');
        $this->audit('PROJECT', $p->id, $other, '2026-10-05 08:00:00'); // diajukan ulang: yang terakhir menang
        $act = $this->project('BM', 'BM-ACT');
        $bqNoAudit = $this->bq($act, 'SUBMITTED', '1000.005', null, 'BQ tanpa audit');
        $bqAudit = $this->bq($act, 'SUBMITTED', '2000.00', null, 'BQ beraudit');
        $this->audit('BQ_ITEM', $bqAudit, $other, '2026-10-06 08:00:00');
        $this->invoice($act, 'INVOICE_SUBMITTED', '1500000.50', 1, $other->id, 'INV-1');
        $pay = $this->invoice($act, 'PAYMENT_SUBMITTED', '99.99', 2, $this->staff->id, 'INV-2');
        $this->audit('INVOICE', $pay, $this->staff, '2026-10-02 08:00:00');
        $this->audit('INVOICE', $pay, $other, '2026-10-07 08:00:00'); // bukti bayar diunggah ulang oleh other
        $this->adjustment($act, 'SUBMITTED', '-250.50');
        $this->mutation($act, $bqAudit, 'SUBMITTED', '75.005', $other->id);

        $res = $this->actingAs($this->manager)->get('/approvals')->assertOk();
        $rows = collect($res->viewData('items')->items());
        $this->assertCount(7, $rows);
        $this->assertSame(7, $rows->unique(fn ($r) => $r['type'].$r['summary'].$r['project_id'])->count());
        $this->assertEquals(['project' => 1, 'bq' => 2, 'invoice' => 1, 'payment' => 1, 'adjustment' => 1, 'mutation' => 1], $res->viewData('counts')->all());
        $this->assertSame(7, $res->viewData('counts')->sum());
        $res->assertSee('Rp1.234.567,90')->assertSee('Rp1.000,01')->assertSee('Rp2.000,00')->assertSee('Rp1.500.000,50')->assertSee('Rp99,99')->assertSee('-Rp250,50')->assertSee('Rp75,01');
        $byType = fn ($t, $s = null) => $rows->first(fn ($r) => $r['type'] === $t && ($s === null || $r['summary'] === $s));
        $this->assertSame('Budi Kedua', $byType('project')['by_name']);
        $this->assertNull($byType('bq', 'BQ tanpa audit')['by_name']);
        $this->assertNull($byType('bq', 'BQ tanpa audit')['at']);
        $this->assertSame('Budi Kedua', $byType('bq', 'BQ beraudit')['by_name']);
        $this->assertSame('Budi Kedua', $byType('invoice')['by_name']);
        $this->assertSame('Budi Kedua', $byType('payment')['by_name']);
        $this->assertSame('Siti Staff', $byType('adjustment')['by_name']);
        $this->assertSame('Budi Kedua', $byType('mutation')['by_name']);
        $res->assertSee('Diajukan — ·', false); // baris tanpa pengaju/tanggal menampilkan tanda hubung
    }

    public function test_own_marking_matches_detail_self_approval_guards(): void
    {
        $p = $this->project('BM', 'BM-OWN');
        $m = $this->manager;
        $this->audit('PROJECT', $p->id, $m); // project: SUBMIT audit apa pun oleh manager
        $sub = $this->project('BM', 'BM-SUB', 'SUBMITTED');
        $this->audit('PROJECT', $sub->id, $m);
        $bq = $this->bq($p, 'SUBMITTED', '10.00', $m->id, 'BQ-OWN');
        $inv = $this->invoice($p, 'INVOICE_SUBMITTED', '20.00', 1, $m->id);
        $pay = $this->invoice($p, 'PAYMENT_SUBMITTED', '30.00', 2, $this->staff->id);
        $this->audit('INVOICE', $pay, $m); // pengunggah bukti bayar = manager
        DB::table('documents')->insert(['project_id' => $p->id, 'entity_type' => 'INVOICE', 'entity_id' => $pay, 'document_type' => 'BUKTI_BAYAR', 'original_filename' => 'b.pdf', 'storage_key' => 'qa/b.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 10, 'uploaded_by' => $m->id]);
        $adj = $this->adjustment($p, 'SUBMITTED', '40.00', $m->id);
        $mut = $this->mutation($p, $bq, 'SUBMITTED', '50.00', $m->id);

        $rows = collect($this->actingAs($m)->get('/approvals')->assertOk()->viewData('items')->items());
        $this->assertCount(6, $rows);
        $this->assertTrue($rows->every(fn ($r) => $r['own']), 'semua item milik manager harus bertanda own: '.json_encode($rows->pluck('own', 'type')));

        // paritas: detail menolak persis item yang ditandai
        $this->post("/projects/{$sub->id}/activate")->assertForbidden();
        $this->post("/bq-items/$bq/approve")->assertForbidden();
        $this->post("/invoices/$inv/verify")->assertForbidden();
        $this->post("/invoices/$pay/approve-payment")->assertForbidden();
        $this->post("/adjustments/$adj/approve")->assertForbidden();
        $this->post("/budget-mutations/$mut/approve")->assertForbidden();
        $this->assertSame('SUBMITTED', DB::table('bq_items')->where('id', $bq)->value('status'));
        $this->assertSame('SUBMITTED', DB::table('project_adjustments')->where('id', $adj)->value('status'));
    }

    public function test_item_of_other_submitter_is_not_marked_own(): void
    {
        $p = $this->project('BM', 'BM-OTH');
        $this->bq($p, 'SUBMITTED', '10.00', $this->staff->id, 'BQ-OTH');
        $this->adjustment($p, 'SUBMITTED', '40.00');
        $this->invoice($p, 'INVOICE_SUBMITTED', '20.00');
        $rows = collect($this->actingAs($this->manager)->get('/approvals')->assertOk()->viewData('items')->items());
        $this->assertCount(3, $rows);
        $this->assertFalse($rows->contains(fn ($r) => $r['own']));
    }

    public function test_read_only_no_mutating_routes_no_forms_and_anchors_exist_on_detail(): void
    {
        $p = $this->project('BM', 'BM-RO');
        $bq = $this->bq($p, 'SUBMITTED');
        $this->invoice($p, 'INVOICE_SUBMITTED', '1.00');
        $snap = json_encode(['target_margin_pct' => '15.0000', 'target_margin_amount' => '1.00', 'effective_rab' => '1.00']);
        DB::table('project_adjustments')->where('id', $this->adjustment($p, 'SUBMITTED', '1.00'))->update(['old_data' => $snap, 'new_data' => $snap]);
        $this->mutation($p, $bq, 'SUBMITTED', '1.00');
        $this->actingAs($this->manager);

        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            $this->call(strtoupper($verb), '/approvals')->assertStatus(405);
        }
        $this->post('/approvals/1/approve')->assertNotFound();
        $html = $this->get('/approvals')->assertOk()->getContent();
        $this->assertStringNotContainsString('name="_method"', $html);
        $this->assertDoesNotMatchRegularExpression('#<form[^>]*(approve|revision|verify|activate)#i', $html);
        $this->assertStringNotContainsString('Setujui', $html);

        $show = $this->get(route('projects.show', $p->id))->assertOk();
        foreach (['id="item-bq"', 'id="jadwal"', 'id="penyesuaian"', 'id="relokasi-budget"'] as $anchor) {
            $show->assertSee($anchor, false);
        }
    }

    public function test_html_is_escaped_for_names_and_summaries(): void
    {
        $evil = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => '<b>Evil</b>']);
        $p = $this->project('BM', 'BM-XSS', 'ACTIVE', ['project_name' => '<script>alert(1)</script>']);
        $bq = $this->bq($p, 'SUBMITTED', '1.00', null, '<img src=x onerror=alert(2)>');
        $this->audit('BQ_ITEM', $bq, $evil);

        $res = $this->actingAs($this->manager)->get('/approvals')->assertOk();
        $res->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false)->assertDontSee('<b>Evil</b>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertSee('&lt;b&gt;Evil&lt;/b&gt;', false);
    }

    public function test_pagination_twenty_per_page_without_duplicates(): void
    {
        $p = $this->project('BM', 'BM-PAGE');
        foreach (range(1, 25) as $i) {
            $this->bq($p, 'SUBMITTED', "$i.00", null, sprintf('PAGEITEM%02d', $i));
        }
        $this->actingAs($this->manager);
        $first = $this->get('/approvals')->assertOk();
        $second = $this->get('/approvals?page=2')->assertOk();
        $this->assertCount(20, $first->viewData('items')->items());
        $this->assertCount(5, $second->viewData('items')->items());
        $names = collect([...$first->viewData('items')->items(), ...$second->viewData('items')->items()])->pluck('summary');
        $this->assertCount(25, $names->unique(), 'tidak ada item ganda/hilang lintas halaman');
        $this->assertSame(['bq' => 25], $first->viewData('counts')->all()); // hitungan = total semua halaman
        $first->assertSee('Menunggu keputusan (25)');
        $this->get('/approvals?page=abc')->assertOk();
        $this->get('/approvals?page=-3')->assertOk();
    }

    public function test_pending_items_of_non_decidable_project_status_are_still_listed(): void
    {
        // Dokumentasi perilaku: inbox tidak menyaring status project; detail menolak (403) bila project bukan ACTIVE/COMPLETED. Saat ini tidak ada route yang menciptakan kondisi ini.
        $done = $this->project('BM', 'BM-CANCELLED', 'CANCELLED');
        $this->adjustment($done, 'SUBMITTED', '1.00');
        $this->actingAs($this->manager)->get('/approvals')->assertOk()->assertSee('BM-CANCELLED');
    }
}
