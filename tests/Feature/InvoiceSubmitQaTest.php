<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Verifikasi independen INV-01 (QA): celah yang tidak dicakup InvoiceSubmitTest. Angka dihitung tangan, bukan disalin dari kode.
class InvoiceSubmitQaTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
        foreach ([['PPN', null, '11.00'], ['PPH', 'A', '1.75'], ['PPH', 'B', '2.65']] as [$type, $q, $rate]) {
            DB::table('tax_rates')->insert(['tax_type' => $type, 'name' => "$type $rate", 'vendor_qualification' => $q, 'rate' => $rate, 'effective_from' => '2025-01-01', 'created_by' => $this->staff->id]);
        }
    }

    private function invoice(string $pct, int $seq, ?Project $project = null): int
    {
        $project ??= $this->project;

        return DB::table('invoices')->insertGetId([
            'project_id' => $project->id, 'department' => $project->department->value, 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'billing_percentage' => $pct,
            'planned_amount' => '1.00', 'workflow_status' => 'PLANNED', 'created_by' => $this->staff->id,
        ]);
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['version' => 1, 'invoice_number' => 'INV-1', 'billing_date' => '2026-08-20', 'due_date' => '2026-09-30', 'pph_qualification' => 'B', 'file' => UploadedFile::fake()->create('i.pdf', 10, 'application/pdf')];
    }

    private function submit(int $id, array $extra = [])
    {
        return $this->actingAs($this->staff)->post("/invoices/$id/submit", $this->payload($extra));
    }

    private function row(int $id): object
    {
        return DB::table('invoices')->where('id', $id)->first();
    }

    public function test_three_unequal_termins_sum_exactly_and_match_the_hand_calculation(): void
    {
        // Kontrak 100.000,01 EXCLUSIVE: 33,3333% x 100000,01 = 33333,30333 -> 33333,30 (termin 1 dan 2); termin 3 = 100000,01 - 66666,60 = 33333,41 (naif 33333,40: selisih satu sen).
        DB::table('projects')->where('id', $this->project->id)->update(['contract_tax_mode' => 'EXCLUSIVE_PPN', 'contract_value' => '100000.01', 'initial_contract_value' => '100000.01', 'margin_base_ppn_rate_snapshot' => '11.0000']);
        $ids = [$this->invoice('33.3333', 1), $this->invoice('33.3333', 2), $this->invoice('33.3334', 3)];

        foreach ($ids as $i => $id) {
            $this->submit($id, ['invoice_number' => "INV-$i"])->assertSessionHasNoErrors();
        }
        $dpp = array_map(fn ($id) => $this->row($id)->dpp_amount, $ids);
        $this->assertSame(['33333.30', '33333.30', '33333.41'], $dpp);
        $this->assertSame('100000.01', bcadd(bcadd($dpp[0], $dpp[1], 2), $dpp[2], 2));
        // Termin 1: PPN 11% = 3666,663 -> 3666,66; PPh 2,65% = 883,33245 -> 883,33.
        $one = $this->row($ids[0]);
        $this->assertSame(['3666.66', '36999.96', '883.33', '36116.63'], [$one->ppn_amount, $one->subtotal_amount, $one->pph_amount, $one->net_received_amount]);
    }

    public function test_preview_equals_what_submit_stores_in_every_tax_mode_and_qualification(): void
    {
        foreach (['INCLUSIVE_PPN', 'EXCLUSIVE_PPN', 'NON_PPN'] as $mode) {
            foreach (['A', 'B'] as $q) {
                $id = $this->invoice('50.0000', 1, $project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']));
                $this->invoice('50.0000', 2, $project);
                DB::table('projects')->where('id', $project->id)->update(['contract_tax_mode' => $mode, 'contract_value' => '987654321.99', 'initial_contract_value' => '987654321.99'] + ($mode === 'EXCLUSIVE_PPN' ? ['margin_base_ppn_rate_snapshot' => '11.0000'] : []));

                $preview = $this->actingAs($this->staff)->getJson("/invoices/$id/tax-preview?billing_date=2026-08-20&pph_qualification=$q")->assertOk()->json();
                $this->submit($id, ['pph_qualification' => $q, 'invoice_number' => "M-$mode-$q"])->assertSessionHasNoErrors();
                $row = $this->row($id);

                $this->assertSame($preview, [
                    'ppn_rate' => $row->ppn_rate_snapshot, 'pph_rate' => $row->pph_rate_snapshot, 'dpp_amount' => $row->dpp_amount, 'ppn_amount' => $row->ppn_amount,
                    'subtotal_amount' => $row->subtotal_amount, 'pph_amount' => $row->pph_amount, 'net_received_amount' => $row->net_received_amount,
                ], "$mode / $q");
                $this->assertSame(bcsub($row->subtotal_amount, $row->pph_amount, 2), $row->net_received_amount);
                $mode === 'NON_PPN' ? $this->assertSame(['0.00', '0.0000', null], [$row->ppn_amount, $row->ppn_rate_snapshot, $row->ppn_tax_rate_id]) : $this->assertNotNull($row->ppn_tax_rate_id);
            }
        }
    }

    public function test_pph_tax_rate_id_wins_over_a_conflicting_qualification(): void
    {
        $id = $this->invoice('100.0000', 1);
        $pphA = DB::table('tax_rates')->where('tax_type', 'PPH')->where('vendor_qualification', 'A')->value('id');

        $this->submit($id, ['pph_tax_rate_id' => $pphA, 'pph_qualification' => 'B'])->assertSessionHasNoErrors();
        $this->assertSame('1.7500', $this->row($id)->pph_rate_snapshot);
    }

    public function test_invoice_number_uniqueness_is_case_insensitive_friendly_422_and_does_not_block_the_own_row(): void
    {
        $one = $this->invoice('50.0000', 1);
        $two = $this->invoice('50.0000', 2);
        $this->submit($one, ['invoice_number' => '  INV/Ab-1  '])->assertSessionHasNoErrors();
        $this->assertSame('INV/Ab-1', $this->row($one)->invoice_number); // spasi dipangkas

        $this->submit($two, ['invoice_number' => 'inv/ab-1'])->assertStatus(302)->assertSessionHasErrors('invoice_number', errorBag: "invoice-$two");
        $this->assertSame('PLANNED', $this->row($two)->workflow_status);

        // Pengajuan ulang dengan nomor yang sama pada baris sendiri bukan duplikat.
        DB::table('invoices')->where('id', $one)->update(['workflow_status' => 'INVOICE_REVISION_REQUIRED']);
        $this->submit($one, ['invoice_number' => 'INV/Ab-1', 'version' => 2])->assertSessionHasNoErrors();
        $this->assertSame(['INVOICE_SUBMITTED', 3], [$this->row($one)->workflow_status, (int) $this->row($one)->version]);
    }

    public function test_malformed_input_never_gives_a_500(): void
    {
        $id = $this->invoice('100.0000', 1);
        $cases = [
            ['billing_date' => ['2026-08-20']], ['billing_date' => '99999-01-01'], ['billing_date' => '0000-00-00'],
            ['invoice_number' => ['x']], ['bapp_number' => ['x']], ['version' => 'abc'], ['version' => '1.5'], ['version' => '99999999999999999999999'],
            ['pph_qualification' => ['A']], ['pph_qualification' => null, 'pph_tax_rate_id' => ['1']], ['pph_qualification' => null, 'pph_tax_rate_id' => '99999999999999999999'],
            ['file' => [UploadedFile::fake()->create('i.pdf', 10, 'application/pdf')]], ['file' => 'not-a-file'],
        ];
        foreach ($cases as $n => $extra) {
            $status = $this->actingAs($this->staff)->post("/invoices/$id/submit", $this->payload($extra))->getStatusCode();
            $this->assertContains($status, [302, 409, 422], "case $n gave $status");
            $this->assertSame('PLANNED', $this->row($id)->workflow_status, "case $n changed the row");
        }
        $this->assertSame([0, []], [DB::table('audit_logs')->where('entity_type', 'INVOICE')->count(), Storage::disk('local')->allFiles()]);
    }

    public function test_resubmit_failure_keeps_the_old_file_and_status_untouched(): void
    {
        $id = $this->invoice('100.0000', 1);
        $this->submit($id)->assertSessionHasNoErrors();
        DB::table('invoices')->where('id', $id)->update(['workflow_status' => 'INVOICE_REVISION_REQUIRED']);
        $before = [(array) $this->row($id), DB::table('documents')->get()->all(), Storage::disk('local')->allFiles(), DB::table('audit_logs')->count()];

        DB::listen(fn ($q) => str_contains($q->sql, 'insert into `audit_logs`') && throw new \RuntimeException('audit gagal'));
        $this->withoutExceptionHandling();
        try {
            $this->submit($id, ['version' => 2, 'invoice_number' => 'INV-NEW']);
            $this->fail('Harus gagal.');
        } catch (\RuntimeException) {
        }

        $this->assertEquals($before, [(array) $this->row($id), DB::table('documents')->get()->all(), Storage::disk('local')->allFiles(), DB::table('audit_logs')->count()]);
    }

    public function test_invoice_file_opens_through_the_signed_flow_for_the_division_only(): void
    {
        $id = $this->invoice('100.0000', 1);
        $this->submit($id)->assertSessionHasNoErrors();
        $doc = DB::table('documents')->where('entity_type', 'INVOICE')->sole();
        $this->assertMatchesRegularExpression('#^documents/\d+/[0-9a-f-]{36}\.pdf$#', $doc->storage_key);

        $location = $this->actingAs(User::factory()->role('MANAGER_GM', 'BM')->create())->get("/documents/{$doc->id}/open")->assertRedirect()->headers->get('Location');
        $this->get($location)->assertOk();
        $this->actingAs(User::factory()->role('MANAGER_GM', 'CP')->create())->get("/documents/{$doc->id}/open")->assertNotFound();
    }

    public function test_stored_invoice_number_is_escaped_on_the_project_page(): void
    {
        $id = $this->invoice('100.0000', 1);
        $this->submit($id, ['invoice_number' => '<script>alert(1)</script>'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_every_role_and_status_renders_the_project_page_without_500(): void
    {
        $id = $this->invoice('100.0000', 1);
        $users = [$this->staff, User::factory()->role('MANAGER_GM', 'BM')->create(), User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', null)->create()];
        foreach (['PLANNED', 'INVOICE_SUBMITTED', 'INVOICE_REVISION_REQUIRED'] as $status) {
            if ($status === 'PLANNED') {
                $this->submit($id)->assertSessionHasNoErrors();
            }
            DB::table('invoices')->where('id', $id)->update(['workflow_status' => $status, 'revision_reason' => $status === 'INVOICE_REVISION_REQUIRED' ? '<i>alasan</i>' : null]);
            foreach ($users as $user) {
                $this->actingAs($user)->get("/projects/{$this->project->id}")->assertOk();
            }
        }
    }
}
