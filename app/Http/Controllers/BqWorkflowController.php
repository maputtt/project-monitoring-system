<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\BqStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\InvalidTransition;
use App\Models\Project;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Submit, approve, dan minta revisi item BQ (BQ-02). Hak: contribute untuk submit, approve (Manager/GM) untuk keputusan; status dari peta transisi enum. */
class BqWorkflowController extends Controller
{
    /** Status project yang boleh menjalankan workflow BQ: RAB terkunci sejak aktivasi, jadi bukan DRAFT. */
    public const WORKFLOW_PROJECT_STATUSES = [ProjectStatus::ACTIVE, ProjectStatus::COMPLETED];

    private const AUDITED = ['status', 'approved_by', 'approved_at', 'version'];

    public function submit(Request $request, int $bq): RedirectResponse
    {
        return $this->move($request, $bq, 'contribute', BqStatus::SUBMITTED, AuditAction::SUBMIT, 'Item BQ diajukan. Nilainya masuk Pending Biaya BQ.');
    }

    public function approve(Request $request, int $bq): RedirectResponse
    {
        return $this->move($request, $bq, 'approve', BqStatus::APPROVED, AuditAction::APPROVE, 'Item BQ disetujui.');
    }

    public function requestRevision(Request $request, int $bq): RedirectResponse
    {
        $this->load($request, $bq, 'approve'); // 404/403 sebelum validasi alasan
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Alasan revisi wajib diisi.', 'reason.string' => 'Alasan revisi tidak valid.', 'reason.max' => 'Alasan revisi maksimal :max karakter.'])['reason'];

        return $this->move($request, $bq, 'approve', BqStatus::REVISION_REQUIRED, AuditAction::REQUEST_REVISION, 'Item BQ dikembalikan untuk direvisi.', $reason);
    }

    /**
     * Arsipkan item BQ yang ditinggalkan (DRAFT/REVISION_REQUIRED → ARCHIVED), alasan wajib. Tidak ada hapus; ARCHIVED sudah keluar dari alokasi, guard RAB, dan syarat closing.
     * Status project mengikuti aturan edit BQ (DRAFT/ACTIVE/COMPLETED), bukan WORKFLOW_PROJECT_STATUSES.
     */
    public function archive(Request $request, int $bq): RedirectResponse
    {
        [$project] = $this->load($request, $bq, 'contribute'); // 404/403 sebelum validasi alasan
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Alasan pengarsipan wajib diisi.', 'reason.string' => 'Alasan pengarsipan tidak valid.', 'reason.max' => 'Alasan pengarsipan maksimal :max karakter.'])['reason'];

        DB::transaction(function () use ($project, $bq, $reason) {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            $row = DB::table('bq_items')->where('id', $bq)->lockForUpdate()->first();
            abort_if($row === null || (int) $row->project_id !== $locked->id, 404);
            abort_unless(in_array($locked->status, BqItemController::OPEN_PROJECT_STATUSES, true), 403, 'Item BQ hanya bisa diarsipkan saat project '.ProjectStatus::labelList(BqItemController::OPEN_PROJECT_STATUSES).'.');
            abort_if($row->item_type === 'OPERATIONAL', 409, 'BQ Operasional tidak bisa diarsipkan.');
            try {
                $to = BqStatus::from($row->status)->transitionTo(BqStatus::ARCHIVED);
            } catch (InvalidTransition $e) {
                abort(409, $e->getMessage());
            }

            DB::table('bq_items')->where('id', $bq)->update(['status' => $to->value, 'version' => $row->version + 1]);
            Audit::record(AuditAction::UPDATE, 'BQ_ITEM', $bq, Arr::only((array) $row, self::AUDITED), Arr::only((array) DB::table('bq_items')->where('id', $bq)->first(), self::AUDITED), $reason);
        });

        return redirect()->route('projects.show', $project)->with('status', 'Item BQ diarsipkan. Tidak lagi dihitung, riwayatnya tetap tersimpan.');
    }

    /** Item di project di luar jangkauan menjawab 404 (sama seperti route {project}); lalu policy. */
    private function load(Request $request, int $bq, string $ability): array
    {
        $current = DB::table('bq_items')->where('id', $bq)->first();
        $project = Project::query()->visibleTo($request->user())->findOrFail($current?->project_id);
        Gate::authorize($ability, $project);

        return [$project, $current];
    }

    private function move(Request $request, int $bq, string $ability, BqStatus $to, AuditAction $action, string $message, ?string $reason = null): RedirectResponse
    {
        [$project] = $this->load($request, $bq, $ability);
        $user = $request->user();

        $warning = null;
        DB::transaction(function () use ($project, $bq, $to, $action, $reason, $user, &$warning) {
            // Urutan kunci sama dengan workflow project: project dulu, lalu item. Semua dicek ulang di bawah kunci.
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            $row = DB::table('bq_items')->where('id', $bq)->lockForUpdate()->first();
            abort_if($row === null || (int) $row->project_id !== $locked->id, 404);
            abort_unless(in_array($locked->status, self::WORKFLOW_PROJECT_STATUSES, true), 403, 'Workflow item BQ hanya berjalan saat project '.ProjectStatus::labelList(self::WORKFLOW_PROJECT_STATUSES).'.');

            abort_if($row->item_type === 'OPERATIONAL', 409, 'BQ Operasional disetujui lewat aktivasi project, bukan lewat workflow item.');
            $decision = $to !== BqStatus::SUBMITTED;
            abort_if($decision && (int) $row->created_by === $user->id, 403, 'Pembuat item BQ tidak boleh menyetujui atau meminta revisi item yang sama.');
            try {
                BqStatus::from($row->status)->transitionTo($to);
            } catch (InvalidTransition $e) {
                abort(409, $e->getMessage());
            }

            $changes = ['status' => $to->value, 'version' => $row->version + 1];
            if ($to === BqStatus::APPROVED) {
                // Basis sama dengan kartu Sisa RAB: semua item non-ARCHIVED (item ini sudah terhitung, status apa pun) ≤ RAB Perubahan.
                $allocated = $locked->allocatedBudget();
                if (Money::compare($allocated, $locked->effective_rab) > 0) {
                    throw ValidationException::withMessages(['bq' => 'Sisa RAB tidak cukup: total Nilai RAB semua item BQ (termasuk yang masih diajukan/draf) '.Money::format($allocated).' melebihi RAB Perubahan '.Money::format($locked->effective_rab).'.'.$locked->unapprovedAllocationNote().' Kecilkan atau arsipkan item yang belum disetujui (Manager dapat "Minta revisi" bila sudah diajukan), atau naikkan RAB Perubahan lewat penyesuaian target margin.']);
                }
                $changes += ['approved_by' => $user->id, 'approved_at' => now()];
            }

            $before = Arr::only((array) $row, self::AUDITED);
            DB::table('bq_items')->where('id', $bq)->update($changes);
            Audit::record($action, 'BQ_ITEM', $bq, $before, Arr::only((array) DB::table('bq_items')->where('id', $bq)->first(), self::AUDITED), $reason);
            $warning = $to === BqStatus::SUBMITTED ? $locked->overAllocationWarning() : null; // hanya saat staff mengajukan
        });

        return redirect()->route('projects.show', $project)->with('status', $message)->with('warning', $warning);
    }
}
