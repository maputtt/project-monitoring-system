<?php

namespace App\Http\Controllers;

use App\Enums\Department;
use App\Models\Project;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Dashboard per role (DSH-01, AC-23). Read-only; semua angka dihitung dari Project::visibleTo.
 * STAFF_PIC dan MANAGER_GM terkunci pada divisinya (?department diabaikan); DIREKSI dan SYSTEM_ADMIN boleh memfilter satu divisi.
 * Kartu dan grafik menaut ke daftar: halaman yang sudah ada (invoice, approvals) atau DashboardListController.
 */
class DashboardController extends Controller
{
    /** Invoice outstanding = tagihan yang sudah dihitung pajaknya (subtotal) tetapi belum PAID/CANCELLED; PLANNED belum punya subtotal. */
    private const SETTLED = ['PAID', 'CANCELLED'];

    /** Aturan 6A: DRAFT, SUBMITTED, dan CANCELLED tidak masuk angka KPI. */
    public const ACTIVATED = ['ACTIVE', 'COMPLETED', 'FINANCIALLY_CLOSED', 'ARCHIVED'];

    /** Calon kontrak: project yang belum diaktifkan dan belum dibatalkan. */
    public const PROSPECT = ['DRAFT', 'SUBMITTED'];

    /** Revisi B: grafik hanya project yang masih berjalan (belum tutup buku). */
    public const OPEN = ['ACTIVE', 'COMPLETED'];

    /** Margin Realisasi: project yang sudah tutup buku (ARCHIVED hanya bila pernah ditutup, yaitu actual_profit_amount terisi). */
    public const CLOSED = ['FINANCIALLY_CLOSED', 'ARCHIVED'];

    /** Divisi yang dipilih: peran terkunci divisi mengabaikan ?department; DIREKSI dan SYSTEM_ADMIN boleh memfilter satu divisi. Dipakai juga oleh daftar drill-down. */
    public static function department(Request $request): ?string
    {
        return $request->user()->role->isDivisionScoped() ? null : $request->validate(['department' => ['nullable', Rule::enum(Department::class)]])['department'] ?? null;
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $department = self::department($request);

        $visible = Project::query()->visibleTo($user)->when($department, fn ($q, $d) => $q->where('projects.department', $d));
        $allIds = (clone $visible)->select('projects.id'); // semua status: antrean persetujuan harus ikut menghitung project SUBMITTED
        $ids = (clone $visible)->whereIn('projects.status', self::ACTIVATED)->select('projects.id'); // angka uang hanya dari project yang sudah diaktifkan
        $openIds = (clone $visible)->whereIn('projects.status', self::OPEN)->select('projects.id'); // grafik: hanya yang masih berjalan

        $p = DB::table('projects')->whereIn('id', $ids)
            ->selectRaw("COUNT(*) AS n, COALESCE(SUM(status = 'ACTIVE'), 0) AS active, COALESCE(SUM(status = 'COMPLETED'), 0) AS completed, COALESCE(SUM(initial_contract_value), 0) AS contract, COALESCE(SUM(effective_rab), 0) AS rab, COALESCE(SUM(target_margin_amount), 0) AS margin, COALESCE(SUM(margin_base_amount), 0) AS base")->first();
        $realized = DB::table('projects')->whereIn('id', (clone $visible)->whereIn('projects.status', self::CLOSED)->whereNotNull('projects.actual_profit_amount')->select('projects.id'))
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(actual_profit_amount), 0) AS profit, COALESCE(SUM(margin_base_amount), 0) AS base')->first();
        // Calon kontrak: DRAFT dan SUBMITTED (CANCELLED tidak); sengaja di luar $ids dan KPI di atas, yang hanya menghitung project teraktivasi.
        $prospects = (clone $visible)->whereIn('projects.status', self::PROSPECT)->toBase()->selectRaw('COUNT(*) AS n, COALESCE(SUM(projects.initial_contract_value), 0) AS total')->first();
        $today = now(config('app.local_timezone'))->toDateString();
        $settled = "'".implode("','", self::SETTLED)."'";
        $outstandingSql = "COALESCE(SUM(CASE WHEN workflow_status NOT IN ($settled) THEN subtotal_amount END), 0) AS outstanding";
        $kpiInvoices = DB::table('invoices')->whereIn('project_id', $ids)
            ->selectRaw("$outstandingSql, COALESCE(SUM(CASE WHEN workflow_status = 'PAID' THEN net_received_amount END), 0) AS nett")->first();
        $i = DB::table('invoices')->whereIn('project_id', $openIds)
            ->selectRaw("$outstandingSql, COALESCE(SUM(CASE WHEN workflow_status = 'PAID' THEN subtotal_amount END), 0) AS paid, COALESCE(SUM(CASE WHEN workflow_status = 'PLANNED' THEN planned_amount END), 0) AS planned")->first();

