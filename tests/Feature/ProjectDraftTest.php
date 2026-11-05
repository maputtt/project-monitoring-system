<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// PRJ-01 (PRD 5.2, REV5.7, AC-03, AC-21, AC-22, AC-28): buat dan ubah project DRAFT, semua nilai hitung dari server.
class ProjectDraftTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private int $pks;

    private int $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->pks = DB::table('pks')->insertGetId(['pks_number' => 'PKS/1', 'name' => 'PKS', 'client_name' => 'Klien', 'issued_date' => '2026-01-01', 'status' => 'ACTIVE', 'created_by' => $this->staff->id]);
        $this->contact = DB::table('bouwheer_contacts')->insertGetId(['name' => 'PIC', 'company_name' => 'Klien', 'is_active' => true]);
        DB::table('tax_rates')->insert(['tax_type' => 'PPN', 'name' => 'PPN 11%', 'rate' => '11.00', 'effective_from' => '2022-04-01', 'created_by' => $this->staff->id]);
    }

    /** Contoh PRD 6.3: Rp671.151.608,00 termasuk PPN, margin 15%, termin 20/50/30. */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'pks_id' => $this->pks, 'bouwheer_contact_id' => $this->contact, 'project_code' => 'PRJ-001', 'project_name' => 'Gedung A', 'location' => 'Jakarta',
            'spk_number' => 'SPK/001/2026', 'spk_date' => '2026-02-10', 'contract_value' => '671151608.00', 'contract_tax_mode' => 'INCLUSIVE_PPN',
            'margin_mode' => 'PCT', 'margin_value' => '15', 'payment_type' => 'TERMIN', 'billing_percentages' => ['20', '50', '30'],
            'start_date' => '2026-03-01', 'end_date' => '2026-11-30',
        ];
    }

    private function createProject(array $overrides = []): Project
    {
        $this->actingAs($this->staff)->post('/projects', $this->form($overrides))->assertSessionHasNoErrors();

        return Project::latest('id')->firstOrFail();
    }

    private function updateForm(Project $project, array $overrides = []): array
    {
        return $overrides + $this->form(['project_code' => $project->project_code]) + ['version' => $project->version];
    }

    private function invoices(Project $project): Collection
    {
        return DB::table('invoices')->where('project_id', $project->id)->orderBy('sequence_number')->get();
    }

    private function audit(string $action): Collection
    {
        return DB::table('audit_logs')->where('entity_type', 'PROJECT')->where('action', $action)->orderBy('id')->get();
    }

    private function rowCounts(): array
    {
        return array_map(fn ($t) => DB::table($t)->count(), ['projects', 'invoices', 'bq_items', 'project_user_assignments']) + [4 => DB::table('audit_logs')->where('entity_type', 'PROJECT')->count()];
    }

    public function test_planned_billing_dates_are_optional_aligned_per_termin_replaced_on_update_and_validated(): void
    {
        $project = $this->createProject(['planned_billing_dates' => ['2026-11-01', null, '2026-12-15']]);
        $this->assertSame(['2026-11-01', null, '2026-12-15'], $this->invoices($project)->pluck('planned_billing_date')->all());
        $this->assertSame(['2026-11-01', null, '2026-12-15'], json_decode($this->audit('CREATE')->sole()->data_after, true)['planned_billing_dates']);

        $this->put("/projects/{$project->id}", $this->updateForm($project, ['planned_billing_dates' => ['2026-11-02', '2026-12-01', '']]))->assertSessionHasNoErrors();
        $this->assertSame(['2026-11-02', '2026-12-01', null], $this->invoices($project)->pluck('planned_billing_date')->all());

        // Kunci tanggal tidak dikirim: tanggal tersimpan dipertahankan (tidak hilang diam-diam); hanya isian kosong eksplisit yang menghapus.
        $this->put("/projects/{$project->id}", $this->updateForm($project->refresh()))->assertSessionHasNoErrors();
        $this->assertSame(['2026-11-02', '2026-12-01', null], $this->invoices($project)->pluck('planned_billing_date')->all());
        $this->put("/projects/{$project->id}", $this->updateForm($project->refresh(), ['planned_billing_dates' => ['', '', '']]))->assertSessionHasNoErrors();
        $this->assertSame([null, null, null], $this->invoices($project)->pluck('planned_billing_date')->all());

        $before = $this->rowCounts();
        foreach ([['2026-02-31'], ['01/11/2026'], ['1999-12-31'], [['2026-11-01']], 'abc'] as $bad) {
            $this->actingAs($this->staff)->post('/projects', $this->form(['project_code' => 'PRJ-BAD', 'planned_billing_dates' => $bad]))->assertSessionHasErrors();
        }
        $this->assertSame($before, $this->rowCounts());
    }

    public function test_absent_dates_key_keeps_stored_dates_by_sequence_when_termin_count_changes(): void
    {
        $project = $this->createProject(['planned_billing_dates' => ['2026-11-01', '2026-11-02', '2026-11-03']]);

        $this->put("/projects/{$project->id}", $this->updateForm($project, ['billing_percentages' => ['50', '50']]))->assertSessionHasNoErrors();
        $this->assertSame(['2026-11-01', '2026-11-02'], $this->invoices($project)->pluck('planned_billing_date')->all());

        $this->put("/projects/{$project->id}", $this->updateForm($project->refresh(), ['billing_percentages' => ['25', '25', '25', '25']]))->assertSessionHasNoErrors();
        $this->assertSame(['2026-11-01', '2026-11-02', null, null], $this->invoices($project)->pluck('planned_billing_date')->all());
        $this->assertSame([1, 2, 3, 4], $this->invoices($project)->pluck('sequence_number')->map(fn ($n) => (int) $n)->all());
    }

    public function test_guest_is_sent_to_login(): void
    {
        $project = Project::factory()->assignedTo($this->staff)->create();

        $this->get('/projects/create')->assertRedirect('/login');
        $this->post('/projects', $this->form())->assertRedirect('/login');
        $this->get("/projects/{$project->id}/edit")->assertRedirect('/login');
        $this->put("/projects/{$project->id}", $this->form())->assertRedirect('/login');
        $this->assertSame(1, Project::count());
    }

    public function test_only_staff_creates_a_project(): void
    {
        foreach ([['MANAGER_GM', 'BM'], ['DIREKSI', null], ['SYSTEM_ADMIN', 'ITBS']] as [$role, $department]) {
            $this->actingAs(User::factory()->role($role, $department)->create());
            $this->get('/projects/create')->assertForbidden();
            $this->post('/projects', $this->form())->assertForbidden();
        }
        $this->assertSame(0, Project::count());

        $this->actingAs($this->staff)->get('/projects/create')->assertOk()->assertSee('Project baru');
    }

    public function test_edit_needs_an_assigned_staff_and_a_draft_project(): void
    {
        $project = Project::factory()->assignedTo($this->staff)->create();
        $active = Project::factory()->assignedTo($this->staff)->create(['status' => 'ACTIVE']);

        // Staff divisi sama yang tidak ditugaskan: project tidak terlihat, jadi 404.
        $other = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->actingAs($other)->get("/projects/{$project->id}/edit")->assertNotFound();
        $this->put("/projects/{$project->id}", $this->updateForm($project))->assertNotFound();

        // Terlihat tetapi tidak berhak: 403.
        foreach ([['MANAGER_GM', 'BM'], ['DIREKSI', null], ['SYSTEM_ADMIN', 'ITBS']] as [$role, $department]) {
            $this->actingAs(User::factory()->role($role, $department)->create());
            $this->get("/projects/{$project->id}/edit")->assertForbidden();
            $this->put("/projects/{$project->id}", $this->updateForm($project))->assertForbidden();
        }
        $this->actingAs($this->staff)->get("/projects/{$active->id}/edit")->assertForbidden();
        $this->put("/projects/{$active->id}", $this->updateForm($active))->assertForbidden();

        $this->get("/projects/{$project->id}/edit")->assertOk();
        $this->assertSame(1, (int) $project->fresh()->version);
    }

    public function test_prd_example_is_calculated_and_created_with_its_companions(): void
    {
        $project = $this->createProject();

        $this->assertSame(['671151608.00', '15.0000', '100672741.20', '570478866.80', '570478866.80', null], [
            $project->margin_base_amount, $project->target_margin_pct, $project->target_margin_amount, $project->initial_rab, $project->effective_rab, $project->margin_base_ppn_rate_snapshot,
        ]);
        $this->assertSame('671151608.00', $project->initial_contract_value);
        $this->assertSame(['BM', 'DRAFT', $this->staff->id, 1, 3], [$project->department->value, $project->status->value, $project->created_by, $project->version, $project->payment_count]);

        $invoices = $this->invoices($project);
        $this->assertSame(['134230321.60', '335575804.00', '201345482.40'], $invoices->pluck('planned_amount')->all());
        $this->assertSame(['20.0000', '50.0000', '30.0000'], $invoices->pluck('billing_percentage')->all());
        $this->assertSame([1, 2, 3], $invoices->pluck('sequence_number')->all());
        $this->assertSame(['PLANNED'], $invoices->pluck('workflow_status')->unique()->all());
        $this->assertSame(['TERMIN'], $invoices->pluck('billing_type')->unique()->all());
        $this->assertSame(['BM'], $invoices->pluck('department')->unique()->all());
        $this->assertSame([$this->staff->id], $invoices->pluck('created_by')->unique()->all());
        $this->assertSame('671151608.00', $invoices->reduce(fn ($sum, $row) => bcadd($sum, $row->planned_amount, 2), '0'));

        $bq = DB::table('bq_items')->where('project_id', $project->id)->sole();
        $this->assertSame(['OPERATIONAL', 'Biaya Lain-lain / Operasional', '0.00', '0.00', 'DRAFT'], [$bq->item_type, $bq->item_name, $bq->baseline_budget, $bq->effective_budget, $bq->status]);

        $assignment = DB::table('project_user_assignments')->where('project_id', $project->id)->sole();
        $this->assertSame(['PIC', $this->staff->id, $this->staff->id, 1], [$assignment->assignment_role, $assignment->user_id, $assignment->assigned_by, $assignment->is_active]);

        $audit = $this->audit('CREATE')->sole();
        $this->assertSame([$project->id, $this->staff->id, null], [$audit->entity_id, $audit->user_id, $audit->data_before]);
        $after = json_decode($audit->data_after, true);
        $this->assertSame(['PRJ-001', '100672741.20', '2026-02-10', ['20.0000', '50.0000', '30.0000']], [$after['project_code'], $after['target_margin_amount'], $after['spk_date'], $after['billing_percentages']]);

        // Pembuat melihat draft-nya sendiri lewat redirect ke halaman ubah.
        $this->get("/projects/{$project->id}/edit")->assertOk()->assertSee('Rp570.478.866,80')->assertSee('Rp201.345.482,40');
    }

    public function test_successful_save_redirects_to_the_edit_page(): void
    {
        $response = $this->actingAs($this->staff)->post('/projects', $this->form());

        $project = Project::sole();
        $response->assertRedirect("/projects/{$project->id}/edit")->assertSessionHas('status');
    }

    public function test_exclusive_ppn_adds_ppn_and_keeps_the_rate_snapshot(): void
    {
        $project = $this->createProject(['contract_value' => '180000000.00', 'contract_tax_mode' => 'EXCLUSIVE_PPN']);

        $this->assertSame(['180000000.00', '199800000.00', '11.0000', '29970000.00', '169830000.00'], [
            $project->contract_value, $project->margin_base_amount, $project->margin_base_ppn_rate_snapshot, $project->target_margin_amount, $project->initial_rab,
        ]);
        $this->assertSame('199800000.00', $this->invoices($project)->reduce(fn ($sum, $row) => bcadd($sum, $row->planned_amount, 2), '0'));
    }

    public function test_exclusive_ppn_without_a_rate_in_force_is_refused_and_nothing_is_written(): void
    {
        $this->actingAs($this->staff)->post('/projects', $this->form(['contract_tax_mode' => 'EXCLUSIVE_PPN', 'spk_date' => '2022-03-31']))
            ->assertSessionHasErrors('spk_date');

        $this->assertSame([0, 0, 0, 0, 0], $this->rowCounts());
    }

    public function test_non_ppn_margin_base_equals_contract_value(): void
    {
        $project = $this->createProject(['contract_tax_mode' => 'NON_PPN', 'contract_value' => '100000000.00']);

        $this->assertSame(['100000000.00', null], [$project->margin_base_amount, $project->margin_base_ppn_rate_snapshot]);
    }

    public function test_margin_given_as_an_amount_stores_the_percentage_with_four_decimals_half_up(): void
    {
        $project = $this->createProject(['contract_value' => '30.00', 'contract_tax_mode' => 'NON_PPN', 'margin_mode' => 'AMOUNT', 'margin_value' => '20.00']);
        $this->assertSame(['66.6667', '20.00', '10.00'], [$project->target_margin_pct, $project->target_margin_amount, $project->initial_rab]);

        $tie = $this->createProject(['project_code' => 'PRJ-002', 'contract_value' => '16000.00', 'contract_tax_mode' => 'NON_PPN', 'margin_mode' => 'AMOUNT', 'margin_value' => '1']);
        $this->assertSame('0.0063', $tie->target_margin_pct); // 0,00625 tepat di tengah: naik
    }

    public function test_zero_margin_base_with_a_zero_amount_does_not_divide_by_zero(): void
    {
        $project = $this->createProject(['contract_value' => '0', 'contract_tax_mode' => 'NON_PPN', 'margin_mode' => 'AMOUNT', 'margin_value' => '0']);

        $this->assertSame(['0.0000', '0.00', '0.00'], [$project->target_margin_pct, $project->target_margin_amount, $project->initial_rab]);
        $this->assertSame(['0.00'], $this->invoices($project)->pluck('planned_amount')->unique()->all());

        $this->post('/projects', $this->form(['project_code' => 'PRJ-002', 'contract_value' => '0', 'contract_tax_mode' => 'NON_PPN', 'margin_mode' => 'AMOUNT', 'margin_value' => '1']))
            ->assertSessionHasErrors('margin_value');
    }

    public function test_billing_must_total_exactly_100_and_the_last_row_absorbs_the_difference(): void
    {
        foreach ([['33.3333', '33.3333', '33.3333'], ['50', '50.01'], ['99.9999'], ['60', '40', '0.0001']] as $rows) {
            $this->actingAs($this->staff)->post('/projects', $this->form(['billing_percentages' => $rows]))->assertSessionHasErrors('billing_percentages');
        }
        $this->assertSame(0, Project::count());

        $project = $this->createProject(['contract_value' => '100.00', 'contract_tax_mode' => 'NON_PPN', 'billing_percentages' => ['33.3333', '33.3333', '33.3334']]);
        $this->assertSame(['33.33', '33.33', '33.34'], $this->invoices($project)->pluck('planned_amount')->all());
        $this->assertSame(['33.3333', '33.3333', '33.3334'], $this->invoices($project)->pluck('billing_percentage')->all());
    }

    public function test_forged_calculated_fields_are_ignored(): void
    {
        $other = User::factory()->role('STAFF_PIC', 'CP')->create();
        $project = $this->createProject([
            'department' => 'CP', 'status' => 'ACTIVE', 'initial_rab' => '1.00', 'effective_rab' => '1.00', 'margin_base_amount' => '1.00', 'target_margin_amount' => '1.00',
            'margin_base_ppn_rate_snapshot' => '9.0000', 'actual_profit_amount' => '5.00', 'created_by' => $other->id, 'version' => 77, 'payment_count' => 9,
        ]);

        $this->assertSame(['BM', 'DRAFT', '570478866.80', '671151608.00', null, null, $this->staff->id, 1, 3], [
            $project->department->value, $project->status->value, $project->effective_rab, $project->margin_base_amount, $project->margin_base_ppn_rate_snapshot, $project->actual_profit_amount, $project->created_by, $project->version, $project->payment_count,
        ]);
    }

    public function test_validation_rules(): void
    {
        $expired = DB::table('pks')->insertGetId(['pks_number' => 'PKS/2', 'name' => 'PKS lama', 'client_name' => 'Klien', 'issued_date' => '2020-01-01', 'status' => 'EXPIRED', 'created_by' => $this->staff->id]);
        $inactive = DB::table('bouwheer_contacts')->insertGetId(['name' => 'PIC lama', 'company_name' => 'Klien', 'is_active' => false]);

        $invalid = [
            'PKS tidak aktif' => [['pks_id' => $expired], 'pks_id'],
            'PKS tidak ada' => [['pks_id' => 9999], 'pks_id'],
            'PIC nonaktif' => [['bouwheer_contact_id' => $inactive], 'bouwheer_contact_id'],
            'selesai sebelum mulai' => [['start_date' => '2026-12-01', 'end_date' => '2026-11-30'], 'end_date'],
            'mode pajak tidak dikenal' => [['contract_tax_mode' => 'LAINNYA'], 'contract_tax_mode'],
            'nilai kontrak negatif' => [['contract_value' => '-1.00'], 'contract_value'],
            'nilai kontrak tiga desimal' => [['contract_value' => '100.005'], 'contract_value'],
            'nilai kontrak huruf' => [['contract_value' => '1e3'], 'contract_value'],
            'margin nominal di atas margin base' => [['margin_mode' => 'AMOUNT', 'margin_value' => '671151608.01'], 'margin_value'],
            'margin nominal tiga desimal' => [['margin_mode' => 'AMOUNT', 'margin_value' => '10.005'], 'margin_value'],
            'margin persen di atas 100' => [['margin_value' => '100.01'], 'margin_value'],
            'margin negatif' => [['margin_value' => '-1'], 'margin_value'],
            'mode margin tidak dikenal' => [['margin_mode' => 'X'], 'margin_mode'],
            'tipe pembayaran tidak dikenal' => [['payment_type' => 'WEEKLY'], 'payment_type'],
            'persentase nol' => [['billing_percentages' => ['100', '0']], 'billing_percentages.1'],
            'persentase lima desimal' => [['billing_percentages' => ['99.99999', '0.00001']], 'billing_percentages.0'],
            'tanpa persentase' => [['billing_percentages' => []], 'billing_percentages'],
            'terlalu banyak baris' => [['billing_percentages' => array_fill(0, 61, '1')], 'billing_percentages'],
            'tanpa kode' => [['project_code' => ''], 'project_code'],
        ];

        foreach ($invalid as $why => [$overrides, $field]) {
            $this->actingAs($this->staff)->post('/projects', $this->form($overrides))->assertSessionHasErrors($field);
            $this->assertSame([0, 0, 0, 0, 0], $this->rowCounts(), $why);
        }

        $this->post('/projects', $this->form(['margin_mode' => 'AMOUNT', 'margin_value' => '671151608.00']))->assertSessionHasNoErrors(); // tepat sama dengan margin base
        $this->assertSame('0.00', Project::sole()->initial_rab);
        $this->assertSame('100.0000', Project::sole()->target_margin_pct);
    }

    public function test_project_code_is_unique_and_double_submit_creates_one_project(): void
    {
        $this->actingAs($this->staff)->post('/projects', $this->form());
        $this->post('/projects', $this->form())->assertSessionHasErrors('project_code');

        $this->assertSame([1, 3, 1, 1, 1], $this->rowCounts());
    }

    public function test_failure_while_creating_rolls_everything_back(): void
    {
        // Gagal tepat saat menulis audit, setelah project, jadwal invoice, BQ, dan assignment sudah ditulis.
        DB::beforeExecuting(function ($query) {
            if (str_contains($query, 'insert into `audit_logs`')) {
                throw new \RuntimeException('audit gagal');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->staff)->post('/projects', $this->form());
            $this->fail('Penyimpanan seharusnya gagal.');
        } catch (\RuntimeException) {
        }

        $this->assertSame([0, 0, 0, 0, 0], $this->rowCounts());
    }

    public function test_edit_recalculates_and_writes_an_audit_with_before_and_after(): void
    {
        $project = $this->createProject();

        $this->put("/projects/{$project->id}", $this->updateForm($project, [
            'contract_value' => '180000000.00', 'contract_tax_mode' => 'EXCLUSIVE_PPN', 'margin_mode' => 'AMOUNT', 'margin_value' => '23976000.00', 'billing_percentages' => ['40', '60'],
            'department' => 'CP', 'status' => 'ACTIVE', 'initial_rab' => '1.00',
        ]))->assertRedirect("/projects/{$project->id}/edit")->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame(['180000000.00', '199800000.00', '11.0000', '12.0000', '23976000.00', '175824000.00', '175824000.00', 2, 2, 'BM', 'DRAFT'], [
            $project->contract_value, $project->margin_base_amount, $project->margin_base_ppn_rate_snapshot, $project->target_margin_pct, $project->target_margin_amount,
            $project->initial_rab, $project->effective_rab, $project->version, $project->payment_count, $project->department->value, $project->status->value,
        ]);
        $this->assertSame(['79920000.00', '119880000.00'], $this->invoices($project)->pluck('planned_amount')->all());
        $this->assertSame('180000000.00', $project->initial_contract_value); // DRAFT: baseline ikut nilai kontrak

        $audit = $this->audit('UPDATE')->sole();
        $before = json_decode($audit->data_before, true);
        $after = json_decode($audit->data_after, true);
        $this->assertSame([$project->id, $this->staff->id], [$audit->entity_id, $audit->user_id]);
        $this->assertSame(['671151608.00', '100672741.20', 1, ['20.0000', '50.0000', '30.0000']], [$before['margin_base_amount'], $before['target_margin_amount'], $before['version'], $before['billing_percentages']]);
        $this->assertSame(['199800000.00', '23976000.00', 2, ['40.0000', '60.0000']], [$after['margin_base_amount'], $after['target_margin_amount'], $after['version'], $after['billing_percentages']]);

        // BQ OPERATIONAL dan assignment tidak disentuh.
        $this->assertSame([1, 1], [DB::table('bq_items')->count(), DB::table('project_user_assignments')->count()]);
    }

    public function test_edit_grows_and_shrinks_the_billing_plan_in_place(): void
    {
        $project = $this->createProject();
        $ids = $this->invoices($project)->pluck('id');

        $this->put("/projects/{$project->id}", $this->updateForm($project, ['billing_percentages' => ['25', '25', '25', '25']]))->assertSessionHasNoErrors();
        $grown = $this->invoices($project);
        $this->assertSame($ids->all(), $grown->take(3)->pluck('id')->all()); // baris lama diubah di tempat
        $this->assertSame([1, 2, 3, 4], $grown->pluck('sequence_number')->all());
        $this->assertSame('671151608.00', $grown->reduce(fn ($sum, $row) => bcadd($sum, $row->planned_amount, 2), '0'));

        $this->put("/projects/{$project->id}", $this->updateForm($project->refresh(), ['billing_percentages' => ['33.3333', '66.6667']]))->assertSessionHasNoErrors();
        $shrunk = $this->invoices($project);
        $this->assertSame($ids->take(2)->all(), $shrunk->pluck('id')->all());
        $this->assertSame(['223716978.95', '447434629.05'], $shrunk->pluck('planned_amount')->all());
        $this->assertSame('671151608.00', bcadd($shrunk[0]->planned_amount, $shrunk[1]->planned_amount, 2));
        $this->assertSame(2, (int) $project->fresh()->payment_count);
    }

    public function test_stale_version_is_refused_and_changes_nothing(): void
    {
        $project = $this->createProject();
        $this->put("/projects/{$project->id}", $this->updateForm($project, ['project_name' => 'Pertama']))->assertSessionHasNoErrors();
        $auditCount = $this->audit('UPDATE')->count();

        // Form lama masih membawa version 1.
        $this->from("/projects/{$project->id}/edit")->put("/projects/{$project->id}", $this->updateForm($project, ['project_name' => 'Kedua', 'billing_percentages' => ['100']]))
            ->assertRedirect("/projects/{$project->id}/edit")->assertSessionHasErrors('version');
        $this->putJson("/projects/{$project->id}", $this->updateForm($project, ['project_name' => 'Kedua']))->assertStatus(409);

        $project->refresh();
        $this->assertSame(['Pertama', 2, 3], [$project->project_name, $project->version, $this->invoices($project)->count()]);
        $this->assertCount($auditCount, $this->audit('UPDATE'));
    }

    public function test_failure_while_updating_rolls_everything_back(): void
    {
        $project = $this->createProject();
        $before = $this->invoices($project)->pluck('planned_amount')->all();

        DB::beforeExecuting(function ($query) {
            if (str_contains($query, 'insert into `audit_logs`')) {
                throw new \RuntimeException('audit gagal');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->put("/projects/{$project->id}", $this->updateForm($project, ['project_name' => 'Berubah', 'billing_percentages' => ['100']]));
            $this->fail('Penyimpanan seharusnya gagal.');
        } catch (\RuntimeException) {
        }

        $project->refresh();
        $this->assertSame(['Gedung A', 1], [$project->project_name, $project->version]);
        $this->assertSame($before, $this->invoices($project)->pluck('planned_amount')->all());
    }

    public function test_edit_keeps_its_own_code_but_cannot_take_another_projects_code(): void
    {
        $first = $this->createProject();
        $second = $this->createProject(['project_code' => 'PRJ-002']);

        $this->put("/projects/{$first->id}", $this->updateForm($first))->assertSessionHasNoErrors(); // kode sendiri tidak dianggap duplikat
        $this->put("/projects/{$first->id}", $this->updateForm($first->refresh(), ['project_code' => 'PRJ-002']))->assertSessionHasErrors('project_code');
        $this->assertSame(['PRJ-001', 'PRJ-002'], [$first->fresh()->project_code, $second->fresh()->project_code]);
    }

    public function test_update_without_a_version_is_refused(): void
    {
        $project = $this->createProject();

        $this->put("/projects/{$project->id}", $this->form(['project_code' => 'PRJ-001']))->assertSessionHasErrors('version');
    }

    /** Form ubah mengisi ulang margin sebagai nominal tersimpan; menyimpan tanpa perubahan tidak boleh menggeser nilai apa pun. */
    private function assertUnchangedSaveKeepsMargin(Project $project): void
    {
        $keep = fn () => [$project->target_margin_pct, $project->target_margin_amount, $project->initial_rab, $project->effective_rab];
        $before = $keep();

        $this->put("/projects/{$project->id}", $this->updateForm($project, [
            'contract_value' => $project->contract_value, 'contract_tax_mode' => $project->contract_tax_mode, 'margin_mode' => 'AMOUNT', 'margin_value' => $project->target_margin_amount,
        ]))->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame($before, $keep());
        $this->assertSame(2, (int) $project->version);
    }

    public function test_unchanged_save_keeps_the_margin_created_as_an_amount(): void
    {
        $project = $this->createProject(['contract_value' => '123456789.12', 'contract_tax_mode' => 'NON_PPN', 'margin_mode' => 'AMOUNT', 'margin_value' => '15432098.65']);

        $this->assertUnchangedSaveKeepsMargin($project);
        $this->assertSame('15432098.65', $project->target_margin_amount);
    }

    public function test_unchanged_save_keeps_the_margin_created_as_a_percentage(): void
    {
        // 12,3456% dari 100,00 = 12,35 setelah pembulatan; dihitung balik dari nominal akan menjadi 12,3500%.
        $project = $this->createProject(['contract_value' => '100.00', 'contract_tax_mode' => 'NON_PPN', 'margin_value' => '12.3456', 'billing_percentages' => ['100']]);
        $this->assertSame(['12.3456', '12.35', '87.65'], [$project->target_margin_pct, $project->target_margin_amount, $project->initial_rab]);

        $this->assertUnchangedSaveKeepsMargin($project);
        $this->assertSame('12.3456', $project->target_margin_pct);

        // Nominal diubah: persentase dihitung ulang dari nominal baru.
        $this->put("/projects/{$project->id}", $this->updateForm($project, ['contract_value' => '100.00', 'contract_tax_mode' => 'NON_PPN', 'margin_mode' => 'AMOUNT', 'margin_value' => '12.36', 'billing_percentages' => ['100']]))->assertSessionHasNoErrors();
        $this->assertSame('12.3600', $project->refresh()->target_margin_pct);
    }

    public function test_wrong_typed_input_is_a_validation_error_not_a_server_error(): void
    {
        $project = $this->createProject();
        $crafted = [
            'pks_id' => ['1'], 'bouwheer_contact_id' => ['1'], 'project_code' => ['x'], 'project_name' => ['x'], 'location' => ['x'], 'spk_number' => ['x'],
            'spk_date' => ['x'], 'contract_value' => ['1'], 'contract_tax_mode' => ['NON_PPN'], 'margin_mode' => ['PCT'], 'margin_value' => ['1'], 'payment_type' => ['TERMIN'],
            'start_date' => ['x'], 'end_date' => ['x'], 'billing_percentages' => 'abc',
        ];
        $counts = $this->rowCounts();

        foreach ($crafted as $field => $value) {
            $this->post('/projects', $this->form([$field => $value]))->assertSessionHasErrors($field);
            $this->put("/projects/{$project->id}", $this->updateForm($project, [$field => $value]))->assertSessionHasErrors($field);
        }
        $this->post('/projects', $this->form(['billing_percentages' => [['1']]]))->assertSessionHasErrors('billing_percentages.0');
        $this->put("/projects/{$project->id}", $this->updateForm($project, ['version' => ['1']]))->assertSessionHasErrors('version');
        // start_date berupa array tidak boleh merusak pembanding end_date.
        $this->post('/projects', $this->form(['start_date' => ['x'], 'end_date' => '2026-12-01']))->assertSessionHasErrors('start_date');

        $this->assertSame($counts, $this->rowCounts());
        $this->assertSame(1, (int) $project->fresh()->version);
    }

    public function test_validation_messages_are_in_indonesian(): void
    {
        $this->actingAs($this->staff)->post('/projects', $this->form(['project_name' => str_repeat('a', 201), 'project_code' => str_repeat('b', 101), 'location' => str_repeat('c', 256), 'spk_number' => str_repeat('d', 101)]))
            ->assertSessionHasErrors([
                'project_name' => 'Nama project maksimal 200 karakter.', 'project_code' => 'Kode project maksimal 100 karakter.',
                'location' => 'Lokasi maksimal 255 karakter.', 'spk_number' => 'Nomor SPK maksimal 100 karakter.',
            ]);
    }

    public function test_staff_of_another_division_creates_a_project_in_their_own_division(): void
    {
        $cp = User::factory()->role('STAFF_PIC', 'CP')->create();
        $this->actingAs($cp)->post('/projects', $this->form())->assertSessionHasNoErrors();

        $project = Project::sole();
        $this->assertSame('CP', $project->department->value);
        $this->assertSame(['CP'], $this->invoices($project)->pluck('department')->unique()->all());
        $this->get("/projects/{$project->id}/edit")->assertOk();
        $this->actingAs($this->staff)->get("/projects/{$project->id}/edit")->assertNotFound();
    }

    public function test_inactive_assignment_gives_404_on_edit(): void
    {
        $project = Project::factory()->assignedTo($this->staff, active: false)->create();

        $this->actingAs($this->staff)->get("/projects/{$project->id}/edit")->assertNotFound();
        $this->put("/projects/{$project->id}", $this->updateForm($project))->assertNotFound();
    }

    public function test_exactly_60_billing_rows_are_accepted_and_negative_or_text_percentages_refused(): void
    {
        $rows = [...array_fill(0, 59, '1.6666'), '1.6706']; // 59 x 1,6666 + 1,6706 = 100
        $project = $this->createProject(['billing_percentages' => $rows]);

        $planned = $this->invoices($project);
        $this->assertCount(60, $planned);
        $this->assertSame('671151608.00', $planned->reduce(fn ($sum, $row) => bcadd($sum, $row->planned_amount, 2), '0'));

        foreach ([['-10', '110'], ['abc', '100'], ['50', '50', 'x'], ['5e1', '50']] as $bad) {
            $this->post('/projects', $this->form(['project_code' => 'PRJ-X', 'billing_percentages' => $bad]))->assertSessionHasErrors();
        }
        $this->assertSame(1, Project::count());
    }

    public function test_exclusive_ppn_without_a_rate_is_also_refused_on_update(): void
    {
        $project = $this->createProject();
        $counts = $this->rowCounts();

        $this->put("/projects/{$project->id}", $this->updateForm($project, ['contract_tax_mode' => 'EXCLUSIVE_PPN', 'spk_date' => '2022-03-31']))->assertSessionHasErrors('spk_date');

        $this->assertSame($counts, $this->rowCounts());
        $project->refresh();
        $this->assertSame([1, 'INCLUSIVE_PPN', '2026-02-10'], [$project->version, $project->contract_tax_mode, $project->spk_date->toDateString()]);
    }

    public function test_forged_id_is_ignored(): void
    {
        $project = $this->createProject(['id' => 999]);
        $this->assertNotSame(999, $project->id);

        $this->put("/projects/{$project->id}", $this->updateForm($project, ['id' => 999]))->assertSessionHasNoErrors();
        $this->assertSame([$project->id], Project::pluck('id')->all());
    }

    public function test_a_plan_that_would_make_the_last_amount_negative_is_refused(): void
    {
        // Rp0,05 dibagi 8 x 11,5% (masing-masing naik ke 0,01) membuat sisa untuk baris terakhir negatif.
        $rows = [...array_fill(0, 8, '11.5'), '8'];

        $this->actingAs($this->staff)->post('/projects', $this->form(['contract_value' => '0.05', 'contract_tax_mode' => 'NON_PPN', 'billing_percentages' => $rows]))
            ->assertSessionHasErrors('billing_percentages');

        $this->assertSame([0, 0, 0, 0, 0], $this->rowCounts());
    }
}
