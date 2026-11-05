<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// D-C-1: status WITHDRAWN ("Ditarik") untuk usulan adjustment dan mutasi budget BQ.
return new class extends Migration
{
    public function up(): void
    {
        $this->replace('project_adjustments', 'chk_adjustments_status', "'DRAFT','SUBMITTED','APPROVED','REVISION_REQUIRED','REVERSED','WITHDRAWN'");
        $this->replace('bq_budget_mutations', 'chk_mutations_status', "'DRAFT','SUBMITTED','APPROVED','REVISION_REQUIRED','REVERSED','WITHDRAWN'");
    }

    public function down(): void
    {
        foreach (['project_adjustments', 'bq_budget_mutations'] as $table) {
            if (DB::table($table)->where('status', 'WITHDRAWN')->exists()) {
                throw new RuntimeException("Rollback ditolak: masih ada baris WITHDRAWN di $table (riwayat finansial, tidak boleh dihapus).");
            }
        }

        $this->replace('project_adjustments', 'chk_adjustments_status', "'DRAFT','SUBMITTED','APPROVED','REVISION_REQUIRED','REVERSED'");
        $this->replace('bq_budget_mutations', 'chk_mutations_status', "'DRAFT','SUBMITTED','APPROVED','REVISION_REQUIRED','REVERSED'");
    }

    private function replace(string $table, string $check, string $values): void
    {
        DB::statement("ALTER TABLE $table DROP CHECK $check");
        DB::statement("ALTER TABLE $table ADD CONSTRAINT $check CHECK (status IN ($values))");
    }
};
