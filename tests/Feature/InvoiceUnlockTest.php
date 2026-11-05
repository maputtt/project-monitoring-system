<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// INV-04 (PRD 5.5, AC-18): satu-satunya jalan keluar dari PAID; Manager/GM divisi, alasan wajib, audit UNLOCK.
class InvoiceUnlockTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private Project $project;

    private int $paid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
        $this->paid = $this->invoice(1, 'PAID');
    }

    private function invoice(int $seq, string $status): int
    {
        $id = DB::table('invoices')->insertGetId([
            'invoice_number' => "INV/$seq", 'billing_date' => '2026-08-20', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500',
            'dpp_amount' => '100.00', 'ppn_amount' => '11.00', 'subtotal_amount' => '111.00', 'pph_amount' => '3.00', 'net_received_amount' => '108.00',
            'submitted_by' => $this->staff->id, 'submitted_at' => now(),
            'locked_at' => $status === 'PAID' ? '2026-09-01 10:00:00' : null, 'locked_by' => $status === 'PAID' ? $this->manager->id : null,
            'project_id' => $this->project->id, 'department' => 'BM', 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'billing_percentage' => '50.0000',
            'planned_amount' => '111.00', 'workflow_status' => $status, 'created_by' => $this->staff->id,
        ]);
        DB::table('documents')->insert([
            'project_id' => $this->project->id, 'entity_type' => 'INVOICE', 'entity_id' => $id, 'document_type' => 'BUKTI_BAYAR', 'original_filename' => 'bayar.pdf',
            'storage_key' => "k/$id/".uniqid(), 'mime_type' => 'application/pdf', 'file_size' => 10, 'status' => $status === 'PAID' ? 'VERIFIED' : 'UPLOADED', 'uploaded_by' => $this->staff->id,
        ]);

        return $id;
    }

    private function row(?int $id = null): object
    {
        return DB::table('invoices')->where('id', $id ?? $this->paid)->first();
    }

    private function audits(): int
    {
        return DB::table('audit_logs')->where('entity_type', 'INVOICE')->count();
    }

    private function unlock(?User $user = null, int|string|null $id = null, mixed $reason = 'Nominal bukti bayar salah')
    {
        return $this->actingAs($user ?? $this->manager)->post('/invoices/'.($id ?? $this->paid).'/unlock', $reason === null ? [] : ['reason' => $reason]);
    }

    private function stillPaid(): void
    {
        $row = $this->row();
        $this->assertSame(['PAID', 1, null, null, null, 0], [$row->workflow_status, (int) $row->version, $row->unlocked_at, $row->unlocked_by, $row->unlock_reason, $this->audits()]);
    }

    public function test_unlock_returns_paid_to_payment_revision_with_reason_and_audit(): void
    {
        $this->unlock()->assertRedirect("/projects/{$this->project->id}")->assertSessionHasNoErrors();

        $row = $this->row();
        $this->assertSame(['PAYMENT_REVISION_REQUIRED', 2, $this->manager->id, 'Nominal bukti bayar salah', '2026-09-01 10:00:00', $this->manager->id],
            [$row->workflow_status, (int) $row->version, (int) $row->unlocked_by, $row->unlock_reason, $row->locked_at, (int) $row->locked_by]); // locked_* tetap sebagai jejak
        $this->assertNotNull($row->unlocked_at);
        $this->assertSame('108.00', $row->net_received_amount);

        $audit = DB::table('audit_logs')->where('entity_type', 'INVOICE')->sole();
        $this->assertSame(['UNLOCK', $this->manager->id, $this->paid, 'Nominal bukti bayar salah', 'PAID', 'PAYMENT_REVISION_REQUIRED', null, $this->manager->id],
            [$audit->action, (int) $audit->user_id, (int) $audit->entity_id, $audit->reason, json_decode($audit->data_before)->workflow_status, json_decode($audit->data_after)->workflow_status,
                json_decode($audit->data_before)->unlock_reason, json_decode($audit->data_after)->unlocked_by]);
    }

    public function test_after_unlock_a_new_proof_can_be_uploaded_and_approved_into_paid_again(): void
    {
        $this->unlock();
        $this->actingAs($this->staff)->post("/invoices/{$this->paid}/submit-payment", ['file' => UploadedFile::fake()->create('baru.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
        $this->assertSame('PAYMENT_SUBMITTED', $this->row()->workflow_status);

        $other = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->actingAs($other)->post("/invoices/{$this->paid}/approve-payment")->assertSessionHasNoErrors();

        $row = $this->row();
        $this->assertSame(['PAID', $other->id, $this->manager->id, 'Nominal bukti bayar salah'], [$row->workflow_status, (int) $row->locked_by, (int) $row->unlocked_by, $row->unlock_reason]); // unlocked_* = unlock terakhir
        $this->assertNotSame('2026-09-01 10:00:00', $row->locked_at);
        $this->assertSame(['REPLACED', 'VERIFIED'], DB::table('documents')->where('entity_id', $this->paid)->orderBy('id')->pluck('status')->all());
    }

    public function test_reason_is_required_and_bounded(): void
    {
        foreach ([null, '', '   ', str_repeat('a', 1001), ['x']] as $bad) {
            $this->unlock(reason: $bad)->assertSessionHasErrors('reason');
        }
        $this->stillPaid();
        $this->unlock(reason: str_repeat('a', 1000))->assertSessionHasNoErrors();
        $this->assertSame('PAYMENT_REVISION_REQUIRED', $this->row()->workflow_status);
    }

    public function test_only_the_division_manager_may_unlock(): void
    {
        $this->post("/invoices/{$this->paid}/unlock", ['reason' => 'x'])->assertRedirect('/login');
        foreach ([
            [$this->staff, 403], [User::factory()->role('DIREKSI', null)->create(), 403], [User::factory()->role('SYSTEM_ADMIN', null)->create(), 403],
            [User::factory()->role('MANAGER_GM', 'CP')->create(), 404], [User::factory()->role('STAFF_PIC', 'CP')->create(), 404],
        ] as [$user, $code]) {
            $this->unlock($user)->assertStatus($code);
        }
        $this->unlock(id: 999999)->assertNotFound();
        $this->unlock(id: str_repeat('9', 21))->assertNotFound();
        $this->stillPaid();
    }

    public function test_the_payment_approver_may_unlock_without_a_self_approval_bar(): void
    {
        $this->assertSame($this->manager->id, (int) $this->row()->locked_by);
        $this->unlock()->assertSessionHasNoErrors();
        $this->assertSame('PAYMENT_REVISION_REQUIRED', $this->row()->workflow_status);
    }

    public function test_only_paid_invoices_unlock_everything_else_is_409_without_audit(): void
    {
        foreach (['PLANNED', 'INVOICE_SUBMITTED', 'INVOICE_REVISION_REQUIRED', 'INVOICE_VERIFIED', 'WAITING_FOR_PAYMENT', 'PAYMENT_SUBMITTED', 'PAYMENT_REVISION_REQUIRED', 'CANCELLED'] as $i => $status) {
            $id = $this->invoice($i + 2, $status);
            $this->unlock(id: $id)->assertStatus(409);
            $row = $this->row($id);
            $this->assertSame([$status, 1, null], [$row->workflow_status, (int) $row->version, $row->unlocked_at], $status);
        }
        $this->assertSame(0, $this->audits());
    }

    public function test_project_status_gate(): void
    {
        foreach (['DRAFT', 'SUBMITTED', 'FINANCIALLY_CLOSED', 'ARCHIVED', 'CANCELLED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            $this->unlock()->assertForbidden();
        }
        $this->stillPaid();
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED']);
        $this->unlock()->assertSessionHasNoErrors();
        $this->assertSame('PAYMENT_REVISION_REQUIRED', $this->row()->workflow_status);
    }

    public function test_failure_after_the_update_rolls_everything_back(): void
    {
        DB::listen(fn ($query) => str_contains($query->sql, 'insert into `audit_logs`') && throw new \RuntimeException('audit gagal'));
        $this->withoutExceptionHandling();
        try {
            $this->unlock();
            $this->fail('Harus gagal.');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit gagal', $e->getMessage());
        }
        $this->stillPaid();
    }

    public function test_page_offers_unlock_only_to_the_division_manager_on_paid_and_shows_the_note_afterwards(): void
    {
        $page = "/projects/{$this->project->id}";
        $open = $this->invoice(2, 'WAITING_FOR_PAYMENT');

        $html = $this->actingAs($this->manager)->get($page)->assertOk()->getContent();
        $this->assertStringContainsString("invoices/{$this->paid}/unlock", $html);
        $this->assertStringNotContainsString("invoices/$open/unlock", $html);
        foreach ([$this->staff, User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', null)->create()] as $user) {
            $this->assertStringNotContainsString('/unlock', $this->actingAs($user)->get($page)->assertOk()->getContent(), $user->role->value);
        }

        $this->unlock();
        $this->actingAs($this->staff)->get($page)->assertOk()->assertSee('Kunci dibuka')->assertSee('Alasan unlock: Nominal bukti bayar salah')->assertSee($this->manager->name);
        $this->assertStringNotContainsString('/unlock', $this->actingAs($this->manager)->get($page)->getContent());
    }
}
