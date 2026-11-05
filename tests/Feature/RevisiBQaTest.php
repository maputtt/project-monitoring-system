<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Verifikasi independen Revisi B (dashboard drill-down): angka kartu = jumlah daftar (semua halaman), rata-rata tertimbang dengan bcmath tangan,
// lingkup per peran, input liar, XSS, paginasi, keadaan kosong, dan Sisa/overbudget setelah realisasi diisi.
class RevisiBQaTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private User $direksi;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->direksi = User::factory()->role('DIREKSI', null)->create();
        $this->admin = User::factory()->role('SYSTEM_ADMIN', null)->create();
    }

    private function make(string $code, string $status, array $o = []): Project
    {
        $factory = Project::factory();
        if (! empty($o['assigned'])) {
            $factory = $factory->assignedTo($this->staff);
        }
        unset($o['assigned']);

        return $factory->create($o + ['project_code' => $code, 'status' => $status, 'department' => 'BM', 'created_by' => $this->staff->id]);
    }

    private function invoice(Project $p, int $seq, string $status, ?string $subtotal, ?string $net = null, string $plannedDate = '2026-09-01'): void
    {
        $billed = ! in_array($status, ['PLANNED', 'CANCELLED'], true);
        DB::table('invoices')->insert([
            'project_id' => $p->id, 'department' => $p->department->value, 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'planned_amount' => '10.00',
            'workflow_status' => $status, 'created_by' => $this->staff->id, 'subtotal_amount' => $subtotal, 'net_received_amount' => $net,
            'invoice_number' => $billed ? "RB/$p->project_code/$seq" : null, 'billing_date' => $billed ? '2026-09-01' : null, 'planned_billing_date' => $plannedDate,
            'ppn_rate_snapshot' => $billed ? '11.0000' : null, 'pph_rate_snapshot' => $billed ? '2.6500' : null,
            'locked_at' => $status === 'PAID' ? now() : null, 'locked_by' => $status === 'PAID' ? $this->manager->id : null,
        ]);
    }

    private function bq(Project $p, string $name, string $status, bool $over, string $effective, string $negotiated, ?string $actual = null): int
    {
        return DB::table('bq_items')->insertGetId(['project_id' => $p->id, 'item_name' => $name, 'item_type' => 'NORMAL', 'status' => $status, 'is_overbudget' => $over,
            'effective_budget' => $effective, 'negotiated_value' => $negotiated, 'actual_cost' => $actual, 'created_by' => $this->staff->id]);
    }

    /** Semua baris dari semua halaman satu daftar (variabel view `$var`). */
    private function walk(User $user, string $url, string $var): array
    {
        $rows = [];
        $page = 1;
        do {
            $r = $this->actingAs($user)->get($url.(str_contains($url, '?') ? '&' : '?')."page=$page")->assertOk();
            $paginator = $r->viewData($var);
            array_push($rows, ...$paginator->items());
            $page++;
        } while ($page <= $paginator->lastPage());

        return $rows;
    }

    private function sum(array $rows, string $field): string
    {
        return array_reduce($rows, fn ($carry, $row) => bcadd($carry, (string) $row->$field, 2), '0.00');
    }

    /** part / whole x 100, half-up (menjauhi nol), dua desimal, hanya bcmath. */
    private function pct(string $part, string $whole): string
    {
        if (bccomp($whole, '0', 2) === 0) {
            return '0.00';
        }
        $abs = bcadd(bcdiv(bcmul(ltrim($part, '-'), '100', 6), ltrim($whole, '-'), 12), '0.005', 12);
        $out = bcadd($abs, '0', 2);
        $negative = (bccomp($part, '0', 2) < 0) !== (bccomp($whole, '0', 2) < 0);

        return $negative && bccomp($out, '0', 2) !== 0 ? '-'.$out : $out;
    }

    /** Campuran status, nilai kontrak dikoreksi (initial berbeda), tiga divisi data; cukup banyak untuk dua halaman. */
    private function mixedFixture(): void
    {
        for ($i = 1; $i <= 23; $i++) {
            $initial = number_format(1000000 + $i * 123456.78, 2, '.', '');
            $corrected = $i % 5 === 0 ? bcsub($initial, '250000.00', 2) : $initial; // koreksi nilai tertagih: contract_value != initial_contract_value
            $base = bcadd($initial, $i % 2 ? '0.00' : bcmul($initial, '0.11', 2), 2);
            $this->make(sprintf('RB-A%02d', $i), 'ACTIVE', ['assigned' => $i % 2 === 0, 'initial_contract_value' => $initial, 'contract_value' => $corrected,
                'effective_rab' => bcmul((string) $i, '77777.77', 2), 'margin_base_amount' => $base, 'target_margin_amount' => bcmul((string) $i, '3333.33', 2)]);
        }
        $this->make('RB-D1', 'COMPLETED', ['assigned' => true, 'effective_rab' => '500.55', 'margin_base_amount' => '3000.00', 'target_margin_amount' => '450.10']);
        $this->make('RB-D2', 'COMPLETED', ['effective_rab' => '700.45', 'margin_base_amount' => '4000.00', 'target_margin_amount' => '0.00']);
        $this->make('RB-C1', 'FINANCIALLY_CLOSED', ['effective_rab' => '10.00', 'margin_base_amount' => '2000.00', 'target_margin_amount' => '300.00', 'actual_profit_amount' => '-12.34']);
        $this->make('RB-C2', 'FINANCIALLY_CLOSED', ['assigned' => true, 'margin_base_amount' => '1500.00', 'target_margin_amount' => '200.00', 'actual_profit_amount' => '333.33']);
        $this->make('RB-X1', 'ARCHIVED', ['margin_base_amount' => '999.00', 'target_margin_amount' => '10.00', 'actual_profit_amount' => '77.77']);
        $this->make('RB-X2', 'ARCHIVED', ['margin_base_amount' => '999.00', 'target_margin_amount' => '10.00', 'actual_profit_amount' => null]);
        $this->make('RB-P1', 'DRAFT', ['assigned' => true, 'initial_contract_value' => '111.11', 'contract_value' => '111.11']);
        $this->make('RB-P2', 'SUBMITTED', ['initial_contract_value' => '222.22', 'contract_value' => '222.22']);
        $this->make('RB-Z1', 'CANCELLED', ['initial_contract_value' => '999.99', 'contract_value' => '999.99']);
        for ($i = 1; $i <= 3; $i++) {
            $this->make("RB-CP$i", 'ACTIVE', ['department' => 'CP', 'initial_contract_value' => '5000.00', 'contract_value' => '4000.00', 'effective_rab' => '123.45', 'margin_base_amount' => '5000.00', 'target_margin_amount' => '555.55']);
        }
        $this->make('RB-CPC', 'FINANCIALLY_CLOSED', ['department' => 'CP', 'margin_base_amount' => '9000.00', 'target_margin_amount' => '900.00', 'actual_profit_amount' => '999.00']);

        $p = Project::where('project_code', 'RB-A02')->first();
        $q = Project::where('project_code', 'RB-A03')->first();
        $this->bq($p, 'Over mine', 'APPROVED', true, '100.00', '150.00');
        $this->bq($q, 'Over unassigned', 'APPROVED', true, '100.00', '150.00');
        $this->bq(Project::where('project_code', 'RB-CP1')->first(), 'Over CP', 'APPROVED', true, '100.00', '150.00');
        $this->bq(Project::where('project_code', 'RB-C1')->first(), 'Over closed project', 'CLOSED', true, '100.00', '150.00', '120.00');
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function viewers(): array
    {
        return ['manager' => ['manager', null], 'staff' => ['staff', null], 'direksi all' => ['direksi', null], 'direksi BM' => ['direksi', 'BM'], 'direksi CP' => ['direksi', 'CP'], 'admin' => ['admin', null]];
    }

    #[DataProvider('viewers')]
    public function test_every_project_card_equals_the_sum_of_its_list_over_all_pages(string $who, ?string $dept): void
    {
        $this->mixedFixture();
        $user = $this->$who;
        $q = $dept ? "?department=$dept" : '';
        $kpi = $this->actingAs($user)->get('/dashboard'.$q)->assertOk()->viewData('kpi');
        $view = $this->actingAs($user)->get('/dashboard'.$q)->viewData('prospects');
        $allocation = $this->actingAs($user)->get('/dashboard'.$q)->viewData('allocation');

        $activated = $this->walk($user, "/dashboard/projects/activated$q", 'projects');
        $this->assertSame([count($activated), $this->sum($activated, 'initial_contract_value'), $this->sum($activated, 'effective_rab'), $this->sum($activated, 'target_margin_amount')],
            [$kpi['activated'], $kpi['contract'], $kpi['rab'], $kpi['margin']], 'Nilai Project / RAB Perubahan / Target Margin vs daftar activated');

        $this->assertSame($kpi['projects'], count($this->walk($user, "/dashboard/projects/active$q", 'projects')));
        $this->assertSame($kpi['completed'], count($this->walk($user, "/dashboard/projects/completed$q", 'projects')));
        $prospects = $this->walk($user, "/dashboard/projects/prospects$q", 'projects');
        $this->assertSame([$view['count'], $view['total']], [count($prospects), $this->sum($prospects, 'initial_contract_value')]);
        $open = $this->walk($user, "/dashboard/projects/open$q", 'projects');
        $this->assertSame($allocation['rab'], $this->sum($open, 'effective_rab'), 'RAB grafik vs daftar open');

        // Margin Realisasi: kartu, total halaman, dan jumlah baris semua halaman; persen tertimbang dari bcmath tangan.
        $page = $this->actingAs($user)->get('/dashboard/margin'.$q)->assertOk();
        $rows = $this->walk($user, "/dashboard/margin$q", 'projects');
        $totals = $page->viewData('totals');
        $this->assertSame([$kpi['realized_count'], $kpi['realized']], [count($rows), $this->sum($rows, 'actual_profit_amount')]);
        $this->assertSame([(string) count($rows), $kpi['realized'], $this->sum($rows, 'target_margin_amount')], [(string) $totals->n, $totals->actual, $totals->target]);
        $this->assertSame($this->pct($kpi['realized'], $this->sum($rows, 'margin_base_amount')), $kpi['realized_pct']);
        $this->assertSame($this->pct($kpi['margin'], (string) DB::table('projects')->whereIn('id', array_map(fn ($row) => $row->id, $activated))->sum('margin_base_amount')), $kpi['margin_pct']);

        // BQ overbudget: kartu = jumlah baris daftar semua halaman.
        $items = $this->walk($user, "/dashboard/overbudget$q", 'items');
        $this->assertSame($kpi['overbudget'], count($items));
    }

    public function test_scope_differs_per_role_in_the_expected_way(): void
    {
        $this->mixedFixture();
        $kpi = fn (User $u, string $q = '') => $this->actingAs($u)->get('/dashboard'.$q)->viewData('kpi');
        // Manager BM: semua BM teraktivasi (23 aktif, 2 selesai, 2 tutup, 1 arsip berlaba, 1 arsip tanpa laba); ?department=CP diabaikan.
        $this->assertSame(29, $kpi($this->manager)['activated']);
        $this->assertSame(29, $kpi($this->manager, '?department=CP')['activated']);
        // Staff: hanya project yang ditugaskan (11 aktif genap + D1 + C2).
        $this->assertSame([13, 11, 1, 1], [$kpi($this->staff)['activated'], $kpi($this->staff)['projects'], $kpi($this->staff)['completed'], $kpi($this->staff)['realized_count']]);
        // Direksi: semua divisi (29 + 4 CP), dan filter divisi menyempit.
        $this->assertSame(33, $kpi($this->direksi)['activated']);
        $this->assertSame(4, $kpi($this->direksi, '?department=CP')['activated']);
        $this->assertSame(29, $kpi($this->direksi, '?department=BM')['activated']);
    }

    public function test_invoice_cards_equal_their_invoice_lists(): void
    {
        $a = Project::factory()->create(['project_code' => 'RB-IA', 'status' => 'ACTIVE', 'department' => 'BM', 'created_by' => $this->staff->id]);
        $c = Project::factory()->create(['project_code' => 'RB-IC', 'status' => 'COMPLETED', 'department' => 'BM', 'created_by' => $this->staff->id]);
        $f = Project::factory()->create(['project_code' => 'RB-IF', 'status' => 'FINANCIALLY_CLOSED', 'department' => 'BM', 'created_by' => $this->staff->id]);
        $d = Project::factory()->create(['project_code' => 'RB-ID', 'status' => 'DRAFT', 'department' => 'BM', 'created_by' => $this->staff->id]);
        $cp = Project::factory()->create(['project_code' => 'RB-IX', 'status' => 'ACTIVE', 'department' => 'CP', 'created_by' => $this->staff->id]);
        $today = now(config('app.local_timezone'))->toDateString();
        $yesterday = now(config('app.local_timezone'))->subDay()->toDateString();
        $this->invoice($a, 1, 'PAID', '100.10', '97.45');
        $this->invoice($a, 2, 'INVOICE_SUBMITTED', '20.20');
        $this->invoice($a, 3, 'PLANNED', null, null, $yesterday);   // terlambat tagih
        $this->invoice($a, 4, 'PLANNED', null, null, $today);       // hari ini belum terlambat
        $this->invoice($c, 1, 'WAITING_FOR_PAYMENT', '30.30');
        $this->invoice($c, 2, 'PLANNED', null, null, $yesterday);
        $this->invoice($f, 1, 'PAID', '40.04', '39.00');
        $this->invoice($f, 2, 'PLANNED', null, null, $yesterday);   // project tutup buku: bukan terlambat tagih
        $this->invoice($d, 1, 'PLANNED', null, null, $yesterday);   // calon kontrak: bukan terlambat tagih
        $this->invoice($cp, 1, 'PAYMENT_SUBMITTED', '5.05');
        $this->invoice($cp, 2, 'PLANNED', null, null, $yesterday);

        foreach ([[$this->manager, ''], [$this->direksi, ''], [$this->direksi, '&department=BM'], [$this->direksi, '&department=CP']] as [$user, $q]) {
            $kpi = $this->actingAs($user)->get('/dashboard?'.ltrim($q, '&'))->assertOk();
            $late = $kpi->viewData('late_to_bill');
            $kpi = $kpi->viewData('kpi');
            $out = $this->walk($user, '/invoices?outstanding=1'.$q, 'invoices');
            $paid = $this->walk($user, '/invoices?status=PAID'.$q, 'invoices');
            $lateRows = $this->walk($user, '/invoices?late_to_bill=1'.$q, 'invoices');
            $this->assertSame([$kpi['outstanding'], $kpi['nett'], $late], [$this->sum($out, 'subtotal_amount'), $this->sum($paid, 'net_received_amount'), count($lateRows)], "user {$user->role->value}$q");
        }
        $this->assertSame(['50.50', '136.45', 2], (function () {
            $r = $this->actingAs($this->manager)->get('/dashboard');

            return [$r->viewData('kpi')['outstanding'], $r->viewData('kpi')['nett'], $r->viewData('late_to_bill')];
        })()); // 20,20 + 30,30; 97,45 + 39,00; terlambat: a3 dan c2
    }

    public function test_weighted_percentages_match_an_independent_bcmath_calculation(): void
    {
        $a = $this->make('RB-W1', 'ACTIVE');
        $b = $this->make('RB-W2', 'COMPLETED');
        $c = $this->make('RB-W3', 'FINANCIALLY_CLOSED', ['actual_profit_amount' => '1.00']);
        $d = $this->make('RB-W4', 'ARCHIVED', ['actual_profit_amount' => '1.00']);
        mt_srand(20261022);
        $cents = fn (int $max) => number_format(mt_rand(1, $max) / 100, 2, '.', '');
        $cases = [['1.00', '800.00'], ['3.00', '2400.00'], ['1.00', '1600.00'], ['333.33', '1000.00'], ['66.67', '100.00']];
        for ($i = 0; $i < 25; $i++) {
            $cases[] = [$cents(900000000), $cents(9000000000)];
        }
        foreach ($cases as [$target, $base]) {
            $half = bcdiv($base, '2', 2);
            DB::table('projects')->whereIn('id', [$a->id, $b->id])->update(['target_margin_amount' => $target, 'margin_base_amount' => $base]);
            DB::table('projects')->whereIn('id', [$c->id, $d->id])->update(['target_margin_amount' => $target, 'margin_base_amount' => $half, 'actual_profit_amount' => bcsub($target, bcdiv($target, '3', 2), 2)]);
            $kpi = $this->actingAs($this->manager)->get('/dashboard')->viewData('kpi');
            $sumBase = bcadd(bcmul($base, '2', 2), bcmul($half, '2', 2), 2);
            $sumTarget = bcmul($target, '4', 2);
            $this->assertSame($this->pct($sumTarget, $sumBase), $kpi['margin_pct'], "target $target / base $base");
            $profit = bcsub($target, bcdiv($target, '3', 2), 2);
            $this->assertSame($this->pct(bcmul($profit, '2', 2), bcmul($half, '2', 2)), $kpi['realized_pct'], "actual $profit / base $half");
        }
        // Laba negatif dibulatkan menjauhi nol: -1/800 = -0,125% menjadi -0,13%.
        DB::table('projects')->whereIn('id', [$c->id, $d->id])->update(['margin_base_amount' => '400.00', 'actual_profit_amount' => '-0.50']);
        $this->assertSame('-0.13', $this->actingAs($this->manager)->get('/dashboard')->viewData('kpi')['realized_pct']);
        // Dasar margin nol: tanpa pembagian nol, persen 0.
        DB::table('projects')->update(['margin_base_amount' => '0.00', 'target_margin_amount' => '0.00']);
        $this->assertSame('0.00', $this->actingAs($this->manager)->get('/dashboard')->viewData('kpi')['margin_pct']);
        $this->actingAs($this->manager)->get('/dashboard/margin')->assertOk()->assertSee('0%');
    }

    public function test_wild_input_never_gives_a_500_and_unknown_groups_are_404(): void
    {
        $this->mixedFixture();
        foreach (['ACTIVE', 'Active', 'activeX', 'active%00', '0', 'closed', 'cancelled', 'active/', '%2e%2e'] as $group) {
            $status = $this->actingAs($this->manager)->get("/dashboard/projects/$group")->getStatusCode();
            $this->assertContains($status, [200, 404], "group $group -> $status");
        }
        $this->actingAs($this->manager)->get('/dashboard/projects/ACTIVE')->assertNotFound();
        $this->actingAs($this->manager)->get('/dashboard/projects/activeX')->assertNotFound();

        $queries = ['?department=XX', '?department=bm', '?department[]=BM', '?department=', '?department=BM%20', '?page=abc', '?page=-1', '?page=99999999999999999999', '?page[]=1', '?department=CP&page=2'];
        foreach (['/dashboard/projects/active', '/dashboard/projects/open', '/dashboard/overbudget', '/dashboard/margin', '/dashboard', '/invoices'] as $path) {
            foreach ($queries as $query) {
                foreach ([$this->manager, $this->direksi, $this->staff, $this->admin] as $user) {
                    $status = $this->actingAs($user)->get($path.$query)->getStatusCode();
                    $this->assertLessThan(500, $status, "{$user->role->value} $path$query -> $status");
                }
            }
        }
        foreach (['abc', '2', '[]=1'] as $bad) {
            $this->assertLessThan(500, $this->actingAs($this->manager)->get('/invoices?outstanding'.(str_starts_with($bad, '[') ? '' : '=').$bad)->getStatusCode());
        }
        // Peran terkunci divisi mengabaikan ?department; Direksi ditolak untuk nilai tidak dikenal (validasi), bukan 500.
        $this->actingAs($this->manager)->get('/dashboard/projects/active?department=XX')->assertOk();
        $this->actingAs($this->direksi)->get('/dashboard/projects/active?department=XX')->assertSessionHasErrors('department');
    }

    public function test_guest_and_system_admin_boundaries(): void
    {
        $this->mixedFixture();
        foreach (['/dashboard/projects/active', '/dashboard/overbudget', '/dashboard/margin'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->get('/invoices?outstanding=1')->assertRedirect('/login');
        $this->actingAs($this->admin)->get('/invoices?outstanding=1')->assertForbidden();
        $this->actingAs($this->staff)->get('/approvals')->assertForbidden();
        $this->actingAs($this->direksi)->get('/approvals')->assertForbidden();
        // Tidak ada tautan ke halaman terlarang di dashboard peran yang bersangkutan.
        $this->actingAs($this->admin)->get('/dashboard')->assertDontSee('href="'.url('/invoices'), false)->assertDontSee('href="'.url('/approvals'), false);
        $this->actingAs($this->staff)->get('/dashboard')->assertDontSee('href="'.url('/approvals'), false);
    }

    public function test_user_controlled_text_is_escaped_on_every_new_page(): void
    {
        $evil = '<img src=x onerror=alert(1)>';
        $p = $this->make('RB-XSS', 'FINANCIALLY_CLOSED', ['project_name' => $evil, 'margin_base_amount' => '100.00', 'target_margin_amount' => '10.00', 'actual_profit_amount' => '5.00', 'assigned' => true]);
        $this->bq($p, $evil, 'APPROVED', true, '1.00', '2.00');
        $this->make('RB-XS2', 'ACTIVE', ['project_name' => $evil, 'assigned' => true]);
        $this->make('RB-XS3', 'DRAFT', ['project_name' => $evil, 'assigned' => true]);
        foreach (['/dashboard/projects/activated', '/dashboard/projects/active', '/dashboard/projects/prospects', '/dashboard/margin', '/dashboard/overbudget', '/dashboard'] as $url) {
            $this->actingAs($this->manager)->get($url)->assertOk()->assertDontSee($evil, false);
        }
        $this->actingAs($this->manager)->get('/dashboard/overbudget')->assertSee(e($evil), false);
        $this->actingAs($this->staff)->get('/dashboard/margin')->assertSee(e($evil), false);
    }

    public function test_pagination_keeps_the_department_filter_on_every_list(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $closed = $this->make(sprintf('RB-PG%02d', $i), 'FINANCIALLY_CLOSED', ['department' => 'CP', 'margin_base_amount' => '100.00', 'target_margin_amount' => '10.00', 'actual_profit_amount' => '5.00']);
            $active = $this->make(sprintf('RB-PA%02d', $i), 'ACTIVE', ['department' => 'CP']);
            $this->bq($active, "Over $i", 'APPROVED', true, '1.00', '2.00');
            $this->invoice($active, 1, 'INVOICE_SUBMITTED', '1.00');
        }
        $this->make('RB-BMONLY', 'ACTIVE');
        foreach (['/dashboard/projects/active', '/dashboard/margin', '/dashboard/overbudget', '/invoices?outstanding=1'] as $base) {
            $url = $base.(str_contains($base, '?') ? '&' : '?').'department=CP';
            $html = $this->actingAs($this->direksi)->get($url)->assertOk()->getContent();
            preg_match_all('/href="([^"]*[?&;]page=\d+[^"]*)"/', $html, $m);
            $this->assertNotEmpty($m[1], "tidak ada tautan halaman di $base");
            foreach ($m[1] as $href) {
                $this->assertStringContainsString('department=CP', $href, $base);
            }
            $this->assertStringNotContainsString('RB-BMONLY', $this->actingAs($this->direksi)->get($url.'&page=2')->assertOk()->getContent());
        }
        // Halaman 2 memang berisi sisa baris (21 - 20 = 1) dan total margin menghitung semua halaman.
        $this->assertCount(1, $this->actingAs($this->direksi)->get('/dashboard/margin?department=CP&page=2')->viewData('projects')->items());
        $this->assertSame('21', (string) $this->actingAs($this->direksi)->get('/dashboard/margin?department=CP&page=2')->viewData('totals')->n);
    }

    public function test_empty_states_render_on_every_list_and_cards_show_zero(): void
    {
        foreach (['/dashboard/projects/activated' => 'Tidak ada project di daftar ini', '/dashboard/projects/prospects' => 'Tidak ada project di daftar ini', '/dashboard/margin' => 'Belum ada project tutup buku',
            '/dashboard/overbudget' => 'Tidak ada item BQ yang melewati budget'] as $url => $text) {
            foreach ([$this->staff, $this->manager, $this->direksi, $this->admin] as $user) {
                $this->actingAs($user)->get($url)->assertOk()->assertSee($text)->assertDontSee('page=');
            }
        }
        $kpi = $this->actingAs($this->direksi)->get('/dashboard')->assertOk()->assertSee('Belum ada project di jangkauan Anda')->viewData('kpi');
        $this->assertSame(['0.00', '0.00', '0.00', 0, 0], [$kpi['contract'], $kpi['margin_pct'], $kpi['realized'], $kpi['realized_count'], $kpi['overbudget']]);
    }

    public function test_cards_are_real_links_without_nested_interactives_or_tab_traps(): void
    {
        $this->mixedFixture();
        foreach ([$this->manager, $this->direksi, $this->staff] as $user) {
            $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
            $this->assertStringNotContainsString('tabindex="-1"', $html);
            $this->assertSame(0, preg_match('/<a\b[^>]*>(?:(?!<\/a>).)*<a\b/s', $html), 'anchor bersarang');
            $this->assertSame(0, preg_match('/<a\b[^>]*>(?:(?!<\/a>).)*<(button|input|select)\b/s', $html), 'kontrol interaktif di dalam tautan');
        }
    }

    public function test_actual_cost_flips_overbudget_card_and_list_consistently(): void
    {
        $p = $this->make('RB-ACT', 'ACTIVE', ['assigned' => true]);
        $id = $this->bq($p, 'Beton RB', 'APPROVED', true, '100.00', '150.00'); // nego melewati RAB
        $count = fn () => $this->actingAs($this->manager)->get('/dashboard')->viewData('kpi')['overbudget'];
        $list = fn () => $this->actingAs($this->manager)->get('/dashboard/overbudget')->viewData('items')->total();
        $put = function (string $amount) use ($id) {
            $version = DB::table('bq_items')->where('id', $id)->value('version');

            return $this->actingAs($this->staff)->put("/bq-items/$id/actual-cost", ['actual_cost' => $amount, 'version' => $version, 'reason' => 'koreksi']);
        };
        $this->assertSame([1, 1], [$count(), $list()]);
        $put('90.00')->assertSessionHasNoErrors();   // realisasi di bawah RAB: Sisa 10, tidak overbudget
        $this->assertSame([0, 0], [$count(), $list()]);
        $put('100.00');                              // tepat sama: Sisa 0, bukan overbudget
        $this->assertSame([0, 0], [$count(), $list()]);
        $put('100.01');                              // satu sen lebih: overbudget
        $this->assertSame([1, 1], [$count(), $list()]);
        $this->actingAs($this->manager)->get('/dashboard/overbudget')->assertSee('-Rp0,01');
        $this->assertSame(1, (int) DB::table('bq_items')->where('id', $id)->value('is_overbudget'));
    }

    public function test_dashboard_has_no_leftover_project_list_and_uses_new_labels(): void
    {
        $this->mixedFixture();
        $html = $this->actingAs($this->manager)->get('/dashboard')->assertOk()->getContent();
        foreach (['Total Nilai Project', 'RAB Perubahan', 'role="tooltip"', 'Penjelasan Kendala aktif', 'Target Margin', 'Margin Realisasi', 'Tagihan Belum Lunas', 'Uang Diterima (Nett)', 'Project Aktif/Berjalan', 'Project Selesai', 'Calon Kontrak'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringNotContainsString('RB-A02', $html); // daftar project di bawah dashboard sudah dihapus
        $this->assertStringNotContainsString('Nett received', $html);
        $this->assertStringNotContainsString('Invoice outstanding', $html);
    }
}
