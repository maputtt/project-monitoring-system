<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// QA kartu Sisa RAB: kartu "Sisa RAB" = RAB Perubahan - Σ Nilai RAB item BQ non-ARCHIVED, bergerak nyata; kartu "Total Nilai BQ"; halaman cetak sama.
class SisaRabCardQaTest extends TestCase
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
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'DRAFT']);
        DB::table('projects')->where('id', $this->project->id)->update(['effective_rab' => '1000000.00', 'target_margin_amount' => '200000.00']);
    }

    /** Nilai kartu di halaman project dan halaman cetak; keduanya harus sama dengan summary. */
    private function cards(string $label): array
    {
        $show = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk();
        $print = $this->actingAs($this->staff)->get("/projects/{$this->project->id}/print")->assertOk();
        $this->assertSame($print->viewData('summary'), $show->viewData('summary'));

        $showCard = collect(explode('<div class="card relative', $show->getContent()))->first(fn ($c) => str_contains($c, ">$label<span x-data"));
        preg_match('/tabular-nums[^>]*>(Rp[^<]*)<\/p>/', (string) $showCard, $s);
        preg_match('/<div class="l">'.preg_quote($label, '/').'<\/div>\s*<div class="v[^"]*">([^<]*)<\/div>/', $print->getContent(), $p);

        return [$s[1] ?? null, $p[1] ?? null];
    }

    private function sisa(string $expected): void
    {
        $this->assertSame($expected, $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->viewData('summary')['unallocated']);
        $money = 'Rp'.number_format((float) $expected, 2, ',', '.');
        $this->assertSame([$money, $money], $this->cards('Sisa RAB'));
    }

    private function relocate(array $extra)
    {
        return $this->actingAs($this->staff)->post("/projects/{$this->project->id}/budget-mutations", $extra + ['reason' => 'Geser', 'file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf')]);
    }

    private function decide(string $type, ?int $source, ?int $dest, string $amount): void
    {
        $this->relocate(['mutation_type' => $type, 'source_bq_id' => $source, 'destination_bq_id' => $dest, 'amount' => $amount])->assertSessionHasNoErrors();
        $id = DB::table('bq_budget_mutations')->max('id');
        $this->actingAs($this->manager)->post("/budget-mutations/$id/approve")->assertSessionHasNoErrors();
    }

    public function test_sisa_rab_card_moves_for_real_on_add_relocate_return_and_archive(): void
    {
        $this->sisa('1000000.00');

        // Item baru tanpa vendor (Nilai Vendor/Subkon 0): Sisa RAB turun sebesar Nilai RAB-nya.
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/bq-items", ['item_name' => 'Tanpa vendor', 'baseline_budget' => '300000.00', 'negotiated_value' => '0.00'])->assertSessionHasNoErrors();
        $a = (int) DB::table('bq_items')->where('item_name', 'Tanpa vendor')->value('id');
        $this->sisa('700000.00');

        // Item kedua (Draft) lalu diarsipkan: naik lagi.
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/bq-items", ['item_name' => 'Akan batal', 'baseline_budget' => '200000.00', 'negotiated_value' => '150000.00'])->assertSessionHasNoErrors();
        $b = (int) DB::table('bq_items')->where('item_name', 'Akan batal')->value('id');
        $this->sisa('500000.00');
        $this->actingAs($this->staff)->postJson("/bq-items/$b/archive", ['reason' => 'Batal'])->assertRedirect();
        $this->sisa('700000.00');

        DB::table('bq_items')->where('id', $a)->update(['status' => 'APPROVED']);
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'ACTIVE']);
        $this->sisa('700000.00'); // approve tidak menggeser (nilai sudah terhitung sejak Draft)

        // Submit relokasi tidak menggeser; approve dari Sisa RAB ke item menurunkan Sisa RAB.
        $this->relocate(['mutation_type' => 'FROM_UNALLOCATED_RAB', 'destination_bq_id' => $a, 'amount' => '100000.00'])->assertSessionHasNoErrors();
        $this->sisa('700000.00');
        $this->actingAs($this->manager)->post('/budget-mutations/'.DB::table('bq_budget_mutations')->max('id').'/approve')->assertSessionHasNoErrors();
        $this->sisa('600000.00');
        $this->assertSame('400000.00', DB::table('bq_items')->find($a)->effective_budget);

        // Antar item BQ tidak mengubah total, jadi Sisa RAB tetap.
        $c = DB::table('bq_items')->insertGetId(['project_id' => $this->project->id, 'item_name' => 'Tujuan', 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'created_by' => $this->staff->id,
            'baseline_budget' => '0.00', 'effective_budget' => '0.00', 'negotiated_value' => '0.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000']);
        $this->sisa('600000.00');
        $this->decide('TRANSFER_BETWEEN_BQ', $a, $c, '10000.00');
        $this->sisa('600000.00');

        // Kembalikan ke Sisa RAB: naik.
        $this->decide('RETURN_TO_UNALLOCATED_RAB', $a, null, '50000.00');
        $this->sisa('650000.00');

        // Uji coba PM 2026-11-02: penjaga relokasi memakai angka kartu ini; melebihi kartu = 422 "Sisa RAB tidak cukup".
        $this->relocate(['mutation_type' => 'FROM_UNALLOCATED_RAB', 'destination_bq_id' => $a, 'amount' => '650000.01'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post('/budget-mutations/'.DB::table('bq_budget_mutations')->max('id').'/approve')
            ->assertSessionHasErrors(['mutation' => 'Sisa RAB tidak cukup: tersedia Rp650.000,00, relokasi Rp650.000,01.']);
        $this->sisa('650000.00');
    }

    public function test_total_nilai_bq_card_sums_vendor_for_normal_only_and_realisasi_and_ignores_archived(): void
    {
        $row = fn (string $type, string $status, string $rab, string $nego, ?string $actual) => DB::table('bq_items')->insertGetId([
            'project_id' => $this->project->id, 'item_name' => "$type $status", 'item_type' => $type, 'status' => $status, 'created_by' => $this->staff->id,
            'baseline_budget' => $rab, 'effective_budget' => $rab, 'negotiated_value' => $nego, 'actual_cost' => $actual, 'margin_amount' => '0.00', 'margin_pct' => '0.0000',
        ]);
        $row('NORMAL', 'APPROVED', '100000.10', '90000.05', '80000.01');
        $row('NORMAL', 'DRAFT', '50000.20', '40000.10', null);
        $row('OPERATIONAL', 'APPROVED', '20000.30', '7777.00', '15000.02'); // vendor OPERATIONAL tidak dihitung
        $row('NORMAL', 'ARCHIVED', '999999.99', '888888.88', '777777.77');

        $summary = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->viewData('summary');
        $this->assertSame(['170000.60', '130000.15', '95000.03', '829999.40'], [$summary['bq_effective'], $summary['bq_vendor'], $summary['bq_actual'], $summary['unallocated']]);
        foreach (["/projects/{$this->project->id}", "/projects/{$this->project->id}/print"] as $url) {
            $this->actingAs($this->staff)->get($url)->assertSee('Rp170.000,60')->assertSee('Vendor/Subkon Rp130.000,15 · Realisasi Rp95.000,03')->assertSee('Rp829.999,40')->assertDontSee('RAB Belum Dialokasikan');
        }
    }

    public function test_projected_profit_unchanged_and_equals_the_tooltip_formula(): void
    {
        $row = fn (string $type, string $status, string $rab, string $nego) => DB::table('bq_items')->insert([
            'project_id' => $this->project->id, 'item_name' => "$type $status", 'item_type' => $type, 'status' => $status, 'created_by' => $this->staff->id,
            'baseline_budget' => $rab, 'effective_budget' => $rab, 'negotiated_value' => $nego, 'margin_amount' => '0.00', 'margin_pct' => '0.0000',
        ]);
        $row('NORMAL', 'APPROVED', '300000.00', '280000.00');   // hemat 20.000
        $row('NORMAL', 'SUBMITTED', '100000.00', '95000.50');   // hemat 4.999,50
        $row('NORMAL', 'DRAFT', '60000.00', '1.00');            // Draft: Nilai RAB penuh dikembalikan
        $row('NORMAL', 'REVISION_REQUIRED', '40000.00', '1.00'); // idem
        $row('OPERATIONAL', 'APPROVED', '50000.00', '0.00');    // operasional: tanpa penghematan
        $row('NORMAL', 'ARCHIVED', '5000000.00', '1.00');       // diarsipkan: tidak dihitung

        $s = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->viewData('summary');
        // Rumus HEAD: target + (RAB - committed - reserve - pending) = 200.000 + (1.000.000 - 280.000 - 50.000 - 95.000,50)
        $this->assertSame('774999.50', $s['projected_profit']);
        // Rumus tooltip: target + Sisa RAB + penghematan (APPROVED+SUBMITTED NORMAL) + Nilai RAB Draft/Perlu Revisi (semua tipe)
        $tooltip = bcadd(bcadd(bcadd('200000.00', $s['unallocated'], 2), bcadd('20000.00', '4999.50', 2), 2), bcadd('60000.00', '40000.00', 2), 2);
        $this->assertSame($tooltip, $s['projected_profit']);

        // Operasional Draft (cadangan belum disetujui): Nilai RAB-nya ikut dikembalikan ke Projected Profit.
        DB::table('bq_items')->where('item_type', 'OPERATIONAL')->update(['status' => 'DRAFT']);
        $s = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->viewData('summary');
        $this->assertSame('824999.50', $s['projected_profit']); // 200.000 + (1.000.000 - 280.000 - 95.000,50)
        $this->assertSame(bcadd(bcadd(bcadd('200000.00', $s['unallocated'], 2), '24999.50', 2), '150000.00', 2), $s['projected_profit']); // rumus tooltip: Draft/Perlu Revisi = 60.000 + 40.000 + operasional 50.000
    }
}
