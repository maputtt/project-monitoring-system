<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// QA tahap 2: subform halaman project dalam popup. Galat server membuka ulang hanya popup yang gagal, tanpa banner galat halaman ganda.
class ProjectPopupQaTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE_BANNER = 'mb-5 flex items-start gap-3 rounded-2xl border border-rose-200'; // banner layout; popup memakai rounded-xl

    private User $staff;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
    }

    private function bq(string $status, string $name): int
    {
        return DB::table('bq_items')->insertGetId([
            'project_id' => $this->project->id, 'item_name' => $name, 'item_type' => 'NORMAL', 'status' => $status, 'created_by' => $this->staff->id,
            'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '800.00', 'margin_amount' => '200.00', 'margin_pct' => '20.0000',
        ]);
    }

    public function test_each_failed_subform_reopens_only_its_own_popup_without_a_page_banner(): void
    {
        $draft = $this->bq('DRAFT', 'QA Popup draf');
        $approved = $this->bq('APPROVED', 'QA Popup disetujui');
        $this->bq('APPROVED', 'QA Popup lain'); // item lain: popupnya tidak boleh ikut terbuka
        $page = "/projects/{$this->project->id}";
        $cases = [
            ['bq-new', 'post', "$page/bq-items", ['item_name' => '', '_modal' => 'bq-new']],
            ["bq-$draft", 'put', "/bq-items/$draft", ['item_name' => '', 'baseline_budget' => 'abc', 'negotiated_value' => '1', 'version' => 1, '_modal' => "bq-$draft"]],
            ["actual-$approved", 'put', "/bq-items/$approved/actual-cost", ['actual_cost' => 'xyz', 'version' => 1, '_modal' => "actual-$approved"]],
            ['mutation-new', 'post', "$page/budget-mutations", ['mutation_type' => 'FROM_UNALLOCATED_RAB', 'amount' => 'abc', '_modal' => 'mutation-new']],
            ['adjust-new', 'post', "$page/adjustments", ['margin_mode' => 'PCT', 'margin_value' => 'zz', '_modal' => 'adjust-new']],
            ['upload-doc', 'post', "$page/documents", ['document_type' => 'OTHER', '_modal' => 'upload-doc']],
        ];

        foreach ($cases as [$modal, $method, $url, $data]) {
            $html = $this->actingAs($this->staff)->from($page)->followingRedirects()->{$method}($url, $data);
            $this->assertSame([$modal], $this->openModals($html->getContent()), $modal);
            $html->assertDontSee(self::PAGE_BANNER, false);
        }
    }

    public function test_relocation_popup_shows_its_hint_once(): void
    {
        $this->bq('APPROVED', 'QA Popup disetujui');
        $html = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->getContent();

        $this->assertSame(1, substr_count($html, 'Mengajukan tidak mengubah saldo'));
    }

    public function test_manager_and_direksi_get_no_form_popup_on_the_project_page(): void
    {
        $this->bq('APPROVED', 'QA Popup disetujui');
        foreach (['MANAGER_GM', 'DIREKSI'] as $role) {
            $user = User::factory()->role($role, 'BM')->create();
            $html = $this->actingAs($user)->get("/projects/{$this->project->id}")->getContent();
            $this->assertSame(0, substr_count($html, 'aria-haspopup="dialog"'), $role);
            $this->assertStringNotContainsString('name="_modal"', $html, $role);
        }
    }
}
