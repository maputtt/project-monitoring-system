<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Keputusan PM 2026-11-19 (B): hapus passkey sebuah HP ikut menghapus langganan push HP itu. Baris lama tetap NULL (tidak terhubung).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->foreignId('passkey_id')->nullable()->after('user_id')->constrained('passkeys')->cascadeOnDelete(); // FK otomatis membuat indeks
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('passkey_id');
        });
    }
};
