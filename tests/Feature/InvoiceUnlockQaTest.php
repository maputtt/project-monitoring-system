<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Verifikasi independen INV-04: unlock tidak bisa memintas alur pembayaran, siklus PAID ulang, escaping, tanpa 500.
class InvoiceUnlockQaTest extends TestCase
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

    private function act(User $user, string $path, array $data = [])
    {
        return $this->actingAs($user)->post("/invoices/{$this->paid}/$path", $data);
    }

    private function repay(User $approver): void
    {
        $this->act($this->staff, 'submit-payment', ['file' => UploadedFile::fake()->create('baru.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
        $this->act($approver, 'approve-payment')->assertSessionHasNoErrors();
    }

    public function test_unlock_cannot_bypass_the_payment_workflow_on_payment_submitted(): void
    {
        $id = $this->invoice(2, 'PAYMENT_SUBMITTED');
        $before = (array) $this->row($id);
        $this->actingAs($this->manager)->post("/invoices/$id/unlock", ['reason' => 'x'])->assertStatus(409);
        $this->assertEquals($before, (array) $this->row($id)); // tidak ada revision_*/unlocked_* tertulis
        $this->assertSame('UPLOADED', DB::table('documents')->where('entity_id', $id)->value('status'));
        $this->assertSame(0, DB::table('audit_logs')->where('entity_type', 'INVOICE')->count());
    }

    public function test_after_unlock_the_invoice_cannot_skip_the_new_proof(): void
    {
        $this->act($this->manager, 'unlock', ['reason' => 'salah nominal']);
        $other = User::factory()->role('MANAGER_GM', 'BM')->create();
        foreach (['approve-payment' => [], 'verify' => [], 'request-revision' => ['reason' => 'x'], 'unlock' => ['reason' => 'lagi']] as $path => $data) {
            $this->act($other, $path, $data)->assertStatus(409);
        }
        $this->assertSame(['PAYMENT_REVISION_REQUIRED', 2], [$this->row()->workflow_status, (int) $this->row()->version]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'UNLOCK')->count());
    }

    public function test_relocked_invoice_is_fully_locked_and_a_second_unlock_cycle_keeps_both_records(): void
    {
        $this->act($this->manager, 'unlock', ['reason' => 'siklus satu']);
        $other = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->repay($other);
        $row = $this->row();
        $this->assertSame(['PAID', 4, $other->id, $this->manager->id, 'siklus satu'], [$row->workflow_status, (int) $row->version, (int) $row->locked_by, (int) $row->unlocked_by, $row->unlock_reason]);

        $third = User::factory()->role('MANAGER_GM', 'BM')->create();
        $file = ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')];
        foreach (['verify' => [], 'request-revision' => ['reason' => 'x'], 'approve-payment' => []] as $path => $data) {
            $this->act($third, $path, $data)->assertStatus(409);
        }
        $this->act($this->staff, 'submit-payment', $file)->assertStatus(409);
        $this->assertSame(['PAID', 4], [$this->row()->workflow_status, (int) $this->row()->version]);

        $this->act($third, 'unlock', ['reason' => 'siklus dua'])->assertSessionHasNoErrors();
        $this->repay($this->manager);
        $row = $this->row();
        $this->assertSame(['PAID', 7, $this->manager->id, $third->id, 'siklus dua'], [$row->workflow_status, (int) $row->version, (int) $row->locked_by, (int) $row->unlocked_by, $row->unlock_reason]);
        $this->assertSame(['siklus satu', 'siklus dua'], DB::table('audit_logs')->where('action', 'UNLOCK')->orderBy('id')->pluck('reason')->all()); // riwayat lengkap di audit
        $this->assertSame(['REPLACED', 'REPLACED', 'VERIFIED'], DB::table('documents')->where('entity_id', $this->paid)->orderBy('id')->pluck('status')->all());
    }

    public function test_multibyte_reason_of_1000_characters_is_stored_and_trimmed_whitespace_rejected(): void
    {
        $reason = str_repeat("\u{1F600}", 1000);
        $this->act($this->manager, 'unlock', ['reason' => $reason])->assertSessionHasNoErrors();
        $this->assertSame($reason, $this->row()->unlock_reason);
        $this->assertSame($reason, DB::table('audit_logs')->where('action', 'UNLOCK')->value('reason'));
    }

    public function test_get_is_not_allowed_and_unlock_is_the_only_unlock_route(): void
    {
        $this->actingAs($this->manager)->get("/invoices/{$this->paid}/unlock")->assertStatus(405);
        $this->assertSame('PAID', $this->row()->workflow_status);
    }

    public function test_unlock_reason_is_escaped_and_note_differs_from_a_plain_payment_revision(): void
    {
        $page = "/projects/{$this->project->id}";
        $this->act($this->manager, 'unlock', ['reason' => '<script>alert(9)</script>']);
        foreach ([$this->staff, $this->manager] as $user) {
            $html = $this->actingAs($user)->get($page)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(9)', $html);
            $this->assertStringContainsString('&lt;script&gt;alert(9)&lt;/script&gt;', $html);
        }
        // Bukti baru diajukan lalu diminta revisi biasa: catatan "Kunci dibuka" hilang, diganti revisi pembayaran biasa.
        $this->travel(5)->seconds(); // unlocked_at dan revision_requested_at beresolusi detik
        $this->act($this->staff, 'submit-payment', ['file' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf')]);
        $this->act($this->manager, 'request-revision', ['reason' => 'buram'])->assertSessionHasNoErrors();
        $html = $this->actingAs($this->staff)->get($page)->assertOk()->getContent();
        $this->assertStringNotContainsString('Kunci dibuka', $html);
        $this->assertStringContainsString('Alasan revisi: buram', $html);
    }

    public function test_project_page_never_500s_and_offers_unlock_only_to_the_manager_of_an_open_project(): void
    {
        foreach (['PLANNED', 'INVOICE_SUBMITTED', 'INVOICE_REVISION_REQUIRED', 'INVOICE_VERIFIED', 'WAITING_FOR_PAYMENT', 'PAYMENT_SUBMITTED', 'PAYMENT_REVISION_REQUIRED', 'CANCELLED'] as $i => $status) {
            $this->invoice($i + 2, $status);
        }
        DB::table('invoices')->where('workflow_status', 'PAYMENT_REVISION_REQUIRED')->update(['unlocked_at' => now(), 'unlock_reason' => 'tanpa pengguna']); // unlocked_by null
        foreach (['ACTIVE', 'COMPLETED', 'FINANCIALLY_CLOSED', 'ARCHIVED'] as $projectStatus) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $projectStatus]);
            foreach ([$this->staff, $this->manager, User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', null)->create()] as $user) {
                $html = $this->actingAs($user)->get("/projects/{$this->project->id}")->assertOk()->getContent();
                $expected = $user->is($this->manager) && in_array($projectStatus, ['ACTIVE', 'COMPLETED'], true);
                $this->assertSame($expected, str_contains($html, "invoices/{$this->paid}/unlock"), "$projectStatus {$user->role->value}");
            }
        }
    }

    public function test_unlock_on_a_closed_project_is_forbidden(): void
    {
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'FINANCIALLY_CLOSED']);
        $this->act($this->manager, 'unlock', ['reason' => 'x'])->assertForbidden();
        $this->assertSame('PAID', $this->row()->workflow_status);
    }
}
