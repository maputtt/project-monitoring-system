<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalStatus;
use App\Enums\AuditAction;
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
use Illuminate\Validation\ValidationException;

/**
 * Adjustment project (PRJ-04, AC-02): TARGET_MARGIN, CONTRACT_VALUE, dan BILLING_PERCENTAGE; ajukan, setujui, minta revisi, ajukan ulang.
 * BILLING_PERCENTAGE (Koreksi Persentase Termin, ACTIVE/COMPLETED, tanpa file): hanya persentase termin PLANNED, total tetap, termin terakhir wajib PLANNED; approve menghitung ulang planned_amount semua termin
 * lewat Money::allocate dan menaikkan version termin PLANNED saja; project tidak berubah.
 * TARGET_MARGIN wajib melampirkan Nota Disposisi Direksi (entity PROJECT_ADJUSTMENT); CONTRACT_VALUE tidak memakai file (input diabaikan). Usulan lama tanpa nota tetap bisa disetujui.
 * Mengajukan tidak mengubah project. Approve mengubah angka project sekaligus di bawah kunci; initial_rab dan initial_contract_value (baseline) tidak pernah disentuh.
 * TARGET_MARGIN: target_margin_* dan effective_rab (hanya ACTIVE). CONTRACT_VALUE (koreksi termin batal, ACTIVE/COMPLETED): contract_value, margin_base_amount, target margin, effective_rab;
 * nilai kontrak baru selalu dihitung sistem dari termin tidak batal, tidak pernah dari input. RAB, PROJECT_PERIOD, dan REVERSAL di luar cakupan. Tidak ada hapus.
 */
class ProjectAdjustmentController extends Controller
{
    private const FIGURES = ['target_margin_pct', 'target_margin_amount', 'effective_rab'];

    private const CONTRACT_FIGURES = ['contract_value', 'margin_base_amount', ...self::FIGURES];

    /** Status project yang menerima tiap jenis adjustment. */
    private const OPEN_STATUSES = [
        'TARGET_MARGIN' => [ProjectStatus::ACTIVE],
        'CONTRACT_VALUE' => [ProjectStatus::ACTIVE, ProjectStatus::COMPLETED],
        'BILLING_PERCENTAGE' => [ProjectStatus::ACTIVE, ProjectStatus::COMPLETED],
    ];

    public const LABELS = ['TARGET_MARGIN' => 'Penyesuaian target margin', 'CONTRACT_VALUE' => 'Koreksi Nilai Tertagih', 'BILLING_PERCENTAGE' => 'Koreksi Persentase Termin'];

    /** @return list<ProjectStatus> */
    public static function openStatuses(string $type): array
    {
        return self::OPEN_STATUSES[$type];
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $this->validated($request, 'adjustment-new', null, true);
        $type = $data['adjustment_type'] ?? 'TARGET_MARGIN';
        $user = $request->user();

        $key = null;
        try {
            DB::transaction(function () use ($project, $data, $type, $user, $request, &$key) {
                $locked = $this->lockProject($project->id, $type);
                // Cek di bawah kunci project agar klik ganda/dua pengaju terserialisasi; usulan kedua akan basi dan buntu di REVISION_REQUIRED.
                if ($type !== 'TARGET_MARGIN' && DB::table('project_adjustments')->where('project_id', $locked->id)->where('adjustment_type', $type)->whereIn('status', ['SUBMITTED', 'REVISION_REQUIRED'])->exists()) {
                    throw ValidationException::withMessages([$type === 'CONTRACT_VALUE' ? 'adjustment_type' : 'billing_percentages' => 'Masih ada '.self::LABELS[$type].' yang belum selesai.'])->errorBag('adjustment-new');
                }
                $id = DB::table('project_adjustments')->insertGetId($this->fields($locked, $data, 'adjustment-new', $type) + ['project_id' => $locked->id, 'status' => 'SUBMITTED', 'submitted_by' => $user->id, 'submitted_at' => now()]);
                $nota = $type === 'TARGET_MARGIN' ? DocumentStore::storeReplacing($request->file('file'), $locked->id, 'PROJECT_ADJUSTMENT', $id, 'NOTA_DISPOSISI', $user->id, false, $key) : null;
                Audit::record(AuditAction::SUBMIT, 'PROJECT_ADJUSTMENT', $id, after: $this->audited($id) + $this->notaAudit($nota));
            });
        } catch (\Throwable $e) {
            $key && Storage::disk(DocumentStore::DISK)->delete($key); // transaksi membatalkan barisnya, berkasnya jangan yatim
            throw $e;
        }

        return redirect()->route('projects.show', $project)->with('status', self::LABELS[$type].' diajukan. Project berubah setelah Manager/GM menyetujui.');
    }

