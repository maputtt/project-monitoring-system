<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// DSH-01 QA independen: KPI hand-calc (semua status, jebakan float), kebocoran lintas divisi/assignment, query string abuse, read-only, XSS, paginasi.
class DashboardQaTest extends TestCase
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

    private function invoice(Project $project, int $seq, string $status, ?string $subtotal, ?string $net): void
    {
        $billed = ! in_array($status, ['PLANNED', 'CANCELLED'], true);
        DB::table('invoices')->insert([
            'project_id' => $project->id, 'department' => $project->department->value, 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'planned_amount' => '1.00',
            'workflow_status' => $status, 'created_by' => $this->staff->id, 'subtotal_amount' => $subtotal, 'net_received_amount' => $net,
            'invoice_number' => $billed ? "QA/{$project->project_code}/$seq" : null, 'billing_date' => $billed ? '2026-09-01' : null,
            'ppn_rate_snapshot' => $billed ? '11.0000' : null, 'pph_rate_snapshot' => $billed ? '2.6500' : null,
            'locked_at' => $status === 'PAID' ? now() : null, 'locked_by' => $status === 'PAID' ? $this->manager->id : null,
        ]);
    }

    private function bq(Project $project, string $status, bool $overbudget): int
    {
        return DB::table('bq_items')->insertGetId(['project_id' => $project->id, 'item_name' => 'Item', 'item_type' => 'NORMAL', 'status' => $status, 'is_overbudget' => $overbudget, 'created_by' => $this->staff->id]);
    }

    private function kpi(User $user, string $query = ''): array
    {
        return $this->actingAs($user)->get('/dashboard'.$query)->assertOk()->viewData('kpi');
    }

    public function test_kpis_match_hand_calculation_for_every_status_and_float_traps(): void
    {
        // Jebakan float: 0.10+0.20 dan nilai 14 digit. Aturan 6A: hanya ACTIVE/COMPLETED/FINANCIALLY_CLOSED/ARCHIVED yang masuk angka uang; DRAFT/SUBMITTED/CANCELLED tidak.
        $p1 = Project::factory()->active()->create(['project_code' => 'QA-1', 'status' => 'ACTIVE', 'contract_value' => '0.10', 'effective_rab' => '0.07', 'target_margin_amount' => '0.01']);
        $p2 = Project::factory()->create(['project_code' => 'QA-2', 'status' => 'SUBMITTED', 'contract_value' => '0.20', 'effective_rab' => '0.07', 'target_margin_amount' => '0.02']);
        Project::factory()->create(['project_code' => 'QA-3', 'status' => 'DRAFT', 'contract_value' => '9999999999999.99', 'effective_rab' => '0.01', 'target_margin_amount' => '0.03']);
        Project::factory()->create(['project_code' => 'QA-5', 'status' => 'COMPLETED', 'contract_value' => '10000000000000.20', 'effective_rab' => '0.08', 'target_margin_amount' => '0.05']);
        Project::factory()->create(['project_code' => 'QA-4', 'status' => 'CANCELLED', 'contract_value' => '0.01', 'effective_rab' => '0.00', 'target_margin_amount' => '0.00']);

        // Outstanding = semua status selain PAID/CANCELLED dan selain PLANNED (tanpa subtotal): 1.01+2.02+4.04+8.08+16.16+32.32 = 63.63
        foreach ([['PLANNED', null, '0.99'], ['INVOICE_SUBMITTED', '1.01', '0.99'], ['INVOICE_REVISION_REQUIRED', '2.02', '0.99'], ['INVOICE_VERIFIED', '4.04', '0.99'],
            ['WAITING_FOR_PAYMENT', '8.08', '0.99'], ['PAYMENT_SUBMITTED', '16.16', '0.99'], ['PAYMENT_REVISION_REQUIRED', '32.32', '0.99'],
            ['PAID', '64.64', '60.01'], ['CANCELLED', '128.28', '0.99']] as $i => [$status, $subtotal, $net]) {
            $this->invoice($p1, $i + 1, $status, $subtotal, $net);
        }
        $this->invoice($p2, 1, 'PAID', '0.10', '0.20'); // project SUBMITTED: tidak masuk nett

        // BQ: enam status, semuanya overbudget; ARCHIVED tidak dihitung -> 5. Satu non-overbudget SUBMITTED hanya menambah pending.
        $mutationBq = 0;
        foreach (['DRAFT', 'SUBMITTED', 'REVISION_REQUIRED', 'APPROVED', 'CLOSED', 'ARCHIVED'] as $status) {
            $mutationBq = $this->bq($p1, $status, true);
        }
        $this->bq($p1, 'SUBMITTED', false);
        // Mutasi: SUBMITTED dihitung, APPROVED/REVISION_REQUIRED/REVERSED/DRAFT tidak.
        foreach (['SUBMITTED', 'APPROVED', 'REVISION_REQUIRED', 'REVERSED', 'DRAFT'] as $status) {
            DB::table('bq_budget_mutations')->insert(['project_id' => $p1->id, 'destination_bq_id' => $mutationBq, 'amount' => '1.00', 'reason' => 'x', 'mutation_type' => 'FROM_UNALLOCATED_RAB', 'status' => $status, 'submitted_by' => $this->staff->id]);
        }
        foreach (['SUBMITTED', 'SUBMITTED', 'APPROVED', 'REVISION_REQUIRED', 'REVERSED', 'DRAFT'] as $status) {
            DB::table('project_adjustments')->insert(['project_id' => $p1->id, 'adjustment_type' => 'RAB', 'status' => $status, 'reason' => 'x', 'submitted_by' => $this->staff->id, 'old_data' => '{}', 'new_data' => '{}']);
        }

        // pending = project SUBMITTED (1) + bq SUBMITTED (2) + invoice INVOICE_SUBMITTED/PAYMENT_SUBMITTED (2) + adjustment (2) + mutasi (1) = 8
        $expected = ['activated' => 2, 'projects' => 1, 'completed' => 1, 'contract' => '10000000000000.30', 'rab' => '0.15', 'margin' => '0.06', 'margin_pct' => '0.00', 'outstanding' => '63.63', 'nett' => '60.01', 'realized_count' => 0, 'realized' => '0.00', 'realized_pct' => '0.00', 'overbudget' => 5, 'pending' => 8];
        $this->assertSame($expected, $this->kpi($this->manager));
        $this->assertSame($expected, $this->kpi(User::factory()->role('DIREKSI', null)->create()));
        $this->actingAs($this->manager)->get('/dashboard')->assertSee('Rp10.000.000.000.000,30', false)->assertSee('Rp63,63', false)->assertSee('Rp60,01', false);
    }

    public function test_staff_never_sees_unassigned_deactivated_or_foreign_division_numbers(): void
    {
        $mine = Project::factory()->active()->assignedTo($this->staff)->create(['project_code' => 'QA-MINE', 'contract_value' => '100.00', 'effective_rab' => '10.00', 'target_margin_amount' => '1.00']);
        $deactivated = Project::factory()->active()->assignedTo($this->staff, false)->create(['project_code' => 'QA-DEACT', 'project_name' => 'ZZDEACTNAME', 'contract_value' => '2000.00', 'effective_rab' => '20.00', 'target_margin_amount' => '2.00']);
        $unassigned = Project::factory()->active()->create(['project_code' => 'QA-UNASS', 'project_name' => 'ZZUNASSNAME', 'contract_value' => '30000.00', 'effective_rab' => '30.00', 'target_margin_amount' => '3.00']);
        $cp = Project::factory()->active()->create(['project_code' => 'QA-CP', 'department' => 'CP', 'project_name' => 'ZZCPNAME', 'contract_value' => '400000.00', 'effective_rab' => '40.00', 'target_margin_amount' => '4.00']);
        $cpAssigned = Project::factory()->active()->assignedTo($this->staff)->create(['project_code' => 'QA-CPASG', 'department' => 'CP', 'project_name' => 'ZZCPASGNAME', 'contract_value' => '5000000.00', 'effective_rab' => '50.00', 'target_margin_amount' => '5.00']);
        foreach ([$mine, $deactivated, $unassigned, $cp, $cpAssigned] as $n => $project) {
            $this->invoice($project, 1, 'PAID', '10.00', (string) (10 ** $n).'.00');
            $this->invoice($project, 2, 'INVOICE_SUBMITTED', (string) (10 ** $n).'00.00', null);
            $this->bq($project, 'SUBMITTED', true);
        }

        $expected = ['activated' => 1, 'projects' => 1, 'completed' => 0, 'contract' => '100.00', 'rab' => '10.00', 'margin' => '1.00', 'margin_pct' => '0.00', 'outstanding' => '100.00', 'nett' => '1.00', 'realized_count' => 0, 'realized' => '0.00', 'realized_pct' => '0.00', 'overbudget' => 1, 'pending' => 2];
        foreach (['', '?department=CP', '?department=BM', '?department=XX', '?department[]=CP', '?department='] as $query) {
            $this->assertSame($expected, $this->kpi($this->staff, $query), $query);
            $this->actingAs($this->staff)->get('/dashboard'.$query)->assertSee('Rp100,00')
                ->assertDontSee('QA-DEACT')->assertDontSee('ZZDEACTNAME')->assertDontSee('QA-UNASS')->assertDontSee('ZZUNASSNAME')
                ->assertDontSee('QA-CP')->assertDontSee('ZZCPNAME')->assertDontSee('ZZCPASGNAME')->assertDontSee('Rp2.000,00')->assertDontSee('Rp30.000,00')->assertDontSee('Rp400.000,00')->assertDontSee('Rp5.000.000,00');
        }
        // Manager BM melihat tiga project BM (mine, deactivated, unassigned) dan tidak ada CP.
        $manager = $this->kpi($this->manager, '?department=CP');
        $this->assertSame(3, $manager['projects']);
        $this->assertSame('32100.00', $manager['contract']);
        $this->actingAs($this->manager)->get('/dashboard?department=CP')->assertDontSee('QA-CP')->assertDontSee('ZZCPNAME')->assertDontSee('ZZCPASGNAME');
    }

    public function test_deactivated_assignment_reactivated_changes_scope_immediately(): void
    {
        $project = Project::factory()->active()->assignedTo($this->staff, false)->create(['contract_value' => '5.00']);
        $this->assertSame(0, $this->kpi($this->staff)['projects']);
        DB::table('project_user_assignments')->where('project_id', $project->id)->update(['is_active' => true]);
        $this->assertSame(1, $this->kpi($this->staff)['projects']);
    }

    public function test_direksi_filter_abuse_gives_validation_errors_not_500(): void
    {
        Project::factory()->active()->create(['project_code' => 'QA-BM', 'contract_value' => '1.00']);
        Project::factory()->active()->create(['project_code' => 'QA-ITBS', 'department' => 'ITBS', 'contract_value' => '2.00']);
        foreach ([User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', null)->create()] as $user) {
            foreach (['?department=XX', '?department=bm', '?department[]=BM', '?department[a]=BM', '?department=%27%20OR%201=1--', '?department=9999999999999999999999'] as $query) {
                $this->actingAs($user)->get('/dashboard'.$query)->assertStatus(302)->assertSessionHasErrors('department');
            }
            $this->assertSame(2, $this->kpi($user, '?department=')['projects'], 'empty = semua divisi');
            $this->assertSame('1.00', $this->kpi($user, '?department=BM')['contract']);
            $this->assertSame('2.00', $this->kpi($user, '?department=ITBS')['contract']);
            $this->assertSame(0, $this->kpi($user, '?department=CP')['projects']);
            $this->actingAs($user)->get('/dashboard?department=ITBS')->assertSee('Rp2,00')->assertDontSee('Rp1,00')->assertSee('Divisi ITBS');
        }
    }

    public function test_validation_error_is_shown_when_returning_to_the_previous_page(): void
    {
        $direksi = User::factory()->role('DIREKSI', null)->create();
        $this->actingAs($direksi)->withHeader('referer', url('/dashboard'))->followingRedirects()->get('/dashboard?department=XX')->assertOk()->assertSee('role="alert"', false);
    }

    public function test_repeating_a_bad_department_url_does_not_redirect_to_itself(): void
    {
        $direksi = User::factory()->role('DIREKSI', null)->create();
        $this->actingAs($direksi)->get('/dashboard?department=XX');
        $this->actingAs($direksi)->get('/dashboard?department=XX')->assertRedirect(url('/dashboard'))->assertSessionHasErrors('department');
    }

    public function test_page_is_read_only_for_every_role(): void
    {
        $project = Project::factory()->active()->assignedTo($this->staff)->create(['project_code' => 'QA-RO', 'status' => 'SUBMITTED']);
        $this->invoice($project, 1, 'PAYMENT_SUBMITTED', '5.00', '4.00');
        $this->bq($project, 'SUBMITTED', true);
        $users = [$this->staff, $this->manager, User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', null)->create()];
        foreach ($users as $user) {
            $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
            $main = $html;
            // Satu-satunya <form> yang boleh ada adalah logout dari layout.
            preg_match_all('/<form\b[^>]*>/i', $main, $forms);
            foreach ($forms[0] as $form) {
                $this->assertStringContainsString('/logout', $form, $user->role->value.' memiliki form selain logout: '.$form);
            }
            foreach (['/edit', '/approve', '/submit', '/unlock', '/revise', '/create', 'Project baru', 'name="_method"', 'type="submit"'] as $needle) {
                if ($needle === 'type="submit"') {
                    $this->assertLessThanOrEqual(1, substr_count($html, $needle), $user->role->value.' punya tombol submit selain logout');

                    continue;
                }
                $this->assertStringNotContainsString($needle, $html, $user->role->value.' melihat '.$needle);
            }
            foreach (['put', 'patch', 'delete', 'post'] as $verb) {
                $this->actingAs($user)->{$verb}('/dashboard')->assertStatus(405);
            }
        }
    }

    public function test_system_admin_sees_data_and_drilldown_links_but_no_action_menu(): void
    {
        $project = Project::factory()->active()->create(['project_code' => 'QA-ADM', 'contract_value' => '77.00']);
        $admin = User::factory()->role('SYSTEM_ADMIN', null)->create();
        $response = $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('Rp77,00')->assertSee('href="'.route('dashboard.projects', 'activated').'"', false);
        $response->assertDontSee('href="'.url('/projects').'"', false)->assertDontSee('href="'.url('/invoices').'"', false)->assertDontSee('href="'.url('/approvals').'"', false)->assertDontSee('/invoices');
        $this->actingAs($admin)->get(route('projects.show', $project->id))->assertOk();
    }

    public function test_project_names_are_escaped_and_every_status_renders(): void
    {
        $i = 0;
        foreach (ProjectStatus::cases() as $status) {
            Project::factory()->create(['project_code' => 'QA-S'.(++$i), 'status' => $status->value, 'project_name' => '<script>alert('.$i.')</script>"\'&']);
        }
        foreach ([$this->manager, User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', null)->create()] as $user) {
            $response = $this->actingAs($user)->get('/dashboard')->assertOk();
            $this->assertSame(4, $response->viewData('kpi')['activated']); // 6A: DRAFT, SUBMITTED, CANCELLED tidak dihitung
            $this->assertStringNotContainsString('<script>alert(', $response->getContent());
            // Nama project hanya tampil di daftar drill-down, dan selalu di-escape.
            $list = $this->actingAs($user)->get(route('dashboard.projects', 'activated'))->assertOk();
            $this->assertStringNotContainsString('<script>alert(', $list->getContent());
            $list->assertSee('&lt;script&gt;alert(3)&lt;/script&gt;', false);
        }
    }

    public function test_list_pagination_keeps_filter_and_orders_by_code_while_kpis_stay_global(): void
    {
        for ($n = 1; $n <= 25; $n++) {
            Project::factory()->active()->create(['project_code' => sprintf('QA-P%02d', $n), 'contract_value' => '1.00']);
        }
        Project::factory()->active()->create(['project_code' => 'QA-CP01', 'department' => 'CP', 'contract_value' => '1000.00']);
        $direksi = User::factory()->role('DIREKSI', null)->create();
        $list = route('dashboard.projects', 'activated');
        $p1 = $this->actingAs($direksi)->get($list.'?department=BM')->assertOk();
        $this->assertSame(20, $p1->viewData('projects')->count());
        $p1->assertSee('QA-P01')->assertSee('QA-P20')->assertDontSee('QA-P21')->assertSee('department=BM&amp;page=2', false)->assertDontSee('QA-CP01');
        $p2 = $this->actingAs($direksi)->get($list.'?department=BM&page=2')->assertOk();
        $this->assertSame(5, $p2->viewData('projects')->count());
        $p2->assertSee('QA-P21')->assertSee('QA-P25')->assertDontSee('QA-P20');
        $dashboard = $this->actingAs($direksi)->get('/dashboard?department=BM&page=2')->assertOk()->viewData('kpi');
        $this->assertSame([25, '25.00'], [$dashboard['activated'], $dashboard['contract']]);
        foreach (['?page=0', '?page=-3', '?page=abc', '?page[]=2', '?page=99999999999999999999'] as $query) {
            $this->actingAs($direksi)->get('/dashboard'.$query)->assertOk();
            $this->actingAs($direksi)->get($list.$query)->assertOk();
        }
    }

    public function test_landing_and_home_route(): void
    {
        $this->assertSame('/', route('home', [], false));
        $this->get('/')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->post('/login', ['email' => $this->manager->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->post('/logout');
        $this->actingAs($this->staff)->get('/')->assertRedirect('/dashboard');
        $this->actingAs($this->staff)->get('/nope-404')->assertNotFound()->assertSee('href="'.route('home').'"', false);
    }
}
