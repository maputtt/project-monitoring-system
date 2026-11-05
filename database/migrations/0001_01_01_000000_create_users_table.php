<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PRD 16.2.1. Kolom password bernama password_hash; User::getAuthPasswordName() menyesuaikan.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('role', 30);
            $table->string('name', 150);
            $table->string('email', 150)->unique();
            $table->string('password_hash');
            $table->string('phone_number', 30)->nullable();
            $table->string('position', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_role CHECK (role IN ('STAFF_PIC','MANAGER_GM','DIREKSI','SYSTEM_ADMIN'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_department CHECK (department IS NULL OR department IN ('BM','CP','ITBS'))");
        // Akses STAFF_PIC dan MANAGER_GM dibatasi per divisi, jadi keduanya wajib punya divisi.
        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_department_required CHECK (role NOT IN ('STAFF_PIC','MANAGER_GM') OR department IS NOT NULL)");

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
