<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\ProjectStatus;
use App\Exceptions\InvalidTransition;
use App\Models\Project;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Submit, aktivasi, minta revisi (PRJ-02), dan tandai selesai (CLS-01). Hak akses ada di route (can:) dan ProjectPolicy; status dari peta transisi enum. */
class ProjectWorkflowController extends Controller
{
    private const AUDITED = ['status', 'activated_by', 'activated_at', 'version'];

    public function submit(Request $request, Project $project): RedirectResponse
    {
        return $this->move($request, $project, ProjectStatus::SUBMITTED, AuditAction::SUBMIT, 'Project diajukan untuk aktivasi.');
    }

    public function activate(Request $request, Project $project): RedirectResponse
    {
        return $this->move($request, $project, ProjectStatus::ACTIVE, AuditAction::APPROVE, 'Project diaktifkan. Baseline finansial terkunci.');
    }

    /** ACTIVE → COMPLETED: langkah siklus hidup oleh Manager/GM, bukan pengajuan, jadi tanpa larangan pengaju. */
    public function complete(Request $request, Project $project): RedirectResponse
    {
        return $this->move($request, $project, ProjectStatus::COMPLETED, AuditAction::UPDATE, 'Project ditandai '.ProjectStatus::COMPLETED->label().'.');
    }

    public function requestRevision(Request $request, Project $project): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Alasan revisi wajib diisi.', 'reason.string' => 'Alasan revisi tidak valid.', 'reason.max' => 'Alasan revisi maksimal :max karakter.'])['reason'];

        return $this->move($request, $project, ProjectStatus::DRAFT, AuditAction::REQUEST_REVISION, 'Project dikembalikan ke '.ProjectStatus::DRAFT->label().' untuk direvisi.', $reason);
    }

    private function move(Request $request, Project $project, ProjectStatus $to, AuditAction $action, string $message, ?string $reason = null): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($project, $to, $action, $reason, $user) {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            $from = $locked->status;
            $before = Arr::only($locked->attributesToArray(), self::AUDITED);
            try {
                $from->transitionTo($to);
            } catch (InvalidTransition $e) {
                abort(409, $e->getMessage());
            }

            // Adjustment hanya bisa diputuskan saat ACTIVE dan COMPLETED tak bisa kembali; yang tertinggal akan memblokir closing selamanya. Mutasi tetap bisa diputuskan saat COMPLETED.
            if ($to === ProjectStatus::COMPLETED && DB::table('project_adjustments')->where('project_id', $locked->id)->whereIn('status', ['SUBMITTED', 'REVISION_REQUIRED'])->exists()) {
                abort(409, 'Project belum bisa ditandai selesai: masih ada penyesuaian atau koreksi yang belum selesai. Setujui atau selesaikan dulu selagi project berstatus '.ProjectStatus::ACTIVE->label().'.');
            }

            $changes = ['status' => $to, 'version' => $locked->version + 1]; // version naik agar form ubah yang basi ditolak
            if ($to === ProjectStatus::ACTIVE) {
                // Dicek ulang di bawah kunci: policy berjalan sebelum kunci diambil.
                abort_if($locked->hasBeenSubmittedBy($user), 403, 'Pengaju tidak boleh mengaktifkan project yang sama.');
                $changes += ['activated_by' => $user->id, 'activated_at' => now()];
                // BQ operasional otomatis dibuat DRAFT sejak project dibuat (PRD 5.4); disetujui bersama aktivasi, tanpa lewat SUBMITTED.
                DB::table('bq_items')->where('project_id', $locked->id)->where('item_type', 'OPERATIONAL')->where('status', 'DRAFT')
                    ->update(['status' => 'APPROVED', 'approved_by' => $user->id, 'approved_at' => now()]);
                // Satu RAB (basis kartu Sisa RAB): semua item BQ non-ARCHIVED tidak boleh melebihi RAB Perubahan; gagal = seluruh transaksi dibatalkan.
                $allocated = $locked->allocatedBudget();
                if (Money::compare($allocated, $locked->effective_rab) > 0) {
                    throw ValidationException::withMessages(['project' => 'Sisa RAB tidak cukup: total Nilai RAB semua item BQ (termasuk yang masih diajukan/draf) '.Money::format($allocated).' melebihi RAB Perubahan '.Money::format($locked->effective_rab).'.'.$locked->unapprovedAllocationNote().' Kecilkan atau arsipkan item yang belum disetujui, atau naikkan RAB Perubahan lewat penyesuaian target margin.']);
                }
            }
            $locked->forceFill($changes)->save();

            Audit::record($action, 'PROJECT', $locked->id, $before, Arr::only($locked->refresh()->attributesToArray(), self::AUDITED), $reason);
        });

        return redirect()->route('projects.show', $project)->with('status', $message);
    }
}
