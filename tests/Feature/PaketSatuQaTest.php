<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PksStatus;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// QA independen "Paket 1": batas tengah malam WIB vs UTC, matriks status overdue, label Indonesia tanpa teks enum mentah, alur 409 adjustment.
class PaketSatuQaTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
    }

    private function invoice(Project $project, int $seq, string $number, string $status, ?string $due): int
    {
        return DB::table('invoices')->insertGetId([
            'project_id' => $project->id, 'department' => 'BM', 'sequence_number' => $seq, 'billing_type' => 'TERMIN',
            'planned_amount' => '111.00', 'billing_percentage' => '100.0000', 'workflow_status' => $status, 'created_by' => $this->staff->id, 'due_date' => $due,
            'invoice_number' => $number, 'billing_date' => '2026-09-01', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500', 'subtotal_amount' => '111.00', 'net_received_amount' => '108.00',
            'locked_at' => $status === 'PAID' ? now() : null, 'locked_by' => $status === 'PAID' ? $this->manager->id : null,
        ]);
    }

    public function test_overdue_flips_exactly_at_wib_midnight_not_utc_midnight(): void
    {
        $project = Project::factory()->active()->assignedTo($this->staff)->create();
        $this->invoice($project, 1, 'INV/DUE-1016', 'WAITING_FOR_PAYMENT', '2026-10-16');

        // 16:59:59 UTC = 23:59:59 WIB tanggal 16: jatuh tempo hari ini belum lewat.
        $this->travelTo('2026-10-16 16:59:59');
        $this->actingAs($this->manager)->get('/invoices?overdue=1')->assertOk()->assertDontSee('INV/DUE-1016');
        $this->assertSame(1, substr_count($this->get('/invoices')->getContent(), 'Lewat jatuh tempo')); // hanya label filter

        // 17:00:00 UTC = 00:00:00 WIB tanggal 17: lewat.
        $this->travelTo('2026-10-16 17:00:00');
        $this->get('/invoices?overdue=1')->assertOk()->assertSee('INV/DUE-1016');
        $this->assertSame(1 + 2, substr_count($this->get('/invoices')->getContent(), 'Lewat jatuh tempo'));
    }

    public function test_overdue_applies_only_to_billed_unsettled_statuses(): void
    {
        $this->travelTo('2026-10-17 03:00:00');
        $project = Project::factory()->active()->assignedTo($this->staff)->create();
        $overdue = ['INVOICE_SUBMITTED', 'INVOICE_REVISION_REQUIRED', 'INVOICE_VERIFIED', 'WAITING_FOR_PAYMENT', 'PAYMENT_SUBMITTED', 'PAYMENT_REVISION_REQUIRED'];
        foreach (InvoiceStatus::cases() as $i => $status) {
            $this->invoice($project, $i + 1, 'INV/'.$status->value.'/X', $status->value, '2026-10-01');
        }

        $html = $this->actingAs($this->manager)->get('/invoices?overdue=1')->assertOk()->getContent();
        foreach (InvoiceStatus::cases() as $status) {
            in_array($status->value, $overdue, true)
                ? $this->assertStringContainsString('INV/'.$status->value.'/X', $html, $status->value)
                : $this->assertStringNotContainsString('INV/'.$status->value.'/X', $html, $status->value);
        }
        $this->assertSame(1 + 2 * count($overdue), substr_count($this->get('/invoices')->getContent(), 'Lewat jatuh tempo'));
    }

    public function test_billing_date_upper_bound_follows_the_wib_date(): void
    {
        DB::table('tax_rates')->insert([
            ['tax_type' => 'PPN', 'name' => 'PPN 11', 'vendor_qualification' => null, 'rate' => '11.00', 'effective_from' => '2025-01-01', 'created_by' => $this->staff->id],
            ['tax_type' => 'PPH', 'name' => 'PPH B', 'vendor_qualification' => 'B', 'rate' => '2.65', 'effective_from' => '2025-01-01', 'created_by' => $this->staff->id],
        ]);
        $project = Project::factory()->active()->assignedTo($this->staff)->create();
        $id = DB::table('invoices')->insertGetId(['project_id' => $project->id, 'department' => 'BM', 'sequence_number' => 1, 'billing_type' => 'TERMIN',
            'billing_percentage' => '100.0000', 'planned_amount' => '1.00', 'workflow_status' => 'PLANNED', 'created_by' => $this->staff->id]);
        $preview = fn (string $date) => $this->actingAs($this->staff)->getJson("/invoices/$id/tax-preview?billing_date=$date&pph_qualification=B");

        $this->travelTo('2026-10-16 16:59:59'); // WIB masih 16 Okt: batas 16 Okt 2027
        $preview('2027-10-16')->assertOk();
        $preview('2027-10-17')->assertStatus(422)->assertJsonValidationErrors('billing_date');

        $this->travelTo('2026-10-16 17:00:00'); // WIB sudah 17 Okt: batas 17 Okt 2027
        $preview('2027-10-17')->assertOk();
        $preview('2027-10-18')->assertStatus(422)->assertJsonValidationErrors('billing_date');
    }

    public function test_dashboard_greeting_and_date_use_wib(): void
    {
        $this->travelTo('2026-10-16 16:59:59'); // 23:59 WIB, Jumat
        $this->actingAs($this->manager)->get('/dashboard')->assertOk()->assertSee('Selamat malam')->assertSee('Jumat, 16 Oktober 2026');

        $this->travelTo('2026-10-16 17:00:00'); // 00:00 WIB, Sabtu
        $this->get('/dashboard')->assertOk()->assertSee('Selamat pagi')->assertSee('Sabtu, 17 Oktober 2026');
    }

    public function test_no_raw_status_text_in_badges_of_the_project_invoice_and_pks_lists(): void
    {
        foreach (ProjectStatus::cases() as $i => $status) {
            Project::factory()->create(['project_code' => "RAW-$i", 'status' => $status->value]);
        }
        $project = Project::factory()->active()->create();
        foreach (InvoiceStatus::cases() as $i => $status) {
            $this->invoice($project, $i + 1, "INV/RAW/$i", $status->value, '2026-12-01');
        }
        foreach (PksStatus::cases() as $i => $status) {
            DB::table('pks')->insert(['pks_number' => "PKS/RAW/$i", 'name' => 'PKS', 'client_name' => 'Klien', 'issued_date' => '2026-01-01', 'status' => $status->value, 'created_by' => $this->staff->id]);
        }

        $pages = [
            [$this->manager, '/projects', ProjectStatus::cases()], [$this->manager, '/dashboard', ProjectStatus::cases()],
            [$this->manager, '/invoices', InvoiceStatus::cases()], [$this->staff, '/master/pks', PksStatus::cases()],
        ];
        foreach ($pages as [$user, $url, $cases]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            foreach ($cases as $case) {
                $this->assertStringNotContainsString('>'.$case->value.'<', $html, "$url menampilkan teks enum mentah {$case->value}");
                $this->assertStringNotContainsString('>'.trim($case->value).'</option>', $html);
            }
        }
    }

    public function test_adjustment_409_renders_the_error_page_and_the_revision_route_recovers(): void
    {
        $project = Project::factory()->assignedTo($this->staff)->create([
            'created_by' => $this->staff->id, 'status' => 'ACTIVE', 'contract_value' => '199800000.00', 'margin_base_amount' => '199800000.00',
            'target_margin_pct' => '12.0000', 'target_margin_amount' => '23976000.00', 'initial_rab' => '175824000.00', 'effective_rab' => '175824000.00',
        ]);
        $submit = function (string $pct) use ($project) {
            $this->actingAs($this->staff)->post("/projects/{$project->id}/adjustments", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => $pct, 'reason' => 'Nego'])->assertSessionHasNoErrors();

            return (int) DB::table('project_adjustments')->max('id');
        };
        $first = $submit('10');
        $stale = $submit('8');
        $this->actingAs($this->manager)->post("/adjustments/$first/approve")->assertSessionHasNoErrors();

        // Angka project bergeser: usulan kedua ditolak dengan halaman 409 (HTML, bukan 500), tanpa menulis apa pun.
        $audits = DB::table('audit_logs')->count();
        $this->post("/adjustments/$stale/approve")->assertStatus(409)->assertSee('Data sudah berubah');
        $this->assertSame('SUBMITTED', DB::table('project_adjustments')->find($stale)->status);
        $this->assertSame($audits, DB::table('audit_logs')->count());

        // Jalan keluar: minta revisi, pengaju mengajukan ulang (old_data diperbarui), lalu bisa disetujui.
        $this->post("/adjustments/$stale/request-revision", ['reason' => 'Angka berubah'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->put("/adjustments/$stale", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '8', 'reason' => 'Nego ulang'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post("/adjustments/$stale/approve")->assertSessionHasNoErrors();
        $this->assertSame('8.0000', DB::table('projects')->find($project->id)->target_margin_pct);
    }

    public function test_submitter_staff_cannot_approve_even_when_the_data_is_stale(): void
    {
        $project = Project::factory()->assignedTo($this->staff)->create(['status' => 'ACTIVE', 'created_by' => $this->staff->id, 'margin_base_amount' => '1000.00', 'target_margin_pct' => '10.0000', 'target_margin_amount' => '100.00', 'effective_rab' => '900.00', 'initial_rab' => '900.00']);
        $this->actingAs($this->staff)->post("/projects/{$project->id}/adjustments", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '5', 'reason' => 'x'])->assertSessionHasNoErrors();
        $id = (int) DB::table('project_adjustments')->max('id');
        DB::table('projects')->where('id', $project->id)->update(['target_margin_amount' => '99.00']); // bergeser

        $this->post("/adjustments/$id/approve")->assertForbidden(); // kebijakan peran, bukan 409
    }
}
