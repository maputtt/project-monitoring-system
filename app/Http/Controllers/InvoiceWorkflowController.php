<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\InvoiceStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\InvalidTransition;
use App\Models\Project;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Verifikasi invoice dan permintaan revisi oleh Manager/GM (INV-02, AC-07, AC-08). Pengaju tidak boleh memutuskan invoice yang sama.
 * Tahap pembayaran (INV-03, AC-09/10): permintaan revisi PAYMENT_SUBMITTED dan approvePayment (PAID + terkunci).
 * Pengaju pembayaran = pengunggah BUKTI_BAYAR terbaru (tidak ada kolom khusus; submitted_by tetap pengaju invoice).
 */
class InvoiceWorkflowController extends Controller
{
    private const AUDITED = ['workflow_status', 'approved_by', 'approved_at', 'revision_reason', 'revision_requested_by', 'revision_requested_at', 'version'];

    public function verify(Request $request, int $invoice): RedirectResponse
    {
        [$project] = $this->load($request, $invoice);

        $this->decide($project, $invoice, $request->user()->id, null);

        return redirect()->route('projects.show', $project)->with('status', 'Invoice diverifikasi. Menunggu bukti pembayaran.');
    }

    public function requestRevision(Request $request, int $invoice): RedirectResponse
    {
        [$project] = $this->load($request, $invoice); // 404/403 sebelum validasi alasan
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Alasan revisi wajib diisi.', 'reason.string' => 'Alasan revisi tidak valid.', 'reason.max' => 'Alasan revisi maksimal :max karakter.'])['reason'];

        $this->decide($project, $invoice, $request->user()->id, $reason);

        return redirect()->route('projects.show', $project)->with('status', 'Invoice dikembalikan untuk direvisi.');
    }

    /** PAYMENT_SUBMITTED → PAID: terkunci (locked_at/locked_by wajib oleh CHECK). Tidak ada route lain yang menerima invoice PAID. */
    public function approvePayment(Request $request, int $invoice): RedirectResponse
    {
        [$project] = $this->load($request, $invoice);
        $userId = $request->user()->id;

        DB::transaction(function () use ($project, $invoice, $userId) {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            $row = DB::table('invoices')->where('id', $invoice)->lockForUpdate()->first();
            abort_if($row === null || (int) $row->project_id !== $locked->id, 404);
            abort_unless(in_array($locked->status, BqWorkflowController::WORKFLOW_PROJECT_STATUSES, true), 403, 'Workflow invoice hanya berjalan saat project '.ProjectStatus::labelList(BqWorkflowController::WORKFLOW_PROJECT_STATUSES).'.');
            $doc = $this->latestDoc($invoice, 'BUKTI_BAYAR');
            abort_if(in_array($userId, [(int) $row->submitted_by, (int) $doc?->uploaded_by], true), 403, 'Pengaju pembayaran tidak boleh menyetujui pembayaran yang sama.');

            try {
                $to = InvoiceStatus::from($row->workflow_status)->transitionTo(InvoiceStatus::PAID);
            } catch (InvalidTransition $e) {
                abort(409, $e->getMessage());
            }
            abort_if($doc === null, 409, 'Bukti bayar tidak ditemukan.');

            DB::table('invoices')->where('id', $invoice)->update(['workflow_status' => $to->value, 'locked_at' => now(), 'locked_by' => $userId, 'version' => $row->version + 1]);
            DB::table('documents')->where('id', $doc->id)->update(['status' => 'VERIFIED', 'verified_by' => $userId, 'verified_at' => now()]);

            $after = Arr::only((array) DB::table('invoices')->where('id', $invoice)->first(), [...self::AUDITED, 'locked_at', 'locked_by']);
            Audit::record(AuditAction::APPROVE, 'INVOICE', $invoice, Arr::only((array) $row, self::AUDITED), Arr::only($after, self::AUDITED) + ['document_id' => $doc->id]);
            Audit::record(AuditAction::LOCK, 'INVOICE', $invoice, Arr::only((array) $row, ['locked_at', 'locked_by']), Arr::only($after, ['workflow_status', 'locked_at', 'locked_by']));
        });

        return redirect()->route('projects.show', $project)->with('status', 'Pembayaran disetujui. Invoice '.InvoiceStatus::PAID->label().' dan terkunci.');
    }

