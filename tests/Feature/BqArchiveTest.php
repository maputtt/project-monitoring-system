<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

// Arsip item BQ yang ditinggalkan: tanpa hard delete, alasan wajib, tercatat di audit.
class BqArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Project $project;

    private int $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
        $this->draft = $this->bq('DRAFT');
    }

    private function bq(string $status, string $type = 'NORMAL'): int
    {
        return DB::table('bq_items')->insertGetId([
            'project_id' => $this->project->id, 'item_name' => "Item $status", 'item_type' => $type, 'status' => $status, 'created_by' => $this->staff->id,
            'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '800.00', 'margin_amount' => '200.00', 'margin_pct' => '20.0000',
        ]);
    }

    private function archive(?User $user = null, ?int $id = null, mixed $reason = 'Pekerjaan batal'): TestResponse
    {
        return $this->actingAs($user ?? $this->staff)->postJson('/bq-items/'.($id ?? $this->draft).'/archive', ['reason' => $reason]);
    }

    private function row(?int $id = null): object
    {
        return DB::table('bq_items')->where('id', $id ?? $this->draft)->first();
    }

    public function test_archives_draft_and_revision_required_with_audit_and_reason(): void
    {
        $revision = $this->bq('REVISION_REQUIRED');

        foreach ([$this->draft, $revision] as $id) {
            $this->archive(id: $id, reason: 'Pekerjaan batal')->assertRedirect();
            $this->assertSame(['ARCHIVED', 2], [$this->row($id)->status, (int) $this->row($id)->version]);
        }

        $audit = DB::table('audit_logs')->where('entity_type', 'BQ_ITEM')->where('entity_id', $this->draft)->where('action', 'UPDATE')->sole();
        $this->assertSame(['Pekerjaan batal', 'DRAFT', 'ARCHIVED'], [$audit->reason, json_decode($audit->data_before)->status, json_decode($audit->data_after)->status]);
    }

    public function test_reason_is_required_and_bounded(): void
    {
        foreach ([null, '', '   ', str_repeat('a', 1001), ['x']] as $bad) {
            $this->archive(reason: $bad)->assertStatus(422);
        }
        $this->assertSame('DRAFT', $this->row()->status);
        $this->archive(reason: str_repeat('a', 1000))->assertRedirect();
    }

    public function test_other_item_statuses_and_operational_are_409(): void
    {
        foreach (['SUBMITTED', 'APPROVED', 'CLOSED', 'ARCHIVED'] as $status) {
            $id = $this->bq($status);
            $this->archive(id: $id)->assertStatus(409);
            $this->assertSame($status, $this->row($id)->status);
        }
        $operational = $this->bq('DRAFT', 'OPERATIONAL');
        $this->archive(id: $operational)->assertStatus(409);
        $this->assertSame('DRAFT', $this->row($operational)->status);
        $this->assertSame(0, DB::table('audit_logs')->where('entity_type', 'BQ_ITEM')->count());
    }

    public function test_project_status_gate(): void
    {
        foreach (['DRAFT', 'COMPLETED'] as $ok) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $ok]);
            DB::table('bq_items')->where('id', $this->draft)->update(['status' => 'DRAFT']);
            $this->archive()->assertRedirect();
        }
        DB::table('bq_items')->where('id', $this->draft)->update(['status' => 'DRAFT']);
        foreach (['SUBMITTED', 'FINANCIALLY_CLOSED'] as $blocked) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $blocked]);
            $this->archive()->assertStatus(403);
            $this->assertSame('DRAFT', $this->row()->status);
        }
    }

    public function test_only_the_assigned_staff_may_archive(): void
    {
        $this->post("/bq-items/{$this->draft}/archive", ['reason' => 'x'])->assertRedirect('/login');
        foreach ([
            [User::factory()->role('MANAGER_GM', 'BM')->create(), 403], [User::factory()->role('DIREKSI', null)->create(), 403], [User::factory()->role('SYSTEM_ADMIN', null)->create(), 403],
            [User::factory()->role('STAFF_PIC', 'BM')->create(), 404], // satu divisi, tidak ditugaskan: di luar jangkauan
            [User::factory()->role('STAFF_PIC', 'CP')->create(), 404],
        ] as [$user, $code]) {
            $this->archive($user)->assertStatus($code);
        }
        $this->archive(id: 999999)->assertNotFound();
        $this->archive(id: (int) str_repeat('9', 18))->assertNotFound();
        $this->actingAs($this->staff)->postJson('/bq-items/'.str_repeat('9', 21).'/archive', ['reason' => 'x'])->assertNotFound();
        $this->assertSame('DRAFT', $this->row()->status);
    }

    public function test_archived_item_leaves_allocation_and_shows_as_history(): void
    {
        $this->archive()->assertRedirect();
        $page = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk();

        $this->assertSame('0.00', $page->viewData('summary')['bq_effective']);
        $this->assertCount(0, $page->viewData('bqItems'));
        $page->assertSee('Item BQ diarsipkan (1)', false)->assertSee('Item DRAFT');
    }

    public function test_rolls_back_when_the_audit_write_fails(): void
    {
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into `audit_logs`')) {
                throw new RuntimeException('audit gagal');
            }
        });

        try {
            $this->actingAs($this->staff)->withoutExceptionHandling()->post("/bq-items/{$this->draft}/archive", ['reason' => 'x']);
            $this->fail('Arsip seharusnya gagal.');
        } catch (RuntimeException) {
        }

        $this->assertSame(['DRAFT', 1], [$this->row()->status, (int) $this->row()->version]);
    }
}
