<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// status tampil berbahasa Indonesia (nilai enum tetap untuk filter/API) dan waktu tampil WIB (penyimpanan UTC).
class IndonesianDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_list_shows_indonesian_status_label_but_keeps_enum_values_for_filters(): void
    {
        $this->withoutVite();
        $staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $project = Project::factory()->assignedTo($staff)->create();
        DB::table('invoices')->insert(['project_id' => $project->id, 'department' => 'BM', 'sequence_number' => 1, 'billing_type' => 'TERMIN',
            'planned_amount' => '111.00', 'billing_percentage' => '100.0000', 'workflow_status' => 'WAITING_FOR_PAYMENT', 'created_by' => $staff->id, 'due_date' => '2026-11-01',
            'invoice_number' => 'INV/1', 'billing_date' => '2026-09-01', 'ppn_rate_snapshot' => '11.0000', 'pph_rate_snapshot' => '2.6500', 'subtotal_amount' => '111.00', 'net_received_amount' => '108.00']);

        $this->actingAs($staff)->get('/invoices')->assertOk()
            ->assertSee('Menunggu Pembayaran')
            ->assertDontSee('>WAITING_FOR_PAYMENT<', false) // tidak ada teks mentah di badge maupun opsi filter
            ->assertSee('value="WAITING_FOR_PAYMENT"', false); // nilai opsi filter tidak berubah
    }

    public function test_audit_log_time_is_shown_in_wib_while_stored_utc(): void
    {
        $this->withoutVite();
        $manager = User::factory()->role('MANAGER_GM', 'BM')->create();
        $project = Project::factory()->create();
        DB::table('audit_logs')->insert(['user_id' => $manager->id, 'action' => 'UPDATE', 'entity_type' => 'PROJECT', 'entity_id' => $project->id, 'reason' => 'uji waktu', 'created_at' => '2026-10-16 17:30:00']);

        // 17:30 UTC = 00:30 WIB hari berikutnya.
        $this->actingAs($manager)->get('/audit-logs')->assertOk()->assertSee('17/10/2026 00:30 WIB');
        $this->assertSame('2026-10-16 17:30:00', DB::table('audit_logs')->value('created_at'));
    }
}
