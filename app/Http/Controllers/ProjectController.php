<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\PksStatus;
use App\Enums\ProjectStatus;
use App\Models\BouwheerContact;
use App\Models\Pks;
use App\Models\Project;
use App\Models\TaxRate;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Buat dan ubah project DRAFT (PRJ-01). Hak akses ada di route (can:) dan ProjectPolicy; nilai hitung selalu dari server. */
class ProjectController extends Controller
{
    private const MAX_PAYMENTS = 60;

    public function create(): View
    {
        return $this->form(new Project);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $user = $request->user();

        $project = DB::transaction(function () use ($data, $user) {
            $project = (new Project)->forceFill($this->calculate($data) + ['department' => $user->department, 'status' => ProjectStatus::DRAFT, 'created_by' => $user->id]);
            $project->save();
            $this->planInvoices($project, $data['billing_percentages'], $data['planned_billing_dates'], $user->id);

            DB::table('bq_items')->insert(['project_id' => $project->id, 'item_name' => 'Biaya Lain-lain / Operasional', 'item_type' => 'OPERATIONAL', 'baseline_budget' => '0.00', 'effective_budget' => '0.00', 'status' => 'DRAFT', 'created_by' => $user->id]);
            // Tanpa assignment ini pembuat tidak bisa melihat draft-nya sendiri (Project::visibleTo).
            DB::table('project_user_assignments')->insert(['project_id' => $project->id, 'user_id' => $user->id, 'assignment_role' => 'PIC', 'assigned_by' => $user->id]);

            Audit::record(AuditAction::CREATE, 'PROJECT', $project->id, after: $this->snapshot($project->refresh()));

            return $project;
        });

        return redirect()->route('projects.edit', $project)->with('status', 'Project draft dibuat.');
    }

    public function edit(Project $project): View
    {
        return $this->form($project);
    }

    public function update(Request $request, Project $project): RedirectResponse|JsonResponse
    {
        $data = $this->validated($request, $project);
        $user = $request->user();

        $saved = DB::transaction(function () use ($data, $project, $user) {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            abort_unless($locked->status === ProjectStatus::DRAFT, 403);
            if ((int) $locked->version !== (int) $data['version']) {
                return false;
            }

            $before = $this->snapshot($locked);
            $locked->forceFill($this->calculate($data, $locked) + ['version' => $locked->version + 1])->save();
            $this->planInvoices($locked, $data['billing_percentages'], $data['planned_billing_dates'], $user->id);
            Audit::record(AuditAction::UPDATE, 'PROJECT', $locked->id, $before, $this->snapshot($locked->refresh()));

            return true;
        });

        if (! $saved) {
            $message = 'Project sudah diubah pengguna lain. Muat ulang halaman, lalu ulangi perubahan Anda.';

            return $request->expectsJson() ? response()->json(['message' => $message], 409) : back()->withInput()->withErrors(['version' => $message]);
        }

        return redirect()->route('projects.edit', $project)->with('status', 'Project draft diperbarui.');
    }

