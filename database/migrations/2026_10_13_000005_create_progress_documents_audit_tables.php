<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// project_progress (PRD 16.2.11), documents (16.2.12), audit_logs (16.2.13).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('entry_type', 20);
            $table->date('progress_date');
            $table->string('week_key', 20);
            $table->decimal('progress_percentage', 5, 2)->nullable();
            $table->string('milestone_name', 150)->nullable();
            $table->string('milestone_status', 30)->nullable();
            $table->text('description');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['project_id', 'progress_date']);
        });
        DB::statement("ALTER TABLE project_progress ADD CONSTRAINT chk_progress_entry_type CHECK (entry_type IN ('WEEKLY_UPDATE','MILESTONE'))");
        DB::statement('ALTER TABLE project_progress ADD CONSTRAINT chk_progress_percentage CHECK (progress_percentage IS NULL OR (progress_percentage >= 0 AND progress_percentage <= 100))');
        DB::statement("ALTER TABLE project_progress ADD CONSTRAINT chk_progress_milestone_status CHECK (milestone_status IS NULL OR milestone_status IN ('PLANNED','ACHIEVED','DELAYED'))");
        DB::statement("ALTER TABLE project_progress ADD CONSTRAINT chk_progress_milestone_complete CHECK (entry_type <> 'MILESTONE' OR (milestone_name IS NOT NULL AND milestone_status IS NOT NULL))");

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('document_type', 40);
            $table->string('original_filename');
            $table->string('storage_key', 500)->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('checksum', 128)->nullable();
            $table->string('status', 20)->default('UPLOADED');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('uploaded_at')->useCurrent();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('replaced_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->index(['entity_type', 'entity_id']);
        });
        DB::statement("ALTER TABLE documents ADD CONSTRAINT chk_documents_entity_type CHECK (entity_type IN ('PROJECT','BQ_ITEM','BQ_MUTATION','INVOICE','PROJECT_ADJUSTMENT','PROGRESS'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT chk_documents_type CHECK (document_type IN ('NOTA_DISPOSISI','BAPP','BAST','INVOICE_FILE','BUKTI_BAYAR','PROGRESS_PHOTO','OTHER'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT chk_documents_status CHECK (status IN ('UPLOADED','VERIFIED','REVISION_REQUIRED','REPLACED'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT chk_documents_mime CHECK (mime_type IN ('application/pdf','image/jpeg','image/png'))");
        DB::statement('ALTER TABLE documents ADD CONSTRAINT chk_documents_size CHECK (file_size > 0 AND file_size <= 52428800)'); // 50 MB

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete(); // null = aksi sistem
            $table->string('action', 50);
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id');
            $table->json('data_before')->nullable();
            $table->json('data_after')->nullable();
            $table->text('reason')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->string('ip_address', 50)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
        });
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT chk_audit_logs_action CHECK (action IN ('CREATE','UPDATE','SUBMIT','APPROVE','REQUEST_REVISION','LOCK','UNLOCK','REVERSE','LOGIN'))");
        // Append-only dijaga database: UPDATE dan DELETE ditolak apa pun jalurnya.
        foreach (['UPDATE', 'DELETE'] as $event) {
            DB::unprepared('CREATE TRIGGER trg_audit_logs_no_'.strtolower($event)." BEFORE {$event} ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs'); // trigger ikut terhapus bersama tabelnya
        Schema::dropIfExists('documents');
        Schema::dropIfExists('project_progress');
    }
};
