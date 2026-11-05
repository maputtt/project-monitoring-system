<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Revisi C4: tipe penyesuaian BILLING_PERCENTAGE (Koreksi Persentase Termin).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE project_adjustments DROP CHECK chk_adjustments_type');
        DB::statement("ALTER TABLE project_adjustments ADD CONSTRAINT chk_adjustments_type CHECK (adjustment_type IN ('CONTRACT_VALUE','RAB','TARGET_MARGIN','PROJECT_PERIOD','BILLING_PERCENTAGE'))");
    }

    public function down(): void
    {
        if (DB::table('project_adjustments')->where('adjustment_type', 'BILLING_PERCENTAGE')->exists()) {
            throw new RuntimeException('Rollback ditolak: masih ada penyesuaian BILLING_PERCENTAGE (riwayat finansial, tidak boleh dihapus).');
        }

        DB::statement('ALTER TABLE project_adjustments DROP CHECK chk_adjustments_type');
        DB::statement("ALTER TABLE project_adjustments ADD CONSTRAINT chk_adjustments_type CHECK (adjustment_type IN ('CONTRACT_VALUE','RAB','TARGET_MARGIN','PROJECT_PERIOD'))");
    }
};