    /** Pengaju memperbaiki usulan REVISION_REQUIRED lalu mengajukannya kembali; yang APPROVED tidak bisa diubah (koreksi lewat reversal, pasca-MVP). */
    public function resubmit(Request $request, int $adjustment): RedirectResponse
    {
        [$project, $current] = $this->load($request, $adjustment, 'contribute');
        // Nota baru opsional (mengganti yang lama); wajib bila usulan TARGET_MARGIN belum punya nota (usulan lama sebelum aturan ini).
        $hasNota = DB::table('documents')->where('entity_type', 'PROJECT_ADJUSTMENT')->where('entity_id', $adjustment)->where('document_type', 'NOTA_DISPOSISI')->where('status', '<>', 'REPLACED')->exists();
        $data = $this->validated($request, "adjustment-$adjustment", $current->adjustment_type, ! $hasNota);
        $user = $request->user();

        $key = null;
        try {
            DB::transaction(function () use ($project, $current, $adjustment, $data, $user, $request, &$key) {
                $locked = $this->lockProject($project->id, $current->adjustment_type); // jenis usulan tidak bisa diganti
                $row = $this->lockAdjustment($adjustment, $locked->id);
                abort_unless((int) $row->submitted_by === $user->id, 403, 'Hanya pengaju yang bisa memperbaiki usulan ini.');
                $this->moveTo($row, ApprovalStatus::SUBMITTED);

                $before = $this->audited($adjustment);
                DB::table('project_adjustments')->where('id', $adjustment)->update($this->fields($locked, $data, "adjustment-$adjustment", $row->adjustment_type) + ['status' => 'SUBMITTED', 'submitted_at' => now()]);
                $nota = $row->adjustment_type === 'TARGET_MARGIN' && $request->hasFile('file') ? DocumentStore::storeReplacing($request->file('file'), $locked->id, 'PROJECT_ADJUSTMENT', $adjustment, 'NOTA_DISPOSISI', $user->id, true, $key) : null;
                Audit::record(AuditAction::SUBMIT, 'PROJECT_ADJUSTMENT', $adjustment, $before, $this->audited($adjustment) + $this->notaAudit($nota));
            });
        } catch (\Throwable $e) {
            $key && Storage::disk(DocumentStore::DISK)->delete($key);
            throw $e;
        }

        return redirect()->route('projects.show', $project)->with('status', self::LABELS[$current->adjustment_type].' diajukan ulang.');
    }

