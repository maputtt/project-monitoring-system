<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Revisi A: Target Margin Awal diturunkan dari angka awal, kartu Nilai Project, dan kolom Sumber dokumen.
class RevisiALabelsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
    }

    public function test_initial_target_margin_is_derived_from_initial_figures_for_inclusive_and_exclusive(): void
    {
        // INCLUSIVE: dasar = 1.000.000,00; RAB Awal 850.000,00 -> Target Margin Awal 150.000,00 = 15%. Sudah ada penyesuaian dan koreksi: Awal tidak ikut berubah.
        $inclusive = Project::factory()->active()->create([
            'contract_value' => '600000.00', 'initial_contract_value' => '1000000.00', 'margin_base_amount' => '600000.00',
            'initial_rab' => '850000.00', 'effective_rab' => '500000.00', 'target_margin_pct' => '16.6667', 'target_margin_amount' => '100000.00',
        ]);
        $summary = $this->actingAs($this->manager)->get("/projects/{$inclusive->id}")->assertOk()
            ->assertSee('Target Margin Awal (15%)')->assertSee('Rp150.000,00')->assertSee('Target Margin Perubahan (16,6667%)')
            ->assertSee('nilai tertagih Rp600.000,00 (termin dibatalkan)')->assertSee('Nilai Project')
            ->assertDontSee('Nilai Project + PPN')->viewData('summary');
        $this->assertSame(['150000.00', '15.0000'], [$summary['initial_margin_amount'], $summary['initial_margin_pct']]);

        // EXCLUSIVE_PPN: dasar = 1.000.000,00 + PPN 11% = 1.110.000,00; RAB Awal 943.500,00 -> 166.500,00 = 15%.
        $exclusive = Project::factory()->active()->create([
            'contract_tax_mode' => 'EXCLUSIVE_PPN', 'margin_base_ppn_rate_snapshot' => '11.0000', 'contract_value' => '1000000.00',
            'margin_base_amount' => '1110000.00', 'initial_rab' => '943500.00', 'effective_rab' => '943500.00', 'target_margin_pct' => '15.0000', 'target_margin_amount' => '166500.00',
        ]);
        $summary = $this->get("/projects/{$exclusive->id}")->assertOk()
            ->assertSee('Nilai Project + PPN')->assertSee('dasar perhitungan target margin')->assertSee('Target Margin Awal (15%)')->viewData('summary');
        $this->assertSame(['166500.00', '15.0000'], [$summary['initial_margin_amount'], $summary['initial_margin_pct']]);
    }

    public function test_document_list_shows_the_source_of_each_document(): void
    {
        $project = Project::factory()->active()->create();
        $uploader = $this->manager->id;
        $invoice = DB::table('invoices')->insertGetId(['project_id' => $project->id, 'department' => 'BM', 'sequence_number' => 2, 'billing_type' => 'TERMIN', 'billing_percentage' => '50.00', 'planned_amount' => '1000.00', 'created_by' => $uploader]);
        $snapshot = '{"contract_value":"1.00","target_margin_pct":"15.0000","target_margin_amount":"1.00","effective_rab":"1.00"}';
        $adjustment = fn (string $type) => DB::table('project_adjustments')->insertGetId([
            'project_id' => $project->id, 'adjustment_type' => $type, 'old_data' => $snapshot, 'new_data' => $snapshot, 'difference_amount' => '0.00', 'reason' => 'x', 'status' => 'SUBMITTED', 'submitted_by' => $uploader,
        ]);
        $doc = fn (string $entity, int $id, string $name) => DB::table('documents')->insert([
            'project_id' => $project->id, 'entity_type' => $entity, 'entity_id' => $id, 'document_type' => 'OTHER', 'original_filename' => $name, 'storage_key' => "documents/x/$name",
            'mime_type' => 'application/pdf', 'file_size' => 1, 'checksum' => str_repeat('a', 64), 'status' => 'UPLOADED', 'uploaded_by' => $uploader, 'uploaded_at' => now(),
        ]);
        $doc('PROJECT', $project->id, 'manual.pdf');
        $doc('INVOICE', $invoice, 'inv.pdf');
        $doc('BQ_MUTATION', 77, 'nota.pdf');
        $doc('PROJECT_ADJUSTMENT', $adjustment('TARGET_MARGIN'), 'margin.pdf');
        $doc('PROJECT_ADJUSTMENT', $adjustment('CONTRACT_VALUE'), 'koreksi.pdf');

        $this->actingAs($this->manager)->get("/projects/{$project->id}")->assertOk()
            ->assertSee('Sumber')->assertSee('Upload manual')->assertSee('Invoice termin 2')->assertSee('Relokasi #77')
            ->assertSee('Penyesuaian margin')->assertSee('Koreksi nilai tertagih');
    }
}
