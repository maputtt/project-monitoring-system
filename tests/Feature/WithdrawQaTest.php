<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// D-C-1 verifikasi independen (QA): jalur penyalahgunaan, input rakitan, dokumen, aturan satu-usulan-terbuka, XSS pada alasan.
class WithdrawQaTest extends TestCase
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
        DB::table('tax_rates')->insert([['tax_type' => 'PPN', 'name' => 'PPN 11', 'vendor_qualification' => null, 'rate' => '11.00', 'effective_from' => '2025-01-01', 'created_by' => $this->staff->id]]);
        $this->project = Project::factory()->assignedTo($this->staff)->create([
            'created_by' => $this->staff->id, 'status' => 'ACTIVE', 'contract_value' => '1110000000.00', 'contract_tax_mode' => 'INCLUSIVE_PPN',
            'margin_base_amount' => '1110000000.00', 'target_margin_pct' => '15.0000', 'target_margin_amount' => '166500000.00', 'initial_rab' => '943500000.00', 'effective_rab' => '943500000.00',
        ]);
    }

    private function adjustment(string $status = 'SUBMITTED', string $type = 'TARGET_MARGIN', ?int $by = null): int
    {
        return DB::table('project_adjustments')->insertGetId([
            'project_id' => $this->project->id, 'adjustment_type' => $type, 'status' => $status, 'reason' => 'Nego ulang', 'submitted_by' => $by ?? $this->staff->id,
            'old_data' => json_encode(['target_margin_pct' => '15.0000', 'target_margin_amount' => '166500000.00', 'effective_rab' => '943500000.00']),
            'new_data' => json_encode(['target_margin_pct' => '10.0000', 'target_margin_amount' => '111000000.00', 'effective_rab' => '999000000.00', 'margin_mode' => 'PCT', 'margin_value' => '10.0000']),
            'difference_amount' => '55500000.00', 'revision_reason' => $status === 'REVISION_REQUIRED' ? 'Perbaiki' : null,
            'revision_requested_by' => $status === 'REVISION_REQUIRED' ? $this->manager->id : null, 'revision_requested_at' => $status === 'REVISION_REQUIRED' ? '2026-10-20 10:00:00' : null,
        ]);
    }

    private function mutation(string $status = 'SUBMITTED', ?int $by = null): int
    {
        $bq = DB::table('bq_items')->insertGetId(['project_id' => $this->project->id, 'item_name' => 'Item', 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'created_by' => $this->staff->id,
            'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '800.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000']);

        return DB::table('bq_budget_mutations')->insertGetId([
            'project_id' => $this->project->id, 'source_bq_id' => null, 'destination_bq_id' => $bq, 'amount' => '100.00', 'reason' => 'Geser budget',
            'mutation_type' => 'FROM_UNALLOCATED_RAB', 'status' => $status, 'submitted_by' => $by ?? $this->staff->id, 'submitted_at' => now(),
        ]);
    }

    private function updates(string $entity, int $id)
    {
        return DB::table('audit_logs')->where('entity_type', $entity)->where('entity_id', $id)->where('action', 'UPDATE');
    }

    /** @return array<string, list<string>> */
    public static function kinds(): array
    {
        return ['adjustment' => ['adjustments', 'project_adjustments', 'PROJECT_ADJUSTMENT'], 'mutation' => ['budget-mutations', 'bq_budget_mutations', 'BQ_MUTATION']];
    }

    private function make(string $kind, string $status = 'SUBMITTED'): int
    {
        return $kind === 'adjustments' ? $this->adjustment($status) : $this->mutation($status);
    }

    #[DataProvider('kinds')]
    public function test_guest_and_system_admin_cannot_withdraw(string $path, string $table, string $entity): void
    {
        $id = $this->make($path);
        $this->post("/$path/$id/withdraw", ['reason' => 'x'])->assertRedirect('/login');
        $admin = User::factory()->role('SYSTEM_ADMIN', null)->create();
        $this->actingAs($admin)->post("/$path/$id/withdraw", ['reason' => 'x'])->assertStatus(403);
        $this->actingAs($this->staff)->get("/$path/$id/withdraw")->assertStatus(405); // bukan GET
        $this->assertSame(['SUBMITTED', 0], [DB::table($table)->find($id)->status, $this->updates($entity, $id)->count()]);
    }

    #[DataProvider('kinds')]
    public function test_crafted_reason_values_fail_validation_without_500_and_change_nothing(string $path, string $table, string $entity): void
    {
        $id = $this->make($path);
        $bad = [
            'array' => ['reason' => ['a', 'b']], 'nested' => ['reason' => [['x' => 1]]], 'int' => ['reason' => 12345], 'null' => ['reason' => null], 'blank' => ['reason' => "   \n\t "],
            'empty-array' => ['reason' => []], 'object-ish' => ['reason' => ['a' => 'b']], 'too-long-multibyte' => ['reason' => str_repeat('é', 1001)], 'other-key' => ['alasan' => 'x'],
        ];
        foreach ($bad as $label => $payload) {
            $response = $this->actingAs($this->staff)->post("/$path/$id/withdraw", $payload);
            $this->assertTrue(in_array($response->getStatusCode(), [302, 422], true), "$label gave {$response->getStatusCode()}");
            $response->assertSessionHasErrors('reason');
        }
        $this->assertSame(['SUBMITTED', 0], [DB::table($table)->find($id)->status, $this->updates($entity, $id)->count()]);

        // Batas tepat 1000 karakter (juga multibyte) diterima.
        $this->actingAs($this->staff)->post("/$path/$id/withdraw", ['reason' => str_repeat('é', 1000)])->assertSessionHasNoErrors();
        $this->assertSame('WITHDRAWN', DB::table($table)->find($id)->status);
    }

    #[DataProvider('kinds')]
    public function test_second_withdraw_is_409_with_exactly_one_audit_and_odd_ids_are_404(string $path, string $table, string $entity): void
    {
        $id = $this->make($path, 'REVISION_REQUIRED');
        $this->actingAs($this->staff)->post("/$path/$id/withdraw", ['reason' => 'pertama'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post("/$path/$id/withdraw", ['reason' => 'kedua'])->assertStatus(409);
        $this->actingAs($this->manager)->post("/$path/$id/withdraw", ['reason' => 'ketiga'])->assertStatus(409);
        $this->assertSame(1, $this->updates($entity, $id)->count());
        $this->assertSame(['REVISION_REQUIRED', 'WITHDRAWN'], [json_decode($this->updates($entity, $id)->value('data_before'), true)['status'], json_decode($this->updates($entity, $id)->value('data_after'), true)['status']]);

        foreach (['0', '-1', 'abc', '1.5', str_repeat('9', 19), '1%20OR%201=1'] as $odd) {
            $this->actingAs($this->manager)->post("/$path/$odd/withdraw", ['reason' => 'x'])->assertNotFound();
        }
    }

    public function test_withdraw_keeps_documents_and_revision_metadata(): void
    {
        $a = $this->adjustment('REVISION_REQUIRED');
        $m = $this->mutation('SUBMITTED');
        $doc = fn (string $entity, int $id) => DB::table('documents')->insertGetId([
            'project_id' => $this->project->id, 'entity_type' => $entity, 'entity_id' => $id, 'document_type' => 'NOTA_DISPOSISI', 'original_filename' => 'n.pdf', 'storage_key' => "documents/{$this->project->id}/$entity-$id.pdf",
            'mime_type' => 'application/pdf', 'file_size' => 10, 'checksum' => str_repeat('a', 64), 'status' => 'UPLOADED', 'uploaded_by' => $this->staff->id, 'uploaded_at' => now(),
        ]);
        $docs = [$doc('PROJECT_ADJUSTMENT', $a), $doc('BQ_MUTATION', $m)];
        $before = DB::table('documents')->get()->all();
        $revision = DB::table('project_adjustments')->find($a);

        $this->actingAs($this->staff)->post("/adjustments/$a/withdraw", ['reason' => 'x'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/budget-mutations/$m/withdraw", ['reason' => 'y'])->assertSessionHasNoErrors();

        $this->assertEquals($before, DB::table('documents')->get()->all());
        $after = DB::table('project_adjustments')->find($a);
        $this->assertSame([$revision->revision_reason, $revision->revision_requested_by, $revision->revision_requested_at, $revision->old_data, $revision->new_data, $revision->difference_amount, $revision->submitted_by],
            [$after->revision_reason, $after->revision_requested_by, $after->revision_requested_at, $after->old_data, $after->new_data, $after->difference_amount, $after->submitted_by]);
        $this->assertNull($after->approved_by);
        $this->assertCount(2, $docs);
    }

    public function test_contract_value_one_open_rule_releases_after_withdraw(): void
    {
        foreach ([1 => '30.0000', 2 => '30.0000', 3 => '40.0000'] as $seq => $pct) {
            DB::table('invoices')->insert(['project_id' => $this->project->id, 'department' => 'BM', 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'billing_percentage' => $pct,
                'planned_amount' => '1.00', 'workflow_status' => $seq === 3 ? 'CANCELLED' : 'PLANNED', 'created_by' => $this->staff->id]);
        }
        $submit = fn () => $this->actingAs($this->staff)->post("/projects/{$this->project->id}/adjustments", ['adjustment_type' => 'CONTRACT_VALUE', 'margin_mode' => 'PCT', 'margin_value' => '15', 'reason' => 'Termin 3 batal']);

        $submit()->assertSessionHasNoErrors();
        $first = DB::table('project_adjustments')->value('id');
        $submit()->assertSessionHasErrors(['adjustment_type' => 'Masih ada Koreksi Nilai Tertagih yang belum selesai.'], null, 'adjustment-new');
        $this->actingAs($this->manager)->post("/adjustments/$first/withdraw", ['reason' => 'Ulang'])->assertSessionHasNoErrors();
        $submit()->assertSessionHasNoErrors();
        $this->assertSame(['WITHDRAWN', 'SUBMITTED'], DB::table('project_adjustments')->orderBy('id')->pluck('status')->all());
    }

    public function test_mutation_project_status_gate_and_completed_ok(): void
    {
        $id = $this->mutation();
        foreach (['DRAFT', 'SUBMITTED', 'CANCELLED', 'FINANCIALLY_CLOSED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            $this->actingAs($this->staff)->post("/budget-mutations/$id/withdraw", ['reason' => 'x'])->assertForbidden();
            $this->actingAs($this->manager)->post("/budget-mutations/$id/withdraw", ['reason' => 'x'])->assertForbidden();
        }
        $this->assertSame('SUBMITTED', DB::table('bq_budget_mutations')->find($id)->status);
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED']);
        $this->actingAs($this->manager)->post("/budget-mutations/$id/withdraw", ['reason' => 'x'])->assertSessionHasNoErrors();
        $this->assertSame('WITHDRAWN', DB::table('bq_budget_mutations')->find($id)->status);
    }

    public function test_adjustment_project_status_gate_covers_every_type(): void
    {
        $ids = [$this->adjustment(), $this->adjustment('SUBMITTED', 'CONTRACT_VALUE'), $this->adjustment('REVISION_REQUIRED', 'BILLING_PERCENTAGE')];
        foreach (['DRAFT', 'SUBMITTED', 'CANCELLED', 'FINANCIALLY_CLOSED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            foreach ($ids as $id) {
                $this->actingAs($this->manager)->post("/adjustments/$id/withdraw", ['reason' => 'x'])->assertForbidden();
            }
        }
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED']);
        foreach ($ids as $id) {
            $this->actingAs($this->staff)->post("/adjustments/$id/withdraw", ['reason' => 'x'])->assertSessionHasNoErrors();
        }
        $this->assertSame(['WITHDRAWN'], DB::table('project_adjustments')->pluck('status')->unique()->all());
    }

    public function test_withdrawn_mutation_leaves_inbox_and_dashboard_pending(): void
    {
        $id = $this->mutation();
        $this->assertSame(1, $this->actingAs($this->manager)->get('/dashboard')->viewData('kpi')['pending']);
        $this->actingAs($this->staff)->post("/budget-mutations/$id/withdraw", ['reason' => 'x'])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->actingAs($this->manager)->get('/dashboard')->viewData('kpi')['pending']);
        $this->assertSame(0, $this->actingAs($this->manager)->get('/approvals')->viewData('counts')->get('mutation', 0));
    }

    public function test_reason_is_escaped_on_audit_log_page_and_project_page(): void
    {
        $payload = '<script>alert("xss-d-c-1")</script><img src=x onerror=alert(2)>';
        $a = $this->adjustment();
        $this->actingAs($this->staff)->post("/adjustments/$a/withdraw", ['reason' => $payload])->assertSessionHasNoErrors();

        $page = $this->actingAs($this->manager)->get('/audit-logs')->assertOk();
        $page->assertDontSee('<script>alert("xss-d-c-1")</script>', false)->assertDontSee('<img src=x', false)->assertSee(e($payload), false);
        $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->assertDontSee('<script>alert("xss-d-c-1")</script>', false);
    }

    public function test_manager_own_submission_keeps_note_and_gets_withdraw_but_no_approve(): void
    {
        $a = $this->adjustment('SUBMITTED', 'TARGET_MARGIN', $this->manager->id);
        $m = $this->mutation('SUBMITTED', $this->manager->id);
        $page = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk();
        $page->assertSee('Diajukan oleh Anda, jadi tidak bisa Anda setujui.')->assertSee('Tarik pengajuan')->assertSee("adjustments/$a/withdraw", false)->assertSee("budget-mutations/$m/withdraw", false);
        $page->assertDontSee("adjustments/$a/approve", false)->assertDontSee("budget-mutations/$m/approve", false);
        $this->actingAs($this->manager)->post("/adjustments/$a/approve")->assertForbidden(); // self-approval tetap ditolak
        $this->actingAs($this->manager)->post("/adjustments/$a/withdraw", ['reason' => 'Salah ajukan'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/budget-mutations/$m/withdraw", ['reason' => 'Salah ajukan'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertDontSee('Diajukan oleh Anda, jadi tidak bisa Anda setujui.');
    }
}