    public function approve(Request $request, int $adjustment): RedirectResponse
    {
        [$project, $current] = $this->load($request, $adjustment, 'approve');
        $user = $request->user();

        DB::transaction(function () use ($project, $current, $adjustment, $user) {
            // Urutan kunci: project, lalu baris adjustment. Semua dicek ulang di bawah kunci, dan angka baru dihitung ulang dari margin base (atau termin tidak batal) saat ini.
            $locked = $this->lockProject($project->id, $current->adjustment_type);
            $row = $this->lockAdjustment($adjustment, $locked->id);
            $this->assertDecidable($row, $user->id, ApprovalStatus::APPROVED);

            if ($row->adjustment_type === 'BILLING_PERCENTAGE') {
                $this->approveBilling($locked, $row, $user->id);

                return;
            }

            // old_data = angka project saat usulan diajukan; bila sudah bergeser (mis. adjustment lain disetujui lebih dulu), approver harus melihat angka terbaru.
            abort_if(self::isStale($row, $locked), 409, 'Data project sudah berubah sejak usulan diajukan. Muat ulang halaman.');

            $type = $row->adjustment_type;
            $proposed = json_decode($row->new_data, true);
            // Termin berubah (mis. termin lain dibatalkan) sejak usulan diajukan: nilai kontrak usulan sudah tidak sesuai.
            abort_if($type === 'CONTRACT_VALUE' && Money::compare($locked->billableContractValue(), $proposed['contract_value']) !== 0, 409, 'Termin berubah sejak usulan diajukan. Muat ulang halaman.');
            $new = $this->figures($locked, $type, $proposed['margin_mode'], $proposed['margin_value']);
            $allocated = $locked->allocatedBudget();
            // RAB di bawah Nilai RAB semua item BQ: TARGET_MARGIN ditolak; CONTRACT_VALUE hanya peringatan di layar (nilai kontrak turun karena termin batal).
            if ($type === 'TARGET_MARGIN' && Money::compare($new['effective_rab'], $allocated) < 0) {
                throw ValidationException::withMessages(['adjustment' => 'Sisa RAB tidak cukup: RAB Perubahan baru '.Money::format($new['effective_rab']).' lebih kecil dari total Nilai RAB semua item BQ (termasuk yang masih diajukan/draf) '.Money::format($allocated).'.'.(($note = $locked->unapprovedAllocationNote()) !== '' ? $note.' Kecilkan atau arsipkan item yang belum disetujui lebih dulu.' : ' Kembalikan sisa budget item BQ ke Sisa RAB lewat Relokasi Budget, atau kurangi penurunan RAB pada usulan ini.')]);
            }

            $old = Arr::only($locked->attributesToArray(), $type === 'CONTRACT_VALUE' ? self::CONTRACT_FIGURES : self::FIGURES);
            $locked->forceFill($new + ['version' => $locked->version + 1])->save(); // initial_rab dan initial_contract_value sengaja tidak ada di sini
            DB::table('project_adjustments')->where('id', $adjustment)->update([
                'old_data' => json_encode($old), 'new_data' => json_encode($new + Arr::only($proposed, ['margin_mode', 'margin_value', 'cancelled_sequences'])),
                'difference_amount' => $this->difference($type, $old, $new), 'status' => 'APPROVED', 'approved_by' => $user->id, 'approved_at' => now(),
            ]);
            Audit::record(AuditAction::APPROVE, 'PROJECT_ADJUSTMENT', $adjustment, $old + ['status' => $row->status], $new + ['status' => 'APPROVED']);
        });

        return redirect()->route('projects.show', $project)->with('status', match ($current->adjustment_type) {
            'CONTRACT_VALUE' => 'Koreksi Nilai Tertagih disetujui. Nilai tertagih, target margin, dan RAB Perubahan sudah diperbarui; Nilai Project dan RAB Awal tidak berubah.',
            'BILLING_PERCENTAGE' => 'Koreksi Persentase Termin disetujui. Persentase dan nilai rencana termin yang belum ditagih sudah diperbarui; nilai project tidak berubah.',
            default => 'Penyesuaian disetujui. Target margin dan RAB Perubahan sudah diperbarui; RAB Awal tidak berubah.',
        });
    }

    public function requestRevision(Request $request, int $adjustment): RedirectResponse
    {
        [$project, $current] = $this->load($request, $adjustment, 'approve'); // 404/403 sebelum validasi alasan
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Alasan revisi wajib diisi.', 'reason.string' => 'Alasan revisi tidak valid.', 'reason.max' => 'Alasan revisi maksimal :max karakter.'])['reason'];
        $user = $request->user();

        DB::transaction(function () use ($project, $current, $adjustment, $reason, $user) {
            $locked = $this->lockProject($project->id, $current->adjustment_type);
            $row = $this->lockAdjustment($adjustment, $locked->id);
            $this->assertDecidable($row, $user->id, ApprovalStatus::REVISION_REQUIRED);

            DB::table('project_adjustments')->where('id', $adjustment)->update(['status' => 'REVISION_REQUIRED', 'revision_requested_by' => $user->id, 'revision_requested_at' => now(), 'revision_reason' => $reason]);
            Audit::record(AuditAction::REQUEST_REVISION, 'PROJECT_ADJUSTMENT', $adjustment, ['status' => $row->status], ['status' => 'REVISION_REQUIRED'], $reason);
        });

        return redirect()->route('projects.show', $project)->with('status', 'Penyesuaian dikembalikan untuk direvisi.');
    }

