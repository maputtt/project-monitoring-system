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

// Verifikasi independen INV-03 (QA): kunci PAID, siklus revisi pembayaran, atomicity, berkas, dan tampilan.
class InvoicePaymentQaTest extends TestCase
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
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
    }

    private function invoice(string $status, array $extra = []): int
    {
        return DB::table('invoices')->insertGetId($extra + [
            'invoice_number' => 'INV/'.uniqid(), 'billing_date' => '2026-08-20', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500',
            'dpp_amount' => '100.01', 'ppn_amount' => '11.00', 'subtotal_amount' => '111.01', 'pph_amount' => '2.65', 'net_received_amount' => '108.36',
            'submitted_by' => $this->staff->id, 'submitted_at' => now(), 'project_id' => $this->project->id, 'department' => 'BM',
            'sequence_number' => DB::table('invoices')->count() + 1, 'billing_type' => 'TERMIN', 'billing_percentage' => '50.0000',
            'planned_amount' => '111.01', 'workflow_status' => $status, 'created_by' => $this->staff->id,
            'locked_at' => $status === 'PAID' ? now() : null, 'locked_by' => $status === 'PAID' ? $this->manager->id : null,
        ]);
    }

    private function proof(int $id, int $by, string $status = 'UPLOADED'): int
    {
        return DB::table('documents')->insertGetId([
            'project_id' => $this->project->id, 'entity_type' => 'INVOICE', 'entity_id' => $id, 'document_type' => 'BUKTI_BAYAR', 'original_filename' => 'bayar.pdf',
            'storage_key' => "k/$id/".uniqid(), 'mime_type' => 'application/pdf', 'file_size' => 10, 'status' => $status, 'uploaded_by' => $by,
        ]);
    }

    private function pdf(string $name = 'bayar.pdf', int $kb = 10): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    private function row(int $id): array
    {
        return (array) DB::table('invoices')->where('id', $id)->first();
    }

    private function docs(): array
    {
        return DB::table('documents')->orderBy('id')->get()->map(fn ($d) => (array) $d)->all();
    }

    private function audits(): int
    {
        return DB::table('audit_logs')->where('entity_type', 'INVOICE')->count();
    }

    public function test_paid_invoice_is_byte_for_byte_untouched_by_every_command_and_actor(): void
    {
        $id = $this->invoice('PAID');
        $this->proof($id, $this->staff->id, 'VERIFIED');
        $other = User::factory()->role('MANAGER_GM', 'BM')->create();
        $rowBefore = $this->row($id);
        $docsBefore = $this->docs();
        $spoof = ['workflow_status' => 'PAYMENT_REVISION_REQUIRED', 'locked_at' => null, 'locked_by' => null, 'net_received_amount' => '1.00', 'version' => 1];

        // Staff: submit-payment (file valid) dan submit. Manager lain: approve/verify/revision. Semuanya 409.
        $this->actingAs($this->staff)->post("/invoices/$id/submit-payment", $spoof + ['file' => $this->pdf()])->assertStatus(409);
        $this->actingAs($this->staff)->post("/invoices/$id/submit", $spoof + ['invoice_number' => 'Z', 'billing_date' => '2026-08-20', 'due_date' => '2026-09-30', 'pph_qualification' => 'B', 'file' => $this->pdf('i.pdf')])->assertStatus(409);
        $this->actingAs($other)->post("/invoices/$id/approve-payment", $spoof)->assertStatus(409);
        $this->actingAs($other)->post("/invoices/$id/verify", $spoof)->assertStatus(409);
        $this->actingAs($other)->post("/invoices/$id/request-revision", $spoof + ['reason' => 'x'])->assertStatus(409);
        // Pengaju invoice (Manager lewat perubahan peran) ditolak 403 sebelum transisi; tetap tidak ada perubahan.
        DB::table('users')->where('id', $this->staff->id)->update(['role' => 'MANAGER_GM']);
        $this->actingAs($this->staff->fresh())->post("/invoices/$id/approve-payment")->assertForbidden();
        $this->actingAs($this->staff->fresh())->post("/invoices/$id/request-revision", ['reason' => 'x'])->assertForbidden();

        $this->assertSame($rowBefore, $this->row($id));
        $this->assertSame($docsBefore, $this->docs());
        $this->assertSame(0, $this->audits());
        $this->assertSame([], Storage::disk('local')->allFiles());
        // Jaring DB: PAID tanpa kunci ditolak walau lewat SQL mentah (locked_at/locked_by tidak bisa dikosongkan).
        foreach (['locked_at = NULL', 'locked_by = NULL'] as $set) {
            try {
                DB::update("update invoices set $set where id = ?", [$id]);
                $this->fail("CHECK harus menolak $set");
            } catch (QueryException $e) {
                $this->assertStringContainsString('chk_invoices_paid_locked', $e->getMessage());
            }
        }
        $this->assertSame($rowBefore, $this->row($id));
    }

    public function test_manager_who_verified_the_invoice_may_approve_its_payment(): void
    {
        $id = $this->invoice('INVOICE_SUBMITTED');
        $this->actingAs($this->staff)->post("/invoices/$id/submit-payment", ['file' => $this->pdf()])->assertStatus(409); // belum waktunya
        DB::table('documents')->insert(['project_id' => $this->project->id, 'entity_type' => 'INVOICE', 'entity_id' => $id, 'document_type' => 'INVOICE_FILE', 'original_filename' => 'i.pdf',
            'storage_key' => 'k/i', 'mime_type' => 'application/pdf', 'file_size' => 1, 'status' => 'UPLOADED', 'uploaded_by' => $this->staff->id]);

        $this->actingAs($this->manager)->post("/invoices/$id/verify")->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post("/invoices/$id/submit-payment", ['file' => $this->pdf()])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/invoices/$id/approve-payment")->assertSessionHasNoErrors();

        $row = $this->row($id);
        $this->assertSame(['PAID', $this->manager->id, $this->manager->id], [$row['workflow_status'], (int) $row['approved_by'], (int) $row['locked_by']]);
        $this->assertSame(['APPROVE', 'SUBMIT', 'APPROVE', 'LOCK'], DB::table('audit_logs')->where('entity_type', 'INVOICE')->orderBy('id')->pluck('action')->all());
    }

    public function test_two_revision_rounds_decide_only_the_latest_proof_and_keep_the_chain(): void
    {
        $id = $this->invoice('WAITING_FOR_PAYMENT');
        $this->actingAs($this->staff)->post("/invoices/$id/submit-payment", ['file' => $this->pdf('a.pdf')])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/invoices/$id/request-revision", ['reason' => 'Ronde 1'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post("/invoices/$id/submit-payment", ['file' => $this->pdf('b.pdf')])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/invoices/$id/request-revision", ['reason' => 'Ronde 2'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post("/invoices/$id/submit-payment", ['file' => $this->pdf('c.pdf')])->assertSessionHasNoErrors();

        $d = collect($this->docs());
        $this->assertSame(['REPLACED', 'REPLACED', 'UPLOADED'], $d->pluck('status')->all());
        $this->assertSame([null, $d[0]['id'], $d[1]['id']], $d->pluck('replaced_document_id')->all());
        $this->assertCount(3, Storage::disk('local')->allFiles()); // versi lama tetap tersimpan

        $oldA = $d[0];
        $this->actingAs($this->manager)->post("/invoices/$id/approve-payment")->assertSessionHasNoErrors();
        $after = collect($this->docs());
        $this->assertSame(['REPLACED', 'REPLACED', 'VERIFIED'], $after->pluck('status')->all());
        $this->assertSame($oldA, $after[0]); // dokumen REPLACED tidak disentuh sama sekali
        $this->assertSame(['SUBMIT', 'REQUEST_REVISION', 'SUBMIT', 'REQUEST_REVISION', 'SUBMIT', 'APPROVE', 'LOCK'], DB::table('audit_logs')->where('entity_type', 'INVOICE')->orderBy('id')->pluck('action')->all());
        $this->assertSame(['PAID', 7], [$this->row($id)['workflow_status'], (int) $this->row($id)['version']]);
    }

    public function test_failure_on_the_lock_audit_row_rolls_back_the_whole_approval(): void
    {
        $id = $this->invoice('PAYMENT_SUBMITTED');
        $this->proof($id, $this->staff->id);
        $rowBefore = $this->row($id);
        $docsBefore = $this->docs();
        $n = 0;
        DB::listen(function ($q) use (&$n) {
            str_contains($q->sql, 'insert into `audit_logs`') && ++$n === 2 && throw new \RuntimeException('audit LOCK gagal');
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->manager)->post("/invoices/$id/approve-payment");
            $this->fail('Harus gagal.');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit LOCK gagal', $e->getMessage());
        }
        $this->assertSame(2, $n);
        $this->assertSame($rowBefore, $this->row($id));
        $this->assertSame($docsBefore, $this->docs());
        $this->assertSame(0, $this->audits()); // APPROVE yang sudah masuk ikut batal
    }

    public function test_file_validation_edges_leave_no_trace_and_the_limit_is_inclusive(): void
    {
        $id = $this->invoice('WAITING_FOR_PAYMENT');
        $bad = [
            'pdf bernama jpg' => UploadedFile::fake()->create('palsu.jpg', 10, 'application/pdf'),
            '50MB + 1KB' => $this->pdf('besar.pdf', 51201),
        ];
        foreach ($bad as $file) {
            $this->actingAs($this->staff)->post("/invoices/$id/submit-payment", ['file' => $file])->assertSessionHasErrorsIn("payment-$id", 'file');
        }
        // Error tampil di form termin setelah redirect (bag payment-{id}).
        $this->followingRedirects()->actingAs($this->staff)->from("/projects/{$this->project->id}")->post("/invoices/$id/submit-payment", ['file' => $this->pdf('palsu.jpg')])->assertSee('Ekstensi file tidak sesuai dengan isi file.');
        // Array bukan file: 302 + error, bukan 500.
        $this->actingAs($this->staff)->post("/invoices/$id/submit-payment", ['file' => ['x']])->assertSessionHasErrorsIn("payment-$id", 'file');
        $this->assertSame(['WAITING_FOR_PAYMENT', 1, 0, 0, []], [$this->row($id)['workflow_status'], (int) $this->row($id)['version'], $this->audits(), count($this->docs()), Storage::disk('local')->allFiles()]);

        $this->actingAs($this->staff)->post("/invoices/$id/submit-payment", ['file' => $this->pdf('batas.PDF', 51200)])->assertSessionHasNoErrors();
        $this->assertSame('PAYMENT_SUBMITTED', $this->row($id)['workflow_status']);
        $this->assertStringEndsWith('.pdf', $this->docs()[0]['storage_key']); // ekstensi dari MIME, bukan dari nama kiriman
    }

    public function test_only_the_latest_proof_is_linked_served_and_scoped_and_storage_key_is_hidden(): void
    {
        $id = $this->invoice('WAITING_FOR_PAYMENT');
        $this->actingAs($this->staff)->post("/invoices/$id/submit-payment", ['file' => $this->pdf('a.pdf')]);
        $this->actingAs($this->manager)->post("/invoices/$id/request-revision", ['reason' => 'x']);
        $this->actingAs($this->staff)->post("/invoices/$id/submit-payment", ['file' => $this->pdf('b.pdf')]);
        [$old, $new] = [$this->docs()[0], $this->docs()[1]];

        $html = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->getContent();
        // Tautan di kartu termin = bukti terbaru; yang lama hanya muncul di tab Dokumen.
        preg_match('~href="([^"]+)"[^>]*aria-label="Unduh bukti bayar termin~', $html, $m);
        $this->assertSame(url("/documents/{$new['id']}/open"), $m[1] ?? null);
        $this->assertStringNotContainsString($new['storage_key'], $html);
        $this->assertStringNotContainsString($old['storage_key'], $html);

        $signed = $this->actingAs($this->manager)->get("/documents/{$new['id']}/open")->assertRedirect()->headers->get('Location');
        $this->get($signed)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->role('MANAGER_GM', 'CP')->create())->get("/documents/{$new['id']}/open")->assertNotFound();
        $this->actingAs(User::factory()->role('STAFF_PIC', 'CP')->create())->get($signed)->assertNotFound();
    }

    public function test_revision_reason_and_numbers_are_escaped_on_the_page(): void
    {
        $id = $this->invoice('PAYMENT_SUBMITTED', ['invoice_number' => '<img src=x onerror=alert(1)>']);
        $this->proof($id, $this->staff->id);
        $this->actingAs($this->manager)->post("/invoices/$id/request-revision", ['reason' => '<script>alert("x")</script>'])->assertSessionHasNoErrors();

        foreach ([$this->staff, $this->manager] as $user) {
            $html = $this->actingAs($user)->get("/projects/{$this->project->id}")->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert("x")', $html);
            $this->assertStringNotContainsString('<img src=x onerror', $html);
        }
        $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertSee('Alasan revisi: &lt;script&gt;', false);
    }

    public function test_every_role_and_status_renders_the_project_page_without_error(): void
    {
        $this->proof($this->invoice('PAYMENT_SUBMITTED'), $this->staff->id);
        foreach (['PLANNED', 'INVOICE_SUBMITTED', 'INVOICE_REVISION_REQUIRED', 'INVOICE_VERIFIED', 'WAITING_FOR_PAYMENT', 'PAYMENT_REVISION_REQUIRED', 'PAID', 'CANCELLED'] as $s) {
            $this->invoice($s);
        }
        $users = [$this->staff, $this->manager, User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', null)->create()];
        foreach (['ACTIVE', 'COMPLETED', 'FINANCIALLY_CLOSED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            foreach ($users as $u) {
                $this->actingAs($u)->get("/projects/{$this->project->id}")->assertOk();
            }
        }
        // Project selesai/ditutup: tidak ada form/tombol pembayaran lagi untuk siapa pun kecuali ACTIVE/COMPLETED.
        $closed = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->getContent();
        $this->assertStringNotContainsString('-payment', $closed);
    }
}
