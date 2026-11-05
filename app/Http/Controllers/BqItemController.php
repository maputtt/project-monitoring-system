<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\BqStatus;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Item BQ draft dan BQ Operasional (BQ-01) serta realisasi biaya item APPROVED (BQ-03). Mutasi budget ada di BudgetMutationController. Tidak ada hapus. */
class BqItemController extends Controller
{
    /** Status project yang menerima dan mengubah item NORMAL (API.md). */
    public const OPEN_PROJECT_STATUSES = [ProjectStatus::DRAFT, ProjectStatus::ACTIVE, ProjectStatus::COMPLETED];

    public const EDITABLE_ITEM_STATUSES = [BqStatus::DRAFT, BqStatus::REVISION_REQUIRED];

    private const AUDITED = ['project_id', 'item_code', 'item_name', 'item_type', 'vendor_quote_amount', 'baseline_budget', 'effective_budget', 'negotiated_value', 'margin_amount', 'margin_pct', 'is_overbudget', 'status', 'version'];

    private const ACTUAL_AUDITED = ['actual_cost', 'is_overbudget', 'version'];

    private const MONEY = ['bail', 'string', 'decimal:0,2', 'min:0', 'max:999999999999999']; // di bawah batas kolom DECIMAL(18,2)

    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate($this->rules(operational: false), $this->messages());
        $user = $request->user();

        $warning = null;
        DB::transaction(function () use ($project, $data, $user, &$warning) {
            // Status dicek ulang di bawah kunci: policy berjalan sebelum kunci diambil.
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            abort_unless(in_array($locked->status, self::OPEN_PROJECT_STATUSES, true), 403, 'Item BQ hanya bisa ditambah saat project '.ProjectStatus::labelList(self::OPEN_PROJECT_STATUSES).'.');

            $id = DB::table('bq_items')->insertGetId([
                'project_id' => $locked->id, 'item_code' => $data['item_code'] ?? null, 'item_name' => $data['item_name'], 'item_type' => 'NORMAL', // tipe tidak pernah dari klien
                'vendor_quote_amount' => isset($data['vendor_quote_amount']) ? Money::round($data['vendor_quote_amount']) : null,
                'status' => BqStatus::DRAFT->value, 'created_by' => $user->id,
            ] + $this->figures($data['baseline_budget'], $data['negotiated_value']));

            Audit::record(AuditAction::CREATE, 'BQ_ITEM', $id, after: $this->snapshot($id));
            $warning = $locked->overAllocationWarning();
        });

