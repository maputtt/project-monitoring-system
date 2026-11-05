<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// BQ-04 (PRD 5.4, AC-06, AC-10): mutasi budget. Submit tidak menggeser saldo; approve menggeser atomik di bawah kunci dan mengecek ulang saldo.
// CONCURRENCY: tes paralel sungguhan butuh dua server/proses (dikerjakan qa-engineer). Di sini urutannya disimulasikan berurutan:
// dua usulan SUBMITTED yang menguras sumber yang sama, approve pertama lolos dan kedua ditolak 422 oleh pengecekan ulang di bawah kunci.
class BudgetMutationTest extends TestCase
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
        // effective_rab 570.478.866,80; cadangan operasional 70.478.866,80 APPROVED, jadi sisa RAB belum dialokasikan tepat 500.000.000,00.
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
        $this->bq('OPERATIONAL', 'APPROVED', '70478866.80', '0.00');
    }

    private function bq(string $type = 'NORMAL', string $status = 'APPROVED', string $budget = '1000.00', string $nego = '800.00', ?int $project = null, ?string $actual = null): int
    {
        return DB::table('bq_items')->insertGetId([
            'project_id' => $project ?? $this->project->id, 'item_name' => "Item $type", 'item_type' => $type, 'status' => $status, 'created_by' => $this->staff->id,
            'baseline_budget' => $budget, 'effective_budget' => $budget, 'negotiated_value' => $nego, 'actual_cost' => $actual, 'margin_amount' => '0.00', 'margin_pct' => '0.0000',
        ]);
    }

    private function mutation(?int $source, int $destination, string $amount, string $status = 'SUBMITTED', ?int $by = null): int
    {
        return DB::table('bq_budget_mutations')->insertGetId([
            'project_id' => $this->project->id, 'source_bq_id' => $source, 'destination_bq_id' => $destination, 'amount' => $amount, 'reason' => 'Geser budget',
            'mutation_type' => $source ? 'TRANSFER_BETWEEN_BQ' : 'FROM_UNALLOCATED_RAB', 'status' => $status, 'submitted_by' => $by ?? $this->staff->id,
        ]);
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['mutation_type' => 'FROM_UNALLOCATED_RAB', 'destination_bq_id' => $this->dest, 'amount' => '100.00', 'reason' => 'Butuh tambahan',
            'file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf')];
    }

    private int $dest = 0;

    private function budget(int $id): string
    {
        return DB::table('bq_items')->where('id', $id)->value('effective_budget');
    }

    private function audits(string $action): int
    {
        return DB::table('audit_logs')->where('entity_type', 'BQ_MUTATION')->where('action', $action)->count();
    }

    private function mstatus(int $mutation): string
    {
        return DB::table('bq_budget_mutations')->where('id', $mutation)->value('status');
    }

    private function totalBudget(): string
    {
        return DB::table('bq_items')->where('project_id', $this->project->id)->sum('effective_budget');
    }

    public function test_submit_creates_submitted_mutation_with_private_nota_and_does_not_move_saldo(): void
    {
        $this->dest = $this->bq(budget: '1000.00');

        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/budget-mutations", $this->payload(['source_bq_id' => 999999]))->assertSessionHasNoErrors()->assertRedirect("/projects/{$this->project->id}");

        $m = DB::table('bq_budget_mutations')->first();
        $this->assertSame(['SUBMITTED', 'FROM_UNALLOCATED_RAB', null, '100.00', $this->staff->id], [$m->status, $m->mutation_type, $m->source_bq_id, $m->amount, $m->submitted_by]); // sumber dari klien diabaikan
        $doc = DB::table('documents')->first();
        $this->assertSame(['BQ_MUTATION', $m->id, 'NOTA_DISPOSISI', 'UPLOADED'], [$doc->entity_type, $doc->entity_id, $doc->document_type, $doc->status]);
        Storage::disk('local')->assertExists($doc->storage_key);
        $this->assertSame('1000.00', $this->budget($this->dest));
        $this->assertSame(1, $this->audits('SUBMIT'));
        $this->assertSame(0, $this->audits('APPROVE'));
    }

    public function test_crafted_mutation_type_is_not_echoed_into_alpine_x_data_after_422(): void
    {
        $this->dest = $this->bq();
        $page = "/projects/{$this->project->id}";
        $payload = $this->payload(['mutation_type' => "x'}; window.PWNED=1; if(1){var z='"]);

        // Ikuti redirect 422 supaya old() dan error bag terbawa ke halaman project, lalu pastikan payload tidak keluar sebagai JavaScript.
        $this->actingAs($this->staff)->from($page)->followingRedirects()->post("{$page}/budget-mutations", $payload)
            ->assertOk()->assertSee('Jenis relokasi tidak valid.')->assertDontSee('PWNED', false);
    }

    public function test_submit_validation_rejects_bad_input_without_row_file_or_audit(): void
    {
        $this->dest = $this->bq();
        $other = Project::factory()->create();
        $foreign = $this->bq(project: $other->id);
        $draft = $this->bq(status: 'DRAFT');
        $cases = [
            'tanpa Nota' => [['file' => null], 'file'],
            'ekstensi tidak cocok' => [['file' => UploadedFile::fake()->create('Nota.png', 100, 'application/pdf')], 'file'],
            'nominal nol' => [['amount' => '0.00'], 'amount'],
            'nominal tiga desimal' => [['amount' => '1.234'], 'amount'],
            'nominal negatif' => [['amount' => '-5.00'], 'amount'],
            'alasan kosong' => [['reason' => ''], 'reason'],
            'TRANSFER tanpa sumber' => [['mutation_type' => 'TRANSFER_BETWEEN_BQ'], 'source_bq_id'],
            'sumber sama dengan tujuan' => [['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $this->dest], 'source_bq_id'],
            'jenis tidak didukung' => [['mutation_type' => 'PROJECT_ADJUSTMENT'], 'mutation_type'],
            'sumber milik project lain' => [['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $foreign], 'source_bq_id'],
            'tujuan belum APPROVED' => [['destination_bq_id' => $draft], 'destination_bq_id'],
        ];

        foreach ($cases as $name => [$extra, $field]) {
            $payload = array_replace($this->payload(), $extra);
            $this->actingAs($this->staff)->post("/projects/{$this->project->id}/budget-mutations", $payload)->assertSessionHasErrors($field, null, 'mutation-new');
        }
        $this->assertSame([0, 0, 0], [DB::table('bq_budget_mutations')->count(), DB::table('documents')->count(), $this->audits('SUBMIT')]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_transfer_approve_moves_saldo_atomically_and_recomputes_margins(): void
    {
        $a = $this->bq(budget: '1000.00', nego: '800.00'); // sisa 200,00
        $b = $this->bq(budget: '500.00', nego: '600.00'); // overbudget sebelum mutasi
        DB::table('bq_items')->where('id', $b)->update(['is_overbudget' => true]);
        $total = $this->totalBudget();
        $m = $this->mutation($a, $b, '200.00');

        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(['800.00', '700.00', $total], [$this->budget($a), $this->budget($b), $this->totalBudget()]); // seimbang: total tidak berubah
        $ra = DB::table('bq_items')->find($a);
        $rb = DB::table('bq_items')->find($b);
        $this->assertSame(['0.00', '0.0000', 0, 2], [$ra->margin_amount, $ra->margin_pct, (int) $ra->is_overbudget, $ra->version]); // 800 - 800
        $this->assertSame(['100.00', '14.2857', 0, 2], [$rb->margin_amount, $rb->margin_pct, (int) $rb->is_overbudget, $rb->version]); // 700 - 600; 100 / 700 = 14,2857
        $this->assertSame('1000.00', $ra->baseline_budget); // baseline tidak tertimpa
        $row = DB::table('bq_budget_mutations')->find($m);
        $this->assertSame(['APPROVED', $this->manager->id], [$row->status, $row->approved_by]);
        $audit = DB::table('audit_logs')->where('action', 'APPROVE')->where('entity_type', 'BQ_MUTATION')->first();
        $this->assertSame($m, (int) $audit->entity_id);
        $before = json_decode($audit->data_before, true);
        $after = json_decode($audit->data_after, true);
        $this->assertSame(['1000.00', '200.00', '500.00'], [$before['source_effective_budget'], $before['source_sisa'], $before['destination_effective_budget']]);
        $this->assertSame(['800.00', '700.00'], [$after['source_effective_budget'], $after['destination_effective_budget']]);

        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertStatus(409); // sudah APPROVED: tidak bisa dua kali
        $this->assertSame('800.00', $this->budget($a));
    }

    public function test_from_unallocated_rab_is_bounded_by_remaining_rab(): void
    {
        $a = $this->bq(budget: '400000000.00');
        $b = $this->bq(budget: '99999000.00'); // sisa belum dialokasikan = 1.000,00
        $tooMuch = $this->mutation(null, $b, '1000.01');
        $fits = $this->mutation(null, $b, '1000.00');

        $this->actingAs($this->manager)->post("/budget-mutations/$tooMuch/approve")->assertSessionHasErrors('mutation');
        $this->assertSame(['SUBMITTED', '99999000.00', 0], [$this->mstatus($tooMuch), $this->budget($b), $this->audits('APPROVE')]);

        $this->actingAs($this->manager)->post("/budget-mutations/$fits/approve")->assertSessionHasNoErrors();
        $this->assertSame(['100000000.00', '400000000.00'], [$this->budget($b), $this->budget($a)]);
        $this->assertSame(DB::table('projects')->where('id', $this->project->id)->value('effective_rab'), $this->totalBudget()); // Σ effective budget = Effective RAB persis

        $next = $this->mutation(null, $b, '0.01');
        $this->actingAs($this->manager)->post("/budget-mutations/$next/approve")->assertSessionHasErrors('mutation'); // sisa RAB habis
        $this->assertSame('100000000.00', $this->budget($b));
    }

    public function test_insufficient_sisa_is_rejected_at_store_resubmit_and_approve_without_change(): void
    {
        $a = $this->bq(budget: '1000.00', nego: '800.00'); // sisa 200,00
        $b = $this->bq(budget: '10.00');
        $this->dest = $b;
        $url = "/projects/{$this->project->id}/budget-mutations";
        $msg = 'Sisa item sumber tidak cukup: sisa Item NORMAL Rp200,00, relokasi Rp200,01.';

        // Store: 422 pada field amount di bag mutation-new; tanpa baris, berkas, atau audit.
        $this->actingAs($this->staff)->post($url, $this->payload(['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a, 'amount' => '200.01']))->assertSessionHasErrors(['amount' => $msg], null, 'mutation-new');
        $this->assertSame([0, 0, 0], [DB::table('bq_budget_mutations')->count(), DB::table('documents')->count(), $this->audits('SUBMIT')]);
        $this->assertSame([], Storage::disk('local')->allFiles());

        // Resubmit: bag mutation-{id}; baris tetap REVISION_REQUIRED.
        $r = $this->mutation($a, $b, '10.00', 'REVISION_REQUIRED');
        $this->actingAs($this->staff)->put("/budget-mutations/$r", $this->payload(['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a, 'amount' => '200.01']))->assertSessionHasErrors(['amount' => $msg], null, "mutation-$r");
        $this->assertSame(['REVISION_REQUIRED', '10.00', 0], [$this->mstatus($r), DB::table('bq_budget_mutations')->find($r)->amount, $this->audits('SUBMIT')]);

        // Approve (baris disisipkan mentah, melewati store): 422 pada field mutation, tanpa perubahan dan tanpa audit APPROVE.
        $m = $this->mutation($a, $b, '200.01');
        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertStatus(302)->assertSessionHasErrors(['mutation' => $msg]);
        $this->assertSame(['1000.00', '10.00', 'SUBMITTED', 0], [$this->budget($a), $this->budget($b), $this->mstatus($m), $this->audits('APPROVE')]);
        $this->assertSame(1, DB::table('bq_items')->find($a)->version);

        // Tepat sebesar Sisa lolos.
        $this->actingAs($this->staff)->post($url, $this->payload(['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a, 'amount' => '200.00']))->assertSessionHasNoErrors();
        $this->assertSame(1, $this->audits('SUBMIT'));
    }

    public function test_sisa_follows_actual_cost_and_operational_items_are_relocatable_by_the_same_formula(): void
    {
        $url = "/projects/{$this->project->id}/budget-mutations";
        $b = $this->bq(budget: '10.00');
        $this->dest = $b;
        $go = fn (int $src, string $amount) => $this->actingAs($this->staff)->post($url, $this->payload(['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $src, 'amount' => $amount]));

        // Realisasi terisi menggantikan nego: eff 1.000,00, realisasi 750,00 -> sisa 250,00; margin tetap di atas nego, overbudget di atas realisasi.
        $a = $this->bq(budget: '1000.00', nego: '800.00', actual: '750.00');
        $go($a, '250.01')->assertSessionHasErrors('amount', null, 'mutation-new');
        $m = $this->mutation($a, $b, '250.00');
        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertSessionHasNoErrors();
        $ra = DB::table('bq_items')->find($a);
        $this->assertSame(['750.00', '-50.00', '-6.6667', 0], [$ra->effective_budget, $ra->margin_amount, $ra->margin_pct, (int) $ra->is_overbudget]); // 750 > 750 tidak

        // Realisasi melebihi RAB: sisa -50.000,00, 0,01 pun ditolak.
        $over = $this->bq(budget: '1000000.00', nego: '800000.00', actual: '1050000.00');
        $go($over, '0.01')->assertSessionHasErrors(['amount' => 'Sisa item sumber tidak cukup: sisa Item NORMAL -Rp50.000,00, relokasi Rp0,01.'], null, 'mutation-new');

        // OPERATIONAL (nego 0): realisasi kosong -> sisa seluruh RAB; terisi 4.250.000,00 -> sisa 750.000,00.
        $op = (int) DB::table('bq_items')->where('item_type', 'OPERATIONAL')->value('id'); // satu per project (dari setUp)
        DB::table('bq_items')->where('id', $op)->update(['baseline_budget' => '5000000.00', 'effective_budget' => '5000000.00']);
        $go($op, '5000000.01')->assertSessionHasErrors('amount', null, 'mutation-new');
        $go($op, '5000000.00')->assertSessionHasNoErrors();
        DB::table('bq_items')->where('id', $op)->update(['actual_cost' => '4250000.00']);
        $go($op, '750000.01')->assertSessionHasErrors('amount', null, 'mutation-new');
        $go($op, '750000.00')->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('bq_budget_mutations')->where('source_bq_id', $op)->count());
    }

    public function test_actual_cost_filled_after_submit_makes_approve_fail_with_no_change(): void
    {
        $a = $this->bq(budget: '1000.00', nego: '800.00'); // sisa 200,00
        $b = $this->bq(budget: '10.00');
        $this->dest = $b;
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/budget-mutations", $this->payload(['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a, 'amount' => '200.00']))->assertSessionHasNoErrors();
        $m = (int) DB::table('bq_budget_mutations')->value('id');

        // Realisasi 900,00 masuk setelah diajukan: sisa turun ke 100,00, usulan 200,00 tidak lagi muat.
        $this->actingAs($this->staff)->put("/bq-items/$a/actual-cost", ['actual_cost' => '900.00', 'version' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertSessionHasErrors(['mutation' => 'Sisa item sumber tidak cukup: sisa Item NORMAL Rp100,00, relokasi Rp200,00.']);

        $this->assertSame(['1000.00', '10.00', 'SUBMITTED', 0], [$this->budget($a), $this->budget($b), $this->mstatus($m), $this->audits('APPROVE')]);
        $this->assertSame(2, DB::table('bq_items')->find($a)->version); // hanya versi dari penulisan realisasi
    }

    public function test_is_overbudget_is_actual_or_nego_over_effective_in_actual_cost_writer_mutation_approve_and_backfill(): void
    {
        $flag = fn (int $id) => (int) DB::table('bq_items')->find($id)->is_overbudget;

        // Penulis realisasi: RAB 1.000,00; nego 800,00 tidak lagi menentukan.
        $a = $this->bq(budget: '1000.00', nego: '800.00');
        foreach ([['1000.01', 1], ['1000.00', 0], ['999.99', 0]] as [$actual, $expected]) {
            $this->actingAs($this->staff)->put("/bq-items/$a/actual-cost", ['actual_cost' => $actual, 'reason' => 'koreksi', 'version' => DB::table('bq_items')->find($a)->version])->assertSessionHasNoErrors();
            $this->assertSame($expected, $flag($a), $actual);
        }
        $audit = DB::table('audit_logs')->where('entity_type', 'BQ_ITEM')->where('entity_id', $a)->orderBy('id')->first();
        $this->assertSame([0, 1], [json_decode($audit->data_before)->is_overbudget, json_decode($audit->data_after)->is_overbudget]);

        // Penulis approve mutasi: tujuan RAB 500,00 dengan realisasi 600,00 overbudget; setelah +100,00 menjadi 600 > 600 tidak.
        $src = $this->bq(budget: '1000.00', nego: '0.00');
        $dst = $this->bq(budget: '500.00', nego: '400.00', actual: '600.00');
        DB::table('bq_items')->where('id', $dst)->update(['is_overbudget' => true]);
        $this->actingAs($this->manager)->post('/budget-mutations/'.$this->mutation($src, $dst, '100.00').'/approve')->assertSessionHasNoErrors();
        $this->assertSame([0, 0], [$flag($dst), $flag($src)]);
        $op = DB::table('bq_items')->where('item_type', 'OPERATIONAL')->value('id'); // OPERATIONAL ikut aturan yang sama
        DB::table('bq_items')->where('id', $op)->update(['actual_cost' => '70478866.80']);
        $this->actingAs($this->manager)->post('/budget-mutations/'.$this->mutation(null, $op, '1.00').'/approve')->assertSessionHasNoErrors();
        $this->assertSame(0, $flag($op));

        // Backfill: baris lama yang dihitung dari nego saja diselaraskan; migrasi idempoten.
        $rows = [
            $this->bq(budget: '100.00', nego: '80.00', actual: '120.00'), // seharusnya 1
            $this->bq(budget: '100.00', nego: '150.00', actual: '90.00'), // seharusnya 0
            $this->bq(budget: '100.00', nego: '150.00'), // seharusnya 1
            $this->bq(budget: '100.00', nego: '100.00'), // seharusnya 0
        ];
        DB::table('bq_items')->whereIn('id', $rows)->update(['is_overbudget' => false]);
        DB::table('bq_items')->where('id', $rows[1])->orWhere('id', $rows[3])->update(['is_overbudget' => true]);
        $migration = require base_path('database/migrations/2026_10_22_000003_align_bq_items_is_overbudget.php');
        $migration->up();
        $migration->up();
        $this->assertSame([1, 0, 1, 0], array_map($flag, $rows));
    }

    public function test_two_pending_proposals_draining_same_source_second_is_rejected_on_recheck(): void
    {
        $a = $this->bq(budget: '100.00', nego: '0.00');
        $b = $this->bq(budget: '0.00');
        $first = $this->mutation($a, $b, '60.00');
        $second = $this->mutation($a, $b, '60.00'); // masing-masing muat sendiri, bersama-sama menarik saldo negatif

        $this->actingAs($this->manager)->post("/budget-mutations/$first/approve")->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/budget-mutations/$second/approve")->assertSessionHasErrors('mutation');

        $this->assertSame(['40.00', '60.00', 'APPROVED', 'SUBMITTED'], [$this->budget($a), $this->budget($b), $this->mstatus($first), $this->mstatus($second)]);
        $this->assertSame(1, $this->audits('APPROVE'));
    }

    public function test_submitter_cannot_decide_own_mutation_even_as_manager(): void
    {
        $a = $this->bq();
        $b = $this->bq();
        $m = $this->mutation($a, $b, '10.00', by: $this->manager->id);

        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertForbidden();
        $this->actingAs($this->manager)->post("/budget-mutations/$m/request-revision", ['reason' => 'x'])->assertForbidden();

        $this->assertSame(['SUBMITTED', '1000.00', '1000.00'], [$this->mstatus($m), $this->budget($a), $this->budget($b)]);
        $this->assertSame(0, DB::table('audit_logs')->where('entity_type', 'BQ_MUTATION')->count());
    }

    public function test_roles_and_scope(): void
    {
        $a = $this->bq();
        $b = $this->bq();
        $m = $this->mutation($a, $b, '10.00');
        $otherManager = User::factory()->role('MANAGER_GM', 'CP')->create();
        $otherStaff = User::factory()->role('STAFF_PIC', 'BM')->create(); // divisi sama tapi tidak ditugaskan

        $this->actingAs($this->staff)->post("/budget-mutations/$m/approve")->assertForbidden();
        $this->actingAs($this->staff)->post("/budget-mutations/$m/request-revision", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($otherManager)->post("/budget-mutations/$m/approve")->assertNotFound();
        $this->actingAs($otherStaff)->post("/projects/{$this->project->id}/budget-mutations", $this->payload(['destination_bq_id' => $b]))->assertNotFound();
        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/budget-mutations", $this->payload(['destination_bq_id' => $b]))->assertForbidden(); // Manager tidak mengajukan
        $this->actingAs($this->manager)->post('/budget-mutations/999999/approve')->assertNotFound();

        $this->assertSame(['SUBMITTED', '1000.00'], [$this->mstatus($m), $this->budget($b)]);
    }

    public function test_request_revision_then_edit_and_resubmit_replaces_nota(): void
    {
        $a = $this->bq(budget: '1000.00');
        $b = $this->bq(budget: '500.00');
        $this->dest = $b;
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/budget-mutations", $this->payload(['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a, 'amount' => '150.00']))->assertSessionHasNoErrors();
        $m = (int) DB::table('bq_budget_mutations')->value('id');
        $oldNota = DB::table('documents')->value('id');

        $this->actingAs($this->manager)->post("/budget-mutations/$m/request-revision")->assertSessionHasErrors('reason');
        $this->assertSame('SUBMITTED', $this->mstatus($m));
        $this->actingAs($this->manager)->post("/budget-mutations/$m/request-revision", ['reason' => 'Nominal melebihi saldo'])->assertRedirect();
        $this->assertSame(['REVISION_REQUIRED', '1000.00', '500.00'], [$this->mstatus($m), $this->budget($a), $this->budget($b)]);
        $this->assertSame('Nominal melebihi saldo', DB::table('audit_logs')->where('action', 'REQUEST_REVISION')->value('reason'));
        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertStatus(409);
        $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertSee('Alasan: Nominal melebihi saldo');

        // Bukan pengaju atau bukan Staff/PIC: ditolak. Nominal tetap divalidasi.
        $second = User::factory()->role('STAFF_PIC', 'BM')->create();
        DB::table('project_user_assignments')->insert(['project_id' => $this->project->id, 'user_id' => $second->id, 'assignment_role' => 'PIC', 'assigned_by' => $this->staff->id, 'is_active' => true]);
        $this->actingAs($second)->put("/budget-mutations/$m", $this->payload(['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a]))->assertForbidden();
        $this->actingAs($this->manager)->put("/budget-mutations/$m", $this->payload(['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a]))->assertForbidden();
        $this->actingAs($this->staff)->put("/budget-mutations/$m", $this->payload(['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a, 'amount' => '0']))->assertSessionHasErrors('amount', null, "mutation-$m");

        $this->actingAs($this->staff)->put("/budget-mutations/$m", $this->payload(['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a, 'amount' => '100.00', 'reason' => 'Dikoreksi']))->assertSessionHasNoErrors();
        $row = DB::table('bq_budget_mutations')->find($m);
        $this->assertSame(['SUBMITTED', '100.00', 'Dikoreksi'], [$row->status, $row->amount, $row->reason]);
        $this->assertSame('REPLACED', DB::table('documents')->find($oldNota)->status);
        $this->assertSame($oldNota, DB::table('documents')->where('status', 'UPLOADED')->value('replaced_document_id'));
        $this->assertSame(2, $this->audits('SUBMIT'));
        $this->actingAs($this->staff)->put("/budget-mutations/$m", $this->payload(['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a]))->assertStatus(409); // sudah SUBMITTED

        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertSessionHasNoErrors();
        $this->assertSame(['900.00', '600.00'], [$this->budget($a), $this->budget($b)]);
    }

    public function test_project_status_gate(): void
    {
        $a = $this->bq();
        $b = $this->bq();
        $this->dest = $b;
        $m = $this->mutation($a, $b, '10.00');

        foreach (['DRAFT', 'SUBMITTED', 'FINANCIALLY_CLOSED', 'ARCHIVED', 'CANCELLED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            $this->actingAs($this->staff)->post("/projects/{$this->project->id}/budget-mutations", $this->payload())->assertForbidden();
            $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertForbidden();
        }
        $this->assertSame([1, 'SUBMITTED', '1000.00'], [DB::table('bq_budget_mutations')->count(), $this->mstatus($m), $this->budget($a)]);

        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED']);
        $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertSessionHasNoErrors();
        $this->assertSame('990.00', $this->budget($a));
    }

    public function test_project_page_lists_mutations_with_nota_and_role_based_actions(): void
    {
        $a = $this->bq();
        $this->dest = $a;
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/budget-mutations", $this->payload())->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->assertSee('Setujui relokasi')->assertSee('Minta revisi relokasi')->assertSee('Sisa RAB')->assertSee('Nota Disposisi');
        $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->assertSee('+ Ajukan Relokasi Budget')->assertSee('(Nilai RAB Rp1.000,00, sisa Rp200,00)', false)->assertDontSee('Setujui relokasi');
    }
}
