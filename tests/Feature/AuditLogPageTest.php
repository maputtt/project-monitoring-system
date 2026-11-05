<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// AUD-01 (UX layar 14): audit log read-only. MANAGER_GM hanya project divisinya; DIREKSI dan SYSTEM_ADMIN semuanya.
class AuditLogPageTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $staff;

    private Project $bm;

    private Project $cp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->manager = User::factory()->role('MANAGER_GM', 'BM')->create(['name' => 'Manajer Budi']);
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => 'Staf Rina']);
        $this->bm = Project::factory()->assignedTo($this->staff)->create();
        $this->cp = Project::factory()->create(['department' => 'CP']);
    }

    private function log(string $type, int $entityId, string $reason, string $action = 'UPDATE', ?User $by = null, string $at = '2026-10-10 09:00:00'): void
    {
        DB::table('audit_logs')->insert(['user_id' => ($by ?? $this->staff)->id, 'action' => $action, 'entity_type' => $type, 'entity_id' => $entityId, 'reason' => $reason, 'created_at' => $at]);
    }

    private function bq(Project $project): int
    {
        return DB::table('bq_items')->insertGetId(['project_id' => $project->id, 'item_name' => 'Item', 'created_by' => $this->staff->id]);
    }

    private function invoice(Project $project): int
    {
        return DB::table('invoices')->insertGetId(['project_id' => $project->id, 'department' => $project->department->value, 'sequence_number' => 1, 'billing_type' => 'TERMIN',
            'planned_amount' => '111.00', 'workflow_status' => 'PLANNED', 'created_by' => $this->staff->id]);
    }

    /** Satu baris per jenis entitas: milik divisi BM, milik divisi CP, dan yang bukan project. */
    private function seedLogs(): void
    {
        $this->log('PROJECT', $this->bm->id, 'proyek-bm');
        $this->log('BQ_ITEM', $this->bq($this->bm), 'bq-bm');
        $this->log('INVOICE', $this->invoice($this->bm), 'invoice-bm');
        $assignment = DB::table('project_user_assignments')->where('project_id', $this->bm->id)->value('id');
        $this->log('PROJECT_ASSIGNMENT', $assignment, 'tim-bm');
        $this->log('PROJECT', $this->cp->id, 'proyek-cp');
        $this->log('BQ_ITEM', $this->bq($this->cp), 'bq-cp');
        $this->log('INVOICE', $this->invoice($this->cp), 'invoice-cp');
        $this->log('USER', 1, 'login-sistem', 'LOGIN');
        $this->log('USER', $this->bm->id, 'kelola-user-bentrok-id'); // entity_id sama dengan id project BM, tapi bukan project
    }

    public function test_direksi_and_system_admin_see_everything(): void
    {
        $this->seedLogs();
        foreach (['DIREKSI' => null, 'SYSTEM_ADMIN' => 'ITBS'] as $role => $dept) {
            $this->actingAs(User::factory()->role($role, $dept)->create())->get('/audit-logs')->assertOk()
                ->assertSee(['proyek-bm', 'bq-bm', 'invoice-bm', 'tim-bm', 'proyek-cp', 'bq-cp', 'invoice-cp', 'kelola-user-bentrok-id'])->assertDontSee('login-sistem'); // baris LOGIN lama disembunyikan
        }
    }

    public function test_manager_sees_only_project_logs_of_own_division(): void
    {
        $this->seedLogs();

        $this->actingAs($this->manager)->get('/audit-logs')->assertOk()
            ->assertSee(['proyek-bm', 'bq-bm', 'invoice-bm', 'tim-bm'])
            ->assertDontSee(['proyek-cp', 'bq-cp', 'invoice-cp', 'login-sistem', 'kelola-user-bentrok-id']);
        $this->actingAs($this->manager)->get('/audit-logs?entity_type=INVOICE')->assertSee('invoice-bm')->assertDontSee('invoice-cp');
        $this->actingAs($this->manager)->get('/audit-logs?entity_type=USER')->assertSee('Tidak ada catatan');
    }

    public function test_staff_forbidden_and_guest_redirected(): void
    {
        $this->get('/audit-logs')->assertRedirect('/login');
        $this->actingAs($this->staff)->get('/audit-logs')->assertForbidden();
    }

    public function test_date_filter_uses_wib_days_not_utc(): void
    {
        $this->log('PROJECT', $this->bm->id, 'lewat-tengah-malam-wib', 'UPDATE', $this->staff, '2026-10-16 17:30:00'); // 2026-10-17 00:30 WIB
        $this->actingAs(User::factory()->role('DIREKSI', null)->create());

        $this->get('/audit-logs?date_from=2026-10-17&date_to=2026-10-17')->assertSee('lewat-tengah-malam-wib');
        $this->get('/audit-logs?date_from=2026-10-16&date_to=2026-10-16')->assertDontSee('lewat-tengah-malam-wib');
    }

    public function test_filters_combine_and_invalid_values_are_rejected(): void
    {
        $other = User::factory()->role('STAFF_PIC', 'BM')->create(['name' => 'Staf Lain']);
        $this->log('PROJECT', $this->bm->id, 'a-rina-update', 'UPDATE', $this->staff, '2026-10-01 08:00:00');
        $this->log('PROJECT', $this->bm->id, 'b-rina-submit', 'SUBMIT', $this->staff, '2026-10-05 16:59:59'); // 23:59:59 WIB
        $this->log('INVOICE', $this->invoice($this->bm), 'c-lain-submit', 'SUBMIT', $other, '2026-10-05 17:00:00'); // 00:00:00 WIB hari berikutnya
        $this->actingAs(User::factory()->role('DIREKSI', null)->create());

        $this->get('/audit-logs?user_id='.$this->staff->id)->assertSee(['a-rina-update', 'b-rina-submit'])->assertDontSee('c-lain-submit');
        $this->get('/audit-logs?action=SUBMIT')->assertSee(['b-rina-submit', 'c-lain-submit'])->assertDontSee('a-rina-update');
        $this->get('/audit-logs?entity_type=INVOICE')->assertSee('c-lain-submit')->assertDontSee(['a-rina-update', 'b-rina-submit']);
        $this->get('/audit-logs?date_from=2026-10-05&date_to=2026-10-05')->assertSee('b-rina-submit')->assertDontSee(['a-rina-update', 'c-lain-submit']); // date_to inklusif
        $this->get('/audit-logs?user_id='.$this->staff->id.'&action=SUBMIT&date_from=2026-10-02')->assertSee('b-rina-submit')->assertDontSee(['a-rina-update', 'c-lain-submit']);

        $this->get('/audit-logs?user_id=999999')->assertSessionHasErrors('user_id');
        $this->get('/audit-logs?entity_type=BUKAN')->assertSessionHasErrors('entity_type');
        $this->get('/audit-logs?action=BUKAN')->assertSessionHasErrors('action');
        $this->get('/audit-logs?date_from=2026-10-06&date_to=2026-10-05')->assertSessionHasErrors('date_to');
        $this->get('/audit-logs?date_from=besok')->assertSessionHasErrors('date_from');
    }

    public function test_newest_first_pagination_keeps_filters_and_empty_state_and_escaping(): void
    {
        $this->actingAs(User::factory()->role('SYSTEM_ADMIN', 'ITBS')->create())->get('/audit-logs')->assertSee('Belum ada catatan audit');
        foreach (range(1, 31) as $i) {
            $this->log('PROJECT', $this->bm->id, sprintf('baris-%02d', $i), 'UPDATE', null, sprintf('2026-10-%02d 10:00:00', $i));
        }
        $this->log('PROJECT', $this->bm->id, '<b>xss</b>', 'APPROVE', null, '2026-09-01 10:00:00');

        $this->get('/audit-logs?entity_type=PROJECT')->assertSee('baris-31')->assertSee('baris-02')->assertDontSee('baris-01')->assertSee('entity_type=PROJECT', false)->assertSee('page=2', false);
        $this->get('/audit-logs?entity_type=PROJECT&page=2')->assertSee('baris-01')->assertDontSee('baris-31')->assertSee('&lt;b&gt;xss&lt;/b&gt;', false)->assertDontSee('<b>xss</b>', false);
    }
}
