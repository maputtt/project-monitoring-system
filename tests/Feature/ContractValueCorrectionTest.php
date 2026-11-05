<?php

namespace Tests\Feature;

use App\Http\Controllers\ProjectAdjustmentController;
use App\Http\Controllers\ProjectClosingController;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Paket 3 (7B): koreksi nilai kontrak karena termin batal. Nilai kontrak baru selalu dihitung server dari termin tidak batal (initial_contract_value dibagi menurut persentase termin).
// Approve mengubah contract_value, margin_base_amount, target margin, effective_rab di bawah kunci; initial_contract_value dan initial_rab tidak pernah berubah; termin PLANNED tidak ikut menyusut.
class ContractValueCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private Project $project;

    /** @var list<int> invoice id urut termin */
    private array $termins = [];

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

    /** Contoh 1 desain: INCLUSIVE PPN 11%, kontrak 1.110.000.000,00, termin 30/30/40, margin 15% = 166.500.000,00, RAB 943.500.000,00. */
    private function project(array $attrs = [], array $percentages = ['30.0000', '30.0000', '40.0000']): void
    {
        $this->project = Project::factory()->assignedTo($this->staff)->create($attrs + [
            'created_by' => $this->staff->id, 'status' => 'ACTIVE', 'contract_value' => '1110000000.00', 'contract_tax_mode' => 'INCLUSIVE_PPN',
            'margin_base_amount' => '1110000000.00', 'target_margin_pct' => '15.0000', 'target_margin_amount' => '166500000.00', 'initial_rab' => '943500000.00', 'effective_rab' => '943500000.00',
        ]);
        foreach ($percentages as $i => $pct) {
            $this->termins[$i + 1] = DB::table('invoices')->insertGetId([
                'project_id' => $this->project->id, 'department' => 'BM', 'sequence_number' => $i + 1, 'billing_type' => 'TERMIN', 'billing_percentage' => $pct,
                'planned_amount' => '1.00', 'workflow_status' => 'PLANNED', 'created_by' => $this->staff->id,
            ]);
        }
    }

    private function cancel(int ...$sequences): void
    {
        DB::table('invoices')->where('project_id', $this->project->id)->whereIn('sequence_number', $sequences)->update(['workflow_status' => 'CANCELLED']);
    }

    private function submit(array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->staff)->post("/projects/{$this->project->id}/adjustments", $extra + ['adjustment_type' => 'CONTRACT_VALUE', 'margin_mode' => 'PCT', 'margin_value' => '15', 'reason' => 'Termin 3 dibatalkan klien']);
    }

    private function row(?int $id = null): object
    {
        return DB::table('project_adjustments')->when($id, fn ($q) => $q->where('id', $id))->orderByDesc('id')->first();
    }

    private function data(object $row, string $column = 'new_data'): array
    {
        return json_decode($row->$column, true);
    }

    private function figures(): array
    {
        $p = DB::table('projects')->where('id', $this->project->id)->first();

        return [$p->contract_value, $p->margin_base_amount, $p->target_margin_pct, $p->target_margin_amount, $p->effective_rab, $p->initial_contract_value, $p->initial_rab, (int) $p->version];
    }

    private function audits(string $action): int
    {
        return DB::table('audit_logs')->where('entity_type', 'PROJECT_ADJUSTMENT')->where('action', $action)->count();
    }

    private function bq(string $budget, ?string $actual = null): void
    {
        DB::table('bq_items')->insert(['project_id' => $this->project->id, 'item_name' => 'Item', 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'created_by' => $this->staff->id,
            'baseline_budget' => $budget, 'effective_budget' => $budget, 'negotiated_value' => '0.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000', 'actual_cost' => $actual]);
    }

    public function test_example_1_submit_then_approve_corrects_figures_and_never_touches_baselines(): void
    {
        $this->project();
        $this->cancel(3);
        $before = $this->figures();

        $this->submit(['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf')])->assertSessionHasNoErrors()->assertRedirect("/projects/{$this->project->id}");
        $this->assertSame([0, []], [DB::table('documents')->where('entity_type', 'PROJECT_ADJUSTMENT')->count(), Storage::disk('local')->allFiles()]); // CONTRACT_VALUE tidak memakai file

        $a = $this->row();
        $this->assertSame(['CONTRACT_VALUE', 'SUBMITTED', $this->staff->id, '-444000000.00'], [$a->adjustment_type, $a->status, $a->submitted_by, $a->difference_amount]);
        $this->assertEquals(['contract_value' => '1110000000.00', 'margin_base_amount' => '1110000000.00', 'target_margin_pct' => '15.0000', 'target_margin_amount' => '166500000.00', 'effective_rab' => '943500000.00'], $this->data($a, 'old_data'));
        $this->assertEquals(['contract_value' => '666000000.00', 'margin_base_amount' => '666000000.00', 'target_margin_pct' => '15.0000', 'target_margin_amount' => '99900000.00', 'effective_rab' => '566100000.00',
            'margin_mode' => 'PCT', 'margin_value' => '15.0000', 'cancelled_sequences' => [3]], $this->data($a));
        $this->assertSame([$before, 1, 0], [$this->figures(), $this->audits('SUBMIT'), $this->audits('APPROVE')]); // mengajukan tidak mengubah project

        $this->actingAs($this->manager)->post("/adjustments/{$a->id}/approve")->assertSessionHasNoErrors()->assertRedirect("/projects/{$this->project->id}");

        // contract, base, pct, margin, RAB berubah; initial_contract_value dan initial_rab tetap; version naik.
        $this->assertSame(['666000000.00', '666000000.00', '15.0000', '99900000.00', '566100000.00', '1110000000.00', '943500000.00', 2], $this->figures());
        $approved = $this->row($a->id);
        $this->assertSame(['APPROVED', $this->manager->id, '-444000000.00'], [$approved->status, $approved->approved_by, $approved->difference_amount]);
        $log = DB::table('audit_logs')->where('action', 'APPROVE')->where('entity_type', 'PROJECT_ADJUSTMENT')->sole();
        $this->assertSame(['666000000.00', '1110000000.00'], [json_decode($log->data_after, true)['contract_value'], json_decode($log->data_before, true)['contract_value']]);

        // Setelah itu tidak ada lagi yang bisa dikoreksi, dan persetujuan ulang ditolak (idempoten).
        $this->submit()->assertSessionHasErrors('adjustment_type', null, 'adjustment-new');
        $this->actingAs($this->manager)->post("/adjustments/{$a->id}/approve")->assertStatus(409);
        $this->assertSame([2, 1], [$this->figures()[7], $this->audits('APPROVE')]);
    }

    public function test_remaining_planned_termins_keep_their_original_amounts_after_the_correction(): void
    {
        $this->project();
        $this->cancel(3);
        $this->submit();
        $this->actingAs($this->manager)->post('/adjustments/'.$this->row()->id.'/approve')->assertSessionHasNoErrors();

        $this->actingAs($this->staff)->getJson("/invoices/{$this->termins[2]}/tax-preview?billing_date=2026-08-20&pph_qualification=B")->assertOk()
            ->assertJsonPath('dpp_amount', '300000000.00')->assertJsonPath('ppn_amount', '33000000.00')->assertJsonPath('subtotal_amount', '333000000.00');

        $this->actingAs($this->staff)->post("/invoices/{$this->termins[2]}/submit", ['version' => 1, 'invoice_number' => 'INV/2', 'billing_date' => '2026-08-20', 'due_date' => '2026-12-31',
            'pph_qualification' => 'B', 'file' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')])->assertSessionHasNoErrors();
        $inv = DB::table('invoices')->where('id', $this->termins[2])->first();
        $this->assertSame(['300000000.00', '33000000.00', '333000000.00'], [$inv->dpp_amount, $inv->ppn_amount, $inv->subtotal_amount]);
    }

    public function test_example_2_rounding_half_up_with_unequal_percentages(): void
    {
        $this->project(['contract_value' => '671151608.00', 'margin_base_amount' => '671151608.00', 'target_margin_pct' => '12.5000', 'target_margin_amount' => '83893951.00', 'initial_rab' => '587257657.00', 'effective_rab' => '587257657.00'],
            ['33.3333', '33.3333', '33.3334']);
        $this->assertSame('671151608.00', $this->project->fresh()->billableContractValue());
        $this->cancel(3);
        // Bagian termin: 223.716.978,95 / 223.716.978,95 / 223.717.650,10 (termin terakhir menyerap selisih).
        $this->assertSame('447433957.90', $this->project->fresh()->billableContractValue());

        $this->submit(['margin_value' => '12.5'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post('/adjustments/'.$this->row()->id.'/approve')->assertSessionHasNoErrors();

        // 447.433.957,90 x 12,5% = 55.929.244,7375 -> 55.929.244,74
        $this->assertSame(['447433957.90', '447433957.90', '12.5000', '55929244.74', '391504713.16', '671151608.00', '587257657.00', 2], $this->figures());
    }

    public function test_example_3_all_termins_cancelled_gives_zero_contract_and_negative_closing_when_costs_exist(): void
    {
        $this->project(['status' => 'COMPLETED', 'contract_value' => '1000000000.00', 'margin_base_amount' => '1000000000.00', 'target_margin_pct' => '15.0000', 'target_margin_amount' => '150000000.00', 'initial_rab' => '850000000.00', 'effective_rab' => '850000000.00']);
        $this->cancel(1, 2, 3);
        $this->bq('850000000.00', '200000000.00'); // budget BQ jauh di atas RAB baru 0: hanya peringatan

        // Project COMPLETED menerima koreksi nilai kontrak; penutupan ditahan sampai dikoreksi.
        $this->assertFalse(ProjectClosingController::readiness($this->project->fresh())['ready']);
        $this->actingAs($this->manager)->postJson("/projects/{$this->project->id}/close")->assertStatus(422)->assertJsonPath('errors.closing.0', 'Financial closing ditolak. Nilai tertagih sesuai termin yang tidak batal: 3 termin batal belum dikoreksi; ajukan Koreksi Nilai Tertagih.');

        $this->submit(['margin_mode' => 'AMOUNT', 'margin_value' => '0'])->assertSessionHasNoErrors();
        $this->assertSame('-1000000000.00', $this->row()->difference_amount);
        $this->actingAs($this->manager)->post('/adjustments/'.$this->row()->id.'/approve')->assertSessionHasNoErrors();
        $this->assertSame(['0.00', '0.00', '0.0000', '0.00', '0.00', '1000000000.00', '850000000.00', 2], $this->figures());

        $r = ProjectClosingController::readiness($this->project->fresh());
        $this->assertSame([true, '-200000000.00', '-200000000.00'], [$r['ready'], $r['final_sisa'], $r['actual_profit']]);
        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/close")->assertSessionHasNoErrors();
        $closed = DB::table('projects')->where('id', $this->project->id)->first();
        $this->assertSame(['FINANCIALLY_CLOSED', '-200000000.00'], [$closed->status, $closed->actual_profit_amount]);
    }

    public function test_example_1_closing_numbers_use_the_corrected_rab(): void
    {
        $this->project(['status' => 'COMPLETED']);
        $this->cancel(3);
        $this->bq('566100000.00', '560000000.00');
        $this->submit();
        $this->actingAs($this->manager)->post('/adjustments/'.$this->row()->id.'/approve')->assertSessionHasNoErrors();

        $r = ProjectClosingController::readiness($this->project->fresh()); // termin 1 dan 2 masih PLANNED menahan, tetapi angka finalnya sudah tepat
        $this->assertSame(['6100000.00', '106000000.00'], [$r['final_sisa'], $r['actual_profit']]);
        $this->assertSame('Nilai tertagih sesuai termin yang tidak batal', $r['checks'][2]['label']);
        $this->assertSame([], $r['checks'][2]['blockers']);
    }

    public function test_exclusive_ppn_margin_base_adds_the_snapshot_rate_to_the_new_contract(): void
    {
        // Kontrak 1.000.000.000,00 + PPN 11% -> margin base 1.110.000.000,00; margin 10% = 111.000.000,00.
        $this->project(['contract_value' => '1000000000.00', 'contract_tax_mode' => 'EXCLUSIVE_PPN', 'margin_base_ppn_rate_snapshot' => '11.0000', 'margin_base_amount' => '1110000000.00',
            'target_margin_pct' => '10.0000', 'target_margin_amount' => '111000000.00', 'initial_rab' => '999000000.00', 'effective_rab' => '999000000.00'], ['50.0000', '50.0000']);
        $this->cancel(2);

        $this->submit(['margin_value' => '10'])->assertSessionHasNoErrors();
        $this->assertEquals(['contract_value' => '500000000.00', 'margin_base_amount' => '555000000.00', 'target_margin_pct' => '10.0000', 'target_margin_amount' => '55500000.00', 'effective_rab' => '499500000.00',
            'margin_mode' => 'PCT', 'margin_value' => '10.0000', 'cancelled_sequences' => [2]], $this->data($this->row()));

        $this->actingAs($this->manager)->post('/adjustments/'.$this->row()->id.'/approve')->assertSessionHasNoErrors();
        $this->assertSame(['500000000.00', '555000000.00', '10.0000', '55500000.00', '499500000.00', '1000000000.00', '999000000.00', 2], $this->figures());
    }

    public function test_amount_margin_is_limited_to_the_new_margin_base(): void
    {
        $this->project();
        $this->cancel(3); // margin base baru 666.000.000,00

        $this->submit(['margin_mode' => 'AMOUNT', 'margin_value' => '666000000.01'])->assertSessionHasErrors('margin_value', null, 'adjustment-new');
        $this->assertSame(0, DB::table('project_adjustments')->count());

        $this->submit(['margin_mode' => 'AMOUNT', 'margin_value' => '666000000.00'])->assertSessionHasNoErrors(); // sama dengan margin base: RAB 0
        $this->assertSame(['100.0000', '0.00'], [$this->data($this->row())['target_margin_pct'], $this->data($this->row())['effective_rab']]);
    }

    public function test_submit_needs_an_uncorrected_cancelled_termin_and_a_valid_type(): void
    {
        $this->project();
        $this->submit()->assertSessionHasErrors('adjustment_type', null, 'adjustment-new'); // belum ada termin batal
        $this->submit(['adjustment_type' => 'RAB'])->assertSessionHasErrors('adjustment_type', null, 'adjustment-new');
        $this->assertSame([0, 0], [DB::table('project_adjustments')->count(), $this->audits('SUBMIT')]);

        // Jenis tidak diisi = TARGET_MARGIN seperti sebelumnya.
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/adjustments", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '10', 'reason' => 'x'])->assertSessionHasNoErrors();
        $this->assertSame('TARGET_MARGIN', $this->row()->adjustment_type);
    }

    public function test_project_without_termins_has_nothing_to_correct(): void
    {
        $this->project(percentages: []);

        $this->assertSame('1110000000.00', $this->project->fresh()->billableContractValue());
        $this->submit()->assertSessionHasErrors('adjustment_type', null, 'adjustment-new');
    }

    public function test_status_gates_active_and_completed_only_and_target_margin_stays_active_only(): void
    {
        $this->project();
        $this->cancel(3);

        foreach (['DRAFT', 'SUBMITTED', 'FINANCIALLY_CLOSED', 'ARCHIVED', 'CANCELLED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            $this->submit()->assertForbidden();
        }
        $this->assertSame(0, DB::table('project_adjustments')->count());

        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED']);
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/adjustments", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '10', 'reason' => 'x'])->assertForbidden(); // TARGET_MARGIN
        $this->submit()->assertSessionHasNoErrors();
        $this->assertSame('CONTRACT_VALUE', $this->row()->adjustment_type);

        // Keputusan juga mengikuti status: setelah ditutup, tidak bisa disetujui.
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'FINANCIALLY_CLOSED']);
        $this->actingAs($this->manager)->post('/adjustments/'.$this->row()->id.'/approve')->assertForbidden();
        $this->assertSame(['SUBMITTED', '1110000000.00'], [$this->row()->status, $this->figures()[0]]);
    }

    public function test_authorization_no_self_approval_and_division_scope(): void
    {
        $this->project();
        $this->cancel(3);
        $this->submit();
        $id = $this->row()->id;
        $before = $this->figures();
        $outsider = User::factory()->role('STAFF_PIC', 'BM')->create();
        $otherDivision = User::factory()->role('MANAGER_GM', 'CP')->create();
        $direksi = User::factory()->role('DIREKSI', null)->create();

        $this->submit(as: $this->manager)->assertForbidden();
        $this->submit(as: $direksi)->assertForbidden();
        $this->submit(as: $outsider)->assertNotFound();
        $this->actingAs($this->staff)->post("/adjustments/$id/approve")->assertForbidden();
        $this->actingAs($direksi)->post("/adjustments/$id/approve")->assertForbidden();
        $this->actingAs($otherDivision)->post("/adjustments/$id/approve")->assertNotFound();

        // Pengaju yang juga Manager tidak boleh menyetujui atau meminta revisi usulannya sendiri.
        DB::table('project_adjustments')->where('id', $id)->update(['submitted_by' => $this->manager->id]);
        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertForbidden();
        $this->actingAs($this->manager)->post("/adjustments/$id/request-revision", ['reason' => 'x'])->assertForbidden();

        $this->assertSame([$before, 'SUBMITTED', 0], [$this->figures(), $this->row()->status, $this->audits('APPROVE')]);
    }

    public function test_approve_is_rejected_with_409_when_another_termin_was_cancelled_after_submission(): void
    {
        $this->project();
        $this->cancel(3);
        $this->submit();
        $id = $this->row()->id;
        $before = $this->figures();

        $this->cancel(2); // usulan memuat nilai kontrak 666.000.000,00, sekarang seharusnya 333.000.000,00
        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertStatus(409);
        $this->assertSame([$before, 'SUBMITTED', 0], [$this->figures(), $this->row()->status, $this->audits('APPROVE')]);

        // Revisi lalu ajukan ulang: nilai dihitung ulang dari termin saat itu.
        $this->actingAs($this->manager)->post("/adjustments/$id/request-revision", ['reason' => 'Termin berubah'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->put("/adjustments/$id", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '15', 'reason' => 'Termin 2 dan 3 batal'])->assertSessionHasNoErrors();
        $row = $this->row($id);
        $this->assertSame(['CONTRACT_VALUE', 'SUBMITTED', '-777000000.00'], [$row->adjustment_type, $row->status, $row->difference_amount]);
        $this->assertSame(['333000000.00', [2, 3]], [$this->data($row)['contract_value'], $this->data($row)['cancelled_sequences']]);

        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertSessionHasNoErrors();
        $this->assertSame(['333000000.00', '333000000.00', '15.0000', '49950000.00', '283050000.00', '1110000000.00', '943500000.00', 2], $this->figures());
    }

    public function test_approve_is_rejected_with_409_when_project_figures_moved_since_submission(): void
    {
        $this->project();
        $this->cancel(3);
        $this->submit();
        $id = $this->row()->id;

        DB::table('projects')->where('id', $this->project->id)->update(['target_margin_amount' => '100000000.00', 'effective_rab' => '1010000000.00']);
        $this->assertTrue(ProjectAdjustmentController::isStale($this->row($id), $this->project->fresh()));
        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertStatus(409);

        DB::table('projects')->where('id', $this->project->id)->update(['target_margin_amount' => '166500000.00', 'effective_rab' => '943500000.00', 'contract_value' => '1000000000.00']); // nilai kontrak bergeser
        $this->assertTrue(ProjectAdjustmentController::isStale($this->row($id), $this->project->fresh()));
        $this->assertSame(0, $this->audits('APPROVE'));
    }

    public function test_project_page_exposes_the_correction_data_and_rab_warning(): void
    {
        $this->project();
        $url = "/projects/{$this->project->id}";
        $none = $this->actingAs($this->staff)->get($url)->assertOk()->viewData('contractCorrection');
        $this->assertFalse($none['possible']);

        $this->cancel(3);
        $this->bq('600000000.00'); // lebih besar dari RAB usulan 566.100.000,00
        $this->submit();

        $page = $this->actingAs($this->staff)->get($url)->assertOk();
        $c = $page->viewData('contractCorrection');
        $this->assertSame([true, '1110000000.00', '1110000000.00', '666000000.00', '666000000.00', '15.0000', '600000000.00', [3]],
            [$c['possible'], $c['initial_contract_value'], $c['contract_value'], $c['new_contract_value'], $c['new_margin_base_amount'], $c['current_margin_pct'], $c['committed_budget'], $c['cancelled_sequences']]);
        $this->assertTrue($page->viewData('can')['correctContract']);
        $a = $page->viewData('adjustments')->sole();
        $this->assertSame(['CONTRACT_VALUE', 'Koreksi Nilai Tertagih', true], [$a->adjustment_type, $a->type_label, $a->rab_below_committed]);

        $this->assertFalse($this->actingAs($this->manager)->get($url)->viewData('can')['correctContract']); // hanya Staff/PIC yang mengajukan
        $this->actingAs($this->manager)->get('/approvals')->assertOk()->assertSee('Koreksi Nilai Tertagih')->assertSee('Selisih nilai tertagih')->assertDontSee('Selisih target margin');
    }

    public function test_page_shows_contract_cards_and_hides_the_correction_when_nothing_is_cancelled(): void
    {
        $this->project();
        $html = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->assertSee('Nilai Project')->assertSee('Nilai SPK; tidak berubah')->getContent();
        $this->assertStringNotContainsString('belum dikoreksi ke nilai tertagih', $html);
        $this->assertStringNotContainsString('value="adjust-contract"', $html);
        $this->assertStringNotContainsString('Ajukan Koreksi Nilai Tertagih', $html);
    }

    public function test_correction_form_is_for_staff_only_while_manager_and_direksi_see_the_banner_read_only(): void
    {
        $this->project();
        $this->cancel(3);
        $url = "/projects/{$this->project->id}";

        $this->actingAs($this->staff)->get($url)->assertOk()
            ->assertSee('1 termin dibatalkan belum dikoreksi ke nilai tertagih')->assertSee('Ajukan Koreksi Nilai Tertagih')
            ->assertSee('name="_modal" value="adjust-contract"', false)->assertSee('name="adjustment_type" value="CONTRACT_VALUE"', false)
            ->assertSee('Rp1.110.000.000,00 → Rp666.000.000,00')->assertSee('#3');

        foreach ([$this->manager, User::factory()->role('DIREKSI', null)->create()] as $reader) {
            $html = $this->actingAs($reader)->get($url)->assertOk()->assertSee('1 termin dibatalkan belum dikoreksi ke nilai tertagih')->getContent();
            $this->assertStringNotContainsString('value="adjust-contract"', $html, $reader->role->value);
            $this->assertStringNotContainsString('Ajukan Koreksi Nilai Tertagih', $html, $reader->role->value);
        }

        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED']); // koreksi juga untuk project selesai
        $this->actingAs($this->staff)->get($url)->assertOk()->assertSee('name="_modal" value="adjust-contract"', false)->assertDontSee('+ Ajukan penyesuaian');
    }

    public function test_adjustment_rows_show_type_labels_corrected_cards_and_the_rab_warning_in_the_approve_modal(): void
    {
        $this->project();
        $this->cancel(3);
        $this->bq('600000000.00'); // RAB usulan 566.100.000,00 di bawah total BQ disetujui
        $this->submit();
        $url = "/projects/{$this->project->id}";

        $warning = 'RAB baru di bawah total BQ yang sudah disetujui (Rp600.000.000,00)';
        $this->actingAs($this->staff)->get($url)->assertOk()->assertSee('Koreksi Nilai Tertagih')->assertSee('Selisih nilai tertagih')
            ->assertSee('Nilai tertagih Rp1.110.000.000,00 → Rp666.000.000,00', false)->assertSee('(termin batal #3)', false)->assertSee($warning, false);
        $this->actingAs($this->manager)->get($url)->assertOk()->assertSee('Setujui Koreksi Nilai Tertagih?')->assertSee('Nilai Project')->assertSee('Peringatan')->assertSee($warning, false);

        $this->actingAs($this->manager)->post('/adjustments/'.$this->row()->id.'/approve')->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->get($url)->assertOk()->assertSee('nilai tertagih Rp666.000.000,00 (termin dibatalkan)')->assertSee('Rp666.000.000,00')
            ->assertDontSee('1 termin dibatalkan belum dikoreksi ke nilai tertagih')->assertDontSee($warning, false); // sudah diputuskan: banner dan peringatan hilang
    }

    public function test_failed_correction_submit_reopens_the_contract_form_and_not_the_margin_form(): void
    {
        $this->project();
        $this->cancel(3);
        $url = "/projects/{$this->project->id}";

        $this->from($url);
        $page = $this->followingRedirects()->submit(['margin_value' => '101'])->assertOk()->assertSee('Target margin persen tidak boleh lebih dari 100.');
        $this->assertSame(['adjust-contract'], $this->openModals($page->getContent()));
    }
}
