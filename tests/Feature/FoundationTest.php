<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\AuditAction;
use App\Enums\BqStatus;
use App\Enums\Department;
use App\Enums\InvoiceStatus;
use App\Enums\PksStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    /** Nilai enum di kode harus persis sama dengan daftar di CHECK constraint database. */
    #[DataProvider('enumsAndConstraints')]
    public function test_enum_values_match_database_check_constraint(string $enum, string $constraint): void
    {
        $clause = DB::selectOne('select check_clause c from information_schema.check_constraints where constraint_schema = database() and constraint_name = ?', [$constraint])->c;
        preg_match_all("/'([A-Z_]+)\\\\?'/", $clause, $m);
        $inDatabase = $m[1];
        $inCode = array_column($enum::cases(), 'value');
        sort($inDatabase);
        sort($inCode);

        $this->assertSame($inCode, $inDatabase, "{$enum} berbeda dari {$constraint}");
    }

    public static function enumsAndConstraints(): array
    {
        return [
            [Role::class, 'chk_users_role'],
            [Department::class, 'chk_projects_department'],
            [ProjectStatus::class, 'chk_projects_status'],
            [BqStatus::class, 'chk_bq_items_status'],
            [InvoiceStatus::class, 'chk_invoices_status'],
            [ApprovalStatus::class, 'chk_adjustments_status'],
            [ApprovalStatus::class, 'chk_mutations_status'],
            [AuditAction::class, 'chk_audit_logs_action'],
            [PksStatus::class, 'chk_pks_status'],
        ];
    }

    public function test_user_role_and_department_are_enums(): void
    {
        $user = User::factory()->role('MANAGER_GM', 'CP')->create()->fresh();

        $this->assertSame(Role::MANAGER_GM, $user->role);
        $this->assertSame(Department::CP, $user->department);
        $this->assertNull(User::factory()->role('DIREKSI', null)->create()->fresh()->department);
    }

    public function test_layout_shows_logo_and_menu_for_the_role(): void
    {
        $this->withoutVite();

        $this->get('/ui-kit')->assertOk()
            ->assertSee('images/logo.svg')
            ->assertSee('PKS &amp; Projects', false)
            ->assertSee('Tarif Pajak')
            ->assertDontSee('Approvals')
            ->assertSee('Rp120.928.217,66') // kartu nilai memakai Money
            ->assertSee('Revisi Invoice'); // badge menampilkan label Indonesia

        $this->get('/ui-kit?role=DIREKSI')->assertOk()->assertSee('Audit Log')->assertDontSee('Tarif Pajak');
        $this->get('/ui-kit?role=SYSTEM_ADMIN')->assertOk()->assertSee('Pengguna')->assertSee('Audit Log')->assertDontSee('Data Master')->assertDontSee('Finance &amp; Invoicing', false);
        $this->get('/ui-kit?role=BUKAN_ROLE')->assertOk()->assertSee('Tarif Pajak'); // nilai tak dikenal jatuh ke Staff/PIC
    }

    public function test_layout_shows_the_signed_in_user(): void
    {
        $this->withoutVite();
        $user = User::factory()->role('MANAGER_GM', 'BM')->create(['name' => 'Budi']);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Budi')->assertSee('Manager/GM')->assertSee('Divisi BM')
            ->assertSee('Approvals')->assertSee('Keluar'); // menu mengikuti role user yang login
    }
}