        return redirect()->route('projects.show', $project)->with('status', 'Item BQ ditambahkan sebagai '.BqStatus::DRAFT->label().'.')->with('warning', $warning);
    }

    public function update(Request $request, int $bq): RedirectResponse|JsonResponse
    {
        // Item milik project di luar jangkauan menjawab 404, sama seperti route {project}.
        $current = DB::table('bq_items')->where('id', $bq)->first();
        $project = Project::query()->visibleTo($request->user())->findOrFail($current?->project_id);
        Gate::authorize('contribute', $project);

        $operational = $current->item_type === 'OPERATIONAL';
        $data = $request->validateWithBag("bq-$bq", $this->rules($operational) + ['version' => ['bail', 'required', 'integer']], $this->messages());

        $warning = null;
        $saved = DB::transaction(function () use ($project, $bq, $data, $operational, &$warning) {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $row = DB::table('bq_items')->where('id', $bq)->lockForUpdate()->first();

            if ($operational) {
                abort_unless($lockedProject->status === ProjectStatus::DRAFT, 403, 'Budget operasional hanya bisa diisi saat project '.ProjectStatus::DRAFT->label().'. Perubahan setelahnya lewat Relokasi Budget.');
            } else {
                abort_unless(in_array($lockedProject->status, self::OPEN_PROJECT_STATUSES, true), 403, 'Item BQ hanya bisa diubah saat project '.ProjectStatus::labelList(self::OPEN_PROJECT_STATUSES).'.');
            }
            abort_unless(in_array(BqStatus::from($row->status), self::EDITABLE_ITEM_STATUSES, true), 409, 'Item BQ berstatus '.BqStatus::from($row->status)->label().' tidak bisa diubah.');
            if ((int) $row->version !== (int) $data['version']) {
                return false;
            }

            $before = $this->snapshot($bq);
            $changes = $operational
                ? array_merge($this->figures($data['baseline_budget'], '0'), ['margin_amount' => '0.00', 'margin_pct' => '0.0000']) // cadangan operasional: tanpa nilai nego, jadi tanpa margin
                : ['item_code' => $data['item_code'] ?? null, 'item_name' => $data['item_name'], 'vendor_quote_amount' => isset($data['vendor_quote_amount']) ? Money::round($data['vendor_quote_amount']) : null]
                    + $this->figures($data['baseline_budget'], $data['negotiated_value']);
            DB::table('bq_items')->where('id', $bq)->update($changes + ['version' => $row->version + 1]);

            Audit::record(AuditAction::UPDATE, 'BQ_ITEM', $bq, $before, $this->snapshot($bq));
            $warning = $lockedProject->overAllocationWarning();

            return true;
        });

        if (! $saved) {
            $message = 'Item BQ sudah diubah pengguna lain. Muat ulang halaman, lalu ulangi perubahan Anda.';

            return $request->expectsJson() ? response()->json(['message' => $message], 409) : back()->withInput()->withErrors(['version' => $message], "bq-$bq");
        }

        return redirect()->route('projects.show', $project)->with('status', 'Item BQ diperbarui.')->with('warning', $warning);
    }

    /**
     * Realisasi biaya (BQ-03, AC-27). Hanya item APPROVED (kedua tipe) saat project ACTIVE/COMPLETED; item CLOSED terkunci. Tidak menyentuh budget, margin, atau Sisa RAB.
     * Isian pertama tanpa alasan; nilai yang berubah wajib beralasan; nilai yang sama = tidak ada perubahan (tanpa versi baru atau audit).
     */
    public function actualCost(Request $request, int $bq): RedirectResponse|JsonResponse
    {
        $current = DB::table('bq_items')->where('id', $bq)->first();
        $project = Project::query()->visibleTo($request->user())->findOrFail($current?->project_id);
        Gate::authorize('contribute', $project);

        $data = $request->validateWithBag("bq-$bq", ['actual_cost' => ['required', ...self::MONEY], 'reason' => ['nullable', 'string', 'max:1000'], 'version' => ['bail', 'required', 'integer']], $this->messages());

        $result = DB::transaction(function () use ($project, $bq, $data) {
            // Urutan kunci project lalu item; semua dicek ulang di bawah kunci.
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $row = DB::table('bq_items')->where('id', $bq)->lockForUpdate()->first();

            abort_unless(in_array($lockedProject->status, BqWorkflowController::WORKFLOW_PROJECT_STATUSES, true), 403, 'Realisasi biaya hanya bisa diisi saat project '.ProjectStatus::labelList(BqWorkflowController::WORKFLOW_PROJECT_STATUSES).'.');
            abort_unless($row->status === BqStatus::APPROVED->value, 409, 'Realisasi biaya hanya untuk item '.BqStatus::APPROVED->label().', item ini berstatus '.BqStatus::from($row->status)->label().'.');
            if ((int) $row->version !== (int) $data['version']) {
                return 'stale';
            }

            $new = Money::round($data['actual_cost']);
            if ($row->actual_cost !== null) {
                if (Money::compare($row->actual_cost, $new) === 0) {
                    return 'same';
                }
                if (blank($data['reason'] ?? null)) {
                    throw ValidationException::withMessages(['reason' => 'Alasan wajib diisi untuk mengubah realisasi biaya yang sudah ada.'])->errorBag("bq-$bq");
                }
            }

            $before = Arr::only((array) $row, self::ACTUAL_AUDITED);
            DB::table('bq_items')->where('id', $bq)->update(['actual_cost' => $new, 'is_overbudget' => Money::compare($new, $row->effective_budget) > 0, 'version' => $row->version + 1]);
            Audit::record(AuditAction::UPDATE, 'BQ_ITEM', $bq, $before, Arr::only((array) DB::table('bq_items')->where('id', $bq)->first(), self::ACTUAL_AUDITED), $data['reason'] ?? null);

            return 'saved';
        });

        if ($result === 'stale') {
            $message = 'Item BQ sudah diubah pengguna lain. Muat ulang halaman, lalu ulangi perubahan Anda.';

            return $request->expectsJson() ? response()->json(['message' => $message], 409) : back()->withInput()->withErrors(['version' => $message], "bq-$bq");
        }

        return redirect()->route('projects.show', $project)->with('status', $result === 'same' ? 'Realisasi biaya tidak berubah.' : 'Realisasi biaya disimpan.');
    }

    /**
     * Nilai turunan (PRD 5.3; basis margin = effective budget). Sebelum ada mutasi, effective_budget = baseline_budget.
     *
     * @return array<string, string|bool>
     */
    private function figures(string $baseline, string $negotiated): array
    {
        $baseline = Money::round($baseline);
        $negotiated = Money::round($negotiated);

        return ['baseline_budget' => $baseline, 'effective_budget' => $baseline, 'negotiated_value' => $negotiated] + self::marginFigures($baseline, $negotiated);
    }

    /** Sisa item = Nilai RAB - (realisasi bila terisi, selain itu Nilai Vendor/Subkon). Satu definisi: tampilan, batas relokasi, dan overbudget. */
    public static function sisa(object $item): string
    {
        return Money::sub($item->effective_budget, $item->actual_cost ?? $item->negotiated_value);
    }

    /**
     * Margin dari effective budget dan nilai nego; dipakai juga oleh approve mutasi budget (BQ-04) supaya angkanya konsisten.
     * Margin tetap di atas nilai nego; overbudget = (realisasi ?? nego) > effective, yaitu Sisa < 0.
     *
     * @return array<string, string|bool>
     */
    public static function marginFigures(string $effective, string $negotiated, ?string $actual = null): array
    {
        $margin = Money::sub($effective, $negotiated);
        $pct = Money::ratioPct($margin, $effective);
        // shortcut: persen dijepit ke rentang DECIMAL(7,4) (nego > ~11x budget); margin_amount tetap tepat. Ubah bila ada kebutuhan persen tak terbatas.
        $pct = Money::compare($pct, '-999.9999') < 0 ? '-999.9999' : $pct;

        return ['margin_amount' => $margin, 'margin_pct' => $pct, 'is_overbudget' => Money::compare($actual ?? $negotiated, $effective) > 0];
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $operational): array
    {
        return $operational
            ? ['baseline_budget' => ['required', ...self::MONEY]]
            : [
                'item_name' => ['bail', 'required', 'string', 'max:200'],
                'item_code' => ['bail', 'nullable', 'string', 'max:100'],
                'vendor_quote_amount' => ['nullable', ...self::MONEY],
                'baseline_budget' => ['required', ...self::MONEY],
                'negotiated_value' => ['required', ...self::MONEY],
            ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'item_name.required' => 'Nama pekerjaan wajib diisi.',
            'item_name.max' => 'Nama pekerjaan maksimal :max karakter.',
            'item_code.max' => 'Kode item maksimal :max karakter.',
            'baseline_budget.required' => 'Nilai RAB wajib diisi.',
            'negotiated_value.required' => 'Nilai Vendor/Subkon wajib diisi.',
            '*.decimal' => 'Isi dengan angka maksimal dua desimal.',
            '*.min' => 'Nilai tidak boleh negatif.',
            '*.max' => 'Nilai terlalu besar.',
            'actual_cost.required' => 'Realisasi biaya wajib diisi.',
            'reason.max' => 'Alasan maksimal :max karakter.',
            'version.required' => 'Versi data tidak ada. Muat ulang halaman.',
        ];
    }

    private function snapshot(int $id): array
    {
        return Arr::only((array) DB::table('bq_items')->where('id', $id)->first(), self::AUDITED);
    }
}