    /** Tarik pengajuan: pengaju (Staff/PIC) atau Manager/GM divisi; hanya SUBMITTED/REVISION_REQUIRED, terminal. Project dan termin tidak disentuh. */
    public function withdraw(Request $request, int $adjustment): RedirectResponse
    {
        $current = DB::table('project_adjustments')->where('id', $adjustment)->first();
        $project = Project::query()->visibleTo($request->user())->findOrFail($current?->project_id);
        $approver = Gate::allows('approve', $project);
        abort_unless($approver || Gate::allows('contribute', $project), 403); // 404/403 sebelum validasi alasan
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Alasan penarikan wajib diisi.', 'reason.string' => 'Alasan penarikan tidak valid.', 'reason.max' => 'Alasan penarikan maksimal :max karakter.'])['reason'];
        $user = $request->user();

        DB::transaction(function () use ($project, $adjustment, $reason, $user, $approver) {
            // Tidak lewat lockProject($type): penarikan tetap sah di project ACTIVE/COMPLETED untuk semua jenis.
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            abort_unless(in_array($locked->status, [ProjectStatus::ACTIVE, ProjectStatus::COMPLETED], true), 403, 'Penarikan hanya bisa dilakukan saat project berstatus '.ProjectStatus::labelList([ProjectStatus::ACTIVE, ProjectStatus::COMPLETED]).'.');
            $row = $this->lockAdjustment($adjustment, $locked->id);
            abort_unless($approver || (int) $row->submitted_by === $user->id, 403, 'Hanya pengaju atau Manager/GM yang bisa menarik usulan ini.');
            $this->moveTo($row, ApprovalStatus::WITHDRAWN);

            DB::table('project_adjustments')->where('id', $adjustment)->update(['status' => 'WITHDRAWN']);
            Audit::record(AuditAction::UPDATE, 'PROJECT_ADJUSTMENT', $adjustment, ['status' => $row->status], ['status' => 'WITHDRAWN'], $reason);
        });

        return redirect()->route('projects.show', $project)->with('status', self::LABELS[$current->adjustment_type].' ditarik. Riwayatnya tetap tersimpan; ajukan usulan baru bila masih diperlukan.');
    }

    /** Angka project sudah bergeser dari old_data usulan. Dipakai approve (409) dan layar project (sembunyikan tombol Setujui). */
    public static function isStale(object $row, Project $project): bool
    {
        $submitted = json_decode($row->old_data, true);
        if ($row->adjustment_type === 'BILLING_PERCENTAGE') {
            // Bukan angka project: himpunan termin PLANNED (id dan persentasenya) harus sama dengan saat usulan diajukan.
            $now = DB::table('invoices')->where('project_id', $project->id)->where('workflow_status', 'PLANNED')->orderBy('sequence_number')->pluck('billing_percentage', 'id')->all();
            $then = collect($submitted['termins'])->pluck('billing_percentage', 'invoice_id')->all();

            return array_keys($now) !== array_keys($then) || collect($now)->contains(fn ($pct, $id) => bccomp($pct, $then[$id], 4) !== 0);
        }
        foreach (Arr::only($project->attributesToArray(), self::CONTRACT_FIGURES) as $field => $current) {
            // Usulan TARGET_MARGIN tidak menyimpan contract_value dan margin_base_amount; hanya bandingkan kunci yang ada.
            if (isset($submitted[$field]) && Money::compare($submitted[$field], $current) !== 0) {
                return true;
            }
        }

        return false;
    }

    /** Adjustment di project di luar jangkauan menjawab 404 (sama seperti route {project}); lalu policy. */
    private function load(Request $request, int $adjustment, string $ability): array
    {
        $current = DB::table('project_adjustments')->where('id', $adjustment)->first();
        $project = Project::query()->visibleTo($request->user())->findOrFail($current?->project_id);
        Gate::authorize($ability, $project);

        return [$project, $current];
    }

    /** Kunci baris project, lalu cek ulang di bawah kunci: TARGET_MARGIN hanya untuk project ACTIVE, CONTRACT_VALUE juga COMPLETED. */
    private function lockProject(int $id, string $type): Project
    {
        $locked = Project::query()->lockForUpdate()->findOrFail($id);
        abort_unless(in_array($locked->status, self::OPEN_STATUSES[$type], true), 403, 'Penyesuaian hanya bisa diajukan dan diputuskan saat project berstatus '.ProjectStatus::labelList(self::OPEN_STATUSES[$type]).'.');

        return $locked;
    }

    private function lockAdjustment(int $id, int $projectId): object
    {
        $row = DB::table('project_adjustments')->where('id', $id)->lockForUpdate()->first();
        abort_if($row === null || (int) $row->project_id !== $projectId, 404);

        return $row;
    }