        // Alokasi RAB seperti ringkasan project (PRD 5.8): committed = APPROVED/CLOSED (NORMAL = nilai nego, OPERATIONAL = effective budget), pending = SUBMITTED; ARCHIVED/DRAFT tidak dihitung.
        $cost = "CASE WHEN item_type = 'OPERATIONAL' THEN effective_budget ELSE negotiated_value END";
        $bq = DB::table('bq_items')->whereIn('project_id', $openIds)
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('APPROVED','CLOSED') THEN $cost END), 0) AS committed, COALESCE(SUM(CASE WHEN status = 'SUBMITTED' THEN $cost END), 0) AS pending")->first();
        $rab = Money::add(DB::table('projects')->whereIn('id', $openIds)->selectRaw('COALESCE(SUM(effective_rab), 0) AS total')->value('total'));
        $outstanding = Money::add($i->outstanding);
        $pipelineTotal = Money::add($i->paid, $outstanding, $i->planned);

        // Menunggu keputusan Manager/GM: project, item BQ, invoice (verifikasi dan pembayaran), adjustment, dan relokasi budget yang SUBMITTED.
        $pending = DB::table('projects')->whereIn('id', $allIds)->where('status', 'SUBMITTED')->count()
            + DB::table('bq_items')->whereIn('project_id', $allIds)->where('status', 'SUBMITTED')->count()
            + DB::table('invoices')->whereIn('project_id', $allIds)->whereIn('workflow_status', ['INVOICE_SUBMITTED', 'PAYMENT_SUBMITTED'])->count()
            + DB::table('project_adjustments')->whereIn('project_id', $allIds)->where('status', 'SUBMITTED')->count()
            + DB::table('bq_budget_mutations')->whereIn('project_id', $allIds)->where('status', 'SUBMITTED')->count();

        return view('dashboard', [
            'kpi' => [
                'activated' => (int) $p->n,
                'projects' => (int) $p->active,
                'completed' => (int) $p->completed,
                'contract' => Money::add($p->contract),
                'rab' => Money::add($p->rab),
                'margin' => Money::add($p->margin),
                'margin_pct' => Money::ratioPct($p->margin, $p->base, 2), // rata-rata tertimbang: Σ target margin / Σ dasar margin
                'outstanding' => Money::add($kpiInvoices->outstanding),
                'nett' => Money::add($kpiInvoices->nett),
                'realized_count' => (int) $realized->n,
                'realized' => Money::add($realized->profit),
                'realized_pct' => Money::ratioPct($realized->profit, $realized->base, 2),
                'overbudget' => DB::table('bq_items')->whereIn('project_id', $ids)->where('is_overbudget', true)->where('status', '<>', 'ARCHIVED')->count(),
                'pending' => $pending,
            ],
            'prospects' => ['count' => (int) $prospects->n, 'total' => Money::add($prospects->total)],
            // Termin terlambat ditagih (definisi: InvoiceController::lateToBill), lingkup project teraktivasi seperti KPI; tautan: /invoices?late_to_bill=1.
            'late_to_bill' => InvoiceController::lateToBill(DB::table('invoices')->whereIn('invoices.project_id', $ids), $today)->count(),
            'issues' => InvoiceController::activeIssues()->whereIn('pp.project_id', $ids)->count(), // kendala aktif per termin; daftar: /dashboard/kendala
            // Ringkasan visual (read-only, hanya project berjalan): batang alokasi RAB dan alur tagihan.
            'allocation' => ['rab' => $rab, 'committed' => Money::add($bq->committed), 'pending' => Money::add($bq->pending), 'available' => Money::sub($rab, Money::add($bq->committed, $bq->pending))]
                + $this->usage($rab, $bq->committed, $bq->pending),
            'pipeline' => [
                'paid' => Money::add($i->paid), 'outstanding' => $outstanding, 'planned' => Money::add($i->planned),
                'paid_pct' => Money::ratioPct($i->paid, $pipelineTotal), 'outstanding_pct' => Money::ratioPct($outstanding, $pipelineTotal), 'planned_pct' => Money::ratioPct($i->planned, $pipelineTotal),
            ],
            'departments' => $user->role->isDivisionScoped() ? null : Department::cases(),
            'department' => $department,
        ]);
    }

    /**
     * Porsi batang alokasi RAB dalam persen (2 desimal, string): committed lalu pending, dipotong agar jumlahnya tidak melewati 100.
     * `over` = committed + pending melebihi RAB (RAB nol dengan biaya juga dianggap over).
     *
     * @return array{committed_pct: string, pending_pct: string, over: bool}
     */
    private function usage(string $rab, string $committed, string $pending): array
    {
        $cost = Money::add($committed, $pending);
        if (Money::compare($rab, 0) === 0) {
            // Seluruh batang di sisi yang benar-benar berbiaya: committed bila ada, selain itu pending.
            $hasCommitted = Money::compare($committed, 0) > 0;
            $full = Money::compare($cost, 0) > 0 ? '100.00' : '0.00';

            return ['committed_pct' => $hasCommitted ? $full : '0.00', 'pending_pct' => $hasCommitted ? '0.00' : $full, 'over' => Money::compare($cost, 0) > 0];
        }
        $used = Money::ratioPct($committed, $rab, 2);
        $used = Money::compare($used, 100) > 0 ? '100.00' : $used;
        $room = Money::sub('100', $used);
        $pend = Money::ratioPct($pending, $rab, 2);

        return ['committed_pct' => $used, 'pending_pct' => Money::compare($pend, $room) > 0 ? $room : $pend, 'over' => Money::compare($cost, $rab) > 0];
    }
}
