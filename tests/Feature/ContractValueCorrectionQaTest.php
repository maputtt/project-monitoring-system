<?php

namespace Tests\Feature;

use App\Http\Controllers\ProjectClosingController;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// QA independen Paket 3 (koreksi nilai kontrak, 7B): celah yang tidak dicakup ContractValueCorrectionTest.
// Fokus: hitung ulang independen (bcmath murni, tanpa Money) untuk banyak kombinasi, baseline dan snapshot invoice tidak berubah, interaksi dengan adjustment target margin,
// usulan ganda, dan penyimpangan input.
class ContractValueCorrectionQaTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        DB::table('tax_rates')->insert([
            ['tax_type' => 'PPN', 'name' => 'PPN 11', 'vendor_qualification' => null, 'rate' => '11.00', 'effective_from' => '2025-01-01', 'created_by' => $this->staff->id],
            ['tax_type' => 'PPH', 'name' => 'PPh B', 'vendor_qualification' => 'B', 'rate' => '2.65', 'effective_from' => '2025-01-01', 'created_by' => $this->staff->id],
        ]);
    }

    /** @return array{0: Project, 1: list<int>} */
    private function project(string $contract, array $percentages, string $mode = 'INCLUSIVE_PPN', string $pct = '15.0000', string $status = 'ACTIVE'): array
    {
        $base = $mode === 'EXCLUSIVE_PPN' ? $this->half(bcmul($contract, '1.110000', 8)) : $contract;
        $margin = $this->half(bcdiv(bcmul($base, $pct, 6), '100', 8));
        $project = Project::factory()->assignedTo($this->staff)->create([
            'created_by' => $this->staff->id, 'status' => $status, 'contract_value' => $contract, 'contract_tax_mode' => $mode, 'margin_base_ppn_rate_snapshot' => $mode === 'EXCLUSIVE_PPN' ? '11.0000' : null,
            'margin_base_amount' => $base, 'target_margin_pct' => $pct, 'target_margin_amount' => $margin, 'initial_rab' => bcsub($base, $margin, 2), 'effective_rab' => bcsub($base, $margin, 2),
        ]);
        $ids = [];
        foreach ($percentages as $i => $p) {
            $ids[$i + 1] = DB::table('invoices')->insertGetId([
                'project_id' => $project->id, 'department' => 'BM', 'sequence_number' => $i + 1, 'billing_type' => 'TERMIN', 'billing_percentage' => $p,
                'planned_amount' => '1.00', 'workflow_status' => 'PLANNED', 'created_by' => $this->staff->id,
            ]);
        }

        return [$project, $ids];
    }

    /** Pembulatan half-up murni bcmath untuk nilai positif dengan sampai 8 desimal. */
    private function half(string $x): string
    {
        return bcadd($x, '0.005', 2);
    }

    private function submit(Project $p, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->staff)->post("/projects/{$p->id}/adjustments", $extra + ['adjustment_type' => 'CONTRACT_VALUE', 'margin_mode' => 'PCT', 'margin_value' => '15', 'reason' => 'Termin batal']);
    }

    private function last(): object
    {
        return DB::table('project_adjustments')->orderByDesc('id')->first();
    }

    private function row(Project $p): object
    {
        return DB::table('projects')->where('id', $p->id)->first();
    }

    public function test_independent_bcmath_recomputation_over_random_cases(): void
    {
        mt_srand(20261020);
        for ($case = 0; $case < 120; $case++) {
            $contract = (string) mt_rand(1, 999999999).'.'.str_pad((string) mt_rand(0, 99), 2, '0', STR_PAD_LEFT);
            $n = mt_rand(2, 6);
            $cuts = [];
            for ($i = 0; $i < $n - 1; $i++) {
                $cuts[] = mt_rand(1, 999999);
            }
            sort($cuts);
            $units = []; // persentase 4 desimal, jumlah 100.0000, tiap termin > 0
            $prev = 0;
            foreach ([...$cuts, 1000000] as $c) {
                $units[] = $c - $prev;
                $prev = $c;
            }
            if (min($units) < 1) {
                continue;
            }
            $pcts = array_map(fn ($u) => bcdiv((string) $u, '10000', 4), $units);
            $mode = ['INCLUSIVE_PPN', 'EXCLUSIVE_PPN', 'NON_PPN'][mt_rand(0, 2)];
            [$p, $ids] = $this->project($contract, $pcts, $mode, '12.5000');

            $cancelled = array_filter(array_keys($ids), fn () => mt_rand(0, 2) === 0) ?: [mt_rand(1, $n)];
            DB::table('invoices')->where('project_id', $p->id)->whereIn('sequence_number', $cancelled)->update(['workflow_status' => 'CANCELLED']);

            // Rujukan independen.
            $allocated = '0.00';
            $shares = [];
            foreach ($pcts as $i => $pct) {
                $share = $i === $n - 1 ? bcsub($contract, $allocated, 2) : $this->half(bcdiv(bcmul($contract, $pct, 6), '100', 8));
                $allocated = bcadd($allocated, $share, 2);
                $shares[$i + 1] = $share;
            }
            $billable = '0.00';
            foreach ($shares as $seq => $share) {
                if (! in_array($seq, $cancelled, true)) {
                    $billable = bcadd($billable, $share, 2);
                }
            }
            $base = $mode === 'EXCLUSIVE_PPN' ? $this->half(bcmul($billable, '1.110000', 8)) : $billable;
            $useAmount = mt_rand(0, 3) === 0;
            if ($useAmount) {
                $amount = bcadd(bcdiv((string) mt_rand(0, (int) bcmul($base, '100', 0) > PHP_INT_MAX ? PHP_INT_MAX : (int) bcmul($base, '100', 0)), '100', 2), '0', 2);
                $amount = bccomp($amount, $base, 2) > 0 ? $base : $amount;
                $margin = $amount;
                $input = ['margin_mode' => 'AMOUNT', 'margin_value' => $amount];
            } else {
                $margin = $this->half(bcdiv(bcmul($base, '12.5000', 6), '100', 8));
                $input = ['margin_mode' => 'PCT', 'margin_value' => '12.5'];
            }

            $this->assertSame($billable, $p->fresh()->billableContractValue(), "case $case billable");
            $this->submit($p, $input)->assertSessionHasNoErrors();
            $this->actingAs($this->manager)->post('/adjustments/'.$this->last()->id.'/approve')->assertSessionHasNoErrors();

            $after = $this->row($p);
            $this->assertSame([$billable, $base, $margin, bcsub($base, $margin, 2), $contract], [$after->contract_value, $after->margin_base_amount, $after->target_margin_amount, $after->effective_rab, $after->initial_contract_value], "case $case $mode ".json_encode($pcts).' cancelled '.json_encode($cancelled));
            $this->assertSame(bcsub($billable, $contract, 2), $this->last()->difference_amount, "case $case difference");
        }
    }

    public function test_submitted_and_paid_snapshots_and_baselines_survive_the_correction(): void
    {
        [$p, $ids] = $this->project('1110000000.00', ['30.0000', '30.0000', '40.0000']);
        $this->actingAs($this->staff)->post("/invoices/{$ids[1]}/submit", ['version' => 1, 'invoice_number' => 'INV/1', 'billing_date' => '2026-08-20', 'due_date' => '2026-12-31',
            'pph_qualification' => 'B', 'file' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')])->assertSessionHasNoErrors();
        DB::table('invoices')->where('id', $ids[3])->update(['workflow_status' => 'CANCELLED']);
        $invoicesBefore = DB::table('invoices')->orderBy('id')->get()->all();

        $this->submit($p)->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post('/adjustments/'.$this->last()->id.'/approve')->assertSessionHasNoErrors();

        $this->assertEquals($invoicesBefore, DB::table('invoices')->orderBy('id')->get()->all()); // tidak satu kolom pun di invoice yang berubah
        $row = $this->row($p);
        $this->assertSame(['666000000.00', '1110000000.00', '943500000.00'], [$row->contract_value, $row->initial_contract_value, $row->initial_rab]);

        // Termin ke-3 sudah CANCELLED, jadi tidak ada lagi yang dikoreksi dan DPP termin yang tersisa tetap dari nilai SPK awal.
        $this->actingAs($this->staff)->getJson("/invoices/{$ids[2]}/tax-preview?billing_date=2026-08-20&pph_qualification=B")->assertOk()
            ->assertJsonPath('dpp_amount', '300000000.00')->assertJsonPath('subtotal_amount', '333000000.00');
    }

    public function test_exclusive_and_non_ppn_planned_termins_keep_their_dpp_after_the_correction(): void
    {
        foreach (['EXCLUSIVE_PPN' => ['300000000.00', '33000000.00', '333000000.00'], 'NON_PPN' => ['300000000.00', '0.00', '300000000.00']] as $mode => $expected) {
            [$p, $ids] = $this->project('1000000000.00', ['30.0000', '30.0000', '40.0000'], $mode);
            DB::table('invoices')->where('id', $ids[3])->update(['workflow_status' => 'CANCELLED']);
            $preview = fn () => $this->actingAs($this->staff)->getJson("/invoices/{$ids[2]}/tax-preview?billing_date=2026-08-20&pph_qualification=B")->assertOk()->json();

            $before = $preview();
            $this->submit($p)->assertSessionHasNoErrors();
            $this->actingAs($this->manager)->post('/adjustments/'.$this->last()->id.'/approve')->assertSessionHasNoErrors();
            $after = $preview();

            $this->assertSame($before, $after, $mode);
            $this->assertSame($expected, [$after['dpp_amount'], $after['ppn_amount'], $after['subtotal_amount']], $mode);
        }
    }

    public function test_duplicate_pending_corrections_are_rejected_so_no_dead_end_row_exists(): void
    {
        [$p] = $this->project('1110000000.00', ['30.0000', '30.0000', '40.0000']);
        DB::table('invoices')->where('project_id', $p->id)->where('sequence_number', 3)->update(['workflow_status' => 'CANCELLED']);
        $this->submit($p)->assertSessionHasNoErrors();
        $first = $this->last()->id;

        // Klik ganda atau dua pengaju: usulan kedua ditolak selama yang pertama SUBMITTED, juga selama REVISION_REQUIRED.
        $this->submit($p)->assertSessionHasErrors(['adjustment_type' => 'Masih ada Koreksi Nilai Tertagih yang belum selesai.'], null, 'adjustment-new');
        $this->actingAs($this->manager)->post("/adjustments/$first/request-revision", ['reason' => 'cek'])->assertSessionHasNoErrors();
        $this->submit($p)->assertSessionHasErrors('adjustment_type', null, 'adjustment-new');
        $this->assertSame(1, DB::table('project_adjustments')->where('project_id', $p->id)->count());
        $this->actingAs($this->staff)->put("/adjustments/$first", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '15', 'reason' => 'ulang'])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->post("/adjustments/$first/approve")->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/projects/{$p->id}/complete")->assertSessionHasNoErrors();
        $this->assertSame('COMPLETED', $this->row($p)->status);
        $blocked = collect(ProjectClosingController::readiness($p->fresh())['checks'])->filter(fn ($c) => $c['blockers'] !== [])->pluck('label')->all();
        $this->assertSame([], array_values(array_intersect(['Nilai tertagih sesuai termin yang tidak batal', 'Tidak ada pengajuan pending atau revisi'], $blocked)));
    }

    public function test_pending_correction_blocks_completion_and_closing_until_decided(): void
    {
        [$p] = $this->project('1110000000.00', ['30.0000', '30.0000', '40.0000']);
        DB::table('invoices')->where('project_id', $p->id)->where('sequence_number', 3)->update(['workflow_status' => 'CANCELLED']);
        $this->submit($p)->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->post("/projects/{$p->id}/complete")->assertStatus(409);
        $this->assertSame('ACTIVE', $this->row($p)->status);

        // Tertinggal di COMPLETED (diajukan sebelum selesai tidak mungkin; paksa lewat DB): closing ditahan oleh dua pemeriksa sekaligus.
        DB::table('projects')->where('id', $p->id)->update(['status' => 'COMPLETED']);
        $r = ProjectClosingController::readiness($p->fresh());
        $this->assertFalse($r['ready']);
        $this->assertSame([], array_values(array_diff(['Nilai tertagih sesuai termin yang tidak batal', 'Tidak ada pengajuan pending atau revisi'], collect($r['checks'])->filter(fn ($c) => $c['blockers'] !== [])->pluck('label')->all())));
    }

    public function test_correction_then_target_margin_interplay_recovers_through_revision(): void
    {
        [$p] = $this->project('1110000000.00', ['30.0000', '30.0000', '40.0000']);
        DB::table('invoices')->where('project_id', $p->id)->where('sequence_number', 3)->update(['workflow_status' => 'CANCELLED']);
        $this->submit($p)->assertSessionHasNoErrors();
        $contractRow = $this->last()->id;
        $this->actingAs($this->staff)->post("/projects/{$p->id}/adjustments", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '10', 'reason' => 'turun margin'])->assertSessionHasNoErrors();
        $marginRow = $this->last()->id;

        // Margin 10% dari 1.110.000.000 disetujui lebih dulu: usulan kontrak basi.
        $this->actingAs($this->manager)->post("/adjustments/$marginRow/approve")->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/adjustments/$contractRow/approve")->assertStatus(409);
        $this->actingAs($this->manager)->post("/adjustments/$contractRow/request-revision", ['reason' => 'margin berubah'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->put("/adjustments/$contractRow", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '10', 'reason' => 'ulang'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/adjustments/$contractRow/approve")->assertSessionHasNoErrors();
        $row = $this->row($p);
        $this->assertSame(['666000000.00', '10.0000', '66600000.00', '599400000.00', '943500000.00'], [$row->contract_value, $row->target_margin_pct, $row->target_margin_amount, $row->effective_rab, $row->initial_rab]);

        // Kebalikannya: koreksi disetujui lebih dulu, usulan margin (diajukan sebelum koreksi) basi lalu diperbaiki di atas margin base baru.
        [$q] = $this->project('1110000000.00', ['30.0000', '30.0000', '40.0000']);
        DB::table('invoices')->where('project_id', $q->id)->where('sequence_number', 3)->update(['workflow_status' => 'CANCELLED']);
        $this->actingAs($this->staff)->post("/projects/{$q->id}/adjustments", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '10', 'reason' => 'turun margin'])->assertSessionHasNoErrors();
        $m = $this->last()->id;
        $this->submit($q, ['margin_value' => '15'])->assertSessionHasNoErrors();
        $c = $this->last()->id;
        $this->actingAs($this->manager)->post("/adjustments/$c/approve")->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/adjustments/$m/approve")->assertStatus(409);
        $this->actingAs($this->manager)->post("/adjustments/$m/request-revision", ['reason' => 'kontrak berubah'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->put("/adjustments/$m", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '10', 'reason' => 'ulang'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/adjustments/$m/approve")->assertSessionHasNoErrors();
        $row = $this->row($q);
        $this->assertSame(['666000000.00', '666000000.00', '66600000.00', '599400000.00'], [$row->contract_value, $row->margin_base_amount, $row->target_margin_amount, $row->effective_rab]);
    }

    public function test_client_cannot_inject_figures_or_switch_the_type_of_a_stored_proposal(): void
    {
        [$p] = $this->project('1110000000.00', ['30.0000', '30.0000', '40.0000']);
        DB::table('invoices')->where('project_id', $p->id)->where('sequence_number', 3)->update(['workflow_status' => 'CANCELLED']);

        $this->submit($p, ['contract_value' => '1.00', 'new_contract_value' => '1.00', 'margin_base_amount' => '1.00', 'effective_rab' => '1.00', 'difference_amount' => '0.00', 'status' => 'APPROVED'])->assertSessionHasNoErrors();
        $a = $this->last();
        $this->assertSame(['SUBMITTED', '-444000000.00', '666000000.00'], [$a->status, $a->difference_amount, json_decode($a->new_data, true)['contract_value']]);

        // Jenis usulan tersimpan tidak bisa diganti lewat ajukan ulang.
        $this->actingAs($this->manager)->post("/adjustments/{$a->id}/request-revision", ['reason' => 'x']);
        $this->actingAs($this->staff)->put("/adjustments/{$a->id}", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'adjustment_type' => 'TARGET_MARGIN', 'margin_mode' => 'PCT', 'margin_value' => '15', 'reason' => 'x'])->assertSessionHasNoErrors();
        $this->assertSame('CONTRACT_VALUE', $this->last()->adjustment_type);

        // Nilai tidak wajar.
        foreach ([['margin_value' => '-1'], ['margin_value' => '1e2'], ['margin_value' => '15.00001'], ['margin_mode' => 'AMOUNT', 'margin_value' => '12.345'], ['reason' => ''], ['reason' => str_repeat('a', 1001)]] as $bad) {
            $this->submit($p, $bad)->assertSessionHasErrors(null, null, 'adjustment-new');
        }
        $this->assertSame(1, DB::table('project_adjustments')->count());
    }

    public function test_reason_is_escaped_on_the_project_page_and_in_the_inbox(): void
    {
        [$p] = $this->project('1110000000.00', ['30.0000', '30.0000', '40.0000']);
        DB::table('invoices')->where('project_id', $p->id)->where('sequence_number', 3)->update(['workflow_status' => 'CANCELLED']);
        $xss = '<script>alert(1)</script><img src=x onerror=alert(2)>';
        $this->submit($p, ['reason' => $xss])->assertSessionHasNoErrors();

        foreach ([$this->staff, $this->manager] as $user) {
            $html = $this->actingAs($user)->get("/projects/{$p->id}")->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
            $this->assertStringNotContainsString('<img src=x', $html);
        }
        $this->actingAs($this->manager)->post('/adjustments/'.$this->last()->id.'/request-revision', ['reason' => $xss])->assertSessionHasNoErrors();
        $html = $this->actingAs($this->staff)->get("/projects/{$p->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('name="_modal" value="adjust-', $html);
    }

    public function test_system_admin_and_wrong_role_paths_are_closed_and_audit_is_complete(): void
    {
        [$p] = $this->project('1110000000.00', ['30.0000', '30.0000', '40.0000']);
        DB::table('invoices')->where('project_id', $p->id)->where('sequence_number', 3)->update(['workflow_status' => 'CANCELLED']);
        $admin = User::factory()->role('SYSTEM_ADMIN', null)->create();
        $this->post('/projects/'.$p->id.'/adjustments', [])->assertRedirect('/login');
        $this->submit($p, as: $admin)->assertForbidden();

        $this->submit($p)->assertSessionHasNoErrors();
        $id = $this->last()->id;
        $this->actingAs($admin)->post("/adjustments/$id/approve")->assertForbidden();
        $this->actingAs($this->manager)->post("/adjustments/$id/request-revision", ['reason' => 'koreksi'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->put("/adjustments/$id", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '15', 'reason' => 'x'])->assertForbidden();
        $other = User::factory()->role('STAFF_PIC', 'BM')->create();
        DB::table('project_user_assignments')->insert(['project_id' => $p->id, 'user_id' => $other->id, 'assignment_role' => 'PIC', 'assigned_by' => $this->staff->id, 'is_active' => true]);
        $this->actingAs($other)->put("/adjustments/$id", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '15', 'reason' => 'x'])->assertForbidden(); // bukan pengaju
        $this->actingAs($this->staff)->put("/adjustments/$id", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '15', 'reason' => 'ulang'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertSessionHasNoErrors();

        $actions = DB::table('audit_logs')->where('entity_type', 'PROJECT_ADJUSTMENT')->where('entity_id', $id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['SUBMIT', 'REQUEST_REVISION', 'SUBMIT', 'APPROVE'], $actions);
        $approve = DB::table('audit_logs')->where('entity_type', 'PROJECT_ADJUSTMENT')->where('action', 'APPROVE')->first();
        $this->assertSame($this->manager->id, (int) $approve->user_id);
    }

    // Revisi 2026-10-22: "Nilai Project" = nilai SPK (tidak berubah); RAB dan target margin memakai nilai setelah koreksi.
    public function test_dashboard_kpi_keeps_spk_value_and_uses_corrected_rab_and_margin(): void
    {
        [$p] = $this->project('1110000000.00', ['30.0000', '30.0000', '40.0000']);
        DB::table('invoices')->where('project_id', $p->id)->where('sequence_number', 3)->update(['workflow_status' => 'CANCELLED']);
        $this->actingAs($this->manager)->get('/dashboard')->assertOk()->assertViewHas('kpi', fn ($kpi) => $kpi['contract'] === '1110000000.00' && $kpi['rab'] === '943500000.00');

        $this->submit($p)->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post('/adjustments/'.$this->last()->id.'/approve')->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->get('/dashboard')->assertOk()->assertViewHas('kpi', fn ($kpi) => $kpi['contract'] === '1110000000.00' && $kpi['rab'] === '566100000.00' && $kpi['margin'] === '99900000.00');
    }
}
