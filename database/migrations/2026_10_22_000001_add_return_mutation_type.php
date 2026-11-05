<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Revisi C2: mutasi RETURN_TO_UNALLOCATED_RAB mengembalikan budget BQ ke RAB Belum Dialokasikan, tanpa BQ tujuan (destination_bq_id NULL).
return new class extends Migration
{
    public function up(): void
    {
        $bad = DB::table('bq_budget_mutations')
            ->whereNull('destination_bq_id')
            ->orWhereColumn('source_bq_id', 'destination_bq_id')
            ->count();
        if ($bad > 0) {
            throw new RuntimeException("Migrasi dihentikan: {$bad} baris bq_budget_mutations tanpa tujuan atau dengan sumber = tujuan. Perbaiki data dahulu.");
        }

        DB::statement('ALTER TABLE bq_budget_mutations DROP CHECK chk_mutations_type');
        DB::statement('ALTER TABLE bq_budget_mutations DROP CHECK chk_mutations_source');
        Schema::table('bq_budget_mutations', function (Blueprint $table) {
            $table->dropForeign(['destination_bq_id']);
        });
        Schema::table('bq_budget_mutations', function (Blueprint $table) {
            $table->unsignedBigInteger('destination_bq_id')->nullable()->change();
        });
        Schema::table('bq_budget_mutations', function (Blueprint $table) {
            $table->foreign('destination_bq_id')->references('id')->on('bq_items')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE bq_budget_mutations ADD CONSTRAINT chk_mutations_type CHECK (mutation_type IN ('FROM_UNALLOCATED_RAB','TRANSFER_BETWEEN_BQ','RETURN_TO_UNALLOCATED_RAB','PROJECT_ADJUSTMENT','REVERSAL'))");
        DB::statement("ALTER TABLE bq_budget_mutations ADD CONSTRAINT chk_mutations_source CHECK ((mutation_type = 'RETURN_TO_UNALLOCATED_RAB') = (destination_bq_id IS NULL) AND (mutation_type NOT IN ('TRANSFER_BETWEEN_BQ','RETURN_TO_UNALLOCATED_RAB') OR source_bq_id IS NOT NULL) AND (source_bq_id IS NULL OR destination_bq_id IS NULL OR source_bq_id <> destination_bq_id))");
    }

    public function down(): void
    {
        if (DB::table('bq_budget_mutations')->where('mutation_type', 'RETURN_TO_UNALLOCATED_RAB')->exists()) {
            throw new RuntimeException('Rollback ditolak: masih ada mutasi RETURN_TO_UNALLOCATED_RAB (riwayat finansial, tidak boleh dihapus).');
        }

        DB::statement('ALTER TABLE bq_budget_mutations DROP CHECK chk_mutations_type');
        DB::statement('ALTER TABLE bq_budget_mutations DROP CHECK chk_mutations_source');
        Schema::table('bq_budget_mutations', function (Blueprint $table) {
            $table->dropForeign(['destination_bq_id']);
        });
        Schema::table('bq_budget_mutations', function (Blueprint $table) {
            $table->unsignedBigInteger('destination_bq_id')->nullable(false)->change();
        });
        Schema::table('bq_budget_mutations', function (Blueprint $table) {
            $table->foreign('destination_bq_id')->references('id')->on('bq_items')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE bq_budget_mutations ADD CONSTRAINT chk_mutations_type CHECK (mutation_type IN ('FROM_UNALLOCATED_RAB','TRANSFER_BETWEEN_BQ','PROJECT_ADJUSTMENT','REVERSAL'))");
        DB::statement("ALTER TABLE bq_budget_mutations ADD CONSTRAINT chk_mutations_source CHECK ((mutation_type <> 'TRANSFER_BETWEEN_BQ' OR source_bq_id IS NOT NULL) AND (source_bq_id IS NULL OR source_bq_id <> destination_bq_id))");
    }
};
