<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalStatus;
use App\Enums\AuditAction;
use App\Enums\BqStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\InvalidTransition;
use App\Models\Project;
use App\Support\Audit;
use App\Support\DocumentStore;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Mutasi budget BQ (BQ-04, AC-06, AC-10): ajukan, setujui, minta revisi, ajukan ulang. Hanya FROM_UNALLOCATED_RAB dan TRANSFER_BETWEEN_BQ
 * dan RETURN_TO_UNALLOCATED_RAB (tanpa BQ tujuan) (PROJECT_ADJUSTMENT = PRJ-04; REVERSAL lewat UI pasca-MVP). Mengajukan tidak menggeser saldo; saldo baru bergerak saat approve, di bawah kunci. Tidak ada hapus.
 */
class BudgetMutationController extends Controller
{
    public const TYPES = ['FROM_UNALLOCATED_RAB', 'TRANSFER_BETWEEN_BQ', 'RETURN_TO_UNALLOCATED_RAB'];

    private const AUDITED = ['mutation_type', 'source_bq_id', 'destination_bq_id', 'amount', 'reason', 'status'];

    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $this->validated($request, 'mutation-new', true);
        $user = $request->user();

        $key = null;
        try {
            DB::transaction(function () use ($project, $data, $request, $user, &$key) {
                $locked = $this->lockProject($project->id);
                $this->assertBqItems($locked, $data, 'mutation-new');

                $id = DB::table('bq_budget_mutations')->insertGetId($this->fields($data) + ['project_id' => $locked->id, 'status' => 'SUBMITTED', 'submitted_by' => $user->id, 'submitted_at' => now()]);
                $nota = DocumentStore::storeReplacing($request->file('file'), $locked->id, 'BQ_MUTATION', $id, 'NOTA_DISPOSISI', $user->id, false, $key);
                Audit::record(AuditAction::SUBMIT, 'BQ_MUTATION', $id, after: Arr::only((array) DB::table('bq_budget_mutations')->where('id', $id)->first(), self::AUDITED) + ['nota_document_id' => $nota]);
            });
        } catch (\Throwable $e) {
            $key && Storage::disk(DocumentStore::DISK)->delete($key); // transaksi membatalkan barisnya, berkasnya jangan yatim
            throw $e;
        }

