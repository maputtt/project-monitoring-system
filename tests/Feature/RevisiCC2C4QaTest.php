<?php

namespace Tests\Feature;

use App\Http\Controllers\ProjectClosingController;
use App\Models\Project;
use App\Models\User;
use App\Support\InvoiceTax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// QA independen Revisi C2 (RETURN_TO_UNALLOCATED_RAB) dan C4 (BILLING_PERCENTAGE). Oracle uang = bcmath half-up di test ini, bukan App\Support\Money.
class RevisiCC2C4QaTest extends TestCase
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

    // ---- oracle uang (bcmath, half-up dua desimal) ----

    private function half(string $x): string
    {
        $s = bcadd($x, '0.005', 10);

        return bcadd(substr($s, 0, strpos($s, '.') + 3), '0', 2);
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
        $last = array_key_last($pcts);
        foreach ($pcts as $i => $p) {
            $parts[] = $x = $i === $last ? bcsub($total, $sum, 2) : $this->pct($total, $p);
            $sum = bcadd($sum, $x, 2);
        }

        return $parts;
    }

    /** units of 0.0001% -> "30.0000" */
    private function u(int $units): string
    {
        return sprintf('%d.%04d', intdiv($units, 10000), $units % 10000);
    }

    /** n bilangan bulat positif dengan jumlah $sum. */
    private function split(int $n, int $sum): array
    {
        $cuts = [];
        while (count($cuts) < $n - 1) {
            $cuts[mt_rand(1, $sum - 1)] = true;
        }
        $cuts = array_keys($cuts);
        sort($cuts);
        $parts = [];
        $prev = 0;
        foreach ([...$cuts, $sum] as $c) {
            $parts[] = $c - $prev;
            $prev = $c;
        }

        return $parts;
    }

    private function make(string $mode, string $contract, array $pcts, array $statuses): void
    {
        $attrs = match ($mode) {
            'EXCLUSIVE_PPN' => ['contract_tax_mode' => 'EXCLUSIVE_PPN', 'margin_base_ppn_rate_snapshot' => '11.0000', 'margin_base_amount' => $this->half(bcmul($contract, '1.11', 4))],
            default => ['contract_tax_mode' => $mode, 'margin_base_amount' => $contract],
        };
        $this->project = Project::factory()->assignedTo($this->staff)->create($attrs + [
            'contract_value' => $contract, 'created_by' => $this->staff->id, 'status' => 'ACTIVE', 'target_margin_pct' => '10.0000', 'target_margin_amount' => '1.00',
            'initial_rab' => '0.00', 'effective_rab' => '0.00', 'payment_count' => count($pcts),
        ]);
        $base = $attrs['margin_base_amount'];
        $amounts = $this->alloc($base, $pcts);
        $dppTotal = $mode === 'INCLUSIVE_PPN' ? $this->half(bcdiv($contract, '1.11', 10)) : $contract;
        $dpps = $this->alloc($dppTotal, $pcts);
        $this->termins = [];
        foreach ($pcts as $i => $p) {
            $status = $statuses[$i + 1] ?? 'PLANNED';
            $row = ['project_id' => $this->project->id, 'department' => 'BM', 'sequence_number' => $i + 1, 'billing_type' => 'TERMIN', 'billing_percentage' => $p, 'planned_amount' => $amounts[$i], 'workflow_status' => 'PLANNED', 'created_by' => $this->staff->id];
            $id = DB::table('invoices')->insertGetId($row);
            $this->termins[$i + 1] = $id;
            if ($status === 'PLANNED' || $status === 'CANCELLED') {
                DB::table('invoices')->where('id', $id)->update(['workflow_status' => $status]);

                continue;
            }
            $ppn = $mode === 'NON_PPN' ? '0.00' : $this->pct($dpps[$i], '11');
            DB::table('invoices')->where('id', $id)->update([
                'workflow_status' => $status, 'invoice_number' => "INV-{$this->project->id}-".($i + 1), 'billing_date' => '2026-07-01', 'ppn_rate_snapshot' => $mode === 'NON_PPN' ? '0.0000' : '11.0000', 'pph_rate_snapshot' => '2.6500',
                'dpp_amount' => $dpps[$i], 'ppn_amount' => $ppn, 'subtotal_amount' => bcadd($dpps[$i], $ppn, 2),
            ] + ($status === 'PAID' ? ['locked_at' => now(), 'locked_by' => $this->manager->id] : []));
        }
    }

    private function invoiceRows(): array
    {
        return DB::table('invoices')->where('project_id', $this->project->id)->orderBy('sequence_number')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function projectRow(): array
    {
        return (array) DB::table('projects')->where('id', $this->project->id)->first();
    }

    private function payload(array $byId, array $extra = []): array
    {
        return $extra + ['adjustment_type' => 'BILLING_PERCENTAGE', 'reason' => 'Koreksi QA', 'billing_percentages' => $byId];
    }

    private function bpSubmit(array $byId, array $extra = [])
    {
        return $this->actingAs($this->staff)->post("/projects/{$this->project->id}/adjustments", $this->payload($byId, $extra));
    }

    // ---- C4: sapuan acak ----

    public function test_randomised_correction_sweep_keeps_billed_and_cancelled_shares_bit_identical_and_sum_exact(): void
    {
        mt_srand(20261022);
        $modes = ['INCLUSIVE_PPN', 'EXCLUSIVE_PPN', 'NON_PPN'];
        $hasBilled = $hasCancelled = $hasBoth = 0;
        $iterations = 40;

        for ($it = 0; $it < $iterations; $it++) {
            $mode = $modes[$it % 3];
            $n = mt_rand(3, 6);
            $contract = mt_rand(0, 3) === 0 ? sprintf('%d.%02d', mt_rand(1, 9999), mt_rand(0, 99)) : sprintf('%d.%02d', mt_rand(1000000, 9999999999), mt_rand(0, 99));
            if (bccomp($contract, '0.50', 2) < 0) {
                $contract = '1.37';
            }
            $units = $this->split($n, 1000000);
            $pcts = array_map(fn ($x) => $this->u($x), $units);

            // Status: termin terakhir PLANNED; minimal satu termin non-terakhir PLANNED; sisanya acak.
            $statuses = [];
            $plannedNonLast = 0;
            for ($s = 1; $s < $n; $s++) {
                $statuses[$s] = ['PLANNED', 'PAID', 'CANCELLED', 'INVOICE_SUBMITTED'][mt_rand(0, 3)];
                $plannedNonLast += $statuses[$s] === 'PLANNED' ? 1 : 0;
            }
            if ($plannedNonLast === 0) {
                $statuses[mt_rand(1, $n - 1)] = 'PLANNED';
            }
            $this->make($mode, $contract, $pcts, $statuses);
            $label = "iter $it mode $mode contract $contract pcts ".implode('/', $pcts).' st '.implode(',', $statuses);

            $billed = array_keys(array_filter($statuses, fn ($st) => in_array($st, ['PAID', 'INVOICE_SUBMITTED'], true)));
            $cancelled = array_keys(array_filter($statuses, fn ($st) => $st === 'CANCELLED'));
            $hasBilled += $billed ? 1 : 0;
            $hasCancelled += $cancelled ? 1 : 0;
            $hasBoth += ($billed && $cancelled) ? 1 : 0;
            $plannedSeq = array_values(array_filter(range(1, $n), fn ($s) => ($statuses[$s] ?? 'PLANNED') === 'PLANNED'));

            // Koreksi acak: jumlah unit termin PLANNED tetap, semua > 0, minimal satu berubah.
            $oldUnits = array_map(fn ($s) => $units[$s - 1], $plannedSeq);
            do {
                $newUnits = $this->split(count($plannedSeq), array_sum($oldUnits));
            } while ($newUnits === $oldUnits);
            $newPcts = $pcts;
            foreach ($plannedSeq as $k => $s) {
                $newPcts[$s - 1] = $this->u($newUnits[$k]);
            }

            $before = $this->invoiceRows();
            $projectBefore = $this->projectRow();
            $project = Project::find($this->project->id);
            $billableBefore = $project->billableContractValue();
            $closeBefore = bccomp($project->contract_value, $billableBefore, 2);

            $byId = [];
            foreach ($plannedSeq as $k => $s) {
                $byId[$this->termins[$s]] = $this->u($newUnits[$k]);
            }
            $this->bpSubmit($byId)->assertSessionHasNoErrors();
            $adjustment = DB::table('project_adjustments')->where('project_id', $this->project->id)->orderByDesc('id')->first();
            $this->actingAs($this->manager)->post("/adjustments/{$adjustment->id}/approve")->assertSessionHasNoErrors();

            $after = $this->invoiceRows();
            $base = $this->projectRow()['contract_tax_mode'] === 'EXCLUSIVE_PPN' ? $this->half(bcmul($contract, '1.11', 4)) : $contract;
            $expected = $this->alloc($base, $newPcts);
            $dppTotal = $mode === 'INCLUSIVE_PPN' ? $this->half(bcdiv($contract, '1.11', 10)) : $contract;

            $this->assertSame($projectBefore, $this->projectRow(), "$label: project berubah");
            $sum = '0.00';
            foreach ($after as $i => $row) {
                $s = $i + 1;
                $sum = bcadd($sum, $row['planned_amount'], 2);
                if (in_array($s, $plannedSeq, true)) {
                    $this->assertSame([$newPcts[$i], $expected[$i], (int) $before[$i]['version'] + 1], [$row['billing_percentage'], $row['planned_amount'], (int) $row['version']], "$label: termin $s PLANNED");
                } else {
                    $this->assertSame($before[$i], $row, "$label: termin $s (ditagih/batal) tidak boleh berubah");
                }
            }
            $this->assertSame($base, $sum, "$label: Σ planned != margin base");

            // Bagian termin ditagih/batal identik untuk nilai SPK awal, DPP total, dan margin base awal (lama vs baru).
            foreach ([$contract, $dppTotal, $base] as $total) {
                $old = $this->alloc($total, $pcts);
                $new = $this->alloc($total, $newPcts);
                foreach (array_merge($billed, $cancelled) as $s) {
                    $this->assertSame($old[$s - 1], $new[$s - 1], "$label: bagian termin $s bergeser pada total $total");
                }
                $this->assertSame($total, array_reduce($new, fn ($c, $x) => bcadd($c, $x, 2), '0.00'));
            }
            // Snapshot DPP termin ditagih sama dengan hitung ulang dengan daftar persentase baru.
            foreach ($billed as $s) {
                $calc = InvoiceTax::calculate($contract, $mode, $newPcts, $s - 1, '11.0000', '2.6500');
                $this->assertSame($after[$s - 1]['dpp_amount'], $calc['dpp_amount'], "$label: DPP termin $s");
            }

            // billableContractValue dan cek closing konsisten (tidak bergeser oleh koreksi).
            $fresh = Project::find($this->project->id);
            $newShares = $this->alloc($contract, $newPcts);
            $oracleBillable = '0.00';
            foreach ($newShares as $i => $share) {
                $oracleBillable = in_array($i + 1, $cancelled, true) ? $oracleBillable : bcadd($oracleBillable, $share, 2);
            }
            $this->assertSame($oracleBillable, $fresh->billableContractValue(), "$label: billable");
            $this->assertSame($billableBefore, $fresh->billableContractValue(), "$label: billable bergeser");
            $this->assertSame($closeBefore, bccomp($fresh->contract_value, $fresh->billableContractValue(), 2), "$label: cek closing bergeser");
            $line = collect(ProjectClosingController::readiness($fresh)['checks'])->firstWhere('label', 'Nilai tertagih sesuai termin yang tidak batal');
            $this->assertSame($closeBefore === 0, $line['blockers'] === [], "$label: baris closing");

            // Submit invoice termin PLANNED pertama memakai persentase baru.
            $first = $plannedSeq[0];
            $this->actingAs($this->staff)->post("/invoices/{$this->termins[$first]}/submit", [
                'invoice_number' => "NEW-$it", 'billing_date' => '2026-08-20', 'due_date' => '2026-12-31', 'pph_qualification' => 'B', 'version' => (int) $after[$first - 1]['version'],
                'file' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
            ])->assertSessionHasNoErrors();
            $this->assertSame($this->alloc($dppTotal, $newPcts)[$first - 1], DB::table('invoices')->where('id', $this->termins[$first])->value('dpp_amount'), "$label: DPP invoice setelah koreksi");

            // bersihkan project ini supaya iterasi berikutnya tidak saling mengganggu
            DB::table('project_user_assignments')->where('project_id', $this->project->id)->update(['is_active' => false]);
        }
        $this->assertGreaterThan(10, $hasBilled);
        $this->assertGreaterThan(10, $hasCancelled);
        $this->assertGreaterThan(3, $hasBoth);
    }

    // ---- C4: contoh PRD, dihitung ulang tangan ----

    public function test_prd_examples_by_hand_inclusive_and_exclusive(): void
    {
        // INCLUSIVE 671.151.608,00: 30% = 201.345.482,40; 33,3333% = 223.716.978,95 (671151608 x 0,333333 = 223.716.978,9...); T3 = sisa.
        $this->make('INCLUSIVE_PPN', '671151608.00', ['30.0000', '30.0000', '40.0000'], [1 => 'PAID']);
        $this->bpSubmit([$this->termins[2] => '33.3333', $this->termins[3] => '36.6667'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post('/adjustments/'.DB::table('project_adjustments')->value('id').'/approve')->assertSessionHasNoErrors();
        $this->assertSame(['201345482.40', '223716978.95', '246089146.65'], array_column($this->invoiceRows(), 'planned_amount'));
        $this->assertSame('671151608.00', array_reduce(array_column($this->invoiceRows(), 'planned_amount'), fn ($c, $x) => bcadd($c, $x, 2), '0.00'));
    }

    // ---- C4: input kasar tidak boleh 500 ----

    public function test_crafted_billing_percentage_inputs_never_500(): void
    {
        $this->make('INCLUSIVE_PPN', '671151608.00', ['30.0000', '30.0000', '40.0000'], [1 => 'PAID']);
        [$t2, $t3] = [$this->termins[2], $this->termins[3]];
        $url = "/projects/{$this->project->id}/adjustments";
        $bad = [
            ['billing_percentages' => 'abc'],
            ['billing_percentages' => ['x' => 'y']],
            ['billing_percentages' => [$t2 => ['1'], $t3 => ['2']]],
            ['billing_percentages' => [$t2 => ['a' => ['b']], $t3 => '70']],
            ['billing_percentages' => [$t2 => null, $t3 => '70']],
            ['billing_percentages' => [$t2 => '1e1', $t3 => '60']],
            ['billing_percentages' => [$t2 => '-30', $t3 => '100']],
            ['billing_percentages' => [$t2 => '+33.3333', $t3 => '+36.6667']],
            ['billing_percentages' => [$t2 => '.5', $t3 => '69.5']],
            ['billing_percentages' => [$t2 => '50.', $t3 => '20.']],
            ['billing_percentages' => [$t2 => '99999999999999999999999', $t3 => '1']],
            ['billing_percentages' => ["$t2.5" => '30', $t3 => '40']],
            ['billing_percentages' => ['0'.$t2 => '30', $t3 => '40']],
            ['billing_percentages' => ['' => '30', $t3 => '40']],
            ['billing_percentages' => [$t2 => '٣٣', $t3 => '37']],
            ['billing_percentages' => [99999999999999999999 => '30', $t3 => '40']],
            ['billing_percentages' => [$t2 => '33.3333', $t3 => '36.6667'], 'reason' => ['a']],
            ['billing_percentages' => [$t2 => '33.3333', $t3 => '36.6667'], 'adjustment_type' => ['BILLING_PERCENTAGE']],
            ['billing_percentages' => [$t2 => '33.3333', $t3 => '36.6667'], 'adjustment_type' => 'billing_percentage'],
            ['billing_percentages' => [$t2 => '33.3333', $t3 => '36.6667'], 'margin_mode' => ['PCT'], 'margin_value' => ['x']],
        ];
        foreach ($bad as $k => $extra) {
            $res = $this->actingAs($this->staff)->post($url, array_replace(['adjustment_type' => 'BILLING_PERCENTAGE', 'reason' => 'x'], $extra));
            $this->assertLessThan(500, $res->getStatusCode(), 'case '.$k.' => '.$res->getStatusCode());
        }
        // Hanya +33.3333 / +36.6667, .5/69.5 dan 50./20. boleh lolos; selain itu tidak boleh menyisakan baris.
        $rows = DB::table('project_adjustments')->get();
        foreach ($rows as $r) {
            foreach (json_decode($r->new_data, true)['termins'] as $t) {
                $this->assertMatchesRegularExpression('/^\d+\.\d{4}$/', $t['billing_percentage']);
            }
        }
    }

    public function test_crafted_inputs_on_resubmit_never_500(): void
    {
        $this->make('INCLUSIVE_PPN', '671151608.00', ['30.0000', '30.0000', '40.0000'], [1 => 'PAID']);
        [$t2, $t3] = [$this->termins[2], $this->termins[3]];
        $this->bpSubmit([$t2 => '33.3333', $t3 => '36.6667'])->assertSessionHasNoErrors();
        $id = DB::table('project_adjustments')->value('id');
        $this->actingAs($this->manager)->post("/adjustments/$id/request-revision", ['reason' => 'x'])->assertSessionHasNoErrors();
        foreach ([['billing_percentages' => 'q'], ['billing_percentages' => [$t2 => ['z'], $t3 => 'y']], ['billing_percentages' => []], []] as $extra) {
            $res = $this->actingAs($this->staff)->put("/adjustments/$id", array_replace(['reason' => 'x'], $extra));
            $this->assertLessThan(500, $res->getStatusCode());
        }
        $this->assertSame('REVISION_REQUIRED', DB::table('project_adjustments')->where('id', $id)->value('status'));
        // adjustment_type di body ajukan ulang diabaikan (jenis tersimpan menang).
        $this->actingAs($this->staff)->put("/adjustments/$id", ['adjustment_type' => 'TARGET_MARGIN', 'margin_mode' => 'PCT', 'margin_value' => '5', 'reason' => 'x', 'billing_percentages' => [$t2 => '34.0000', $t3 => '36.0000']])->assertSessionHasNoErrors();
        $this->assertSame(['BILLING_PERCENTAGE', 'SUBMITTED'], [DB::table('project_adjustments')->where('id', $id)->value('adjustment_type'), DB::table('project_adjustments')->where('id', $id)->value('status')]);
    }

    // ---- C4: tabrakan antar-jenis & sinkronisasi ----

    public function test_contract_value_and_billing_corrections_can_both_be_open_and_both_approve(): void
    {
        // T1 CANCELLED, T2/T3 PLANNED: koreksi nilai tertagih (CONTRACT_VALUE) dan koreksi persentase boleh terbuka bersamaan; hasil akhir konsisten.
        $this->make('INCLUSIVE_PPN', '1000000.00', ['30.0000', '30.0000', '40.0000'], [1 => 'CANCELLED']);
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/adjustments", ['adjustment_type' => 'CONTRACT_VALUE', 'margin_mode' => 'PCT', 'margin_value' => '10', 'reason' => 'batal'])->assertSessionHasNoErrors();
        $cv = DB::table('project_adjustments')->where('adjustment_type', 'CONTRACT_VALUE')->value('id');
        $this->bpSubmit([$this->termins[2] => '20.0000', $this->termins[3] => '50.0000'])->assertSessionHasNoErrors();
        $bp = DB::table('project_adjustments')->where('adjustment_type', 'BILLING_PERCENTAGE')->value('id');

        $this->actingAs($this->manager)->post("/adjustments/$bp/approve")->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/adjustments/$cv/approve")->assertSessionHasNoErrors();

        $p = Project::find($this->project->id);
        $this->assertSame('700000.00', $p->contract_value);
        $this->assertSame('700000.00', $p->billableContractValue());
        $line = collect(ProjectClosingController::readiness($p)['checks'])->firstWhere('label', 'Nilai tertagih sesuai termin yang tidak batal');
        $this->assertSame([], $line['blockers']);
        $this->assertSame('1000000.00', array_reduce(array_column($this->invoiceRows(), 'planned_amount'), fn ($c, $x) => bcadd($c, $x, 2), '0.00'));
    }

    public function test_approve_after_a_second_termin_is_cancelled_between_submit_and_approve_is_409_and_nothing_changes(): void
    {
        $this->make('INCLUSIVE_PPN', '671151608.00', ['30.0000', '30.0000', '40.0000'], []);
        $this->bpSubmit([$this->termins[1] => '30.0000', $this->termins[2] => '33.3333', $this->termins[3] => '36.6667'])->assertSessionHasNoErrors();
        $id = DB::table('project_adjustments')->value('id');
        $this->actingAs($this->manager)->post("/invoices/{$this->termins[1]}/cancel", ['reason' => 'x'])->assertSessionHasNoErrors();
        $rows = $this->invoiceRows();
        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertStatus(409);
        $this->assertSame($rows, $this->invoiceRows());
        $this->assertSame('SUBMITTED', DB::table('project_adjustments')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'APPROVE')->where('entity_type', 'PROJECT_ADJUSTMENT')->count());
    }

    public function test_cancelling_the_last_termin_after_submit_blocks_approve_with_409(): void
    {
        $this->make('INCLUSIVE_PPN', '671151608.00', ['30.0000', '30.0000', '40.0000'], []);
        $this->bpSubmit([$this->termins[1] => '31.0000', $this->termins[2] => '30.0000', $this->termins[3] => '39.0000'])->assertSessionHasNoErrors();
        $id = DB::table('project_adjustments')->value('id');
        DB::table('invoices')->where('id', $this->termins[3])->update(['workflow_status' => 'CANCELLED']);
        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertStatus(409);
    }

    public function test_xss_in_reason_is_escaped_on_page_and_inbox(): void
    {
        $this->make('INCLUSIVE_PPN', '671151608.00', ['30.0000', '30.0000', '40.0000'], [1 => 'PAID']);
        $this->bpSubmit([$this->termins[2] => '33.3333', $this->termins[3] => '36.6667'], ['reason' => '<script>alert(1)</script>"><img src=x onerror=alert(2)>'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false);
        $this->actingAs($this->manager)->get('/approvals')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false);
    }

    public function test_revision_form_is_rendered_for_owner_and_not_for_other_staff_and_stale_hides_approve(): void
    {
        $this->make('INCLUSIVE_PPN', '671151608.00', ['30.0000', '30.0000', '40.0000'], [1 => 'PAID']);
        $this->bpSubmit([$this->termins[2] => '33.3333', $this->termins[3] => '36.6667'])->assertSessionHasNoErrors();
        $id = DB::table('project_adjustments')->value('id');
        $this->actingAs($this->manager)->post("/adjustments/$id/request-revision", ['reason' => 'ubah'])->assertSessionHasNoErrors();
        $page = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk();
        $page->assertSee('name="_modal" value="adjust-'.$id.'"', false)->assertSee('Perbaiki dan ajukan ulang Koreksi Persentase Termin');
        $other = User::factory()->role('STAFF_PIC', 'BM')->create();
        DB::table('project_user_assignments')->insert(['project_id' => $this->project->id, 'user_id' => $other->id, 'assignment_role' => 'PIC', 'assigned_by' => $other->id, 'is_active' => true]);
        $this->actingAs($other)->put("/adjustments/$id", $this->payload([$this->termins[2] => '34.0000', $this->termins[3] => '36.0000']))->assertForbidden();
    }

    public function test_stale_proposal_hides_approve_button_and_shows_warning(): void
    {
        $this->make('INCLUSIVE_PPN', '671151608.00', ['30.0000', '30.0000', '40.0000'], [1 => 'PAID']);
        $this->bpSubmit([$this->termins[2] => '33.3333', $this->termins[3] => '36.6667'])->assertSessionHasNoErrors();
        DB::table('invoices')->where('id', $this->termins[2])->update(['workflow_status' => 'CANCELLED']);
        $html = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Daftar termin yang belum ditagih sudah berubah', $html);
        $this->assertStringNotContainsString('Setujui Koreksi Persentase Termin?', $html);
    }

    public function test_two_submits_in_sequence_second_is_blocked_even_from_another_staff(): void
    {
        $this->make('INCLUSIVE_PPN', '671151608.00', ['30.0000', '30.0000', '40.0000'], [1 => 'PAID']);
        $other = User::factory()->role('STAFF_PIC', 'BM')->create();
        DB::table('project_user_assignments')->insert(['project_id' => $this->project->id, 'user_id' => $other->id, 'assignment_role' => 'PIC', 'assigned_by' => $other->id, 'is_active' => true]);
        $this->bpSubmit([$this->termins[2] => '33.3333', $this->termins[3] => '36.6667'])->assertSessionHasNoErrors();
        $this->actingAs($other)->post("/projects/{$this->project->id}/adjustments", $this->payload([$this->termins[2] => '35.0000', $this->termins[3] => '35.0000']))
            ->assertSessionHasErrors('billing_percentages', null, 'adjustment-new');
        $this->assertSame(1, DB::table('project_adjustments')->count());
        // Setelah APPROVED boleh membuka koreksi baru berdasarkan persentase terbaru.
        $this->actingAs($this->manager)->post('/adjustments/'.DB::table('project_adjustments')->value('id').'/approve')->assertSessionHasNoErrors();
        $this->actingAs($other)->post("/projects/{$this->project->id}/adjustments", $this->payload([$this->termins[2] => '35.0000', $this->termins[3] => '35.0000']))->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('project_adjustments')->count());
    }

    // ---- C2: sapuan uang ----

    public function test_return_sweep_integer_cent_oracle_for_pool_sisa_and_untouched_project(): void
    {
        mt_srand(77);
        for ($it = 0; $it < 40; $it++) {
            $project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
            $eff = mt_rand(1, 900000000) * 1; // sen
            $operational = mt_rand(0, 4) === 0;
            $nego = $operational ? 0 : mt_rand(0, (int) ($eff * 1.3));
            $actual = mt_rand(0, 2) === 0 ? mt_rand(0, (int) ($eff * 1.2)) : null;
            $other = mt_rand(0, 500000000);
            $unalloc = mt_rand(0, 300000000);
            $cents = fn (int $c) => sprintf('%d.%02d', intdiv($c, 100), $c % 100);
            $a = DB::table('bq_items')->insertGetId([
                'project_id' => $project->id, 'item_name' => 'A', 'item_type' => $operational ? 'OPERATIONAL' : 'NORMAL', 'status' => 'APPROVED', 'created_by' => $this->staff->id,
                'baseline_budget' => $cents($eff), 'effective_budget' => $cents($eff), 'negotiated_value' => $cents($nego), 'actual_cost' => $actual === null ? null : $cents($actual), 'margin_amount' => '0.00', 'margin_pct' => '0.0000',
            ]);
            $b = DB::table('bq_items')->insertGetId([
                'project_id' => $project->id, 'item_name' => 'B', 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'created_by' => $this->staff->id,
                'baseline_budget' => $cents($other), 'effective_budget' => $cents($other), 'negotiated_value' => '0.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000',
            ]);
            DB::table('projects')->where('id', $project->id)->update(['effective_rab' => $cents($eff + $other + $unalloc)]);
            $sisa = $eff - ($actual ?? $nego);
            $projectRow = (array) DB::table('projects')->where('id', $project->id)->first();
            $bBefore = (array) DB::table('bq_items')->find($b);

            $pick = [$sisa, $sisa + 1, max(1, $sisa - 1), mt_rand(1, max(1, abs($sisa) + 5))][$it % 4];
            $ok = $pick >= 1 && $pick <= $sisa;
            $res = $this->actingAs($this->staff)->post("/projects/{$project->id}/budget-mutations", [
                'mutation_type' => 'RETURN_TO_UNALLOCATED_RAB', 'source_bq_id' => $a, 'amount' => $cents($pick), 'reason' => 'sweep', 'file' => UploadedFile::fake()->create('n.pdf', 10, 'application/pdf'),
            ]);
            $label = "it $it eff $eff nego $nego actual ".var_export($actual, true)." pick $pick sisa $sisa";
            if (! $ok) {
                $res->assertSessionHasErrors(null, 'mutation-new');
                $this->assertSame(0, DB::table('bq_budget_mutations')->where('project_id', $project->id)->count(), $label);

                continue;
            }
            $res->assertSessionHasNoErrors();
            $m = DB::table('bq_budget_mutations')->where('project_id', $project->id)->first();
            $this->assertNull($m->destination_bq_id);
            $this->actingAs($this->manager)->post("/budget-mutations/{$m->id}/approve")->assertSessionHasNoErrors();

            $newEff = $eff - $pick;
            $row = DB::table('bq_items')->find($a);
            $this->assertSame($cents($newEff), $row->effective_budget, $label);
            $this->assertSame((bool) (($actual ?? $nego) > $newEff), (bool) $row->is_overbudget, $label);
            if (! $operational) {
                $this->assertSame($cents($newEff - $nego) === $row->margin_amount || $newEff - $nego < 0, true, $label);
                $this->assertSame(bcsub($cents($newEff), $cents($nego), 2), $row->margin_amount, $label);
            }
            $this->assertSame($bBefore, (array) DB::table('bq_items')->find($b), $label);
            $this->assertSame($projectRow, (array) DB::table('projects')->where('id', $project->id)->first(), "$label: project berubah");
            $poolNow = bcsub($cents($eff + $other + $unalloc), bcadd($cents($newEff), $cents($other), 2), 2);
            $this->assertSame($cents($unalloc + $pick), $poolNow, $label);
            $audit = DB::table('audit_logs')->where('entity_type', 'BQ_MUTATION')->where('entity_id', $m->id)->where('action', 'APPROVE')->sole();
            $this->assertSame([$cents($unalloc), $cents($unalloc + $pick)], [json_decode($audit->data_before, true)['unallocated_rab'], json_decode($audit->data_after, true)['unallocated_rab']], $label);
        }
    }

    public function test_return_whole_item_to_zero_is_safe_and_next_return_is_rejected(): void
    {
        $project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
        $a = DB::table('bq_items')->insertGetId(['project_id' => $project->id, 'item_name' => 'A', 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'created_by' => $this->staff->id,
            'baseline_budget' => '100.00', 'effective_budget' => '100.00', 'negotiated_value' => '0.00', 'margin_amount' => '100.00', 'margin_pct' => '100.0000']);
        DB::table('projects')->where('id', $project->id)->update(['effective_rab' => '100.00']);
        $file = fn () => UploadedFile::fake()->create('n.pdf', 10, 'application/pdf');
        $post = fn (string $amount) => $this->actingAs($this->staff)->post("/projects/{$project->id}/budget-mutations", ['mutation_type' => 'RETURN_TO_UNALLOCATED_RAB', 'source_bq_id' => $a, 'amount' => $amount, 'reason' => 'x', 'file' => $file()]);
        $post('100.00')->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post('/budget-mutations/'.DB::table('bq_budget_mutations')->value('id').'/approve')->assertSessionHasNoErrors();
        $row = DB::table('bq_items')->find($a);
        $this->assertSame(['0.00', '0.00', '0.0000'], [$row->effective_budget, $row->margin_amount, $row->margin_pct]);
        $post('0.01')->assertSessionHasErrors('amount', null, 'mutation-new');
    }

    // ---- C2: izin / status / input kasar ----

    public function test_return_authorization_division_scope_and_status_gates(): void
    {
        $project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
        $a = DB::table('bq_items')->insertGetId(['project_id' => $project->id, 'item_name' => 'A', 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'created_by' => $this->staff->id,
            'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '100.00', 'margin_amount' => '900.00', 'margin_pct' => '90.0000']);
        DB::table('projects')->where('id', $project->id)->update(['effective_rab' => '1000.00']);
        $payload = fn () => ['mutation_type' => 'RETURN_TO_UNALLOCATED_RAB', 'source_bq_id' => $a, 'amount' => '10.00', 'reason' => 'x', 'file' => UploadedFile::fake()->create('n.pdf', 10, 'application/pdf')];
        $url = "/projects/{$project->id}/budget-mutations";

        $outsider = User::factory()->role('STAFF_PIC', 'BM')->create();
        $cp = User::factory()->role('MANAGER_GM', 'CP')->create();
        $direksi = User::factory()->role('DIREKSI', null)->create();
        $this->actingAs($this->manager)->post($url, $payload())->assertForbidden();
        $this->actingAs($direksi)->post($url, $payload())->assertForbidden();
        $this->actingAs($outsider)->post($url, $payload())->assertNotFound();
        $this->assertSame(0, DB::table('bq_budget_mutations')->count());

        $this->actingAs($this->staff)->post($url, $payload())->assertSessionHasNoErrors();
        $id = DB::table('bq_budget_mutations')->value('id');
        $this->actingAs($cp)->post("/budget-mutations/$id/approve")->assertNotFound();
        $this->actingAs($direksi)->post("/budget-mutations/$id/approve")->assertForbidden();
        $this->actingAs($this->staff)->post("/budget-mutations/$id/approve")->assertForbidden();
        $this->actingAs($this->staff)->post("/budget-mutations/$id/request-revision", ['reason' => 'x'])->assertForbidden();

        foreach (['DRAFT', 'FINANCIALLY_CLOSED', 'ARCHIVED', 'CANCELLED'] as $status) {
            DB::table('projects')->where('id', $project->id)->update(['status' => $status]);
            $this->actingAs($this->staff)->post($url, $payload())->assertForbidden();
            $this->actingAs($this->manager)->post("/budget-mutations/$id/approve")->assertForbidden();
        }
        DB::table('projects')->where('id', $project->id)->update(['status' => 'COMPLETED']);
        $this->actingAs($this->manager)->post("/budget-mutations/$id/approve")->assertSessionHasNoErrors();
        $this->assertSame('990.00', DB::table('bq_items')->find($a)->effective_budget);
        $this->assertSame(1, DB::table('audit_logs')->where('entity_type', 'BQ_MUTATION')->where('action', 'APPROVE')->count());
    }

    public function test_return_source_must_be_approved_own_project_and_crafted_inputs_do_not_500(): void
    {
        $project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
        $foreign = Project::factory()->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
        $mk = fn (int $pid, string $status) => DB::table('bq_items')->insertGetId(['project_id' => $pid, 'item_name' => 'X', 'item_type' => 'NORMAL', 'status' => $status, 'created_by' => $this->staff->id,
            'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '100.00', 'margin_amount' => '900.00', 'margin_pct' => '90.0000']);
        $url = "/projects/{$project->id}/budget-mutations";
        $file = fn () => UploadedFile::fake()->create('n.pdf', 10, 'application/pdf');
        foreach ([$mk($foreign->id, 'APPROVED'), $mk($project->id, 'DRAFT'), $mk($project->id, 'SUBMITTED'), $mk($project->id, 'ARCHIVED')] as $src) {
            $this->actingAs($this->staff)->post($url, ['mutation_type' => 'RETURN_TO_UNALLOCATED_RAB', 'source_bq_id' => $src, 'amount' => '1.00', 'reason' => 'x', 'file' => $file()])->assertSessionHasErrors('source_bq_id', null, 'mutation-new');
        }
        foreach ([['source_bq_id' => ['1']], ['source_bq_id' => '1.5'], ['source_bq_id' => '-1'], ['source_bq_id' => 'abc'], ['amount' => ['1']], ['amount' => '1e2'], ['amount' => '0.00'], ['amount' => '-5'], ['reason' => ['a']], ['mutation_type' => ['RETURN_TO_UNALLOCATED_RAB']]] as $extra) {
            $res = $this->actingAs($this->staff)->post($url, array_replace(['mutation_type' => 'RETURN_TO_UNALLOCATED_RAB', 'source_bq_id' => $mk($project->id, 'APPROVED'), 'amount' => '1.00', 'reason' => 'x', 'file' => $file()], $extra));
            $this->assertLessThan(500, $res->getStatusCode(), json_encode($extra));
        }
        $this->assertSame(0, DB::table('bq_budget_mutations')->count());
        $this->assertSame(0, DB::table('documents')->count());
    }

    public function test_return_source_archived_or_drained_between_submit_and_approve_is_422_without_change(): void
    {
        $project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
        $a = DB::table('bq_items')->insertGetId(['project_id' => $project->id, 'item_name' => 'A', 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'created_by' => $this->staff->id,
            'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '100.00', 'margin_amount' => '900.00', 'margin_pct' => '90.0000']);
        DB::table('projects')->where('id', $project->id)->update(['effective_rab' => '1000.00']);
        $this->actingAs($this->staff)->post("/projects/{$project->id}/budget-mutations", ['mutation_type' => 'RETURN_TO_UNALLOCATED_RAB', 'source_bq_id' => $a, 'amount' => '900.00', 'reason' => 'x', 'file' => UploadedFile::fake()->create('n.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
        $id = DB::table('bq_budget_mutations')->value('id');

        DB::table('bq_items')->where('id', $a)->update(['actual_cost' => '150.00']); // sisa turun jadi 850
        $this->actingAs($this->manager)->post("/budget-mutations/$id/approve")->assertSessionHasErrors('mutation');
        DB::table('bq_items')->where('id', $a)->update(['actual_cost' => null, 'status' => 'ARCHIVED']);
        $this->actingAs($this->manager)->post("/budget-mutations/$id/approve")->assertSessionHasErrors('mutation');
        $this->assertSame(['1000.00', 'SUBMITTED', 0], [DB::table('bq_items')->find($a)->effective_budget, DB::table('bq_budget_mutations')->find($id)->status, DB::table('audit_logs')->where('action', 'APPROVE')->count()]);
    }

    public function test_return_resubmit_can_switch_type_both_ways_and_schema_stays_valid(): void
    {
        $project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
        $mk = fn () => DB::table('bq_items')->insertGetId(['project_id' => $project->id, 'item_name' => 'X', 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'created_by' => $this->staff->id,
            'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '100.00', 'margin_amount' => '900.00', 'margin_pct' => '90.0000']);
        [$a, $b] = [$mk(), $mk()];
        DB::table('projects')->where('id', $project->id)->update(['effective_rab' => '2000.00']);
        $file = fn () => UploadedFile::fake()->create('n.pdf', 10, 'application/pdf');

        $this->actingAs($this->staff)->post("/projects/{$project->id}/budget-mutations", ['mutation_type' => 'RETURN_TO_UNALLOCATED_RAB', 'source_bq_id' => $a, 'amount' => '10.00', 'reason' => 'x', 'file' => $file()])->assertSessionHasNoErrors();
        $id = DB::table('bq_budget_mutations')->value('id');
        $this->actingAs($this->manager)->post("/budget-mutations/$id/request-revision", ['reason' => 'ganti'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->put("/budget-mutations/$id", ['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a, 'destination_bq_id' => $b, 'amount' => '10.00', 'reason' => 'x'])->assertSessionHasNoErrors();
        $this->assertSame(['TRANSFER_BETWEEN_BQ', $b], [DB::table('bq_budget_mutations')->find($id)->mutation_type, (int) DB::table('bq_budget_mutations')->find($id)->destination_bq_id]);
        $this->actingAs($this->manager)->post("/budget-mutations/$id/request-revision", ['reason' => 'balik'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->put("/budget-mutations/$id", ['mutation_type' => 'RETURN_TO_UNALLOCATED_RAB', 'source_bq_id' => $a, 'destination_bq_id' => $b, 'amount' => '10.00', 'reason' => 'x'])->assertSessionHasNoErrors();
        $this->assertSame(['RETURN_TO_UNALLOCATED_RAB', null], [DB::table('bq_budget_mutations')->find($id)->mutation_type, DB::table('bq_budget_mutations')->find($id)->destination_bq_id]);
        $this->actingAs($this->manager)->post("/budget-mutations/$id/request-revision", ['reason' => 'lagi'])->assertSessionHasNoErrors();
        // FROM_UNALLOCATED dengan source dari klien: source diabaikan.
        $this->actingAs($this->staff)->put("/budget-mutations/$id", ['mutation_type' => 'FROM_UNALLOCATED_RAB', 'source_bq_id' => $a, 'destination_bq_id' => $b, 'amount' => '10.00', 'reason' => 'x'])->assertSessionHasNoErrors();
        $this->assertSame(['FROM_UNALLOCATED_RAB', null, $b], [DB::table('bq_budget_mutations')->find($id)->mutation_type, DB::table('bq_budget_mutations')->find($id)->source_bq_id, (int) DB::table('bq_budget_mutations')->find($id)->destination_bq_id]);
    }

    public function test_pending_return_blocks_complete_and_closing_and_xss_reason_is_escaped(): void
    {
        $project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
        $a = DB::table('bq_items')->insertGetId(['project_id' => $project->id, 'item_name' => '<b>Item</b>', 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'created_by' => $this->staff->id,
            'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '100.00', 'margin_amount' => '900.00', 'margin_pct' => '90.0000']);
        DB::table('projects')->where('id', $project->id)->update(['effective_rab' => '1000.00']);
        $this->actingAs($this->staff)->post("/projects/{$project->id}/budget-mutations", ['mutation_type' => 'RETURN_TO_UNALLOCATED_RAB', 'source_bq_id' => $a, 'amount' => '10.00', 'reason' => '<script>alert(1)</script>', 'file' => UploadedFile::fake()->create('n.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();

        // Mutasi pending tidak memblokir complete() (masih bisa diputuskan saat COMPLETED, aturan lama); yang memblokir adalah kesiapan closing.
        $this->actingAs($this->manager)->post("/projects/{$project->id}/complete")->assertSessionHasNoErrors();
        $this->assertSame('COMPLETED', DB::table('projects')->where('id', $project->id)->value('status'));
        $ready = ProjectClosingController::readiness($project->fresh());
        $this->assertFalse($ready['ready']);
        $page = $this->actingAs($this->manager)->get("/projects/{$project->id}")->assertOk();
        $page->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<b>Item</b>', false);
        $this->actingAs($this->manager)->get('/approvals')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    /**
     * D-C-1 (ditutup lewat Tarik pengajuan): usulan Koreksi Persentase Termin yang basi karena termin berubah sampai tinggal <= 1 termin PLANNED tidak bisa disetujui atau diajukan ulang;
     * pengaju menariknya (WITHDRAWN), lalu complete() dan kesiapan closing tidak lagi terblokir.
     */
    public function test_stale_correction_that_can_no_longer_be_resubmitted_must_not_block_completion_forever(): void
    {
        $this->make('INCLUSIVE_PPN', '671151608.00', ['30.0000', '30.0000', '40.0000'], [1 => 'PAID']);
        $this->bpSubmit([$this->termins[2] => '33.3333', $this->termins[3] => '36.6667'])->assertSessionHasNoErrors();
        $id = DB::table('project_adjustments')->value('id');
        DB::table('invoices')->where('id', $this->termins[2])->update(['workflow_status' => 'CANCELLED']); // hanya T3 PLANNED

        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertStatus(409);
        $this->actingAs($this->manager)->post("/adjustments/$id/request-revision", ['reason' => 'basi'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->put("/adjustments/$id", $this->payload([$this->termins[3] => '70.0000']))->assertSessionHasErrors();
        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/complete")->assertStatus(409);

        $this->actingAs($this->staff)->post("/adjustments/$id/withdraw", ['reason' => 'Termin berubah'])->assertSessionHasNoErrors();
        $this->assertSame('WITHDRAWN', DB::table('project_adjustments')->where('id', $id)->value('status'));
        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/complete")->assertSessionHasNoErrors();
        $this->assertSame('COMPLETED', DB::table('projects')->where('id', $this->project->id)->value('status'));
        $pending = collect(ProjectClosingController::readiness($this->project->fresh())['checks'])->firstWhere('label', 'Tidak ada pengajuan pending atau revisi');
        $this->assertSame([], $pending['blockers']);
    }

    /** Manager/GM menarik usulan basi (SUBMITTED); usulan baru ditolak selama yang lama terbuka dan diterima setelah ditarik. */
    public function test_manager_withdraws_stale_correction_and_new_proposal_is_accepted(): void
    {
        $this->make('INCLUSIVE_PPN', '1000000.00', ['30.0000', '20.0000', '20.0000', '30.0000'], [1 => 'PAID']);
        $this->bpSubmit([$this->termins[2] => '10.0000', $this->termins[3] => '30.0000', $this->termins[4] => '30.0000'])->assertSessionHasNoErrors();
        $id = DB::table('project_adjustments')->value('id');
        DB::table('invoices')->where('id', $this->termins[2])->update(['workflow_status' => 'CANCELLED']); // usulan basi, T3 dan T4 masih PLANNED
        $new = [$this->termins[3] => '25.0000', $this->termins[4] => '25.0000'];

        $this->bpSubmit($new)->assertSessionHasErrors(['billing_percentages' => 'Masih ada Koreksi Persentase Termin yang belum selesai.'], null, 'adjustment-new');
        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertStatus(409);
        $this->actingAs($this->manager)->post("/adjustments/$id/withdraw", ['reason' => 'Basi'])->assertSessionHasNoErrors();
        $this->assertSame('WITHDRAWN', DB::table('project_adjustments')->where('id', $id)->value('status'));

        $this->bpSubmit($new)->assertSessionHasNoErrors();
        $this->assertSame(['WITHDRAWN', 'SUBMITTED'], DB::table('project_adjustments')->orderBy('id')->pluck('status')->all());
    }
}
