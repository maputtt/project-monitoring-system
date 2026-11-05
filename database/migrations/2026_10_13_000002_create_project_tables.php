<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// projects (PRD 16.2.4 + REV5.3), project_user_assignments (16.2.5), project_adjustments (16.2.6).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pks_id')->constrained('pks')->restrictOnDelete();
            $table->foreignId('bouwheer_contact_id')->constrained('bouwheer_contacts')->restrictOnDelete();
            $table->string('department', 10); // REV5.3: divisi pemilik, disalin dari pembuat
            $table->string('project_code', 100)->unique();
            $table->string('project_name', 200);
            $table->string('location');
            $table->string('spk_number', 100);
            $table->date('spk_date');
            $table->decimal('contract_value', 18, 2);
            $table->string('contract_tax_mode', 20);
            $table->decimal('margin_base_ppn_rate_snapshot', 7, 4)->nullable(); // REV5.3: hanya EXCLUSIVE_PPN
            $table->decimal('margin_base_amount', 18, 2);
            $table->decimal('initial_rab', 18, 2);
            $table->decimal('effective_rab', 18, 2);
            $table->decimal('actual_profit_amount', 18, 2)->nullable(); // boleh negatif bila project rugi
            $table->decimal('target_margin_pct', 7, 4);
            $table->decimal('target_margin_amount', 18, 2);
            $table->string('payment_type', 20);
            $table->integer('payment_count');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 30)->default('DRAFT');
            $table->foreignId('financially_closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('financially_closed_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->integer('version')->default(1);
            $table->index(['pks_id', 'status']);
            $table->index(['department', 'status']);
        });
        DB::statement("ALTER TABLE projects ADD CONSTRAINT chk_projects_department CHECK (department IN ('BM','CP','ITBS'))");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT chk_projects_status CHECK (status IN ('DRAFT','SUBMITTED','ACTIVE','COMPLETED','FINANCIALLY_CLOSED','ARCHIVED','CANCELLED'))");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT chk_projects_tax_mode CHECK (contract_tax_mode IN ('INCLUSIVE_PPN','EXCLUSIVE_PPN','NON_PPN'))");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT chk_projects_ppn_snapshot CHECK ((contract_tax_mode = 'EXCLUSIVE_PPN') = (margin_base_ppn_rate_snapshot IS NOT NULL))");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT chk_projects_payment_type CHECK (payment_type IN ('TERMIN','MONTHLY'))");
        DB::statement('ALTER TABLE projects ADD CONSTRAINT chk_projects_payment_count CHECK (payment_count > 0)');
        DB::statement('ALTER TABLE projects ADD CONSTRAINT chk_projects_period CHECK (end_date >= start_date)');
        DB::statement('ALTER TABLE projects ADD CONSTRAINT chk_projects_money CHECK (contract_value >= 0 AND margin_base_amount >= 0 AND initial_rab >= 0 AND effective_rab >= 0 AND target_margin_amount >= 0 AND target_margin_pct >= 0)');

        Schema::create('project_user_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('assignment_role', 30);
            $table->timestamp('assigned_at')->useCurrent();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unique(['project_id', 'user_id', 'assignment_role'], 'uq_assignments_project_user_role'); // nama otomatis melebihi 64 karakter
        });
        DB::statement("ALTER TABLE project_user_assignments ADD CONSTRAINT chk_assignments_role CHECK (assignment_role IN ('PIC','MANAGER','VIEWER'))");

        Schema::create('project_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('adjustment_type', 30);
            $table->json('old_data');
            $table->json('new_data');
            $table->decimal('difference_amount', 18, 2)->nullable(); // selisih, boleh negatif
            $table->text('reason');
            $table->string('status', 20)->default('DRAFT');
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->useCurrent();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('revision_requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revision_requested_at')->nullable();
            $table->text('revision_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['project_id', 'status']);
        });
        DB::statement("ALTER TABLE project_adjustments ADD CONSTRAINT chk_adjustments_type CHECK (adjustment_type IN ('CONTRACT_VALUE','RAB','TARGET_MARGIN','PROJECT_PERIOD'))");
        DB::statement("ALTER TABLE project_adjustments ADD CONSTRAINT chk_adjustments_status CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','REVISION_REQUIRED','REVERSED'))");
        // No self-approval dijaga juga di database.
        DB::statement('ALTER TABLE project_adjustments ADD CONSTRAINT chk_adjustments_no_self_approval CHECK (approved_by IS NULL OR approved_by <> submitted_by)');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_adjustments');
        Schema::dropIfExists('project_user_assignments');
        Schema::dropIfExists('projects');
    }
};
