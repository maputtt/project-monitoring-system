<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Tahap progres penagihan baru: INVOICE_FAC, KE_GA, TANDA_TERIMA. INVOICE tetap diterima (baris lama).
return new class extends Migration
{
    private const OLD = "'LAMPIRAN','BAPP','BAST','MEMO','INVOICE','MENUNGGU_BAYAR','LAINNYA'";

    private const NEW = "'LAMPIRAN','BAPP','BAST','MEMO','INVOICE','MENUNGGU_BAYAR','LAINNYA','INVOICE_FAC','KE_GA','TANDA_TERIMA'";

    public function up(): void
    {
        $this->replaceCheck(self::NEW);
    }

    public function down(): void
    {
        if (DB::table('project_progress')->whereIn('stage', ['INVOICE_FAC', 'KE_GA', 'TANDA_TERIMA'])->exists()) {
            throw new RuntimeException('Rollback ditolak: masih ada progres penagihan dengan tahap INVOICE_FAC/KE_GA/TANDA_TERIMA (riwayat, tidak boleh dihapus).');
        }

        $this->replaceCheck(self::OLD);
    }

    private function replaceCheck(string $stages): void
    {
        DB::statement('ALTER TABLE project_progress DROP CONSTRAINT chk_progress_billing');
        DB::statement("ALTER TABLE project_progress ADD CONSTRAINT chk_progress_billing CHECK (entry_type <> 'BILLING' OR (invoice_id IS NOT NULL AND stage IS NOT NULL AND stage IN ({$stages})))");
    }
};
