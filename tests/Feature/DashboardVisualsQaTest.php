<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Verifikasi independen ringkasan visual dashboard (DSH-01 follow-up): lingkup, hitungan tangan, tepi, aksesibilitas, XSS.
class DashboardVisualsQaTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
    }

    private function bq(Project $project, string $status, string $type, string $negotiated, string $effective): void
    {
        DB::table('bq_items')->insert(['project_id' => $project->id, 'item_name' => 'Item', 'item_type' => $type, 'status' => $status, 'negotiated_value' => $negotiated, 'effective_budget' => $effective, 'created_by' => $this->staff->id]);
    }

    private function invoice(Project $project, int $seq, string $status, ?string $subtotal, string $planned = '1.00'): void
    {
        $billed = ! in_array($status, ['PLANNED', 'CANCELLED'], true);
        DB::table('invoices')->insert([
            'project_id' => $project->id, 'department' => $project->department->value, 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'planned_amount' => $planned,
            'workflow_status' => $status, 'created_by' => $this->staff->id, 'subtotal_amount' => $subtotal, 'net_received_amount' => null,
            'invoice_number' => $billed ? "Q/{$project->project_code}/$seq" : null, 'billing_date' => $billed ? '2026-09-01' : null,
            'ppn_rate_snapshot' => $billed ? '11.0000' : null, 'pph_rate_snapshot' => $billed ? '2.6500' : null,
            'locked_at' => $status === 'PAID' ? now() : null, 'locked_by' => $status === 'PAID' ? $this->staff->id : null,
        ]);
    }

    /** BM: mine (assigned) + unassigned; CP: foreign. Angka asing sengaja unik supaya mudah dicari di HTML. */
    private function scopedFixture(): void
    {
        $mine = Project::factory()->active()->assignedTo($this->staff)->create(['project_code' => 'Q-MINE', 'effective_rab' => '1000.00']);
        $this->bq($mine, 'APPROVED', 'NORMAL', '100.00', '0');
        $this->bq($mine, 'SUBMITTED', 'NORMAL', '50.00', '0');
        $this->invoice($mine, 1, 'PAID', '10.00');
        $this->invoice($mine, 2, 'INVOICE_VERIFIED', '20.00');
        $this->invoice($mine, 3, 'PLANNED', null, '5.00');

        $unassigned = Project::factory()->active()->create(['project_code' => 'Q-UNASG', 'effective_rab' => '2000.00']);
        $this->bq($unassigned, 'APPROVED', 'NORMAL', '4444.44', '0');
        $this->bq($unassigned, 'SUBMITTED', 'OPERATIONAL', '0', '3333.33');
        $this->invoice($unassigned, 1, 'PAID', '5555.55');
        $this->invoice($unassigned, 2, 'WAITING_FOR_PAYMENT', '6666.66');
        $this->invoice($unassigned, 3, 'PLANNED', null, '7777.77');

        $cp = Project::factory()->active()->create(['project_code' => 'Q-CP', 'department' => 'CP', 'effective_rab' => '3000.00']);
        $this->bq($cp, 'APPROVED', 'NORMAL', '8888.88', '0');
        $this->bq($cp, 'SUBMITTED', 'OPERATIONAL', '0', '9999.99');
        $this->invoice($cp, 1, 'PAID', '1212.12');
        $this->invoice($cp, 2, 'INVOICE_SUBMITTED', '1313.13');
        $this->invoice($cp, 3, 'PLANNED', null, '1414.14');
    }

    public function test_no_other_division_or_unassigned_amount_leaks_into_bars_or_labels(): void
    {
        $this->scopedFixture();
        $foreign = ['4444', '3333', '5555', '6666', '7777', '8888', '9999', '1212', '1313', '1414', '4.444', '8.888', '9.999', 'Q-CP', 'Q-UNASG'];

        // STAFF_PIC (hanya project assigned), query string diabaikan.
        foreach (['', '?department=CP', '?department=BM'] as $query) {
            $r = $this->actingAs($this->staff)->get('/dashboard'.$query)->assertOk();
            $this->assertSame(['rab' => '1000.00', 'committed' => '100.00', 'pending' => '50.00', 'available' => '850.00', 'committed_pct' => '10.00', 'pending_pct' => '5.00', 'over' => false], $r->viewData('allocation'), $query);
            $this->assertSame(['paid' => '10.00', 'outstanding' => '20.00', 'planned' => '5.00', 'paid_pct' => '28.5714', 'outstanding_pct' => '57.1429', 'planned_pct' => '14.2857'], $r->viewData('pipeline'), $query);
            $html = $r->getContent();
            foreach ($foreign as $needle) {
                $this->assertStringNotContainsString($needle, $html, "staff {$query} melihat {$needle}");
            }
        }

        // MANAGER_GM BM: ikut project unassigned BM, tetapi tidak CP.
        $m = $this->actingAs($this->manager)->get('/dashboard?department=CP')->assertOk();
        // RAB 3000; committed 100 + 4444.44 = 4544.44; pending 50 + 3333.33 = 3383.33; available -4927.77
        $this->assertSame(['rab' => '3000.00', 'committed' => '4544.44', 'pending' => '3383.33', 'available' => '-4927.77'], array_slice($m->viewData('allocation'), 0, 4));
        $this->assertTrue($m->viewData('allocation')['over']);
        foreach (['8888', '9999', '1212', '1313', '1414', 'Q-CP'] as $needle) {
            $this->assertStringNotContainsString($needle, $m->getContent(), "manager melihat {$needle}");
        }
        $m->assertSee('-Rp4.927,77', false);

        // DIREKSI/SYSTEM_ADMIN: ?department memfilter satu divisi; angka CP berdiri sendiri.
        foreach (['DIREKSI', 'SYSTEM_ADMIN'] as $role) {
            $u = User::factory()->role($role, null)->create();
            $cp = $this->actingAs($u)->get('/dashboard?department=CP')->assertOk();
            $this->assertSame(['rab' => '3000.00', 'committed' => '8888.88', 'pending' => '9999.99', 'available' => '-15888.87'], array_slice($cp->viewData('allocation'), 0, 4));
            $this->assertSame(['paid' => '1212.12', 'outstanding' => '1313.13', 'planned' => '1414.14'], array_slice($cp->viewData('pipeline'), 0, 3));
            $this->assertStringNotContainsString('Q-MINE', $cp->getContent());
            $this->assertStringNotContainsString('Rp4.444,44', $cp->getContent());
            $all = $this->actingAs($u)->get('/dashboard')->viewData('allocation');
            $this->assertSame(['rab' => '6000.00', 'committed' => '13433.32', 'pending' => '13383.32'], array_slice($all, 0, 3));
        }
    }

    public function test_empty_scope_zero_rab_and_all_cancelled_invoices_do_not_error(): void
    {
        // Staff tanpa assignment: nol project.
        $r = $this->actingAs($this->staff)->get('/dashboard')->assertOk()->assertSee('Belum ada project di jangkauan Anda.');
        $this->assertSame(['rab' => '0.00', 'committed' => '0.00', 'pending' => '0.00', 'available' => '0.00', 'committed_pct' => '0.00', 'pending_pct' => '0.00', 'over' => false], $r->viewData('allocation'));
        $this->assertSame(['paid' => '0.00', 'outstanding' => '0.00', 'planned' => '0.00', 'paid_pct' => '0.0000', 'outstanding_pct' => '0.0000', 'planned_pct' => '0.0000'], $r->viewData('pipeline'));
        $r->assertDontSee('NaN')->assertDontSee('width: %', false);

        // Semua invoice CANCELLED: total tagihan nol.
        $p = Project::factory()->active()->create(['project_code' => 'Q-ZERO', 'effective_rab' => '0.00']);
        $this->invoice($p, 1, 'CANCELLED', '500.00');
        $this->invoice($p, 2, 'CANCELLED', null, '300.00');
        $r = $this->actingAs($this->manager)->get('/dashboard')->assertOk();
        $this->assertSame('0.0000', $r->viewData('pipeline')['paid_pct']);
        $this->assertSame('0.00', $r->viewData('pipeline')['planned']);
        $this->assertFalse($r->viewData('allocation')['over']);

        // RAB nol dengan biaya APPROVED: over, batang penuh, tanpa pembagian nol.
        $this->bq($p, 'APPROVED', 'NORMAL', '10.00', '0');
        $r = $this->actingAs($this->manager)->get('/dashboard')->assertOk()->assertSee('Melebihi RAB');
        $this->assertSame(['committed_pct' => '100.00', 'pending_pct' => '0.00', 'over' => true], array_slice($r->viewData('allocation'), 4));
        $this->assertSame('-10.00', $r->viewData('allocation')['available']);
    }

    public function test_rab_zero_with_only_pending_cost_is_not_labelled_as_used(): void
    {
        // Hanya SUBMITTED (belum resmi) pada RAB nol: batang "terpakai" tidak boleh 100% karena committed = 0.
        $p = Project::factory()->active()->create(['project_code' => 'Q-PEND0', 'effective_rab' => '0.00']);
        $this->bq($p, 'SUBMITTED', 'NORMAL', '10.00', '0');
        $a = $this->actingAs($this->manager)->get('/dashboard')->assertOk()->viewData('allocation');
        $this->assertTrue($a['over']);
        $this->assertSame('0.00', $a['committed_pct']);
    }

    public function test_percentages_clip_so_bar_never_exceeds_100_and_round_half_up(): void
    {
        // 123450 / 1000000 = 12.345% -> 12.35 (half-up); 876550 / 1000000 = 87.655% -> 87.66; jumlah 100.01 dipotong: pending 87.65.
        $p = Project::factory()->active()->create(['project_code' => 'Q-CLIP', 'effective_rab' => '1000000.00']);
        $this->bq($p, 'APPROVED', 'NORMAL', '123450.00', '0');
        $this->bq($p, 'SUBMITTED', 'NORMAL', '876550.00', '0');
        $a = $this->actingAs($this->manager)->get('/dashboard')->viewData('allocation');
        $this->assertSame(['committed_pct' => '12.35', 'pending_pct' => '87.65', 'over' => false], array_slice($a, 4));
        $this->assertSame('0.00', $a['available']);

        // OPERATIONAL pakai effective_budget, NORMAL pakai negotiated_value, REVISION_REQUIRED/DRAFT/ARCHIVED tidak masuk.
        $this->bq($p, 'REVISION_REQUIRED', 'NORMAL', '1.00', '1.00');
        $this->bq($p, 'APPROVED', 'OPERATIONAL', '999.00', '0.01');
        $a = $this->actingAs($this->manager)->get('/dashboard')->viewData('allocation');
        $this->assertSame('123450.01', $a['committed']);
        $this->assertSame('-0.01', $a['available']);
        $this->assertTrue($a['over']);
    }

    public function test_rounding_of_percent_is_not_double_rounded(): void
    {
        // 123449.96 / 1000000 = 12.344996% -> benar 12.34; ratioPct (4 desimal) lalu round(2) memberi 12.35.
        $p = Project::factory()->active()->create(['project_code' => 'Q-DBL', 'effective_rab' => '1000000.00']);
        $this->bq($p, 'APPROVED', 'NORMAL', '123449.96', '0');
        $a = $this->actingAs($this->manager)->get('/dashboard')->viewData('allocation');
        $this->assertSame('12.34', $a['committed_pct']);
    }

    public function test_every_bar_has_an_aria_label_matching_the_legend_numbers(): void
    {
        $this->scopedFixture();
        $html = $this->actingAs($this->manager)->get('/dashboard')->assertOk()->getContent();
        preg_match_all('/<div role="img" aria-label="([^"]*)"/', $html, $labels);
        $this->assertCount(2, $labels[1], 'dua batang ringkasan; daftar project sudah pindah ke halaman drill-down');
        // Catatan D4 (Info): committed 4544,44 dari RAB 3000 = 151,48%, tetapi label dan legenda menampilkan 100% (nilai batang yang dipotong); rupiah dan badge "Melebihi RAB" tetap benar.
        $this->assertSame('Alokasi RAB: terpakai Rp4.544,44 (100%), pending Rp3.383,33 (0%), sisa tersedia -Rp4.927,77', html_entity_decode($labels[1][0]));
        // Angka di aria-label ada juga sebagai teks terlihat (legenda).
        foreach (['Rp4.544,44', 'Rp3.383,33', '-Rp4.927,77'] as $money) {
            $this->assertGreaterThanOrEqual(2, substr_count($html, $money), $money);
        }
        $this->assertStringContainsString('Alur tagihan: dibayar Rp5.565,55', html_entity_decode($labels[1][1]));
    }

    public function test_project_names_stay_out_of_aria_labels_and_are_escaped(): void
    {
        Project::factory()->active()->create(['project_code' => 'Q-XSS', 'project_name' => '"><img src=x onerror=alert(1)>', 'effective_rab' => '10.00']);
        foreach ([$this->manager, User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', null)->create()] as $u) {
            $html = $this->actingAs($u)->get('/dashboard')->assertOk()->getContent();
            $list = $this->actingAs($u)->get(route('dashboard.projects', 'active'))->assertOk()->getContent();
            $this->assertStringNotContainsString('<img src=x', $html.$list);
            $this->assertStringContainsString('&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $list);
            preg_match_all('/aria-label="([^"]*)"/', $html, $m);
            foreach ($m[1] as $label) {
                $this->assertStringNotContainsString('img src', $label);
            }
        }
    }

    public function test_no_500_for_any_role_and_department_value_with_money_in_every_corner(): void
    {
        $this->scopedFixture();
        $zero = Project::factory()->active()->create(['project_code' => 'Q-Z', 'effective_rab' => '0.00']);
        $this->bq($zero, 'SUBMITTED', 'OPERATIONAL', '0', '5.00');
        $users = ['STAFF_PIC' => $this->staff, 'MANAGER_GM' => $this->manager, 'DIREKSI' => User::factory()->role('DIREKSI', null)->create(), 'SYSTEM_ADMIN' => User::factory()->role('SYSTEM_ADMIN', null)->create()];
        foreach ($users as $role => $u) {
            foreach (['', '?department=', '?department=BM', '?department=CP', '?department=ITBS', '?department=XX', '?department[]=BM', '?page=999'] as $q) {
                $status = $this->actingAs($u)->get('/dashboard'.$q)->getStatusCode();
                $invalid = in_array($q, ['?department=XX', '?department[]=BM'], true) && in_array($role, ['DIREKSI', 'SYSTEM_ADMIN'], true);
                $this->assertSame($invalid ? 302 : 200, $status, "$role $q");
            }
        }
    }

    public function test_manager_callout_count_equals_inbox_total_and_empty_state_is_shown(): void
    {
        $this->actingAs($this->manager)->get('/dashboard')->assertSee('Tidak ada yang menunggu');
        $p = Project::factory()->active()->create(['project_code' => 'Q-INB', 'status' => 'SUBMITTED']);
        $this->bq($p, 'SUBMITTED', 'NORMAL', '1.00', '0');
        $this->invoice($p, 1, 'INVOICE_SUBMITTED', '2.00');
        $this->invoice($p, 2, 'PAYMENT_SUBMITTED', '3.00');
        DB::table('project_adjustments')->insert(['project_id' => $p->id, 'adjustment_type' => 'RAB', 'status' => 'SUBMITTED', 'reason' => 'x', 'submitted_by' => $this->staff->id, 'old_data' => '{}', 'new_data' => '{}']);
        $kpi = $this->actingAs($this->manager)->get('/dashboard')->assertSee('item menunggu keputusan')->viewData('kpi');
        $counts = $this->actingAs($this->manager)->get(route('approvals.index'))->viewData('counts');
        $this->assertSame($kpi['pending'], (int) collect($counts)->sum(), 'callout dashboard harus sama dengan total antrean /approvals');
    }

    public function test_available_equals_project_detail_projected_remaining(): void
    {
        $p = Project::factory()->active()->create(['project_code' => 'Q-PAR', 'status' => 'ACTIVE', 'effective_rab' => '500.00']);
        $this->bq($p, 'APPROVED', 'NORMAL', '120.55', '0');
        $this->bq($p, 'CLOSED', 'NORMAL', '10.10', '0');
        $this->bq($p, 'APPROVED', 'OPERATIONAL', '0', '40.00');
        $this->bq($p, 'SUBMITTED', 'NORMAL', '25.25', '0');
        $available = $this->actingAs($this->manager)->get('/dashboard')->viewData('allocation')['available'];
        $this->assertSame('304.10', $available); // 500 - 170.65 - 25.25
        $detail = $this->actingAs($this->manager)->get('/projects/'.$p->id)->assertOk()->viewData('summary')['projected_sisa']; // kartu Sisa RAB kini = belum dialokasikan; sisa proyeksi hanya di summary
        $this->assertSame($available, $detail);
    }
}
