<?php

namespace App\Http\Controllers;

use App\Enums\BqStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\BouwheerContact;
use App\Models\Pks;
use App\Models\Project;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Daftar PKS dengan project-nya dan detail project (PRJ-03). Semua query lewat Project::visibleTo. */
class ProjectViewController extends Controller
{
    private const PKS_PER_PAGE = 10;

    /** Pagination per PKS (satu halaman = beberapa accordion lengkap), jadi satu PKS tidak terbelah antar halaman. */
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(ProjectStatus::class)]]);
        $q = isset($filters['q']) ? addcslashes($filters['q'], '%_\\') : null;

        $projects = Project::query()->visibleTo($request->user())
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('projects.status', $status))
            ->when($q, fn ($query) => $query->where(fn ($w) => $w
                ->where('project_code', 'like', "%$q%")->orWhere('project_name', 'like', "%$q%")
                ->orWhereIn('pks_id', Pks::query()->where('pks_number', 'like', "%$q%")->select('id'))));

        $pks = Pks::query()->whereIn('id', (clone $projects)->select('projects.pks_id'))->orderBy('pks_number')->paginate(self::PKS_PER_PAGE)->withQueryString();

        return view('projects.index', [
            'pks' => $pks,
            'projectsByPks' => $projects->whereIn('pks_id', $pks->pluck('id'))->orderBy('project_code')->get()->groupBy('pks_id'),
            'statuses' => ProjectStatus::cases(),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, Project $project): View
    {
        $user = $request->user();
        $status = $project->status;
        $manageTeam = $user->can('manageTeam', $project);
        $contribute = $user->can('contribute', $project);
        $decide = $user->can('approve', $project);

        return view('projects.show', [
            'project' => $project,
            'pks' => Pks::query()->find($project->pks_id, ['id', 'pks_number', 'name', 'client_name']),
            'contact' => BouwheerContact::query()->find($project->bouwheer_contact_id, ['id', 'name', 'company_name']),
            'summary' => $this->summary($project),
            'invoices' => $this->invoices($project, $contribute, $user),
            'team' => $this->team($project),
            'bqItems' => $this->bqItems($project, $contribute, $user),
            // Riwayat: item BQ yang diarsipkan tidak masuk hitungan, tetapi tetap terlihat.
            'archivedBq' => DB::table('bq_items')->where('project_id', $project->id)->where('status', 'ARCHIVED')->orderBy('id')->get(['id', 'item_code', 'item_name', 'baseline_budget']),
            'mutations' => $this->mutations($project, $user),
            'adjustments' => $this->adjustments($project, $user),
            'contractCorrection' => $this->contractCorrection($project),
            'billingCorrection' => $billingCorrection = $this->billingCorrection($project),
            'documents' => $this->documents($project),
            'documentTypes' => DocumentController::PROJECT_DOCUMENT_TYPES,
            // Checklist closing hanya untuk Manager/GM pada project COMPLETED; close() menghitung ulang di bawah kunci.
            'closing' => $decide && $status === ProjectStatus::COMPLETED ? ProjectClosingController::readiness($project) : null,
            'usableIds' => $project->usablePics()->pluck('id'),
            // Pilihan untuk form "Tugaskan": Staff/PIC aktif divisi project yang belum aktif di project ini.
            'eligible' => ! $manageTeam ? collect() : User::query()->where('role', Role::STAFF_PIC)->where('department', $project->department)->where('is_active', true)
                ->whereNotIn('id', DB::table('project_user_assignments')->where('project_id', $project->id)->where('is_active', true)->select('user_id'))
                ->orderBy('name')->get(['id', 'name', 'email']),
            // Tombol hanya mengikuti policy dan status; server tetap memeriksa ulang saat perintah dikirim.
            'can' => [
                'edit' => $user->can('update', $project),
                'submit' => $status === ProjectStatus::DRAFT && $user->can('submit', $project),
                'activate' => $status === ProjectStatus::SUBMITTED && $user->can('activate', $project),
                'revise' => $status === ProjectStatus::SUBMITTED && $user->can('requestRevision', $project),
                'manageTeam' => $manageTeam,
                'approveBq' => $decide,
                'complete' => $decide && $status === ProjectStatus::ACTIVE,
                'upload' => $contribute && ! in_array($status, [ProjectStatus::FINANCIALLY_CLOSED, ProjectStatus::ARCHIVED, ProjectStatus::CANCELLED], true),
                'mutate' => $contribute && in_array($status, BqWorkflowController::WORKFLOW_PROJECT_STATUSES, true), // mutasi budget BQ-04; server memeriksa ulang
                'adjust' => $contribute && $status === ProjectStatus::ACTIVE, // adjustment target margin PRJ-04; server memeriksa ulang
                'correctContract' => $contribute && ($this->contractCorrection($project)['possible'] ?? false), // koreksi nilai kontrak (ACTIVE/COMPLETED, ada termin batal belum dikoreksi); server memeriksa ulang
                'correctBilling' => $contribute && ($billingCorrection['possible'] ?? false), // Koreksi Persentase Termin; server memeriksa ulang
                'addBq' => $contribute && in_array($status, BqItemController::OPEN_PROJECT_STATUSES, true),
            ],
        ]);
    }

    /** Versi cetak/PDF (read-only): data yang sama dengan show() tanpa dokumen, audit log, dan tombol aksi. Visibilitas sudah dijaga route binding. */
    public function print(Request $request, Project $project): View
    {
        $user = $request->user();

        return view('projects.print', [
            'project' => $project,
            'pks' => Pks::query()->find($project->pks_id, ['id', 'pks_number', 'name', 'client_name']),
            'contact' => BouwheerContact::query()->find($project->bouwheer_contact_id, ['id', 'name', 'company_name']),
            'summary' => $this->summary($project),
            'team' => $this->team($project),
            'bqItems' => $this->bqItems($project, false, $user),
            'invoices' => $this->invoices($project, false, $user),
            'mutations' => $this->mutations($project, $user),
            'adjustments' => $this->adjustments($project, $user),
            'printedBy' => $user->name,
        ]);
    }

    /** Staff/PIC aktif yang ditugaskan, urut nama. */
    private function team(Project $project): Collection
    {
        return DB::table('project_user_assignments')->join('users', 'users.id', '=', 'project_user_assignments.user_id')
            ->where('project_user_assignments.project_id', $project->id)->where('project_user_assignments.is_active', true)
            ->orderBy('users.name')->get(['project_user_assignments.id', 'project_user_assignments.user_id', 'users.name', 'project_user_assignments.assigned_at']);
    }

    /**
     * Ringkasan finansial PRD 5.8. Nilai BQ yang dihitung: item NORMAL = nilai nego (committed), item OPERATIONAL = effective budget (reserve).
     * Biaya yang belum dihasilkan story berikutnya (BQ-01 dan seterusnya) otomatis nol.
     *
     * @return array<string, string|null>
     */
    private function summary(Project $project): array
    {
        $rows = DB::table('bq_items')->where('project_id', $project->id)->where('status', '<>', 'ARCHIVED')
            ->selectRaw("status, item_type, SUM(baseline_budget) AS baseline, SUM(effective_budget) AS effective, SUM(CASE WHEN item_type = 'OPERATIONAL' THEN effective_budget ELSE negotiated_value END) AS cost, SUM(CASE WHEN item_type = 'NORMAL' THEN negotiated_value ELSE 0 END) AS vendor, COALESCE(SUM(actual_cost), 0) AS actual")
            ->groupBy('status', 'item_type')->get();
        $cost = fn (string $status, ?string $type = null) => Money::add(...$rows->whereIn('status', $status === 'APPROVED' ? ['APPROVED', 'CLOSED'] : [$status])->when($type, fn ($r) => $r->where('item_type', $type))->pluck('cost')->all());

        $committed = $cost('APPROVED', 'NORMAL');
        $reserve = $cost('APPROVED', 'OPERATIONAL');
        $pending = $cost('SUBMITTED');
        $official = Money::sub(Money::sub($project->effective_rab, $committed), $reserve);
        $projected = Money::sub($official, $pending);
        $bq = $project->allocatedBudget(); // sumber tunggal yang sama dengan guard keras RAB
        $initialBase = $project->initialMarginBase();
        $initialMargin = Money::sub($initialBase, $project->initial_rab); // Target Margin Awal = dasar awal - RAB Awal

        return [
            'bq_baseline' => Money::add(...$rows->pluck('baseline')->all()),
            'bq_effective' => $bq,
            'bq_vendor' => Money::add(...$rows->pluck('vendor')->all()), // Σ Nilai Vendor/Subkon item NORMAL non-ARCHIVED (kartu Total Nilai BQ)
            'bq_actual' => Money::add(...$rows->pluck('actual')->all()),
            'unallocated' => Money::sub($project->effective_rab, $bq),
            'initial_margin_amount' => $initialMargin,
            'initial_margin_pct' => Money::ratioPct($initialMargin, $initialBase),
            'committed' => $committed,
            'operational_reserve' => $reserve,
            'pending' => $pending,
            'official_sisa' => $official,
            'projected_sisa' => $projected,
            'projected_profit' => Money::add($project->target_margin_amount, $projected),
            // Hanya terisi setelah financial closing (story CLS); sebelum itu tampil "—".
            'final_sisa' => $project->actual_profit_amount === null ? null : Money::sub($project->actual_profit_amount, $project->target_margin_amount),
            'actual_profit' => $project->actual_profit_amount,
        ];
    }

    /** Jadwal invoice. `canSubmit` mengikuti INV-01 (PLANNED atau perlu revisi, project ACTIVE/COMPLETED); server memeriksa ulang saat submit. */
    private function invoices(Project $project, bool $contribute, User $user): Collection
    {
        $workflowOpen = in_array($project->status, BqWorkflowController::WORKFLOW_PROJECT_STATUSES, true);
        $open = $contribute && $workflowOpen;
        $decide = $workflowOpen && $user->can('approve', $project);

        $invoices = DB::table('invoices')->where('project_id', $project->id)->orderBy('sequence_number')->get();
        // Bukti bayar terbaru (bukan REPLACED) per invoice; barisnya yang terbaru menimpa yang lama.
        $proof = DB::table('documents')->where('entity_type', 'INVOICE')->where('document_type', 'BUKTI_BAYAR')->where('status', '<>', 'REPLACED')
            ->whereIn('entity_id', $invoices->pluck('id'))->orderBy('id')->get(['id', 'entity_id', 'uploaded_by'])->keyBy('entity_id');

        // Riwayat progres penagihan, terbaru dulu (satu query); baris pertama per termin = tahap saat ini.
        $progress = DB::table('project_progress as pp')->join('users', 'users.id', '=', 'pp.created_by')->where('pp.project_id', $project->id)->where('pp.entry_type', 'BILLING')
            ->orderByDesc('pp.progress_date')->orderByDesc('pp.id')->get(['pp.id', 'pp.invoice_id', 'pp.stage', 'pp.progress_date', 'pp.description', 'pp.is_issue', 'users.name as author'])->groupBy('invoice_id');

        $unlockers = DB::table('users')->whereIn('id', $invoices->pluck('unlocked_by')->filter())->pluck('name', 'id');

        return $invoices->map(function ($invoice) use ($open, $decide, $user, $proof, $unlockers, $progress) {
            $invoice->progress = $progress[$invoice->id] ?? collect();
            $settled = in_array($invoice->workflow_status, ['PAID', 'CANCELLED'], true);
            $invoice->hasIssue = (bool) $invoice->progress->first()?->is_issue && ! $settled; // sama dengan InvoiceController::activeIssues
            $invoice->canRecordProgress = $open && ! $settled; // catat progres penagihan; server memeriksa ulang
            $invoice->canSubmit = $open && in_array($invoice->workflow_status, ['PLANNED', 'INVOICE_REVISION_REQUIRED'], true);
            // Verifikasi INV-02; ownInvoice: pengaju tidak boleh memutuskan invoice sendiri. Server memeriksa ulang.
            $invoice->ownInvoice = (int) $invoice->submitted_by === $user->id;
            $invoice->canVerify = $decide && $invoice->workflow_status === 'INVOICE_SUBMITTED' && ! $invoice->ownInvoice;
            // Pembayaran INV-03; ownPayment: pengunggah bukti bayar tidak boleh menyetujuinya. Server memeriksa ulang.
            $invoice->proofUrl = isset($proof[$invoice->id]) ? route('documents.open', $proof[$invoice->id]->id) : null;
            $invoice->ownPayment = $invoice->ownInvoice || (int) ($proof[$invoice->id]->uploaded_by ?? 0) === $user->id;
            $invoice->canChangePlannedDate = $open && $invoice->workflow_status === 'PLANNED'; // ubah tanggal rencana tagih; server memeriksa ulang
            $invoice->canSubmitPayment = $open && in_array($invoice->workflow_status, ['WAITING_FOR_PAYMENT', 'PAYMENT_REVISION_REQUIRED'], true);
            $invoice->canApprovePayment = $decide && $invoice->workflow_status === 'PAYMENT_SUBMITTED' && ! $invoice->ownPayment;
            // Unlock INV-04 (hanya PAID, Manager/GM divisi); catatan unlock tampil selama belum ada permintaan revisi yang lebih baru.
            $invoice->canCancel = $decide && $invoice->workflow_status === 'PLANNED'; // pembatalan termin; server memeriksa ulang
            $invoice->canUnlock = $decide && $invoice->workflow_status === 'PAID';
            $invoice->unlockedBy = $unlockers[$invoice->unlocked_by] ?? null;
            $invoice->showUnlockNote = $invoice->workflow_status === 'PAYMENT_REVISION_REQUIRED' && $invoice->unlocked_at !== null
                && ($invoice->revision_requested_at === null || $invoice->unlocked_at >= $invoice->revision_requested_at);

            return $invoice;
        });
    }

    /** Mutasi budget project (BQ-04), terbaru dulu. Nota Disposisi terbaru (bukan REPLACED) ditautkan lewat documents.open. */
    private function mutations(Project $project, User $user): Collection
    {
        $open = in_array($project->status, BqWorkflowController::WORKFLOW_PROJECT_STATUSES, true);
        $rows = DB::table('bq_budget_mutations as m')->leftJoin('bq_items as d', 'd.id', '=', 'm.destination_bq_id')->leftJoin('bq_items as s', 's.id', '=', 'm.source_bq_id')->join('users', 'users.id', '=', 'm.submitted_by')->leftJoin('users as ap', 'ap.id', '=', 'm.approved_by')
            ->where('m.project_id', $project->id)->orderByDesc('m.id')
            ->get(['m.id', 'm.mutation_type', 'm.amount', 'm.reason', 'm.status', 'm.submitted_by', 'm.revision_reason', 'm.source_bq_id', 'm.destination_bq_id', 'd.item_name as destination_name', 's.item_name as source_name', 'users.name as submitter', 'm.submitted_at', 'ap.name as approver', 'm.approved_at']);
        $nota = DB::table('documents')->where('entity_type', 'BQ_MUTATION')->where('document_type', 'NOTA_DISPOSISI')->where('status', '<>', 'REPLACED')
            ->whereIn('entity_id', $rows->pluck('id'))->orderBy('id')->pluck('id', 'entity_id'); // baris terbaru menimpa yang lama

        return $rows->map(function ($m) use ($open, $user, $nota, $project) {
            $m->own = (int) $m->submitted_by === $user->id;
            $m->destination_name ??= 'Sisa RAB'; // RETURN_TO_UNALLOCATED_RAB tidak punya BQ tujuan
            $m->notaUrl = isset($nota[$m->id]) ? route('documents.open', $nota[$m->id]) : null;
            $m->canDecide = $open && $m->status === 'SUBMITTED' && ! $m->own && $user->can('approve', $project);
            $m->canResubmit = $open && $m->status === 'REVISION_REQUIRED' && $m->own && $user->can('contribute', $project);
            $m->canWithdraw = $open && in_array($m->status, ['SUBMITTED', 'REVISION_REQUIRED'], true) && (($m->own && $user->can('contribute', $project)) || $user->can('approve', $project));

            return $m;
        });
    }

    /**
     * Data layar koreksi nilai kontrak (null di luar ACTIVE/COMPLETED). `possible`: ada termin batal yang belum dikoreksi (nilai kontrak > Σ termin tidak batal).
     * new_contract_value dan new_margin_base_amount dihitung server (EXCLUSIVE_PPN: plus PPN snapshot); persentase margin dan hasilnya dihitung layar dari current_margin_pct.
     *
     * @return array<string, mixed>|null
     */
    private function contractCorrection(Project $project): ?array
    {
        if (! in_array($project->status, ProjectAdjustmentController::openStatuses('CONTRACT_VALUE'), true)) {
            return null;
        }
        $new = $project->billableContractValue();

        return [
            'possible' => Money::compare($new, $project->contract_value) < 0,
            'initial_contract_value' => $project->initial_contract_value,
            'contract_value' => $project->contract_value,
            'new_contract_value' => $new,
            'new_margin_base_amount' => $project->contract_tax_mode === 'EXCLUSIVE_PPN' ? Money::addTax($new, $project->margin_base_ppn_rate_snapshot) : $new,
            'current_margin_pct' => $project->target_margin_pct,
            'committed_budget' => $project->committedBudget(),
            'cancelled_sequences' => $project->cancelledSequences(),
        ];
    }

    /**
     * Koreksi Persentase Termin: null bila status project belum menerima. possible = ada >= 2 termin PLANNED, termin terakhir masih PLANNED, dan belum ada usulan terbuka (aturan yang sama dengan server).
     * `termins` = termin PLANNED saat ini untuk isian form (invoice_id jadi kunci billing_percentages[]).
     */
    private function billingCorrection(Project $project): ?array
    {
        if (! in_array($project->status, ProjectAdjustmentController::openStatuses('BILLING_PERCENTAGE'), true)) {
            return null;
        }
        $invoices = DB::table('invoices')->where('project_id', $project->id)->orderBy('sequence_number')->get(['id', 'sequence_number', 'billing_percentage', 'planned_amount', 'workflow_status']);
        $planned = $invoices->where('workflow_status', 'PLANNED')->values();
        $open = DB::table('project_adjustments')->where('project_id', $project->id)->where('adjustment_type', 'BILLING_PERCENTAGE')->whereIn('status', ['SUBMITTED', 'REVISION_REQUIRED'])->exists();

        return [
            'possible' => ! $open && $planned->count() >= 2 && $invoices->last()->workflow_status === 'PLANNED',
            'has_open' => $open,
            'total_percentage' => $planned->reduce(fn ($sum, $i) => bcadd($sum, $i->billing_percentage, 4), '0.0000'),
            'termins' => $planned->map(fn ($i) => ['invoice_id' => (int) $i->id, 'sequence_number' => (int) $i->sequence_number, 'billing_percentage' => $i->billing_percentage, 'planned_amount' => $i->planned_amount])->all(),
        ];
    }

    /** Adjustment target margin dan koreksi nilai kontrak (PRJ-04), terbaru dulu. JSON old/new didekode di sini supaya view tidak mengurai. */
    private function adjustments(Project $project, User $user): Collection
    {
        $rows = DB::table('project_adjustments as a')->join('users', 'users.id', '=', 'a.submitted_by')->leftJoin('users as ap', 'ap.id', '=', 'a.approved_by')->where('a.project_id', $project->id)->orderByDesc('a.id')
            ->get(['a.id', 'a.adjustment_type', 'a.old_data', 'a.new_data', 'a.difference_amount', 'a.reason', 'a.status', 'a.submitted_by', 'a.revision_reason', 'users.name as submitter', 'a.submitted_at', 'ap.name as approver', 'a.approved_at']);
        $committed = $project->committedBudget();
        $nota = DB::table('documents')->where('entity_type', 'PROJECT_ADJUSTMENT')->where('document_type', 'NOTA_DISPOSISI')->where('status', '<>', 'REPLACED')
            ->whereIn('entity_id', $rows->pluck('id'))->orderBy('id')->pluck('id', 'entity_id'); // Nota Disposisi Direksi terbaru; baris terbaru menimpa yang lama

        return $rows->map(function ($a) use ($user, $project, $committed, $nota) {
            $a->notaUrl = isset($nota[$a->id]) ? route('documents.open', $nota[$a->id]) : null;
            $open = in_array($project->status, ProjectAdjustmentController::openStatuses($a->adjustment_type), true);
            $a->old = json_decode($a->old_data, true);
            $a->new = json_decode($a->new_data, true);
            $a->is_contract_value = $a->adjustment_type === 'CONTRACT_VALUE';
            $a->is_billing_percentage = $a->adjustment_type === 'BILLING_PERCENTAGE';
            $a->type_label = ProjectAdjustmentController::LABELS[$a->adjustment_type] ?? 'Penyesuaian';
            $a->difference_label = $a->is_contract_value ? 'Selisih nilai tertagih' : 'Selisih RAB Perubahan';
            // Koreksi Persentase Termin: baris termin lama -> baru untuk tampilan (tanpa selisih RAB/difference_amount).
            $a->termins = $a->is_billing_percentage ? collect($a->new['termins'])->map(fn ($t, $i) => [
                'sequence_number' => $t['sequence_number'], 'old_percentage' => $a->old['termins'][$i]['billing_percentage'], 'new_percentage' => $t['billing_percentage'],
                'old_amount' => $a->old['termins'][$i]['planned_amount'], 'new_amount' => $t['planned_amount'],
            ])->all() : [];
            // Peringatan (bukan blokir) untuk koreksi nilai kontrak yang belum diputuskan: RAB usulan di bawah total budget BQ yang sudah disetujui.
            $a->rab_below_committed = $a->is_contract_value && in_array($a->status, ['SUBMITTED', 'REVISION_REQUIRED'], true) && Money::compare($a->new['effective_rab'], $committed) < 0;
            $a->own = (int) $a->submitted_by === $user->id;
            $a->canDecide = $open && $a->status === 'SUBMITTED' && ! $a->own && $user->can('approve', $project);
            $a->stale = $a->status === 'SUBMITTED' && ProjectAdjustmentController::isStale($a, $project); // Setujui disembunyikan, server tetap 409
            $a->canResubmit = $open && $a->status === 'REVISION_REQUIRED' && $a->own && $user->can('contribute', $project);
            $a->canWithdraw = in_array($project->status, [ProjectStatus::ACTIVE, ProjectStatus::COMPLETED], true) && in_array($a->status, ['SUBMITTED', 'REVISION_REQUIRED'], true) && (($a->own && $user->can('contribute', $project)) || $user->can('approve', $project));

            return $a;
        });
    }

    /** Dokumen project (semua entitas), terbaru dulu. Tautan unduh lewat documents.open, yang menerbitkan URL bertanda tangan 5 menit saat diklik. `source` (Sumber) diturunkan dari entity_type/entity_id. */
    private function documents(Project $project): Collection
    {
        $termin = DB::table('invoices')->where('project_id', $project->id)->pluck('sequence_number', 'id');
        $adjustmentTypes = DB::table('project_adjustments')->where('project_id', $project->id)->pluck('adjustment_type', 'id');

        return DB::table('documents')->join('users', 'users.id', '=', 'documents.uploaded_by')->where('documents.project_id', $project->id)
            ->orderByDesc('documents.id')->get(['documents.id', 'documents.entity_type', 'documents.entity_id', 'documents.document_type', 'documents.original_filename', 'documents.status', 'documents.uploaded_at', 'users.name as uploader'])
            ->map(function ($doc) use ($termin, $adjustmentTypes) {
                $doc->source = match ($doc->entity_type) {
                    'PROJECT' => 'Upload manual',
                    'INVOICE' => 'Invoice termin '.($termin[$doc->entity_id] ?? $doc->entity_id),
                    'BQ_MUTATION' => 'Relokasi #'.$doc->entity_id,
                    'PROJECT_ADJUSTMENT' => ($adjustmentTypes[$doc->entity_id] ?? null) === 'CONTRACT_VALUE' ? 'Koreksi nilai tertagih' : 'Penyesuaian margin',
                    default => $doc->entity_type,
                };
                $doc->url = route('documents.open', $doc->id);

                return $doc;
            });
    }

    /** Item BQ non-ARCHIVED. `editable` mengikuti aturan BQ-01 untuk Staff/PIC; server tetap memeriksa ulang saat simpan. */
    private function bqItems(Project $project, bool $contribute, User $user): Collection
    {
        $workflowOpen = in_array($project->status, BqWorkflowController::WORKFLOW_PROJECT_STATUSES, true);
        $decide = $workflowOpen && $user->can('approve', $project);

        $items = DB::table('bq_items')->where('project_id', $project->id)->where('status', '<>', 'ARCHIVED')->orderByRaw("item_type = 'OPERATIONAL' desc")->orderBy('id')->get();
        // Alasan revisi terakhir per item (audit REQUEST_REVISION); satu query, baris terbaru menimpa yang lama.
        $reasons = DB::table('audit_logs')->where('entity_type', 'BQ_ITEM')->where('action', 'REQUEST_REVISION')
            ->whereIn('entity_id', $items->where('status', 'REVISION_REQUIRED')->pluck('id'))->orderBy('id')->pluck('reason', 'entity_id');

        return $items
            ->map(function ($item) use ($project, $contribute, $workflowOpen, $decide, $user, $reasons) { // map, bukan each: each berhenti di callback yang mengembalikan false
                $item->editable = $contribute && in_array(BqStatus::from($item->status), BqItemController::EDITABLE_ITEM_STATUSES, true)
                    && ($item->item_type === 'OPERATIONAL' ? $project->status === ProjectStatus::DRAFT : in_array($project->status, BqItemController::OPEN_PROJECT_STATUSES, true));
                // Tombol workflow BQ-02; server memeriksa ulang. ownItem: pembuat tidak boleh memutuskan item sendiri.
                $item->canSubmit = $contribute && $workflowOpen && $item->item_type === 'NORMAL' && in_array(BqStatus::from($item->status), BqItemController::EDITABLE_ITEM_STATUSES, true);
                $item->canArchive = $contribute && $item->item_type === 'NORMAL' && in_array(BqStatus::from($item->status), BqItemController::EDITABLE_ITEM_STATUSES, true)
                    && in_array($project->status, BqItemController::OPEN_PROJECT_STATUSES, true); // arsip item ditinggalkan; server memeriksa ulang
                $item->canActual = $contribute && $workflowOpen && $item->status === 'APPROVED'; // realisasi biaya BQ-03; server memeriksa ulang
                $item->revisionReason = $item->status === 'REVISION_REQUIRED' ? $reasons[$item->id] ?? null : null;
                $item->ownItem = (int) $item->created_by === $user->id;
                $item->canDecide = $decide && $item->status === 'SUBMITTED' && ! $item->ownItem;
                $item->sisa = BqItemController::sisa($item);

                return $item;
            });
    }
}
