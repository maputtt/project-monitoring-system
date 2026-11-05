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

// INV-03 (PRD 5.5, AC-09/10/11): Staff/PIC mengunggah bukti bayar, Manager/GM (bukan pengunggah) menyetujui → PAID terkunci.
class InvoicePaymentTest extends TestCase
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
        $this->inv = $this->invoice(1, 'WAITING_FOR_PAYMENT');
    }

    private function invoice(int $seq, string $status): int
    {
        $submitted = $status === 'PLANNED' ? [] : ['invoice_number' => "INV/$seq", 'billing_date' => '2026-08-20', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500',
            'dpp_amount' => '100.00', 'ppn_amount' => '11.00', 'subtotal_amount' => '111.00', 'pph_amount' => '3.00', 'net_received_amount' => '108.00', 'submitted_by' => $this->staff->id, 'submitted_at' => now()];
        $paid = $status === 'PAID' ? ['locked_at' => now(), 'locked_by' => $this->manager->id] : [];

        return DB::table('invoices')->insertGetId($submitted + $paid + [
            'project_id' => $this->project->id, 'department' => 'BM', 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'billing_percentage' => '50.0000',
            'planned_amount' => '111.00', 'workflow_status' => $status, 'created_by' => $this->staff->id,
        ]);
    }

    private function proof(int $id, int $by, string $status = 'UPLOADED'): int
    {
        return DB::table('documents')->insertGetId([
            'project_id' => $this->project->id, 'entity_type' => 'INVOICE', 'entity_id' => $id, 'document_type' => 'BUKTI_BAYAR', 'original_filename' => 'bayar.pdf',
            'storage_key' => "k/$id/".uniqid(), 'mime_type' => 'application/pdf', 'file_size' => 10, 'status' => $status, 'uploaded_by' => $by,
        ]);
    }

    private function row(?int $id = null): object
    {
        return DB::table('invoices')->where('id', $id ?? $this->inv)->first();
    }

    private function proofs(?int $id = null)
    {
        return DB::table('documents')->where('entity_type', 'INVOICE')->where('entity_id', $id ?? $this->inv)->where('document_type', 'BUKTI_BAYAR')->orderBy('id')->get();
    }

    private function audits(): int
    {
        return DB::table('audit_logs')->where('entity_type', 'INVOICE')->count();
    }

    private function pay(?User $user = null, ?int $id = null, mixed $file = 'pdf')
    {
        $file = $file === 'pdf' ? UploadedFile::fake()->create('bayar.pdf', 10, 'application/pdf') : $file;

        return $this->actingAs($user ?? $this->staff)->post('/invoices/'.($id ?? $this->inv).'/submit-payment', $file === null ? [] : ['file' => $file]);
    }

    private function approve(?User $user = null, ?int $id = null)
    {
        return $this->actingAs($user ?? $this->manager)->post('/invoices/'.($id ?? $this->inv).'/approve-payment');
    }

    /** Invoice PAYMENT_SUBMITTED dengan bukti bayar dari $by. */
    private function submitted(?int $by = null): int
    {
        $id = $this->invoice(9, 'PAYMENT_SUBMITTED');
        $this->proof($id, $by ?? $this->staff->id);

        return $id;
    }

    private function untouched(): void
    {
        $this->assertSame(['WAITING_FOR_PAYMENT', 1, 0, 0], [$this->row()->workflow_status, (int) $this->row()->version, $this->audits(), $this->proofs()->count()]);
    }

    public function test_submit_payment_stores_the_proof_and_moves_to_payment_submitted(): void
    {
        $this->pay()->assertRedirect("/projects/{$this->project->id}")->assertSessionHasNoErrors();

        $row = $this->row();
        $this->assertSame(['PAYMENT_SUBMITTED', 2, $this->staff->id], [$row->workflow_status, (int) $row->version, (int) $row->submitted_by]); // submitted_by tetap pengaju invoice
        $doc = $this->proofs()->sole();
        $this->assertSame(['UPLOADED', $this->staff->id, null], [$doc->status, (int) $doc->uploaded_by, $doc->replaced_document_id]);
        Storage::disk('local')->assertExists($doc->storage_key);

        $audit = DB::table('audit_logs')->where('entity_type', 'INVOICE')->sole();
        $this->assertSame(['SUBMIT', $this->staff->id, 'WAITING_FOR_PAYMENT', 'PAYMENT_SUBMITTED', $doc->id],
            [$audit->action, (int) $audit->user_id, json_decode($audit->data_before)->workflow_status, json_decode($audit->data_after)->workflow_status, json_decode($audit->data_after)->payment_document_id]);
        $this->assertSame('108.00', $row->net_received_amount); // uang tidak disentuh
    }

    public function test_file_is_required_and_validated(): void
    {
        $this->pay(file: null)->assertSessionHasErrorsIn("payment-{$this->inv}", 'file');
        foreach ([
            'txt' => UploadedFile::fake()->create('catatan.txt', 10, 'text/plain'), 'exe' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
            'besar' => UploadedFile::fake()->create('besar.pdf', 51201, 'application/pdf'), 'pdf bernama png' => UploadedFile::fake()->create('palsu.png', 10, 'application/pdf'),
        ] as $label => $bad) {
            $this->pay(file: $bad)->assertSessionHasErrorsIn("payment-{$this->inv}", 'file', $label);
        }
        $this->untouched();
    }

    public function test_approve_payment_marks_paid_and_locked_with_audit_approve_and_lock(): void
    {
        $id = $this->submitted();
        $this->approve(id: $id)->assertRedirect("/projects/{$this->project->id}")->assertSessionHasNoErrors();

        $row = $this->row($id);
        $this->assertSame(['PAID', $this->manager->id, 2], [$row->workflow_status, (int) $row->locked_by, (int) $row->version]);
        $this->assertNotNull($row->locked_at);
        $this->assertSame(['VERIFIED', $this->manager->id], [$this->proofs($id)->sole()->status, (int) $this->proofs($id)->sole()->verified_by]);
        $this->assertSame('108.00', $row->net_received_amount);

        $audits = DB::table('audit_logs')->where('entity_type', 'INVOICE')->orderBy('id')->get();
        $this->assertSame([['APPROVE', $this->manager->id], ['LOCK', $this->manager->id]], $audits->map(fn ($a) => [$a->action, (int) $a->user_id])->all());
        $this->assertSame(['PAYMENT_SUBMITTED', 'PAID', $this->manager->id], [json_decode($audits[0]->data_before)->workflow_status, json_decode($audits[0]->data_after)->workflow_status, json_decode($audits[1]->data_after)->locked_by]);
    }

    public function test_paid_invoice_is_locked_every_command_answers_409(): void
    {
        $id = $this->invoice(2, 'PAID');
        $this->proof($id, $this->staff->id, 'VERIFIED');
        $this->pay(id: $id)->assertStatus(409);
        $this->approve(id: $id)->assertStatus(409);
        $this->actingAs($this->manager)->post("/invoices/$id/verify")->assertStatus(409);
        $this->actingAs($this->manager)->post("/invoices/$id/request-revision", ['reason' => 'x'])->assertStatus(409);
        $this->actingAs($this->staff)->post("/invoices/$id/submit", ['version' => 1, 'invoice_number' => 'X', 'billing_date' => '2026-08-20', 'due_date' => '2026-09-30', 'pph_qualification' => 'B',
            'file' => UploadedFile::fake()->create('i.pdf', 1, 'application/pdf')])->assertStatus(409);

        $this->assertSame(['PAID', 1, 0, 1], [$this->row($id)->workflow_status, (int) $this->row($id)->version, $this->audits(), $this->proofs($id)->count()]);
        // Tidak ada route edit/hapus untuk invoice: hanya GET tax-preview dan POST perintah.
        $this->assertSame([], collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'invoices/') && array_intersect($r->methods(), ['PUT', 'PATCH', 'DELETE']))->map->uri()->values()->all());
    }

    public function test_revision_returns_the_proof_and_resubmit_replaces_it(): void
    {
        $id = $this->submitted();
        $old = $this->proofs($id)->sole()->id;
        $this->actingAs($this->manager)->post("/invoices/$id/request-revision", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame('PAYMENT_SUBMITTED', $this->row($id)->workflow_status);

        $this->actingAs($this->manager)->post("/invoices/$id/request-revision", ['reason' => 'Bukti buram'])->assertSessionHasNoErrors();
        $row = $this->row($id);
        $this->assertSame(['PAYMENT_REVISION_REQUIRED', 'Bukti buram', 2], [$row->workflow_status, $row->revision_reason, (int) $row->version]);
        $this->assertSame('REVISION_REQUIRED', $this->proofs($id)->sole()->status);
        $audit = DB::table('audit_logs')->where('entity_type', 'INVOICE')->sole();
        $this->assertSame(['REQUEST_REVISION', ['PAYMENT_SUBMITTED', 'PAYMENT_REVISION_REQUIRED']], [$audit->action, json_decode($audit->data_after)->status_path]);

        $this->pay(id: $id)->assertSessionHasNoErrors();
        $this->assertSame('PAYMENT_SUBMITTED', $this->row($id)->workflow_status);
        $docs = $this->proofs($id);
        $this->assertSame(['REPLACED', 'UPLOADED'], $docs->pluck('status')->all());
        $this->assertSame($old, (int) $docs[1]->replaced_document_id);
        Storage::disk('local')->assertExists($docs[1]->storage_key);
        $this->approve(id: $id)->assertSessionHasNoErrors();
        $this->assertSame('PAID', $this->row($id)->workflow_status);
    }

    public function test_payment_submitter_cannot_approve_or_request_revision_even_as_a_manager(): void
    {
        $id = $this->submitted($this->manager->id); // mis. peran diubah setelah mengunggah
        $this->approve(id: $id)->assertForbidden();
        $this->actingAs($this->manager)->post("/invoices/$id/request-revision", ['reason' => 'x'])->assertForbidden();
        $this->assertSame(['PAYMENT_SUBMITTED', 1, 0], [$this->row($id)->workflow_status, (int) $this->row($id)->version, $this->audits()]);

        // Pengaju invoice juga tidak boleh.
        DB::table('invoices')->where('id', $id)->update(['submitted_by' => $this->manager->id]);
        DB::table('documents')->where('entity_id', $id)->update(['uploaded_by' => $this->staff->id]);
        $this->approve(id: $id)->assertForbidden();

        // Manager lain boleh.
        DB::table('invoices')->where('id', $id)->update(['submitted_by' => $this->staff->id]);
        $this->approve(User::factory()->role('MANAGER_GM', 'BM')->create(), $id)->assertSessionHasNoErrors();
        $this->assertSame('PAID', $this->row($id)->workflow_status);
    }

    public function test_roles_scope_unknown_ids_and_guest(): void
    {
        $id = $this->submitted();
        $blocked = [
            [$this->staff, 403], [User::factory()->role('DIREKSI', null)->create(), 403], [User::factory()->role('SYSTEM_ADMIN', null)->create(), 403],
            [User::factory()->role('MANAGER_GM', 'CP')->create(), 404], [User::factory()->role('STAFF_PIC', 'CP')->create(), 404],
        ];
        foreach ($blocked as [$user, $code]) {
            $this->approve($user, $id)->assertStatus($code);
        }
        // Manager tidak boleh mengunggah bukti bayar; staff lain divisi / tanpa assignment tidak melihatnya.
        foreach ([[$this->manager, 403], [User::factory()->role('DIREKSI', null)->create(), 403], [User::factory()->role('STAFF_PIC', 'CP')->create(), 404], [User::factory()->role('STAFF_PIC', 'BM')->create(), 404]] as [$user, $code]) {
            $this->pay($user)->assertStatus($code);
        }
        $this->actingAs($this->manager)->post('/invoices/999999/approve-payment')->assertNotFound();
        $this->actingAs($this->manager)->post('/invoices/99999999999999999999/approve-payment')->assertNotFound();
        $this->actingAs($this->staff)->post('/invoices/99999999999999999999/submit-payment')->assertNotFound();
        $this->assertSame(['PAYMENT_SUBMITTED', 1, 0], [$this->row($id)->workflow_status, (int) $this->row($id)->version, $this->audits()]);
        $this->assertSame(0, $this->proofs()->count());

        $this->app['auth']->forgetGuards();
        $this->post("/invoices/$id/approve-payment")->assertRedirect('/login');
        $this->post("/invoices/{$this->inv}/submit-payment")->assertRedirect('/login');
        $this->assertSame('PAYMENT_SUBMITTED', $this->row($id)->workflow_status);
    }

    public function test_invalid_transitions_are_409_without_side_effects(): void
    {
        $seq = 3;
        foreach (['PLANNED', 'INVOICE_SUBMITTED', 'INVOICE_REVISION_REQUIRED', 'INVOICE_VERIFIED', 'PAYMENT_SUBMITTED', 'CANCELLED'] as $status) {
            $id = $this->invoice($seq++, $status);
            $this->pay(id: $id)->assertStatus(409);
            $this->assertSame([$status, 1], [$this->row($id)->workflow_status, (int) $this->row($id)->version], $status);
        }
        foreach (['PLANNED', 'INVOICE_SUBMITTED', 'INVOICE_REVISION_REQUIRED', 'INVOICE_VERIFIED', 'WAITING_FOR_PAYMENT', 'PAYMENT_REVISION_REQUIRED', 'CANCELLED'] as $status) {
            $id = $this->invoice($seq++, $status);
            $this->approve(id: $id)->assertStatus(409);
            $this->assertSame([$status, 1], [$this->row($id)->workflow_status, (int) $this->row($id)->version], $status);
        }
        $this->assertSame(0, $this->audits());
        $this->assertSame(0, DB::table('documents')->count());
    }

    public function test_project_must_be_active_or_completed(): void
    {
        $id = $this->submitted();
        foreach (['DRAFT', 'SUBMITTED', 'FINANCIALLY_CLOSED', 'ARCHIVED', 'CANCELLED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            $this->pay()->assertForbidden();
            $this->approve(id: $id)->assertForbidden();
        }
        $this->untouchedAfterGate($id);
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED']);
        $this->pay()->assertSessionHasNoErrors();
        $this->approve(id: $id)->assertSessionHasNoErrors();
        $this->assertSame(['PAYMENT_SUBMITTED', 'PAID'], [$this->row()->workflow_status, $this->row($id)->workflow_status]);
    }

    private function untouchedAfterGate(int $id): void
    {
        $this->assertSame(['WAITING_FOR_PAYMENT', 'PAYMENT_SUBMITTED', 0, 0], [$this->row()->workflow_status, $this->row($id)->workflow_status, $this->audits(), $this->proofs()->count()]);
    }

    public function test_failure_after_the_update_rolls_back_rows_and_leaves_no_orphan_file(): void
    {
        DB::listen(fn ($query) => str_contains($query->sql, 'insert into `audit_logs`') && throw new \RuntimeException('audit gagal'));
        $this->withoutExceptionHandling();
        try {
            $this->pay();
            $this->fail('Harus gagal.');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit gagal', $e->getMessage());
        }
        $this->untouched();
        $this->assertSame([], Storage::disk('local')->allFiles());

        $id = $this->submitted();
        try {
            $this->approve(id: $id);
            $this->fail('Harus gagal.');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit gagal', $e->getMessage());
        }
        $row = $this->row($id);
        $this->assertSame(['PAYMENT_SUBMITTED', 1, null, 'UPLOADED'], [$row->workflow_status, (int) $row->version, $row->locked_at, $this->proofs($id)->sole()->status]);
    }

    public function test_paid_invoice_cannot_exist_without_a_lock_even_by_raw_sql(): void
    {
        $this->expectException(QueryException::class);
        DB::table('invoices')->where('id', $this->inv)->update(['workflow_status' => 'PAID']); // chk_invoices_paid_locked
    }

    public function test_page_shows_forms_buttons_proof_link_reason_and_paid_badge(): void
    {
        $page = "/projects/{$this->project->id}";
        $submitted = $this->submitted();
        $own = $this->invoice(4, 'PAYMENT_SUBMITTED');
        $this->proof($own, $this->manager->id);
        $revision = $this->invoice(5, 'PAYMENT_REVISION_REQUIRED');
        DB::table('invoices')->where('id', $revision)->update(['revision_reason' => 'Nominal tidak cocok']);
        $this->proof($revision, $this->staff->id, 'REVISION_REQUIRED');
        $paid = $this->invoice(6, 'PAID');

        $staff = $this->actingAs($this->staff)->get($page)->assertOk()->assertSee('Alasan revisi: Nominal tidak cocok')->assertSee('Lunas, terkunci')->getContent();
        foreach (["invoices/{$this->inv}/submit-payment", "invoices/$revision/submit-payment"] as $present) {
            $this->assertStringContainsString($present, $staff, $present);
        }
        foreach (["invoices/$submitted/submit-payment", "invoices/$paid/submit-payment", '/approve-payment'] as $absent) {
            $this->assertStringNotContainsString($absent, $staff, $absent);
        }
        $this->assertStringContainsString('/documents/'.$this->proofs($submitted)->sole()->id.'/open', $staff);

        $manager = $this->actingAs($this->manager)->get($page)->assertOk()->assertSee('Pembayaran diajukan oleh Anda, jadi tidak bisa Anda setujui.')->getContent();
        $this->assertStringContainsString("invoices/$submitted/approve-payment", $manager);
        $this->assertStringContainsString("invoices/$submitted/request-revision", $manager);
        foreach (["invoices/$own/", "invoices/$paid/approve-payment", "invoices/$paid/request-revision", '/submit-payment'] as $absent) {
            $this->assertStringNotContainsString($absent, $manager, $absent);
        }
        foreach (['DIREKSI', 'SYSTEM_ADMIN'] as $role) {
            $html = $this->actingAs(User::factory()->role($role, null)->create())->get($page)->assertOk()->getContent();
            $this->assertStringNotContainsString('-payment', $html, $role);
        }
    }
}
