<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// BQ-01 (PRD 5.3, 5.4, AC-04): item BQ draft dan BQ Operasional. Margin dihitung server terhadap effective budget.
class BqItemTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Project $project;

    private int $operational;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id]);
        $this->operational = DB::table('bq_items')->insertGetId(['project_id' => $this->project->id, 'item_name' => 'Biaya Lain-lain / Operasional', 'item_type' => 'OPERATIONAL', 'status' => 'DRAFT', 'created_by' => $this->staff->id]);
    }

    private function form(array $overrides = []): array
    {
        return $overrides + ['item_name' => 'Pekerjaan A', 'item_code' => 'A-01', 'vendor_quote_amount' => '1200.00', 'baseline_budget' => '1000.00', 'negotiated_value' => '800.00'];
    }

    private function add(array $overrides = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->staff)->post("/projects/{$this->project->id}/bq-items", $this->form($overrides));
    }

    private function item(array $overrides = []): int
    {
        $this->add($overrides)->assertSessionHasNoErrors();

        return DB::table('bq_items')->where('project_id', $this->project->id)->max('id');
    }

    private function row(int $id): object
    {
        return DB::table('bq_items')->where('id', $id)->first();
    }

    private function setProjectStatus(string $status): void
    {
        DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
    }

    private function edit(int $id, array $overrides = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->staff)->put("/bq-items/$id", $this->form($overrides) + ['version' => $this->row($id)->version]);
    }

    private function audits(string $action): int
    {
        return DB::table('audit_logs')->where('entity_type', 'BQ_ITEM')->where('action', $action)->count();
    }

    public function test_add_normal_item_computes_effective_margin_and_overbudget_on_the_server(): void
    {
        $this->add(['item_type' => 'OPERATIONAL', 'effective_budget' => '1', 'margin_amount' => '1', 'status' => 'APPROVED'])->assertRedirect("/projects/{$this->project->id}");
        $ok = $this->row(DB::table('bq_items')->max('id'));
        $this->assertSame(['NORMAL', 'DRAFT', '1000.00', '1000.00', '800.00', '200.00', '20.0000', 0, $this->staff->id], [$ok->item_type, $ok->status, $ok->baseline_budget, $ok->effective_budget, $ok->negotiated_value, $ok->margin_amount, $ok->margin_pct, $ok->is_overbudget, $ok->created_by]);
        $this->assertSame(1, DB::table('bq_items')->where('project_id', $this->project->id)->where('item_type', 'OPERATIONAL')->count()); // tidak ada OPERATIONAL kedua

        $over = $this->row($this->item(['baseline_budget' => '1000.00', 'negotiated_value' => '1250.55']));
        $this->assertSame(['-250.55', '-25.0550', 1], [$over->margin_amount, $over->margin_pct, $over->is_overbudget]);

        $round = $this->row($this->item(['baseline_budget' => '3', 'negotiated_value' => '1']));
        $this->assertSame(['2.00', '66.6667'], [$round->margin_amount, $round->margin_pct]); // 2/3 dibulatkan half-up

        $huge = $this->row($this->item(['baseline_budget' => '1', 'negotiated_value' => '20000']));
        $this->assertSame(['-19999.00', '-999.9999'], [$huge->margin_amount, $huge->margin_pct]); // persen dijepit ke DECIMAL(7,4)
    }

    public function test_add_validates_input_and_writes_nothing_on_failure(): void
    {
        $this->add(['negotiated_value' => '-1'])->assertSessionHasErrors('negotiated_value');
        $this->add(['baseline_budget' => '1.005'])->assertSessionHasErrors('baseline_budget');
        $this->add(['item_name' => ''])->assertSessionHasErrors('item_name');

        $this->assertSame(1, DB::table('bq_items')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('entity_type', 'BQ_ITEM')->count());
    }

    public function test_add_is_allowed_only_while_project_is_draft_active_or_completed(): void
    {
        foreach (['DRAFT' => 302, 'ACTIVE' => 302, 'COMPLETED' => 302, 'SUBMITTED' => 403, 'FINANCIALLY_CLOSED' => 403, 'ARCHIVED' => 403, 'CANCELLED' => 403] as $status => $http) {
            $this->setProjectStatus($status);
            $this->add()->assertStatus($http);
        }

        $this->assertSame(4, DB::table('bq_items')->count()); // operasional + tiga item
    }

    public function test_edit_recomputes_bumps_version_and_is_audited(): void
    {
        $id = $this->item();

        $this->edit($id, ['item_name' => 'Pekerjaan B', 'baseline_budget' => '500.00', 'negotiated_value' => '600.00', 'vendor_quote_amount' => null])->assertRedirect("/projects/{$this->project->id}");

        $row = $this->row($id);
        $this->assertSame(['Pekerjaan B', null, '500.00', '-100.00', '-20.0000', 1, 2, 'NORMAL'], [$row->item_name, $row->vendor_quote_amount, $row->effective_budget, $row->margin_amount, $row->margin_pct, $row->is_overbudget, $row->version, $row->item_type]);
        $update = DB::table('audit_logs')->where('entity_type', 'BQ_ITEM')->where('entity_id', $id)->where('action', 'UPDATE')->sole();
        $this->assertSame(['Pekerjaan A', 'Pekerjaan B'], [json_decode($update->data_before)->item_name, json_decode($update->data_after)->item_name]);
        $this->assertSame(1, $this->audits('CREATE'));
    }

    public function test_edit_is_allowed_only_for_draft_and_revision_required_items(): void
    {
        $id = $this->item();

        foreach (['DRAFT' => 302, 'REVISION_REQUIRED' => 302, 'SUBMITTED' => 409, 'APPROVED' => 409, 'CLOSED' => 409, 'ARCHIVED' => 409] as $status => $http) {
            DB::table('bq_items')->where('id', $id)->update(['status' => $status]);
            $this->edit($id)->assertStatus($http);
        }

        $this->assertSame(2, $this->audits('UPDATE'));
    }

    public function test_operational_item_is_filled_only_while_project_is_draft(): void
    {
        $this->edit($this->operational, ['baseline_budget' => '250.50', 'item_name' => 'Diganti', 'item_type' => 'NORMAL', 'negotiated_value' => '99'])->assertRedirect();
        $row = $this->row($this->operational);
        $this->assertSame(['Biaya Lain-lain / Operasional', 'OPERATIONAL', '250.50', '250.50', '0.00', '0.00', '0.0000', 0], [$row->item_name, $row->item_type, $row->baseline_budget, $row->effective_budget, $row->negotiated_value, $row->margin_amount, $row->margin_pct, $row->is_overbudget]);

        $this->setProjectStatus('ACTIVE');
        $this->edit($this->operational, ['baseline_budget' => '999.00'])->assertForbidden();
        DB::table('bq_items')->where('id', $this->operational)->update(['status' => 'APPROVED']); // seperti setelah aktivasi
        $this->edit($this->operational, ['baseline_budget' => '999.00'])->assertForbidden();
        $this->assertSame('250.50', $this->row($this->operational)->baseline_budget);

        $normal = $this->item(); // item NORMAL tetap bisa diubah pada project ACTIVE
        $this->edit($normal, ['negotiated_value' => '700.00'])->assertRedirect();
    }

    public function test_only_assigned_staff_can_create_or_edit(): void
    {
        $this->post("/projects/{$this->project->id}/bq-items", $this->form())->assertRedirect('/login');
        $id = $this->item();
        $count = DB::table('bq_items')->count();
        $outsiders = [
            'unassigned same division' => User::factory()->role('STAFF_PIC', 'BM')->create(),
            'other division' => User::factory()->role('STAFF_PIC', 'CP')->create(),
        ];
        foreach ($outsiders as $user) {
            $this->add(user: $user)->assertNotFound();
            $this->edit($id, user: $user)->assertNotFound();
        }

        foreach ([User::factory()->role('MANAGER_GM', 'BM')->create(), User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', null)->create()] as $user) {
            $this->add(user: $user)->assertForbidden();
            $this->edit($id, user: $user)->assertForbidden();
        }

        $this->assertSame($count, DB::table('bq_items')->count());
        $this->assertSame(['Pekerjaan A', 1], [$this->row($id)->item_name, $this->row($id)->version]);
    }

    public function test_stale_version_is_rejected_without_changes(): void
    {
        $id = $this->item();
        $this->edit($id)->assertRedirect(); // version menjadi 2

        $this->actingAs($this->staff)->putJson("/bq-items/$id", $this->form(['item_name' => 'Basi']) + ['version' => 1])->assertStatus(409);
        $this->actingAs($this->staff)->from('/back')->put("/bq-items/$id", $this->form(['item_name' => 'Basi']) + ['version' => 1])->assertRedirect('/back')->assertSessionHasErrorsIn("bq-$id", 'version');

        $row = $this->row($id);
        $this->assertSame(['Pekerjaan A', 2], [$row->item_name, $row->version]);
        $this->assertSame(1, $this->audits('UPDATE'));
    }

    public function test_edit_control_stays_on_draft_normal_item_after_the_locked_operational_row(): void
    {
        $id = $this->item(['item_name' => 'Pekerjaan Setelah Operasional']);
        $this->setProjectStatus('ACTIVE'); // baris OPERATIONAL (urutan pertama) tidak editable

        $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->assertSee(route('bq-items.update', $id), false)->assertDontSee(route('bq-items.update', $this->operational), false);
    }

    // QA BQ-01: nilai tangan. 1/2.000.000 x 100 = 0,00005 tepat di batas pembulatan; setengah dibulatkan menjauhi nol (half-up uang).
    public function test_margin_pct_rounding_clamp_boundary_and_money_limits(): void
    {
        $cases = [
            [['baseline_budget' => '2000000', 'negotiated_value' => '1999999'], ['1.00', '0.0001', 0]],
            [['baseline_budget' => '2000000', 'negotiated_value' => '2000001'], ['-1.00', '-0.0001', 1]],
            [['baseline_budget' => '100000000', 'negotiated_value' => '1099999900'], ['-999999900.00', '-999.9999', 1]], // tepat -999,9999, tanpa jepit
            [['baseline_budget' => '100000000', 'negotiated_value' => '1099999901'], ['-999999901.00', '-999.9999', 1]], // -1000,00001 dijepit; nominal tetap tepat
            [['baseline_budget' => '0', 'negotiated_value' => '5'], ['-5.00', '0.0000', 1]], // basis nol: tanpa pembagian nol
            [['baseline_budget' => '999999999999999', 'negotiated_value' => '0'], ['999999999999999.00', '100.0000', 0]], // batas atas yang diterima
            [['baseline_budget' => '100', 'negotiated_value' => '-0'], ['100.00', '100.0000', 0]], // -0 tidak melanggar CHECK
        ];
        foreach ($cases as [$input, $expected]) {
            $row = $this->row($this->item($input));
            $this->assertSame($expected, [$row->margin_amount, $row->margin_pct, $row->is_overbudget], json_encode($input));
        }

        $count = DB::table('bq_items')->count();
        $this->add(['negotiated_value' => '999999999999999.01'])->assertSessionHasErrors('negotiated_value');
        $this->add(['baseline_budget' => '1000000000000000'])->assertSessionHasErrors('baseline_budget');
        $this->add(['vendor_quote_amount' => '-0.01'])->assertSessionHasErrors('vendor_quote_amount');
        $this->assertSame($count, DB::table('bq_items')->count());
    }

    public function test_manager_of_other_division_unknown_ids_and_guest_edit_are_rejected(): void
    {
        $id = $this->item();
        $other = User::factory()->role('MANAGER_GM', 'CP')->create();

        $this->add(user: $other)->assertNotFound();
        $this->edit($id, user: $other)->assertNotFound();
        $this->actingAs($this->staff)->put('/bq-items/999999', $this->form() + ['version' => 1])->assertNotFound();
        $this->actingAs($this->staff)->post('/projects/999999/bq-items', $this->form())->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->put("/bq-items/$id", $this->form() + ['version' => 1])->assertRedirect('/login');
        $this->assertSame(1, $this->row($id)->version);
    }

    public function test_project_page_renders_for_every_role_and_status_and_escapes_item_text(): void
    {
        $this->item(['item_name' => '<script>alert(1)</script>', 'item_code' => '"><img src=x>']);
        foreach (['SUBMITTED', 'REVISION_REQUIRED', 'APPROVED', 'CLOSED'] as $status) { // semua status item harus bisa dirender
            DB::table('bq_items')->insert(['project_id' => $this->project->id, 'item_name' => "st-$status", 'status' => $status, 'created_by' => $this->staff->id]);
        }
        $viewers = [User::factory()->role('MANAGER_GM', 'BM')->create(), User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', null)->create()];

        foreach (['DRAFT', 'ACTIVE', 'SUBMITTED', 'COMPLETED', 'FINANCIALLY_CLOSED', 'ARCHIVED', 'CANCELLED'] as $status) {
            $this->setProjectStatus($status);
            foreach ([$this->staff, ...$viewers] as $user) {
                $html = $this->actingAs($user)->get("/projects/{$this->project->id}")->assertOk()->getContent();
                $this->assertStringNotContainsString('<script>alert(1)', $html);
                $this->assertStringNotContainsString('"><img src=x>', $html);
                if ($user->isNot($this->staff)) {
                    $this->assertStringNotContainsString('name="version"', $html, "$status {$user->role->value}"); // tanpa form ubah
                }
            }
        }
    }

    public function test_project_page_lists_items_and_shows_forms_only_to_staff(): void
    {
        $id = $this->item(['item_name' => 'Pekerjaan Tampil']);

        $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->assertSee('Pekerjaan Tampil')->assertSee('Tambah item BQ')->assertSee(route('bq-items.update', $id), false);

        $manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->actingAs($manager)->get("/projects/{$this->project->id}")->assertOk()->assertSee('Pekerjaan Tampil')->assertDontSee('Tambah item BQ')->assertDontSee(route('bq-items.update', $id), false);
    }

    public function test_over_allocation_warning_shows_only_when_unallocated_is_negative(): void
    {
        $url = "/projects/{$this->project->id}";
        $this->actingAs($this->staff)->get($url)->assertOk()->assertDontSee('Over-alokasi');

        $this->item(['baseline_budget' => '570478866.80', 'negotiated_value' => '570478866.80']); // pas = Rp0 belum dialokasikan
        $this->actingAs($this->staff)->get($url)->assertOk()->assertDontSee('Over-alokasi');

        $this->item(['baseline_budget' => '100.50', 'negotiated_value' => '100.00']);
        $this->actingAs($this->staff)->get($url)->assertOk()->assertSee('Over-alokasi Rp100,50.', false)->assertSee('role="status"', false);
    }
}
