<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Uji coba PM 2026-11-02 (menunggu konfirmasi GM): penjaga keras RAB memakai angka kartu Sisa RAB (Σ Nilai RAB semua item BQ non-ARCHIVED). Skenario demo 102.
class UnifiedRabGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->project = Project::factory()->active()->assignedTo($this->staff)->create(['effective_rab' => '3054720000.00']);
    }

    private function bq(string $name, string $status, string $budget): int
    {
        return DB::table('bq_items')->insertGetId(['project_id' => $this->project->id, 'item_name' => $name, 'item_type' => 'NORMAL', 'status' => $status, 'created_by' => $this->staff->id,
            'baseline_budget' => $budget, 'effective_budget' => $budget, 'negotiated_value' => '0.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000']);
    }

    private function card(): string
    {
        return $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->viewData('summary')['unallocated'];
    }

    private function relocate(int $destination, string $amount): int
    {
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/budget-mutations", ['mutation_type' => 'FROM_UNALLOCATED_RAB', 'destination_bq_id' => $destination, 'amount' => $amount,
            'reason' => 'Tambah', 'file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf')])->assertSessionHasNoErrors();

        return (int) DB::table('bq_budget_mutations')->max('id');
    }

    public function test_hard_guard_uses_the_sisa_rab_card_basis_including_drafts_and_submitted(): void
    {
        $base = $this->bq('Pekerjaan', 'APPROVED', '2670000000.00');
        $rak = $this->bq('rak server', 'SUBMITTED', '270000000.00');
        $this->assertSame('114720000.00', $this->card());

        // Draf "AC" 300.000.000 melebihi kartu: tetap tersimpan (peringatan saja) dan boleh diajukan.
        $warning = 'Perhatian: Sisa RAB menjadi -Rp185.280.000,00; item BQ belum bisa disetujui sebelum total Nilai RAB ≤ RAB Perubahan.';
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/bq-items", ['item_name' => 'AC', 'baseline_budget' => '300000000.00', 'negotiated_value' => '0.00'])->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Item BQ ditambahkan sebagai Draf.')->assertSessionHas('warning', $warning);
        $ac = (int) DB::table('bq_items')->where('item_name', 'AC')->value('id');
        $this->assertSame('-185280000.00', $this->card());
        $this->actingAs($this->staff)->post("/bq-items/$ac/submit")->assertSessionHasNoErrors()->assertSessionHas('warning', $warning);
        $this->assertSame('SUBMITTED', DB::table('bq_items')->find($ac)->status);

        // Approve ditolak: 2.670.000.000 + 270.000.000 + 300.000.000 = 3.240.000.000 > 3.054.720.000; tidak ada yang berubah.
        $audits = DB::table('audit_logs')->count();
        foreach ([$ac, $rak] as $id) {
            $this->actingAs($this->manager)->post("/bq-items/$id/approve")->assertSessionHasErrors(['bq' => 'Sisa RAB tidak cukup: total Nilai RAB semua item BQ (termasuk yang masih diajukan/draf) Rp3.240.000.000,00 melebihi RAB Perubahan Rp3.054.720.000,00. Item yang belum disetujui: AC (Diajukan, Rp300.000.000,00); rak server (Diajukan, Rp270.000.000,00). Kecilkan atau arsipkan item yang belum disetujui (Manager dapat "Minta revisi" bila sudah diajukan), atau naikkan RAB Perubahan lewat penyesuaian target margin.']);
            $this->assertSame('SUBMITTED', DB::table('bq_items')->find($id)->status);
        }
        $this->assertSame($audits, DB::table('audit_logs')->count());

        // AC dikembalikan untuk revisi lalu diarsipkan: kartu pulih, rak server bisa disetujui (kartu tetap, nilainya sudah terhitung).
        $this->actingAs($this->manager)->post("/bq-items/$ac/request-revision", ['reason' => 'Terlalu besar'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post("/bq-items/$ac/archive", ['reason' => 'Batal'])->assertSessionHasNoErrors();
        $this->assertSame('114720000.00', $this->card());
        $this->actingAs($this->manager)->post("/bq-items/$rak/approve")->assertSessionHasNoErrors()->assertSessionMissing('warning');
        $this->assertSame('APPROVED', DB::table('bq_items')->find($rak)->status);
        $this->assertSame('114720000.00', $this->card());

        // Relokasi dari Sisa RAB: lebih 1 sen ditolak (rollback), tepat sebesar kartu lolos dan kartu jadi 0.
        $over = $this->relocate($base, '114720000.01');
        $this->actingAs($this->manager)->post("/budget-mutations/$over/approve")->assertSessionHasErrors(['mutation' => 'Sisa RAB tidak cukup: tersedia Rp114.720.000,00, relokasi Rp114.720.000,01.']);
        $this->assertSame(['SUBMITTED', '2670000000.00'], [DB::table('bq_budget_mutations')->find($over)->status, DB::table('bq_items')->find($base)->effective_budget]);
        $exact = $this->relocate($base, '114720000.00');
        $this->actingAs($this->manager)->post("/budget-mutations/$exact/approve")->assertSessionHasNoErrors();
        $this->assertSame(['2784720000.00', '0.00'], [DB::table('bq_items')->find($base)->effective_budget, $this->card()]);
    }

    public function test_warning_flash_only_when_sisa_rab_is_negative_after_add_or_edit(): void
    {
        $this->bq('Pekerjaan', 'APPROVED', '3000000000.00'); // Sisa 54.720.000
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/bq-items", ['item_name' => 'Pas', 'baseline_budget' => '54720000.00', 'negotiated_value' => '0.00'])
            ->assertSessionHasNoErrors()->assertSessionMissing('warning'); // tepat 0 bukan negatif
        $id = (int) DB::table('bq_items')->where('item_name', 'Pas')->value('id');

        $this->actingAs($this->staff)->put("/bq-items/$id", ['item_name' => 'Pas', 'baseline_budget' => '54720000.01', 'negotiated_value' => '0.00', 'version' => 1])
            ->assertSessionHasNoErrors()->assertSessionHas('warning', 'Perhatian: Sisa RAB menjadi -Rp0,01; item BQ belum bisa disetujui sebelum total Nilai RAB ≤ RAB Perubahan.');
        $this->actingAs($this->staff)->put("/bq-items/$id", ['item_name' => 'Pas', 'baseline_budget' => '1.00', 'negotiated_value' => '0.00', 'version' => 2])
            ->assertSessionHasNoErrors()->assertSessionMissing('warning');
    }

    public function test_activation_and_margin_adjustment_count_unapproved_items_too(): void
    {
        $submitted = Project::factory()->state(['status' => 'SUBMITTED', 'effective_rab' => '1000.00'])->assignedTo($this->staff)->create();
        $item = fn (string $status, string $type = 'NORMAL') => DB::table('bq_items')->insertGetId(['project_id' => $submitted->id, 'item_name' => "i$status$type", 'item_type' => $type, 'status' => $status, 'created_by' => $this->staff->id,
            'baseline_budget' => '500.00', 'effective_budget' => '500.00', 'negotiated_value' => '0.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000']);
        $item('DRAFT'); // belum disetujui, tetap dihitung
        $item('DRAFT', 'OPERATIONAL');
        $draft = $item('REVISION_REQUIRED');
        DB::table('bq_items')->where('id', $draft)->update(['item_name' => '<b>Besar</b>', 'effective_budget' => '600.00']);
        // Hanya item NORMAL belum disetujui yang disebut (operasional ikut disetujui saat aktivasi); terbesar dulu; nama di-escape Blade.
        $this->actingAs($this->manager)->from("/projects/{$submitted->id}")->post("/projects/{$submitted->id}/activate")->assertSessionHasErrors(['project' => 'Sisa RAB tidak cukup: total Nilai RAB semua item BQ (termasuk yang masih diajukan/draf) Rp1.600,00 melebihi RAB Perubahan Rp1.000,00. Item yang belum disetujui: <b>Besar</b> (Perlu Revisi, Rp600,00); iDRAFTNORMAL (Draf, Rp500,00). Kecilkan atau arsipkan item yang belum disetujui, atau naikkan RAB Perubahan lewat penyesuaian target margin.']);
        $this->from("/projects/{$submitted->id}")->followingRedirects()->post("/projects/{$submitted->id}/activate")->assertSee('&lt;b&gt;Besar&lt;/b&gt; (Perlu Revisi, Rp600,00)', false)->assertDontSee('<b>Besar</b> (Perlu', false);
        $this->assertSame('SUBMITTED', DB::table('projects')->find($submitted->id)->status);

        DB::table('bq_items')->where('id', $draft)->update(['status' => 'ARCHIVED']);
        $this->actingAs($this->manager)->post("/projects/{$submitted->id}/activate")->assertSessionHasNoErrors();
        $this->assertSame('ACTIVE', DB::table('projects')->find($submitted->id)->status);
    }
}
