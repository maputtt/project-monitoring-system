<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\Department;
use App\Enums\Role;
use App\Models\Project;
use App\Support\AuditSentence;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** AUD-01: daftar audit log read-only (UX layar 14). Tabel audit_logs tidak punya project_id, jadi MANAGER_GM dibatasi lewat entitasnya. */
class AuditLogController extends Controller
{
    private const PER_PAGE = 30;

    /** entity_type yang terikat project => tabel penunjuk project_id-nya (null: entity_id adalah id project itu sendiri). */
    private const PROJECT_SCOPED = [
        'PROJECT' => null,
        'PROJECT_ASSIGNMENT' => 'project_user_assignments',
        'PROJECT_ADJUSTMENT' => 'project_adjustments',
        'BQ_ITEM' => 'bq_items',
        'BQ_MUTATION' => 'bq_budget_mutations',
        'INVOICE' => 'invoices',
        'DOCUMENT' => 'documents',
        'PROGRESS' => 'project_progress',
    ];

    /** Bukan project: akun, master, dan tarif. Hanya DIREKSI yang melihatnya. Baris LOGIN lama disembunyikan untuk semua peran. */
    private const NON_PROJECT = ['USER', 'PKS', 'BOUWHEER_CONTACT', 'TAX_RATE'];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'entity_type' => ['nullable', Rule::in([...array_keys(self::PROJECT_SCOPED), ...self::NON_PROJECT])],
            'action' => ['nullable', Rule::enum(AuditAction::class)->except(AuditAction::LOGIN)], // LOGIN tidak dicatat lagi dan disembunyikan
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ], [
            'entity_type.*' => 'Entitas tidak dikenal.', 'action.*' => 'Aksi tidak dikenal.',
            'date_from.*' => 'Tanggal dari tidak valid.', 'date_to.date_format' => 'Tanggal sampai tidak valid.', 'date_to.after_or_equal' => 'Tanggal sampai harus sama atau setelah tanggal dari.',
        ]);
        $user = $request->user();
        // Tanggal filter adalah hari WIB; created_at tersimpan UTC.
        $tz = config('app.local_timezone');
        $from = isset($filters['date_from']) ? Carbon::parse($filters['date_from'], $tz)->startOfDay()->utc() : null;
        $to = isset($filters['date_to']) ? Carbon::parse($filters['date_to'], $tz)->addDay()->startOfDay()->utc() : null;
        $manager = $user->role === Role::MANAGER_GM;
        $department = DashboardController::department($request); // null untuk peran per-divisi: ?department diabaikan
        $canPickDepartment = $user->role === Role::DIREKSI;

        $logs = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where('audit_logs.action', '<>', AuditAction::LOGIN->value) // baris LOGIN lama tetap di database
            ->when($manager, fn ($q) => $this->onlyProjectsIn($q, Project::query()->visibleTo($user)->select('projects.id')))
            ->when($department, fn ($q, $d) => $this->onlyProjectsIn($q, Project::query()->where('projects.department', $d)->select('projects.id'))) // divisi = divisi project baris itu; non-project tereksklusi
            ->when($filters['entity_type'] ?? null, fn ($q, $type) => $q->where('audit_logs.entity_type', $type))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('audit_logs.action', $action))
            ->when($from, fn ($q, $t) => $q->where('audit_logs.created_at', '>=', $t->toDateTimeString()))
            ->when($to, fn ($q, $t) => $q->where('audit_logs.created_at', '<', $t->toDateTimeString()))
            ->orderByDesc('audit_logs.created_at')->orderByDesc('audit_logs.id')
            ->paginate(self::PER_PAGE, ['audit_logs.id', 'audit_logs.created_at', 'audit_logs.action', 'audit_logs.entity_type', 'audit_logs.entity_id', 'audit_logs.reason', 'audit_logs.data_before', 'audit_logs.data_after',
                'users.name as actor_name', 'users.role as actor_role'])->withQueryString();

        AuditSentence::decorate($logs->items());

        return view('audit-logs.index', [
            'logs' => $logs,
            'filters' => $filters + ['department' => $department],
            'actions' => array_filter(AuditAction::cases(), fn ($a) => $a !== AuditAction::LOGIN),
            'entityTypes' => [...array_keys(self::PROJECT_SCOPED), ...self::NON_PROJECT],
            'departments' => $canPickDepartment ? Department::cases() : [],
        ]);
    }

    /** Batasi baris audit ke entitas terikat project yang project-nya ada di subquery $projects. */
    private function onlyProjectsIn($q, $projects)
    {
        return $q->where(function ($any) use ($projects) {
            foreach (self::PROJECT_SCOPED as $type => $table) {
                $any->orWhere(fn ($one) => $one->where('audit_logs.entity_type', $type)->whereIn('audit_logs.entity_id',
                    $table === null ? $projects : DB::table($table)->whereIn('project_id', $projects)->select('id')));
            }
        });
    }
}
