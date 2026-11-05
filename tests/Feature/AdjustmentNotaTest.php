<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Revisi C3: Nota Disposisi Direksi wajib pada usulan TARGET_MARGIN (entity PROJECT_ADJUSTMENT). Margin base 1.000.000,00.
class AdjustmentNotaTest extends TestCase
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
        $this->project = Project::factory()->assignedTo($this->staff)->create([
            'created_by' => $this->staff->id, 'status' => 'ACTIVE', 'contract_value' => '1000000.00', 'margin_base_amount' => '1000000.00',
            'target_margin_pct' => '10.0000', 'target_margin_amount' => '100000.00', 'initial_rab' => '900000.00', 'effective_rab' => '900000.00',
        ]);
    }

    private function nota(string $name = 'Nota.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, 'application/pdf');
    }

    private function body(array $extra = []): array
    {
        return $extra + ['margin_mode' => 'PCT', 'margin_value' => '12', 'reason' => 'Nego ulang'];
    }

    private function submit(array $extra = [])
    {
        return $this->actingAs($this->staff)->post("/projects/{$this->project->id}/adjustments", $this->body($extra));
    }

    private function docs()
    {
        return DB::table('documents')->where('entity_type', 'PROJECT_ADJUSTMENT')->where('document_type', 'NOTA_DISPOSISI')->orderBy('id')->get();
    }

    /** Usulan REVISION_REQUIRED; $withNota=false meniru baris lama sebelum aturan ini. */
    private function revision(bool $withNota): int
    {
        $id = DB::table('project_adjustments')->insertGetId([
            'project_id' => $this->project->id, 'adjustment_type' => 'TARGET_MARGIN', 'status' => 'REVISION_REQUIRED', 'reason' => 'Nego', 'submitted_by' => $this->staff->id,
            'old_data' => json_encode(['target_margin_pct' => '10.0000', 'target_margin_amount' => '100000.00', 'effective_rab' => '900000.00']),
            'new_data' => json_encode(['target_margin_pct' => '12.0000', 'target_margin_amount' => '120000.00', 'effective_rab' => '880000.00', 'margin_mode' => 'PCT', 'margin_value' => '12.0000']), 'difference_amount' => '-20000.00',
        ]);
        if ($withNota) {
            $this->actingAs($this->staff)->put("/adjustments/$id", $this->body(['file' => $this->nota()]))->assertSessionHasNoErrors(); // nota pertama lewat jalur ajukan ulang
            DB::table('project_adjustments')->where('id', $id)->update(['status' => 'REVISION_REQUIRED']);
        }

        return $id;
    }

    public function test_submit_requires_the_nota_and_stores_nothing_without_it(): void
    {
        $this->submit()->assertSessionHasErrors(['file' => 'Nota Disposisi Direksi wajib dilampirkan.'], null, 'adjustment-new');
        $this->assertSame([0, 0, []], [DB::table('project_adjustments')->count(), DB::table('audit_logs')->where('entity_type', 'PROJECT_ADJUSTMENT')->count(), Storage::disk('local')->allFiles()]);
    }

    public function test_submit_with_nota_stores_a_private_document_and_the_audit_carries_its_id(): void
    {
        $this->submit(['file' => $this->nota()])->assertSessionHasNoErrors();

        $a = DB::table('project_adjustments')->first();
        $d = $this->docs()->sole();
        $this->assertSame([$this->project->id, $a->id, 'UPLOADED', $this->staff->id], [(int) $d->project_id, (int) $d->entity_id, $d->status, (int) $d->uploaded_by]);
        Storage::disk('local')->assertExists($d->storage_key);
        $audit = DB::table('audit_logs')->where('entity_type', 'PROJECT_ADJUSTMENT')->where('action', 'SUBMIT')->sole();
        $this->assertSame($d->id, json_decode($audit->data_after, true)['nota_document_id']);
        $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->assertSee('Nota Disposisi Direksi')->assertSee(route('documents.open', $d->id), false);
    }

    public function test_failed_submit_with_file_leaves_no_row_document_or_orphan_file(): void
    {
        $this->submit(['file' => $this->nota(), 'margin_value' => '101'])->assertSessionHasErrors('margin_value', null, 'adjustment-new');
        $this->submit(['file' => $this->nota(), 'margin_mode' => 'AMOUNT', 'margin_value' => '1000000.01'])->assertSessionHasErrors('margin_value', null, 'adjustment-new'); // lolos validasi, gagal di bawah kunci

        $this->assertSame([0, 0, []], [DB::table('project_adjustments')->count(), DB::table('documents')->count(), Storage::disk('local')->allFiles()]);
    }

    public function test_resubmit_with_file_replaces_the_old_nota_and_without_file_keeps_it(): void
    {
        $id = $this->revision(true);
        $first = $this->docs()->sole();

        $this->actingAs($this->staff)->put("/adjustments/$id", $this->body())->assertSessionHasNoErrors(); // tanpa file: nota lama tetap
        $this->assertSame(['UPLOADED'], $this->docs()->pluck('status')->all());

        DB::table('project_adjustments')->where('id', $id)->update(['status' => 'REVISION_REQUIRED']);
        $this->actingAs($this->staff)->put("/adjustments/$id", $this->body(['file' => $this->nota('Baru.pdf')]))->assertSessionHasNoErrors();

        [$old, $new] = $this->docs()->all();
        $this->assertSame([$first->id, 'REPLACED', 'UPLOADED', $first->id], [$old->id, $old->status, $new->status, $new->replaced_document_id]);
        $audit = DB::table('audit_logs')->where('entity_type', 'PROJECT_ADJUSTMENT')->where('action', 'SUBMIT')->orderByDesc('id')->first();
        $this->assertSame($new->id, json_decode($audit->data_after, true)['nota_document_id']);
    }

    public function test_legacy_row_without_nota_needs_one_on_resubmit_but_can_still_be_approved(): void
    {
        $id = $this->revision(false);
        $this->actingAs($this->staff)->put("/adjustments/$id", $this->body())->assertSessionHasErrors('file', null, "adjustment-$id");
        $this->assertSame('REVISION_REQUIRED', DB::table('project_adjustments')->where('id', $id)->value('status'));
        $this->assertCount(0, $this->docs());

        DB::table('project_adjustments')->where('id', $id)->update(['status' => 'SUBMITTED']); // usulan lama tanpa nota tetap bisa disetujui
        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', DB::table('project_adjustments')->where('id', $id)->value('status'));
    }
}
