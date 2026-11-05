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

// QA independen INV-02: celah yang tidak dicakup InvoiceVerifyTest (siklus revisi penuh, rollback jalur revisi, backstop CHECK, input alasan, render semua status/peran).
class InvoiceVerifyQaTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private Project $project;

    private int $inv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
        $this->inv = $this->invoice(1, 'INVOICE_SUBMITTED');
    }

    private function invoice(int $seq, string $status): int
    {
        $submitted = $status === 'PLANNED' ? [] : ['invoice_number' => "INV/$seq", 'billing_date' => '2026-08-20', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500',
            'dpp_amount' => '100.00', 'ppn_amount' => '11.00', 'subtotal_amount' => '111.00', 'pph_amount' => '3.00', 'net_received_amount' => '108.00', 'submitted_by' => $this->staff->id, 'submitted_at' => now()];
        $id = DB::table('invoices')->insertGetId($submitted + [
            'project_id' => $this->project->id, 'department' => 'BM', 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'billing_percentage' => '50.0000',
            'planned_amount' => '111.00', 'workflow_status' => $status, 'created_by' => $this->staff->id,
        ]);
        $status === 'PLANNED' || DB::table('documents')->insert([
            'project_id' => $this->project->id, 'entity_type' => 'INVOICE', 'entity_id' => $id, 'document_type' => 'INVOICE_FILE', 'original_filename' => 'inv.pdf',
            'storage_key' => "k/$id", 'mime_type' => 'application/pdf', 'file_size' => 10, 'uploaded_by' => $this->staff->id,
        ]);

        return $id;
    }

    private function row(): object
    {
        return DB::table('invoices')->where('id', $this->inv)->first();
    }

    private function docs(): array
    {
        return DB::table('documents')->where('entity_id', $this->inv)->orderBy('id')->get(['status', 'verified_by', 'replaced_document_id'])->map(fn ($d) => (array) $d)->all();
    }

    private function resubmit(string $number): void
    {
        DB::table('tax_rates')->insertOrIgnore([
            ['tax_type' => 'PPN', 'name' => 'PPN 11', 'vendor_qualification' => null, 'rate' => '11.00', 'effective_from' => '2025-01-01', 'created_by' => $this->staff->id],
            ['tax_type' => 'PPH', 'name' => 'PPh B', 'vendor_qualification' => 'B', 'rate' => '2.65', 'effective_from' => '2025-01-01', 'created_by' => $this->staff->id],
        ]);
        $this->actingAs($this->staff)->post("/invoices/{$this->inv}/submit", ['version' => $this->row()->version, 'invoice_number' => $number, 'billing_date' => '2026-08-20', 'due_date' => '2026-09-30', 'pph_qualification' => 'B',
            'file' => UploadedFile::fake()->create('inv.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
    }

    private function act(string $path, array $data = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->manager)->post("/invoices/{$this->inv}/$path", $data);
    }

    public function test_full_cycle_revise_resubmit_verify_touches_only_the_current_file(): void
    {
        $this->act('request-revision', ['reason' => 'Salah nomor'])->assertSessionHasNoErrors();
        $this->resubmit('INV/1-R');
        $this->act('verify')->assertSessionHasNoErrors();

        $row = $this->row();
        $this->assertSame(['WAITING_FOR_PAYMENT', $this->manager->id, 4], [$row->workflow_status, (int) $row->approved_by, (int) $row->version]);
        $docs = $this->docs();
        $this->assertSame(['REPLACED', null], [$docs[0]['status'], $docs[0]['verified_by']]); // file lama tidak disentuh lagi
        $this->assertSame(['VERIFIED', $this->manager->id, 1], [$docs[1]['status'], (int) $docs[1]['verified_by'], (int) $docs[1]['replaced_document_id'] > 0 ? 1 : 0]);
        $this->assertSame(['REQUEST_REVISION', 'SUBMIT', 'APPROVE'], DB::table('audit_logs')->where('entity_type', 'INVOICE')->orderBy('id')->pluck('action')->all());
    }

    public function test_failure_after_the_update_rolls_back_the_revision_path_too(): void
    {
        DB::listen(fn ($query) => str_contains($query->sql, 'insert into `audit_logs`') && throw new \RuntimeException('audit gagal'));
        $this->withoutExceptionHandling();
        try {
            $this->act('request-revision', ['reason' => 'x']);
            $this->fail('Harus gagal.');
        } catch (\RuntimeException) {
        }
        $row = $this->row();
        $this->assertSame(['INVOICE_SUBMITTED', null, null, null, 1, 0, 'UPLOADED'], [$row->workflow_status, $row->revision_reason, $row->revision_requested_by, $row->revision_requested_at, (int) $row->version,
            DB::table('audit_logs')->where('entity_type', 'INVOICE')->count(), $this->docs()[0]['status']]);
    }

    public function test_check_constraint_is_the_backstop_for_self_approval(): void
    {
        $this->expectException(QueryException::class);
        DB::table('invoices')->where('id', $this->inv)->update(['approved_by' => $this->staff->id]);
    }

    public function test_reason_input_edges(): void
    {
        foreach ([['reason' => '   '], ['reason' => ['x']], ['reason' => str_repeat('é', 1001)]] as $bad) {
            $this->act('request-revision', $bad)->assertSessionHasErrors('reason');
        }
        $this->actingAs($this->manager)->postJson("/invoices/{$this->inv}/request-revision", ['reason' => 123])->assertStatus(422);
        $this->assertSame('INVOICE_SUBMITTED', $this->row()->workflow_status);

        $xss = '<script>alert(1)</script> & "q" '.str_repeat('é', 900);
        $this->act('request-revision', ['reason' => $xss, 'version' => 99, 'approved_by' => $this->manager->id])->assertSessionHasNoErrors();
        $this->assertSame(trim($xss), $this->row()->revision_reason);
        $this->assertNull($this->row()->approved_by);
        $html = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_route_edges_do_not_500(): void
    {
        $this->actingAs($this->manager)->get("/invoices/{$this->inv}/verify")->assertStatus(405);
        $this->actingAs($this->manager)->post('/invoices/999999999999999999/verify')->assertNotFound();
        $this->actingAs($this->manager)->post('/invoices/0/verify')->assertNotFound();
        $this->actingAs($this->manager)->post('/invoices/-1/request-revision', ['reason' => 'x'])->assertNotFound();
        $this->actingAs($this->manager)->post('/invoices/abc/verify')->assertNotFound();
        $this->actingAs($this->manager)->postJson("/invoices/{$this->inv}/verify")->assertRedirect(); // JSON client: redirect, no 500
    }

    public function test_project_page_renders_for_every_role_and_invoice_status(): void
    {
        foreach (['PLANNED', 'INVOICE_REVISION_REQUIRED', 'INVOICE_VERIFIED', 'WAITING_FOR_PAYMENT', 'PAYMENT_SUBMITTED', 'PAYMENT_REVISION_REQUIRED', 'CANCELLED'] as $i => $status) {
            $id = $this->invoice($i + 2, $status);
            $status === 'PAID' || DB::table('invoices')->where('id', $id)->update(['revision_reason' => 'r']);
        }
        $roles = [$this->staff, $this->manager, User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', null)->create()];
        foreach ($roles as $user) {
            $html = $this->actingAs($user)->get("/projects/{$this->project->id}")->assertOk()->getContent();
            // hanya INVOICE_SUBMITTED yang boleh punya tombol keputusan
            $this->assertSame($user->is($this->manager) ? 2 : 0, substr_count(str_replace("invoices/{$this->inv}/progress", '', $html), "invoices/{$this->inv}/"), $user->role->value);
            $this->assertStringNotContainsString('/invoices/'.($this->inv + 1).'/verify', $html);
        }
    }
}
