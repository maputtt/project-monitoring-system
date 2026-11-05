<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Revisi B: rata-rata tertimbang, kartu Project Selesai / Margin Realisasi, lingkup grafik, tautan kartu, dan daftar drill-down.
class DashboardDrillDownTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private User $direksi;

    /** @var array<string, Project> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->direksi = User::factory()->role('DIREKSI', null)->create();

        // BM: aktif (ditugaskan ke staff), selesai, tutup buku, diarsip (pernah tutup / tanpa laba), draf, diajukan. CP: aktif dan tutup buku.
        // Dasar margin dan target dipilih supaya rata-rata tertimbang (17,5%) berbeda dari rata-rata sederhana (15%).
        $this->project('active', 'ACTIVE', '1000.00', '1000.00', '100.00', null, true);
        $this->project('done', 'COMPLETED', '3000.00', '3000.00', '600.00');
        $this->project('closed', 'FINANCIALLY_CLOSED', '1000.00', '1000.00', '100.00', '150.00');
        $this->project('archived', 'ARCHIVED', '3000.00', '3000.00', '600.00', '50.00');
        $this->project('archived_no_profit', 'ARCHIVED', '10.00', '0.00', '0.00');
        $this->project('draft', 'DRAFT', '7.00', '7.00', '1.00');
        $this->project('submitted', 'SUBMITTED', '8.00', '8.00', '1.00');
        $this->project('cp_active', 'ACTIVE', '5000.00', '5000.00', '500.00', null, false, 'CP');
        $this->project('cp_closed', 'FINANCIALLY_CLOSED', '9000.00', '9000.00', '900.00', '999.00', false, 'CP');
    }

    private function project(string $key, string $status, string $contract, string $base, string $target, ?string $actual = null, bool $assigned = false, string $department = 'BM'): void
    {
        $factory = Project::factory();
        $this->p[$key] = ($assigned ? $factory->assignedTo($this->staff) : $factory)->create([
            'project_code' => 'DD-'.strtoupper($key), 'department' => $department, 'status' => $status, 'contract_value' => $contract, 'margin_base_amount' => $base,
            'target_margin_amount' => $target, 'actual_profit_amount' => $actual, 'effective_rab' => '1000.00', 'created_by' => $this->staff->id,
        ]);
    }

    private function invoice(string $key, int $seq, string $status, ?string $subtotal): void
    {
        $billed = ! in_array($status, ['PLANNED', 'CANCELLED'], true);
        DB::table('invoices')->insert([
            'project_id' => $this->p[$key]->id, 'department' => $this->p[$key]->department->value, 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'planned_amount' => '10.00',
            'workflow_status' => $status, 'created_by' => $this->staff->id, 'subtotal_amount' => $subtotal, 'net_received_amount' => $subtotal,
            'invoice_number' => $billed ? "DD/$key/$seq" : null, 'billing_date' => $billed ? '2026-09-01' : null, 'planned_billing_date' => '2026-09-01',
            'ppn_rate_snapshot' => $billed ? '11.0000' : null, 'pph_rate_snapshot' => $billed ? '2.6500' : null,
            'locked_at' => $status === 'PAID' ? now() : null, 'locked_by' => $status === 'PAID' ? $this->manager->id : null,
        ]);
    }

    private function bq(string $key, string $status, bool $overbudget, string $effective = '100.00', string $negotiated = '150.00', ?string $actual = null, string $name = 'Item'): void
    {
        DB::table('bq_items')->insert(['project_id' => $this->p[$key]->id, 'item_name' => $name, 'item_type' => 'NORMAL', 'status' => $status, 'is_overbudget' => $overbudget,
            'effective_budget' => $effective, 'negotiated_value' => $negotiated, 'actual_cost' => $actual, 'created_by' => $this->staff->id]);
    }

    private function codes(User $user, string $url): array
    {
        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
        preg_match_all('/DD-[A-Z_]+/', $html, $m);

        return array_values(array_unique($m[0]));
    }

    public function test_weighted_target_margin_percent_and_project_counts(): void
    {
        $kpi = $this->actingAs($this->manager)->get('/dashboard')->assertOk()->viewData('kpi');
        // Teraktivasi BM: active, done, closed, archived, archived_no_profit. Σ target 1400, Σ dasar 8000 = 17,5% (rata-rata sederhana 15%).
        $this->assertSame(['activated' => 5, 'projects' => 1, 'completed' => 1, 'contract' => '8010.00', 'margin' => '1400.00', 'margin_pct' => '17.50'],
            array_intersect_key($kpi, array_flip(['activated', 'projects', 'completed', 'contract', 'margin', 'margin_pct'])));
        $this->actingAs($this->manager)->get('/dashboard')->assertSee('17,5% dari dasar margin')->assertSee('Project Selesai')->assertSee('belum tutup buku');
    }

    public function test_margin_realisasi_counts_only_closed_projects_with_actual_profit(): void
    {
        $kpi = $this->actingAs($this->manager)->get('/dashboard')->viewData('kpi');
        // closed 150 + archived 50 = 200 atas dasar 1000 + 3000; archived_no_profit (laba kosong) dan CP tidak ikut.
        $this->assertSame([2, '200.00', '5.00'], [$kpi['realized_count'], $kpi['realized'], $kpi['realized_pct']]);
        $cp = $this->actingAs($this->direksi)->get('/dashboard?department=CP')->viewData('kpi');
        $this->assertSame([1, '999.00', '11.10'], [$cp['realized_count'], $cp['realized'], $cp['realized_pct']]);
    }

    public function test_tutup_buku_card_counts_realized_projects_and_links_to_the_margin_list(): void
    {
        $card = fn (string $html) => preg_replace('/\s+/', ' ', strip_tags($html));
        $manager = $this->actingAs($this->manager)->get('/dashboard')->assertOk()->assertSee('href="'.e(route('dashboard.margin')).'"', false);
        $this->assertStringContainsString('Project Tutup Buku 2 laba final terkunci', $card($manager->getContent()));

        $cp = $this->actingAs($this->direksi)->get('/dashboard?department=CP')->assertOk()->assertSee('href="'.e(route('dashboard.margin', ['department' => 'CP'])).'"', false);
        $this->assertStringContainsString('Project Tutup Buku 1 laba final terkunci', $card($cp->getContent()));
    }

    public function test_dashboard_shows_no_raw_status_codes_as_text(): void
    {
        $this->invoice('active', 1, 'PAID', '100.00');
        $text = strip_tags($this->actingAs($this->manager)->get('/dashboard')->assertOk()->assertSee('Dibayar')->getContent());
        $this->assertStringContainsString('Lunas', $text);
        $this->assertStringNotContainsString('PAID', $text); // atribut href (?status=PAID) dibuang strip_tags
    }

    public function test_charts_cover_only_active_and_completed_projects(): void
    {
        $this->invoice('active', 1, 'PAID', '100.00');
        $this->invoice('done', 1, 'INVOICE_SUBMITTED', '50.00');
        $this->invoice('closed', 1, 'PAID', '7000.00');
        $this->bq('active', 'APPROVED', false, '100.00', '100.00');
        $this->bq('closed', 'APPROVED', false, '888.00', '888.00');
        $r = $this->actingAs($this->manager)->get('/dashboard')->assertOk();
        $this->assertSame(['rab' => '2000.00', 'committed' => '100.00', 'pending' => '0.00', 'available' => '1900.00'], array_slice($r->viewData('allocation'), 0, 4));
        $this->assertSame(['paid' => '100.00', 'outstanding' => '50.00', 'planned' => '0.00'], array_slice($r->viewData('pipeline'), 0, 3));
        $r->assertSee(route('dashboard.projects', 'open'), false);
    }

    public function test_every_card_links_to_its_list_and_department_filter_is_carried(): void
    {
        $this->invoice('active', 1, 'INVOICE_SUBMITTED', '50.00');
        $manager = $this->actingAs($this->manager)->get('/dashboard')->assertOk();
        foreach ([route('dashboard.projects', 'activated'), route('dashboard.projects', 'active'), route('dashboard.projects', 'completed'), route('dashboard.projects', 'prospects'), route('dashboard.projects', 'open'),
            route('dashboard.margin'), route('dashboard.overbudget'), route('approvals.index'), route('invoices.index', ['outstanding' => 1]), route('invoices.index', ['status' => 'PAID']), route('invoices.index', ['late_to_bill' => 1])] as $href) {
            $manager->assertSee('href="'.e($href).'"', false);
        }

        $direksi = $this->actingAs($this->direksi)->get('/dashboard?department=CP')->assertOk();
        foreach ([route('dashboard.projects', ['group' => 'completed', 'department' => 'CP']), route('dashboard.margin', ['department' => 'CP']), route('dashboard.overbudget', ['department' => 'CP']),
            route('invoices.index', ['outstanding' => 1, 'department' => 'CP'])] as $href) {
            $direksi->assertSee('href="'.e($href).'"', false);
        }
        $direksi->assertDontSee('href="'.route('approvals.index').'"', false); // antrean hanya untuk Manager/GM: kartu tetap tampil tanpa tautan
        $direksi->assertSee('Approval pending');

        // Staff: antrean tertutup; SYSTEM_ADMIN: /invoices juga tertutup, tetapi daftar dashboard tetap terbuka.
        $this->actingAs($this->staff)->get('/dashboard')->assertDontSee('href="'.route('approvals.index').'"', false)->assertSee('href="'.e(route('invoices.index', ['outstanding' => 1])).'"', false);
        $admin = User::factory()->role('SYSTEM_ADMIN', null)->create();
        $this->actingAs($admin)->get('/dashboard')->assertDontSee('/invoices')->assertDontSee('/approvals')->assertSee('href="'.route('dashboard.margin').'"', false);
    }

    public function test_project_lists_show_the_right_statuses_within_visibility(): void
    {
        $this->assertSame(['DD-ACTIVE'], $this->codes($this->manager, route('dashboard.projects', 'active')));
        $this->assertSame(['DD-DONE'], $this->codes($this->manager, route('dashboard.projects', 'completed')));
        $this->assertEqualsCanonicalizing(['DD-DRAFT', 'DD-SUBMITTED'], $this->codes($this->manager, route('dashboard.projects', 'prospects')));
        $this->assertEqualsCanonicalizing(['DD-ACTIVE', 'DD-DONE'], $this->codes($this->manager, route('dashboard.projects', 'open')));
        $this->assertEqualsCanonicalizing(['DD-ACTIVE', 'DD-DONE', 'DD-CLOSED', 'DD-ARCHIVED', 'DD-ARCHIVED_NO_PROFIT'], $this->codes($this->manager, route('dashboard.projects', 'activated')));

        // Staff hanya melihat project yang ditugaskan; divisi lain tidak pernah muncul; ?department diabaikan untuk peran terkunci divisi.
        $this->assertSame(['DD-ACTIVE'], $this->codes($this->staff, route('dashboard.projects', 'activated').'?department=CP'));
        $this->assertSame([], $this->codes($this->staff, route('dashboard.projects', 'completed')));

        // Direksi: semua divisi, atau satu divisi lewat filter dashboard.
        $this->assertEqualsCanonicalizing(['DD-ACTIVE', 'DD-CP_ACTIVE'], $this->codes($this->direksi, route('dashboard.projects', 'active')));
        $this->assertSame(['DD-CP_ACTIVE'], $this->codes($this->direksi, route('dashboard.projects', 'active').'?department=CP'));
        $this->actingAs($this->direksi)->get(route('dashboard.projects', 'active').'?department=XX')->assertSessionHasErrors('department');
    }

    public function test_list_routes_reject_unknown_group_and_guests_but_open_for_every_role(): void
    {
        $this->get(route('dashboard.margin'))->assertRedirect('/login');
        $this->get(route('dashboard.overbudget'))->assertRedirect('/login');
        $this->actingAs($this->manager)->get('/dashboard/projects/closed')->assertNotFound();
        $admin = User::factory()->role('SYSTEM_ADMIN', null)->create();
        foreach ([$this->staff, $this->manager, $this->direksi, $admin] as $user) {
            foreach ([route('dashboard.projects', 'open'), route('dashboard.margin'), route('dashboard.overbudget')] as $url) {
                $this->actingAs($user)->get($url)->assertOk();
            }
        }
    }

    public function test_overbudget_list_scope_columns_and_link(): void
    {
        $this->bq('active', 'SUBMITTED', true, '100.00', '150.00', null, 'Beton K300');   // sisa 100 - 150 = -50 (Nilai Vendor/Subkon dipakai)
        $this->bq('done', 'APPROVED', true, '100.00', '150.00', '80.00', 'Besi');          // realisasi terisi: sisa 100 - 80 = 20
        $this->bq('active', 'ARCHIVED', true, '1.00', '2.00', null, 'ZZARSIP');              // diarsipkan: tidak ikut
        $this->bq('draft', 'DRAFT', true, '1.00', '2.00', null, 'ZZCALON');                  // project belum aktif: tidak ikut (sama dengan kartu)
        $this->bq('cp_active', 'APPROVED', true, '1.00', '2.00', null, 'ZZLAIN');
        $this->bq('active', 'APPROVED', false, '1.00', '2.00', null, 'ZZAMAN');

        $kpi = $this->actingAs($this->manager)->get('/dashboard')->viewData('kpi');
        $page = $this->actingAs($this->manager)->get(route('dashboard.overbudget'))->assertOk();
        $this->assertSame($kpi['overbudget'], $page->viewData('items')->total());
        $page->assertSee('Beton K300')->assertSee('Besi')->assertSee('-Rp50,00')->assertSee('Rp20,00')->assertSee('Nilai Vendor/Subkon')
            ->assertDontSee('ZZARSIP')->assertDontSee('ZZCALON')->assertDontSee('ZZLAIN')->assertDontSee('ZZAMAN')
            ->assertSee('href="'.route('projects.show', $this->p['active']->id).'#item-bq"', false);

        $this->assertSame(1, $this->actingAs($this->direksi)->get(route('dashboard.overbudget', ['department' => 'CP']))->viewData('items')->total());
        $this->assertSame(1, $this->actingAs($this->staff)->get(route('dashboard.overbudget'))->viewData('items')->total()); // hanya project ditugaskan
    }

    public function test_margin_list_totals_are_weighted_and_scope_is_closed_with_profit(): void
    {
        $page = $this->actingAs($this->manager)->get(route('dashboard.margin'))->assertOk();
        $this->assertEqualsCanonicalizing(['DD-CLOSED', 'DD-ARCHIVED'], $this->codes($this->manager, route('dashboard.margin')));
        $totals = $page->viewData('totals');
        $this->assertSame(['4000.00', '700.00', '200.00', '4000.00'], [$totals->contract, $totals->target, $totals->actual, $totals->base]);
        // Target 700/4000 = 17,5%; realisasi 200/4000 = 5%; baris: closed 150/1000 = 15%, archived 50/3000 = 1,67%.
        $page->assertSee('Rp700,00 · 17,5%', false)->assertSee('Rp200,00 · 5%', false)->assertSee('Rp150,00 · 15%', false)->assertSee('Rp50,00 · 1,67%', false)->assertDontSee('DD-CP_CLOSED');

        $this->assertSame(['DD-CP_CLOSED'], $this->codes($this->direksi, route('dashboard.margin', ['department' => 'CP'])));
        $this->actingAs($this->staff)->get(route('dashboard.margin'))->assertSee('Belum ada project tutup buku'); // staff tidak ditugaskan ke project tutup buku
    }

    public function test_invoice_outstanding_filter_matches_the_dashboard_definition(): void
    {
        foreach ([[1, 'PLANNED', null], [2, 'INVOICE_SUBMITTED', '10.00'], [3, 'WAITING_FOR_PAYMENT', '20.00'], [4, 'PAID', '30.00'], [5, 'CANCELLED', null], [6, 'PAYMENT_SUBMITTED', '40.00']] as [$seq, $status, $subtotal]) {
            $this->invoice('active', $seq, $status, $subtotal);
        }
        $this->invoice('cp_active', 1, 'INVOICE_VERIFIED', '5.00');
        $this->assertSame('70.00', $this->actingAs($this->manager)->get('/dashboard')->viewData('kpi')['outstanding']);
        $list = $this->actingAs($this->manager)->get('/invoices?outstanding=1')->assertOk()->viewData('invoices');
        $this->assertSame(3, $list->total());
        $this->assertEqualsCanonicalizing(['INVOICE_SUBMITTED', 'WAITING_FOR_PAYMENT', 'PAYMENT_SUBMITTED'], collect($list->items())->pluck('workflow_status')->all());
        $this->assertSame(4, $this->actingAs($this->direksi)->get('/invoices?outstanding=1')->viewData('invoices')->total());
        $this->assertSame(1, $this->actingAs($this->direksi)->get('/invoices?outstanding=1&department=CP')->viewData('invoices')->total());
        $this->actingAs($this->manager)->get('/invoices?outstanding=1')->assertSee('name="outstanding" value="1" checked', false);
    }
}