        return redirect()->route('projects.show', $project)->with('status', 'Relokasi Budget diajukan. Saldo baru berubah setelah Manager/GM menyetujui.');
    }

    /** Pengaju memperbaiki usulan REVISION_REQUIRED (semua isian; Nota Disposisi baru opsional) lalu mengajukannya kembali. */
    public function resubmit(Request $request, int $mutation): RedirectResponse
    {
        [$project] = $this->load($request, $mutation, 'contribute');
        $data = $this->validated($request, "mutation-$mutation", false);
        $user = $request->user();

        $key = null;
        try {
            DB::transaction(function () use ($project, $mutation, $data, $request, $user, &$key) {
                $locked = $this->lockProject($project->id);
                $row = $this->lockMutation($mutation, $locked->id);
                abort_unless((int) $row->submitted_by === $user->id, 403, 'Hanya pengaju yang bisa memperbaiki usulan ini.');
                abort_unless($row->status === 'REVISION_REQUIRED', 409, 'Relokasi Budget berstatus '.ApprovalStatus::from($row->status)->label().', hanya yang berstatus '.ApprovalStatus::REVISION_REQUIRED->label().' yang bisa diajukan ulang.');
                $this->assertBqItems($locked, $data, "mutation-$mutation");

                $before = Arr::only((array) $row, self::AUDITED);
                DB::table('bq_budget_mutations')->where('id', $mutation)->update($this->fields($data) + ['status' => 'SUBMITTED', 'submitted_at' => now()]);
                $nota = $request->hasFile('file') ? DocumentStore::storeReplacing($request->file('file'), $locked->id, 'BQ_MUTATION', $mutation, 'NOTA_DISPOSISI', $user->id, true, $key) : null;
                Audit::record(AuditAction::SUBMIT, 'BQ_MUTATION', $mutation, $before, Arr::only((array) DB::table('bq_budget_mutations')->where('id', $mutation)->first(), self::AUDITED) + ['nota_document_id' => $nota]);
            });
        } catch (\Throwable $e) {
            $key && Storage::disk(DocumentStore::DISK)->delete($key);
            throw $e;
        }

        return redirect()->route('projects.show', $project)->with('status', 'Relokasi Budget diajukan ulang.');
    }

    public function approve(Request $request, int $mutation): RedirectResponse
    {
        [$project] = $this->load($request, $mutation, 'approve');
        $user = $request->user();

        DB::transaction(function () use ($project, $mutation, $user) {
            // Urutan kunci: project, baris mutasi, lalu item BQ urut id (tanpa deadlock). Saldo dicek ulang di bawah kunci, bukan nilai saat diajukan.
            $locked = $this->lockProject($project->id);
            $row = $this->lockMutation($mutation, $locked->id);
            $this->assertDecidable($row, $user->id);

            $items = DB::table('bq_items')->whereIn('id', array_filter([$row->source_bq_id, $row->destination_bq_id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $row->source_bq_id === null ? null : $items->get($row->source_bq_id);
            $destination = $row->destination_bq_id === null ? null : $items->get($row->destination_bq_id); // null = RETURN_TO_UNALLOCATED_RAB
            $valid = fn (?object $item) => $item !== null && (int) $item->project_id === $locked->id && $item->status === BqStatus::APPROVED->value;
            if (($row->destination_bq_id !== null && ! $valid($destination)) || ($row->source_bq_id !== null && ! $valid($source))) {
                throw ValidationException::withMessages(['mutation' => 'Item BQ sumber atau tujuan sudah tidak berstatus '.BqStatus::APPROVED->label().'. Ajukan ulang relokasinya.']);
            }

            $amount = $row->amount;
            if ($source !== null) {
                if ($message = $this->sisaShortfall($source, $amount)) {
                    throw ValidationException::withMessages(['mutation' => $message]);
                }
            } else {
                $unallocated = Money::sub($locked->effective_rab, $locked->allocatedBudget()); // = kartu Sisa RAB (semua item non-ARCHIVED)
                if (Money::compare($unallocated, $amount) < 0) {
                    throw ValidationException::withMessages(['mutation' => 'Sisa RAB tidak cukup: tersedia '.Money::format($unallocated).', relokasi '.Money::format($amount).'.']);
                }
            }

            $before = ['status' => $row->status, 'source_bq_id' => $row->source_bq_id, 'destination_bq_id' => $row->destination_bq_id, 'amount' => $amount,
                'source_effective_budget' => $source?->effective_budget, 'source_sisa' => $source === null ? null : BqItemController::sisa($source), 'destination_effective_budget' => $destination?->effective_budget];
            $returning = $destination === null;
            $unallocatedBefore = $returning ? Money::sub($locked->effective_rab, $locked->allocatedBudget()) : null;
            $newSource = $source === null ? null : Money::sub($source->effective_budget, $amount);
            $newDestination = $destination === null ? null : Money::add($destination->effective_budget, $amount);
            if ($source !== null) {
                $this->setBudget($source, $newSource);
            }
            if ($destination !== null) {
                $this->setBudget($destination, $newDestination);
            }

            DB::table('bq_budget_mutations')->where('id', $mutation)->update(['status' => 'APPROVED', 'approved_by' => $user->id, 'approved_at' => now()]);
            $after = ['status' => 'APPROVED', 'approved_by' => $user->id, 'source_effective_budget' => $newSource, 'destination_effective_budget' => $newDestination];
            if ($returning) {
                $before['unallocated_rab'] = $unallocatedBefore;
                $after['unallocated_rab'] = Money::sub($locked->effective_rab, $locked->allocatedBudget());
            }
            Audit::record(AuditAction::APPROVE, 'BQ_MUTATION', $mutation, $before, $after);
        });

        return redirect()->route('projects.show', $project)->with('status', 'Relokasi Budget disetujui. Saldo BQ sudah diperbarui.');
    }

    public function requestRevision(Request $request, int $mutation): RedirectResponse
    {
        [$project] = $this->load($request, $mutation, 'approve'); // 404/403 sebelum validasi alasan
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Alasan revisi wajib diisi.', 'reason.string' => 'Alasan revisi tidak valid.', 'reason.max' => 'Alasan revisi maksimal :max karakter.'])['reason'];
        $user = $request->user();

        DB::transaction(function () use ($project, $mutation, $reason, $user) {
            $locked = $this->lockProject($project->id);
            $row = $this->lockMutation($mutation, $locked->id);
            $this->assertDecidable($row, $user->id);

            DB::table('bq_budget_mutations')->where('id', $mutation)->update(['status' => 'REVISION_REQUIRED', 'revision_requested_by' => $user->id, 'revision_requested_at' => now(), 'revision_reason' => $reason]);
            Audit::record(AuditAction::REQUEST_REVISION, 'BQ_MUTATION', $mutation, ['status' => $row->status], ['status' => 'REVISION_REQUIRED'], $reason);
        });

        return redirect()->route('projects.show', $project)->with('status', 'Relokasi Budget dikembalikan untuk direvisi.');
    }

    /** Tarik pengajuan: pengaju (Staff/PIC) atau Manager/GM divisi; hanya SUBMITTED/REVISION_REQUIRED, terminal. Saldo BQ tidak disentuh. */
    public function withdraw(Request $request, int $mutation): RedirectResponse
    {
        $current = DB::table('bq_budget_mutations')->where('id', $mutation)->first();
        $project = Project::query()->visibleTo($request->user())->findOrFail($current?->project_id);
        $approver = Gate::allows('approve', $project);
        abort_unless($approver || Gate::allows('contribute', $project), 403); // 404/403 sebelum validasi alasan
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Alasan penarikan wajib diisi.', 'reason.string' => 'Alasan penarikan tidak valid.', 'reason.max' => 'Alasan penarikan maksimal :max karakter.'])['reason'];
        $user = $request->user();

        DB::transaction(function () use ($project, $mutation, $reason, $user, $approver) {
            $locked = $this->lockProject($project->id);
            $row = $this->lockMutation($mutation, $locked->id);
            abort_unless($approver || (int) $row->submitted_by === $user->id, 403, 'Hanya pengaju atau Manager/GM yang bisa menarik usulan ini.');
            try {
                ApprovalStatus::from($row->status)->transitionTo(ApprovalStatus::WITHDRAWN);
            } catch (InvalidTransition $e) {
                abort(409, $e->getMessage());
            }

            DB::table('bq_budget_mutations')->where('id', $mutation)->update(['status' => 'WITHDRAWN']);
            Audit::record(AuditAction::UPDATE, 'BQ_MUTATION', $mutation, ['status' => $row->status], ['status' => 'WITHDRAWN'], $reason);
        });

        return redirect()->route('projects.show', $project)->with('status', 'Relokasi Budget ditarik. Riwayatnya tetap tersimpan; ajukan usulan baru bila masih diperlukan.');
    }

    /** Mutasi di project di luar jangkauan menjawab 404 (sama seperti route {project}); lalu policy. */
    private function load(Request $request, int $mutation, string $ability): array
    {
        $current = DB::table('bq_budget_mutations')->where('id', $mutation)->first();
        $project = Project::query()->visibleTo($request->user())->findOrFail($current?->project_id);
        Gate::authorize($ability, $project);

        return [$project, $current];
    }

    /** Kunci baris project, lalu cek ulang status di bawah kunci: ACTIVE atau COMPLETED, sama dengan workflow BQ (BQ-02). */
    private function lockProject(int $id): Project
    {
        $locked = Project::query()->lockForUpdate()->findOrFail($id);
        abort_unless(in_array($locked->status, BqWorkflowController::WORKFLOW_PROJECT_STATUSES, true), 403, 'Relokasi Budget hanya berjalan saat project '.ProjectStatus::labelList(BqWorkflowController::WORKFLOW_PROJECT_STATUSES).'.');

        return $locked;
    }

    private function lockMutation(int $id, int $projectId): object
    {
        $row = DB::table('bq_budget_mutations')->where('id', $id)->lockForUpdate()->first();
        abort_if($row === null || (int) $row->project_id !== $projectId, 404);

        return $row;
    }

    private function assertDecidable(object $row, int $userId): void
    {
        abort_if((int) $row->submitted_by === $userId, 403, 'Pengaju relokasi tidak boleh menyetujui atau meminta revisi relokasinya sendiri.');
        abort_unless($row->status === 'SUBMITTED', 409, 'Relokasi Budget berstatus '.ApprovalStatus::from($row->status)->label().', hanya yang berstatus '.ApprovalStatus::SUBMITTED->label().' yang bisa diputuskan.');
    }

    /** effective_budget baru + margin (dari nilai nego) dan overbudget (dari realisasi/nego) dihitung ulang (BQ OPERATIONAL tanpa margin); versi naik satu. */
    private function setBudget(object $item, string $budget): void
    {
        $figures = BqItemController::marginFigures($budget, $item->negotiated_value, $item->actual_cost);
        $changes = ['effective_budget' => $budget, 'version' => $item->version + 1] + ($item->item_type === 'NORMAL' ? $figures : Arr::only($figures, ['is_overbudget']));
        DB::table('bq_items')->where('id', $item->id)->update($changes);
    }

    /** Sumber dan tujuan harus item APPROVED, non-ARCHIVED, milik project ini. */
    private function assertBqItems(Project $project, array $data, string $bag): void
    {
        $ids = array_values(array_unique(array_filter([$data['source_bq_id'] ?? null, $data['destination_bq_id'] ?? null])));
        $found = DB::table('bq_items')->where('project_id', $project->id)->where('status', BqStatus::APPROVED->value)->whereIn('id', $ids)->pluck('id')->all();
        $errors = [];
        foreach (['source_bq_id', 'destination_bq_id'] as $field) {
            if (($data[$field] ?? null) !== null && ! in_array((int) $data[$field], $found, true)) {
                $errors[$field] = 'Pilih item BQ '.BqStatus::APPROVED->label().' milik project ini.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors)->errorBag($bag);
        }
        if (($data['source_bq_id'] ?? null) !== null && $message = $this->sisaShortfall(DB::table('bq_items')->find($data['source_bq_id']), Money::round($data['amount']))) {
            throw ValidationException::withMessages(['amount' => $message])->errorBag($bag);
        }
    }

    /** Saldo yang boleh direlokasi dari item sumber = Sisa item (bukan Nilai RAB). Mengembalikan pesan bila nominal melebihi Sisa. */
    private function sisaShortfall(object $source, string $amount): ?string
    {
        $sisa = BqItemController::sisa($source);

        return Money::compare($sisa, $amount) < 0 ? 'Sisa item sumber tidak cukup: sisa '.$source->item_name.' '.Money::format($sisa).', relokasi '.Money::format($amount).'.' : null;
    }

    private function fields(array $data): array
    {
        return ['mutation_type' => $data['mutation_type'], 'source_bq_id' => $data['source_bq_id'] ?? null, 'destination_bq_id' => $data['destination_bq_id'] ?? null,
            'amount' => Money::round($data['amount']), 'reason' => $data['reason']];
    }

    private function validated(Request $request, string $bag, bool $notaRequired): array
    {
        if ($request->input('mutation_type') === 'FROM_UNALLOCATED_RAB') {
            $request->merge(['source_bq_id' => null]); // sumber hanya untuk TRANSFER_BETWEEN_BQ dan RETURN; input klien diabaikan
        }
        if ($request->input('mutation_type') === 'RETURN_TO_UNALLOCATED_RAB') {
            $request->merge(['destination_bq_id' => null]); // RETURN tanpa tujuan: kembali ke Sisa RAB
        }

        return $request->validateWithBag($bag, [
            'mutation_type' => ['required', Rule::in(self::TYPES)],
            'source_bq_id' => ['nullable', 'required_if:mutation_type,TRANSFER_BETWEEN_BQ,RETURN_TO_UNALLOCATED_RAB', 'integer', 'min:1', 'different:destination_bq_id'],
            'destination_bq_id' => ['nullable', 'required_unless:mutation_type,RETURN_TO_UNALLOCATED_RAB', 'integer', 'min:1'],
            'amount' => ['bail', 'required', 'string', 'decimal:0,2', 'min:0.01', 'max:999999999999999'], // di bawah batas kolom DECIMAL(18,2)
            'reason' => ['required', 'string', 'max:1000'],
            'file' => DocumentController::fileRules($notaRequired),
        ], [
            'mutation_type.required' => 'Pilih jenis relokasi.', 'mutation_type.in' => 'Jenis relokasi tidak valid.',
            'source_bq_id.required_if' => 'Pilih item BQ sumber.', 'destination_bq_id.required_unless' => 'Pilih item BQ tujuan.', 'source_bq_id.different' => 'Sumber dan tujuan tidak boleh item yang sama.', 'source_bq_id.integer' => 'Item BQ sumber tidak valid.',
            'destination_bq_id.required' => 'Pilih item BQ tujuan.', 'destination_bq_id.integer' => 'Item BQ tujuan tidak valid.',
            'amount.required' => 'Nominal wajib diisi.', 'amount.decimal' => 'Isi dengan angka maksimal dua desimal.', 'amount.min' => 'Nominal harus lebih dari nol.', 'amount.max' => 'Nominal terlalu besar.',
            'reason.required' => 'Alasan wajib diisi.', 'reason.max' => 'Alasan maksimal :max karakter.',
            'file.required' => 'Nota Disposisi wajib dilampirkan.',
        ] + DocumentController::FILE_MESSAGES); // pesan khusus di kiri supaya menang atas pesan file umum
    }
}
