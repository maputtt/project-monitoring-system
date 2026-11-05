<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Revisi C4 (tampilan): halaman project memuat baris Koreksi Persentase Termin tanpa angka RAB/margin, form hanya untuk yang berhak, dan kartu BQ ponsel memuat Sisa.
class BillingPercentageUiTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private Project $project;

    /** @var array<int, int> sequence_number => invoice id */
    private array $termins = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE', 'contract_value' => '1000000.00', 'contract_tax_mode' => 'INCLUSIVE_PPN', 'margin_base_amount' => '1000000.00']);
        foreach ([1 => ['30.0000', '300000.00'], 2 => ['30.0000', '300000.00'], 3 => ['40.0000', '400000.00']] as $seq => [$pct, $amount]) {
            $this->termins[$seq] = DB::table('invoices')->insertGetId([
                'project_id' => $this->project->id, 'department' => 'BM', 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'billing_percentage' => $pct,
                'planned_amount' => $amount, 'workflow_status' => 'PLANNED', 'created_by' => $this->staff->id,
            ]);
        }
    }

    private function termin(int $seq, string $pct, string $amount): array
    {
        return ['invoice_id' => $this->termins[$seq], 'sequence_number' => $seq, 'billing_percentage' => $pct, 'planned_amount' => $amount];
    }

    /** Usulan 30/30/40 -> 30/35/35 dalam status tertentu, dimasukkan langsung ke DB. */
    private function proposal(string $status): int
    {
        $old = [$this->termin(1, '30.0000', '300000.00'), $this->termin(2, '30.0000', '300000.00'), $this->termin(3, '40.0000', '400000.00')];
        $new = [$this->termin(1, '30.0000', '300000.00'), $this->termin(2, '35.0000', '350000.00'), $this->termin(3, '35.0000', '350000.00')];

        return DB::table('project_adjustments')->insertGetId([
            'project_id' => $this->project->id, 'adjustment_type' => 'BILLING_PERCENTAGE', 'old_data' => json_encode(['termins' => $old]), 'new_data' => json_encode(['termins' => $new]),
            'difference_amount' => null, 'reason' => 'Kesepakatan ulang jadwal tagihan', 'status' => $status, 'submitted_by' => $this->staff->id,
            'revision_reason' => $status === 'REVISION_REQUIRED' ? 'Cek lagi total' : null, 'approved_by' => $status === 'APPROVED' ? $this->manager->id : null,
        ]);
    }

    public function test_project_page_renders_a_billing_percentage_row_in_every_status_without_rab_figures(): void
    {
        foreach (['SUBMITTED', 'REVISION_REQUIRED', 'APPROVED'] as $status) {
            DB::table('project_adjustments')->delete();
            $id = $this->proposal($status);

            $staffPage = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()
                ->assertSee('Koreksi Persentase Termin')->assertSee('Termin 2: 30% → 35%', false)->assertSee('Termin 3: 40% → 35%', false);
            $managerPage = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->assertSee('Termin 2: 30% → 35%', false);

            if ($status === 'SUBMITTED') {
                $managerPage->assertSee('Setujui Koreksi Persentase Termin?')->assertSee("adjustments/$id/approve", false);
            }
            if ($status === 'REVISION_REQUIRED') {
                $staffPage->assertSee('Perbaiki dan ajukan ulang Koreksi Persentase Termin')->assertSee("adjustments/$id", false)->assertSee('Cek lagi total');
            } else {
                $staffPage->assertDontSee('Perbaiki dan ajukan ulang Koreksi Persentase Termin');
            }
        }
    }

    public function test_billing_form_renders_only_when_the_user_can_correct_billing(): void
    {
        $url = "/projects/{$this->project->id}";
        $field = "billing_percentages[{$this->termins[2]}]";

        $this->actingAs($this->staff)->get($url)->assertOk()
            ->assertSee('Ajukan Koreksi Persentase Termin')->assertSee($field, false)->assertSee('Total persentase sekarang')->assertDontSee('belum bisa diajukan');
        $this->actingAs($this->manager)->get($url)->assertOk()->assertDontSee($field, false)->assertDontSee('Ajukan Koreksi Persentase Termin');

        // Termin terakhir dibatalkan: tombol dan form hilang, Staff/PIC melihat alasannya.
        DB::table('invoices')->where('id', $this->termins[3])->update(['workflow_status' => 'CANCELLED']);
        $this->actingAs($this->staff)->get($url)->assertOk()->assertDontSee($field, false)->assertSee('termin terakhir sudah ditagih atau dibatalkan');

        // Status project DRAFT-like (tidak menerima koreksi): tidak ada bagian koreksi.
        DB::table('invoices')->where('id', $this->termins[3])->update(['workflow_status' => 'PLANNED']);
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'FINANCIALLY_CLOSED']);
        $this->actingAs($this->staff)->get($url)->assertOk()->assertDontSee($field, false);
    }

    public function test_percentages_are_shown_with_indonesian_decimals_but_form_values_keep_the_dot(): void
    {
        $id = $this->proposal('REVISION_REQUIRED');
        $new = [$this->termin(1, '30.0000', '300000.00'), $this->termin(2, '33.5000', '335000.00'), $this->termin(3, '36.5000', '365000.00')];
        DB::table('project_adjustments')->where('id', $id)->update(['new_data' => json_encode(['termins' => $new])]);

        $html = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->getContent();
        $text = strip_tags($html);
        $this->assertStringContainsString('30% → 33,5%', $text);
        $this->assertStringContainsString('40% → 36,5%', $text);
        $this->assertStringNotContainsString('33.5%', $text);
        $this->assertStringNotContainsString('.0000%', $text);
        $this->assertStringContainsString('\u002233.5\u0022', $html); // isian form (resubmit) tetap titik desimal
    }

    public function test_failed_submit_reopens_the_billing_form_with_the_typed_values(): void
    {
        $payload = ['adjustment_type' => 'BILLING_PERCENTAGE', 'reason' => 'x', 'billing_percentages' => [$this->termins[1] => '30', $this->termins[2] => '31', $this->termins[3] => '40']];
        $url = "/projects/{$this->project->id}";
        $this->actingAs($this->staff)->from($url)->post("$url/adjustments", $payload)->assertRedirect($url)->assertSessionHasErrorsIn('adjustment-new', ['billing_percentages']);

        $page = $this->actingAs($this->staff)->from($url)->followingRedirects()->post("$url/adjustments", $payload)
            ->assertSee('Total persentase termin yang dikoreksi harus tetap 100%', false)->assertSee('\u0022'.$this->termins[2].'\u0022:\u002231\u0022', false);
        $this->assertSame(['adjust-billing'], $this->openModals($page->getContent()));
    }

    public function test_bq_items_show_all_four_figures_in_the_item_cell_for_phones(): void
    {
        DB::table('bq_items')->insert([
            'project_id' => $this->project->id, 'item_name' => 'Item Mobile', 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'created_by' => $this->staff->id,
            'baseline_budget' => '1000000.00', 'effective_budget' => '1000000.00', 'negotiated_value' => '800000.00', 'margin_amount' => '200000.00', 'margin_pct' => '20.0000',
        ]);

        $html = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<dl class="mt-2 grid grid-cols-2[^>]*md:hidden">.*?Nilai RAB.*?Nilai Vendor/Subkon.*?Realisasi.*?Sisa.*?Rp1\.000\.000,00.*?</dl>#s', $html);
    }
}