    private function form(Project $project): View
    {
        return view('projects.form', [
            'project' => $project,
            'pksList' => Pks::query()->where('status', PksStatus::ACTIVE)->orderBy('pks_number')->get(['id', 'pks_number', 'name']),
            'contacts' => BouwheerContact::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'company_name']),
            'invoices' => $project->exists ? DB::table('invoices')->where('project_id', $project->id)->orderBy('sequence_number')->get() : collect(),
            'maxPayments' => self::MAX_PAYMENTS,
            // Hanya untuk pratinjau di browser; server tetap memilih tarif sendiri saat simpan.
            'ppnRates' => TaxRate::query()->where('tax_type', 'PPN')->whereNull('vendor_qualification')->where('is_active', true)->get(['rate', 'effective_from', 'effective_until']),
        ]);
    }

    /** Semua nilai turunan (PRD 5.2, REV5.7). Request tidak pernah menentukan margin base, RAB, atau snapshot. */
    private function calculate(array $d, ?Project $current = null): array
    {
        $contract = Money::round($d['contract_value']);
        $snapshot = null;
        $base = $contract; // INCLUSIVE_PPN dan NON_PPN

        if ($d['contract_tax_mode'] === 'EXCLUSIVE_PPN') {
            $ppn = TaxRate::inForce('PPN', $d['spk_date'])->first()
                ?? throw ValidationException::withMessages(['spk_date' => 'Tarif PPN yang berlaku pada tanggal SPK belum ada. Tambahkan lebih dulu di menu Tarif Pajak.']);
            $snapshot = bcadd($ppn->rate, '0', 4);
            $base = Money::addTax($contract, $ppn->rate);
        }

        if ($d['margin_mode'] === 'PCT') {
            $pct = bcadd($d['margin_value'], '0', 4);
            $margin = Money::percentOf($base, $pct);
        } else {
            $margin = Money::round($d['margin_value']);
            if (Money::compare($margin, $base) > 0) {
                throw ValidationException::withMessages(['margin_value' => 'Target margin tidak boleh melebihi dasar target margin ('.Money::format($base).').']);
            }
            // Simpan ulang tanpa perubahan (form ubah mengirim nominal): persentase asli dipertahankan, bukan dihitung ulang dari nominal yang sudah dibulatkan.
            $unchanged = $current && Money::compare($current->margin_base_amount, $base) === 0 && Money::compare($current->target_margin_amount, $margin) === 0;
            $pct = $unchanged ? $current->target_margin_pct : Money::ratioPct($margin, $base);
        }

        $rab = Money::sub($base, $margin);

        return Arr::only($d, ['pks_id', 'bouwheer_contact_id', 'project_code', 'project_name', 'location', 'spk_number', 'spk_date', 'contract_tax_mode', 'payment_type', 'start_date', 'end_date']) + [
            'contract_value' => $contract,
            'initial_contract_value' => $contract, // baseline SPK; hanya ditulis saat DRAFT (calculate dipakai store/update DRAFT)
            'margin_base_ppn_rate_snapshot' => $snapshot,
            'margin_base_amount' => $base,
            'target_margin_pct' => $pct,
            'target_margin_amount' => $margin,
            'initial_rab' => $rab,
            'effective_rab' => $rab,
            'payment_count' => count($d['billing_percentages']),
        ];
    }

    /** Jadwal invoice PLANNED: satu baris per persentase, baris terakhir menyerap selisih pembulatan. */
    private function planInvoices(Project $project, array $percentages, ?array $plannedDates, int $userId): void
    {
        $amounts = Money::allocate($project->margin_base_amount, $percentages);
        // Pada margin base sangat kecil, pembulatan bisa membuat baris terakhir negatif; kolom planned_amount tidak boleh negatif.
        if (str_starts_with(end($amounts), '-')) {
            throw ValidationException::withMessages(['billing_percentages' => 'Dasar target margin terlalu kecil untuk dibagi ke '.count($percentages).' penagihan.']);
        }
        $existingRows = DB::table('invoices')->where('project_id', $project->id)->orderBy('sequence_number')->get(['id', 'planned_billing_date']);
        $existing = $existingRows->pluck('id')->all();
        // Kunci tanggal tidak dikirim (null) = pertahankan tanggal tersimpan per urutan termin; hanya isian kosong yang eksplisit menghapus.
        $plannedDates ??= $existingRows->pluck('planned_billing_date')->all();

        foreach ($percentages as $i => $pct) {
            $row = ['billing_type' => $project->payment_type, 'billing_percentage' => $pct, 'planned_amount' => $amounts[$i], 'planned_billing_date' => $plannedDates[$i] ?? null];
            isset($existing[$i])
                ? DB::table('invoices')->where('id', $existing[$i])->update($row)
                : DB::table('invoices')->insert($row + ['project_id' => $project->id, 'sequence_number' => $i + 1, 'department' => $project->department->value, 'workflow_status' => 'PLANNED', 'created_by' => $userId]);
        }

        // Boleh dihapus karena project masih DRAFT dan barisnya baru rencana (PLANNED), belum tagihan.
        DB::table('invoices')->where('project_id', $project->id)->where('sequence_number', '>', count($percentages))->where('workflow_status', 'PLANNED')->delete();
    }

    private function snapshot(Project $project): array
    {
        return Arr::except($project->attributesToArray(), ['id', 'created_at', 'updated_at']) + [
            'billing_percentages' => DB::table('invoices')->where('project_id', $project->id)->orderBy('sequence_number')->pluck('billing_percentage')->all(),
            'planned_billing_dates' => DB::table('invoices')->where('project_id', $project->id)->orderBy('sequence_number')->pluck('planned_billing_date')->all(),
        ];
    }

    private function validated(Request $request, ?Project $project = null): array
    {
        $amountMode = $request->input('margin_mode') === 'AMOUNT';

        $data = $request->validate([
            'pks_id' => ['bail', 'required', 'integer', Rule::exists('pks', 'id')->where('status', PksStatus::ACTIVE->value)],
            'bouwheer_contact_id' => ['bail', 'required', 'integer', Rule::exists('bouwheer_contacts', 'id')->where('is_active', true)],
            'project_code' => ['bail', 'required', 'string', 'max:100', Rule::unique('projects', 'project_code')->ignore($project)],
            'project_name' => ['bail', 'required', 'string', 'max:200'],
            'location' => ['bail', 'required', 'string', 'max:255'],
            'spk_number' => ['bail', 'required', 'string', 'max:100'],
            'spk_date' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'contract_value' => ['bail', 'required', 'string', 'decimal:0,2', 'min:0', 'max:999999999999999'], // di bawah batas kolom DECIMAL(18,2)
            'contract_tax_mode' => ['bail', 'required', 'string', 'in:INCLUSIVE_PPN,EXCLUSIVE_PPN,NON_PPN'],
            'margin_mode' => ['bail', 'required', 'string', 'in:PCT,AMOUNT'],
            'margin_value' => ['bail', 'required', 'string', $amountMode ? 'decimal:0,2' : 'decimal:0,4', 'min:0', $amountMode ? 'max:999999999999999' : 'max:100'],
            'payment_type' => ['bail', 'required', 'string', 'in:TERMIN,MONTHLY'],
            'billing_percentages' => ['bail', 'required', 'array', 'min:1', 'max:'.self::MAX_PAYMENTS],
            'billing_percentages.*' => ['bail', 'required', 'string', 'decimal:0,4', 'gt:0'],
            'planned_billing_dates' => ['nullable', 'array', 'max:'.self::MAX_PAYMENTS], // sejajar dengan billing_percentages; kosong = belum direncanakan
            'planned_billing_dates.*' => ['nullable', 'string', ...InvoiceController::PLANNED_DATE_RULES],
            'start_date' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'end_date' => ['bail', 'required', 'string', 'date_format:Y-m-d', Rule::when(is_string($request->input('start_date')), 'after_or_equal:start_date')], // start_date bertipe array merusak pembanding
        ] + ($project ? ['version' => ['bail', 'required', 'integer']] : []), [
            'pks_id.required' => 'PKS wajib dipilih.',
            'pks_id.exists' => 'PKS harus berstatus '.PksStatus::ACTIVE->label().'.',
            'bouwheer_contact_id.required' => 'PIC Bouwheer wajib dipilih.',
            'bouwheer_contact_id.exists' => 'PIC Bouwheer harus aktif.',
            'project_code.required' => 'Kode project wajib diisi.',
            'project_code.unique' => 'Kode project sudah dipakai.',
            'project_name.required' => 'Nama project wajib diisi.',
            'location.required' => 'Lokasi wajib diisi.',
            'spk_number.required' => 'Nomor SPK wajib diisi.',
            'spk_date.required' => 'Tanggal SPK wajib diisi.',
            'spk_date.date_format' => 'Tanggal SPK tidak valid.',
            'contract_value.required' => 'Nilai Project wajib diisi.',
            'contract_value.decimal' => 'Nilai Project berupa angka dengan maksimal dua desimal.',
            'contract_value.min' => 'Nilai Project tidak boleh negatif.',
            'contract_value.max' => 'Nilai Project terlalu besar.',
            'contract_tax_mode.required' => 'Mode pajak kontrak wajib dipilih.',
            'contract_tax_mode.in' => 'Mode pajak kontrak tidak dikenal.',
            'margin_mode.in' => 'Target margin harus diisi sebagai persen atau nominal.',
            'margin_value.required' => 'Target margin wajib diisi.',
            'margin_value.decimal' => $amountMode ? 'Target margin nominal maksimal dua desimal.' : 'Target margin persen maksimal empat desimal.',
            'margin_value.min' => 'Target margin tidak boleh negatif.',
            'margin_value.max' => $amountMode ? 'Target margin terlalu besar.' : 'Target margin persen tidak boleh lebih dari 100.',
            'payment_type.in' => 'Tipe pembayaran harus TERMIN atau MONTHLY.',
            'billing_percentages.required' => 'Persentase penagihan wajib diisi.',
            'billing_percentages.min' => 'Persentase penagihan wajib diisi.',
            'billing_percentages.max' => 'Jumlah penagihan maksimal '.self::MAX_PAYMENTS.'.',
            'billing_percentages.*.required' => 'Persentase penagihan wajib diisi.',
            'billing_percentages.*.decimal' => 'Persentase penagihan berupa angka dengan maksimal empat desimal.',
            'billing_percentages.*.gt' => 'Persentase penagihan harus lebih dari 0.',
            'planned_billing_dates.*.date_format' => 'Tanggal rencana penagihan tidak valid.',
            'planned_billing_dates.*.after_or_equal' => 'Tanggal rencana penagihan tidak valid.',
            'planned_billing_dates.*.before_or_equal' => 'Tanggal rencana penagihan tidak valid.',
            'start_date.required' => 'Tanggal mulai wajib diisi.',
            'end_date.required' => 'Tanggal selesai wajib diisi.',
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.',
            // Pesan umum untuk aturan tipe dan panjang, supaya tidak ada pesan bahasa Inggris.
            'required' => ':attribute wajib diisi.',
            'string' => ':attribute tidak valid.',
            'integer' => ':attribute tidak valid.',
            'array' => ':attribute tidak valid.',
            'date_format' => ':attribute tidak valid.',
            'max' => ':attribute maksimal :max karakter.',
        ], [
            'pks_id' => 'PKS', 'bouwheer_contact_id' => 'PIC Bouwheer', 'project_code' => 'Kode project', 'project_name' => 'Nama project', 'location' => 'Lokasi',
            'spk_number' => 'Nomor SPK', 'spk_date' => 'Tanggal SPK', 'contract_value' => 'Nilai Project', 'contract_tax_mode' => 'Mode pajak kontrak',
            'margin_mode' => 'Mode target margin', 'margin_value' => 'Target margin', 'payment_type' => 'Tipe pembayaran', 'billing_percentages' => 'Persentase penagihan',
            'billing_percentages.*' => 'Persentase penagihan', 'planned_billing_dates.*' => 'Tanggal rencana penagihan', 'start_date' => 'Tanggal mulai', 'end_date' => 'Tanggal selesai', 'version' => 'Versi',
        ]);

        // Dibandingkan sebagai desimal, bukan float.
        $data['billing_percentages'] = array_map(fn ($p) => bcadd($p, '0', 4), array_values($data['billing_percentages']));
        $data['planned_billing_dates'] = array_key_exists('planned_billing_dates', $data) ? array_values($data['planned_billing_dates'] ?? []) : null;
        if (bccomp(array_reduce($data['billing_percentages'], fn ($sum, $p) => bcadd($sum, $p, 4), '0'), '100', 4) !== 0) {
            throw ValidationException::withMessages(['billing_percentages' => 'Total persentase penagihan harus tepat 100%.']);
        }

        return $data;
    }
}
