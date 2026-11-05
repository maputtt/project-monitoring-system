<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Daftar drill-down dari kartu dashboard (Revisi B). Read-only; selalu lewat Project::visibleTo dan filter divisi dashboard.
 * Invoice dan antrean persetujuan memakai halaman yang sudah ada (/invoices, /approvals); di sini hanya daftar yang belum punya halaman.
 */
class DashboardListController extends Controller
{
    private const PER_PAGE = 20;

    /** @var array<string, array{0: string, 1: list<string>, 2: string}> group => [judul, status project, keterangan] */
    public const GROUPS = [
        'activated' => ['Nilai Project', DashboardController::ACTIVATED, 'Project yang sudah diaktifkan, termasuk yang selesai dan tutup buku. Dasar kartu Nilai Project.'],
        'active' => ['Project Aktif/Berjalan', ['ACTIVE'], 'Project yang sedang berjalan.'],
        'completed' => ['Project Selesai', ['COMPLETED'], 'Sudah selesai, belum tutup buku.'],
        'prospects' => ['Calon Kontrak', DashboardController::PROSPECT, 'Draf dan menunggu aktivasi; tidak termasuk angka KPI.'],
        'open' => ['Project Berjalan dan Selesai', DashboardController::OPEN, 'Dasar grafik Alokasi RAB dan Alur Tagihan: belum tutup buku.'],
    ];

    private function visible(Request $request, ?string $department)
    {
        return Project::query()->visibleTo($request->user())->when($department, fn ($q, $d) => $q->where('projects.department', $d));
    }

    public function projects(Request $request, string $group): View
    {
        $department = DashboardController::department($request);
        [$title, $statuses, $hint] = self::GROUPS[$group];

        return view('dashboard-lists.projects', [
            'title' => $title, 'hint' => $hint, 'department' => $department,
            'projects' => $this->visible($request, $department)->whereIn('projects.status', $statuses)->orderBy('projects.project_code')
                ->paginate(self::PER_PAGE, ['projects.id', 'projects.project_code', 'projects.project_name', 'projects.department', 'projects.status', 'projects.initial_contract_value', 'projects.effective_rab', 'projects.target_margin_amount'])->withQueryString(),
        ]);
    }

    /** Item BQ overbudget: lingkup sama dengan kartu (project teraktivasi, bukan ARCHIVED). Sisa = Nilai RAB - (realisasi bila terisi, selain itu Nilai Vendor/Subkon). */
    public function overbudget(Request $request): View
    {
        $department = DashboardController::department($request);
        $ids = $this->visible($request, $department)->whereIn('projects.status', DashboardController::ACTIVATED)->select('projects.id');
        $items = DB::table('bq_items')->join('projects', 'projects.id', '=', 'bq_items.project_id')
            ->whereIn('bq_items.project_id', $ids)->where('bq_items.is_overbudget', true)->where('bq_items.status', '<>', 'ARCHIVED')
            ->orderBy('projects.project_code')->orderBy('bq_items.id')
            ->paginate(self::PER_PAGE, ['projects.id as project_id', 'projects.project_code', 'projects.project_name', 'bq_items.id', 'bq_items.item_code', 'bq_items.item_name', 'bq_items.effective_budget', 'bq_items.negotiated_value', 'bq_items.actual_cost'])->withQueryString()
            ->through(fn ($row) => tap($row, fn ($r) => $r->sisa = Money::sub($r->effective_budget, $r->actual_cost ?? $r->negotiated_value)));

        return view('dashboard-lists.overbudget', ['items' => $items, 'department' => $department]);
    }

    /** Kendala aktif: termin yang progres penagihan terbarunya bertanda kendala (definisi: InvoiceController::activeIssues); lingkup project teraktivasi seperti kartu. */
    public function kendala(Request $request): View
    {
        $department = DashboardController::department($request);
        $ids = $this->visible($request, $department)->whereIn('projects.status', DashboardController::ACTIVATED)->select('projects.id');
        $items = InvoiceController::activeIssues()->whereIn('pp.project_id', $ids)
            ->join('projects', 'projects.id', '=', 'pp.project_id')->join('users', 'users.id', '=', 'pp.created_by')
            ->orderBy('projects.project_code')->orderBy('invoices.sequence_number')
            ->paginate(self::PER_PAGE, ['projects.id as project_id', 'projects.project_code', 'projects.project_name', 'invoices.sequence_number', 'pp.stage', 'pp.description', 'pp.progress_date', 'users.name as author'])->withQueryString();

        return view('dashboard-lists.kendala', ['items' => $items, 'department' => $department]);
    }

    /** Margin Realisasi: project tutup buku (FINANCIALLY_CLOSED, atau ARCHIVED yang pernah ditutup) dengan actual_profit_amount; persen tertimbang atas Σ dasar margin. */
    public function margin(Request $request): View
    {
        $department = DashboardController::department($request);
        $closed = $this->visible($request, $department)->whereIn('projects.status', DashboardController::CLOSED)->whereNotNull('projects.actual_profit_amount');
        $totals = DB::table('projects')->whereIn('id', (clone $closed)->select('projects.id'))
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(initial_contract_value), 0) AS contract, COALESCE(SUM(target_margin_amount), 0) AS target, COALESCE(SUM(actual_profit_amount), 0) AS actual, COALESCE(SUM(margin_base_amount), 0) AS base')->first();

        return view('dashboard-lists.margin', [
            'totals' => $totals, 'department' => $department,
            'projects' => $closed->orderBy('projects.project_code')
                ->paginate(self::PER_PAGE, ['projects.id', 'projects.project_code', 'projects.project_name', 'projects.department', 'projects.status', 'projects.initial_contract_value', 'projects.margin_base_amount', 'projects.target_margin_amount', 'projects.actual_profit_amount'])->withQueryString(),
        ]);
    }
}
