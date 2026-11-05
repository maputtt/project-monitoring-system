<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// bq_items (PRD 16.2.7), bq_budget_mutations (16.2.8).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bq_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('item_code', 100)->nullable();
            $table->string('item_name', 200);
            $table->string('item_type', 30)->default('NORMAL');
            $table->decimal('vendor_quote_amount', 18, 2)->nullable();
            $table->decimal('baseline_budget', 18, 2)->default(0);
            $table->decimal('actual_cost', 18, 2)->nullable(); // label UI: Realisasi biaya
            $table->decimal('effective_budget', 18, 2)->default(0);
            $table->decimal('negotiated_value', 18, 2)->default(0);
            $table->decimal('margin_amount', 18, 2)->default(0); // negatif bila overbudget
            $table->decimal('margin_pct', 7, 4)->default(0);
            $table->boolean('is_overbudget')->default(false);
            $table->string('status', 20)->default('DRAFT');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->integer('version')->default(1);
            // Tepat satu BQ OPERATIONAL aktif per project: kolom ini hanya terisi untuk item itu, lalu dibuat unik.
            $table->unsignedBigInteger('operational_project_id')->nullable()
                ->virtualAs("CASE WHEN item_type = 'OPERATIONAL' AND status <> 'ARCHIVED' THEN project_id END");
            $table->unique('operational_project_id', 'uq_bq_items_one_operational');
            $table->index(['project_id', 'status']);
        });
        DB::statement("ALTER TABLE bq_items ADD CONSTRAINT chk_bq_items_type CHECK (item_type IN ('NORMAL','OPERATIONAL'))");
        DB::statement("ALTER TABLE bq_items ADD CONSTRAINT chk_bq_items_status CHECK (status IN ('DRAFT','SUBMITTED','REVISION_REQUIRED','APPROVED','CLOSED','ARCHIVED'))");
        // effective_budget >= 0 juga yang menolak mutasi yang membuat saldo sumber negatif.
        DB::statement('ALTER TABLE bq_items ADD CONSTRAINT chk_bq_items_money CHECK (baseline_budget >= 0 AND effective_budget >= 0 AND negotiated_value >= 0 AND (vendor_quote_amount IS NULL OR vendor_quote_amount >= 0) AND (actual_cost IS NULL OR actual_cost >= 0))');

        Schema::create('bq_budget_mutations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('source_bq_id')->nullable()->constrained('bq_items')->restrictOnDelete(); // null = dari Sisa RAB
            $table->foreignId('destination_bq_id')->constrained('bq_items')->restrictOnDelete();
            $table->string('mutation_type', 30);
            $table->decimal('amount', 18, 2);
            $table->text('reason');
            $table->string('status', 20)->default('DRAFT');
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->useCurrent();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('revision_requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revision_requested_at')->nullable();
            $table->text('revision_reason')->nullable();
            $table->foreignId('reversed_mutation_id')->nullable()->constrained('bq_budget_mutations')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['project_id', 'status']);
        });
        DB::statement("ALTER TABLE bq_budget_mutations ADD CONSTRAINT chk_mutations_type CHECK (mutation_type IN ('FROM_UNALLOCATED_RAB','TRANSFER_BETWEEN_BQ','PROJECT_ADJUSTMENT','REVERSAL'))");
        DB::statement("ALTER TABLE bq_budget_mutations ADD CONSTRAINT chk_mutations_status CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','REVISION_REQUIRED','REVERSED'))");
        DB::statement('ALTER TABLE bq_budget_mutations ADD CONSTRAINT chk_mutations_amount CHECK (amount > 0)');
        DB::statement("ALTER TABLE bq_budget_mutations ADD CONSTRAINT chk_mutations_source CHECK ((mutation_type <> 'TRANSFER_BETWEEN_BQ' OR source_bq_id IS NOT NULL) AND (source_bq_id IS NULL OR source_bq_id <> destination_bq_id))");
        DB::statement('ALTER TABLE bq_budget_mutations ADD CONSTRAINT chk_mutations_no_self_approval CHECK (approved_by IS NULL OR approved_by <> submitted_by)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bq_budget_mutations');
        Schema::dropIfExists('bq_items');
    }
};
