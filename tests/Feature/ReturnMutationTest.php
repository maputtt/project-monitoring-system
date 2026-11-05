<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\User;
use App\Support\AuditSentence;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Revisi C2: RETURN_TO_UNALLOCATED_RAB mengembalikan budget item BQ ke RAB Belum Dialokasikan (tanpa BQ tujuan). Saldo berubah hanya saat approve.
class ReturnMutationTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private Project $project;

    private int $a;

    private int $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
        // A: RAB 1.000.000,00 nego 800.000,00 (sisa 200.000,00). B: RAB 500.000,00 nego 600.000,00.
        $this->a = $this->bq('1000000.00', '800000.00');
        $this->b = $this->bq('500000.00', '600000.00');
        // RAB Belum Dialokasikan tepat 500.000,00.
        DB::table('projects')->where('id', $this->project->id)->update(['effective_rab' => bcadd((string) DB::table('bq_items')->where('project_id', $this->project->id)->sum('effective_budget'), '500000.00', 2)]);
    }

    private function bq(string $budget, string $nego, ?string $actual = null): int
    {
        return DB::table('bq_items')->insertGetId([
            'project_id' => $this->project->id, 'item_name' => 'Item NORMAL', 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'created_by' => $this->staff->id,
            'baseline_budget' => $budget, 'effective_budget' => $budget, 'negotiated_value' => $nego, 'actual_cost' => $actual, 'margin_amount' => '0.00', 'margin_pct' => '0.0000',
        ]);
    }

    private function pool(): string
    {
        $rab = DB::table('projects')->where('id', $this->project->id)->value('effective_rab');

        return bcsub($rab, (string) DB::table('bq_items')->where('project_id', $this->project->id)->whereIn('status', ['APPROVED', 'CLOSED'])->sum('effective_budget'), 2);
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['mutation_type' => 'RETURN_TO_UNALLOCATED_RAB', 'source_bq_id' => $this->a, 'amount' => '150.00', 'reason' => 'Kembalikan sisa',
            'file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf')];
    }

    private function submit(array $extra = [])
    {
        return $this->actingAs($this->staff)->post("/projects/{$this->project->id}/budget-mutations", $this->payload($extra));
    }

    private function raw(array $extra = []): int
    {
        return DB::table('bq_budget_mutations')->insertGetId($extra + ['project_id' => $this->project->id, 'mutation_type' => 'RETURN_TO_UNALLOCATED_RAB', 'source_bq_id' => $this->a,
            'destination_bq_id' => null, 'amount' => '150.00', 'reason' => 'x', 'status' => 'SUBMITTED', 'submitted_by' => $this->staff->id]);
    }

    public function test_submit_and_approve_return_moves_pool_and_recomputes_margin_with_audit(): void
    {
        $this->assertSame('500000.00', $this->pool());

        $this->submit(['amount' => '150000.00', 'destination_bq_id' => $this->b])->assertSessionHasNoErrors(); // tujuan dari klien diabaikan
        $m = DB::table('bq_budget_mutations')->first();
        $this->assertSame(['SUBMITTED', 'RETURN_TO_UNALLOCATED_RAB', $this->a, null, '150000.00'], [$m->status, $m->mutation_type, $m->source_bq_id, $m->destination_bq_id, $m->amount]);
        $this->assertSame('500000.00', $this->pool()); // submit tidak menggeser saldo
        $submit = DB::table('audit_logs')->where('entity_type', 'BQ_MUTATION')->where('action', 'SUBMIT')->first();
        $this->assertSame(DB::table('documents')->value('id'), json_decode($submit->data_after, true)['nota_document_id']);

        $this->actingAs($this->manager)->post("/budget-mutations/{$m->id}/approve")->assertSessionHasNoErrors();

        $a = DB::table('bq_items')->find($this->a);
        $this->assertSame(['850000.00', '50000.00', '5.8824', 2], [$a->effective_budget, $a->margin_amount, $a->margin_pct, $a->version]); // margin dari nego: (850.000 - 800.000)/850.000
        $this->assertSame('650000.00', $this->pool());
        $this->assertSame('500000.00', DB::table('bq_items')->where('id', $this->b)->value('effective_budget'));
        $audit = DB::table('audit_logs')->where('entity_type', 'BQ_MUTATION')->where('action', 'APPROVE')->first();
        $this->assertSame('500000.00', json_decode($audit->data_before, true)['unallocated_rab']);
        $this->assertSame(['650000.00', null, '850000.00'], [json_decode($audit->data_after, true)['unallocated_rab'], json_decode($audit->data_after, true)['destination_effective_budget'], json_decode($audit->data_after, true)['source_effective_budget']]);
        $lines = AuditSentence::details(AuditAction::APPROVE, 'BQ_MUTATION', json_decode($audit->data_before, true), json_decode($audit->data_after, true));
        $this->assertCount(1, array_filter($lines, fn ($l) => str_starts_with($l, 'Sisa RAB: ')), 'label audit relokasi kembali ke Sisa RAB (uji coba PM 2026-11-02)');

        // Pool yang membesar langsung bisa dialokasikan: 650.000,00 lolos, 650.000,01 ditolak.
        $fits = DB::table('bq_budget_mutations')->insertGetId(['project_id' => $this->project->id, 'mutation_type' => 'FROM_UNALLOCATED_RAB', 'destination_bq_id' => $this->b, 'amount' => '650000.01', 'reason' => 'x', 'status' => 'SUBMITTED', 'submitted_by' => $this->staff->id]);
        $this->actingAs($this->manager)->post("/budget-mutations/$fits/approve")->assertSessionHasErrors('mutation');
        DB::table('bq_budget_mutations')->where('id', $fits)->update(['amount' => '650000.00']);
        $this->actingAs($this->manager)->post("/budget-mutations/$fits/approve")->assertSessionHasNoErrors();
        $this->assertSame('0.00', $this->pool());
    }

    public function test_return_over_sisa_is_rejected_at_store_and_approve_without_change(): void
    {
        $this->submit(['amount' => '200000.01'])->assertSessionHasErrors('amount', null, 'mutation-new');
        $this->assertSame(0, DB::table('bq_budget_mutations')->count());
        $this->assertSame(0, DB::table('documents')->count());

        $m = $this->raw(['amount' => '200000.01']);
        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertSessionHasErrors('mutation');
        $this->assertSame('1000000.00', DB::table('bq_items')->where('id', $this->a)->value('effective_budget'));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'APPROVE')->count());
    }

    public function test_return_requires_source_and_nota(): void
    {
        $this->submit(['source_bq_id' => null])->assertSessionHasErrors('source_bq_id', null, 'mutation-new');
        $this->submit(['file' => null])->assertSessionHasErrors('file', null, 'mutation-new');
        $this->assertSame(0, DB::table('bq_budget_mutations')->count());
    }

    public function test_approval_authorization_and_invalid_transition(): void
    {
        $m = $this->raw();
        $this->actingAs($this->staff)->post("/budget-mutations/$m/approve")->assertForbidden();
        DB::table('bq_budget_mutations')->where('id', $m)->update(['submitted_by' => $this->manager->id]);
        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertForbidden(); // pengaju tidak boleh menyetujui sendiri

        DB::table('bq_budget_mutations')->where('id', $m)->update(['submitted_by' => $this->staff->id]);
        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertStatus(409);
        $this->assertSame('999850.00', DB::table('bq_items')->where('id', $this->a)->value('effective_budget')); // hanya sekali
    }

    public function test_project_page_lists_return_row_with_unallocated_destination(): void
    {
        $this->raw();

        $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->assertSee('Kembalikan ke Sisa RAB')->assertSee('→ Sisa RAB', false);
    }

    public function test_approve_modal_note_for_return_says_the_remainder_goes_back_to_the_unallocated_rab(): void
    {
        $this->raw();

        $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()
            ->assertSee('Sisa item sumber dikembalikan ke Sisa RAB.')->assertDontSee('Saldo sumber berkurang dan saldo tujuan bertambah');
    }

    public function test_schema_rejects_return_with_destination_and_transfer_without_destination(): void
    {
        foreach ([['destination_bq_id' => $this->b], ['mutation_type' => 'TRANSFER_BETWEEN_BQ']] as $bad) {
            try {
                $this->raw($bad);
                $this->fail('CHECK harus menolak baris ini.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
