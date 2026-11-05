<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\Department;
use App\Enums\InvoiceStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\InvalidTransition;
use App\Models\Project;
use App\Models\TaxRate;
use App\Support\Audit;
use App\Support\DocumentStore;
use App\Support\InvoiceTax;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Submit invoice dan pratinjau pajak (INV-01, AC-12, AC-13, AC-24, AC-25). Hanya STAFF_PIC yang ditugaskan.
 * Tarif PPN dari billing_date, PPh dari kualifikasi pilihan user; keduanya disimpan sebagai snapshot dan terkunci sampai revisi.
 * Verifikasi ada di InvoiceWorkflowController (INV-02); bukti bayar di sini (INV-03); unlock di INV-04.
 */
class InvoiceController extends Controller
{
    private const PER_PAGE = 20;

    /** Lewat jatuh tempo = due_date sebelum hari ini (WIB) dan invoice sudah ditagihkan: bukan PLANNED (belum ditagih), PAID, atau CANCELLED. */
    public const NOT_OVERDUE = ['PLANNED', 'PAID', 'CANCELLED'];

    /** Aturan tanggal rencana penagihan (form DRAFT dan ubah tanggal): hanya format dan rentang wajar. */
    public const PLANNED_DATE_RULES = ['date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2099-12-31'];

    /** Tahap dokumen penagihan per termin (progres BILLING): kode => label. */
    public const PROGRESS_STAGES = [
        'LAMPIRAN' => 'Lampiran/dokumen pendukung', 'BAPP' => 'BAPP', 'BAST' => 'BAST', 'MEMO' => 'Memo ke Finance/FAC',
        'INVOICE' => 'Invoice dikirim', 'MENUNGGU_BAYAR' => 'Menunggu pembayaran', 'LAINNYA' => 'Lainnya',
    ];

    /**
     * Kendala aktif = progres BILLING terbaru sebuah termin (progress_date, lalu id terbesar) bertanda is_issue dan termin belum PAID/CANCELLED.
     * Satu-satunya definisi: kartu dashboard dan daftarnya memakai ini. Pemanggil membatasi project lewat `pp.project_id`.
     */
    public static function activeIssues(): QueryBuilder
    {
        return DB::table('project_progress as pp')->join('invoices', 'invoices.id', '=', 'pp.invoice_id')
            ->where('pp.entry_type', 'BILLING')->where('pp.is_issue', true)->whereNotIn('invoices.workflow_status', ['PAID', 'CANCELLED'])
            ->whereNotExists(fn ($newer) => $newer->from('project_progress as n')->whereColumn('n.invoice_id', 'pp.invoice_id')->where('n.entry_type', 'BILLING')
                ->where(fn ($w) => $w->whereColumn('n.progress_date', '>', 'pp.progress_date')
                    ->orWhere(fn ($same) => $same->whereColumn('n.progress_date', 'pp.progress_date')->whereColumn('n.id', '>', 'pp.id'))));
    }

    /**
     * Terlambat ditagih = termin PLANNED yang tanggal rencananya sudah lewat (WIB; hari ini belum terlambat), pada project ACTIVE/COMPLETED.
     * Terpisah dari "lewat jatuh tempo" (due_date, sudah ditagih). Satu-satunya definisi: filter daftar, penanda per baris, dan dashboard memakai ini.
     */
    public static function lateToBill(QueryBuilder $query, string $today): QueryBuilder
    {
        $projectStatuses = array_map(fn (ProjectStatus $status) => $status->value, BqWorkflowController::WORKFLOW_PROJECT_STATUSES);

        return $query->where('invoices.workflow_status', 'PLANNED')->where('invoices.planned_billing_date', '<', $today)
            ->whereIn('invoices.project_id', fn ($projects) => $projects->from('projects')->select('projects.id')->whereIn('projects.status', $projectStatuses));
    }