    /**
     * Controlled unlock (INV-04, AC-18): satu-satunya jalan keluar dari PAID, PAID → PAYMENT_REVISION_REQUIRED, alasan wajib.
     * locked_at/locked_by sengaja dibiarkan sebagai jejak kunci sebelumnya (ditimpa saat PAID lagi); unlocked_* mencatat unlock terakhir.
     * Tanpa larangan self-approval: AC-18 hanya meminta Manager/GM divisi + alasan + audit.
     */
    public function unlock(Request $request, int $invoice): RedirectResponse
    {
        [$project] = $this->load($request, $invoice); // 404/403 sebelum validasi alasan
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Alasan unlock wajib diisi.', 'reason.string' => 'Alasan unlock tidak valid.', 'reason.max' => 'Alasan unlock maksimal :max karakter.'])['reason'];
        $userId = $request->user()->id;

        DB::transaction(function () use ($project, $invoice, $userId, $reason) {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            $row = DB::table('invoices')->where('id', $invoice)->lockForUpdate()->first();
            abort_if($row === null || (int) $row->project_id !== $locked->id, 404);
            abort_unless(in_array($locked->status, BqWorkflowController::WORKFLOW_PROJECT_STATUSES, true), 403, 'Unlock invoice hanya berjalan saat project '.ProjectStatus::labelList(BqWorkflowController::WORKFLOW_PROJECT_STATUSES).'.');

            try {
                $to = InvoiceStatus::from($row->workflow_status)->transitionTo(InvoiceStatus::PAYMENT_REVISION_REQUIRED);
            } catch (InvalidTransition $e) {
                abort(409, $e->getMessage());
            }
            // PAYMENT_SUBMITTED juga boleh ke PAYMENT_REVISION_REQUIRED (minta revisi), tetapi itu bukan unlock.
            abort_unless($row->workflow_status === InvoiceStatus::PAID->value, 409, 'Hanya invoice '.InvoiceStatus::PAID->label().' yang bisa dibuka kuncinya.');

            DB::table('invoices')->where('id', $invoice)->update(['workflow_status' => $to->value, 'unlocked_at' => now(), 'unlocked_by' => $userId, 'unlock_reason' => $reason, 'version' => $row->version + 1]);

            $keys = [...self::AUDITED, 'locked_at', 'locked_by', 'unlocked_at', 'unlocked_by', 'unlock_reason'];
            Audit::record(AuditAction::UNLOCK, 'INVOICE', $invoice, Arr::only((array) $row, $keys), Arr::only((array) DB::table('invoices')->where('id', $invoice)->first(), $keys), $reason);
        });

        return redirect()->route('projects.show', $project)->with('status', 'Kunci invoice dibuka. Invoice kembali ke tahap revisi pembayaran.');
    }

    /**
     * Batalkan termin yang tidak akan ditagih (PLANNED → CANCELLED), alasan wajib. CANCELLED tanpa jalan keluar; koreksi invoice yang sudah diajukan memakai revisi.
     * Alasan hanya di audit log (revision_reason milik alur revisi).
     */
    public function cancel(Request $request, int $invoice): RedirectResponse
    {
        [$project] = $this->load($request, $invoice); // 404/403 sebelum validasi alasan
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Alasan pembatalan wajib diisi.', 'reason.string' => 'Alasan pembatalan tidak valid.', 'reason.max' => 'Alasan pembatalan maksimal :max karakter.'])['reason'];

        DB::transaction(function () use ($project, $invoice, $reason) {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            $row = DB::table('invoices')->where('id', $invoice)->lockForUpdate()->first();
            abort_if($row === null || (int) $row->project_id !== $locked->id, 404);
            abort_unless(in_array($locked->status, BqWorkflowController::WORKFLOW_PROJECT_STATUSES, true), 403, 'Pembatalan termin hanya berjalan saat project '.ProjectStatus::labelList(BqWorkflowController::WORKFLOW_PROJECT_STATUSES).'.');
            try {
                $to = InvoiceStatus::from($row->workflow_status)->transitionTo(InvoiceStatus::CANCELLED);
            } catch (InvalidTransition $e) {
                abort(409, $e->getMessage());
            }

            DB::table('invoices')->where('id', $invoice)->update(['workflow_status' => $to->value, 'version' => $row->version + 1]);
            Audit::record(AuditAction::UPDATE, 'INVOICE', $invoice, Arr::only((array) $row, self::AUDITED), Arr::only((array) DB::table('invoices')->where('id', $invoice)->first(), self::AUDITED), $reason);
        });

        return redirect()->route('projects.show', $project)->with('status', 'Termin dibatalkan dan tidak akan ditagih.');
    }

