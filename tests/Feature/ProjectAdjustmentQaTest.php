<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// QA independen PRJ-04: celah yang tidak dicakup ProjectAdjustmentTest (siklus HTTP penuh submit lalu approve dengan oracle bilangan bulat,
// timpa old_data/difference yang dirusak, basis guard, dua adjustment berurutan, rollback tiap jalur, input aneh, render semua peran dan status).
class ProjectAdjustmentQaTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create([
            'created_by' => $this->staff->id, 'status' => 'ACTIVE', 'contract_value' => '199800000.00', 'margin_base_amount' => '199800000.00',
            'target_margin_pct' => '12.0000', 'target_margin_amount' => '23976000.00', 'initial_rab' => '175824000.00', 'effective_rab' => '175824000.00',
        ]);
    }

    private function state(): array
    {
        $p = DB::table('projects')->where('id', $this->project->id)->first();

        return [$p->target_margin_pct, $p->target_margin_amount, $p->effective_rab, $p->initial_rab, (int) $p->version];
    }

    private function bq(string $budget, string $status = 'APPROVED'): void
    {
        DB::table('bq_items')->insert(['project_id' => $this->project->id, 'item_name' => 'Item', 'item_type' => 'NORMAL', 'status' => $status, 'created_by' => $this->staff->id,
            'baseline_budget' => $budget, 'effective_budget' => $budget, 'negotiated_value' => '0.00', 'margin_amount' => '0.00', 'margin_pct' => '0.0000']);
    }

    private function audits(?string $action = null): int
    {
        return DB::table('audit_logs')->where('entity_type', 'PROJECT_ADJUSTMENT')->when($action, fn ($q) => $q->where('action', $action))->count();
    }

    private function row(int $id): object
    {
        return DB::table('project_adjustments')->where('id', $id)->first();
    }

    /** Ajukan lewat HTTP (bukan insert langsung) dan kembalikan id-nya. */
    private function submit(string $mode, string $value, ?User $as = null): int
    {
        $this->actingAs($as ?? $this->staff)->post("/projects/{$this->project->id}/adjustments", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => $mode, 'margin_value' => $value, 'reason' => 'Nego'])->assertSessionHasNoErrors();

        return (int) DB::table('project_adjustments')->max('id');
    }

    private function approve(int $id, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->manager)->post("/adjustments/$id/approve");
    }

    private function fmt(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    public function test_full_http_cycle_with_hand_calculated_pct_and_amount_cases(): void
    {
        $this->bq('100000000.00');
        // 12% -> 10%: 199.800.000 x 10% = 19.980.000; RAB 179.820.000. Submit tidak mengubah apa pun.
        $a = $this->submit('PCT', '10');
        $this->assertSame(['12.0000', '23976000.00', '175824000.00', '175824000.00', 1], $this->state());
        $this->approve($a)->assertSessionHasNoErrors();
        $this->assertSame(['10.0000', '19980000.00', '179820000.00', '175824000.00', 2], $this->state());

        // 10% -> Rp27.972.000 (14%): RAB 171.828.000, turun 7.992.000 dari yang sekarang; initial tetap.
        $b = $this->submit('AMOUNT', '27972000');
        $this->approve($b)->assertSessionHasNoErrors();
        $this->assertSame(['14.0000', '27972000.00', '171828000.00', '175824000.00', 3], $this->state());
        $row = $this->row($b);
        $this->assertSame('-7992000.00', $row->difference_amount);
        $this->assertEquals(['target_margin_pct' => '10.0000', 'target_margin_amount' => '19980000.00', 'effective_rab' => '179820000.00'], json_decode($row->old_data, true));
        $this->assertSame([2, 2], [$this->audits('SUBMIT'), $this->audits('APPROVE')]);
        // Pelaku audit: pengaju untuk SUBMIT, manager untuk APPROVE; APPROVE memuat tiga angka sebelum dan sesudah serta status.
        $this->assertSame([$this->staff->id, $this->manager->id, $this->staff->id, $this->manager->id], DB::table('audit_logs')->where('entity_type', 'PROJECT_ADJUSTMENT')->orderBy('id')->pluck('user_id')->map(fn ($v) => (int) $v)->all());
        $log = DB::table('audit_logs')->where('action', 'APPROVE')->orderByDesc('id')->first();
        $this->assertEquals(['target_margin_pct' => '10.0000', 'target_margin_amount' => '19980000.00', 'effective_rab' => '179820000.00', 'status' => 'SUBMITTED'], json_decode($log->data_before, true));
        $this->assertEquals(['target_margin_pct' => '14.0000', 'target_margin_amount' => '27972000.00', 'effective_rab' => '171828000.00', 'status' => 'APPROVED'], json_decode($log->data_after, true));
    }

    public function test_half_up_rounding_against_integer_oracle(): void
    {
        // [margin base, mode, nilai, persen empat desimal (bulat x 10^4), margin sen]. Oracle memakai bilangan bulat, bukan bcmath.
        foreach ([['12345.67', 'PCT', '12.3456'], ['199800000.00', 'AMOUNT', '12345678.91'], ['80000.00', 'AMOUNT', '1.00'], ['10.00', 'PCT', '0.05'], ['3.00', 'AMOUNT', '1.00'], ['0.07', 'PCT', '50']] as [$base, $mode, $value]) {
            DB::table('projects')->where('id', $this->project->id)->update(['margin_base_amount' => $base]);
            $baseC = (int) str_replace('.', '', $base);
            if ($mode === 'PCT') {
                [$i, $f] = array_pad(explode('.', $value), 2, '');
                $pct4 = (int) ($i.str_pad($f, 4, '0'));
                $marginC = intdiv(2 * $baseC * $pct4 + 1_000_000, 2_000_000);
            } else {
                $marginC = (int) str_replace('.', '', bcadd($value, '0', 2));
                $pct4 = intdiv(2 * $marginC * 1_000_000 + $baseC, 2 * $baseC);
            }
            $id = $this->submit($mode, $value);
            $this->approve($id)->assertSessionHasNoErrors();
            $this->assertSame([sprintf('%d.%04d', intdiv($pct4, 10000), $pct4 % 10000), $this->fmt($marginC), $this->fmt($baseC - $marginC), '175824000.00'],
                array_slice($this->state(), 0, 4), "$base $mode $value");
        }
    }

    public function test_approve_rewrites_tampered_difference_and_survives_amount_mode_tamper(): void
    {
        $id = $this->submit('AMOUNT', '27972000');
        DB::table('project_adjustments')->where('id', $id)->update([
            'difference_amount' => '123.00',
            'new_data' => json_encode(['target_margin_pct' => '1.0000', 'target_margin_amount' => '1.00', 'effective_rab' => '1.00', 'margin_mode' => 'AMOUNT', 'margin_value' => '27972000.00']),
        ]);

        $this->approve($id)->assertSessionHasNoErrors();

        $row = $this->row($id);
        $this->assertSame(['14.0000', '27972000.00', '171828000.00', '175824000.00', 2], $this->state());
        $this->assertSame('-3996000.00', $row->difference_amount);
        $this->assertEquals(['target_margin_pct' => '12.0000', 'target_margin_amount' => '23976000.00', 'effective_rab' => '175824000.00'], json_decode($row->old_data, true));
        $this->assertEquals(['target_margin_pct' => '14.0000', 'target_margin_amount' => '27972000.00', 'effective_rab' => '171828000.00', 'margin_mode' => 'AMOUNT', 'margin_value' => '27972000.00'], json_decode($row->new_data, true));
    }

    public function test_approve_is_409_when_project_figures_changed_since_submission(): void
    {
        $id = $this->submit('PCT', '14');
        DB::table('projects')->where('id', $this->project->id)->update(['target_margin_pct' => '13.0000', 'target_margin_amount' => '25974000.00', 'effective_rab' => '173826000.00']);
        $before = $this->state();
        $audits = $this->audits();

        $this->approve($id)->assertStatus(409);

        $this->assertSame([$before, 'SUBMITTED', $audits], [$this->state(), $this->row($id)->status, $this->audits()]);
    }

    public function test_tampered_value_above_margin_base_is_422_not_500_and_changes_nothing(): void
    {
        $id = $this->submit('PCT', '10');
        DB::table('project_adjustments')->where('id', $id)->update(['new_data' => json_encode(['margin_mode' => 'AMOUNT', 'margin_value' => '999999999.00'])]);
        $before = $this->state();

        $this->approve($id)->assertSessionHasErrors('margin_value');

        $this->assertSame([$before, 'SUBMITTED', 0], [$this->state(), $this->row($id)->status, $this->audits('APPROVE')]);
    }

    public function test_guard_sums_all_non_archived_bq_and_rolls_back_every_trace(): void
    {
        // 12% -> 14%: RAB baru 171.828.000,00. APPROVED + CLOSED berjumlah tepat itu; ARCHIVED tidak dihitung (uji coba PM 2026-11-02: DRAFT/SUBMITTED ikut dihitung, lihat BqWorkflowTest).
        $this->bq('100000000.00');
        $this->bq('71828000.00', 'CLOSED');
        $this->bq('500000000.00', 'ARCHIVED');
        $ok = $this->submit('PCT', '14');
        $this->approve($ok)->assertSessionHasNoErrors();
        $this->assertSame(['14.0000', '27972000.00', '171828000.00', '175824000.00', 2], $this->state());

        // Kembali ke keadaan semula lalu naikkan item CLOSED satu sen: ditolak, dan tak ada jejak.
        DB::table('projects')->where('id', $this->project->id)->update(['target_margin_pct' => '12.0000', 'target_margin_amount' => '23976000.00', 'effective_rab' => '175824000.00', 'version' => 1]);
        DB::table('bq_items')->where('status', 'CLOSED')->update(['effective_budget' => '71828000.01']);
        $bad = $this->submit('PCT', '14');
        $oldData = $this->row($bad)->old_data;
        $audits = $this->audits();

        $this->approve($bad)->assertStatus(302)->assertSessionHasErrors('adjustment');
        $this->assertStringContainsString('Sisa RAB tidak cukup: RAB Perubahan baru Rp171.828.000,00 lebih kecil dari total Nilai RAB semua item BQ', session('errors')->first('adjustment'));

        $row = $this->row($bad);
        $this->assertSame(['12.0000', '23976000.00', '175824000.00', '175824000.00', 1], $this->state());
        $this->assertSame(['SUBMITTED', null, null, $oldData, $audits], [$row->status, $row->approved_by, $row->approved_at, $row->old_data, $this->audits()]);
        $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->assertSee('Diajukan', false);
    }

    public function test_two_submitted_adjustments_apply_in_order_with_guard_rechecked_each_time(): void
    {
        $this->bq('172000000.00');
        $low = $this->submit('PCT', '14');   // RAB 171.828.000 < 172.000.000: ditolak
        $mid = $this->submit('PCT', '13');   // RAB 173.826.000: lolos
        $this->approve($low)->assertSessionHasErrors('adjustment');
        $this->approve($mid)->assertSessionHasNoErrors();
        $this->assertSame(['13.0000', '25974000.00', '173826000.00', '175824000.00', 2], $this->state());

        // Angka project sudah bergeser oleh adjustment lain: usulan lama ditolak 409 (muat ulang), walau BQ sudah turun.
        DB::table('bq_items')->update(['effective_budget' => '100000000.00']);
        $this->approve($low)->assertStatus(409);
        $this->assertSame(['13.0000', '25974000.00', '173826000.00', '175824000.00', 2], $this->state());
    }

    public function test_second_manager_approving_the_same_adjustment_gets_409_with_one_audit(): void
    {
        $other = User::factory()->role('MANAGER_GM', 'BM')->create();
        $id = $this->submit('PCT', '10');

        $this->approve($id)->assertSessionHasNoErrors();
        $after = $this->state();
        $this->approve($id, $other)->assertStatus(409);
        $this->actingAs($other)->post("/adjustments/$id/request-revision", ['reason' => 'x'])->assertStatus(409);

        $this->assertSame([$after, 1, $this->manager->id], [$this->state(), $this->audits('APPROVE'), (int) $this->row($id)->approved_by]);
    }

    public function test_resubmit_edges(): void
    {
        $id = $this->submit('PCT', '10');
        $this->actingAs($this->manager)->post("/adjustments/$id/request-revision", ['reason' => 'Naikkan'])->assertSessionHasNoErrors();
        $payload = ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '9', 'reason' => 'Ok'];
        $other = User::factory()->role('STAFF_PIC', 'CP')->create();
        $otherManager = User::factory()->role('MANAGER_GM', 'CP')->create();

        $this->actingAs($other)->put("/adjustments/$id", $payload)->assertNotFound();
        $this->actingAs($otherManager)->put("/adjustments/$id", $payload)->assertNotFound();
        $this->actingAs($this->staff)->put('/adjustments/999999', $payload)->assertNotFound();
        $this->actingAs($this->staff)->put('/adjustments/12345678901234567890', $payload)->assertNotFound();
        $this->actingAs($this->staff)->put("/adjustments/$id", array_replace($payload, ['margin_value' => '101']))->assertSessionHasErrors('margin_value', null, "adjustment-$id");
        $this->assertSame('REVISION_REQUIRED', $this->row($id)->status);

        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED']);
        $this->actingAs($this->staff)->put("/adjustments/$id", $payload)->assertForbidden();
        $this->assertSame('REVISION_REQUIRED', $this->row($id)->status);

        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'ACTIVE']);
        $this->actingAs($this->staff)->put("/adjustments/$id", $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->put("/adjustments/$id", $payload)->assertStatus(409); // sudah SUBMITTED lagi
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'SUBMIT')->where('entity_type', 'PROJECT_ADJUSTMENT')->where('entity_id', $id)->count()); // pengajuan awal + satu ajukan ulang
    }

    public function test_failed_audit_rolls_back_approve_revision_resubmit_and_submit(): void
    {
        $id = $this->submit('PCT', '10');
        $rev = $this->submit('PCT', '9');
        $this->actingAs($this->manager)->post("/adjustments/$rev/request-revision", ['reason' => 'x']);
        $before = [$this->state(), $this->row($id), $this->row($rev), DB::table('project_adjustments')->count(), $this->audits()];

        DB::listen(fn ($query) => str_contains($query->sql, 'insert into `audit_logs`') && throw new \RuntimeException('audit gagal'));

        $this->approve($id)->assertStatus(500);
        $this->actingAs($this->manager)->post("/adjustments/$id/request-revision", ['reason' => 'y'])->assertStatus(500);
        $this->actingAs($this->staff)->put("/adjustments/$rev", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '8', 'reason' => 'z'])->assertStatus(500);
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/adjustments", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => '8', 'reason' => 'z'])->assertStatus(500);

        $this->assertEquals($before, [$this->state(), $this->row($id), $this->row($rev), DB::table('project_adjustments')->count(), $this->audits()]);
        $this->assertSame(['10.0000', 'SUBMITTED'], [json_decode($this->row($id)->new_data, true)['target_margin_pct'], $this->row($id)->status]);
    }

    public function test_odd_but_accepted_numeric_forms_do_not_500(): void
    {
        foreach (['+10', '.5', '10.', '010', '10.0', ' 10 '] as $v) {
            $r = $this->actingAs($this->staff)->post("/projects/{$this->project->id}/adjustments", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'PCT', 'margin_value' => $v, 'reason' => 'r']);
            $this->assertContains($r->status(), [302], "PCT '$v'");
        }
        foreach (['+1000', '.5', '1000.', '1e3', '0x10', '1,000', '१'] as $v) {
            $r = $this->actingAs($this->staff)->post("/projects/{$this->project->id}/adjustments", ['file' => UploadedFile::fake()->create('Nota.pdf', 100, 'application/pdf'), 'margin_mode' => 'AMOUNT', 'margin_value' => $v, 'reason' => 'r']);
            $this->assertContains($r->status(), [302], "AMOUNT '$v'");
        }
        foreach ([['margin_mode' => ['PCT'], 'margin_value' => '1'], ['margin_mode' => 'PCT', 'margin_value' => ['1']], ['margin_mode' => 'PCT', 'margin_value' => '1', 'reason' => ['x']]] as $bad) {
            $r = $this->actingAs($this->staff)->post("/projects/{$this->project->id}/adjustments", $bad + ['reason' => 'r']);
            $this->assertSame(302, $r->status());
            $r->assertSessionHasErrors();
        }
        // Nilai yang diterima tersimpan sebagai desimal bersih.
        foreach (DB::table('project_adjustments')->get() as $a) {
            $n = json_decode($a->new_data, true);
            $this->assertMatchesRegularExpression('/^\d+\.\d{4}$/', $n['target_margin_pct']);
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $n['target_margin_amount']);
        }
    }

    public function test_page_renders_for_every_role_and_project_status_and_escapes_text(): void
    {
        $xss = '<script>alert(1)</script>"><img src=x>';
        foreach (['SUBMITTED', 'APPROVED', 'REVISION_REQUIRED'] as $s) {
            DB::table('project_adjustments')->insert(['project_id' => $this->project->id, 'adjustment_type' => 'TARGET_MARGIN', 'status' => $s, 'reason' => $xss, 'revision_reason' => $xss, 'submitted_by' => $this->staff->id,
                'old_data' => json_encode(['target_margin_pct' => '12.0000', 'target_margin_amount' => '23976000.00', 'effective_rab' => '175824000.00']),
                'new_data' => json_encode(['target_margin_pct' => '10.0000', 'target_margin_amount' => '19980000.00', 'effective_rab' => '179820000.00', 'margin_mode' => 'PCT', 'margin_value' => '10.0000']), 'difference_amount' => '3996000.00']);
        }
        $users = [$this->staff, $this->manager, User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', null)->create()];

        foreach (['ACTIVE', 'COMPLETED', 'FINANCIALLY_CLOSED', 'ARCHIVED', 'CANCELLED'] as $status) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            foreach ($users as $u) {
                $html = $this->actingAs($u)->get("/projects/{$this->project->id}")->assertOk()->getContent();
                $this->assertStringNotContainsString('<script>alert(1)', $html, "{$u->role->value} $status");
                $this->assertStringNotContainsString('<img src=x>', $html);
                $canAct = $status === 'ACTIVE' && $u->role->value === 'MANAGER_GM';
                $this->assertSame($canAct, str_contains($html, '/approve') && str_contains($html, 'adjustments/'), "{$u->role->value} $status approve link");
                $this->assertSame($status === 'ACTIVE' && $u->role->value === 'STAFF_PIC', str_contains($html, 'Ajukan penyesuaian target margin'), "{$u->role->value} $status form");
            }
        }
    }

    public function test_zero_effective_rab_after_100_percent_margin_renders(): void
    {
        $id = $this->submit('PCT', '100');
        $this->approve($id)->assertSessionHasNoErrors();
        $this->assertSame(['100.0000', '199800000.00', '0.00', '175824000.00', 2], $this->state());
        $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk()->assertSee('Rp0,00')->assertSee('Berbeda dari RAB Awal');
        $this->actingAs($this->manager)->get('/projects')->assertOk();
    }

    public function test_effective_rab_card_hint_changes_only_after_approval(): void
    {
        $url = "/projects/{$this->project->id}";
        $id = $this->submit('PCT', '10');
        $this->actingAs($this->manager)->get($url)->assertOk()->assertDontSee('Berbeda dari RAB Awal')->assertSee('Pending, project belum berubah');
        $this->approve($id);
        $this->actingAs($this->manager)->get($url)->assertOk()->assertSee('Berbeda dari RAB Awal: penyesuaian target margin sudah disetujui')->assertDontSee('Pending, project belum berubah')->assertSee('Rp179.820.000,00');
    }
}
