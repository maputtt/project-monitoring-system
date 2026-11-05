<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Data master: pks (PRD 16.2.2), bouwheer_contacts (16.2.3), tax_rates (16.2.9).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pks', function (Blueprint $table) {
            $table->id();
            $table->string('pks_number', 100)->unique();
            $table->string('name', 200);
            $table->string('client_name', 200);
            $table->string('role_mode', 50)->nullable();
            $table->date('issued_date');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 30)->default('DRAFT');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
        DB::statement("ALTER TABLE pks ADD CONSTRAINT chk_pks_status CHECK (status IN ('DRAFT','ACTIVE','EXPIRED','ARCHIVED','CANCELLED'))");
        DB::statement('ALTER TABLE pks ADD CONSTRAINT chk_pks_period CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date)');

        Schema::create('bouwheer_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('company_name', 200);
            $table->string('position', 100)->nullable();
            $table->string('phone_number', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('tax_type', 10);
            $table->string('name', 150);
            $table->string('vendor_qualification', 50)->nullable();
            $table->decimal('rate', 7, 2);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['tax_type', 'effective_from']);
        });
        DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT chk_tax_rates_rate CHECK (rate >= 0)');
        DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT chk_tax_rates_period CHECK (effective_until IS NULL OR effective_until >= effective_from)');
        // Kualifikasi hanya berlaku untuk PPh: A perusahaan kecil, B menengah ke atas. PPN wajib kosong.
        // IS NOT NULL ditulis eksplisit karena "NULL IN (...)" bernilai UNKNOWN dan CHECK meloloskan UNKNOWN.
        DB::statement("ALTER TABLE tax_rates ADD CONSTRAINT chk_tax_rates_qualification CHECK ((tax_type = 'PPN' AND vendor_qualification IS NULL) OR (tax_type = 'PPH' AND vendor_qualification IS NOT NULL AND vendor_qualification IN ('A','B')))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('bouwheer_contacts');
        Schema::dropIfExists('pks');
    }
};
