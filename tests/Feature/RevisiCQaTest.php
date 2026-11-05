<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// QA independen Revisi C1 (relokasi dibatasi Sisa item, is_overbudget) dan C3 (Nota Disposisi Direksi wajib pada TARGET_MARGIN).
// Oracle uang memakai bilangan bulat sen, bukan bcmath/Money, supaya tidak mengulang implementasi.
class RevisiCQaTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private User $otherStaff;

    private User $otherManager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->otherStaff = User::factory()->role('STAFF_PIC', 'CP')->create();
        $this->otherManager = User::factory()->role('MANAGER_GM', 'CP')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create([
            'created_by' => $this->staff->id, 'status' => 'ACTIVE', 'contract_value' => '1000000.00', 'margin_base_amount' => '1000000.00',
            'target_margin_pct' => '10.0000', 'target_margin_amount' => '100000.00', 'initial_rab' => '900000.00', 'effective_rab' => '900000.00',
        ]);
    }

    private function pdf(string $name = 'Nota.pdf', int $kb = 100): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    private function cents(string $money): int
    {
        return (int) str_replace('.', '', $money);
    }

    private function money(int $cents): string
    {
        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
    }

    private function bq(string $budget, string $nego, ?string $actual = null, string $type = 'NORMAL', string $status = 'APPROVED'): int
    {
        return DB::table('bq_items')->insertGetId([
            'project_id' => $this->project->id, 'item_name' => 'Item '.uniqid(), 'item_type' => $type, 'status' => $status, 'created_by' => $this->staff->id,
            'baseline_budget' => $budget, 'effective_budget' => $budget, 'negotiated_value' => $nego, 'actual_cost' => $actual, 'margin_amount' => '0.00', 'margin_pct' => '0.0000',
        ]);
    }

    private function relocate(int $source, int $destination, string $amount, array $extra = [])
    {
        return $this->actingAs($this->staff)->post("/projects/{$this->project->id}/budget-mutations", $extra + [
            'mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $source, 'destination_bq_id' => $destination, 'amount' => $amount, 'reason' => 'Geser', 'file' => $this->pdf(),
        ]);
    }

    private function mutation(?int $source, int $destination, string $amount, string $status = 'SUBMITTED', ?int $by = null): int
    {
        return DB::table('bq_budget_mutations')->insertGetId([
            'project_id' => $this->project->id, 'source_bq_id' => $source, 'destination_bq_id' => $destination, 'amount' => $amount, 'reason' => 'Geser',
            'mutation_type' => $source ? 'TRANSFER_BETWEEN_BQ' : 'FROM_UNALLOCATED_RAB', 'status' => $status, 'submitted_by' => $by ?? $this->staff->id,
        ]);
    }

    private function item(int $id): object
    {
        return DB::table('bq_items')->find($id);
    }

    /** margin_pct = margin/eff*100 half-up 4 desimal dengan bilangan bulat; dijepit -999,9999; eff nol = 0. */
    private function pct4(int $marginCents, int $effCents): string
    {
        if ($effCents === 0) {
            return '0.0000';
        }
        $num = $marginCents * 1_000_000;
        $q = intdiv(2 * abs($num) + $effCents, 2 * $effCents);
        $units = max(($num < 0 ? -1 : 1) * $q, -9_999_999);

        return sprintf('%s%d.%04d', $units < 0 ? '-' : '', intdiv(abs($units), 10_000), abs($units) % 10_000);
    }

    // ---------- C1 ----------

    public function test_oracle_sweep_accept_reject_boundary_and_post_approve_figures_match_integer_cents(): void
    {
        // [eff, nego, actual|null] dalam sen; amount diuji pada sisa-1, sisa, sisa+1.
        $cases = [
            [100_000_000, 80_000_000, null], [100_000_000, 80_000_000, 75_000_000], [100_000_000, 80_000_000, 105_000_000], [333_333, 100_001, null], [333_333, 100_001, 1],
            [1, 0, null], [100, 100, null], [100, 100, 100], [99_999_999_999, 33_333_333_333, 33_333_333_334], [1_000, 2_000, null], [1_000, 2_000, 500], [7, 3, 2],
        ];
        $b = $this->bq('500.00', '600.00');
        foreach ($cases as [$eff, $nego, $actual]) {
            $sisa = $eff - ($actual ?? $nego);
            foreach ([$sisa - 1, $sisa, $sisa + 1] as $amount) {
                if ($amount < 1) {
                    continue;
                }
                $a = $this->bq($this->money($eff), $this->money($nego), $actual === null ? null : $this->money($actual));
                $before = DB::table('bq_budget_mutations')->count();
                $r = $this->relocate($a, $b, $this->money($amount));
                $label = "eff=$eff nego=$nego actual=".var_export($actual, true)." amount=$amount sisa=$sisa";
                if ($amount > $sisa) {
                    $r->assertSessionHasErrors('amount', null, 'mutation-new');
                    $this->assertSame($before, DB::table('bq_budget_mutations')->count(), $label);

                    continue;
                }
                $r->assertSessionHasNoErrors();
                $m = (int) DB::table('bq_budget_mutations')->max('id');
                $beforeB = $this->cents($this->item($b)->effective_budget);
                $this->actingAs($this->manager)->post("/budget-mutations/$m/approve")->assertSessionHasNoErrors();

                $newEff = $eff - $amount;
                $ra = $this->item($a);
                $this->assertSame($this->money($newEff), $ra->effective_budget, $label);
                $this->assertSame($this->money($newEff - $nego), $ra->margin_amount, $label);
                $this->assertSame($this->pct4($newEff - $nego, $newEff), $ra->margin_pct, $label);
                $this->assertSame((($actual ?? $nego) > $newEff) ? 1 : 0, (int) $ra->is_overbudget, $label);
                $this->assertSame($this->money($beforeB + $amount), $this->item($b)->effective_budget, $label);
                // Sisa sesudah approve tidak pernah negatif akibat relokasi sendiri bila sebelumnya tidak negatif.
                $this->assertGreaterThanOrEqual(0, $newEff - ($actual ?? $nego), $label);
                DB::table('bq_items')->where('id', $b)->update(['effective_budget' => '500.00', 'baseline_budget' => '500.00']);
            }
        }
    }

    public function test_odd_numeric_amount_forms_never_500_and_never_bypass_the_sisa_guard(): void
    {
        $a = $this->bq('1000.00', '800.00'); // sisa 200,00
        $b = $this->bq('500.00', '600.00');
        foreach (['+200.01', '200.010', '2e2', '0x10', '200,01', '१००', ' 200.01 ', '200.', '.5', '+0.5', '00000200.00'] as $v) {
            $status = $this->relocate($a, $b, $v)->status();
            $this->assertContains($status, [302], "amount '$v'");
        }
        foreach (DB::table('bq_budget_mutations')->get() as $row) {
            $this->assertLessThanOrEqual(20000, $this->cents($row->amount), 'nominal tersimpan melebihi sisa: '.$row->amount);
        }
        // '+200.01' / '200.010' (desimal ke-3 nol) harus tidak menyisipkan baris di atas sisa.
        $this->assertSame([], DB::table('bq_budget_mutations')->where('amount', '>', '200.00')->pluck('id')->all());
    }

    public function test_approve_audit_carries_source_sisa_using_actual_cost_and_no_audit_on_rejection(): void
    {
        $a = $this->bq('1000.00', '800.00', '750.00'); // sisa 250,00
        $b = $this->bq('500.00', '600.00');
        $ok = $this->mutation($a, $b, '250.00');
        $bad = $this->mutation($a, $b, '250.01');

        $this->actingAs($this->manager)->post("/budget-mutations/$bad/approve")->assertSessionHasErrors(['mutation' => 'Sisa item sumber tidak cukup: sisa '.$this->item($a)->item_name.' Rp250,00, relokasi Rp250,01.']);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'APPROVE')->count());
        $this->actingAs($this->manager)->post("/budget-mutations/$ok/approve")->assertSessionHasNoErrors();
        $audit = DB::table('audit_logs')->where('action', 'APPROVE')->sole();
        $this->assertSame(['1000.00', '250.00'], [json_decode($audit->data_before)->source_effective_budget, json_decode($audit->data_before)->source_sisa]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'APPROVE')->count());
        // Sisa sumber sekarang 0,00: usulan kedua tidak bisa lagi lolos, bahkan 0,01.
        $this->actingAs($this->manager)->post("/budget-mutations/$bad/approve")->assertSessionHasErrors('mutation');
        $this->assertSame('750.00', $this->item($a)->effective_budget);
    }

    public function test_is_overbudget_writers_bq_create_update_and_page_badge_follow_the_sisa_rule(): void
    {
        $page = "/projects/{$this->project->id}";
        $this->actingAs($this->staff)->post("$page/bq-items", ['item_name' => 'Over', 'item_type' => 'NORMAL', 'vendor_quote_amount' => '0', 'baseline_budget' => '100.00', 'negotiated_value' => '100.01'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post("$page/bq-items", ['item_name' => 'Pas', 'item_type' => 'NORMAL', 'vendor_quote_amount' => '0', 'baseline_budget' => '100.00', 'negotiated_value' => '100.00'])->assertSessionHasNoErrors();
        $this->assertSame([1, 0], DB::table('bq_items')->orderBy('id')->pluck('is_overbudget')->map(fn ($v) => (int) $v)->all());

        // Badge halaman = kolom, bukan hitung ulang terpisah.
        $actualOver = $this->bq('100.00', '10.00', '100.01');
        $actualExact = $this->bq('100.00', '150.00', '100.00'); // nego di atas RAB, tetapi realisasi = RAB: tidak overbudget
        DB::table('bq_items')->where('id', $actualOver)->update(['is_overbudget' => 1]);
        $html = $this->actingAs($this->staff)->get($page)->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, '>Overbudget<'));
        $this->assertSame(0, (int) $this->item($actualExact)->is_overbudget);
    }

    public function test_relocation_form_shows_both_nota_message_variants_and_sisa_in_options(): void
    {
        $a = $this->bq('1000.00', '800.00');
        $b = $this->bq('500.00', '600.00');
        $page = "/projects/{$this->project->id}";
        $html = $this->actingAs($this->staff)->from($page)->followingRedirects()->post("$page/budget-mutations", [
            'mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a, 'destination_bq_id' => $b, 'amount' => '10.00', 'reason' => 'x',
        ])->assertOk()->getContent();
        $this->assertStringContainsString('Nota Disposisi wajib dilampirkan.', $html);
        $this->assertStringNotContainsString('Pilih file yang akan diunggah.', $html);
        $this->assertSame(2, substr_count($html, '(Nilai RAB Rp1.000,00, sisa Rp200,00)')); // sumber dan tujuan di form baru
        $this->assertSame(2, substr_count($html, '(Nilai RAB Rp500,00, sisa -Rp100,00)')); // sisa negatif tampil apa adanya
        // Pesan file lain tetap dari FILE_MESSAGES.
        $html = $this->actingAs($this->staff)->from($page)->followingRedirects()->post("$page/budget-mutations", [
            'mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a, 'destination_bq_id' => $b, 'amount' => '10.00', 'reason' => 'x', 'file' => UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload'),
        ])->assertOk()->getContent();
        $this->assertStringContainsString('File harus PDF, JPG, atau PNG.', $html);
    }

    public function test_crafted_array_and_scalar_file_inputs_on_relocation_and_adjustment_do_not_500_or_leave_files(): void
    {
        $a = $this->bq('1000.00', '800.00');
        $b = $this->bq('500.00', '600.00');
        $page = "/projects/{$this->project->id}";
        $base = ['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a, 'destination_bq_id' => $b, 'amount' => '10.00', 'reason' => 'x'];
        foreach ([['file' => [$this->pdf(), $this->pdf()]], ['file' => 'x'], ['file' => ['a' => 'b']], ['file' => [[]]]] as $bad) {
            $this->actingAs($this->staff)->from($page)->followingRedirects()->post("$page/budget-mutations", $base + $bad)->assertOk();
        }
        $adj = ['margin_mode' => 'PCT', 'margin_value' => '12', 'reason' => 'r'];
        foreach ([['file' => [$this->pdf()]], ['file' => 'x'], ['file' => ['a' => 'b']], ['adjustment_type' => ['TARGET_MARGIN']], ['adjustment_type' => ['x' => 'CONTRACT_VALUE'], 'file' => [$this->pdf()]]] as $bad) {
            $this->actingAs($this->staff)->from($page)->followingRedirects()->post("$page/adjustments", $adj + $bad)->assertOk();
        }
        // Ajukan ulang dengan array.
        $id = $this->legacyRevision(false);
        foreach ([['file' => [$this->pdf()]], ['file' => 'x'], ['adjustment_type' => ['x']]] as $bad) {
            $this->actingAs($this->staff)->from($page)->followingRedirects()->put("/adjustments/$id", $adj + $bad)->assertOk();
        }
        $this->assertSame([0, 0, 0, []], [DB::table('bq_budget_mutations')->count(), DB::table('project_adjustments')->where('id', '<>', $id)->count(), DB::table('documents')->count(), Storage::disk('local')->allFiles()]);
        $this->assertSame('REVISION_REQUIRED', DB::table('project_adjustments')->where('id', $id)->value('status'));
    }

    // ---------- C3 ----------

    private function legacyRevision(bool $withNota, string $type = 'TARGET_MARGIN'): int
    {
        $new = $type === 'TARGET_MARGIN'
            ? ['target_margin_pct' => '12.0000', 'target_margin_amount' => '120000.00', 'effective_rab' => '880000.00', 'margin_mode' => 'PCT', 'margin_value' => '12.0000']
            : ['contract_value' => '900000.00', 'margin_base_amount' => '900000.00', 'target_margin_pct' => '12.0000', 'target_margin_amount' => '108000.00', 'effective_rab' => '792000.00', 'margin_mode' => 'PCT', 'margin_value' => '12.0000', 'cancelled_sequences' => [2]];
        $id = DB::table('project_adjustments')->insertGetId([
            'project_id' => $this->project->id, 'adjustment_type' => $type, 'status' => 'REVISION_REQUIRED', 'reason' => 'Nego', 'submitted_by' => $this->staff->id,
            'old_data' => json_encode(['target_margin_pct' => '10.0000', 'target_margin_amount' => '100000.00', 'effective_rab' => '900000.00']), 'new_data' => json_encode($new), 'difference_amount' => '-20000.00',
        ]);
        if ($withNota) {
            $this->actingAs($this->staff)->put("/adjustments/$id", ['margin_mode' => 'PCT', 'margin_value' => '12', 'reason' => 'r', 'file' => $this->pdf('Lama.pdf')])->assertSessionHasNoErrors();
            DB::table('project_adjustments')->where('id', $id)->update(['status' => 'REVISION_REQUIRED']);
        }

        return $id;
    }

    private function adjust(array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->staff)->post("/projects/{$this->project->id}/adjustments", $extra + ['margin_mode' => 'PCT', 'margin_value' => '12', 'reason' => 'Nego']);
    }

    public function test_file_validation_on_nota_type_extension_size_and_empty_type_defaults_to_target_margin(): void
    {
        $this->adjust(['file' => $this->pdf('Nota.png')])->assertSessionHasErrors('file', null, 'adjustment-new');
        $this->adjust(['file' => UploadedFile::fake()->create('Nota.exe', 10, 'application/x-msdownload')])->assertSessionHasErrors('file', null, 'adjustment-new');
        $this->adjust(['file' => UploadedFile::fake()->image('foto.gif')])->assertSessionHasErrors('file', null, 'adjustment-new');
        $this->adjust(['file' => $this->pdf('Besar.pdf', 51201)])->assertSessionHasErrors(['file' => 'Ukuran file maksimal 50 MB.'], null, 'adjustment-new');
        $this->adjust(['adjustment_type' => ''])->assertSessionHasErrors(['file' => 'Nota Disposisi Direksi wajib dilampirkan.'], null, 'adjustment-new'); // string kosong = TARGET_MARGIN
        $this->assertSame([0, 0, []], [DB::table('project_adjustments')->count(), DB::table('documents')->count(), Storage::disk('local')->allFiles()]);

        $this->adjust(['file' => $this->pdf('Pas.pdf', 51200)])->assertSessionHasNoErrors(); // tepat 50 MB lolos
        $this->adjust(['file' => UploadedFile::fake()->image('Foto.JPG')])->assertSessionHasNoErrors(); // ekstensi huruf besar
        $this->assertSame(2, DB::table('documents')->where('entity_type', 'PROJECT_ADJUSTMENT')->count());
    }

    public function test_contract_value_ignores_any_file_and_resubmit_type_cannot_be_flipped_to_dodge_the_nota(): void
    {
        $contract = $this->legacyRevision(false, 'CONTRACT_VALUE');
        // Fixture tanpa termin batal: koreksi kontrak ditolak sebagai aturan lain (bukan karena file), file tidak pernah divalidasi atau disimpan.
        $r = $this->actingAs($this->staff)->put("/adjustments/$contract", ['margin_mode' => 'PCT', 'margin_value' => '12', 'reason' => 'r', 'file' => $this->pdf()]);
        $r->assertSessionHasErrors('adjustment_type', null, "adjustment-$contract")->assertSessionDoesntHaveErrors('file', null, "adjustment-$contract");
        $this->assertSame(0, DB::table('documents')->count()); // diabaikan, tidak disimpan
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->actingAs($this->staff)->put("/adjustments/$contract", ['margin_mode' => 'PCT', 'margin_value' => '12', 'reason' => 'r', 'file' => 'bukan-file'])->assertSessionDoesntHaveErrors('file', null, "adjustment-$contract"); // tidak 500; file tidak diperiksa

        // Usulan TARGET_MARGIN lama tanpa nota: mengirim adjustment_type=CONTRACT_VALUE di body tidak menghapus kewajiban nota.
        $legacy = $this->legacyRevision(false);
        $this->actingAs($this->staff)->put("/adjustments/$legacy", ['adjustment_type' => 'CONTRACT_VALUE', 'margin_mode' => 'PCT', 'margin_value' => '12', 'reason' => 'r'])->assertSessionHasErrors('file', null, "adjustment-$legacy");
        $this->assertSame('REVISION_REQUIRED', DB::table('project_adjustments')->where('id', $legacy)->value('status'));
        // Dan tidak bisa menempelkan nota ke baris CONTRACT_VALUE lewat adjustment_type=TARGET_MARGIN.
        DB::table('project_adjustments')->where('id', $contract)->update(['status' => 'REVISION_REQUIRED']);
        $this->actingAs($this->staff)->put("/adjustments/$contract", ['adjustment_type' => 'TARGET_MARGIN', 'margin_mode' => 'PCT', 'margin_value' => '12', 'reason' => 'r', 'file' => $this->pdf()])->assertSessionDoesntHaveErrors('file', null, "adjustment-$contract");
        $this->assertSame([0, 'CONTRACT_VALUE'], [DB::table('documents')->count(), DB::table('project_adjustments')->where('id', $contract)->value('adjustment_type')]);
    }

    public function test_project_status_gate_stores_no_file_and_authorization_is_unchanged(): void
    {
        foreach (['DRAFT', 'FINANCIALLY_CLOSED', 'ARCHIVED', 'CANCELLED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            $this->adjust(['file' => $this->pdf()])->assertForbidden();
        }
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'ACTIVE']);
        $this->assertSame([0, 0, []], [DB::table('project_adjustments')->count(), DB::table('documents')->count(), Storage::disk('local')->allFiles()]);

        $this->adjust(['file' => $this->pdf()], $this->manager)->assertForbidden(); // Manager tidak mengajukan
        $this->adjust(['file' => $this->pdf()], $this->otherStaff)->assertNotFound(); // divisi lain
        $this->assertSame([0, []], [DB::table('documents')->count(), Storage::disk('local')->allFiles()]);

        $this->adjust(['file' => $this->pdf()])->assertSessionHasNoErrors();
        $id = (int) DB::table('project_adjustments')->value('id');
        $this->actingAs($this->staff)->post("/adjustments/$id/approve")->assertForbidden(); // staff
        $this->actingAs($this->otherManager)->post("/adjustments/$id/approve")->assertNotFound();
        DB::table('project_adjustments')->where('id', $id)->update(['submitted_by' => $this->manager->id]);
        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertForbidden(); // self-approval
        $this->assertSame('SUBMITTED', DB::table('project_adjustments')->where('id', $id)->value('status'));
        DB::table('project_adjustments')->where('id', $id)->update(['submitted_by' => $this->staff->id]);
        $this->actingAs($this->manager)->post("/adjustments/$id/approve")->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', DB::table('project_adjustments')->where('id', $id)->value('status'));
        // Resubmit setelah disetujui: 409, dan dengan nota pun tidak ada berkas baru.
        $this->actingAs($this->staff)->put("/adjustments/$id", ['margin_mode' => 'PCT', 'margin_value' => '5', 'reason' => 'r', 'file' => $this->pdf('Baru.pdf')])->assertStatus(409);
        $this->assertSame(1, DB::table('documents')->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_nota_is_private_signed_division_scoped_and_audit_complete(): void
    {
        $this->adjust(['file' => $this->pdf('Rahasia.pdf')])->assertSessionHasNoErrors();
        $doc = DB::table('documents')->sole();
        $this->assertMatchesRegularExpression('#^documents/'.$this->project->id.'/[0-9a-f-]{36}\.pdf$#', $doc->storage_key); // nama acak, bukan nama klien
        $this->assertStringNotContainsString('Rahasia', $doc->storage_key);

        // Tautan di halaman -> alihan ke URL bertanda tangan; tanpa tanda tangan 403; divisi lain 404; tamu diarahkan login.
        $open = route('documents.open', $doc->id);
        $this->actingAs($this->manager)->get($open)->assertRedirectContains('signature=');
        $this->actingAs($this->manager)->get(route('documents.download', $doc->id))->assertForbidden();
        $this->actingAs($this->otherManager)->get($open)->assertNotFound();
        $this->actingAs($this->otherStaff)->get($open)->assertNotFound();
        $this->actingAs($this->otherStaff)->get("/projects/{$this->project->id}")->assertNotFound();
        auth()->logout();
        $this->get($open)->assertRedirect('/login');

        // Audit SUBMIT memuat id nota; data lain tidak bocor ke audit (tanpa path berkas).
        $audit = DB::table('audit_logs')->where('entity_type', 'PROJECT_ADJUSTMENT')->where('action', 'SUBMIT')->sole();
        $after = json_decode($audit->data_after, true);
        $this->assertSame($doc->id, $after['nota_document_id']);
        $this->assertStringNotContainsString($doc->storage_key, $audit->data_after);

        // Halaman Dokumen: Sumber "Penyesuaian margin" untuk TARGET_MARGIN; link nota di baris penyesuaian.
        $html = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Penyesuaian margin', $html);
        $this->assertStringContainsString('Unduh Nota Disposisi Direksi penyesuaian', $html);
    }

    public function test_resubmit_replacement_keeps_chain_page_link_points_to_latest_and_documents_tab_lists_both(): void
    {
        $id = $this->legacyRevision(true);
        $first = DB::table('documents')->sole();
        DB::table('project_adjustments')->where('id', $id)->update(['status' => 'REVISION_REQUIRED']);
        $this->actingAs($this->staff)->put("/adjustments/$id", ['margin_mode' => 'PCT', 'margin_value' => '13', 'reason' => 'r', 'file' => $this->pdf('Baru.pdf')])->assertSessionHasNoErrors();

        $docs = DB::table('documents')->orderBy('id')->get();
        $this->assertSame(['REPLACED', 'UPLOADED', $first->id], [$docs[0]->status, $docs[1]->status, $docs[1]->replaced_document_id]);
        $html = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->getContent();
        $this->assertStringContainsString(route('documents.open', $docs[1]->id), $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, route('documents.open', $docs[0]->id)) + 1); // lama hanya di tab Dokumen
        $row = DB::table('audit_logs')->where('entity_type', 'PROJECT_ADJUSTMENT')->where('action', 'SUBMIT')->orderByDesc('id')->first();
        $this->assertSame($docs[1]->id, json_decode($row->data_after, true)['nota_document_id']);
        // Ajukan ulang tanpa file mencatat nota_document_id null (tidak ada nota baru), nota lama tetap UPLOADED.
        DB::table('project_adjustments')->where('id', $id)->update(['status' => 'REVISION_REQUIRED']);
        $this->actingAs($this->staff)->put("/adjustments/$id", ['margin_mode' => 'PCT', 'margin_value' => '14', 'reason' => 'r'])->assertSessionHasNoErrors();
        $this->assertSame(['REPLACED', 'UPLOADED'], DB::table('documents')->orderBy('id')->pluck('status')->all());
    }

    public function test_failed_audit_after_file_stored_leaves_no_orphan_and_keeps_old_nota_active(): void
    {
        $id = $this->legacyRevision(true);
        $old = DB::table('documents')->sole();
        DB::table('project_adjustments')->where('id', $id)->update(['status' => 'REVISION_REQUIRED']);
        $files = Storage::disk('local')->allFiles();

        DB::listen(fn ($q) => str_contains($q->sql, 'insert into `audit_logs`') && throw new \RuntimeException('audit gagal'));
        $this->actingAs($this->staff)->put("/adjustments/$id", ['margin_mode' => 'PCT', 'margin_value' => '13', 'reason' => 'r', 'file' => $this->pdf('Baru.pdf')])->assertStatus(500);
        $this->adjust(['file' => $this->pdf('Baru2.pdf')])->assertStatus(500);

        $this->assertSame($files, Storage::disk('local')->allFiles()); // berkas baru dibersihkan
        $this->assertSame([$old->id], DB::table('documents')->pluck('id')->all());
        $this->assertSame('UPLOADED', DB::table('documents')->find($old->id)->status); // penandaan REPLACED ikut batal
        $this->assertSame(['REVISION_REQUIRED', 1], [DB::table('project_adjustments')->where('id', $id)->value('status'), DB::table('project_adjustments')->count()]);
    }

    public function test_failed_audit_on_relocation_store_and_resubmit_leaves_no_orphan_and_keeps_old_nota_active(): void
    {
        $a = $this->bq('1000.00', '800.00');
        $b = $this->bq('500.00', '600.00');
        $m = $this->mutation($a, $b, '10.00', 'SUBMITTED');
        $this->actingAs($this->staff)->put("/budget-mutations/$m", ['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a, 'destination_bq_id' => $b, 'amount' => '10.00', 'reason' => 'r'])->assertStatus(409);
        DB::table('bq_budget_mutations')->where('id', $m)->update(['status' => 'REVISION_REQUIRED']);
        $this->actingAs($this->staff)->put("/budget-mutations/$m", ['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a, 'destination_bq_id' => $b, 'amount' => '10.00', 'reason' => 'r', 'file' => $this->pdf('Lama.pdf')])->assertSessionHasNoErrors();
        DB::table('bq_budget_mutations')->where('id', $m)->update(['status' => 'REVISION_REQUIRED']);
        $files = Storage::disk('local')->allFiles();
        $count = DB::table('bq_budget_mutations')->count();

        DB::listen(fn ($q) => str_contains($q->sql, 'insert into `audit_logs`') && throw new \RuntimeException('audit gagal'));
        $this->actingAs($this->staff)->put("/budget-mutations/$m", ['mutation_type' => 'TRANSFER_BETWEEN_BQ', 'source_bq_id' => $a, 'destination_bq_id' => $b, 'amount' => '11.00', 'reason' => 'r', 'file' => $this->pdf('Baru.pdf')])->assertStatus(500);
        $this->relocate($a, $b, '12.00')->assertStatus(500);

        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame(['UPLOADED', 10], [DB::table('documents')->sole()->status, $this->cents(DB::table('bq_budget_mutations')->find($m)->amount) / 100]);
        $this->assertSame($count, DB::table('bq_budget_mutations')->count());
    }

    public function test_failed_document_row_insert_deletes_the_stored_file(): void
    {
        DB::listen(fn ($q) => str_contains($q->sql, 'insert into `documents`') && throw new \RuntimeException('dokumen gagal'));
        $this->adjust(['file' => $this->pdf()])->assertStatus(500);
        $this->assertSame([0, 0, []], [DB::table('project_adjustments')->count(), DB::table('documents')->count(), Storage::disk('local')->allFiles()]);
    }
}