    /** INV-05: daftar invoice semua project yang terlihat (Project::visibleTo). Read-only; aksi ada di detail project. */
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
            'due_from' => ['nullable', 'date_format:Y-m-d'],
            'due_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:due_from'],
            'overdue' => ['nullable', 'boolean'],
            'late_to_bill' => ['nullable', 'boolean'],
            'outstanding' => ['nullable', 'boolean'],
            // Peran terkunci divisi: parameter diabaikan (seperti dashboard), bukan ditolak.
            'department' => $request->user()->role->isDivisionScoped() ? ['exclude'] : ['nullable', Rule::enum(Department::class)],
        ]);
        // Seperti dashboard: hanya peran lintas-divisi yang boleh memfilter divisi; peran terkunci divisi tetap dibatasi Project::visibleTo.
        $departments = $request->user()->role->isDivisionScoped() ? null : Department::cases();
        $department = $departments ? ($filters['department'] ?? null) : null;
        $today = now(config('app.local_timezone'))->toDateString();

        $invoices = DB::table('invoices')->join('projects', 'projects.id', '=', 'invoices.project_id')
            ->whereIn('invoices.project_id', Project::query()->visibleTo($request->user())->select('projects.id'))
            ->when($department, fn ($q, $d) => $q->where('projects.department', $d))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('invoices.workflow_status', $status))
            ->when($filters['due_from'] ?? null, fn ($q, $date) => $q->where('invoices.due_date', '>=', $date))
            ->when($filters['due_to'] ?? null, fn ($q, $date) => $q->where('invoices.due_date', '<=', $date))
            ->when($filters['overdue'] ?? null, fn ($q) => $q->where('invoices.due_date', '<', $today)->whereNotIn('invoices.workflow_status', self::NOT_OVERDUE))
            ->when($filters['late_to_bill'] ?? null, fn ($q) => self::lateToBill($q, $today))
            // Tagihan Belum Lunas (kartu dashboard): sudah ditagihkan, belum PAID/CANCELLED.
            ->when($filters['outstanding'] ?? null, fn ($q) => $q->whereNotIn('invoices.workflow_status', self::NOT_OVERDUE))
            ->orderByRaw('invoices.due_date is null')->orderBy('invoices.due_date')->orderBy('invoices.id')
            ->paginate(self::PER_PAGE, ['invoices.id', 'invoices.project_id', 'projects.project_code', 'projects.project_name', 'invoices.department', 'invoices.sequence_number',
                'invoices.invoice_number', 'invoices.billing_date', 'invoices.planned_billing_date', 'invoices.due_date', 'invoices.workflow_status',
                'invoices.planned_amount', 'invoices.subtotal_amount', 'invoices.net_received_amount'])->withQueryString();

        // Penanda per baris dari definisi yang sama dengan filter (satu query untuk satu halaman).
        $late = self::lateToBill(DB::table('invoices')->whereIn('invoices.id', $invoices->pluck('id')), $today)->pluck('invoices.id')->all();
        $invoices->through(fn ($row) => tap($row, fn ($r) => $r->late_to_bill = in_array($r->id, $late)));

        return view('invoices.index', ['invoices' => $invoices, 'statuses' => InvoiceStatus::cases(), 'filters' => ['department' => $department] + $filters, 'departments' => $departments, 'today' => $today, 'notOverdue' => self::NOT_OVERDUE]);
    }

    private const AUDITED = ['workflow_status', 'invoice_number', 'bapp_number', 'bast_number', 'billing_date', 'due_date', 'ppn_rate_snapshot', 'pph_rate_snapshot',
        'dpp_amount', 'ppn_amount', 'subtotal_amount', 'pph_amount', 'net_received_amount', 'version'];

    /** Hanya GET tanpa efek samping: tidak menyimpan apa pun. */
    public function taxPreview(Request $request, int $invoice): JsonResponse
    {
        [$project, $row] = $this->load($request, $invoice);
        $data = $request->validate($this->taxRules(), $this->messages());

        $tax = InvoiceTax::compute($project, $this->percentages($project->id, $row->id, $index), $index, $data['billing_date'], $this->qualification($data));

        return response()->json(['ppn_rate' => $tax['ppn_rate_snapshot'], 'pph_rate' => $tax['pph_rate_snapshot']]
            + Arr::only($tax, ['dpp_amount', 'ppn_amount', 'subtotal_amount', 'pph_amount', 'net_received_amount']));
    }

    public function submit(Request $request, int $invoice): RedirectResponse|JsonResponse
    {
        [$project] = $this->load($request, $invoice);
        $bag = "invoice-$invoice";
        $data = $request->validateWithBag($bag, $this->taxRules() + [
            'version' => ['bail', 'required', 'integer'],
            'due_date' => ['bail', 'required', 'date_format:Y-m-d', Rule::when(is_string($request->input('billing_date')), 'after_or_equal:billing_date'), 'before_or_equal:'.now(config('app.local_timezone'))->addYears(2)->toDateString()], // jatuh tempo bisa jauh setelah tanggal penagihan (maks. satu tahun ke depan), jadi batasnya dua tahun
            'invoice_number' => ['bail', 'required', 'string', 'max:100'],
            'bapp_number' => ['nullable', 'string', 'max:100'],
            'bast_number' => ['nullable', 'string', 'max:100'],
            'file' => DocumentController::fileRules(),
        ], $this->messages());
        $user = $request->user();

        $key = null;
        try {
            $result = DB::transaction(function () use ($project, $invoice, $data, $request, $user, &$key) {
                // Urutan kunci: project dulu, lalu baris invoice; semua dicek ulang di bawah kunci.
                $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
                abort_unless(in_array($locked->status, BqWorkflowController::WORKFLOW_PROJECT_STATUSES, true), 403, 'Invoice hanya bisa diajukan saat project '.ProjectStatus::labelList(BqWorkflowController::WORKFLOW_PROJECT_STATUSES).'.');
                $row = DB::table('invoices')->where('id', $invoice)->lockForUpdate()->first();
                abort_if($row === null || (int) $row->project_id !== $locked->id, 404);

                try {
                    InvoiceStatus::from($row->workflow_status)->transitionTo(InvoiceStatus::INVOICE_SUBMITTED);
                } catch (InvalidTransition $e) {
                    abort(409, $e->getMessage());
                }
                if ((int) $row->version !== (int) $data['version']) {
                    return 'stale';
                }

                $tax = InvoiceTax::compute($locked, $this->percentages($locked->id, $row->id, $index), $index, $data['billing_date'], $this->qualification($data));
                DB::table('invoices')->where('id', $invoice)->update($tax + [
                    'invoice_number' => $data['invoice_number'], 'bapp_number' => $data['bapp_number'] ?? null, 'bast_number' => $data['bast_number'] ?? null,
                    'billing_date' => $data['billing_date'], 'due_date' => $data['due_date'], 'workflow_status' => InvoiceStatus::INVOICE_SUBMITTED->value,
                    'submitted_by' => $user->id, 'submitted_at' => now(), 'version' => $row->version + 1,
                ]);

                // Pengajuan ulang: file lama tetap tersimpan, ditandai REPLACED dan ditunjuk file baru.
                $old = DB::table('documents')->where('entity_type', 'INVOICE')->where('entity_id', $invoice)->where('document_type', 'INVOICE_FILE')->where('status', '<>', 'REPLACED')->orderByDesc('id')->value('id');
                $doc = DocumentStore::store($request->file('file'), $locked->id, 'INVOICE', $invoice, 'INVOICE_FILE', $user->id);
                $key = DB::table('documents')->where('id', $doc)->value('storage_key');
                if ($old !== null) {
                    DB::table('documents')->where('id', $doc)->update(['replaced_document_id' => $old]);
                    DB::table('documents')->where('id', $old)->update(['status' => 'REPLACED']);
                }

                Audit::record(AuditAction::SUBMIT, 'INVOICE', $invoice, Arr::only((array) $row, self::AUDITED),
                    Arr::only((array) DB::table('invoices')->where('id', $invoice)->first(), self::AUDITED) + ['invoice_document_id' => $doc]);

                return 'saved';
            });
        } catch (\Throwable $e) {
            $key && Storage::disk(DocumentStore::DISK)->delete($key); // transaksi membatalkan barisnya, berkasnya jangan yatim
            throw match (true) {
                $e instanceof ValidationException => $e->errorBag($bag),
                $e instanceof QueryException && ($e->errorInfo[1] ?? null) === 1062 => ValidationException::withMessages(['invoice_number' => 'Nomor invoice sudah dipakai di divisi ini.'])->errorBag($bag),
                default => $e,
            };
        }

        if ($result === 'stale') {
            $message = 'Invoice sudah diubah pengguna lain. Muat ulang halaman, lalu ulangi pengajuan Anda.';

            return $request->expectsJson() ? response()->json(['message' => $message], 409) : back()->withInput()->withErrors(['version' => $message], $bag);
        }

        return redirect()->route('projects.show', $project)->with('status', 'Invoice diajukan. Menunggu verifikasi Manager/GM.');
    }

    /**
     * Ubah tanggal rencana penagihan termin PLANNED (project ACTIVE/COMPLETED). Bila tanggal lama sudah lewat (WIB), alasan wajib dan dicatat di audit.
     * Cek versi optimistik seperti submit; versi baris naik satu.
     */
    public function changePlannedBillingDate(Request $request, int $invoice): RedirectResponse|JsonResponse
    {
        [$project] = $this->load($request, $invoice);
        $bag = "planned-date-$invoice";
        $data = $request->validateWithBag($bag, [
            'planned_billing_date' => ['bail', 'required', ...self::PLANNED_DATE_RULES],
            'version' => ['bail', 'required', 'integer'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'planned_billing_date.required' => 'Tanggal rencana penagihan wajib diisi.', 'planned_billing_date.*' => 'Tanggal rencana penagihan tidak valid.',
            'version.required' => 'Versi data tidak ada. Muat ulang halaman.', 'version.integer' => 'Versi data tidak valid. Muat ulang halaman.',
            'reason.string' => 'Alasan tidak valid.', 'reason.max' => 'Alasan maksimal :max karakter.',
        ]);
        $today = now(config('app.local_timezone'))->toDateString();

        $result = DB::transaction(function () use ($project, $invoice, $data, $today, $bag) {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            abort_unless(in_array($locked->status, BqWorkflowController::WORKFLOW_PROJECT_STATUSES, true), 403, 'Tanggal rencana penagihan hanya bisa diubah saat project '.ProjectStatus::labelList(BqWorkflowController::WORKFLOW_PROJECT_STATUSES).'.');
            $row = DB::table('invoices')->where('id', $invoice)->lockForUpdate()->first();
            abort_if($row === null || (int) $row->project_id !== $locked->id, 404);
            abort_unless($row->workflow_status === 'PLANNED', 409, 'Tanggal rencana penagihan hanya bisa diubah pada termin berstatus '.InvoiceStatus::PLANNED->label().'.');
            if ((int) $row->version !== (int) $data['version']) {
                return 'stale';
            }

            // Tanggal lama yang sudah lewat berarti penagihan mundur dari rencana: harus ada alasan tertulis.
            $reason = $data['reason'] ?? null;
            if ($row->planned_billing_date !== null && $row->planned_billing_date < $today && $reason === null) {
                throw ValidationException::withMessages(['reason' => 'Tanggal rencana sebelumnya sudah lewat. Isi alasan perubahan.'])->errorBag($bag);
            }

            DB::table('invoices')->where('id', $invoice)->update(['planned_billing_date' => $data['planned_billing_date'], 'version' => $row->version + 1]);
            $fields = ['planned_billing_date', 'version'];
            Audit::record(AuditAction::UPDATE, 'INVOICE', $invoice, Arr::only((array) $row, $fields), Arr::only((array) DB::table('invoices')->where('id', $invoice)->first(), $fields), $reason);

            return 'saved';
        });

        if ($result === 'stale') {
            $message = 'Invoice sudah diubah pengguna lain. Muat ulang halaman, lalu ulangi perubahan Anda.';

            return $request->expectsJson() ? response()->json(['message' => $message], 409) : back()->withInput()->withErrors(['version' => $message], $bag);
        }

        return redirect()->route('projects.show', $project)->with('status', 'Tanggal rencana penagihan diperbarui.');
    }

    /** Catat progres dokumen penagihan sebuah termin (project ACTIVE/COMPLETED, termin belum PAID/CANCELLED). Selalu baris baru; riwayat tidak diubah. */
    public function recordProgress(Request $request, int $invoice): RedirectResponse
    {
        [$project] = $this->load($request, $invoice);
        $today = now(config('app.local_timezone'))->toDateString();
        $data = $request->validateWithBag("progress-$invoice", [
            'stage' => ['bail', 'required', 'string', Rule::in(array_keys(self::PROGRESS_STAGES))],
            'progress_date' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.$today],
            'description' => ['bail', 'required', 'string', 'max:1000'],
            'is_issue' => ['nullable', 'boolean'],
        ], [
            'stage.*' => 'Pilih tahap dokumen yang valid.',
            'progress_date.required' => 'Tanggal wajib diisi.', 'progress_date.before_or_equal' => 'Tanggal tidak boleh di masa depan.', 'progress_date.*' => 'Tanggal tidak valid.',
            'description.required' => 'Keterangan wajib diisi.', 'description.max' => 'Keterangan maksimal :max karakter.', 'description.*' => 'Keterangan tidak valid.',
            'is_issue.*' => 'Penanda kendala tidak valid.',
        ]);

        $sequence = DB::transaction(function () use ($project, $invoice, $data, $request) {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            abort_unless(in_array($locked->status, BqWorkflowController::WORKFLOW_PROJECT_STATUSES, true), 403, 'Progres penagihan hanya bisa dicatat saat project '.ProjectStatus::labelList(BqWorkflowController::WORKFLOW_PROJECT_STATUSES).'.');
            $row = DB::table('invoices')->where('id', $invoice)->lockForUpdate()->first();
            abort_if($row === null || (int) $row->project_id !== $locked->id, 404);
            abort_if(in_array($row->workflow_status, ['PAID', 'CANCELLED'], true), 409, 'Progres tidak bisa dicatat pada termin yang sudah Lunas atau Dibatalkan.');

            $date = Carbon::parse($data['progress_date']);
            $isIssue = $request->boolean('is_issue');
            $id = DB::table('project_progress')->insertGetId([
                'project_id' => $locked->id, 'invoice_id' => $invoice, 'entry_type' => 'BILLING', 'stage' => $data['stage'], 'is_issue' => $isIssue,
                'progress_date' => $data['progress_date'], 'week_key' => sprintf('%d-W%02d', $date->isoWeekYear, $date->isoWeek), 'description' => $data['description'], 'created_by' => $request->user()->id,
            ]);
            Audit::record(AuditAction::CREATE, 'PROGRESS', $id, null, ['sequence_number' => $row->sequence_number, 'stage' => $data['stage'], 'progress_date' => $data['progress_date'], 'is_issue' => $isIssue, 'note' => $data['description']]);

            return $row->sequence_number;
        });

        return redirect()->to(route('projects.show', $project).'#jadwal')->with('status', "Progres termin $sequence dicatat.");
    }

    /** Bukti bayar (INV-03, AC-09): WAITING_FOR_PAYMENT atau PAYMENT_REVISION_REQUIRED → PAYMENT_SUBMITTED. Pengaju pembayaran = pengunggah BUKTI_BAYAR ini (lihat approvePayment). */
    public function submitPayment(Request $request, int $invoice): RedirectResponse
    {
        [$project] = $this->load($request, $invoice);
        $request->validateWithBag("payment-$invoice", ['file' => DocumentController::fileRules()], ['file.required' => 'Bukti bayar wajib dilampirkan.'] + DocumentController::FILE_MESSAGES);
        $user = $request->user();

        $key = null;
        try {
            DB::transaction(function () use ($project, $invoice, $request, $user, &$key) {
                $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
                abort_unless(in_array($locked->status, BqWorkflowController::WORKFLOW_PROJECT_STATUSES, true), 403, 'Bukti bayar hanya bisa diajukan saat project '.ProjectStatus::labelList(BqWorkflowController::WORKFLOW_PROJECT_STATUSES).'.');
                $row = DB::table('invoices')->where('id', $invoice)->lockForUpdate()->first();
                abort_if($row === null || (int) $row->project_id !== $locked->id, 404);

                try {
                    $to = InvoiceStatus::from($row->workflow_status)->transitionTo(InvoiceStatus::PAYMENT_SUBMITTED);
                } catch (InvalidTransition $e) {
                    abort(409, $e->getMessage());
                }
                DB::table('invoices')->where('id', $invoice)->update(['workflow_status' => $to->value, 'version' => $row->version + 1]);

                // Pengajuan ulang: bukti lama tetap tersimpan, ditandai REPLACED dan ditunjuk bukti baru.
                $old = DB::table('documents')->where('entity_type', 'INVOICE')->where('entity_id', $invoice)->where('document_type', 'BUKTI_BAYAR')->where('status', '<>', 'REPLACED')->orderByDesc('id')->value('id');
                $doc = DocumentStore::store($request->file('file'), $locked->id, 'INVOICE', $invoice, 'BUKTI_BAYAR', $user->id);
                $key = DB::table('documents')->where('id', $doc)->value('storage_key');
                if ($old !== null) {
                    DB::table('documents')->where('id', $doc)->update(['replaced_document_id' => $old]);
                    DB::table('documents')->where('id', $old)->update(['status' => 'REPLACED']);
                }

                Audit::record(AuditAction::SUBMIT, 'INVOICE', $invoice, Arr::only((array) $row, self::AUDITED),
                    Arr::only((array) DB::table('invoices')->where('id', $invoice)->first(), self::AUDITED) + ['payment_document_id' => $doc]);
            });
        } catch (\Throwable $e) {
            $key && Storage::disk(DocumentStore::DISK)->delete($key); // transaksi membatalkan barisnya, berkasnya jangan yatim
            throw $e;
        }

        return redirect()->route('projects.show', $project)->with('status', 'Bukti bayar diajukan. Menunggu persetujuan Manager/GM.');
    }

    /** Invoice di project di luar jangkauan menjawab 404 (sama seperti route {project}); lalu policy. */
    private function load(Request $request, int $invoice): array
    {
        $row = DB::table('invoices')->where('id', $invoice)->first();
        $project = Project::query()->visibleTo($request->user())->findOrFail($row?->project_id);
        Gate::authorize('contribute', $project);

        return [$project, $row];
    }

    /** Persentase semua termin project urut sequence_number, dan posisi invoice ini di dalamnya. */
    private function percentages(int $projectId, int $invoiceId, ?int &$index): array
    {
        $rows = DB::table('invoices')->where('project_id', $projectId)->orderBy('sequence_number')->pluck('billing_percentage', 'id');
        $index = array_search($invoiceId, $rows->keys()->all(), true);

        return $rows->values()->all();
    }

    private function taxRules(): array
    {
        return [
            'billing_date' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:'.now(config('app.local_timezone'))->addYearNoOverflow()->toDateString()], // tarif PPN terbuka berlaku selamanya, jadi salah ketik tahun jauh akan mengunci invoice
            'pph_tax_rate_id' => ['nullable', 'required_without:pph_qualification', 'integer', Rule::exists('tax_rates', 'id')->where('tax_type', 'PPH')],
            'pph_qualification' => ['nullable', 'required_without:pph_tax_rate_id', 'in:A,B'],
        ];
    }

    /** Kualifikasi PPh dari tarif yang dipilih (pph_tax_rate_id) atau langsung dari A/B; tarif yang dipakai tetap yang berlaku pada billing_date. */
    private function qualification(array $data): string
    {
        return ($data['pph_tax_rate_id'] ?? null) ? TaxRate::query()->findOrFail($data['pph_tax_rate_id'])->vendor_qualification : $data['pph_qualification'];
    }

    private function messages(): array
    {
        return DocumentController::FILE_MESSAGES + [
            'version.required' => 'Versi data tidak ada. Muat ulang halaman.', 'version.integer' => 'Versi data tidak valid. Muat ulang halaman.',
            'invoice_number.required' => 'Nomor invoice wajib diisi.', 'invoice_number.max' => 'Nomor invoice maksimal :max karakter.',
            'bapp_number.max' => 'Nomor BAPP maksimal :max karakter.', 'bast_number.max' => 'Nomor BAST maksimal :max karakter.',
            'due_date.required' => 'Tanggal jatuh tempo wajib diisi.', 'due_date.date_format' => 'Tanggal jatuh tempo tidak valid.', 'due_date.after_or_equal' => 'Tanggal jatuh tempo tidak boleh lebih awal dari tanggal penagihan.', 'due_date.before_or_equal' => 'Tanggal jatuh tempo tidak boleh lebih dari dua tahun ke depan.',
            'billing_date.required' => 'Tanggal penagihan wajib diisi.', 'billing_date.date_format' => 'Tanggal penagihan tidak valid.', 'billing_date.before_or_equal' => 'Tanggal penagihan tidak boleh lebih dari satu tahun ke depan.',
            'pph_qualification.required_without' => 'Pilih kualifikasi PPh.', 'pph_tax_rate_id.required_without' => 'Pilih kualifikasi PPh.',
            'pph_qualification.in' => 'Kualifikasi PPh tidak valid.', 'pph_tax_rate_id.exists' => 'Tarif PPh tidak valid.',
            'file.required' => 'File invoice wajib dilampirkan.',
        ];
    }
}
