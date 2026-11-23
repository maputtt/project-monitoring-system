<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Story C: tiket laporan masalah. Riwayat: tidak ada hard delete, FK RESTRICT.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 150);
            $table->text('description');
            $table->string('page_url', 2048)->nullable();
            $table->string('screenshot_path', 255)->nullable();
            $table->string('screenshot_mime', 50)->nullable();
            $table->string('status', 20)->default('NEW');
            $table->text('admin_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['reporter_id', 'created_at']);
        });

        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT chk_support_tickets_status CHECK (status IN ('NEW','IN_PROGRESS','RESOLVED'))");
    }

    public function down(): void
    {
        if (DB::table('support_tickets')->exists()) {
            throw new RuntimeException('Rollback ditolak: masih ada tiket support (riwayat, tidak boleh dihapus).');
        }

        Schema::dropIfExists('support_tickets');
    }
};
