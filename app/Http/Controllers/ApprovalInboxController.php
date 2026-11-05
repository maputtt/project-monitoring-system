<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Approval Inbox (DSH-02): daftar gabungan yang menunggu keputusan Manager/GM di divisinya. Read-only.
 * Keputusan tetap diambil di detail project (modal konfirmasi dan guard no self-approval sudah ada di sana); halaman ini hanya menautkan.
 * Item milik sendiri tetap ditampilkan dengan penanda, karena server menolak keputusannya di detail.
 */
class ApprovalInboxController extends Controller
{
    private const PER_PAGE = 20;

    public const TYPES = [
        'project' => 'Aktivasi project',
        'bq' => 'BQ',
        'invoice' => 'Invoice',
        'payment' => 'Bukti bayar',
        'adjustment' => 'Penyesuaian',
        'mutation' => 'Relokasi Budget',
    ];

    public function index(Request $request): View
    {
        $me = $request->user()->id;
        $ids = Project::query()->visibleTo($request->user())->select('projects.id');
        $pending = fn (string $table, string $statusColumn, array $statuses) => DB::table($table)->join('projects', 'projects.id', '=', "$table.project_id")
            ->whereIn("$table.project_id", $ids)->whereIn("$table.$statusColumn", $statuses);
        $cols = ['projects.id as project_id', 'projects.project_code', 'projects.project_name'];

        // projects, bq_items, dan bukti bayar tidak punya kolom pengaju: pengaju = baris audit SUBMIT (sama seperti guard di detail).
        $projects = DB::table('projects')->whereIn('id', $ids)->where('status', 'SUBMITTED')->get(['id as project_id', 'project_code', 'project_name', 'contract_value']);
        $bqs = $pending('bq_items', 'status', ['SUBMITTED'])->get([...$cols, 'bq_items.id', 'bq_items.item_name', 'bq_items.effective_budget', 'bq_items.created_by']);
        $invoices = $pending('invoices', 'workflow_status', ['INVOICE_SUBMITTED', 'PAYMENT_SUBMITTED'])
            ->get([...$cols, 'invoices.id', 'invoices.workflow_status', 'invoices.sequence_number', 'invoices.invoice_number', 'invoices.subtotal_amount', 'invoices.submitted_by', 'invoices.submitted_at']);
        $adjustments = $pending('project_adjustments', 'status', ['SUBMITTED'])->get([...$cols, 'project_adjustments.adjustment_type', 'project_adjustments.difference_amount', 'project_adjustments.old_data', 'project_adjustments.new_data', 'project_adjustments.submitted_by', 'project_adjustments.submitted_at']);
        $mutations = $pending('bq_budget_mutations', 'status', ['SUBMITTED'])->get([...$cols, 'bq_budget_mutations.amount', 'bq_budget_mutations.submitted_by', 'bq_budget_mutations.submitted_at']);

        $submits = fn (string $type, $rows) => DB::table('audit_logs')->where('action', 'SUBMIT')->where('entity_type', $type)
            ->whereIn('entity_id', $rows->pluck($type === 'PROJECT' ? 'project_id' : 'id'))->orderBy('id')->get(['entity_id', 'user_id', 'created_at'])->groupBy('entity_id');
        $projectSubmits = $submits('PROJECT', $projects);
        $bqSubmits = $submits('BQ_ITEM', $bqs);
        $invoiceSubmits = $submits('INVOICE', $invoices);

        $items = collect();
        $add = fn (string $type, object $r, string $summary, ?string $amount, ?int $by, ?string $at, bool $own, string $anchor = '', ?string $typeLabel = null) => $items->push([
            'type' => $type, 'type_label' => $typeLabel ?? self::TYPES[$type], 'project_id' => $r->project_id, 'project_code' => $r->project_code, 'project_name' => $r->project_name,
            'summary' => $summary, 'amount' => $amount, 'by' => $by, 'at' => $at, 'own' => $own || ($by !== null && (int) $by === $me), 'anchor' => $anchor,
        ]);

        foreach ($projects as $r) {
            $log = $projectSubmits[$r->project_id] ?? collect();
            $add('project', $r, 'Menunggu aktivasi', $r->contract_value, $log->last()?->user_id, $log->last()?->created_at, $log->contains('user_id', $me));
        }
        foreach ($bqs as $r) {
            $log = $bqSubmits[$r->id] ?? collect();
            $add('bq', $r, $r->item_name, $r->effective_budget, $log->last()?->user_id, $log->last()?->created_at, (int) $r->created_by === $me, '#item-bq');
        }
        foreach ($invoices as $r) {
            $label = "Termin #{$r->sequence_number} · ".($r->invoice_number ?? 'Belum bernomor');
            if ($r->workflow_status === 'PAYMENT_SUBMITTED') {
                $log = $invoiceSubmits[$r->id] ?? collect(); // SUBMIT terakhir = pengunggah bukti bayar
                $add('payment', $r, $label, $r->subtotal_amount, $log->last()?->user_id, $log->last()?->created_at, (int) $r->submitted_by === $me, '#jadwal');
            } else {
                $add('invoice', $r, $label, $r->subtotal_amount, $r->submitted_by, $r->submitted_at, false, '#jadwal');
            }
        }
        foreach ($adjustments as $r) {
            $contract = $r->adjustment_type === 'CONTRACT_VALUE';
            // Koreksi Persentase Termin tidak punya selisih rupiah: ringkasannya daftar termin yang dikoreksi, nominal kosong.
            $summary = $r->adjustment_type === 'BILLING_PERCENTAGE' ? 'Termin '.self::changedTermins($r->old_data, $r->new_data) : ($contract ? 'Selisih nilai tertagih' : 'Selisih target margin');
            $add('adjustment', $r, $summary, $r->difference_amount, $r->submitted_by, $r->submitted_at, false, '#penyesuaian', $r->adjustment_type === 'TARGET_MARGIN' ? null : (ProjectAdjustmentController::LABELS[$r->adjustment_type] ?? null)); // jenis tanpa label (RAB, PROJECT_PERIOD) memakai label umum "Penyesuaian"
        }
        foreach ($mutations as $r) {
            $add('mutation', $r, 'Relokasi budget BQ', $r->amount, $r->submitted_by, $r->submitted_at, false, '#relokasi-budget');
        }

        $names = DB::table('users')->whereIn('id', $items->pluck('by')->filter()->unique())->pluck('name', 'id');
        $items = $items->map(fn (array $i) => $i + ['by_name' => $names[$i['by']] ?? null])->sortByDesc('at')->values();
        $page = LengthAwarePaginator::resolveCurrentPage();

        return view('approvals.index', [
            'items' => new LengthAwarePaginator($items->forPage($page, self::PER_PAGE), $items->count(), self::PER_PAGE, $page, ['path' => $request->url()]),
            'counts' => $items->countBy('type'),
            'types' => self::TYPES,
        ]);
    }

    /** Nomor termin yang persentasenya berubah pada usulan Koreksi Persentase Termin (termin yang tetap tidak disebut). */
    private static function changedTermins(string $oldData, string $newData): string
    {
        $old = collect(json_decode($oldData, true)['termins'])->pluck('billing_percentage', 'invoice_id');

        return collect(json_decode($newData, true)['termins'])->filter(fn ($t) => bccomp($old[$t['invoice_id']], $t['billing_percentage'], 4) !== 0)->pluck('sequence_number')->join(', ');
    }
}
