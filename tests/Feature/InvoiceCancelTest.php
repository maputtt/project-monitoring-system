<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

// Pembatalan termin PLANNED dan skenario closing end-to-end yang dibuka olehnya bersama arsip BQ.
class InvoiceCancelTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $manager;

    private Project $project;

    private int $planned;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
        $this->planned = $this->invoice(1, 'PLANNED');
    }

    private function invoice(int $seq, string $status): int
    {
        $planned = $status === 'PLANNED';

        return DB::table('invoices')->insertGetId([
            'project_id' => $this->project->id, 'department' => 'BM', 'sequence_number' => $seq, 'billing_type' => 'TERMIN', 'billing_percentage' => '50.0000',
            'planned_amount' => '111.00', 'workflow_status' => $status, 'created_by' => $this->staff->id,
            'submitted_by' => $planned ? null : $this->staff->id, 'submitted_at' => $planned ? null : now(),
            'invoice_number' => $planned ? null : "INV/$seq", 'billing_date' => $planned ? null : '2026-08-20',
            'ppn_rate_snapshot' => $planned ? null : '11.0000', 'pph_rate_snapshot' => $planned ? null : '2.6500',
            'dpp_amount' => $planned ? null : '100.00', 'ppn_amount' => $planned ? null : '11.00', 'subtotal_amount' => $planned ? null : '111.00',
            'pph_amount' => $planned ? null : '3.00', 'net_received_amount' => $planned ? null : '108.00',
            'locked_at' => $status === 'PAID' ? now() : null, 'locked_by' => $status === 'PAID' ? $this->manager->id : null,
        ]);
    }

    private function cancel(?User $user = null, ?int $id = null, mixed $reason = 'Termin digabung'): TestResponse
    {
        return $this->actingAs($user ?? $this->manager)->postJson('/invoices/'.($id ?? $this->planned).'/cancel', ['reason' => $reason]);
    }

    private function row(?int $id = null): object
    {
        return DB::table('invoices')->where('id', $id ?? $this->planned)->first();
    }

    public function test_cancels_a_planned_invoice_with_audit_and_reason(): void
    {
        $this->cancel()->assertRedirect();

        $this->assertSame(['CANCELLED', 2], [$this->row()->workflow_status, (int) $this->row()->version]);
        $audit = DB::table('audit_logs')->where('entity_type', 'INVOICE')->where('entity_id', $this->planned)->where('action', 'UPDATE')->sole();
        $this->assertSame(['Termin digabung', 'PLANNED', 'CANCELLED'], [$audit->reason, json_decode($audit->data_before)->workflow_status, json_decode($audit->data_after)->workflow_status]);
    }

    public function test_reason_is_required_and_bounded(): void
    {
        foreach ([null, '', '   ', str_repeat('a', 1001), ['x']] as $bad) {
            $this->cancel(reason: $bad)->assertStatus(422);
        }
        $this->assertSame('PLANNED', $this->row()->workflow_status);
        $this->cancel(reason: str_repeat('a', 1000))->assertRedirect();
    }

    public function test_any_other_status_is_409_including_cancelled_itself(): void
    {
        foreach (['INVOICE_SUBMITTED', 'INVOICE_REVISION_REQUIRED', 'WAITING_FOR_PAYMENT', 'PAYMENT_SUBMITTED', 'PAID', 'CANCELLED'] as $i => $status) {
            $id = $this->invoice($i + 2, $status);
            $this->cancel(id: $id)->assertStatus(409);
            $this->assertSame($status, $this->row($id)->workflow_status);
        }
        $this->assertSame(0, DB::table('audit_logs')->where('entity_type', 'INVOICE')->count());
    }

    public function test_nothing_can_act_on_a_cancelled_invoice(): void
    {
        $this->cancel()->assertRedirect();
        foreach (['verify', 'request-revision', 'approve-payment', 'unlock'] as $action) {
            $this->actingAs($this->manager)->postJson("/invoices/{$this->planned}/$action", ['reason' => 'x'])->assertStatus(409);
        }
        $this->assertSame('CANCELLED', $this->row()->workflow_status);
    }

    public function test_project_status_gate(): void
    {
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED']);
        $this->cancel()->assertRedirect();
        DB::table('invoices')->where('id', $this->planned)->update(['workflow_status' => 'PLANNED']);
        foreach (['DRAFT', 'SUBMITTED', 'FINANCIALLY_CLOSED'] as $blocked) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $blocked]);
            $this->cancel()->assertStatus(403);
            $this->assertSame('PLANNED', $this->row()->workflow_status);
        }
    }

    public function test_only_the_division_manager_may_cancel(): void
    {
        $this->post("/invoices/{$this->planned}/cancel", ['reason' => 'x'])->assertRedirect('/login');
        foreach ([
            [$this->staff, 403], [User::factory()->role('DIREKSI', null)->create(), 403], [User::factory()->role('SYSTEM_ADMIN', null)->create(), 403],
            [User::factory()->role('MANAGER_GM', 'CP')->create(), 404], [User::factory()->role('STAFF_PIC', 'CP')->create(), 404],
        ] as [$user, $code]) {
            $this->cancel($user)->assertStatus($code);
        }
        $this->cancel(id: 999999)->assertNotFound();
        $this->actingAs($this->manager)->postJson('/invoices/'.str_repeat('9', 21).'/cancel', ['reason' => 'x'])->assertNotFound();
        $this->assertSame('PLANNED', $this->row()->workflow_status);
    }

    public function test_cancelled_invoice_stays_visible_and_the_button_shows_only_for_planned(): void
    {
        $page = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk();
        $this->assertTrue($page->viewData('invoices')->sole()->canCancel);
        $page->assertSee('Batalkan termin');

        $this->cancel()->assertRedirect();
        $page = $this->actingAs($this->manager)->get("/projects/{$this->project->id}")->assertOk();
        $this->assertFalse($page->viewData('invoices')->sole()->canCancel);
        $page->assertSee('Dibatalkan')->assertSee('tidak akan ditagih')->assertDontSee('Batalkan termin');

        $this->assertFalse($this->actingAs($this->staff)->get("/projects/{$this->project->id}")->viewData('invoices')->sole()->canCancel);
    }

    public function test_rolls_back_when_the_audit_write_fails(): void
    {
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into `audit_logs`')) {
                throw new RuntimeException('audit gagal');
            }
        });

        try {
            $this->actingAs($this->manager)->withoutExceptionHandling()->post("/invoices/{$this->planned}/cancel", ['reason' => 'x']);
            $this->fail('Pembatalan seharusnya gagal.');
        } catch (RuntimeException) {
        }

        $this->assertSame(['PLANNED', 1], [$this->row()->workflow_status, (int) $this->row()->version]);
    }

    public function test_closing_unblocks_after_cancel_and_archive_and_excludes_the_archived_bq(): void
    {
        // Effective RAB 179.820.000,00; target margin 30.000.000,00; realisasi satu-satunya BQ yang dihitung 170.000.000,00 → Final Sisa 9.820.000,00.
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'COMPLETED', 'effective_rab' => '179820000.00', 'target_margin_amount' => '30000000.00', 'contract_value' => '335575804.00']); // termin 1 dibatalkan di bawah dan nilai kontrak sudah dikoreksi ke bagian termin 2
        $this->invoice(2, 'PAID');
        $bq = fn (string $status, ?string $actual) => DB::table('bq_items')->insertGetId([
            'project_id' => $this->project->id, 'item_name' => "BQ $status", 'item_type' => 'NORMAL', 'status' => $status, 'created_by' => $this->staff->id, 'actual_cost' => $actual,
            'baseline_budget' => '1000.00', 'effective_budget' => '1000.00', 'negotiated_value' => '800.00', 'margin_amount' => '200.00', 'margin_pct' => '20.0000',
        ]);
        $bq('APPROVED', '170000000.00');
        $abandoned = $bq('DRAFT', '5.00'); // realisasi nyasar pada item yang ditinggalkan tidak boleh ikut Final Sisa

        $this->actingAs($this->manager)->postJson("/projects/{$this->project->id}/close")->assertStatus(422);
        $this->cancel()->assertRedirect();
        $this->actingAs($this->manager)->postJson("/projects/{$this->project->id}/close")->assertStatus(422); // BQ DRAFT masih menahan

        $this->actingAs($this->staff)->postJson("/bq-items/$abandoned/archive", ['reason' => 'Ditinggalkan'])->assertRedirect();
        $this->actingAs($this->manager)->post("/projects/{$this->project->id}/close")->assertRedirect("/projects/{$this->project->id}");

        $project = DB::table('projects')->where('id', $this->project->id)->first();
        $this->assertSame(['FINANCIALLY_CLOSED', '39820000.00'], [$project->status, $project->actual_profit_amount]); // 30.000.000,00 + 9.820.000,00
        $this->assertSame('ARCHIVED', DB::table('bq_items')->where('id', $abandoned)->value('status'));
    }
}
