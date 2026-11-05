<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Progres dokumen penagihan per termin: entry_type BILLING pada project_progress (tahap dokumen, tanggal, catatan, penanda kendala). Baris tidak diubah/dihapus; koreksi = baris baru.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_progress', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('project_id')->constrained('invoices')->restrictOnDelete();
            $table->string('stage', 30)->nullable()->after('entry_type');
            $table->boolean('is_issue')->default(false)->after('stage');
        });
        DB::statement('ALTER TABLE project_progress DROP CHECK chk_progress_entry_type');
        DB::statement("ALTER TABLE project_progress ADD CONSTRAINT chk_progress_entry_type CHECK (entry_type IN ('WEEKLY_UPDATE','MILESTONE','BILLING'))");
        DB::statement("ALTER TABLE project_progress ADD CONSTRAINT chk_progress_billing CHECK (entry_type <> 'BILLING' OR (invoice_id IS NOT NULL AND stage IS NOT NULL AND stage IN ('LAMPIRAN','BAPP','BAST','MEMO','INVOICE','MENUNGGU_BAYAR','LAINNYA')))");
    }

    public function down(): void
    {
        if (DB::table('project_progress')->where('entry_type', 'BILLING')->exists()) {
            throw new RuntimeException('Rollback ditolak: masih ada progres penagihan (riwayat, tidak boleh dihapus).');
        }

        DB::statement('ALTER TABLE project_progress DROP CHECK chk_progress_billing');
        DB::statement('ALTER TABLE project_progress DROP CHECK chk_progress_entry_type');
        DB::statement("ALTER TABLE project_progress ADD CONSTRAINT chk_progress_entry_type CHECK (entry_type IN ('WEEKLY_UPDATE','MILESTONE'))");
        Schema::table('project_progress', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropColumn(['stage', 'is_issue']);
        });
    }
};
