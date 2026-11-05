<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// QA independen Revisi A: hitung ulang Target Margin Awal lewat alur nyata (penyesuaian + koreksi), Sisa BQ, kartu, sapuan sisa teks lama, XSS.
class RevisiAQaTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    /** Teks lama yang tidak boleh tampil di layar mana pun (kecuali kapsul "Baseline terkunci", dicek terpisah). */
    private const OLD = ['Initial RAB', 'Effective RAB', 'Official', 'Available', 'Pending cost', 'Mutasi', 'mutasi', 'Margin base', 'Nilai nego', 'Effective budget',
        'Koreksi nilai kontrak', 'Nett received', 'Invoice outstanding', 'Sudah dipakai', 'Nilai kontrak awal', 'Sisa RAB belum dialokasikan', 'Pindah antar BQ', 'Tambah dari sisa RAB'];

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

    private function half(string $x): string
    {
        return bcadd($x, '0.005', 2); // half-up murni bcmath (nilai positif)
    }

    private function project(string $contract, string $mode, string $pct, array $state = []): Project
    {
        $base = $mode === 'EXCLUSIVE_PPN' ? $this->half(bcmul($contract, '1.110000', 8)) : $contract;
        $margin = $this->half(bcdiv(bcmul($base, $pct, 6), '100', 8));
        $project = Project::factory()->assignedTo($this->staff)->create($state + [
            'created_by' => $this->staff->id, 'status' => 'ACTIVE', 'contract_value' => $contract, 'contract_tax_mode' => $mode,
            'margin_base_ppn_rate_snapshot' => $mode === 'EXCLUSIVE_PPN' ? '11.0000' : null, 'margin_base_amount' => $base, 'target_margin_pct' => $pct,
            'target_margin_amount' => $margin, 'initial_rab' => bcsub($base, $margin, 2), 'effective_rab' => bcsub($base, $margin, 2),
        ]);
        foreach (['50.0000', '30.0000', '20.0000'] as $i => $p) {
            DB::table('invoices')->insert(['project_id' => $project->id, 'department' => 'BM', 'sequence_number' => $i + 1, 'billing_type' => 'TERMIN', 'billing_percentage' => $p,
                'planned_amount' => '1.00', 'workflow_status' => 'PLANNED', 'created_by' => $this->staff->id]);
        }

        return $project;
    }

    private function decide(Project $p, string $type, string $margin): void
    {
        $this->actingAs($this->staff)->post("/projects/{$p->id}/adjustments", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'adjustment_type' => $type, 'margin_mode' => 'PCT', 'margin_value' => $margin, 'reason' => 'uji'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post('/adjustments/'.DB::table('project_adjustments')->orderByDesc('id')->value('id').'/approve')->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function summary(Project $p): array
    {
        return $this->actingAs($this->manager)->get("/projects/{$p->id}")->assertOk()->viewData('summary');
    }

    public static function modes(): array
    {
        return [['INCLUSIVE_PPN'], ['NON_PPN'], ['EXCLUSIVE_PPN']];
    }

    #[DataProvider('modes')]
    public function test_target_margin_awal_stays_at_the_activation_value_after_margin_adjustment_and_contract_correction(string $mode): void
    {
        $p = $this->project('1000000.00', $mode, '15.0000');
        $exclusive = $mode === 'EXCLUSIVE_PPN';
        $awal = $exclusive ? '166500.00' : '150000.00'; // 1.110.000 x 15% / 1.000.000 x 15%
        $this->assertSame([$awal, '15.0000'], [$this->summary($p)['initial_margin_amount'], $this->summary($p)['initial_margin_pct']]);

        $this->decide($p, 'TARGET_MARGIN', '20');
        $row = DB::table('projects')->where('id', $p->id)->first();
        $this->assertSame($exclusive ? '222000.00' : '200000.00', $row->target_margin_amount);
        $s = $this->summary($p);
        $this->assertSame([$awal, '15.0000'], [$s['initial_margin_amount'], $s['initial_margin_pct']]);

        // Termin 3 (20%) dibatalkan -> nilai tertagih 800.000,00; dasar EXCLUSIVE = 800.000 x 1,11 = 888.000,00.
        DB::table('invoices')->where('project_id', $p->id)->where('sequence_number', 3)->update(['workflow_status' => 'CANCELLED']);
        $this->decide($p, 'CONTRACT_VALUE', '20');
        $row = DB::table('projects')->where('id', $p->id)->first();
        $this->assertSame(['800000.00', '1000000.00', $exclusive ? '888000.00' : '800000.00', $exclusive ? '177600.00' : '160000.00'], [$row->contract_value, $row->initial_contract_value, $row->margin_base_amount, $row->target_margin_amount]);
        $s = $this->summary($p);
        $this->assertSame([$awal, '15.0000'], [$s['initial_margin_amount'], $s['initial_margin_pct']]);

        $page = $this->get("/projects/{$p->id}")->assertOk();
        $page->assertSee('Target Margin Awal (15%)')->assertSee('Target Margin Perubahan (20%)')->assertSee('nilai tertagih Rp800.000,00 (termin dibatalkan)');
        $exclusive ? $page->assertSee('Nilai Project + PPN') : $page->assertDontSee('Nilai Project + PPN');
    }

    public function test_nilai_project_card_has_no_caption_when_not_corrected_and_other_modes_hide_the_ppn_card(): void
    {
        $p = $this->project('1000000.00', 'INCLUSIVE_PPN', '15.0000');
        $this->actingAs($this->manager)->get("/projects/{$p->id}")->assertOk()->assertSee('Nilai SPK; tidak berubah')->assertDontSee('nilai tertagih Rp')->assertDontSee('Nilai Project + PPN');
    }

    public function test_bq_sisa_is_rab_minus_actual_or_vendor_value_with_overbudget_highlighting_and_awal_caption(): void
    {
        $p = $this->project('1000000.00', 'INCLUSIVE_PPN', '15.0000');
        $bq = fn (string $name, string $status, string $base, string $eff, string $nego, ?string $actual, string $type = 'NORMAL', int $over = 0) => DB::table('bq_items')->insert([
            'project_id' => $p->id, 'item_name' => $name, 'item_type' => $type, 'status' => $status, 'created_by' => $this->staff->id, 'baseline_budget' => $base, 'effective_budget' => $eff,
            'negotiated_value' => $nego, 'actual_cost' => $actual, 'margin_amount' => '0.00', 'margin_pct' => '0.0000', 'is_overbudget' => $over,
        ]);
        $bq('SisaNego', 'APPROVED', '100.00', '100.00', '80.00', null);        // 20,00
        $bq('SisaActual', 'APPROVED', '100.00', '100.00', '80.00', '95.50');   // 4,50 (realisasi menang atas nego)
        $bq('SisaNegatif', 'APPROVED', '100.00', '100.00', '80.00', '120.25', 'NORMAL', 1); // -20,25 rose; flag selaras Sisa < 0
        $bq('Teralokasi', 'APPROVED', '70.00', '150.00', '60.00', null);       // awal Rp70,00; sisa 90,00
        $bq('Operasional', 'APPROVED', '50.00', '50.00', '0.00', null, 'OPERATIONAL'); // sisa 50,00

        $html = $this->actingAs($this->manager)->get("/projects/{$p->id}")->assertOk()->getContent();
        foreach (['Nilai RAB', 'Nilai Vendor/Subkon', 'Realisasi', 'Sisa'] as $h) {
            $this->assertMatchesRegularExpression('/<th class="th[^"]*">'.preg_quote($h, '/').'<\/th>/', $html, $h);
        }
        $row = fn (string $name) => preg_match('/<tr[^>]*>(?:(?!<\/tr>).)*?'.$name.'(?:(?!<\/tr>).)*<\/tr>/s', $html, $m) ? $m[0] : $this->fail("row $name");
        $cells = fn (string $r) => array_map(fn ($c) => trim(preg_replace('/\s+/', ' ', strip_tags($c))), preg_split('/<\/td>/', substr($r, strpos($r, '</td>') + 5)));

        $this->assertSame('Rp20,00', $cells($row('SisaNego'))[3]);
        $this->assertSame('Rp4,50', $cells($row('SisaActual'))[3]);
        $this->assertSame('Rp90,00', $cells($row('Teralokasi'))[3]);
        $this->assertSame('Rp50,00', $cells($row('Operasional'))[3]);
        $neg = $row('SisaNegatif');
        $this->assertSame('-Rp20,25', $cells($neg)[3]);
        $this->assertStringContainsString('text-rose-700">-Rp20,25', preg_replace('/\s+/', ' ', $neg));
        $this->assertStringContainsString('Overbudget', $neg);
        $this->assertStringNotContainsString('Overbudget', $row('SisaNego'));
        $this->assertStringContainsString('awal Rp70,00', $row('Teralokasi'));
        $this->assertStringNotContainsString('awal Rp', $row('SisaNego'));
        $this->assertStringNotContainsString('Baseline', str_replace('Baseline terkunci', '', substr($html, strpos($html, 'id="item-bq"'), 30000)));
    }

    public function test_sisa_rab_card_is_projected_and_becomes_final_after_closing(): void
    {
        $p = $this->project('1000000.00', 'INCLUSIVE_PPN', '15.0000', ['initial_rab' => '1000000.00', 'effective_rab' => '1000000.00', 'target_margin_amount' => '150000.00', 'margin_base_amount' => '1150000.00']);
        $bq = fn (string $type, string $status, string $eff, string $nego, ?string $actual = null) => DB::table('bq_items')->insert(['project_id' => $p->id, 'item_name' => 'Row'.$status.$type, 'created_by' => $this->staff->id,
            'item_type' => $type, 'status' => $status, 'baseline_budget' => $eff, 'effective_budget' => $eff, 'negotiated_value' => $nego, 'actual_cost' => $actual, 'margin_amount' => '0.00', 'margin_pct' => '0.0000']);
        $bq('NORMAL', 'APPROVED', '190000.00', '200000.10');
        $bq('NORMAL', 'SUBMITTED', '60000.00', '50000.05');
        $bq('OPERATIONAL', 'APPROVED', '20000.20', '0.00');
        $page = $this->actingAs($this->manager)->get("/projects/{$p->id}")->assertOk();
        $this->assertSame('729999.65', $page->viewData('summary')['projected_sisa']); // 1.000.000 - 200.000,10 - 20.000,20 - 50.000,05
        $page->assertSee('Sisa RAB')->assertSee('Pending Biaya BQ')->assertSee('Rp50.000,05')->assertSee('Rp729.999,80')->assertSee('Total Nilai BQ')->assertSee('Rp270.000,20')->assertSee('Vendor/Subkon Rp250.000,15 · Realisasi Rp0,00')
            ->assertDontSee('RAB Belum Dialokasikan')->assertDontSee('Sisa Akhir')->assertDontSee('Official')->assertDontSee('Available');
        $this->assertSame(0, substr_count($page->getContent(), 'Rp779.999,70'));
        $this->assertSame(0, substr_count($page->getContent(), 'Rp729.999,65')); // Sisa RAB kini = belum dialokasikan, bukan proyeksi lama // Official Sisa RAB tidak lagi tampil

        DB::table('projects')->where('id', $p->id)->update(['status' => 'FINANCIALLY_CLOSED', 'actual_profit_amount' => '10000.00']);
        DB::table('bq_items')->where('project_id', $p->id)->update(['actual_cost' => '1000.00']);
        $closed = $this->get("/projects/{$p->id}")->assertOk();
        $closed->assertSee('Sisa Akhir (Tutup Buku)')->assertDontSee('Final Sisa RAB')->assertDontSee('RAB Perubahan dikurangi total Nilai RAB BQ');
    }

    public function test_no_old_label_survives_on_any_screen(): void
    {
        $p = $this->project('1000000.00', 'EXCLUSIVE_PPN', '15.0000');
        $a = DB::table('bq_items')->insertGetId(['project_id' => $p->id, 'item_name' => 'Item A', 'status' => 'APPROVED', 'created_by' => $this->staff->id, 'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '800.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000']);
        $b = DB::table('bq_items')->insertGetId(['project_id' => $p->id, 'item_name' => 'Item B', 'status' => 'APPROVED', 'created_by' => $this->staff->id, 'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '800.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000']);
        DB::table('bq_items')->insert(['project_id' => $p->id, 'item_name' => 'Item C', 'status' => 'DRAFT', 'created_by' => $this->staff->id, 'baseline_budget' => '10.00', 'effective_budget' => '10.00', 'negotiated_value' => '8.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000']);
        DB::table('bq_budget_mutations')->insert(['project_id' => $p->id, 'source_bq_id' => $a, 'destination_bq_id' => $b, 'amount' => '100.00', 'reason' => 'Geser', 'mutation_type' => 'TRANSFER_BETWEEN_BQ', 'status' => 'SUBMITTED', 'submitted_by' => $this->staff->id]);
        DB::table('bq_budget_mutations')->insert(['project_id' => $p->id, 'source_bq_id' => null, 'destination_bq_id' => $b, 'amount' => '10.00', 'reason' => 'Tambah', 'mutation_type' => 'FROM_UNALLOCATED_RAB', 'status' => 'REVISION_REQUIRED', 'submitted_by' => $this->staff->id]);
        DB::table('invoices')->where('project_id', $p->id)->where('sequence_number', 3)->update(['workflow_status' => 'CANCELLED']);
        $this->actingAs($this->staff)->post("/projects/{$p->id}/adjustments", ['adjustment_type' => 'CONTRACT_VALUE', 'margin_mode' => 'PCT', 'margin_value' => '15', 'reason' => 'uji'])->assertSessionHasNoErrors();
        DB::table('audit_logs')->insert(['user_id' => $this->manager->id, 'action' => 'APPROVE', 'entity_type' => 'BQ_MUTATION', 'entity_id' => 1, 'created_at' => now()]);

        $screens = ["/projects/{$p->id}", "/projects/{$p->id}/edit", '/approvals', '/dashboard', '/tax-rates', '/audit-logs', '/invoices', '/projects'];
        foreach ([$this->manager, $this->staff] as $user) {
            foreach ($screens as $url) {
                $res = $this->actingAs($user)->get($url);
                if ($res->status() === 403 || $res->status() === 302) {
                    continue; // layar yang memang bukan untuk role itu
                }
                $res->assertOk();
                $html = preg_replace('/Baseline (belum )?terkunci/', '', $res->getContent());
                $text = html_entity_decode(strip_tags(preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $html)));
                foreach (self::OLD as $old) {
                    $this->assertStringNotContainsString($old, $text, "$old pada $url sebagai {$user->role->value}");
                }
                $this->assertDoesNotMatchRegularExpression('/\bBaseline\b/', $text, "Baseline pada $url");
            }
        }
        $this->actingAs($this->manager)->get("/projects/{$p->id}")->assertSee('Relokasi Budget')->assertSee('Antar item BQ')->assertSee('Dari Sisa RAB')->assertSee('Koreksi Nilai Tertagih')
            ->assertSee('Hanya untuk termin yang tidak jadi ditagih', false);
        $this->get('/approvals')->assertSee('Relokasi Budget')->assertSee('Koreksi Nilai Tertagih');
        $this->get('/audit-logs')->assertSee('Relokasi Budget')->assertSee('<option value="BQ_MUTATION"', false);
    }

    public function test_validation_message_uses_dasar_target_margin(): void
    {
        $p = $this->project('1000000.00', 'INCLUSIVE_PPN', '15.0000');
        $this->actingAs($this->staff)->post("/projects/{$p->id}/adjustments", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'adjustment_type' => 'TARGET_MARGIN', 'margin_mode' => 'AMOUNT', 'margin_value' => '99999999999', 'reason' => 'x'])
            ->assertSessionHasErrors('margin_value', null, 'adjustment-new');
        $message = session('errors')->getBag('adjustment-new')->first('margin_value');
        $this->assertStringContainsString('dasar target margin', $message);
        $this->assertStringNotContainsString('margin base', $message);
    }

    // D-RA-1 (diperbaiki): flash submit BQ memakai istilah "Pending Biaya BQ".
    public function test_bq_submit_flash_uses_pending_biaya_bq(): void
    {
        $p = $this->project('1000000.00', 'INCLUSIVE_PPN', '15.0000');
        $id = DB::table('bq_items')->insertGetId(['project_id' => $p->id, 'item_name' => 'Item A', 'status' => 'DRAFT', 'created_by' => $this->staff->id, 'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '800.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000']);
        $this->actingAs($this->staff)->post("/bq-items/$id/submit")->assertSessionHasNoErrors();
        $this->assertStringContainsString('Pending Biaya BQ', (string) session('status'));
        $this->assertStringNotContainsStringIgnoringCase('pending cost', (string) session('status'));
    }

    public function test_tax_rate_list_has_no_used_column_and_new_text_is_escaped(): void
    {
        $this->actingAs($this->manager)->get('/tax-rates')->assertOk()->assertDontSee('Sudah dipakai')->assertSee('Berlaku sampai');
        $this->assertStringNotContainsString('Ya</span>', $this->get('/tax-rates')->getContent());

        $evil = '<script>alert(1)</script>';
        $p = $this->project('1000000.00', 'INCLUSIVE_PPN', '15.0000');
        $a = DB::table('bq_items')->insertGetId(['project_id' => $p->id, 'item_name' => $evil, 'status' => 'APPROVED', 'created_by' => $this->staff->id, 'baseline_budget' => '100.00', 'effective_budget' => '120.00', 'negotiated_value' => '80.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000']);
        DB::table('bq_budget_mutations')->insert(['project_id' => $p->id, 'source_bq_id' => null, 'destination_bq_id' => $a, 'amount' => '1.00', 'reason' => $evil, 'mutation_type' => 'FROM_UNALLOCATED_RAB', 'status' => 'SUBMITTED', 'submitted_by' => $this->staff->id]);
        DB::table('documents')->insert(['project_id' => $p->id, 'entity_type' => 'PROJECT', 'entity_id' => $p->id, 'document_type' => 'OTHER', 'original_filename' => $evil.'.pdf', 'storage_key' => 'documents/x/x.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 1, 'checksum' => str_repeat('a', 64), 'status' => 'UPLOADED', 'uploaded_by' => $this->manager->id, 'uploaded_at' => now()]);
        $html = $this->get("/projects/{$p->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString($evil, $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }
}
