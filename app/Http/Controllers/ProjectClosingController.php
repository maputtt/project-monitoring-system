<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\BqStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Financial closing (CLS-01, PRD 5.8, AC-16/AC-17): COMPLETED → FINANCIALLY_CLOSED. Hak akses di route (can:approve).
 * Syarat dihitung oleh readiness(): layar memakainya untuk checklist, close() memakainya lagi di bawah kunci project.
 */
class ProjectClosingController extends Controller
{
    private const AUDITED = ['status', 'actual_profit_amount', 'financially_closed_by', 'financially_closed_at', 'version'];

    private const PENDING = ['SUBMITTED', 'REVISION_REQUIRED'];

    /**
     * Checklist closing dan angka finalnya.
     * Final Sisa RAB = effective_rab − Σ actual_cost BQ non-ARCHIVED; Actual Profit = target_margin_amount + Final Sisa RAB (boleh negatif).
     *
     * @return array{ready: bool, checks: list<array{label: string, blockers: list<string>}>, final_sisa: string, actual_profit: string}
     */
    public static function readiness(Project $project): array
    {
        $bq = DB::table('bq_items')->where('project_id', $project->id)->where('status', '<>', BqStatus::ARCHIVED->value)->orderBy('id')->get(['item_name', 'status', 'actual_cost']);
        $invoices = DB::table('invoices')->where('project_id', $project->id)->whereNotIn('workflow_status', ['PAID', 'CANCELLED'])->orderBy('sequence_number')->get(['sequence_number', 'workflow_status']);
        $pending = fn (string $table) => DB::table($table)->where('project_id', $project->id)->whereIn('status', self::PENDING)->count();

        $checks = [
            ['label' => 'Project berstatus '.ProjectStatus::COMPLETED->label(), 'blockers' => $project->status === ProjectStatus::COMPLETED ? [] : ['status saat ini '.$project->status->label()]],
            ['label' => 'Semua invoice '.InvoiceStatus::PAID->label().' atau '.InvoiceStatus::CANCELLED->label(), 'blockers' => $invoices->map(fn ($i) => "termin $i->sequence_number berstatus ".InvoiceStatus::from($i->workflow_status)->label())->all()],
            ['label' => 'Nilai tertagih sesuai termin yang tidak batal', 'blockers' => Money::compare($project->contract_value, $project->billableContractValue()) === 0 ? []
                : [count($project->cancelledSequences()).' termin batal belum dikoreksi; ajukan Koreksi Nilai Tertagih']],
            ['label' => 'Semua BQ '.BqStatus::APPROVED->label().' dengan realisasi biaya terisi', 'blockers' => [
                ...$bq->where('status', '<>', BqStatus::APPROVED->value)->map(fn ($b) => "$b->item_name berstatus ".BqStatus::from($b->status)->label().' (belum '.BqStatus::APPROVED->label().')')->all(),
                ...$bq->where('status', BqStatus::APPROVED->value)->whereNull('actual_cost')->map(fn ($b) => "$b->item_name belum punya realisasi biaya")->all(),
            ]],
            ['label' => 'Tidak ada pengajuan pending atau revisi', 'blockers' => array_values(array_filter([
                ($n = $pending('project_adjustments')) ? "$n penyesuaian atau koreksi belum selesai" : null,
                ($n = $pending('bq_budget_mutations')) ? "$n Relokasi Budget belum selesai" : null,
            ]))],
        ];
        $final = Money::sub($project->effective_rab, Money::add(...$bq->pluck('actual_cost')->filter(fn ($v) => $v !== null)->all()));

        return [
            'ready' => collect($checks)->every(fn ($c) => $c['blockers'] === []),
            'checks' => $checks,
            'final_sisa' => $final,
            'actual_profit' => Money::add($project->target_margin_amount, $final),
        ];
    }

    public function close(Request $request, Project $project): RedirectResponse
    {
        DB::transaction(function () use ($request, $project) {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            // Semua penulis BQ, invoice, mutasi, dan adjustment mengunci baris project lebih dulu, jadi angka di bawah stabil sampai commit.
            $r = self::readiness($locked);
            if (! $r['ready']) {
                throw ValidationException::withMessages(['closing' => collect($r['checks'])->filter(fn ($c) => $c['blockers'] !== [])
                    ->map(fn ($c) => 'Financial closing ditolak. '.$c['label'].': '.implode('; ', $c['blockers']).'.')->values()->all()]);
            }

            $before = Arr::only($locked->attributesToArray(), self::AUDITED);
            $locked->status->transitionTo(ProjectStatus::FINANCIALLY_CLOSED); // sudah COMPLETED (dicek di atas)
            $locked->forceFill([
                'status' => ProjectStatus::FINANCIALLY_CLOSED, 'actual_profit_amount' => $r['actual_profit'],
                'financially_closed_by' => $request->user()->id, 'financially_closed_at' => now(), 'version' => $locked->version + 1,
            ])->save();
            $closed = DB::table('bq_items')->where('project_id', $locked->id)->where('status', BqStatus::APPROVED->value)
                ->update(['status' => BqStatus::CLOSED->value, 'version' => DB::raw('version + 1')]);

            Audit::record(AuditAction::LOCK, 'PROJECT', $locked->id, $before,
                Arr::only($locked->refresh()->attributesToArray(), self::AUDITED) + ['final_sisa_rab' => $r['final_sisa'], 'closed_bq_count' => $closed]);
        });

        return redirect()->route('projects.show', $project)->with('status', 'Financial closing selesai. Seluruh nilai finansial project terkunci.');
    }
}
