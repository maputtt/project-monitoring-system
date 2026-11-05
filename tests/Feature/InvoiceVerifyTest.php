<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// INV-02 (PRD 5.5, AC-07, AC-08): Manager/GM memverifikasi invoice atau meminta revisi; pengaju tidak boleh memutuskan invoice sendiri.
// Jalur PAYMENT_SUBMITTED → PAYMENT_REVISION_REQUIRED diuji di InvoicePaymentTest (INV-03).
class InvoiceVerifyTest extends TestCase
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

    private function invoice(int $seq, string $status, ?int $by = null): int
    {
        $submitted = $status === 'PLANNED' ? [] : ['invoice_number' => "INV/$seq", 'billing_date' => '2026-08-20', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500',
            'dpp_amount' => '100.00', 'ppn_amount' => '11.00', 'subtotal_amount' => '111.00', 'pph_amount' => '3.00', 'net_received_amount' => '108.00', 'submitted_by' => $by ?? $this->staff->id, 'submitted_at' => now()];
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

    private function row(?int $id = null): object
    {
        return DB::table('invoices')->where('id', $id ?? $this->inv)->first();
    }

    private function doc(?int $id = null): object
    {
        return DB::table('documents')->where('entity_type', 'INVOICE')->where('entity_id', $id ?? $this->inv)->sole();
    }

    private function audits(): int
    {
        return DB::table('audit_logs')->where('entity_type', 'INVOICE')->count();
    }

    private function verify(?User $user = null, ?int $id = null)
    {
        return $this->actingAs($user ?? $this->manager)->post('/invoices/'.($id ?? $this->inv).'/verify');
    }

    private function revise(array $data = ['reason' => 'Nomor salah'], ?User $user = null, ?int $id = null)
    {
        return $this->actingAs($user ?? $this->manager)->post('/invoices/'.($id ?? $this->inv).'/request-revision', $data);
    }

    private function untouched(): void
    {
        $this->assertSame(['INVOICE_SUBMITTED', null, null, 1, 0, 'UPLOADED'], [$this->row()->workflow_status, $this->row()->approved_by, $this->row()->revision_reason, (int) $this->row()->version, $this->audits(), $this->doc()->status]);
    }

    public function test_verify_ends_at_waiting_for_payment_passing_through_verified_in_the_audit(): void
    {
        $this->verify()->assertRedirect("/projects/{$this->project->id}")->assertSessionHasNoErrors();

        $row = $this->row();
        $this->assertSame(['WAITING_FOR_PAYMENT', $this->manager->id, 2], [$row->workflow_status, (int) $row->approved_by, (int) $row->version]);
        $this->assertNotNull($row->approved_at);
        $this->assertSame(['VERIFIED', $this->manager->id], [$this->doc()->status, (int) $this->doc()->verified_by]);

        $audit = DB::table('audit_logs')->where('entity_type', 'INVOICE')->sole();
        $after = json_decode($audit->data_after);
        $this->assertSame(['APPROVE', $this->manager->id, 'INVOICE_SUBMITTED', 'WAITING_FOR_PAYMENT', ['INVOICE_SUBMITTED', 'INVOICE_VERIFIED', 'WAITING_FOR_PAYMENT']],
            [$audit->action, (int) $audit->user_id, json_decode($audit->data_before)->workflow_status, $after->workflow_status, $after->status_path]);
        $this->assertSame('108.00', $this->row()->net_received_amount); // uang tidak disentuh
    }

    public function test_request_revision_needs_a_reason_then_returns_the_invoice_and_resubmit_works(): void
    {
        foreach ([[], ['reason' => ''], ['reason' => str_repeat('a', 1001)]] as $bad) {
            $this->revise($bad)->assertSessionHasErrors('reason');
        }
        $this->untouched();

        $this->revise()->assertRedirect("/projects/{$this->project->id}")->assertSessionHasNoErrors();
        $row = $this->row();
        $this->assertSame(['INVOICE_REVISION_REQUIRED', 'Nomor salah', $this->manager->id, null, 2], [$row->workflow_status, $row->revision_reason, (int) $row->revision_requested_by, $row->approved_by, (int) $row->version]);
        $this->assertNotNull($row->revision_requested_at);
        $this->assertSame('REVISION_REQUIRED', $this->doc()->status);
        $audit = DB::table('audit_logs')->where('entity_type', 'INVOICE')->sole();
        $this->assertSame(['REQUEST_REVISION', 'Nomor salah', ['INVOICE_SUBMITTED', 'INVOICE_REVISION_REQUIRED']], [$audit->action, $audit->reason, json_decode($audit->data_after)->status_path]);

        // INV-01: Staff/PIC memperbaiki dan mengajukan ulang; file lama menjadi REPLACED.
        DB::table('tax_rates')->insert([
            ['tax_type' => 'PPN', 'name' => 'PPN 11', 'vendor_qualification' => null, 'rate' => '11.00', 'effective_from' => '2025-01-01', 'created_by' => $this->staff->id],
            ['tax_type' => 'PPH', 'name' => 'PPh B', 'vendor_qualification' => 'B', 'rate' => '2.65', 'effective_from' => '2025-01-01', 'created_by' => $this->staff->id],
        ]);
        $this->actingAs($this->staff)->post("/invoices/{$this->inv}/submit", ['version' => 2, 'invoice_number' => 'INV/1-R', 'billing_date' => '2026-08-20', 'due_date' => '2026-09-30', 'pph_qualification' => 'B',
            'file' => UploadedFile::fake()->create('inv.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
        $this->assertSame('INVOICE_SUBMITTED', $this->row()->workflow_status);
        $this->assertSame(['REPLACED', 'UPLOADED'], DB::table('documents')->where('entity_id', $this->inv)->orderBy('id')->pluck('status')->all());
    }

    public function test_submitter_cannot_decide_even_as_a_manager(): void
    {
        DB::table('invoices')->where('id', $this->inv)->update(['submitted_by' => $this->manager->id]);
        $this->verify()->assertForbidden();
        $this->revise()->assertForbidden();
        $row = $this->row();
        $this->assertSame(['INVOICE_SUBMITTED', null, null, 1, 0], [$row->workflow_status, $row->approved_by, $row->revision_reason, (int) $row->version, $this->audits()]);
    }

    public function test_roles_scope_unknown_ids_and_guest(): void
    {
        $blocked = [
            [$this->staff, 403], [User::factory()->role('DIREKSI', null)->create(), 403], [User::factory()->role('SYSTEM_ADMIN', null)->create(), 403],
            [User::factory()->role('MANAGER_GM', 'CP')->create(), 404], [User::factory()->role('STAFF_PIC', 'CP')->create(), 404],
        ];
        foreach ($blocked as [$user, $code]) {
            $this->verify($user)->assertStatus($code);
            $this->revise(user: $user)->assertStatus($code);
        }
        $this->actingAs($this->manager)->post('/invoices/999999/verify')->assertNotFound();
        $this->actingAs($this->manager)->post('/invoices/99999999999999999999/verify')->assertNotFound();
        $this->actingAs($this->manager)->post('/invoices/99999999999999999999/request-revision', ['reason' => 'x'])->assertNotFound();
        $this->untouched();

        $this->app['auth']->forgetGuards();
        $this->post("/invoices/{$this->inv}/verify")->assertRedirect('/login');
        $this->post("/invoices/{$this->inv}/request-revision", ['reason' => 'x'])->assertRedirect('/login');
        $this->untouched();
    }

    public function test_invalid_transitions_are_409_without_side_effects(): void
    {
        $seq = 2;
        foreach (['PLANNED', 'INVOICE_REVISION_REQUIRED', 'INVOICE_VERIFIED', 'WAITING_FOR_PAYMENT', 'CANCELLED'] as $status) {
            $id = $this->invoice($seq++, $status);
            $this->verify(id: $id)->assertStatus(409);
            $this->revise(id: $id)->assertStatus(409);
            $this->assertSame([$status, 1], [$this->row($id)->workflow_status, (int) $this->row($id)->version], $status);
        }
        $this->assertSame(0, $this->audits());

        $this->verify()->assertSessionHasNoErrors();
        $this->verify()->assertStatus(409); // sudah WAITING_FOR_PAYMENT, bukan PAID
        $this->assertSame(['WAITING_FOR_PAYMENT', 1], [$this->row()->workflow_status, $this->audits()]);
    }

    public function test_project_must_be_active_or_completed(): void
    {
        foreach (['DRAFT', 'SUBMITTED', 'FINANCIALLY_CLOSED', 'ARCHIVED', 'CANCELLED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            $this->verify()->assertForbidden();
            $this->revise()->assertForbidden();
            $this->untouched();
        }
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED']);
        $this->verify()->assertSessionHasNoErrors();
        $this->assertSame('WAITING_FOR_PAYMENT', $this->row()->workflow_status);
    }

    public function test_failure_after_the_update_rolls_everything_back(): void
    {
        DB::listen(fn ($query) => str_contains($query->sql, 'insert into `audit_logs`') && throw new \RuntimeException('audit gagal'));

        $this->withoutExceptionHandling();
        try {
            $this->verify();
            $this->fail('Harus gagal.');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit gagal', $e->getMessage());
        }
        $this->untouched();
    }

    public function test_page_shows_buttons_by_role_hint_to_submitter_and_reason_to_staff(): void
    {
        $page = "/projects/{$this->project->id}";
        $own = $this->invoice(2, 'INVOICE_SUBMITTED', $this->manager->id);
        $revision = $this->invoice(3, 'INVOICE_REVISION_REQUIRED');
        DB::table('invoices')->where('id', $revision)->update(['revision_reason' => 'Lampiran buram']);

        $manager = $this->actingAs($this->manager)->get($page)->assertOk()->getContent();
        $this->assertStringContainsString("invoices/{$this->inv}/verify", $manager);
        $this->assertStringContainsString("invoices/{$this->inv}/request-revision", $manager);
        $this->assertStringContainsString('Diajukan oleh Anda, jadi tidak bisa Anda verifikasi.', $manager);
        foreach (["invoices/$own/", "invoices/$revision/"] as $absent) {
            $this->assertStringNotContainsString($absent, $manager, $absent);
        }

        $staff = $this->actingAs($this->staff)->get($page)->assertOk()->assertSee('Alasan revisi: Lampiran buram')->assertDontSee('Diajukan oleh Anda')->getContent();
        $this->assertStringNotContainsString('/verify', $staff);
        foreach (['DIREKSI', 'SYSTEM_ADMIN'] as $role) {
            $this->assertStringNotContainsString('/verify', $this->actingAs(User::factory()->role($role, null)->create())->get($page)->assertOk()->getContent(), $role);
        }

        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'DRAFT']);
        $this->assertStringNotContainsString("invoices/{$this->inv}/verify", $this->actingAs($this->manager)->get($page)->getContent());
    }
}