    private function assertDecidable(object $row, int $userId, ApprovalStatus $to): void
    {
        abort_if((int) $row->submitted_by === $userId, 403, 'Pengaju tidak boleh menyetujui atau meminta revisi penyesuaiannya sendiri.');
        abort_unless($row->status === 'SUBMITTED', 409, 'Penyesuaian berstatus '.ApprovalStatus::from($row->status)->label().', hanya yang berstatus '.ApprovalStatus::SUBMITTED->label().' yang bisa diputuskan.');
        $this->moveTo($row, $to);
    }

    private function moveTo(object $row, ApprovalStatus $to): void
    {
        try {
            ApprovalStatus::from($row->status)->transitionTo($to);
        } catch (InvalidTransition $e) {
            abort(409, $e->getMessage());
        }
    }

    /**
     * Target margin baru dan Effective RAB (rumus sama dengan ProjectController::calculate).
     * TARGET_MARGIN: dari margin base project saat ini. CONTRACT_VALUE: nilai kontrak baru = billableContractValue(); margin base ikut (EXCLUSIVE_PPN: plus PPN snapshot), keduanya ikut dikembalikan.
     */
    private function figures(Project $project, string $type, string $mode, string $value, ?string $bag = null): array
    {
        $extra = [];
        $base = $project->margin_base_amount;
        if ($type === 'CONTRACT_VALUE') {
            $contract = $project->billableContractValue();
            $base = $project->contract_tax_mode === 'EXCLUSIVE_PPN' ? Money::addTax($contract, $project->margin_base_ppn_rate_snapshot) : $contract;
            $extra = ['contract_value' => $contract, 'margin_base_amount' => $base];
        }
        if ($mode === 'PCT') {
            $pct = bcadd($value, '0', 4);
            $margin = Money::percentOf($base, $pct);
        } else {
            $margin = Money::round($value);
            $pct = Money::ratioPct($margin, $base);
        }
        if (Money::compare($margin, $base) > 0) {
            $error = ValidationException::withMessages(['margin_value' => 'Target margin tidak boleh melebihi dasar target margin ('.Money::format($base).').']);
            throw $bag ? $error->errorBag($bag) : $error;
        }

        return $extra + ['target_margin_pct' => $pct, 'target_margin_amount' => $margin, 'effective_rab' => Money::sub($base, $margin)];
    }

    /** TARGET_MARGIN: selisih Effective RAB; CONTRACT_VALUE: selisih nilai kontrak. Boleh negatif. */
    private function difference(string $type, array $old, array $new): string
    {
        $field = $type === 'CONTRACT_VALUE' ? 'contract_value' : 'effective_rab';

        return Money::sub($new[$field], $old[$field]);
    }

    /** Kolom baris adjustment: old_data = kondisi project sekarang, new_data = usulan, difference_amount = lihat difference(). */
    private function fields(Project $project, array $data, string $bag, string $type): array
    {
        if ($type === 'BILLING_PERCENTAGE') {
            return $this->billingFields($project, $data, $bag);
        }
        if ($type === 'CONTRACT_VALUE' && Money::compare($project->billableContractValue(), $project->contract_value) >= 0) {
            throw ValidationException::withMessages(['adjustment_type' => 'Tidak ada termin batal yang belum dikoreksi.'])->errorBag($bag);
        }
        $new = $this->figures($project, $type, $data['margin_mode'], $data['margin_value'], $bag);
        $old = Arr::only($project->attributesToArray(), $type === 'CONTRACT_VALUE' ? self::CONTRACT_FIGURES : self::FIGURES);
        $proposal = ['margin_mode' => $data['margin_mode'], 'margin_value' => $data['margin_mode'] === 'PCT' ? bcadd($data['margin_value'], '0', 4) : Money::round($data['margin_value'])]
            + ($type === 'CONTRACT_VALUE' ? ['cancelled_sequences' => $project->cancelledSequences()] : []);

        return [
            'adjustment_type' => $type, 'old_data' => json_encode($old), 'new_data' => json_encode($new + $proposal),
            'difference_amount' => $this->difference($type, $old, $new), 'reason' => $data['reason'],
        ];
    }

