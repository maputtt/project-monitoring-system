<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// PRJ-03 (UX layar 3 dan 5): daftar PKS dan detail project, selalu lewat Project::visibleTo.
class ProjectListTest extends TestCase
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

    public function test_list_shows_only_visible_projects_grouped_by_pks(): void
    {
        $mine = Project::factory()->assignedTo($this->staff)->create(['project_code' => 'MINE-1', 'project_name' => 'Gedung Saya']);
        Project::factory()->create(['project_code' => 'BM-OTHER']);
        Project::factory()->create(['project_code' => 'CP-1', 'department' => 'CP']);

        $this->actingAs($this->staff)->get('/projects')->assertOk()->assertSee('MINE-1')->assertDontSee('BM-OTHER')->assertDontSee('CP-1')
            ->assertSee(DB::table('pks')->where('id', $mine->pks_id)->value('pks_number'));
        $this->actingAs($this->manager)->get('/projects')->assertSee('MINE-1')->assertSee('BM-OTHER')->assertDontSee('CP-1');
        $this->actingAs(User::factory()->role('DIREKSI', null)->create())->get('/projects')->assertSee('BM-OTHER')->assertSee('CP-1');
    }

    public function test_list_is_closed_to_system_admin_and_guests(): void
    {
        $this->get('/projects')->assertRedirect('/login');
        $this->actingAs(User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create())->get('/projects')->assertForbidden();
    }

    public function test_search_and_status_filter(): void
    {
        $a = Project::factory()->assignedTo($this->staff)->create(['project_code' => 'ALFA-1', 'project_name' => 'Menara Biru']);
        Project::factory()->assignedTo($this->staff)->create(['project_code' => 'BETA-2', 'project_name' => 'Pabrik Hijau', 'status' => 'ACTIVE']);
        $this->actingAs($this->staff);

        $this->get('/projects?q=alfa')->assertSee('ALFA-1')->assertDontSee('BETA-2');
        $this->get('/projects?q=Hijau')->assertSee('BETA-2')->assertDontSee('ALFA-1');
        $this->get('/projects?q='.urlencode(DB::table('pks')->where('id', $a->pks_id)->value('pks_number')))->assertSee('ALFA-1')->assertDontSee('BETA-2');
        $this->get('/projects?q=%25')->assertSee('Tidak ada project yang cocok'); // wildcard LIKE diperlakukan literal
        $this->get('/projects?status=ACTIVE')->assertSee('BETA-2')->assertDontSee('ALFA-1');
        $this->get('/projects?status=BUKAN')->assertSessionHasErrors('status');
    }

    public function test_pks_groups_are_paginated(): void
    {
        foreach (range(1, 12) as $i) {
            Project::factory()->assignedTo($this->staff)->create(['project_code' => sprintf('P-%02d', $i)]);
        }
        DB::table('pks')->orderBy('id')->get()->each(fn ($pks, $i) => DB::table('pks')->where('id', $pks->id)->update(['pks_number' => sprintf('PKS-%02d', $i + 1)]));
        $this->actingAs($this->staff);

        $this->get('/projects')->assertSee('P-10')->assertDontSee('P-11');
        $this->get('/projects?page=2')->assertSee('P-11')->assertSee('P-12')->assertDontSee('P-10');
    }

    public function test_detail_shows_the_financial_summary_and_is_read_only_for_system_admin(): void
    {
        $project = Project::factory()->assignedTo($this->staff)->create(['status' => 'SUBMITTED', 'contract_tax_mode' => 'EXCLUSIVE_PPN', 'margin_base_ppn_rate_snapshot' => '11.0000']);
        $contact = DB::table('bouwheer_contacts')->where('id', $project->bouwheer_contact_id)->value('name');
        $bq = fn (array $row) => DB::table('bq_items')->insert($row + ['project_id' => $project->id, 'item_name' => 'BQ', 'created_by' => $this->staff->id]);
        $bq(['item_type' => 'OPERATIONAL', 'status' => 'APPROVED', 'baseline_budget' => '5000.25', 'effective_budget' => '5000.25']);
        $bq(['status' => 'APPROVED', 'baseline_budget' => '90000.00', 'effective_budget' => '100000.50', 'negotiated_value' => '100000.50']);
        $bq(['status' => 'SUBMITTED', 'baseline_budget' => '900.00', 'effective_budget' => '1000.10', 'negotiated_value' => '1000.10']);
        $bq(['status' => 'ARCHIVED', 'effective_budget' => '999999.00', 'negotiated_value' => '999999.00']);

        // Official = 570.478.866,80 - 100.000,50 - 5.000,25; Projected = Official - 1.000,10; Profit = 100.672.741,20 + Projected.
        $this->actingAs($this->staff)->get("/projects/{$project->id}")->assertOk()
            ->assertSee('Rp570.372.865,95')->assertSee('Rp671.045.607,15')
            ->assertSee('Belum termasuk PPN (PPN 11%)', false)->assertSee('PT Klien')->assertSee(e($contact), false)
            ->assertSee('Rp106.000,85') // total effective BQ (BQ ARCHIVED tidak dihitung)
            ->assertDontSee('Aktivasi')->assertDontSee('Minta revisi');

        $this->actingAs($this->manager)->get("/projects/{$project->id}")->assertSee('Aktivasi')->assertSee('Minta revisi');
        $this->actingAs(User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create())->get("/projects/{$project->id}")->assertOk()
            ->assertSee('Rp570.372.865,95')->assertDontSee('Aktivasi')->assertDontSee('Minta revisi')->assertDontSee('Ubah draft');
        $this->actingAs(User::factory()->role('DIREKSI', null)->create())->get("/projects/{$project->id}")->assertOk();
        $this->actingAs(User::factory()->role('MANAGER_GM', 'CP')->create())->get("/projects/{$project->id}")->assertNotFound();
    }

    // --- QA independen PRJ-03 ---

    public function test_summary_matches_prd_5_8_with_a_hand_calculated_case(): void
    {
        // effective_rab 1.000.000,00; target margin 150.000,00; margin base 1.150.000,00.
        $project = Project::factory()->assignedTo($this->staff)->create(['status' => 'ACTIVE', 'margin_base_amount' => '1150000.00', 'target_margin_amount' => '150000.00', 'target_margin_pct' => '13.0435', 'initial_rab' => '1000000.00', 'effective_rab' => '1000000.00']);
        $bq = fn (string $type, string $status, string $baseline, string $effective, string $nego) => DB::table('bq_items')->insert(['project_id' => $project->id, 'item_name' => 'BQ', 'created_by' => $this->staff->id, 'item_type' => $type, 'status' => $status, 'baseline_budget' => $baseline, 'effective_budget' => $effective, 'negotiated_value' => $nego]);
        $bq('NORMAL', 'APPROVED', '180000.00', '190000.00', '200000.10'); // committed memakai nilai nego, bukan budget
        $bq('NORMAL', 'SUBMITTED', '40000.00', '60000.00', '50000.05');   // pending
        $bq('NORMAL', 'DRAFT', '7000.00', '8000.00', '7777.77');          // hanya masuk total BQ
        $bq('NORMAL', 'REVISION_REQUIRED', '3000.00', '4000.00', '3333.33'); // tidak memengaruhi saldo
        $bq('OPERATIONAL', 'APPROVED', '20000.20', '20000.20', '0.00');   // reserve

        // Official = 1.000.000,00 - 200.000,10 - 20.000,20 = 779.999,70; Projected = 779.999,70 - 50.000,05 = 729.999,65
        // Profit = 150.000,00 + 729.999,65 = 879.999,65; total effective BQ = 282.000,20; belum dialokasikan = 717.999,80
        $this->actingAs($this->manager)->get("/projects/{$project->id}")->assertOk()
            ->assertSee('Rp50.000,05')->assertDontSee('Rp729.999,65')->assertSee('Rp879.999,65')
            ->assertSee('Rp282.000,20')->assertSee('Rp717.999,80')->assertSee('Rp200.000,10')->assertSee('Rp20.000,20')
            ->assertSee('Projected Profit')->assertDontSee('Actual Profit')
            // Kartu Sisa RAB = belum dialokasikan; Total Nilai BQ memuat Σ Vendor/Subkon item NORMAL (200.000,10 + 50.000,05 + 7.777,77 + 3.333,33) dan Realisasi.
            ->assertSeeInOrder(['Sisa RAB', 'Rp717.999,80', 'Total Nilai BQ', 'Rp282.000,20', 'Vendor/Subkon Rp261.111,25 · Realisasi Rp0,00'])->assertDontSee('RAB Belum Dialokasikan');

        // Setelah closing (actual_profit terisi): Sisa Akhir (Tutup Buku) = 160.000,00 - 150.000,00 = 10.000,00.
        DB::table('projects')->where('id', $project->id)->update(['status' => 'FINANCIALLY_CLOSED', 'actual_profit_amount' => '160000.00']);
        $this->get("/projects/{$project->id}")->assertSee('Actual Profit')->assertSee('Rp160.000,00')->assertSee('Sisa Akhir (Tutup Buku)')->assertSee('Rp10.000,00')->assertDontSee('Projected Profit');
    }

    public function test_project_text_is_escaped_on_list_and_detail(): void
    {
        $xss = '<script>alert(1)</script>';
        $project = Project::factory()->assignedTo($this->staff)->create(['project_name' => $xss.'N', 'location' => $xss.'L']);
        DB::table('pks')->where('id', $project->pks_id)->update(['name' => $xss.'K']);
        DB::table('bouwheer_contacts')->where('id', $project->bouwheer_contact_id)->update(['name' => $xss.'C']);

        foreach (["/projects/{$project->id}", '/projects'] as $url) {
            $this->actingAs($this->staff)->get($url)->assertOk()->assertDontSee($xss, false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        }
    }

    public function test_other_divisions_never_leak_through_pks_names_search_or_page_slots(): void
    {
        $mine = Project::factory()->assignedTo($this->staff)->create(['project_code' => 'MINE-1']);
        // 11 PKS yang hanya berisi project divisi CP: tidak boleh memakan slot halaman 1, tampil, atau bisa dicari.
        foreach (range(1, 11) as $i) {
            $other = Project::factory()->create(['department' => 'CP', 'project_code' => "CPX-$i", 'project_name' => "Rahasia CP $i"]);
            DB::table('pks')->where('id', $other->pks_id)->update(['pks_number' => sprintf('AAA-CP-%02d', $i), 'name' => "PKS Rahasia $i"]);
        }
        $this->actingAs($this->staff);

        $this->get('/projects')->assertOk()->assertSee('MINE-1')->assertDontSee('Rahasia')->assertDontSee('AAA-CP')->assertDontSee('page=2', false);
        $this->get('/projects?q=AAA-CP')->assertDontSee('AAA-CP-')->assertDontSee('PKS Rahasia')->assertSee('Tidak ada project yang cocok');
        $this->get('/projects?q=Rahasia&status=DRAFT')->assertDontSee('CPX-1')->assertSee('Tidak ada project yang cocok');
        $this->actingAs($this->manager)->get('/projects?q=CPX')->assertSee('Tidak ada project yang cocok');
        $this->actingAs(User::factory()->role('DIREKSI', null)->create())->get('/projects?q=CPX-1')->assertSee('AAA-CP-01')->assertSee('Rahasia CP 1');
    }

    public function test_search_treats_underscore_backslash_and_long_or_array_input_safely(): void
    {
        Project::factory()->assignedTo($this->staff)->create(['project_code' => 'ABC-1', 'project_name' => 'Proyek 50%_baik\\x']);
        Project::factory()->assignedTo($this->staff)->create(['project_code' => 'ZZZ-9', 'project_name' => 'Proyek biasa']);
        $this->actingAs($this->staff);

        $this->get('/projects?q='.urlencode('50%_baik\\x'))->assertSee('ABC-1')->assertDontSee('ZZZ-9');
        $this->get('/projects?q=_')->assertSee('ABC-1')->assertDontSee('ZZZ-9'); // "_" literal, bukan satu karakter bebas
        $this->get('/projects?q='.urlencode('\\'))->assertSee('ABC-1')->assertDontSee('ZZZ-9');
        $this->get('/projects?q='.str_repeat('a', 101))->assertSessionHasErrors('q');
        $this->get('/projects?q[]=x')->assertSessionHasErrors('q');
        $this->get('/projects?status[]=DRAFT')->assertSessionHasErrors('status');
        $this->get('/projects?status=draft')->assertSessionHasErrors('status');
        $this->get('/projects?page=999')->assertOk()->assertSee('Belum ada project');
    }

    public function test_pagination_keeps_filters_and_never_splits_a_pks(): void
    {
        $shared = Project::factory()->assignedTo($this->staff)->create(['project_code' => 'S-1']);
        foreach (['S-2', 'S-3'] as $code) {
            Project::factory()->assignedTo($this->staff)->create(['project_code' => $code, 'pks_id' => $shared->pks_id]);
        }
        foreach (range(1, 10) as $i) {
            Project::factory()->assignedTo($this->staff)->create(['project_code' => sprintf('Q-%02d', $i)]);
        }
        DB::table('pks')->orderBy('id')->get()->each(fn ($pks, $i) => DB::table('pks')->where('id', $pks->id)->update(['pks_number' => sprintf('PKS-%02d', $i + 1)]));
        $this->actingAs($this->staff);

        $this->get('/projects?status=DRAFT')->assertSee('S-2')->assertSee('S-3')->assertSee('status=DRAFT&amp;page=2', false)->assertDontSee('Q-10');
        $this->get('/projects?status=DRAFT&page=2')->assertSee('Q-10')->assertDontSee('S-2')->assertDontSee('S-3');
    }

    public function test_detail_buttons_follow_status_and_submitter(): void
    {
        $project = Project::factory()->assignedTo($this->staff)->create();
        $this->actingAs($this->staff)->get("/projects/{$project->id}")->assertSee('Submit untuk aktivasi')->assertSee('Ubah draft');
        $this->actingAs($this->manager)->get("/projects/{$project->id}")->assertDontSee('Submit untuk aktivasi');

        DB::table('projects')->where('id', $project->id)->update(['status' => 'SUBMITTED']);
        DB::table('audit_logs')->insert(['user_id' => $this->manager->id, 'action' => 'SUBMIT', 'entity_type' => 'PROJECT', 'entity_id' => $project->id, 'request_id' => 'x']);
        $this->actingAs($this->manager)->get("/projects/{$project->id}")->assertDontSee('Aktivasi')->assertSee('Minta revisi'); // pengaju tidak bisa aktivasi
        $this->actingAs($this->staff)->get("/projects/{$project->id}")->assertDontSee('Submit untuk aktivasi')->assertDontSee('Ubah draft');
    }
}
