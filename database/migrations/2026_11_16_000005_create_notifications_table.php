<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Story E: tabel notifikasi bawaan Laravel (infrastruktur, di luar 13 tabel domain).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('notifications')->exists()) {
            throw new RuntimeException('Rollback ditolak: masih ada notifikasi (riwayat, tidak boleh dihapus).');
        }

        Schema::dropIfExists('notifications');
    }
};
