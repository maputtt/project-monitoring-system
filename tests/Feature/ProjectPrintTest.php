<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Versi cetak/PDF project: visibilitas sama dengan halaman project, isi read-only, keluaran di-escape.
class ProjectPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_print_page_shows_key_content_and_is_escaped(): void
    {
        $staff = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => 'Budi <b>Cetak</b>']);
        $project = Project::factory()->assignedTo($staff)->active()->create(['project_code' => 'BM-PRINT-1', 'project_name' => 'Menara <script>x</script>']);
        DB::table('bq_items')->insert(['project_id' => $project->id, 'item_name' => 'Pekerjaan Lantai', 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '900.00', 'created_by' => $staff->id]);

        $this->actingAs($staff)->get(route('projects.print', $project))->assertOk()
            ->assertSee('BM-PRINT-1')->assertSee('Pekerjaan Lantai')->assertSee('Rp671.151.608,00')->assertSee('Ringkasan finansial')
            ->assertSee('Dicetak', false)->assertSee('oleh Budi &lt;b&gt;Cetak&lt;/b&gt;', false)
            ->assertDontSee('<script>x</script>', false)->assertSee('Menara &lt;script&gt;x&lt;/script&gt;', false);
        $this->actingAs($staff)->get(route('projects.show', $project))->assertSee(route('projects.print', $project), false);
    }

    public function test_draft_project_with_empty_sections_prints(): void
    {
        $manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $project = Project::factory()->create();

        $this->actingAs($manager)->get(route('projects.print', $project))->assertOk()
            ->assertSee('Belum ada item BQ.')->assertSee('Belum ada jadwal invoice.')->assertSee('Belum ada penyesuaian.')->assertSee('Belum ada relokasi budget.');
    }

    public function test_print_follows_the_same_visibility_as_show(): void
    {
        $project = Project::factory()->create();
        $otherStaff = User::factory()->role('STAFF_PIC', 'BM')->create(); // tidak ditugaskan
        $otherDivision = User::factory()->role('MANAGER_GM', 'CP')->create();

        foreach ([$otherStaff, $otherDivision] as $user) {
            $this->actingAs($user)->get(route('projects.show', $project))->assertNotFound();
            $this->actingAs($user)->get(route('projects.print', $project))->assertNotFound();
        }
        foreach ([User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create()] as $reader) {
            $this->actingAs($reader)->get(route('projects.show', $project))->assertOk();
            $this->actingAs($reader)->get(route('projects.print', $project))->assertOk();
        }
        auth()->logout();
        $this->get(route('projects.print', $project))->assertRedirect('/login');
    }

    public function test_hostile_text_is_escaped_everywhere_including_the_css_footer(): void
    {
        $evil = "Z\"; } </style><script>alert(1)</script> \ ' ü😀
";
        $staff = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => $evil]);
        $project = Project::factory()->assignedTo($staff)->active()->create(['project_code' => 'BM-XSS-1', 'project_name' => $evil]);
        $bq = DB::table('bq_items')->insertGetId(['project_id' => $project->id, 'item_name' => $evil, 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '900.00', 'created_by' => $staff->id]);
        DB::table('bq_budget_mutations')->insert(['project_id' => $project->id, 'destination_bq_id' => $bq, 'mutation_type' => 'FROM_UNALLOCATED_RAB', 'amount' => '10.00', 'reason' => $evil, 'status' => 'REVISION_REQUIRED', 'revision_reason' => $evil, 'submitted_by' => $staff->id, 'submitted_at' => '2026-10-03 10:00:00']);
        DB::table('project_adjustments')->insert(['project_id' => $project->id, 'adjustment_type' => 'TARGET_MARGIN', 'status' => 'REVISION_REQUIRED', 'reason' => $evil, 'revision_reason' => $evil, 'difference_amount' => '5.00', 'submitted_by' => $staff->id, 'submitted_at' => '2026-10-02 09:00:00',
            'old_data' => json_encode(['target_margin_pct' => '10.0000', 'target_margin_amount' => '100.00', 'effective_rab' => '900.00']), 'new_data' => json_encode(['target_margin_pct' => '12.0000', 'target_margin_amount' => '120.00', 'effective_rab' => '880.00'])]);

        $html = $this->actingAs($staff)->get(route('projects.print', $project))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertSame(3, substr_count($html, '</style>')); // @fonts + style halaman + style footer; data tidak menambah penutup
        $this->assertSame(1, substr_count($html, '<script>')); // hanya script cetak milik halaman
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        // Footer margin-box: setiap karakter berbahaya menjadi escape CSS heksadesimal di dalam string.
        $this->assertStringContainsString('content: "BM-XSS-1 \b7  Dicetak', $html);
        $this->assertStringContainsString('oleh Z\22 \3b  \7d  \3c /style\3e \3c script\3e alert\28 1\29 \3c /script\3e  \5c  \27  \fc \1f600 \a "; color', $html);
    }

    public function test_approved_rows_show_the_approver_once_and_keep_show_page_rows_unique(): void
    {
        $staff = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => 'Pengaju']);
        $manager = User::factory()->role('MANAGER_GM', 'BM')->create(['name' => 'Penyetuju Unik']);
        $project = Project::factory()->assignedTo($staff)->active()->create();
        $bq = DB::table('bq_items')->insertGetId(['project_id' => $project->id, 'item_name' => 'Tujuan', 'item_type' => 'NORMAL', 'status' => 'APPROVED', 'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '900.00', 'created_by' => $staff->id]);
        DB::table('bq_budget_mutations')->insert(['project_id' => $project->id, 'destination_bq_id' => $bq, 'mutation_type' => 'FROM_UNALLOCATED_RAB', 'amount' => '10.00', 'reason' => 'm1', 'status' => 'APPROVED', 'submitted_by' => $staff->id, 'approved_by' => $manager->id, 'approved_at' => '2026-10-04 08:00:00', 'submitted_at' => '2026-10-03 10:00:00']);
        DB::table('bq_budget_mutations')->insert(['project_id' => $project->id, 'destination_bq_id' => $bq, 'mutation_type' => 'FROM_UNALLOCATED_RAB', 'amount' => '20.00', 'reason' => 'm2', 'status' => 'SUBMITTED', 'submitted_by' => $staff->id, 'submitted_at' => '2026-10-03 11:00:00']);

        $print = $this->actingAs(User::factory()->role('DIREKSI', null)->create())->get(route('projects.print', $project))->assertOk()->getContent();
        $this->assertSame(1, substr_count($print, 'Penyetuju Unik<'));
        $this->assertSame(2, preg_match_all('/<td>(m1|m2)<\/td>/', $print));
        $this->actingAs($manager)->get(route('projects.show', $project))->assertOk();
    }
}
