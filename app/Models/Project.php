<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\BqStatus;
use App\Enums\Department;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Support\Money;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    // Tetap tertutup: nilai hitung (department, status, margin, RAB, version) diisi server lewat forceFill di ProjectController.
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'department' => Department::class,
            'status' => ProjectStatus::class,
            'spk_date' => 'date:Y-m-d',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'contract_value' => 'decimal:2',
            'initial_contract_value' => 'decimal:2',
            'margin_base_amount' => 'decimal:2',
            'initial_rab' => 'decimal:2',
            'effective_rab' => 'decimal:2',
            'target_margin_amount' => 'decimal:2',
            'actual_profit_amount' => 'decimal:2',
        ];
    }

    /**
     * Satu-satunya aturan "project mana yang boleh dilihat user ini" (PRD FD-16, AC-19, AC-23).
     * Semua daftar, detail, pencarian, dan unduhan dokumen wajib melewati scope ini.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        match ($user->role) {
            Role::DIREKSI, Role::SYSTEM_ADMIN => null, // semua divisi, read-only
            Role::MANAGER_GM => $query->where('projects.department', $user->department),
            Role::STAFF_PIC => $query->where('projects.department', $user->department)
                ->whereExists(fn ($assignment) => $assignment->from('project_user_assignments')
                    ->whereColumn('project_user_assignments.project_id', 'projects.id')
                    ->where('project_user_assignments.user_id', $user->id)
                    ->where('project_user_assignments.is_active', true)),
        };
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    /** Tabel projects tidak punya submitted_by; siapa pun yang pernah SUBMIT (baris audit mana pun) tidak boleh mengaktifkan. */
    public function hasBeenSubmittedBy(User $user): bool
    {
        return DB::table('audit_logs')->where('entity_type', 'PROJECT')->where('entity_id', $this->getKey())
            ->where('action', AuditAction::SUBMIT->value)->where('user_id', $user->id)->exists();
    }

    /**
     * Alokasi RAB: Σ effective_budget SEMUA item BQ yang tidak diarsipkan (DRAFT, SUBMITTED, REVISION_REQUIRED, APPROVED, CLOSED).
     * Satu definisi untuk kartu Sisa RAB (RAB Perubahan − ini) dan guard keras RAB (approve BQ, relokasi dari Sisa RAB, approve penyesuaian margin, aktivasi). Uji coba PM 2026-11-02. Panggil di bawah kunci project.
     */
    public function allocatedBudget(): string
    {
        return Money::add(DB::table('bq_items')->where('project_id', $this->getKey())->where('status', '<>', 'ARCHIVED')->selectRaw('COALESCE(SUM(effective_budget), 0) AS total')->value('total'));
    }

    /** Kalimat penyebab over-alokasi: sampai 3 item belum disetujui terbesar (nama apa adanya; Blade yang meng-escape). Kosong bila tidak ada. */
    public function unapprovedAllocationNote(): string
    {
        $rows = DB::table('bq_items')->where('project_id', $this->getKey())->whereIn('status', ['DRAFT', 'SUBMITTED', 'REVISION_REQUIRED'])
            ->orderByDesc('effective_budget')->orderBy('id')->get(['item_name', 'status', 'effective_budget']);
        if ($rows->isEmpty()) {
            return '';
        }
        $list = $rows->take(3)->map(fn ($r) => $r->item_name.' ('.BqStatus::from($r->status)->label().', '.Money::format($r->effective_budget).')')->implode('; ');

        return ' Item yang belum disetujui: '.$list.($rows->count() > 3 ? '; +'.($rows->count() - 3).' lainnya' : '').'.';
    }

    /** Peringatan setelah staff menyimpan item BQ: Sisa RAB (RAB Perubahan − alokasi) negatif. Null bila tidak negatif. Panggil di bawah kunci project. */
    public function overAllocationWarning(): ?string
    {
        $sisa = Money::sub($this->effective_rab, $this->allocatedBudget());

        return Money::compare($sisa, '0') < 0
            ? 'Perhatian: Sisa RAB menjadi '.Money::format($sisa).'; item BQ belum bisa disetujui sebelum total Nilai RAB ≤ RAB Perubahan.'
            : null;
    }

    /** Σ effective_budget item APPROVED dan CLOSED saja; tidak lagi dipakai guard RAB, hanya peringatan koreksi nilai tertagih. */
    public function committedBudget(): string
    {
        return Money::add(DB::table('bq_items')->where('project_id', $this->getKey())->whereIn('status', ['APPROVED', 'CLOSED'])->selectRaw('COALESCE(SUM(effective_budget), 0) AS total')->value('total'));
    }

    /**
     * Nilai kontrak yang masih bisa ditagih: bagian SPK awal (initial_contract_value) milik termin yang tidak CANCELLED, dengan pembagian yang sama dengan jadwal invoice.
     * Sama dengan contract_value selama tidak ada termin batal yang belum dikoreksi. Project tanpa termin dianggap tidak ada koreksi.
     */
    public function billableContractValue(): string
    {
        $rows = DB::table('invoices')->where('project_id', $this->getKey())->orderBy('sequence_number')->get(['billing_percentage', 'workflow_status']);
        if ($rows->isEmpty()) {
            return $this->contract_value;
        }
        $shares = Money::allocate($this->initial_contract_value, $rows->pluck('billing_percentage')->all());

        return Money::add(...collect($shares)->filter(fn ($share, $i) => $rows[$i]->workflow_status !== 'CANCELLED')->all());
    }

    /** Dasar target margin saat aktivasi: dari nilai SPK awal (EXCLUSIVE_PPN ditambah PPN pada snapshot). Dipakai Target Margin Awal; tidak bergantung koreksi nilai tertagih. */
    public function initialMarginBase(): string
    {
        return $this->contract_tax_mode === 'EXCLUSIVE_PPN' ? Money::addTax($this->initial_contract_value, $this->margin_base_ppn_rate_snapshot ?? 0) : Money::round($this->initial_contract_value);
    }

    /** Nomor termin yang CANCELLED, urut. */
    public function cancelledSequences(): array
    {
        return DB::table('invoices')->where('project_id', $this->getKey())->where('workflow_status', 'CANCELLED')->orderBy('sequence_number')->pluck('sequence_number')->map(fn ($n) => (int) $n)->all();
    }

    /** Project yang masih berjalan; yang sudah ditutup, diarsip, atau dibatalkan tidak lagi butuh Staff/PIC. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('projects.status', [ProjectStatus::FINANCIALLY_CLOSED->value, ProjectStatus::ARCHIVED->value, ProjectStatus::CANCELLED->value]);
    }

    /** Staff/PIC yang masih berguna: assignment aktif, akun aktif, role STAFF_PIC, divisi sama dengan project. */
    public function usablePics(): Builder
    {
        return User::query()->where('is_active', true)->where('role', Role::STAFF_PIC)->where('department', $this->department)
            ->whereIn('id', DB::table('project_user_assignments')->where('project_id', $this->getKey())->where('is_active', true)->select('user_id'));
    }

    /**
     * Ada Staff/PIC berguna selain $userId? Dua locking read tanpa subquery: membaca baris terbaru yang sudah commit,
     * bukan snapshot REPEATABLE READ yang mungkin lebih tua dari kunci project. Panggil setelah baris project dikunci.
     */
    public function hasOtherUsablePic(int $userId): bool
    {
        $assigned = DB::table('project_user_assignments')->where('project_id', $this->getKey())->where('is_active', true)->lockForUpdate()->pluck('user_id');

        return User::query()->whereIn('id', $assigned)->where('id', '<>', $userId)->where('is_active', true)->where('role', Role::STAFF_PIC)
            ->where('department', $this->department)->lockForUpdate()->get(['id'])->isNotEmpty();
    }
}
