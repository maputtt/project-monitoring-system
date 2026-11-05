<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// BQ-04 QA independen (qa-engineer, 2026-10-15): batas uang, cek ulang di bawah kunci, aturan Nota, input penyalahgunaan, constraint DB, dan render halaman per role.
// Konkurensi paralel sungguhan diuji terpisah di DB scratch dengan dua server (lihat laporan QA).
class BudgetMutationQaTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private Project $project;

    private int $dest = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
        $this->bq('OPERATIONAL', 'APPROVED', '70478866.80', '0.00');
    }

    private function bq(string $type = 'NORMAL', string $status = 'APPROVED', string $budget = '1000.00', string $nego = '800.00', ?int $project = null, ?string $actual = null): int
    {
        return DB::table('bq_items')->insertGetId([
            'project_id' => $project ?? $this->project->id, 'item_name' => "Item $type", 'item_type' => $type, 'status' => $status, 'created_by' => $this->staff->id,
            'baseline_budget' => $budget, 'effective_budget' => $budget, 'negotiated_value' => $nego, 'actual_cost' => $actual, 'margin_amount' => '0.00', 'margin_pct' => '0.0000',
        ]);
    }

    private function mutation(?int $source, int $destination, string $amount, string $status = 'SUBMITTED', ?int $by = null): int
    {
        return DB::table('bq_budget_mutations')->insertGetId([
            'project_id' => $this->project->id, 'source_bq_id' => $source, 'destination_bq_id' => $destination, 'amount' => $amount, 'reason' => 'Geser budget',
            'mutation_type' => $source ? 'TRANSFER_BETWEEN_BQ' : 'FROM_UNALLOCATED_RAB', 'status' => $status, 'submitted_by' => $by ?? $this->staff->id, 'submitted_at' => now(),
        ]);
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['mutation_type' => 'FROM_UNALLOCATED_RAB', 'destination_bq_id' => $this->dest, 'amount' => '100.00', 'reason' => 'Butuh tambahan',
            'file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf')];
    }

    private function row(int $id): object
    {
        return DB::table('bq_items')->find($id);
    }

    public function test_transfer_up_to_exact_sisa_is_allowed_and_one_cent_more_is_not(): void
    {
        $a = $this->bq(budget: '1000.00', nego: '800.00'); // sisa 200,00
        $b = $this->bq(budget: '500.00', nego: '600.00');
        $m = $this->mutation($a, $b, '200.00');
        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertSessionHasNoErrors()->assertStatus(302);
        $ra = $this->row($a);
        $rb = $this->row($b);
        $this->assertSame(['800.00', '0.00', '0.0000', 0], [$ra->effective_budget, $ra->margin_amount, $ra->margin_pct, (int) $ra->is_overbudget]);
        $this->assertSame(['700.00', '100.00', '14.2857', 0], [$rb->effective_budget, $rb->margin_amount, $rb->margin_pct, (int) $rb->is_overbudget]);
        $m2 = $this->mutation($a, $b, '0.01'); // sisa A sekarang 0,00
        $this->actingAs($this->manager)->post("/budget-mutations/$m2/approve")->assertSessionHasErrors('mutation');
        $this->assertSame(['800.00', 'SUBMITTED', 1], [$this->row($a)->effective_budget, DB::table('bq_budget_mutations')->find($m2)->status, DB::table('audit_logs')->where('action', 'APPROVE')->count()]);
    }

    public function test_margin_pct_clamped_when_actual_cost_lets_source_drop_far_below_nego(): void
    {
        $a = $this->bq(budget: '1000.00', nego: '800.00', actual: '0.01'); // sisa 999,99
        $b = $this->bq();
        $m = $this->mutation($a, $b, '999.99');
        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertSessionHasNoErrors()->assertStatus(302);
        $ra = $this->row($a);
        $this->assertSame(['0.01', '-799.99', '-999.9999', 0], [$ra->effective_budget, $ra->margin_amount, $ra->margin_pct, (int) $ra->is_overbudget]); // margin tetap di atas nego; 0,01 > 0,01 tidak
    }

    public function test_operational_item_margin_untouched(): void
    {
        $op = DB::table('bq_items')->where('item_type', 'OPERATIONAL')->value('id');
        $n = $this->bq(budget: '500.00', nego: '400.00');
        $m = $this->mutation($op, $n, '100.00');
        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertSessionHasNoErrors()->assertStatus(302);
        $r = $this->row($op);
        $this->assertSame(['70478766.80', '0.00', '0.0000', 0, 2], [$r->effective_budget, $r->margin_amount, $r->margin_pct, (int) $r->is_overbudget, $r->version]);
        $this->assertSame('600.00', $this->row($n)->effective_budget);
        // ke OPERATIONAL dari sisa RAB
        $m2 = $this->mutation(null, $op, '10.00');
        $this->actingAs($this->manager)->post("/budget-mutations/$m2/approve")->assertSessionHasNoErrors();
    }

    public function test_approve_rechecks_item_status_and_project_membership(): void
    {
        $a = $this->bq();
        $b = $this->bq();
        $m = $this->mutation($a, $b, '10.00');
        DB::table('bq_items')->where('id', $b)->update(['status' => 'ARCHIVED']);
        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertSessionHasErrors('mutation');
        DB::table('bq_items')->where('id', $b)->update(['status' => 'APPROVED']);
        DB::table('bq_items')->where('id', $a)->update(['status' => 'ARCHIVED']);
        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertSessionHasErrors('mutation');
        DB::table('bq_items')->where('id', $a)->update(['status' => 'APPROVED']);
        $other = Project::factory()->create();
        $foreign = $this->bq(project: $other->id);
        DB::table('bq_budget_mutations')->where('id', $m)->update(['destination_bq_id' => $foreign]);
        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertSessionHasErrors('mutation');
        $this->assertSame(['1000.00', '1000.00', 'SUBMITTED', 0], [$this->row($a)->effective_budget, $this->row($foreign)->effective_budget, DB::table('bq_budget_mutations')->find($m)->status, DB::table('audit_logs')->where('action', 'APPROVE')->count()]);
    }

    public function test_unallocated_basis_counts_all_non_archived_items(): void
    {
        // rab 570478866.80, op 70478866.80 approved. Uji coba PM 2026-11-02: DRAFT ikut dihitung, ARCHIVED tidak.
        $big = $this->bq(budget: '499999998.99', status: 'DRAFT');
        $x = $this->bq(budget: '1.00'); // sisa kartu = 0,01
        $this->bq(budget: '123456789.00', status: 'ARCHIVED'); // tidak dihitung
        $m = $this->mutation(null, $x, '0.01');
        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertSessionHasNoErrors();
        $this->assertSame('1.01', $this->row($x)->effective_budget);

        // Sisa kartu kini 0,00: mutasi berikutnya ditolak tanpa mengubah saldo.
        $m2 = $this->mutation(null, $x, '0.01');
        $this->actingAs($this->manager)->post("/budget-mutations/$m2/approve")->assertSessionHasErrors(['mutation' => 'Sisa RAB tidak cukup: tersedia Rp0,00, relokasi Rp0,01.']);
        $this->assertSame('1.01', $this->row($x)->effective_budget);

        // Item besar jadi CLOSED dan lebih kecil 9,00: sisa kartu 8,99 sehingga mutasi lolos.
        DB::table('bq_items')->where('id', $big)->update(['status' => 'CLOSED', 'effective_budget' => '499999990.00']);
        $this->actingAs($this->manager)->post("/budget-mutations/$m2/approve")->assertSessionHasNoErrors();
        $this->assertSame('1.02', $this->row($x)->effective_budget);
    }

    public function test_nota_is_private_random_and_downloadable_via_signed_flow(): void
    {
        $this->dest = $this->bq();
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/budget-mutations", $this->payload(['file' => UploadedFile::fake()->create('Nota Rahasia.pdf', 100, 'application/pdf')]))->assertSessionHasNoErrors();
        $doc = DB::table('documents')->first();
        $this->assertMatchesRegularExpression('#^documents/'.$this->project->id.'/[0-9a-f-]{36}\.pdf$#', $doc->storage_key);
        $this->assertSame(['Nota Rahasia.pdf', 'application/pdf'], [$doc->original_filename, $doc->mime_type]);
        $this->actingAs($this->manager)->get("/documents/{$doc->id}/open")->assertRedirect();
        $this->actingAs($this->manager)->get("/documents/{$doc->id}/download")->assertForbidden(); // tanpa tanda tangan
        $cp = User::factory()->role('MANAGER_GM', 'CP')->create();
        $this->actingAs($cp)->get("/documents/{$doc->id}/open")->assertNotFound();
        auth()->logout();
        $this->get("/documents/{$doc->id}/open")->assertRedirect('/login');
        // halaman project: tautan Nota ada dan bukan URL storage langsung
        $html = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->getContent();
        $this->assertStringContainsString("/documents/{$doc->id}/open", $html);
        $this->assertStringNotContainsString($doc->storage_key, $html);
    }

    public function test_nota_file_rules(): void
    {
        $this->dest = $this->bq();
        $ok = fn ($f) => $this->actingAs($this->staff)->post("/projects/{$this->project->id}/budget-mutations", $this->payload(['file' => $f]));
        $ok(UploadedFile::fake()->create('a.pdf', 51201, 'application/pdf'))->assertSessionHasErrors('file', null, 'mutation-new');
        $ok(UploadedFile::fake()->create('a.txt', 10, 'text/plain'))->assertSessionHasErrors('file', null, 'mutation-new');
        $ok(UploadedFile::fake()->create('a.exe', 10, 'application/x-msdownload'))->assertSessionHasErrors('file', null, 'mutation-new');
        $ok(UploadedFile::fake()->create('a.php.pdf', 10, 'text/x-php'))->assertSessionHasErrors('file', null, 'mutation-new');
        $this->assertSame(0, DB::table('bq_budget_mutations')->count());
        $ok(UploadedFile::fake()->create('a.pdf', 51200, 'application/pdf'))->assertSessionHasNoErrors();
        $ok(UploadedFile::fake()->image('b.jpg'))->assertSessionHasNoErrors();
        $ok(UploadedFile::fake()->image('c.png'))->assertSessionHasNoErrors();
        $this->assertSame([3, 3], [DB::table('bq_budget_mutations')->count(), DB::table('documents')->count()]);
        $this->assertSame(3, count(Storage::disk('local')->allFiles()));
    }

    public function test_abuse_inputs_never_500(): void
    {
        $this->dest = $this->bq();
        $url = "/projects/{$this->project->id}/budget-mutations";
        $cases = [
            'mutation_type[]' => ['mutation_type' => ['x']],
            'destination[]' => ['destination_bq_id' => [1]],
            'source[]' => ['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => [1]],
            'amount[]' => ['amount' => ['1']],
            'reason[]' => ['reason' => ['x']],
            'file string' => ['file' => 'abc'],
            'file[]' => ['file' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]],
            'amount 1e3' => ['amount' => '1e3'],
            'amount 1,000' => ['amount' => '1,000'],
            'amount huge' => ['amount' => '99999999999999999999999'],
            'amount 15 digits + .99' => ['amount' => '999999999999999.99'],
            'dest overflow' => ['destination_bq_id' => '99999999999999999999'],
            'dest 0' => ['destination_bq_id' => '0'],
            'dest negative' => ['destination_bq_id' => '-1'],
            'reason 1001' => ['reason' => str_repeat('x', 1001)],
            'type lowercase' => ['mutation_type' => 'transfer_between_bq'],
            'type REVERSAL' => ['mutation_type' => 'REVERSAL'],
        ];
        foreach ($cases as $name => $extra) {
            $r = $this->actingAs($this->staff)->post($url, $this->payload($extra));
            $this->assertContains($r->getStatusCode(), [302, 422], "$name -> ".$r->getStatusCode());
            if ($r->getStatusCode() === 302) {
                $r2 = $this->actingAs($this->staff)->followingRedirects()->from($url)->post($url, $this->payload($extra));
                $this->assertLessThan(500, $r2->getStatusCode(), "$name follow-up page -> ".$r2->getStatusCode());
            }
        }
        $this->assertSame(0, DB::table('bq_budget_mutations')->count(), 'no row from abuse inputs: '.json_encode(DB::table('bq_budget_mutations')->get()));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_amount_formats_accepted_by_decimal_rule_are_stored_correctly(): void
    {
        $this->dest = $this->bq();
        foreach (['+100' => '100.00', '.5' => '0.50', '50.' => '50.00', '0.01' => '0.01', '12.5' => '12.50', '999999999999999' => '999999999999999.00'] as $in => $out) {
            DB::table('bq_budget_mutations')->delete();
            $r = $this->actingAs($this->staff)->post("/projects/{$this->project->id}/budget-mutations", $this->payload(['amount' => (string) $in]));
            $stored = DB::table('bq_budget_mutations')->value('amount');
            $this->assertSame($out, $stored, "input $in status ".$r->getStatusCode());
        }
    }

    public function test_resubmit_variants(): void
    {
        $a = $this->bq();
        $b = $this->bq();
        $this->dest = $b;
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/budget-mutations", $this->payload(['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a]))->assertSessionHasNoErrors();
        $m = (int) DB::table('bq_budget_mutations')->value('id');
        $nota = DB::table('documents')->value('id');
        $this->actingAs($this->manager)->post("/budget-mutations/$m/request-revision", ['reason' => 'x'])->assertRedirect();
        $this->actingAs($this->manager)->post("/budget-mutations/$m/request-revision", ['reason' => 'x'])->assertStatus(409);
        // tanpa file: Nota lama tetap, ganti jenis ke FROM_UNALLOCATED_RAB: source jadi null
        $p = $this->payload(['amount' => '20.00']);
        unset($p['file']);
        $this->actingAs($this->staff)->put("/budget-mutations/$m", $p)->assertSessionHasNoErrors();
        $row = DB::table('bq_budget_mutations')->find($m);
        $this->assertSame(['SUBMITTED', 'FROM_UNALLOCATED_RAB', null, '20.00'], [$row->status, $row->mutation_type, $row->source_bq_id, $row->amount]);
        $this->assertSame('UPLOADED', DB::table('documents')->find($nota)->status);
        $this->assertSame(1, DB::table('documents')->count());
        $audit = DB::table('audit_logs')->where('action', 'SUBMIT')->orderByDesc('id')->first();
        $this->assertNull(json_decode($audit->data_after, true)['nota_document_id']);
        $this->assertSame('REVISION_REQUIRED', json_decode($audit->data_before, true)['status'] ?? null);
        // revision fields remain (stale) after resubmit
        $this->assertSame('x', DB::table('bq_budget_mutations')->find($m)->revision_reason);
        // staff tak ditugaskan: 404; project DRAFT: 403
        DB::table('bq_budget_mutations')->where('id', $m)->update(['status' => 'REVISION_REQUIRED']);
        $other = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->actingAs($other)->put("/budget-mutations/$m", $this->payload())->assertNotFound();
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'DRAFT']);
        $this->actingAs($this->staff)->put("/budget-mutations/$m", $this->payload())->assertForbidden();
    }

    public function test_request_revision_and_route_edges(): void
    {
        $a = $this->bq();
        $b = $this->bq();
        $m = $this->mutation($a, $b, '10.00');
        $this->actingAs($this->manager)->post("/budget-mutations/$m/request-revision", ['reason' => str_repeat('x', 1001)])->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post("/budget-mutations/$m/request-revision", ['reason' => ['x']])->assertSessionHasErrors('reason');
        $this->assertSame('SUBMITTED', DB::table('bq_budget_mutations')->find($m)->status);
        foreach (['99999999999999999999', '9999999999999999999', '999999999999999999', '0', 'abc', '-1'] as $id) {
            $this->actingAs($this->manager)->post("/budget-mutations/$id/approve")->assertNotFound();
            $this->actingAs($this->manager)->post("/budget-mutations/$id/request-revision", ['reason' => 'x'])->assertNotFound();
            $this->actingAs($this->staff)->put("/budget-mutations/$id", $this->payload())->assertNotFound();
        }
        auth()->logout();
        $this->post("/budget-mutations/$m/approve")->assertRedirect('/login');
        $this->post("/budget-mutations/$m/request-revision")->assertRedirect('/login');
        $this->put("/budget-mutations/$m")->assertRedirect('/login');
        $this->post("/projects/{$this->project->id}/budget-mutations")->assertRedirect('/login');
        // tidak ada route hapus / GET
        $this->actingAs($this->manager)->delete("/budget-mutations/$m")->assertStatus(405);
        $this->actingAs($this->manager)->get("/budget-mutations/$m/approve")->assertStatus(405);
    }

    public function test_db_constraints_hold(): void
    {
        $a = $this->bq();
        $b = $this->bq();
        $m = $this->mutation($a, $b, '10.00');
        foreach ([
            fn () => DB::table('bq_items')->where('id', $a)->update(['effective_budget' => '-0.01']),
            fn () => DB::table('bq_budget_mutations')->where('id', $m)->update(['approved_by' => $this->staff->id]),
            fn () => DB::table('bq_budget_mutations')->where('id', $m)->update(['amount' => '0.00']),
            fn () => DB::table('bq_budget_mutations')->where('id', $m)->update(['source_bq_id' => $b]),
        ] as $i => $f) {
            try {
                $f();
                $this->fail("constraint $i did not fire");
            } catch (QueryException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_readonly_roles_and_all_sections_render_once(): void
    {
        $a = $this->bq();
        $b = $this->bq();
        $this->dest = $b;
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/budget-mutations", $this->payload())->assertSessionHasNoErrors();
        $this->mutation($a, $b, '5.00', 'REVISION_REQUIRED');
        DB::table('bq_budget_mutations')->where('status', 'REVISION_REQUIRED')->update(['revision_reason' => 'Perbaiki <b>ini</b>']);
        $this->mutation($a, $b, '6.00', 'APPROVED', $this->staff->id);
        $this->bq(status: 'DRAFT');
        $this->bq(status: 'SUBMITTED');
        $direksi = User::factory()->role('DIREKSI', null)->create();
        $admin = User::factory()->role('SYSTEM_ADMIN', null)->create();
        $sections = ['id="ringkasan"', 'id="kontrak"', 'id="rincian-bq"', 'id="item-bq"', 'id="relokasi-budget"', 'id="jadwal"', 'id="dokumen"', 'id="tim"'];
        foreach ([$this->staff, $this->manager, $direksi, $admin] as $u) {
            $html = $this->actingAs($u)->get("/projects/{$this->project->id}")->assertOk()->getContent();
            foreach ($sections as $s) {
                $this->assertSame(1, substr_count($html, $s), "{$u->role->value} $s");
            }
            $this->assertStringContainsString('Perbaiki &lt;b&gt;ini&lt;/b&gt;', $html);
            $this->assertSame(1, substr_count($html, '<main'), 'one main');
        }
        foreach ([$direksi, $admin] as $u) {
            $html = $this->actingAs($u)->get("/projects/{$this->project->id}")->getContent();
            foreach (['Setujui mutasi', '+ Ajukan mutasi', '+ Tambah item', '+ Unggah dokumen', 'Perbaiki mutasi'] as $s) {
                $this->assertStringNotContainsString($s, $html, $u->role->value.' sees '.$s);
            }
            $m = DB::table('bq_budget_mutations')->where('status', 'SUBMITTED')->value('id');
            $this->actingAs($u)->post("/budget-mutations/$m/approve")->assertForbidden();
            $this->actingAs($u)->post("/budget-mutations/$m/request-revision", ['reason' => 'x'])->assertForbidden();
            $this->actingAs($u)->post("/projects/{$this->project->id}/budget-mutations", $this->payload())->assertForbidden();
        }
    }
}
