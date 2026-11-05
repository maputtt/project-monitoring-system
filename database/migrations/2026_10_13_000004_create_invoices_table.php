<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// invoices (PRD 16.2.10 + REV5.3: nomor invoice unik per divisi).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('department', 10); // REV5.3: disalin dari project agar nomor invoice bisa unik per divisi
            $table->integer('sequence_number');
            $table->string('billing_type', 20);
            $table->string('description')->nullable();
            $table->date('planned_billing_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('billing_percentage', 7, 4)->nullable();
            $table->decimal('planned_amount', 18, 2);
            $table->foreignId('ppn_tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            $table->foreignId('pph_tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            $table->string('invoice_number', 100)->nullable();
            $table->string('bapp_number', 100)->nullable();
            $table->string('bast_number', 100)->nullable();
            $table->date('billing_date')->nullable();
            $table->decimal('ppn_rate_snapshot', 7, 4)->nullable();
            $table->decimal('pph_rate_snapshot', 7, 4)->nullable();
            $table->decimal('dpp_amount', 18, 2)->nullable();
            $table->decimal('ppn_amount', 18, 2)->nullable();
            $table->decimal('pph_amount', 18, 2)->nullable();
            $table->decimal('subtotal_amount', 18, 2)->nullable();
            $table->decimal('net_received_amount', 18, 2)->nullable();
            $table->string('workflow_status', 32)->default('PLANNED');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('revision_requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revision_requested_at')->nullable();
            $table->text('revision_reason')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('unlocked_at')->nullable();
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('unlock_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->integer('version')->default(1);
            $table->unique(['project_id', 'sequence_number']);
            $table->unique(['department', 'invoice_number']); // nomor diberikan FAC per divisi; NULL boleh berulang
            $table->index(['project_id', 'workflow_status']);
            $table->index(['due_date', 'workflow_status']);
        });
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT chk_invoices_department CHECK (department IN ('BM','CP','ITBS'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT chk_invoices_billing_type CHECK (billing_type IN ('TERMIN','MONTHLY'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT chk_invoices_status CHECK (workflow_status IN ('PLANNED','INVOICE_SUBMITTED','INVOICE_REVISION_REQUIRED','INVOICE_VERIFIED','WAITING_FOR_PAYMENT','PAYMENT_SUBMITTED','PAYMENT_REVISION_REQUIRED','PAID','CANCELLED'))");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT chk_invoices_sequence CHECK (sequence_number > 0)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT chk_invoices_percentage CHECK (billing_percentage IS NULL OR (billing_percentage >= 0 AND billing_percentage <= 100))');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT chk_invoices_money CHECK (planned_amount >= 0 AND COALESCE(dpp_amount, 0) >= 0 AND COALESCE(ppn_amount, 0) >= 0 AND COALESCE(pph_amount, 0) >= 0 AND COALESCE(subtotal_amount, 0) >= 0 AND COALESCE(net_received_amount, 0) >= 0)');
        // Invoice aktual wajib punya nomor, tanggal penagihan, dan snapshot tarif.
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT chk_invoices_submitted_complete CHECK (workflow_status IN ('PLANNED','CANCELLED') OR (invoice_number IS NOT NULL AND billing_date IS NOT NULL AND ppn_rate_snapshot IS NOT NULL AND pph_rate_snapshot IS NOT NULL))");
        // PAID selalu terkunci.
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT chk_invoices_paid_locked CHECK (workflow_status <> 'PAID' OR (locked_at IS NOT NULL AND locked_by IS NOT NULL))");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT chk_invoices_no_self_approval CHECK (approved_by IS NULL OR submitted_by IS NULL OR approved_by <> submitted_by)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
