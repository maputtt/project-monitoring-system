<?php

namespace Tests\Feature;

use App\Http\Controllers\ProjectClosingController;
use App\Http\Controllers\ProjectViewController;
use App\Models\Project;
use App\Models\User;
use App\Support\InvoiceTax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Revisi C4: Koreksi Persentase Termin (BILLING_PERCENTAGE). Hanya persentase termin PLANNED berubah, total tetap, termin terakhir wajib PLANNED.
// Approve menghitung ulang planned_amount semua termin dari margin base awal, menaikkan version termin PLANNED saja, dan tidak menyentuh project, termin ditagih, maupun snapshot pajak.
// Angka di test dihitung terpisah dengan bcmath (half-up) di helper pct()/alloc(), bukan dengan Money.
class BillingPercentageCorrectionTest extends TestCase
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
        Storage::fake('local');
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        DB::table('tax_rates')->insert([
            ['tax_type' => 'PPN', 'name' => 'PPN 11', 'vendor_qualification' => null, 'rate' => '11.00', 'effective_from' => '2025-01-01', 'created_by' => $this->staff->id],
            ['tax_type' => 'PPH', 'name' => 'PPh B', 'vendor_qualification' => 'B', 'rate' => '2.65', 'effective_from' => '2025-01-01', 'created_by' => $this->staff->id],
        ]);
    }

    /** half-up dua desimal dari string bc skala 10 */
    private function half(string $x): string
    {
        return bcadd(substr(bcadd($x, '0.005', 10), 0, strpos(bcadd($x, '0.005', 10), '.') + 3), '0', 2);
    }

    private function pct(string $total, string $pct): string
    {
        return $this->half(bcdiv(bcmul($total, $pct, 10), '100', 10));
    }

    /** @return list<string> */
    private function alloc(string $total, array $pcts): array
    {
        $parts = [];
        $sum = '0.00';
        foreach ($pcts as $i => $p) {
            $parts[] = $x = $i === array_key_last($pcts) ? bcsub($total, $sum, 2) : $this->pct($total, $p);
            $sum = bcadd($sum, $x, 2);
        }

        return $parts;
    }

    /** INCLUSIVE: kontrak 671.151.608,00 termin 30/30/40; margin base = nilai kontrak. EXCLUSIVE: 1.000.000.000,00 + PPN 11% -> margin base 1.110.000.000,00. */
    private function project(bool $exclusive = false, array $pcts = ['30.0000', '30.0000', '40.0000'], array $statuses = [], array $attrs = []): void
    {
        $attrs += $exclusive
            ? ['contract_value' => '1000000000.00', 'contract_tax_mode' => 'EXCLUSIVE_PPN', 'margin_base_ppn_rate_snapshot' => '11.0000', 'margin_base_amount' => '1110000000.00']
            : ['contract_value' => '671151608.00', 'contract_tax_mode' => 'INCLUSIVE_PPN', 'margin_base_amount' => '671151608.00'];
        $this->project = Project::factory()->assignedTo($this->staff)->create($attrs + [
            'created_by' => $this->staff->id, 'status' => 'ACTIVE', 'target_margin_pct' => '10.0000', 'target_margin_amount' => '1000000.00', 'initial_rab' => '600000000.00', 'effective_rab' => '600000000.00',
        ]);
        $amounts = $this->alloc($this->project->initialMarginBase(), $pcts);
        foreach ($pcts as $i => $pct) {
            $this->termins[$i + 1] = DB::table('invoices')->insertGetId([
                'project_id' => $this->project->id, 'department' => 'BM', 'sequence_number' => $i + 1, 'billing_type' => 'TERMIN', 'billing_percentage' => $pct,
                'planned_amount' => $amounts[$i], 'workflow_status' => 'PLANNED', 'created_by' => $this->staff->id,
            ]);
            $this->state($i + 1, $statuses[$i + 1] ?? 'PLANNED');
        }
    }

    /** Ubah status termin langsung di DB dengan kolom wajib CHECK (nomor invoice, tanggal, snapshot tarif, kunci PAID). */
    private function state(int $sequence, string $status): void
    {
        $extra = in_array($status, ['PLANNED', 'CANCELLED'], true) ? [] : ['invoice_number' => 'INV-'.$this->project->id.'-'.$sequence, 'billing_date' => '2026-07-01', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500']
            + ($status === 'PAID' ? ['locked_at' => now(), 'locked_by' => $this->manager->id] : []);
        DB::table('invoices')->where('id', $this->termins[$sequence])->update(['workflow_status' => $status] + $extra);
    }

    /** @param array<int, string> $bySequence sequence_number => persentase baru */
    private function payload(array $bySequence, array $extra = []): array
    {
        return $extra + ['adjustment_type' => 'BILLING_PERCENTAGE', 'reason' => 'Kesepakatan ulang jadwal tagihan', 'billing_percentages' => collect($bySequence)->mapWithKeys(fn ($p, $seq) => [$this->termins[$seq] => $p])->all()];
    }

    private function submit(array $bySequence = [2 => '33.3333', 3 => '36.6667'], array $extra = [], ?User $as = null)
    {
        // Persentase termin yang tidak disebut ikut dikirim dengan nilai tersimpan: form berisi semua termin PLANNED.
        $current = DB::table('invoices')->where('project_id', $this->project->id)->where('workflow_status', 'PLANNED')->pluck('billing_percentage', 'sequence_number')->all();

        return $this->actingAs($as ?? $this->staff)->post("/projects/{$this->project->id}/adjustments", $this->payload($bySequence + $current, $extra));
    }

    private function row(?int $id = null): object
    {
        return DB::table('project_adjustments')->when($id, fn ($q) => $q->where('id', $id))->orderByDesc('id')->first();
    }

    private function approve(int $id, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->manager)->post("/adjustments/$id/approve");
    }

    /** @return array<int, array{string, string, int, string}> sequence_number => [persen, planned_amount, version, workflow_status] */
    private function invoices(): array
    {
        return DB::table('invoices')->where('project_id', $this->project->id)->orderBy('sequence_number')->get()
            ->mapWithKeys(fn ($i) => [(int) $i->sequence_number => [$i->billing_percentage, $i->planned_amount, (int) $i->version, $i->workflow_status]])->all();
    }

    private function projectRow(): array
    {
        return (array) DB::table('projects')->where('id', $this->project->id)->first();
    }

    private function audits(string $action): int
    {
        return DB::table('audit_logs')->where('entity_type', 'PROJECT_ADJUSTMENT')->where('action', $action)->count();
    }

    public function test_inclusive_example_recomputes_planned_amounts_and_leaves_billed_termin_snapshots_untouched(): void
    {
        $this->project(statuses: [1 => 'PAID']);
        // T1 sudah ditagih dan dibayar dengan DPP dari persentase lama: DPP total = 671.151.608,00 / 1,11, bagian 30%.
        $dppTotal = $this->half(bcdiv('671151608.00', '1.11', 10));
        $dppT1 = $this->pct($dppTotal, '30.0000');
        DB::table('invoices')->where('id', $this->termins[1])->update(['dpp_amount' => $dppT1, 'ppn_amount' => $this->pct($dppT1, '11'), 'subtotal_amount' => bcadd($dppT1, $this->pct($dppT1, '11'), 2)]);
        $snapshot = (array) DB::table('invoices')->where('id', $this->termins[1])->first();
        $project = $this->projectRow();

        $this->submit()->assertSessionHasNoErrors()->assertRedirect("/projects/{$this->project->id}");

        $a = $this->row();
        $this->assertSame(['BILLING_PERCENTAGE', 'SUBMITTED', $this->staff->id, null], [$a->adjustment_type, $a->status, $a->submitted_by, $a->difference_amount]);
        $this->assertSame([0, 1, 0], [DB::table('documents')->count(), $this->audits('SUBMIT'), $this->audits('APPROVE')]); // tanpa file
        $old = json_decode($a->old_data, true)['termins'];
        $new = json_decode($a->new_data, true)['termins'];
        $this->assertSame([[2, '30.0000', '201345482.40'], [3, '40.0000', '268460643.20']], array_map(fn ($t) => [$t['sequence_number'], $t['billing_percentage'], $t['planned_amount']], $old));
        $this->assertSame([[2, '33.3333', '223716978.95'], [3, '36.6667', '246089146.65']], array_map(fn ($t) => [$t['sequence_number'], $t['billing_percentage'], $t['planned_amount']], $new)); // pratinjau
        $this->assertSame([$this->termins[2], $this->termins[3]], array_column($new, 'invoice_id'));
        $this->assertSame([30, 30, 40], array_map('intval', array_column($this->invoices(), 0))); // mengajukan tidak mengubah termin

        $this->approve($a->id)->assertSessionHasNoErrors()->assertRedirect("/projects/{$this->project->id}");

        $inv = $this->invoices();
        $this->assertSame(['30.0000', '201345482.40', 1, 'PAID'], $inv[1]); // T1 tidak berubah, version tidak naik
        $this->assertSame(['33.3333', '223716978.95', 2, 'PLANNED'], $inv[2]);
        $this->assertSame(['36.6667', '246089146.65', 2, 'PLANNED'], $inv[3]);
        $this->assertSame('671151608.00', bcadd(bcadd($inv[1][1], $inv[2][1], 2), $inv[3][1], 2)); // jumlah rencana tetap sama dengan margin base
        $this->assertSame($snapshot, (array) DB::table('invoices')->where('id', $this->termins[1])->first());
        $this->assertSame($project, $this->projectRow()); // project tidak berubah, termasuk version

        // DPP T1 dengan daftar persentase baru tetap sama dengan yang tersimpan.
        $calc = InvoiceTax::calculate('671151608.00', 'INCLUSIVE_PPN', ['30.0000', '33.3333', '36.6667'], 0, '11.0000', '2.6500');
        $this->assertSame($dppT1, $calc['dpp_amount']);

        $done = $this->row($a->id);
        $this->assertSame(['APPROVED', $this->manager->id], [$done->status, $done->approved_by]);
        $this->assertSame([[2, '33.3333', '223716978.95'], [3, '36.6667', '246089146.65']], array_map(fn ($t) => [$t['sequence_number'], $t['billing_percentage'], $t['planned_amount']], json_decode($done->new_data, true)['termins']));
        $log = DB::table('audit_logs')->where('action', 'APPROVE')->where('entity_type', 'PROJECT_ADJUSTMENT')->sole();
        $this->assertSame([$a->id, '201345482.40', '223716978.95'], [(int) $log->entity_id, json_decode($log->data_before, true)['termins'][0]['planned_amount'], json_decode($log->data_after, true)['termins'][0]['planned_amount']]);
    }

    public function test_exclusive_example_uses_the_margin_base_with_ppn_for_planned_amounts(): void
    {
        $this->project(exclusive: true, statuses: [1 => 'PAID']);
        $this->assertSame(['333000000.00', '333000000.00', '444000000.00'], array_column($this->invoices(), 1));

        $this->submit()->assertSessionHasNoErrors();
        $this->approve($this->row()->id)->assertSessionHasNoErrors();

        $inv = $this->invoices();
        $this->assertSame(['333000000.00', '369999630.00', '407000370.00'], array_column($inv, 1));
        $this->assertSame(['1110000000.00'], [bcadd(bcadd($inv[1][1], $inv[2][1], 2), $inv[3][1], 2)]);
        $this->assertSame([1, 2, 2], array_column($inv, 2));
    }

    public function test_after_approval_the_next_invoice_dpp_follows_the_new_percentage_and_the_old_form_version_is_stale(): void
    {
        $this->project(statuses: [1 => 'PAID']);
        $this->submit();
        $this->approve($this->row()->id)->assertSessionHasNoErrors();

        $payload = ['invoice_number' => 'INV/2', 'billing_date' => '2026-08-20', 'due_date' => '2026-12-31', 'pph_qualification' => 'B', 'file' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')];
        $this->actingAs($this->staff)->post("/invoices/{$this->termins[2]}/submit", $payload + ['version' => 1])->assertSessionHasErrors('version', null, "invoice-{$this->termins[2]}"); // form lama (version 1)
        $this->assertSame('PLANNED', $this->invoices()[2][3]);

        $this->actingAs($this->staff)->post("/invoices/{$this->termins[2]}/submit", $payload + ['version' => 2])->assertSessionHasNoErrors();
        $dppTotal = $this->half(bcdiv('671151608.00', '1.11', 10));
        $expected = $this->alloc($dppTotal, ['30.0000', '33.3333', '36.6667'])[1];
        $this->assertSame($expected, DB::table('invoices')->where('id', $this->termins[2])->value('dpp_amount'));
    }

    public function test_validation_rules_reject_with_422_and_leave_nothing_behind(): void
    {
        $this->project(statuses: [1 => 'PAID']);
        $bag = 'adjustment-new';

        // Total 69,9999 bukan 70.
        $this->submit([2 => '33.3333', 3 => '36.6666'])->assertSessionHasErrors(['billing_percentages' => 'Total persentase termin yang dikoreksi harus tetap 70% (sekarang 69,9999%).'], null, $bag);
        // Tidak ada yang berubah.
        $this->submit([2 => '30.0000', 3 => '40.0000'])->assertSessionHasErrors(['billing_percentages' => 'Tidak ada persentase termin yang berubah.'], null, $bag);
        // Termin ditagih (T1) ikut dikirim: himpunan tidak sama dengan termin PLANNED.
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/adjustments", $this->payload([1 => '10.0000', 2 => '33.3333', 3 => '36.6667']))
            ->assertSessionHasErrors(['billing_percentages' => 'Daftar termin berubah. Muat ulang halaman.'], null, $bag);
        // Termin PLANNED tidak lengkap.
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/adjustments", $this->payload([2 => '70.0000']))
            ->assertSessionHasErrors(['billing_percentages' => 'Daftar termin berubah. Muat ulang halaman.'], null, $bag);
        // Nol, nol berdesimal, lima desimal, lebih dari 100, bukan angka, kosong.
        foreach (['0', '0.0000', '33.33333', '101', 'abc', ''] as $bad) {
            $this->submit([2 => $bad, 3 => '70.0000'])->assertSessionHasErrors('billing_percentages.'.$this->termins[2], null, $bag);
        }
        $this->submit([2 => '0', 3 => '70.0000'])->assertSessionHasErrors(['billing_percentages.'.$this->termins[2] => 'Persentase termin harus lebih dari 0.'], null, $bag);
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/adjustments", ['adjustment_type' => 'BILLING_PERCENTAGE', 'reason' => 'x'])->assertSessionHasErrors('billing_percentages', null, $bag);
        $this->submit(extra: ['reason' => ''])->assertSessionHasErrors('reason', null, $bag);

        $this->assertSame([0, 0], [DB::table('project_adjustments')->count(), $this->audits('SUBMIT')]);
    }

    public function test_correction_needs_two_planned_termins_and_a_planned_last_termin(): void
    {
        // Termin terakhir CANCELLED: persentase 33,3333/33,3333/33,3334 pada margin base 1.000,01.
        $this->project(pcts: ['33.3333', '33.3333', '33.3334'], statuses: [3 => 'CANCELLED'], attrs: ['contract_value' => '1000.01', 'margin_base_amount' => '1000.01']);
        $this->submit([1 => '30.0000', 2 => '36.6666'])->assertSessionHasErrors(['billing_percentages' => 'Termin terakhir sudah ditagih atau dibatalkan, persentase tidak bisa dikoreksi.'], null, 'adjustment-new');
        $this->assertSame(0, DB::table('project_adjustments')->count());
        $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->assertViewHas('billingCorrection', fn ($c) => $c['possible'] === false);

        // Hanya satu termin PLANNED.
        $this->state(1, 'PAID');
        $this->state(2, 'PAID');
        $this->state(3, 'PLANNED');
        $this->submit([3 => '33.3334'])->assertSessionHasErrors(['billing_percentages' => 'Koreksi persentase memerlukan minimal dua termin yang belum ditagih.'], null, 'adjustment-new');
        $this->assertSame(0, DB::table('project_adjustments')->count());
    }

    public function test_billed_cancelled_first_termins_are_allowed_when_the_last_is_planned(): void
    {
        $this->project(statuses: [1 => 'CANCELLED']);
        $this->submit([2 => '35.0000', 3 => '35.0000'])->assertSessionHasNoErrors();
        $this->approve($this->row()->id)->assertSessionHasNoErrors();

        $this->assertSame([['30.0000', '201345482.40', 1, 'CANCELLED'], ['35.0000', '234903062.80', 2, 'PLANNED'], ['35.0000', '234903062.80', 2, 'PLANNED']], array_values($this->invoices()));
    }

    public function test_approve_is_409_when_the_planned_termins_changed_after_submission(): void
    {
        $this->project();
        $this->submit();
        $id = $this->row()->id;
        $before = $this->invoices();

        foreach (['PAID', 'CANCELLED', 'INVOICE_SUBMITTED'] as $to) {
            $this->state(2, $to);
            $this->approve($id)->assertStatus(409);
        }
        $this->state(2, 'PLANNED');
        DB::table('invoices')->where('id', $this->termins[3])->update(['billing_percentage' => '39.0000']); // persentase bergeser
        $this->approve($id)->assertStatus(409);
        DB::table('invoices')->where('id', $this->termins[3])->update(['billing_percentage' => '40.0000']);

        $this->assertSame([$before, 'SUBMITTED', 0], [$this->invoices(), $this->row()->status, $this->audits('APPROVE')]);

        // Setelah termin kembali sama, persetujuan berhasil, dan persetujuan ulang ditolak (idempoten).
        $this->approve($id)->assertSessionHasNoErrors();
        $this->approve($id)->assertStatus(409);
        $this->assertSame([2, 1], [$this->invoices()[2][2], $this->audits('APPROVE')]);
    }

    public function test_only_one_open_correction_per_project_and_revision_resubmit_reads_the_current_termins(): void
    {
        $this->project();
        $this->submit()->assertSessionHasNoErrors();
        $id = $this->row()->id;

        $this->submit([2 => '31.0000', 3 => '39.0000'])->assertSessionHasErrors(['billing_percentages' => 'Masih ada Koreksi Persentase Termin yang belum selesai.'], null, 'adjustment-new');
        $this->assertSame(1, DB::table('project_adjustments')->count());

        $this->actingAs($this->manager)->post("/adjustments/$id/request-revision", ['reason' => 'Ubah angka'])->assertSessionHasNoErrors();
        $this->submit([2 => '31.0000', 3 => '39.0000'])->assertSessionHasErrors('billing_percentages', null, 'adjustment-new'); // REVISION_REQUIRED masih terbuka

        $this->assertSame(1, DB::table('project_adjustments')->count());
    }

    public function test_resubmit_after_revision_replaces_the_proposal_and_can_be_approved(): void
    {
        $this->project(statuses: [1 => 'PAID']);
        $this->submit();
        $id = $this->row()->id;
        $this->actingAs($this->manager)->post("/adjustments/$id/request-revision", ['reason' => 'Ubah angka'])->assertSessionHasNoErrors();
        $this->approve($id)->assertStatus(409); // bukan SUBMITTED

        $this->actingAs($this->staff)->put("/adjustments/$id", $this->payload([2 => '32.0000', 3 => '38.0000']))->assertSessionHasNoErrors();
        $this->assertSame(['SUBMITTED', null], [$this->row($id)->status, $this->row($id)->difference_amount]);
        $this->actingAs($this->manager)->put("/adjustments/$id", $this->payload([2 => '32.0000', 3 => '38.0000']))->assertForbidden(); // bukan pengaju (juga bukan REVISION_REQUIRED)

        $this->approve($id)->assertSessionHasNoErrors();
        $this->assertSame([['32.0000', $this->pct('671151608.00', '32.0000')], ['38.0000', bcsub('671151608.00', bcadd($this->pct('671151608.00', '30.0000'), $this->pct('671151608.00', '32.0000'), 2), 2)]], array_map(fn ($i) => [$i[0], $i[1]], array_slice(array_values($this->invoices()), 1)));
    }

    public function test_authorization_status_gates_and_no_self_approval(): void
    {
        $this->project();
        $this->submit();
        $id = $this->row()->id;
        $before = [$this->invoices(), $this->projectRow()];
        $outsider = User::factory()->role('STAFF_PIC', 'BM')->create();
        $otherManager = User::factory()->role('MANAGER_GM', 'CP')->create();
        $direksi = User::factory()->role('DIREKSI', null)->create();

        $this->submit(as: $this->manager)->assertForbidden();
        $this->submit(as: $direksi)->assertForbidden();
        $this->submit(as: $outsider)->assertNotFound();
        $this->approve($id, $this->staff)->assertForbidden();
        $this->approve($id, $direksi)->assertForbidden();
        $this->approve($id, $otherManager)->assertNotFound();
        DB::table('project_adjustments')->where('id', $id)->update(['submitted_by' => $this->manager->id]);
        $this->approve($id)->assertForbidden(); // pengaju tidak boleh menyetujui sendiri
        $this->actingAs($this->manager)->post("/adjustments/$id/request-revision", ['reason' => 'x'])->assertForbidden();
        DB::table('project_adjustments')->where('id', $id)->update(['submitted_by' => $this->staff->id]);
        $this->assertSame([$before, 'SUBMITTED', 0], [[$this->invoices(), $this->projectRow()], $this->row()->status, $this->audits('APPROVE')]);
    }

    public function test_project_status_gate_active_and_completed_only(): void
    {
        $this->project();
        foreach (['DRAFT', 'SUBMITTED', 'FINANCIALLY_CLOSED', 'ARCHIVED', 'CANCELLED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            $this->submit()->assertForbidden();
        }
        $this->assertSame(0, DB::table('project_adjustments')->count());

        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED']);
        $this->submit()->assertSessionHasNoErrors();
        $id = $this->row()->id;
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'FINANCIALLY_CLOSED']);
        $this->approve($id)->assertForbidden();
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED']);
        $this->approve($id)->assertSessionHasNoErrors();
        $this->assertSame(['33.3333', 2], [$this->invoices()[2][0], $this->invoices()[2][2]]);
    }

    public function test_pending_correction_blocks_complete_and_closing_readiness(): void
    {
        $this->project();
        $this->submit();

        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/complete")->assertStatus(409);
        $this->assertSame('ACTIVE', $this->projectRow()['status']);

        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED']); // dipaksa lewat DB
        $readiness = ProjectClosingController::readiness($this->project->fresh());
        $this->assertFalse($readiness['ready']);
        $this->assertContains('1 penyesuaian atau koreksi belum selesai', collect($readiness['checks'])->pluck('blockers')->flatten()->all());
    }

    public function test_project_page_flags_and_inbox_row_for_the_correction(): void
    {
        $this->project();
        $url = "/projects/{$this->project->id}";

        $page = $this->actingAs($this->staff)->get($url)->assertOk();
        $c = $page->viewData('billingCorrection');
        $this->assertSame([true, false, '100.0000', [$this->termins[1], $this->termins[2], $this->termins[3]]], [$c['possible'], $c['has_open'], $c['total_percentage'], array_column($c['termins'], 'invoice_id')]);
        $this->assertSame(['30.0000', '201345482.40'], [$c['termins'][0]['billing_percentage'], $c['termins'][0]['planned_amount']]);
        $this->assertTrue($page->viewData('can')['correctBilling']);
        $this->assertFalse($this->actingAs($this->manager)->get($url)->viewData('can')['correctBilling']); // hanya Staff/PIC yang mengajukan

        $this->submit();
        $open = $this->billingCorrectionNow();
        $this->assertSame([false, true], [$open['possible'], $open['has_open']]);

        $this->actingAs($this->manager)->get('/approvals')->assertOk()->assertSee('Koreksi Persentase Termin')->assertSee('Termin 2, 3')->assertSee('—'); // termin 1 tidak berubah, jadi tidak disebut
        $this->assertSame(['Koreksi Persentase Termin', 'Termin 2, 3', null], $this->inboxItem());
    }

    /** Halaman project belum bisa dirender dengan baris Koreksi Persentase Termin (tugas frontend), jadi data view dibaca langsung dari controller. */
    private function billingCorrectionNow(): array
    {
        $method = new \ReflectionMethod(ProjectViewController::class, 'billingCorrection');

        return $method->invoke(app(ProjectViewController::class), $this->project->fresh());
    }

    private function inboxItem(): array
    {
        $item = $this->actingAs($this->manager)->get('/approvals')->viewData('items')->getCollection()->firstWhere('type', 'adjustment');

        return [$item['type_label'], $item['summary'], $item['amount']];
    }
}
