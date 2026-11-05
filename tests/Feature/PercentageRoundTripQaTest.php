<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// QA: nilai isian form (titik desimal) yang dirender server untuk usulan Koreksi Persentase Termin dan Koreksi Nilai Tertagih harus bisa dikirim ulang tanpa error validasi,
// sementara teks tampilan memakai koma. Nilai isian diambil dari HTML hasil render, bukan dibangun ulang di test.
class PercentageRoundTripQaTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Project $project;

    /** @var array<int, int> */
    private array $termins = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
    }

    private function project(string $marginPct, array $percentages): void
    {
        $this->project = Project::factory()->assignedTo($this->staff)->create([
            'created_by' => $this->staff->id, 'status' => 'ACTIVE', 'contract_value' => '1110000000.00', 'contract_tax_mode' => 'INCLUSIVE_PPN',
            'margin_base_amount' => '1110000000.00', 'target_margin_pct' => $marginPct, 'target_margin_amount' => '166500000.00', 'initial_rab' => '943500000.00', 'effective_rab' => '943500000.00',
        ]);
        foreach ($percentages as $i => $pct) {
            $this->termins[$i + 1] = DB::table('invoices')->insertGetId([
                'project_id' => $this->project->id, 'department' => 'BM', 'sequence_number' => $i + 1, 'billing_type' => 'TERMIN', 'billing_percentage' => $pct,
                'planned_amount' => '1.00', 'workflow_status' => 'PLANNED', 'created_by' => $this->staff->id,
            ]);
        }
    }

    /** Payload x-data (@js) milik popup <x-form-modal> bernama $modal, sebagai array. */
    private function payload(string $html, string $modal, string $component): array
    {
        $this->assertSame(1, preg_match('/name="_modal" value="'.preg_quote($modal, '/').'".*?<div class="contents"\s+x-data="'.$component.'\((.*?)\)"/s', $html, $m), "popup $modal tidak ditemukan");

        $json = str_replace(chr(92).'u0022', '"', html_entity_decode($m[1], ENT_QUOTES));
        $json = preg_replace('/^JSON\.parse\(\'(.*)\'\)$/s', '$1', $json); // @js memakai JSON.parse('...') untuk objek

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function billingCases(): array
    {
        return [
            'tiga desimal berulang' => [['30.0000', '33.3333', '36.6667'], ['30', '33.3333', '36.6667']],
            'setengah' => [['30.0000', '33.5000', '36.5000'], ['30', '33.5', '36.5']],
            'dua desimal' => [['10.5000', '45.2500', '44.2500'], ['10.5', '45.25', '44.25']],
            'bulat' => [['30.0000', '30.0000', '40.0000'], ['30', '30', '40']],
            'seratus dan kecil' => [['0.0001', '99.9999'], ['0.0001', '99.9999']],
        ];
    }

    /** @dataProvider billingCases */
    #[DataProvider('billingCases')]
    public function test_billing_resubmit_form_prefill_round_trips_without_validation_error(array $stored, array $expectedInputs): void
    {
        $this->project('15.0000', array_fill(0, count($stored), '0'));
        $old = $new = [];
        foreach ($stored as $i => $pct) {
            $seq = $i + 1;
            $old[] = ['invoice_id' => $this->termins[$seq], 'sequence_number' => $seq, 'billing_percentage' => number_format(100 / count($stored), 4, '.', ''), 'planned_amount' => '1.00'];
            $new[] = ['invoice_id' => $this->termins[$seq], 'sequence_number' => $seq, 'billing_percentage' => $pct, 'planned_amount' => '1.00'];
        }
        // Persentase saat ini di invoices = yang lama (total harus tetap).
        foreach ($old as $o) {
            DB::table('invoices')->where('id', $o['invoice_id'])->update(['billing_percentage' => $o['billing_percentage']]);
        }
        // Total lama harus 100 agar total usulan tetap: sesuaikan termin terakhir.
        $sumOld = array_sum(array_map(fn ($o) => (float) $o['billing_percentage'], array_slice($old, 0, -1)));
        $lastOld = number_format(100 - $sumOld, 4, '.', '');
        $old[count($old) - 1]['billing_percentage'] = $lastOld;
        DB::table('invoices')->where('id', $old[count($old) - 1]['invoice_id'])->update(['billing_percentage' => $lastOld]);
        $id = DB::table('project_adjustments')->insertGetId([
            'project_id' => $this->project->id, 'adjustment_type' => 'BILLING_PERCENTAGE', 'old_data' => json_encode(['termins' => $old]), 'new_data' => json_encode(['termins' => $new]),
            'reason' => 'Kesepakatan ulang', 'status' => 'REVISION_REQUIRED', 'submitted_by' => $this->staff->id, 'revision_reason' => 'Cek lagi',
        ]);

        $html = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->getContent();
        $vals = $this->payload($html, "adjust-$id", 'billingPreview')['vals'];

        $this->assertSame($expectedInputs, array_values($vals), 'nilai isian harus titik desimal tanpa nol di belakang');
        $this->assertStringNotContainsString(',', implode('', $vals));

        $this->actingAs($this->staff)->put("/adjustments/$id", ['billing_percentages' => $vals, 'reason' => 'Kirim ulang tanpa ubah isian'])
            ->assertSessionHasNoErrors()->assertRedirect("/projects/{$this->project->id}");
        $row = DB::table('project_adjustments')->where('id', $id)->first();
        $this->assertSame('SUBMITTED', $row->status);
        $saved = array_column(json_decode($row->new_data, true)['termins'], 'billing_percentage');
        $this->assertSame(array_map(fn ($v) => number_format((float) $v, 4, '.', ''), $stored), $saved);
    }

    public static function marginCases(): array
    {
        return [['16.6667', '16.6667'], ['15.0000', '15'], ['0.0000', '0'], ['100.0000', '100'], ['12.5000', '12.5'], ['0.0063', '0.0063']];
    }

    #[DataProvider('marginCases')]
    public function test_contract_correction_prefill_margin_value_is_a_dot_decimal_the_server_accepts(string $stored, string $expected): void
    {
        $this->project($stored, ['30.0000', '30.0000', '40.0000']);
        DB::table('invoices')->where('id', $this->termins[3])->update(['workflow_status' => 'CANCELLED']);

        $html = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->getContent();
        $payload = $this->payload($html, 'adjust-contract', 'adjustPreview');
        $this->assertSame($expected, $payload['margin_value']);
        $this->assertStringNotContainsString(',', $payload['margin_value']);

        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/adjustments", [
            'adjustment_type' => 'CONTRACT_VALUE', 'margin_mode' => $payload['margin_mode'], 'margin_value' => $payload['margin_value'], 'reason' => 'Termin 3 dibatalkan klien',
        ])->assertSessionHasNoErrors()->assertRedirect("/projects/{$this->project->id}");
        $this->assertSame(1, DB::table('project_adjustments')->where('project_id', $this->project->id)->where('adjustment_type', 'CONTRACT_VALUE')->count());
    }

    public function test_display_text_uses_comma_everywhere_on_the_project_page(): void
    {
        $this->project('16.6667', ['30.0000', '33.5000', '36.5000']);
        DB::table('invoices')->where('id', $this->termins[1])->update(['ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500']);
        $text = strip_tags($this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->getContent());
        $this->assertStringContainsString('Target Margin Perubahan (16,6667%)', $text);
        $this->assertStringContainsString('33,5%', $text);
        $this->assertSame(0, preg_match('/\d+\.\d+\s?%/', $text), 'angka persen berformat titik terlihat di teks');
        $this->assertSame(0, preg_match('/\d+,\d*0%/', $text), 'nol di belakang tidak dipangkas');
    }
}
