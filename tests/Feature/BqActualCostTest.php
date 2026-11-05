<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// BQ-03 (PRD 5.8, AC-27): realisasi biaya (actual_cost) pada item APPROVED; perubahan beralasan, diaudit dengan nilai lama dan baru.
class BqActualCostTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
    }

    private function bq(string $status = 'APPROVED', string $type = 'NORMAL'): int
    {
        return DB::table('bq_items')->insertGetId([
            'project_id' => $this->project->id, 'item_name' => "Item $type", 'item_type' => $type, 'status' => $status, 'created_by' => $this->staff->id,
            'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '800.00', 'margin_amount' => '200.00', 'margin_pct' => '20.0000',
        ]);
    }

    private function row(int $id): object
    {
        return DB::table('bq_items')->where('id', $id)->first();
    }

    private function fill(int $id, array $data, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->staff)->put("/bq-items/$id/actual-cost", $data + ['version' => $this->row($id)->version]);
    }

    private function audits(): int
    {
        return DB::table('audit_logs')->where('entity_type', 'BQ_ITEM')->where('action', 'UPDATE')->count();
    }

    public function test_first_fill_needs_no_reason_and_change_needs_reason_with_old_and_new_audited(): void
    {
        foreach (['NORMAL', 'OPERATIONAL'] as $type) { // kedua tipe boleh
            $id = $this->bq('APPROVED', $type);
            $this->fill($id, ['actual_cost' => '123.46'])->assertRedirect("/projects/{$this->project->id}")->assertSessionHasNoErrors();
            $this->assertSame(['123.46', 2], [$this->row($id)->actual_cost, (int) $this->row($id)->version]);
        }
        $first = DB::table('audit_logs')->where('entity_type', 'BQ_ITEM')->where('entity_id', $id)->first();
        $this->assertSame([null, '123.46', 2], [json_decode($first->data_before)->actual_cost, json_decode($first->data_after)->actual_cost, json_decode($first->data_after)->version]);
        $this->assertNull($first->reason);

        $this->fill($id, ['actual_cost' => '200'])->assertSessionHasErrors('reason', errorBag: "bq-$id");
        $this->fill($id, ['actual_cost' => '200', 'reason' => '   '])->assertSessionHasErrors('reason', errorBag: "bq-$id");
        $this->assertSame(['123.46', 2], [$this->row($id)->actual_cost, (int) $this->row($id)->version]);

        $before = $this->audits();
        $this->fill($id, ['actual_cost' => '200', 'reason' => 'Tagihan vendor akhir'])->assertSessionHasNoErrors();
        $this->assertSame(['200.00', 3, $before + 1], [$this->row($id)->actual_cost, (int) $this->row($id)->version, $this->audits()]);
        $last = DB::table('audit_logs')->where('entity_id', $id)->orderByDesc('id')->first();
        $this->assertSame(['123.46', '200.00', 'Tagihan vendor akhir'], [json_decode($last->data_before)->actual_cost, json_decode($last->data_after)->actual_cost, $last->reason]);
    }

    public function test_same_value_is_a_noop_without_version_or_audit_and_zero_is_allowed(): void
    {
        $id = $this->bq();
        $this->fill($id, ['actual_cost' => '0'])->assertSessionHasNoErrors();
        $this->assertSame('0.00', $this->row($id)->actual_cost);

        $before = $this->audits();
        $this->fill($id, ['actual_cost' => '0.00'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([2, $before], [(int) $this->row($id)->version, $this->audits()]);
    }

    public function test_invalid_amounts_are_rejected(): void
    {
        $id = $this->bq();
        foreach (['-0.01', '1000000000000000', '1.234', 'abc', '', '1e3', '999999999999999.99'] as $bad) {
            $this->fill($id, ['actual_cost' => $bad])->assertSessionHasErrors('actual_cost', errorBag: "bq-$id");
        }
        $this->fill($id, ['actual_cost' => '5', 'reason' => str_repeat('a', 1001)])->assertSessionHasErrors('reason', errorBag: "bq-$id");
        $this->assertSame([null, 0], [$this->row($id)->actual_cost, $this->audits()]);
        $this->fill($id, ['actual_cost' => '999999999999999'])->assertSessionHasNoErrors();
    }

    public function test_only_approved_items_in_open_projects_and_nothing_else_changes(): void
    {
        foreach (['DRAFT', 'SUBMITTED', 'REVISION_REQUIRED', 'CLOSED', 'ARCHIVED'] as $status) {
            $id = $this->bq($status);
            $this->fill($id, ['actual_cost' => '10'])->assertStatus(409);
            $this->assertNull($this->row($id)->actual_cost);
        }
        $id = $this->bq();
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'FINANCIALLY_CLOSED']);
        $this->fill($id, ['actual_cost' => '10'])->assertForbidden();
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED']);
        $this->assertSame(0, $this->audits());

        $figures = (array) $this->row($id);
        $rab = Project::find($this->project->id)->effective_rab;
        $this->fill($id, ['actual_cost' => '999999'])->assertSessionHasNoErrors();
        $after = (array) $this->row($id);
        foreach (['baseline_budget', 'effective_budget', 'negotiated_value', 'margin_amount', 'margin_pct', 'status'] as $field) {
            $this->assertEquals($figures[$field], $after[$field], $field);
        }
        $this->assertSame(1, (int) $after['is_overbudget']); // satu-satunya angka turunan yang ikut: realisasi 999.999 > RAB 1.000
        $this->assertEquals($rab, Project::find($this->project->id)->effective_rab);
        $summary = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->viewData('summary');
        $this->assertSame('1600.00', $summary['committed']); // nilai nego item APPROVED + CLOSED (CLS-01: CLOSED tetap dihitung), bukan realisasi
    }

    public function test_noop_ignores_reason_stale_version_beats_noop_and_first_fill_keeps_a_given_reason(): void
    {
        $id = $this->bq();
        $this->fill($id, ['actual_cost' => '10', 'reason' => '  awal  '])->assertSessionHasNoErrors();
        $this->assertSame('awal', DB::table('audit_logs')->where('entity_id', $id)->where('action', 'UPDATE')->value('reason'));

        $before = $this->audits();
        $this->fill($id, ['actual_cost' => '10.00', 'reason' => 'diabaikan'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->putJson("/bq-items/$id/actual-cost", ['actual_cost' => '10', 'version' => 1])->assertStatus(409); // versi basi menang atas no-op
        $this->assertSame([$before, 2], [$this->audits(), (int) $this->row($id)->version]);

        $this->fill($id, ['actual_cost' => '0', 'reason' => str_repeat('é', 1000)])->assertSessionHasNoErrors(); // batas 1000 karakter, turun ke 0 diperbolehkan
        $this->assertSame(['0.00', 3], [$this->row($id)->actual_cost, (int) $this->row($id)->version]);
    }

    public function test_failed_submit_rerenders_form_with_escaped_reason_and_summary_is_untouched(): void
    {
        $id = $this->bq();
        $page = "/projects/{$this->project->id}";
        $summary = $this->actingAs($this->staff)->get($page)->viewData('summary');
        $this->fill($id, ['actual_cost' => '123456'])->assertSessionHasNoErrors();
        $this->assertSame(Arr::except($summary, 'bq_actual'), Arr::except($this->actingAs($this->staff)->get($page)->viewData('summary'), 'bq_actual')); // seluruh ringkasan sama, kecuali total Realisasi yang memang ikut berubah

        $html = $this->actingAs($this->staff)->followingRedirects()->from($page)->put("/bq-items/$id/actual-cost", ['actual_cost' => '-5', 'reason' => '<script>x</script>', 'version' => 2])->assertOk();
        $html->assertSee('Nilai tidak boleh negatif.')->assertSee('&lt;script&gt;x&lt;/script&gt;', false)->assertDontSee('<script>x</script>', false);
        $this->actingAs($this->staff)->putJson("/bq-items/$id/actual-cost", ['actual_cost' => '-5', 'version' => 2])->assertStatus(422)->assertJsonValidationErrors('actual_cost');
    }

    public function test_authorization_scope_unknown_ids_and_guest(): void
    {
        $id = $this->bq();
        $this->fill($id, ['actual_cost' => '1'], User::factory()->role('MANAGER_GM', 'BM')->create())->assertForbidden();
        $this->fill($id, ['actual_cost' => '1'], User::factory()->role('DIREKSI', null)->create())->assertForbidden();
        $this->fill($id, ['actual_cost' => '1'], User::factory()->role('STAFF_PIC', 'BM')->create())->assertNotFound(); // tidak ditugaskan
        $this->fill($id, ['actual_cost' => '1'], User::factory()->role('STAFF_PIC', 'CP')->create())->assertNotFound();
        $this->fill($id, ['actual_cost' => '1'], User::factory()->role('MANAGER_GM', 'CP')->create())->assertNotFound();
        $this->actingAs($this->staff)->put('/bq-items/999999/actual-cost', ['actual_cost' => '1', 'version' => 1])->assertNotFound();
        $this->actingAs($this->staff)->put('/bq-items/99999999999999999999/actual-cost', ['actual_cost' => '1', 'version' => 1])->assertNotFound();
        $this->assertNull($this->row($id)->actual_cost);

        $this->assertContains($this->fill($id, ['actual_cost' => '1'], User::factory()->role('SYSTEM_ADMIN', null)->create())->getStatusCode(), [403, 404]);

        $this->app['auth']->forgetGuards();
        $this->putJson("/bq-items/$id/actual-cost", ['actual_cost' => '1', 'version' => 1])->assertUnauthorized();
        $this->put("/bq-items/$id/actual-cost", ['actual_cost' => '1', 'version' => 1])->assertRedirect('/login');
        $this->assertSame([null, 0], [$this->row($id)->actual_cost, $this->audits()]);
    }

    public function test_stale_version_is_rejected_without_changes(): void
    {
        $id = $this->bq();
        $this->actingAs($this->staff)->put("/bq-items/$id/actual-cost", ['actual_cost' => '5', 'version' => 9])->assertRedirect()->assertSessionHasErrors('version', errorBag: "bq-$id");
        $this->actingAs($this->staff)->putJson("/bq-items/$id/actual-cost", ['actual_cost' => '5', 'version' => 9])->assertStatus(409);
        $this->assertSame([null, 1, 0], [$this->row($id)->actual_cost, (int) $this->row($id)->version, $this->audits()]);
    }

    public function test_project_page_offers_the_form_only_to_staff_on_approved_items(): void
    {
        $id = $this->bq();
        $this->bq('DRAFT');
        $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->assertSee('Isi realisasi biaya')->assertSee('name="_modal" value="actual-'.$id.'"', false);
        $this->actingAs(User::factory()->role('MANAGER_GM', 'BM')->create())->get("/projects/{$this->project->id}")->assertOk()->assertDontSee('Isi realisasi biaya');

        $this->fill($id, ['actual_cost' => '1500'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertSee('Ubah realisasi biaya')->assertSee('Rp1.500,00');
    }
}