    /** Dokumen terbaru (bukan REPLACED) pada tahap INVOICE_FILE atau BUKTI_BAYAR; versi lama tetap tersimpan. */
    private function latestDoc(int $invoice, string $stage): ?object
    {
        return DB::table('documents')->where('entity_type', 'INVOICE')->where('entity_id', $invoice)->where('document_type', $stage)->where('status', '<>', 'REPLACED')->orderByDesc('id')->first(['id', 'uploaded_by']);
    }

    /** Invoice di project di luar jangkauan menjawab 404 (sama seperti route {project}); lalu policy. */
    private function load(Request $request, int $invoice): array
    {
        $row = DB::table('invoices')->where('id', $invoice)->first();
        $project = Project::query()->visibleTo($request->user())->findOrFail($row?->project_id);
        Gate::authorize('approve', $project);

        return [$project, $row];
    }

    /** $reason null berarti verifikasi. */
    private function decide(Project $project, int $invoice, int $userId, ?string $reason): void
    {
        DB::transaction(function () use ($project, $invoice, $userId, $reason) {
            // Urutan kunci: project dulu, lalu baris invoice; semua dicek ulang di bawah kunci.
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            $row = DB::table('invoices')->where('id', $invoice)->lockForUpdate()->first();
            abort_if($row === null || (int) $row->project_id !== $locked->id, 404);
            abort_unless(in_array($locked->status, BqWorkflowController::WORKFLOW_PROJECT_STATUSES, true), 403, 'Workflow invoice hanya berjalan saat project '.ProjectStatus::labelList(BqWorkflowController::WORKFLOW_PROJECT_STATUSES).'.');

            $from = InvoiceStatus::from($row->workflow_status);
            $stage = $from === InvoiceStatus::PAYMENT_SUBMITTED ? 'BUKTI_BAYAR' : 'INVOICE_FILE';
            $latest = $this->latestDoc($invoice, $stage); // pengunggah tahap ini = pengaju tahap ini
            abort_if(in_array($userId, [(int) $row->submitted_by, (int) $latest?->uploaded_by], true), 403, 'Pengaju tidak boleh memverifikasi atau meminta revisi yang sama.');
            try {
                if ($reason === null) {
                    // INVOICE_VERIFIED hanya dilewati: peta transisi tetap dipatuhi, status akhir WAITING_FOR_PAYMENT.
                    $path = [$from, $from->transitionTo(InvoiceStatus::INVOICE_VERIFIED)];
                    $to = end($path)->transitionTo(InvoiceStatus::WAITING_FOR_PAYMENT);
                    $path[] = $to;
                    $changes = ['approved_by' => $userId, 'approved_at' => now()];
                    $docStatus = 'VERIFIED';
                } else {
                    $to = $from->transitionTo($from === InvoiceStatus::PAYMENT_SUBMITTED ? InvoiceStatus::PAYMENT_REVISION_REQUIRED : InvoiceStatus::INVOICE_REVISION_REQUIRED);
                    $path = [$from, $to];
                    $changes = ['revision_reason' => $reason, 'revision_requested_by' => $userId, 'revision_requested_at' => now()];
                    $docStatus = 'REVISION_REQUIRED';
                }
            } catch (InvalidTransition $e) {
                abort(409, $e->getMessage());
            }

            DB::table('invoices')->where('id', $invoice)->update($changes + ['workflow_status' => $to->value, 'version' => $row->version + 1]);
            // Dokumen terbaru tahap ini ikut diputuskan.
            $doc = $latest?->id;
            $doc !== null && DB::table('documents')->where('id', $doc)->update(['status' => $docStatus] + ($reason === null ? ['verified_by' => $userId, 'verified_at' => now()] : []));

            Audit::record($reason === null ? AuditAction::APPROVE : AuditAction::REQUEST_REVISION, 'INVOICE', $invoice, Arr::only((array) $row, self::AUDITED),
                Arr::only((array) DB::table('invoices')->where('id', $invoice)->first(), self::AUDITED) + ['status_path' => array_map(fn ($s) => $s->value, $path), 'document_id' => $doc], $reason);
        });
    }
}