    /**
     * Koreksi Persentase Termin: aturan 2-6 di bawah kunci project (aturan 1, satu usulan terbuka, ada di store). Hanya persentase termin PLANNED berubah; total persentase tetap;
     * termin terakhir wajib PLANNED supaya bagian termin ditagih/batal tidak bergeser (bagian terakhir menyerap pembulatan). old_data/new_data = daftar termin PLANNED.
     */
    private function billingFields(Project $project, array $data, string $bag): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['billing_percentages' => $message])->errorBag($bag);
        $invoices = DB::table('invoices')->where('project_id', $project->id)->orderBy('sequence_number')->get(['id', 'sequence_number', 'billing_percentage', 'planned_amount', 'workflow_status']);
        $planned = $invoices->where('workflow_status', 'PLANNED')->values();
        $sent = collect($data['billing_percentages'])->mapWithKeys(fn ($pct, $id) => [(string) $id => bcadd($pct, '0', 4)]);

        if ($sent->keys()->map(fn ($id) => (string) $id)->sort()->values()->all() !== $planned->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all()) {
            $fail('Daftar termin berubah. Muat ulang halaman.');
        }
        if ($planned->count() < 2) {
            $fail('Koreksi persentase memerlukan minimal dua termin yang belum ditagih.');
        }
        if ($invoices->last()->workflow_status !== 'PLANNED') {
            $fail('Termin terakhir sudah ditagih atau dibatalkan, persentase tidak bisa dikoreksi.');
        }
        $oldTotal = $planned->reduce(fn ($sum, $i) => bcadd($sum, $i->billing_percentage, 4), '0.0000');
        $newTotal = $sent->reduce(fn ($sum, $pct) => bcadd($sum, $pct, 4), '0.0000');
        if (bccomp($oldTotal, $newTotal, 4) !== 0) {
            $fmt = fn (string $pct) => str_replace('.', ',', rtrim(rtrim($pct, '0'), '.'));
            $fail('Total persentase termin yang dikoreksi harus tetap '.$fmt($oldTotal).'% (sekarang '.$fmt($newTotal).'%).');
        }
        if (! $planned->contains(fn ($i) => bccomp($i->billing_percentage, $sent[(string) $i->id], 4) !== 0)) {
            $fail('Tidak ada persentase termin yang berubah.');
        }

        $amounts = $this->allocation($project, $invoices->map(fn ($i) => $i->workflow_status === 'PLANNED' ? $sent[(string) $i->id] : $i->billing_percentage)->all(), $bag);
        $new = [];
        foreach ($invoices as $position => $i) {
            if ($i->workflow_status === 'PLANNED') {
                $new[] = $this->termin($i, $sent[(string) $i->id], $amounts[$position]);
            }
        }

        return [
            'adjustment_type' => 'BILLING_PERCENTAGE', 'old_data' => json_encode(['termins' => $planned->map(fn ($i) => $this->termin($i, $i->billing_percentage, $i->planned_amount))->all()]),
            'new_data' => json_encode(['termins' => $new]), 'difference_amount' => null, 'reason' => $data['reason'],
        ];
    }

    private function termin(object $invoice, string $pct, string $amount): array
    {
        return ['invoice_id' => (int) $invoice->id, 'sequence_number' => (int) $invoice->sequence_number, 'billing_percentage' => $pct, 'planned_amount' => $amount];
    }

    /** Nilai rencana tiap termin dari margin base awal (sama dengan ProjectController::planInvoices); bagian terakhir tidak boleh negatif. */
    private function allocation(Project $project, array $percentages, ?string $bag = null): array
    {
        $amounts = Money::allocate($project->initialMarginBase(), $percentages);
        if (str_starts_with(end($amounts), '-')) {
            $error = ValidationException::withMessages(['billing_percentages' => 'Dasar target margin terlalu kecil untuk persentase termin ini.']);
            throw $bag ? $error->errorBag($bag) : $error;
        }

        return $amounts;
    }

    /** Approve Koreksi Persentase Termin: kunci termin, cek ulang basi, hitung ulang rencana semua termin, naikkan version termin PLANNED saja. Project tidak berubah. */
    private function approveBilling(Project $locked, object $row, int $userId): void
    {
        $invoices = DB::table('invoices')->where('project_id', $locked->id)->orderBy('sequence_number')->lockForUpdate()->get(['id', 'sequence_number', 'billing_percentage', 'planned_amount', 'workflow_status', 'version']);
        abort_if(self::isStale($row, $locked) || $invoices->last()?->workflow_status !== 'PLANNED', 409, 'Termin berubah sejak usulan diajukan. Muat ulang halaman.');

        $before = json_decode($row->old_data, true);
        $sent = collect(json_decode($row->new_data, true)['termins'])->pluck('billing_percentage', 'invoice_id');
        $amounts = $this->allocation($locked, $invoices->map(fn ($i) => $i->workflow_status === 'PLANNED' ? $sent[$i->id] : $i->billing_percentage)->all());
        $new = [];
        foreach ($invoices as $position => $i) {
            if ($i->workflow_status === 'PLANNED') {
                DB::table('invoices')->where('id', $i->id)->update(['billing_percentage' => $sent[$i->id], 'planned_amount' => $amounts[$position], 'version' => $i->version + 1]);
                $new[] = $this->termin($i, $sent[$i->id], $amounts[$position]);
            }
        }

        DB::table('project_adjustments')->where('id', $row->id)->update(['new_data' => json_encode(['termins' => $new]), 'status' => 'APPROVED', 'approved_by' => $userId, 'approved_at' => now()]);
        Audit::record(AuditAction::APPROVE, 'PROJECT_ADJUSTMENT', $row->id, $before + ['status' => $row->status], ['termins' => $new, 'status' => 'APPROVED']);
    }

    private function audited(int $id): array
    {
        return Arr::only((array) DB::table('project_adjustments')->where('id', $id)->first(), ['adjustment_type', 'old_data', 'new_data', 'difference_amount', 'reason', 'status']);
    }

    private function notaAudit(?int $nota): array
    {
        return $nota === null ? [] : ['nota_document_id' => $nota];
    }

    private function validated(Request $request, string $bag, ?string $storedType, bool $notaRequired): array
    {
        $type = $storedType ?? $request->input('adjustment_type') ?? 'TARGET_MARGIN';
        $amountMode = $request->input('margin_mode') === 'AMOUNT';

        $own = $type === 'BILLING_PERCENTAGE'
            ? ['billing_percentages' => ['required', 'array'], 'billing_percentages.*' => ['bail', 'required', 'string', 'decimal:0,4', 'gt:0', 'max:100']]
            : [
                'margin_mode' => ['bail', 'required', 'string', 'in:PCT,AMOUNT'],
                'margin_value' => ['bail', 'required', 'string', $amountMode ? 'decimal:0,2' : 'decimal:0,4', 'min:0', $amountMode ? 'max:999999999999999' : 'max:100'],
            ];

        return $request->validateWithBag($bag, [
            'adjustment_type' => ['nullable', 'string', 'in:TARGET_MARGIN,CONTRACT_VALUE,BILLING_PERCENTAGE'], // hanya dibaca saat membuat usulan baru; ajukan ulang memakai jenis yang tersimpan
            'reason' => ['required', 'string', 'max:1000'],
        ] + $own + ($type === 'TARGET_MARGIN' ? ['file' => DocumentController::fileRules($notaRequired)] : []), ['file.required' => 'Nota Disposisi Direksi wajib dilampirkan.'] + DocumentController::FILE_MESSAGES + [
            'adjustment_type.in' => 'Jenis penyesuaian tidak dikenal.',
            'billing_percentages.required' => 'Persentase termin wajib diisi.', 'billing_percentages.array' => 'Persentase termin tidak valid.',
            'billing_percentages.*.required' => 'Persentase termin wajib diisi.', 'billing_percentages.*.decimal' => 'Persentase termin maksimal empat desimal.',
            'billing_percentages.*.gt' => 'Persentase termin harus lebih dari 0.', 'billing_percentages.*.max' => 'Persentase termin tidak boleh lebih dari 100.',
            'margin_mode.required' => 'Pilih persen atau nominal.', 'margin_mode.in' => 'Target margin harus diisi sebagai persen atau nominal.',
            'margin_value.required' => 'Target margin baru wajib diisi.', 'margin_value.decimal' => $amountMode ? 'Target margin nominal maksimal dua desimal.' : 'Target margin persen maksimal empat desimal.',
            'margin_value.min' => 'Target margin tidak boleh negatif.', 'margin_value.max' => $amountMode ? 'Target margin terlalu besar.' : 'Target margin persen tidak boleh lebih dari 100.',
            'reason.required' => 'Alasan wajib diisi.', 'reason.max' => 'Alasan maksimal :max karakter.',
            'string' => ':attribute tidak valid.',
        ]);
    }
}
